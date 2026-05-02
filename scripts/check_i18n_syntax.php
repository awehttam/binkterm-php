#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * check_i18n_syntax.php
 *
 * Runs `php -l` on every catalog file under config/i18n/ and reports any
 * that fail to parse. A parse error in a catalog file causes a fatal HTTP 500
 * whenever that locale is loaded, so this check should be run before the
 * key-comparison scripts (which silently skip unloadable files).
 *
 * Usage:
 *   php scripts/check_i18n_syntax.php               # check all locales
 *   php scripts/check_i18n_syntax.php --locale=it   # check a specific locale only
 */

$locale = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--locale=')) {
        $locale = trim(substr($arg, strlen('--locale=')));
    }
}

$i18nDir = __DIR__ . '/../config/i18n';

if (!is_dir($i18nDir)) {
    fwrite(STDERR, "i18n directory not found: {$i18nDir}\n");
    exit(2);
}

$pattern = $locale !== null
    ? $i18nDir . '/' . $locale . '/*.php'
    : $i18nDir . '/*/*.php';

$files = glob($pattern);

if ($files === false || count($files) === 0) {
    $msg = $locale ? "No catalog files found for locale: {$locale}" : "No catalog files found under {$i18nDir}";
    fwrite(STDERR, $msg . "\n");
    exit(2);
}

sort($files);

$failed  = 0;
$checked = 0;

foreach ($files as $file) {
    // Skip override JSON files and the allowlist — only check PHP catalogs.
    if (!str_ends_with($file, '.php')) {
        continue;
    }

    $checked++;
    $output = [];
    $code   = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);

    $rel = str_replace(realpath($i18nDir . '/..') . DIRECTORY_SEPARATOR, '', realpath($file));
    $rel = str_replace('\\', '/', $rel);

    if ($code !== 0) {
        echo "[FAIL] {$rel}\n";
        foreach ($output as $line) {
            if (trim($line) !== '') {
                echo "       {$line}\n";
            }
        }
        $failed++;
    } else {
        echo "[OK]   {$rel}\n";
    }
}

echo "\nChecked {$checked} file(s). Syntax errors: {$failed}\n";

if ($failed === 0) {
    echo "i18n syntax check passed.\n";
}

exit($failed > 0 ? 1 : 0);
