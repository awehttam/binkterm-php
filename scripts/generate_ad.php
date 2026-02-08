#!/usr/bin/env php
<?php

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Config;

/**
 * Convert a network domain to CamelCase.
 */
function toCamelCase(string $value): string
{
    $value = preg_replace('/[^a-zA-Z0-9]+/', ' ', trim($value));
    if ($value === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $value);
    $parts = array_map(function ($part) {
        $lower = strtolower($part);
        if ($lower === 'net') {
            return 'Net';
        }
        if (strlen($lower) > 3 && substr($lower, -3) === 'net') {
            $prefix = substr($lower, 0, -3);
            return strtoupper(substr($prefix, 0, 1)) . substr($prefix, 1) . 'Net';
        }
        return strtoupper(substr($lower, 0, 1)) . substr($lower, 1);
    }, $parts);

    return implode('', $parts);
}

/**
 * Wrap text into multiple lines with a fixed width.
 *
 * @return string[]
 */
function wrapText(string $text, int $width): array
{
    $wrapped = wordwrap($text, $width, "\n", true);
    return explode("\n", $wrapped);
}

/**
 * Strip ANSI escape sequences to measure visible length.
 */
function stripAnsi(string $text): string
{
    return preg_replace('/\x1b\[[0-9;]*m/', '', $text);
}

/**
 * Pad ANSI text to a fixed visible width.
 */
function padAnsi(string $text, int $width): string
{
    $len = strlen(stripAnsi($text));
    if ($len < $width) {
        return $text . str_repeat(' ', $width - $len);
    }
    return $text;
}

/**
 * Truncate ANSI string to a visible width, preserving escape sequences.
 */
function truncateAnsi(string $text, int $width): string
{
    $out = '';
    $visible = 0;
    $len = strlen($text);

    for ($i = 0; $i < $len && $visible < $width; $i++) {
        $ch = $text[$i];
        if ($ch === "\x1b" && $i + 1 < $len && $text[$i + 1] === '[') {
            $seq = $ch;
            $i++;
            $seq .= $text[$i];
            while ($i + 1 < $len) {
                $i++;
                $seq .= $text[$i];
                if ($text[$i] === 'm') {
                    break;
                }
            }
            $out .= $seq;
            continue;
        }

        $out .= $ch;
        $visible++;
    }

    return $out;
}

/**
 * Format a line within an ANSI bordered box.
 */
function formatAnsiLine(string $text, string $colorCode, int $width): string
{
    $padded = ' ' . $text;
    if (strlen($padded) > ($width - 2)) {
        $padded = substr($padded, 0, $width - 2);
    }
    $padded = str_pad($padded, $width - 2);
    return "\x1b[1;34m|\x1b[0m" . $colorCode . $padded . "\x1b[0m\x1b[1;34m|\x1b[0m";
}

/**
 * Format a line within an ANSI bordered box, allowing ANSI in content.
 */
function formatAnsiLineRaw(string $content, int $width): string
{
    $padded = ' ' . $content;
    $padded = truncateAnsi($padded, $width - 2);
    $padded = padAnsi($padded, $width - 2);
    return "\x1b[1;34m|\x1b[0m" . $padded . "\x1b[1;34m|\x1b[0m";
}

function randChoice(array $items)
{
    return $items[array_rand($items)];
}

function randInt(int $min, int $max): int
{
    return mt_rand($min, $max);
}

/**
 * Build 5x5 block font lines for a string.
 *
 * @return string[]|null Returns 5 lines or null if too wide or empty.
 */
