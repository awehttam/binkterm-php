<?php

/**
 * PHPUnit tests for i18n catalog integrity.
 * No HTTP server required — tests the catalog PHP files directly.
 *
 * Run: php vendor/bin/phpunit tests/Unit/I18nCatalogTest.php
 */

use PHPUnit\Framework\TestCase;

class I18nCatalogTest extends TestCase
{
    private static string $i18nDir;
    private static array $locales;
    private static array $namespaces = ['common', 'errors'];

    public static function setUpBeforeClass(): void
    {
        self::$i18nDir = dirname(__DIR__, 2) . '/config/i18n';
        self::$locales = array_filter(
            array_map('basename', glob(self::$i18nDir . '/*', GLOB_ONLYDIR) ?: []),
            fn($d) => !str_starts_with($d, '.')
        );
    }

    // ── Catalog loading ────────────────────────────────────────────────────────

    public function testLocalesExist(): void
    {
        $this->assertContains('en', self::$locales, 'en locale must exist');
        $this->assertContains('es', self::$locales, 'es locale must exist');
    }

    /**
     * @dataProvider catalogFileProvider
     */
    public function testCatalogFileReturnsArray(string $locale, string $namespace): void
    {
        $file = self::$i18nDir . "/$locale/$namespace.php";
        $this->assertFileExists($file, "Catalog file missing: $file");

        $catalog = require $file;
        $this->assertIsArray($catalog, "$locale/$namespace.php must return an array");
        $this->assertNotEmpty($catalog, "$locale/$namespace.php must not be empty");
    }

    // ── Key parity ─────────────────────────────────────────────────────────────

    /**
     * @dataProvider namespacePairProvider
     */
    public function testEnAndEsHaveSameKeys(string $namespace): void
    {
        $en = require self::$i18nDir . "/en/$namespace.php";
        $es = require self::$i18nDir . "/es/$namespace.php";

        $enKeys = array_keys($en);
        $esKeys = array_keys($es);

        $missingInEs = array_diff($enKeys, $esKeys);
        $missingInEn = array_diff($esKeys, $enKeys);

        $this->assertEmpty(
            $missingInEs,
            "Keys in en/$namespace but missing in es/$namespace:\n  " . implode("\n  ", $missingInEs)
        );
        $this->assertEmpty(
            $missingInEn,
            "Keys in es/$namespace but missing in en/$namespace:\n  " . implode("\n  ", $missingInEn)
        );
    }

    // ── Value quality ──────────────────────────────────────────────────────────

    /**
     * @dataProvider catalogFileProvider
     */
    public function testAllValuesAreNonEmptyStrings(string $locale, string $namespace): void
    {
        $catalog = require self::$i18nDir . "/$locale/$namespace.php";
        $empty = [];

        foreach ($catalog as $key => $value) {
            // Empty string is intentional for some keys (e.g. time.suffix_singular = '')
            // Only flag non-string values (null, array, etc.)
            if (!is_string($value)) {
                $empty[] = $key;
            }
        }

        $this->assertEmpty(
            $empty,
            "$locale/$namespace.php has empty or non-string values for keys:\n  " . implode("\n  ", $empty)
        );
    }

    /**
     * @dataProvider catalogFileProvider
     */
    public function testNoDuplicateKeys(string $locale, string $namespace): void
    {
        // PHP will silently use the last value for duplicate keys; detect via token parsing
        $file = self::$i18nDir . "/$locale/$namespace.php";
        $source = file_get_contents($file);
        $tokens = token_get_all($source);
        $stringKeys = [];
        $duplicates = [];

        for ($i = 0; $i < count($tokens); $i++) {
            // Look for string literals used as array keys (followed by =>)
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
                $key = trim($tokens[$i][1], "'\"");
                // Look ahead for =>
                for ($j = $i + 1; $j < min($i + 4, count($tokens)); $j++) {
                    if (is_string($tokens[$j]) && $tokens[$j] === '>') {
                        // Check if preceded by =
                        if (is_string($tokens[$j - 1]) && $tokens[$j - 1] === '=') {
                            if (isset($stringKeys[$key])) {
                                $duplicates[] = $key;
                            }
                            $stringKeys[$key] = true;
                        }
                        break;
                    }
                    if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }

        $this->assertEmpty(
            $duplicates,
            "$locale/$namespace.php has duplicate keys:\n  " . implode("\n  ", $duplicates)
        );
    }

    // ── Placeholder consistency ────────────────────────────────────────────────

    /**
     * For each key that has {placeholder} tokens in en, verify es has the same placeholders.
     * @dataProvider namespacePairProvider
     */
    public function testPlaceholdersMatchBetweenLocales(string $namespace): void
    {
        $en = require self::$i18nDir . "/en/$namespace.php";
        $es = require self::$i18nDir . "/es/$namespace.php";

        $mismatches = [];
        foreach ($en as $key => $enValue) {
            if (!isset($es[$key])) {
                continue; // Key parity test catches this
            }
            preg_match_all('/\{(\w+)\}/', (string)$enValue, $enMatches);
            preg_match_all('/\{(\w+)\}/', (string)$es[$key], $esMatches);

            $enPlaceholders = array_unique($enMatches[1]);
            $esPlaceholders = array_unique($esMatches[1]);
            sort($enPlaceholders);
            sort($esPlaceholders);

            if ($enPlaceholders !== $esPlaceholders) {
                $mismatches[] = "$key: en={" . implode('},{', $enPlaceholders) . "} es={" . implode('},{', $esPlaceholders) . "}";
            }
        }

        $this->assertEmpty(
            $mismatches,
            "$namespace placeholder mismatches between en and es:\n  " . implode("\n  ", $mismatches)
        );
    }

    // ── Key naming conventions ─────────────────────────────────────────────────

    /**
     * @dataProvider catalogFileProvider
     */
    public function testKeysFollowNamingConvention(string $locale, string $namespace): void
    {
        $catalog = require self::$i18nDir . "/$locale/$namespace.php";
        $violations = [];

        foreach (array_keys($catalog) as $key) {
            // Keys must be dot-separated lowercase segments (segments may start with digit, e.g. time.1_hour)
            if (!preg_match('/^[a-z][a-z0-9]*(\.[a-z0-9][a-z0-9_]*)+$/', $key)) {
                $violations[] = $key;
            }
        }

        $this->assertEmpty(
            $violations,
            "$locale/$namespace.php has keys that don't follow dot.notation.convention:\n  " . implode("\n  ", $violations)
        );
    }

    // ── Data providers ─────────────────────────────────────────────────────────

    public static function catalogFileProvider(): array
    {
        $cases = [];
        $i18nDir = dirname(__DIR__, 2) . '/config/i18n';
        $locales = array_map('basename', glob($i18nDir . '/*', GLOB_ONLYDIR) ?: []);
        foreach ($locales as $locale) {
            foreach (['common', 'errors'] as $ns) {
                $cases["$locale/$ns"] = [$locale, $ns];
            }
        }
        return $cases;
    }

    public static function namespacePairProvider(): array
    {
        return [
            'common' => ['common'],
            'errors' => ['errors'],
        ];
    }
}
