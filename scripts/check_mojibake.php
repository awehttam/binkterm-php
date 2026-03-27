#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scan project files for mojibake (double-encoded UTF-8) and optionally fix them.
 *
 * Mojibake arises when UTF-8 bytes are misinterpreted as ISO-8859-1/Windows-1252 and
 * then re-encoded as UTF-8, producing garbled sequences like 'Ã©' instead of 'é'.
 *
 * Usage:
 *   php scripts/check_mojibake.php [--fix] [path ...]
 *
 * Options:
 *   --fix      Repair files in-place
 *   path ...   File(s) or director(ies) to scan
 *              (default: config/ src/ templates/ routes/ scripts/ public_html/)
 */

$doFix    = false;
$paths    = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--fix') {
        $doFix = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        printUsage($argv[0]);
        exit(0);
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    } else {
        $paths[] = $arg;
    }
}

if (empty($paths)) {
    $root  = dirname(__DIR__);
    $candidates = ['config', 'src', 'templates', 'routes', 'scripts', 'public_html'];
    foreach ($candidates as $c) {
        $full = "{$root}/{$c}";
        if (is_dir($full) || is_file($full)) {
            $paths[] = $full;
        }
    }
}

$scanExtensions = ['php', 'twig', 'js', 'json', 'md'];
$skipDirs       = ['vendor', 'node_modules', '.git', '.svn'];

// ──────────────────────────────────────────────────────────────────────────────
// Main
// ──────────────────────────────────────────────────────────────────────────────

$totalFiles    = 0;
$affectedFiles = 0;
$fixedFiles    = 0;
$hasError      = false;

foreach (collectFiles($paths, $scanExtensions, $skipDirs) as $file) {
    $totalFiles++;

    $content = file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, "Cannot read: {$file}\n");
        $hasError = true;
        continue;
    }

    // Quick pre-filter: only run full analysis if suspicious byte patterns present.
    if (!hasMojibakeHint($content)) {
        continue;
    }

    $issues = detectMojibake($content);
    if (empty($issues)) {
        continue;
    }

    $affectedFiles++;
    $rel = makeRelative($file);
    echo colorize($rel, 'yellow') . "\n";

    foreach ($issues as $issue) {
        echo "  Line {$issue['line']}:\n";
        echo "    Before: " . colorize(rtrim($issue['original']), 'red')   . "\n";
        echo "    After:  " . colorize(rtrim($issue['fixed']),    'green') . "\n";
        if ($issue['note']) {
            echo "    Note:   " . colorize($issue['note'], 'cyan') . "\n";
        }
        echo "\n";
    }

    if ($doFix) {
        $fixed = rebuildContent($content, $issues);
        if (file_put_contents($file, $fixed) === false) {
            fwrite(STDERR, "  Cannot write: {$file}\n");
            $hasError = true;
        } else {
            $fixedFiles++;
            echo "  " . colorize("[FIXED]", 'green') . "\n\n";
        }
    }
}

echo str_repeat('-', 60) . "\n";
echo "Scanned {$totalFiles} file(s). Mojibake found in {$affectedFiles}.\n";
if ($doFix) {
    echo "Fixed {$fixedFiles} file(s).\n";
} elseif ($affectedFiles > 0) {
    echo "Run with --fix to repair.\n";
}

exit($hasError ? 1 : ($affectedFiles > 0 && !$doFix ? 2 : 0));

// ──────────────────────────────────────────────────────────────────────────────
// Detection & repair
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Quick check: does the content contain any known mojibake byte signatures?
 *
 * The byte sequence C3 83 (UTF-8 for U+00C3 Ã) should never legitimately follow
 * another C3/C2 lead byte; its presence indicates double-encoding of the 0xC3 byte
 * (used as the lead byte for all U+00C0–U+00FF characters in UTF-8).
 * C3 82 similarly indicates double-encoded 0xC2 sequences.
 * C3 A2 C2 80 is the start of the Windows-1252 curly-quote/dash mojibake pattern.
 */
function hasMojibakeHint(string $content): bool
{
    return strpos($content, "\xC3\x83") !== false
        || strpos($content, "\xC3\x82") !== false
        || strpos($content, "\xC3\xA2\xC2\x80") !== false;
}

/**
 * Analyse content line by line and return info about each line that contains mojibake.
 *
 * @return array<int, array{line: int, original: string, fixed: string, note: string}>
 */
function detectMojibake(string $content): array
{
    $issues = [];
    foreach (explode("\n", $content) as $i => $line) {
        if (!hasMojibakeHint($line)) {
            continue;
        }
        $fixed = fixMojibakeString($line);
        if ($fixed !== $line) {
            $note = '';
            // Warn when the fix produces a bare letter before an accented char where an
            // apostrophe is probably missing (e.g. "lécran" should be "l'écran").
            if (preg_match('/\b[ldnm](?=[éèêëàâùûîïôœ])/u', $fixed)) {
                $note = "Possible missing apostrophe — review manually (e.g. l'écran, d'enregistrer).";
            }
            $issues[] = [
                'line'     => $i + 1,
                'original' => $line,
                'fixed'    => $fixed,
                'note'     => $note,
            ];
        }
    }
    return $issues;
}