function buildBlockTextLines(string $text, int $width): ?array
{
    $font = [
        'A' => [" ### ", "#   #", "#####", "#   #", "#   #"],
        'B' => ["#### ", "#   #", "#### ", "#   #", "#### "],
        'C' => [" ####", "#    ", "#    ", "#    ", " ####"],
        'D' => ["#### ", "#   #", "#   #", "#   #", "#### "],
        'E' => ["#####", "#    ", "#### ", "#    ", "#####"],
        'F' => ["#####", "#    ", "#### ", "#    ", "#    "],
        'G' => [" ####", "#    ", "#  ##", "#   #", " ####"],
        'H' => ["#   #", "#   #", "#####", "#   #", "#   #"],
        'I' => ["#####", "  #  ", "  #  ", "  #  ", "#####"],
        'J' => ["#####", "   # ", "   # ", "#  # ", " ##  "],
        'K' => ["#   #", "#  # ", "###  ", "#  # ", "#   #"],
        'L' => ["#    ", "#    ", "#    ", "#    ", "#####"],
        'M' => ["#   #", "## ##", "# # #", "#   #", "#   #"],
        'N' => ["#   #", "##  #", "# # #", "#  ##", "#   #"],
        'O' => [" ### ", "#   #", "#   #", "#   #", " ### "],
        'P' => ["#### ", "#   #", "#### ", "#    ", "#    "],
        'Q' => [" ### ", "#   #", "#   #", "#  ##", " ####"],
        'R' => ["#### ", "#   #", "#### ", "#  # ", "#   #"],
        'S' => [" ####", "#    ", " ### ", "    #", "#### "],
        'T' => ["#####", "  #  ", "  #  ", "  #  ", "  #  "],
        'U' => ["#   #", "#   #", "#   #", "#   #", " ### "],
        'V' => ["#   #", "#   #", "#   #", " # # ", "  #  "],
        'W' => ["#   #", "#   #", "# # #", "## ##", "#   #"],
        'X' => ["#   #", " # # ", "  #  ", " # # ", "#   #"],
        'Y' => ["#   #", " # # ", "  #  ", "  #  ", "  #  "],
        'Z' => ["#####", "   # ", "  #  ", " #   ", "#####"],
        '0' => [" ### ", "#   #", "#   #", "#   #", " ### "],
        '1' => ["  #  ", " ##  ", "  #  ", "  #  ", " ### "],
        '2' => [" ### ", "#   #", "   # ", "  #  ", "#####"],
        '3' => ["#### ", "    #", " ### ", "    #", "#### "],
        '4' => ["#   #", "#   #", "#####", "    #", "    #"],
        '5' => ["#####", "#    ", "#### ", "    #", "#### "],
        '6' => [" ####", "#    ", "#### ", "#   #", " ### "],
        '7' => ["#####", "   # ", "  #  ", " #   ", "#    "],
        '8' => [" ### ", "#   #", " ### ", "#   #", " ### "],
        '9' => [" ### ", "#   #", " ####", "    #", " ### "],
        ' ' => ["  ", "  ", "  ", "  ", "  "],
        '-' => ["     ", "     ", "#####", "     ", "     "],
    ];

    $text = strtoupper(trim($text));
    if ($text === '') {
        return null;
    }

    $lines = ["", "", "", "", ""];
    foreach (str_split($text) as $ch) {
        $glyph = $font[$ch] ?? $font[' '];
        for ($i = 0; $i < 5; $i++) {
            $lines[$i] .= $glyph[$i] . ' ';
        }
    }

    $maxLen = 0;
    foreach ($lines as $line) {
        $maxLen = max($maxLen, strlen($line));
    }

    if ($maxLen > ($width - 2)) {
        return null;
    }

    return $lines;
}

/**
 * Generate a row of ANSI shaded blocks using background colors.
 */
/**
 * Generate a starfield line.
 */
function makeStarRow(int $width, float $density): string
{
    $target = $width - 2;
    $row = '';
    $colors = ['30', '34', '35', '36', '90'];
    for ($i = 0; $i < $target; $i++) {
        $roll = mt_rand() / mt_getrandmax();
        if ($roll < $density) {
            $ch = (mt_rand(0, 1) === 0) ? '.' : '*';
            $color = $colors[array_rand($colors)];
            $row .= "\x1b[" . $color . "m" . $ch . "\x1b[0m";
        } else {
            $row .= ' ';
        }
    }
    return $row;
}

/**
 * Generate a skyline block with ANSI shading.
 *
 * @return string[] Array of rows (top to bottom).
 */
function makeSkyline(int $width, int $height, array $palette): array
{
    $target = $width - 2;
    $segments = [];
    $remaining = $target;

    while ($remaining > 0) {
        $minSeg = $remaining < 2 ? 1 : 2;
        $segWidth = randInt($minSeg, min(6, $remaining));
        $segments[] = [
            'width' => $segWidth,
            'height' => randInt(2, $height),
            'color' => randChoice($palette),
        ];
        $remaining -= $segWidth;
    }

    $rows = [];
    for ($row = 0; $row < $height; $row++) {
        $line = '';
        foreach ($segments as $seg) {
            $fill = ($row >= ($height - $seg['height']));
            if ($fill) {
                $line .= "\x1b[" . $seg['color'] . "m" . str_repeat(' ', $seg['width']) . "\x1b[0m";
            } else {
                $line .= str_repeat(' ', $seg['width']);
            }
        }
        $rows[] = $line;
    }

    return $rows;
}

