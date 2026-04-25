#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * check_i18n_missing_keys.php
 *
 * Compares all non-English locale catalogs against the English (en) baseline
 * and reports any keys that are missing.
 *
 * Usage:
 *   php scripts/check_i18n_missing_keys.php               # list all missing keys
 *   php scripts/check_i18n_missing_keys.php --locale=es   # check a specific locale only
 *   php scripts/check_i18n_missing_keys.php --ns=common   # check a specific namespace only
 */

$locale    = null;
$namespace = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--locale=')) {
        $locale = trim(substr($arg, strlen('--locale=')));
    }
    if (str_starts_with($arg, '--ns=')) {
        $namespace = trim(substr($arg, strlen('--ns=')));
    }
}

$i18nDir = __DIR__ . '/../config/i18n';

if (!is_dir($i18nDir)) {
    fwrite(STDERR, "i18n directory not found: {$i18nDir}\n");
    exit(2);
}

// Discover namespaces from English locale files.
$enDir = $i18nDir . '/en';
if (!is_dir($enDir)) {
    fwrite(STDERR, "English locale directory not found: {$enDir}\n");
    exit(2);
}

$namespaces = [];
foreach (glob($enDir . '/*.php') as $file) {
    $ns = basename($file, '.php');
    if ($namespace !== null && $ns !== $namespace) {
        continue;
    }
    $namespaces[] = $ns;
}

if (empty($namespaces)) {
    fwrite(STDERR, "No namespace files found" . ($namespace ? " matching --ns={$namespace}" : '') . "\n");
    exit(2);
}

// Discover non-English locales.
$locales = [];
foreach (glob($i18nDir . '/*/') as $dir) {
    $loc = basename($dir);
    if ($loc === 'en' || $loc === 'overrides') {
        continue;
    }
    if ($locale !== null && $loc !== $locale) {
        continue;
    }
    $locales[] = $loc;
}

if (empty($locales)) {
    echo "No non-English locales found" . ($locale ? " matching --locale={$locale}" : '') . ".\n";
    exit(0);
}

sort($locales);
sort($namespaces);

$totalMissing = 0;
$exitCode = 0;

foreach ($namespaces as $ns) {
    $enFile = $enDir . '/' . $ns . '.php';
    $enCatalog = @require $enFile;
    if (!is_array($enCatalog)) {
        fwrite(STDERR, "Failed to load English catalog: {$enFile}\n");
        exit(2);
    }
    $enKeys = array_keys($enCatalog);

    foreach ($locales as $loc) {
        $locFile = $i18nDir . '/' . $loc . '/' . $ns . '.php';
        if (!is_file($locFile)) {
            $missing = $enKeys;
        } else {
            $locCatalog = @require $locFile;
            if (!is_array($locCatalog)) {
                fwrite(STDERR, "Failed to load catalog: {$locFile}\n");
                continue;
            }
            $missing = array_values(array_diff($enKeys, array_keys($locCatalog)));
        }

        $count = count($missing);
        $totalMissing += $count;

        if ($count === 0) {
            echo "[OK]      {$loc}/{$ns}: all " . count($enKeys) . " keys present\n";
        } else {
            echo "[MISSING] {$loc}/{$ns}: {$count} missing / " . count($enKeys) . " total\n";
            $exitCode = 1;
            sort($missing);
            foreach ($missing as $key) {
                echo "          - {$key}\n";
            }
        }
    }
}

echo "\nTotal missing keys: {$totalMissing}\n";

if ($exitCode === 0) {
    echo "i18n missing key check passed.\n";
}

exit($exitCode);
