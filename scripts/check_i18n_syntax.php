#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * check_i18n_syntax.php
 *
 * Three-pass check for every catalog file under config/i18n/:
 *
 *   Pass 1 — php -l   : catches parse errors (missing semicolons, unmatched brackets…)
 *   Pass 2 — include  : executes the file and checks for runtime fatals; catches
 *                        U+2018/U+2019 curly quotes used as string delimiters, which
 *                        PHP 8 treats as Unicode identifier starts rather than quote
 *                        tokens so they survive php -l but blow up at runtime.
 *   Pass 3 — byte scan: flags U+2018/U+2019 in positions that are unambiguously wrong
 *                        string delimiters (key-opener, key-closer before '=>',
 *                        value-opener after '=>').  Gives a precise line/column
 *                        diagnostic before the caller even deploys.
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

// ── helpers ──────────────────────────────────────────────────────────────────

/**
 * Pass 2: execute the file in a child PHP process and return any error output.
 * A non-zero exit code means a fatal was thrown (e.g. Undefined constant "'ui").
 *
 * Uses a temp-file bootstrap to avoid shell-quoting problems with Windows paths.
 *
 * @return array{code: int, lines: string[]}
 */
function includeTest(string $file): array
{
    $bootstrap = '<?php' . "\n"
        . 'error_reporting(E_ALL);' . "\n"
        . '$r = include ' . var_export($file, true) . ';' . "\n"
        . 'if (!is_array($r)) { fwrite(STDERR, "File did not return an array\n"); exit(1); }' . "\n";

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'i18n_check_' . md5($file) . '.php';
    file_put_contents($tmp, $bootstrap);

    $output = [];
    $code   = 0;
    exec('php ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
    @unlink($tmp);

    return ['code' => $code, 'lines' => $output];
}

/**
 * Pass 3: scan raw bytes for U+2018 / U+2019 in unambiguously-wrong delimiter
 * positions within catalog PHP files.
 *
 * Positions checked:
 *   a) Key-opening  — after leading whitespace, where ASCII ' should open a key
 *   b) Key-closing  — immediately before ' =>' or '\t=>'
 *   c) Value-opening — immediately after '=> '
 *
 * Value-closing position (before trailing ',') is skipped because U+2019 there
 * is ambiguous with a legitimate apostrophe; the include test (pass 2) catches
 * that case at runtime.
 *
 * @return string[]  Human-readable issue descriptions, empty if clean.
 */
function scanCurlyQuoteDelimiters(string $file): array
{
    $u2018 = "\xe2\x80\x98"; // U+2018 LEFT SINGLE QUOTATION MARK  '
    $u2019 = "\xe2\x80\x99"; // U+2019 RIGHT SINGLE QUOTATION MARK '
    $both  = [$u2018, $u2019];

    $lines  = file($file);
    $issues = [];

    if ($lines === false) {
        return ["could not read file"];
    }

    foreach ($lines as $i => $rawLine) {
        $lineNum     = $i + 1;
        $trimmedLine = rtrim($rawLine, "\r\n");
        $ltrimmed    = ltrim($trimmedLine);

        // (a) Key-opening delimiter
        foreach ($both as $cq) {
            if (str_starts_with($ltrimmed, $cq)) {
                $char = $cq === $u2018 ? 'U+2018 \xe2\x80\x98' : 'U+2019 \xe2\x80\x99';
                $issues[] = "line {$lineNum}: {$char} (curly quote) in key-opening delimiter position";
            }
        }

        // (b) Key-closing delimiter — curly quote immediately before ' =>'
        foreach ($both as $cq) {
            if (str_contains($trimmedLine, $cq . ' =>') || str_contains($trimmedLine, $cq . "\t=>")) {
                $char = $cq === $u2018 ? 'U+2018 \xe2\x80\x98' : 'U+2019 \xe2\x80\x99';
                $issues[] = "line {$lineNum}: {$char} (curly quote) in key-closing delimiter position (before '=>')";
            }
        }

        // (c) Value-opening delimiter — curly quote immediately after '=> '
        foreach ($both as $cq) {
            if (str_contains($trimmedLine, '=> ' . $cq) || str_contains($trimmedLine, '=>' . $cq)) {
                $char = $cq === $u2018 ? 'U+2018 \xe2\x80\x98' : 'U+2019 \xe2\x80\x99';
                $issues[] = "line {$lineNum}: {$char} (curly quote) in value-opening delimiter position (after '=>')";
            }
        }
    }

    return $issues;
}

// ── main loop ─────────────────────────────────────────────────────────────────

$failed  = 0;
$checked = 0;

foreach ($files as $file) {
    if (!str_ends_with($file, '.php')) {
        continue;
    }

    $checked++;
    $rel = str_replace(realpath($i18nDir . '/..') . DIRECTORY_SEPARATOR, '', realpath($file));
    $rel = str_replace('\\', '/', $rel);

    $fileErrors = [];

    // Pass 1: php -l
    $lintOutput = [];
    $lintCode   = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lintOutput, $lintCode);
    if ($lintCode !== 0) {
        foreach ($lintOutput as $line) {
            if (trim($line) !== '') {
                $fileErrors[] = '[syntax]  ' . $line;
            }
        }
    }

    // Pass 2: include/execute test (only if syntax is clean — avoids double-reporting)
    if ($lintCode === 0) {
        $inc = includeTest($file);
        if ($inc['code'] !== 0) {
            foreach ($inc['lines'] as $line) {
                if (trim($line) !== '') {
                    $fileErrors[] = '[runtime] ' . $line;
                }
            }
        }
    }

    // Pass 3: byte-level curly-quote delimiter scan
    foreach (scanCurlyQuoteDelimiters($file) as $issue) {
        $fileErrors[] = '[curly]   ' . $issue;
    }

    if ($fileErrors !== []) {
        echo "[FAIL] {$rel}\n";
        foreach ($fileErrors as $line) {
            echo "       {$line}\n";
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