/**
 * Fix mojibake in a single string of content (raw bytes, not Unicode-aware).
 *
 * Strategy: find runs of two or more consecutive 2-byte UTF-8 sequences whose
 * lead bytes are in the C2–C3 range (U+0080–U+00FF, the Latin Extended block).
 * A run of two such pairs is the minimum footprint of one double-encoded
 * Latin-1 character (the lead byte 0xC3/0xC2 is itself encoded as a 2-byte
 * sequence, followed by the continuation byte encoded as another 2-byte sequence).
 *
 * For each candidate run we try decoding it as ISO-8859-1; if the resulting
 * bytes are valid UTF-8 we replace the run with the decoded bytes.  This test
 * prevents false-positive "fixes" on legitimately correct consecutive accented
 * characters such as "éè", because the decoded bytes (e.g. 0xE9 0xE8) would
 * not form a valid UTF-8 sequence.
 */
function fixMojibakeString(string $s): string
{
    // Operate on raw bytes (no /u flag).
    // Pattern: two or more consecutive 2-byte UTF-8 sequences with C2 or C3 as lead byte.
    $result = preg_replace_callback(
        '/[\xC2\xC3][\x80-\xBF](?:[\xC2\xC3][\x80-\xBF])+/',
        static function (array $m): string {
            $candidate = $m[0];
            // Decode UTF-8 → ISO-8859-1 to recover the original byte values.
            $decoded = mb_convert_encoding($candidate, 'ISO-8859-1', 'UTF-8');
            // Only accept the fix if the recovered bytes are themselves valid UTF-8.
            if ($decoded !== false && $decoded !== $candidate && mb_check_encoding($decoded, 'UTF-8')) {
                return $decoded;
            }
            return $candidate;
        },
        $s
    );

    return $result ?? $s;
}

/**
 * Rebuild full file content applying the detected per-line fixes.
 */
function rebuildContent(string $content, array $issues): string
{
    $lines = explode("\n", $content);
    foreach ($issues as $issue) {
        $lines[$issue['line'] - 1] = $issue['fixed'];
    }
    return implode("\n", $lines);
}

// ──────────────────────────────────────────────────────────────────────────────
// File system helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Recursively collect files with the given extensions, skipping named directories.
 *
 * @param  string[] $paths
 * @param  string[] $extensions  Without leading dot.
 * @param  string[] $skipDirs    Directory basenames to ignore.
 * @return string[]
 */
function collectFiles(array $paths, array $extensions, array $skipDirs): array
{
    $files = [];
    foreach ($paths as $path) {
        if (is_file($path)) {
            $files[] = realpath($path);
            continue;
        }
        if (!is_dir($path)) {
            fwrite(STDERR, "Path not found: {$path}\n");
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            // Skip ignored directories anywhere in the path.
            $parts = explode(DIRECTORY_SEPARATOR, $fileInfo->getPathname());
            $skip  = false;
            foreach ($parts as $part) {
                if (in_array($part, $skipDirs, true)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            if (in_array(strtolower($fileInfo->getExtension()), $extensions, true)) {
                $files[] = $fileInfo->getPathname();
            }
        }
    }
    sort($files);
    return array_unique($files);
}

/**
 * Make a path relative to the project root for display.
 */
function makeRelative(string $path): string
{
    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    if (str_starts_with($path, $root)) {
        return substr($path, strlen($root));
    }
    return $path;
}

// ──────────────────────────────────────────────────────────────────────────────
// Output helpers
// ──────────────────────────────────────────────────────────────────────────────

function colorize(string $text, string $color): string
{
    if (!stream_isatty(STDOUT)) {
        return $text;
    }
    $codes = [
        'red'    => '31',
        'green'  => '32',
        'yellow' => '33',
        'cyan'   => '36',
    ];
    $code = $codes[$color] ?? '0';
    return "\033[{$code}m{$text}\033[0m";
}

function printUsage(string $script): void
{
    echo <<<USAGE
    Usage: php {$script} [--fix] [path ...]

    Scans files for mojibake (double-encoded UTF-8) and optionally fixes them.

    Options:
      --fix      Repair files in-place
      --help     Show this help

    Paths:
      One or more files or directories to scan.
      Default: config/ src/ templates/ routes/ scripts/ public_html/

    Exit codes:
      0  No mojibake found (or --fix ran successfully)
      1  I/O error
      2  Mojibake found but --fix not specified

    USAGE;
}
