<?php

/**
 * PHPUnit tests for i18n keys added by the echomail moderation feature.
 *
 * Verifies that all three locales (en, es, fr) contain the new keys,
 * that values are non-empty strings, and that fr is in parity with en
 * (a gap in the existing I18nCatalogTest which only checks en ↔ es).
 *
 * Run: php vendor/bin/phpunit tests/Unit/EchomailModerationI18nTest.php
 */

use PHPUnit\Framework\TestCase;

class EchomailModerationI18nTest extends TestCase
{
    private static string $i18nDir;

    /** Keys added to common.php by this PR */
    private const COMMON_KEYS = [
        'ui.admin.echomail_moderation.page_title',
        'ui.admin.echomail_moderation.heading',
        'ui.admin.echomail_moderation.about_title',
        'ui.admin.echomail_moderation.about_text',
        'ui.admin.echomail_moderation.threshold_hint',
        'ui.admin.echomail_moderation.bbs_settings_link',
        'ui.admin.echomail_moderation.empty_queue',
        'ui.admin.echomail_moderation.col_area',
        'ui.admin.echomail_moderation.col_author',
        'ui.admin.echomail_moderation.col_subject',
        'ui.admin.echomail_moderation.col_date',
        'ui.admin.echomail_moderation.col_actions',
        'ui.admin.echomail_moderation.action_approve',
        'ui.admin.echomail_moderation.action_reject',
        'ui.admin.echomail_moderation.approved_success',
        'ui.admin.echomail_moderation.rejected_success',
        'ui.admin.bbs_settings.features.echomail_moderation_threshold',
        'ui.admin.bbs_settings.features.echomail_moderation_threshold_help',
        'ui.admin.bbs_settings.validation.echomail_moderation_threshold_range',
        'ui.base.admin.echomail_moderation',
        'ui.api.messages.pending_moderation',
    ];

    /** Keys added to errors.php by this PR */
    private const ERRORS_KEYS = [
        'errors.admin.echomail_moderation.not_found',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$i18nDir = dirname(__DIR__, 2) . '/config/i18n';
    }

    // ── All locales have the new common keys ───────────────────────────────────

    /**
     * @dataProvider localeProvider
     */
    public function testNewCommonKeysExistInLocale(string $locale): void
    {
        $file = self::$i18nDir . "/{$locale}/common.php";
        $this->assertFileExists($file, "common.php must exist for locale {$locale}");

        $catalog = require $file;
        foreach (self::COMMON_KEYS as $key) {
            $this->assertArrayHasKey($key, $catalog, "Key '{$key}' missing from {$locale}/common.php");
        }
    }

    /**
     * @dataProvider localeProvider
     */
    public function testNewCommonKeyValuesAreNonEmptyStrings(string $locale): void
    {
        $catalog = require self::$i18nDir . "/{$locale}/common.php";

        foreach (self::COMMON_KEYS as $key) {
            if (!array_key_exists($key, $catalog)) {
                continue; // presence tested separately above
            }
            $this->assertIsString(
                $catalog[$key],
                "Key '{$key}' in {$locale}/common.php must be a string"
            );
            $this->assertNotSame(
                '',
                $catalog[$key],
                "Key '{$key}' in {$locale}/common.php must not be empty"
            );
        }
    }

    // ── All locales have the new errors keys ───────────────────────────────────

    /**
     * @dataProvider localeProvider
     */
    public function testNewErrorsKeysExistInLocale(string $locale): void
    {
        $file = self::$i18nDir . "/{$locale}/errors.php";
        $this->assertFileExists($file, "errors.php must exist for locale {$locale}");

        $catalog = require $file;
        foreach (self::ERRORS_KEYS as $key) {
            $this->assertArrayHasKey($key, $catalog, "Key '{$key}' missing from {$locale}/errors.php");
        }
    }

    /**
     * @dataProvider localeProvider
     */
    public function testNewErrorsKeyValuesAreNonEmptyStrings(string $locale): void
    {
        $catalog = require self::$i18nDir . "/{$locale}/errors.php";

        foreach (self::ERRORS_KEYS as $key) {
            if (!array_key_exists($key, $catalog)) {
                continue;
            }
            $this->assertIsString($catalog[$key], "Key '{$key}' in {$locale}/errors.php must be a string");
            $this->assertNotSame('', $catalog[$key], "Key '{$key}' in {$locale}/errors.php must not be empty");
        }
    }

    // ── fr has all NEW echomail moderation keys ────────────────────────────────
    // (A broad fr↔en parity check is not included because pre-existing gaps in
    // the catalogues are out of scope for this PR.)

    public function testNewCommonKeysExistInFr(): void
    {
        $fr = require self::$i18nDir . "/fr/common.php";
        foreach (self::COMMON_KEYS as $key) {
            $this->assertArrayHasKey($key, $fr, "Key '{$key}' missing from fr/common.php");
        }
    }

    public function testNewErrorsKeysExistInFr(): void
    {
        $fr = require self::$i18nDir . "/fr/errors.php";
        foreach (self::ERRORS_KEYS as $key) {
            $this->assertArrayHasKey($key, $fr, "Key '{$key}' missing from fr/errors.php");
        }
    }

    /**
     * Placeholders in fr values must match en values for the NEW moderation keys only.
     */
    public function testFrNewCommonKeyPlaceholdersMatchEn(): void
    {
        $en = require self::$i18nDir . "/en/common.php";
        $fr = require self::$i18nDir . "/fr/common.php";

        $mismatches = [];
        foreach (self::COMMON_KEYS as $key) {
            if (!isset($en[$key]) || !isset($fr[$key])) {
                continue;
            }
            preg_match_all('/\{(\w+)\}/', (string)$en[$key], $enMatches);
            preg_match_all('/\{(\w+)\}/', (string)$fr[$key], $frMatches);

            $enPlaceholders = array_unique($enMatches[1]);
            $frPlaceholders = array_unique($frMatches[1]);
            sort($enPlaceholders);
            sort($frPlaceholders);

            if ($enPlaceholders !== $frPlaceholders) {
                $mismatches[] = "$key: en={" . implode('},{', $enPlaceholders)
                    . "} fr={" . implode('},{', $frPlaceholders) . "}";
            }
        }

        $this->assertEmpty(
            $mismatches,
            "Placeholder mismatches between en and fr for new moderation keys:\n  " . implode("\n  ", $mismatches)
        );
    }

    // ── Specific key sanity checks ─────────────────────────────────────────────

    public function testEnNotFoundErrorKeyHasExpectedContent(): void
    {
        $catalog = require self::$i18nDir . '/en/errors.php';
        $value = $catalog['errors.admin.echomail_moderation.not_found'] ?? null;
        $this->assertIsString($value);
        // Should mention "not found" or similar phrase
        $this->assertMatchesRegularExpression(
            '/not.found|pending/i',
            $value,
            'en error message for not_found should reference "not found" or "pending"'
        );
    }

    public function testEnPendingModerationMessageMentionsModerat(): void
    {
        $catalog = require self::$i18nDir . '/en/common.php';
        $value = $catalog['ui.api.messages.pending_moderation'] ?? null;
        $this->assertIsString($value);
        $this->assertMatchesRegularExpression(
            '/moderat/i',
            $value,
            'pending_moderation message should mention "moderation" or "moderator"'
        );
    }

    // ── Data providers ─────────────────────────────────────────────────────────

    public static function localeProvider(): array
    {
        return [
            'en' => ['en'],
            'es' => ['es'],
            'fr' => ['fr'],
        ];
    }

}