/**
 * Center text within the box width (visible chars).
 */
function centerText(string $text, int $width): string
{
    $target = $width - 2;
    $len = strlen($text);
    if ($len >= $target) {
        return $text;
    }
    $left = intdiv($target - $len, 2);
    $right = $target - $len - $left;
    return str_repeat(' ', $left) . $text . str_repeat(' ', $right);
}

/**
 * Build ANSI ad content with randomized art/layout.
 */
function buildAnsiAd(
    string $systemName,
    string $sysopName,
    string $location,
    array $domains,
    string $siteUrl,
    int $variant,
    string $extraText = ''
): string {
    $width = 72;
    $border = "\x1b[1;34m+" . str_repeat('-', $width - 2) . "+\x1b[0m";

    $palettes = [
        ['40', '44', '46', '47', '100'],
        ['40', '41', '43', '47', '101'],
        ['40', '42', '46', '47', '102'],
        ['40', '45', '46', '47', '104'],
        ['40', '42', '44', '47', '100'],
    ];
    $palette = randChoice($palettes);

    $domainText = 'Networks: ' . (empty($domains) ? 'None' : implode(', ', $domains));
    $siteText = "Website: {$siteUrl}";

    $lines = [];
    $lines[] = $border;

    if ($variant === 1) {
        $blockText = buildBlockTextLines($systemName, $width);
        if ($blockText) {
            foreach ($blockText as $line) {
                $lines[] = formatAnsiLine(centerText($line, $width), "\x1b[1;36m", $width);
            }
        } else {
            $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;36m", $width);
        }
        $lines[] = formatAnsiLine("Sysop: {$sysopName}", "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine("Location: {$location}", "\x1b[0;37m", $width);
        foreach (wrapText($domainText, $width - 2) as $domainLine) {
            $lines[] = formatAnsiLine($domainLine, "\x1b[0;32m", $width);
        }
        foreach (wrapText($siteText, $width - 2) as $siteLine) {
            $lines[] = formatAnsiLine($siteLine, "\x1b[1;37m", $width);
        }
        if ($extraText !== '') {
            $lines[] = formatAnsiLine('', "\x1b[0;37m", $width);
            foreach (wrapText($extraText, $width - 2) as $extraLine) {
                $lines[] = formatAnsiLine(centerText($extraLine, $width), "\x1b[1;35m", $width);
            }
        }
    } elseif ($variant === 2) {
        $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine("Sysop: {$sysopName}", "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine("Location: {$location}", "\x1b[0;37m", $width);
        foreach (wrapText($domainText, $width - 2) as $domainLine) {
            $lines[] = formatAnsiLine($domainLine, "\x1b[0;32m", $width);
        }
        foreach (wrapText($siteText, $width - 2) as $siteLine) {
            $lines[] = formatAnsiLine($siteLine, "\x1b[1;37m", $width);
        }
        if ($extraText !== '') {
            $lines[] = formatAnsiLine('', "\x1b[0;37m", $width);
            foreach (wrapText($extraText, $width - 2) as $extraLine) {
                $lines[] = formatAnsiLine(centerText($extraLine, $width), "\x1b[1;35m", $width);
            }
        }
    } elseif ($variant === 3) {
        $lines[] = formatAnsiLineRaw(makeStarRow($width, 0.08), $width);
        $lines[] = formatAnsiLineRaw(makeStarRow($width, 0.04), $width);
        $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine("Sysop: {$sysopName}", "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine("Location: {$location}", "\x1b[0;37m", $width);
        foreach (wrapText($domainText, $width - 2) as $domainLine) {
            $lines[] = formatAnsiLine($domainLine, "\x1b[0;32m", $width);
        }
        foreach (wrapText($siteText, $width - 2) as $siteLine) {
            $lines[] = formatAnsiLine($siteLine, "\x1b[1;37m", $width);
        }
        if ($extraText !== '') {
            $lines[] = formatAnsiLine('', "\x1b[0;37m", $width);
            foreach (wrapText($extraText, $width - 2) as $extraLine) {
                $lines[] = formatAnsiLine(centerText($extraLine, $width), "\x1b[1;35m", $width);
            }
        }
        $lines[] = formatAnsiLineRaw(makeStarRow($width, 0.02), $width);
    } elseif ($variant === 4) {
        $lines[] = formatAnsiLine(centerText('WELCOME TO', $width), "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine("Sysop: {$sysopName}", "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine("Location: {$location}", "\x1b[0;37m", $width);
        foreach (wrapText($domainText, $width - 2) as $domainLine) {
            $lines[] = formatAnsiLine($domainLine, "\x1b[0;32m", $width);
        }
        foreach (wrapText($siteText, $width - 2) as $siteLine) {
            $lines[] = formatAnsiLine($siteLine, "\x1b[1;37m", $width);
        }
        if ($extraText !== '') {
            $lines[] = formatAnsiLine('', "\x1b[0;37m", $width);
            foreach (wrapText($extraText, $width - 2) as $extraLine) {
                $lines[] = formatAnsiLine(centerText($extraLine, $width), "\x1b[1;35m", $width);
            }
        }
    } else {
        $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine("Sysop: {$sysopName}", "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine("Location: {$location}", "\x1b[0;37m", $width);
        foreach (wrapText($domainText, $width - 2) as $domainLine) {
            $lines[] = formatAnsiLine($domainLine, "\x1b[0;32m", $width);
        }
        foreach (wrapText($siteText, $width - 2) as $siteLine) {
            $lines[] = formatAnsiLine($siteLine, "\x1b[1;37m", $width);
        }
        if ($extraText !== '') {
            $lines[] = formatAnsiLine('', "\x1b[0;37m", $width);
            foreach (wrapText($extraText, $width - 2) as $extraLine) {
                $lines[] = formatAnsiLine(centerText($extraLine, $width), "\x1b[1;35m", $width);
            }
        }
    }

    $lines[] = $border;

    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Create a filesystem-friendly filename slug.
 */
function slugify(string $value): string
{
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim($value, '_');
    return $value !== '' ? $value : 'bbs';
}

function showUsage(): void
{
    echo "Usage: php scripts/generate_ad.php [--output=PATH] [--stdout] [--seed=SEED] [--variant=N] [--extra=TEXT]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --output=PATH  Write the ANSI ad to a specific file path.\n";
    echo "  --stdout       Print the ANSI ad to stdout.\n";
    echo "  --seed=SEED    Seed the random generator (int or string).\n";
    echo "  --variant=N    Force layout variant (1-5).\n";
    echo "  --extra=TEXT   Extra line centered near the bottom of the ad.\n";
    echo "  --help         Show this help message.\n";
    echo "\n";
    echo "Default behavior:\n";
    echo "  Writes the ad to bbs_ads/auto_<system>_<timestamp>.ans and prints the path.\n";
}

$args = [];
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--') === 0) {
        if (strpos($arg, '=') !== false) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $args[$key] = $value;
        } else {
            $args[substr($arg, 2)] = true;
        }
    }
}

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

if (isset($args['seed'])) {
    $seed = $args['seed'];
    if (is_numeric($seed)) {
        mt_srand((int)$seed);
    } else {
        mt_srand((int)crc32((string)$seed));
    }
}

$config = BinkpConfig::getInstance();
$systemName = $config->getSystemName();
$sysopName = $config->getSystemSysop();
$location = $config->getSystemLocation();
$siteUrl = Config::getSiteUrl();

$domains = [];
foreach ($config->getEnabledUplinks() as $uplink) {
    $domain = trim($uplink['domain'] ?? '');
    if ($domain !== '') {
        $domains[] = toCamelCase($domain);
    }
}
$domains = array_values(array_unique($domains));
sort($domains, SORT_NATURAL | SORT_FLAG_CASE);

$extraText = '';
if (isset($args['extra'])) {
    $extraText = trim((string)$args['extra']);
}

$variant = isset($args['variant']) ? (int)$args['variant'] : 3;
if ($variant < 1 || $variant > 5) {
    $variant = 3;
}

$ansi = buildAnsiAd($systemName, $sysopName, $location, $domains, $siteUrl, $variant, $extraText);

$didOutput = false;

if (isset($args['stdout'])) {
    echo $ansi;
    $didOutput = true;
}

if (isset($args['output'])) {
    $outputPath = $args['output'];
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    file_put_contents($outputPath, $ansi);
    echo "Wrote ANSI ad to: {$outputPath}\n";
    $didOutput = true;
}

if (!$didOutput) {
    $adsDir = __DIR__ . '/../bbs_ads';
    if (!is_dir($adsDir)) {
        mkdir($adsDir, 0755, true);
    }

    $timestamp = date('Ymd_His');
    $safeName = slugify($systemName);
    $outputPath = $adsDir . DIRECTORY_SEPARATOR . "auto_{$safeName}_{$timestamp}.ans";
    file_put_contents($outputPath, $ansi);
    echo "Wrote ANSI ad to: {$outputPath}\n";
}
