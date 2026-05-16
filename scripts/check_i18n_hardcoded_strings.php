#!/usr/bin/env php
<?php

declare(strict_types=1);

const ALLOWLIST_FILE = __DIR__ . '/../config/i18n/hardcoded_allowlist.php';

/**
 * @return array<int, string>
 */
function collectTargetFiles(): array
{
    $roots = [
        __DIR__ . '/../templates',
        __DIR__ . '/../public_html/js',
    ];

    $files = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $path = $item->getPathname();
            if (str_ends_with($path, '.twig') || str_ends_with($path, '.js')) {
                $files[] = $path;
            }
        }
    }

    sort($files, SORT_STRING);
    return $files;
}

/**
 * @return array<int, array{file:string,line:int,type:string,text:string,signature:string}>
 */
function collectViolations(): array
{
    $patterns = [
        'ui_call_literal' => '/\b(?:showError|showSuccess|alert|confirm)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
        'api_error_fallback' => '/\bapiError\(\s*[^,]+,\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
        'ternary_error_fallback' => '/\?\s*payload\.error\s*:\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
    ];

    $violations = [];
    foreach (collectTargetFiles() as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            fwrite(STDERR, "Failed to read file: {$file}\n");
            exit(2);
        }

        foreach ($patterns as $type => $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === false) {
                fwrite(STDERR, "Regex error while scanning file: {$file}\n");
                exit(2);
            }

            foreach ($matches[2] as $index => $capture) {
                $text = trim(stripslashes($capture[0]));
                if (!isPotentialEnglishUiString($text)) {
                    continue;
                }

                $offset = $matches[0][$index][1];
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                $relativeFile = str_replace('\\', '/', ltrim(str_replace(realpath(__DIR__ . '/..') ?: '', '', realpath($file) ?: $file), DIRECTORY_SEPARATOR));
                if ($relativeFile === '') {
                    $relativeFile = str_replace('\\', '/', $file);
                }

                $signature = sprintf('%s|%s|%s', $type, $relativeFile, $text);
                $violations[] = [
                    'file' => $relativeFile,
                    'line' => $line,
                    'type' => $type,
                    'text' => $text,
                    'signature' => $signature,
                ];
            }
        }
    }

    usort($violations, static function (array $a, array $b): int {
        return [$a['file'], $a['line'], $a['type'], $a['text']] <=> [$b['file'], $b['line'], $b['type'], $b['text']];
    });

    return $violations;
}

function isPotentialEnglishUiString(string $text): bool
{
    if ($text === '') {
        return false;
    }

    if (!preg_match('/[A-Za-z]/', $text)) {
        return false;
    }

    if (preg_match('/^(errors|messages|time)\.[A-Za-z0-9_.-]+$/', $text)) {
        return false;
    }

    if (str_contains($text, '{{') || str_contains($text, '{%')) {
        return false;
    }

    return true;
}

/**
 * @return array<string, true>
 */
function loadAllowlistSignatures(): array
{
    if (!is_file(ALLOWLIST_FILE)) {
        return [];
    }

    $data = require ALLOWLIST_FILE;
    if (!is_array($data)) {
        fwrite(STDERR, "Allowlist must return an array: " . ALLOWLIST_FILE . "\n");
        exit(2);
    }

    $signatures = [];
    foreach ($data as $signature) {
        if (is_string($signature) && $signature !== '') {
            $signatures[$signature] = true;
        }
    }

    return $signatures;
}

/**
 * @param array<int, array{file:string,line:int,type:string,text:string,signature:string}> $violations
 */
function writeAllowlist(array $violations): void
{
    $signatures = [];
    foreach ($violations as $violation) {
        $signatures[$violation['signature']] = true;
    }

    ksort($signatures);

    $lines = [];
    $lines[] = '<?php';
    $lines[] = '';
    $lines[] = 'return [';
    foreach (array_keys($signatures) as $signature) {
        $escaped = addcslashes($signature, "\\'");
        $lines[] = "    '{$escaped}',";
    }
    $lines[] = '];';
    $lines[] = '';

    $written = file_put_contents(ALLOWLIST_FILE, implode("\n", $lines));
    if ($written === false) {
        fwrite(STDERR, "Failed to write allowlist: " . ALLOWLIST_FILE . "\n");
        exit(2);
    }

    echo "Wrote allowlist with " . count($signatures) . " signatures to " . ALLOWLIST_FILE . PHP_EOL;
}

$updateAllowlist = in_array('--update-allowlist', $argv, true);
$violations = collectViolations();

if ($updateAllowlist) {
    writeAllowlist($violations);
    exit(0);
}

$allowlist = loadAllowlistSignatures();
$newViolations = [];
foreach ($violations as $violation) {
    if (!isset($allowlist[$violation['signature']])) {
        $newViolations[] = $violation;
    }
}

echo 'Scanned files: ' . count(collectTargetFiles()) . PHP_EOL;
echo 'Detected hardcoded UI strings: ' . count($violations) . PHP_EOL;
echo 'Allowlisted signatures: ' . count($allowlist) . PHP_EOL;
echo 'New violations: ' . count($newViolations) . PHP_EOL;

if (!empty($newViolations)) {
    echo PHP_EOL . "New hardcoded UI strings (not allowlisted):" . PHP_EOL;
    foreach ($newViolations as $violation) {
        echo "- {$violation['file']}:{$violation['line']} [{$violation['type']}] {$violation['text']}" . PHP_EOL;
    }
    exit(1);
}

echo "i18n hardcoded string check passed." . PHP_EOL;
exit(0);
