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
    $left = $GLOBALS['borderStyle']['left'] ?? "\x1b[1;34m|\x1b[0m";
    $right = $GLOBALS['borderStyle']['right'] ?? "\x1b[1;34m|\x1b[0m";
    return $left . $colorCode . $padded . "\x1b[0m" . $right;
}

/**
 * Format a line within an ANSI bordered box, allowing ANSI in content.
 */
function formatAnsiLineRaw(string $content, int $width): string
{
    $padded = ' ' . $content;
    $padded = truncateAnsi($padded, $width - 2);
    $padded = padAnsi($padded, $width - 2);
    $left = $GLOBALS['borderStyle']['left'] ?? "\x1b[1;34m|\x1b[0m";
    $right = $GLOBALS['borderStyle']['right'] ?? "\x1b[1;34m|\x1b[0m";
    return $left . $padded . $right;
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
 * Build a simple ASCII border with color shading.
 *
 * @return array{top:string,bottom:string,left:string,right:string}
 */
function buildBorderStyle(int $width, string $accentLevel = 'none'): array
{
    $edgeLen = $width - 2;
    $reset = "\x1b[0m";
    $base = "34";   // dark blue
    $accentLight = "90"; // light gray
    $accentWhite = "37"; // white

    $accentRoll = null;
    if ($accentLevel === 'rare') {
        $accentRoll = 60;
    } elseif ($accentLevel === 'subtle') {
        $accentRoll = 30;
    } elseif ($accentLevel === 'noticeable') {
        $accentRoll = 15;
    }

    $edge = '';
    for ($i = 0; $i < $edgeLen; $i++) {
        $color = $base;
        if ($accentRoll !== null) {
            $roll = mt_rand(0, $accentRoll - 1);
            if ($roll === 0) {
                $color = $accentWhite;
            } elseif ($roll === 1) {
                $color = $accentLight;
            }
        }
        $edge .= "\x1b[" . $color . "m-\x1b[0m";
    }

    $left = "\x1b[" . $base . "m|\x1b[0m";
    $right = "\x1b[" . $base . "m|\x1b[0m";
    $top = "\x1b[" . $base . "m+\x1b[0m" . $edge . "\x1b[" . $base . "m+\x1b[0m";
    $bottom = "\x1b[" . $base . "m+\x1b[0m" . $edge . "\x1b[" . $base . "m+\x1b[0m";

    return [
        'top' => $top,
        'bottom' => $bottom,
        'left' => $left,
        'right' => $right,
    ];
}

/**
 * Build a gradient shaded border style (cyan to purple gradient).
 * Mimics the Wordle-style frame with shading and color gradient.
 *
 * @return array{top:string,bottom:string,left:string,right:string}
 */
function buildGradientBorderStyle(int $width): array
{
    $edgeLen = $width - 2;
    $reset = "\x1b[0m";

    // Gradient colors from bright cyan to purple/magenta
    // 96 = bright cyan, 94 = bright blue, 95 = bright magenta, 35 = magenta
    $topColors = ['96', '96', '36', '36', '94'];
    $bottomColors = ['95', '35', '35', '35', '95'];

    // Build top edge with bright cyan gradient
    $topEdge = '';
    for ($i = 0; $i < $edgeLen; $i++) {
        $colorIdx = (int)(($i / $edgeLen) * (count($topColors) - 1));
        $color = $topColors[$colorIdx];
        $topEdge .= "\x1b[" . $color . "m─\x1b[0m";
    }

    // Build bottom edge with purple/magenta gradient
    $bottomEdge = '';
    for ($i = 0; $i < $edgeLen; $i++) {
        $colorIdx = (int)(($i / $edgeLen) * (count($bottomColors) - 1));
        $color = $bottomColors[$colorIdx];
        $bottomEdge .= "\x1b[" . $color . "m─\x1b[0m";
    }

    // Corners use gradient colors
    $top = "\x1b[96m┌\x1b[0m" . $topEdge . "\x1b[36m┐\x1b[0m";
    $bottom = "\x1b[95m└\x1b[0m" . $bottomEdge . "\x1b[35m┘\x1b[0m";

    // Sides will be colored based on position (set in formatAnsiLine)
    $left = "\x1b[96m│\x1b[0m";
    $right = "\x1b[36m│\x1b[0m";

    return [
        'top' => $top,
        'bottom' => $bottom,
        'left' => $left,
        'right' => $right,
    ];
}

/**
 * Build an ice cold gradient border style (dark blue/cyan gradient).
 * Uses darker, cooler colors for a frozen, icy aesthetic.
 *
 * @return array{top:string,bottom:string,left:string,right:string}
 */
function buildGradientBorderStyle2(int $width): array
{
    $edgeLen = $width - 2;
    $reset = "\x1b[0m";

    // Ice cold gradient colors - dark blues, cyans, and grays
    // 36 = dark cyan, 34 = dark blue, 90 = dark gray, 94 = bright blue (for subtle highlights)
    $topColors = ['36', '36', '94', '34', '34'];
    $bottomColors = ['34', '34', '90', '90', '36'];

    // Build top edge with dark cyan/blue gradient
    $topEdge = '';
    for ($i = 0; $i < $edgeLen; $i++) {
        $colorIdx = (int)(($i / $edgeLen) * (count($topColors) - 1));
        $color = $topColors[$colorIdx];
        $topEdge .= "\x1b[" . $color . "m─\x1b[0m";
    }

    // Build bottom edge with dark blue/gray gradient
    $bottomEdge = '';
    for ($i = 0; $i < $edgeLen; $i++) {
        $colorIdx = (int)(($i / $edgeLen) * (count($bottomColors) - 1));
        $color = $bottomColors[$colorIdx];
        $bottomEdge .= "\x1b[" . $color . "m─\x1b[0m";
    }

    // Corners use dark ice colors
    $top = "\x1b[36m┌\x1b[0m" . $topEdge . "\x1b[34m┐\x1b[0m";
    $bottom = "\x1b[34m└\x1b[0m" . $bottomEdge . "\x1b[90m┘\x1b[0m";

    // Sides use dark cyan and blue
    $left = "\x1b[36m│\x1b[0m";
    $right = "\x1b[34m│\x1b[0m";

    return [
        'top' => $top,
        'bottom' => $bottom,
        'left' => $left,
        'right' => $right,
    ];
}

/**
 * Build a star border style for ASCII art variant.
 *
 * @return array{top:string,bottom:string,left:string,right:string}
 */
function buildStarBorderStyle(int $width, string $colorCode = "\x1b[1;35m"): array
{
    $edgeLen = $width - 2;
    $reset = "\x1b[0m";
    $edge = str_repeat('*', $edgeLen);
    $top = $colorCode . '*' . $edge . '*' . $reset;
    $bottom = $colorCode . '*' . $edge . '*' . $reset;
    $left = $colorCode . '*' . $reset;
    $right = $colorCode . '*' . $reset;

    return [
        'top' => $top,
        'bottom' => $bottom,
        'left' => $left,
        'right' => $right,
    ];
}

/**
 * Return the base 5x5 block font map.
 *
 * @return array<string, array<int, string>>
 */
function getBlockFont(): array
{
    return [
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
}

/**
 * Build 5x5 block font lines for a string.
 *
 * @return string[]|null Returns 5 lines or null if too wide or empty.
 */
function buildBlockTextLines(string $text, int $width): ?array
{
    $font = getBlockFont();

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
 * Build a scaled outline font from the 5x5 block map.
 *
 * @return string[]|null Returns scaled lines or null if too wide or empty.
 */
function buildOutlineTextLines(string $text, int $width, int $scale = 2): ?array
{
    $font = getBlockFont();
    $text = strtoupper(trim($text));
    if ($text === '' || $scale < 1) {
        return null;
    }

    $glyphs = [];
    foreach (str_split($text) as $ch) {
        $glyphs[] = $font[$ch] ?? $font[' '];
    }

    $outlineGlyphs = [];
    foreach ($glyphs as $glyph) {
        $h = count($glyph);
        $w = strlen($glyph[0]);
        $outline = array_fill(0, $h, str_repeat(' ', $w));
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($glyph[$y][$x] !== '#') {
                    continue;
                }
                $isEdge = false;
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        if ($dy === 0 && $dx === 0) {
                            continue;
                        }
                        $ny = $y + $dy;
                        $nx = $x + $dx;
                        if ($ny < 0 || $ny >= $h || $nx < 0 || $nx >= $w) {
                            $isEdge = true;
                            continue;
                        }
                        if ($glyph[$ny][$nx] === ' ') {
                            $isEdge = true;
                        }
                    }
                }
                if ($isEdge) {
                    $row = $outline[$y];
                    $row[$x] = '#';
                    $outline[$y] = $row;
                }
            }
        }
        $outlineGlyphs[] = $outline;
    }

    $lines = [];
    $scaledHeight = count($outlineGlyphs[0]) * $scale;
    for ($y = 0; $y < $scaledHeight; $y++) {
        $lines[] = '';
    }

    foreach ($outlineGlyphs as $glyph) {
        $h = count($glyph);
        $w = strlen($glyph[0]);
        for ($y = 0; $y < $h; $y++) {
            $row = $glyph[$y];
            $scaledRow = '';
            for ($x = 0; $x < $w; $x++) {
                $ch = $row[$x] === '#' ? '#' : ' ';
                $scaledRow .= str_repeat($ch, $scale);
            }
            for ($sy = 0; $sy < $scale; $sy++) {
                $lines[$y * $scale + $sy] .= $scaledRow . str_repeat(' ', $scale);
            }
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
 * Build a filled, scaled block font (ASCII '#').
 *
 * @return string[]|null
 */
function buildFilledTextLines(string $text, int $width, int $scale = 2): ?array
{
    $font = getBlockFont();
    $text = strtoupper(trim($text));
    if ($text === '' || $scale < 1) {
        return null;
    }

    $glyphs = [];
    foreach (str_split($text) as $ch) {
        $glyphs[] = $font[$ch] ?? $font[' '];
    }

    $lines = [];
    $scaledHeight = count($glyphs[0]) * $scale;
    for ($y = 0; $y < $scaledHeight; $y++) {
        $lines[] = '';
    }

    foreach ($glyphs as $glyph) {
        $h = count($glyph);
        $w = strlen($glyph[0]);
        for ($y = 0; $y < $h; $y++) {
            $row = $glyph[$y];
            $scaledRow = '';
            for ($x = 0; $x < $w; $x++) {
                $ch = $row[$x] === '#' ? '#' : ' ';
                $scaledRow .= str_repeat($ch, $scale);
            }
            for ($sy = 0; $sy < $scale; $sy++) {
                $lines[$y * $scale + $sy] .= $scaledRow . str_repeat(' ', $scale);
            }
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
 * Build a slanted font using the 5x5 block map.
 *
 * @return string[]|null
 */
function buildSlantTextLines(string $text, int $width): ?array
{
    $base = buildBlockTextLines($text, $width);
    if (!$base) {
        return null;
    }

    $lines = [];
    $slant = 0;
    foreach ($base as $line) {
        $lines[] = str_repeat(' ', $slant) . $line;
        $slant++;
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
 * Build title lines using selected font, splitting into multiple lines if needed.
 *
 * @return string[]|null
 */
function buildTitleLines(string $text, int $width, string $titleFont): ?array
{
    $builders = [];
    if ($titleFont === 'banner') {
        $builders[] = function (string $lineText) use ($width): ?array {
            return buildFilledTextLines($lineText, $width, 2);
        };
        $builders[] = function (string $lineText) use ($width): ?array {
            return buildFilledTextLines($lineText, $width, 1);
        };
    } elseif ($titleFont === 'outline') {
        $builders[] = function (string $lineText) use ($width): ?array {
            return buildOutlineTextLines($lineText, $width, 2);
        };
        $builders[] = function (string $lineText) use ($width): ?array {
            return buildOutlineTextLines($lineText, $width, 1);
        };
    } elseif ($titleFont === 'slant') {
        $builders[] = function (string $lineText) use ($width): ?array {
            return buildSlantTextLines($lineText, $width);
        };
    }
    $builders[] = function (string $lineText) use ($width): ?array {
        return buildBlockTextLines($lineText, $width);
    };

    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $words = preg_split('/\s+/', $text);
    foreach ($builders as $buildLine) {
        $linesText = [];
        $current = '';
        $ok = true;
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $candidateLines = $buildLine($candidate);
            if ($candidateLines) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $linesText[] = $current;
                $current = $word;
            } else {
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            continue;
        }
        if ($current !== '') {
            $linesText[] = $current;
        }

        $finalLines = [];
        foreach ($linesText as $lineText) {
            $lineArt = $buildLine($lineText);
            if (!$lineArt) {
                $ok = false;
                break;
            }
            foreach ($lineArt as $line) {
                $finalLines[] = $line;
            }
        }
        if ($ok && !empty($finalLines)) {
            return $finalLines;
        }
    }

    return null;
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
    array $webdoors,
    string $siteUrl,
    int $variant,
    string $extraText = '',
    string $borderAccent = 'none',
    string $tagline = '',
    string $titleFont = 'block',
    string $borderStyle = 'default'
): string {
    $width = 72;

    // Select border style
    if ($borderStyle === 'gradient') {
        $GLOBALS['borderStyle'] = buildGradientBorderStyle($width);
    } elseif ($borderStyle === 'gradient2') {
        $GLOBALS['borderStyle'] = buildGradientBorderStyle2($width);
    } else {
        $GLOBALS['borderStyle'] = buildBorderStyle($width, $borderAccent);
    }
    $border = $GLOBALS['borderStyle']['top'];

    $palettes = [
        ['40', '44', '46', '47', '100'],
        ['40', '41', '43', '47', '101'],
        ['40', '42', '46', '47', '102'],
        ['40', '45', '46', '47', '104'],
        ['40', '42', '44', '47', '100'],
    ];
    $palette = randChoice($palettes);

    $domainText = 'Networks: ' . (empty($domains) ? 'None' : implode(', ', $domains));
    $webdoorsText = 'WebDoors: ' . (empty($webdoors) ? 'None' : implode(', ', $webdoors));
    $siteText = "Website: {$siteUrl}";

    $lines = [];
    if ($variant === 6) {
        $GLOBALS['borderStyle'] = buildStarBorderStyle($width);
        $border = $GLOBALS['borderStyle']['top'];
    }

    $lines[] = $border;

    if ($variant === 1) {
        // Split panel: left column stats, right column title + website
        $leftWidth = 26;
        $rightWidth = ($width - 2) - $leftWidth;
        $leftLines = [];
        $leftLines[] = "Sysop: {$sysopName}";
        $leftLines[] = "Location: {$location}";
        foreach (wrapText($domainText, $leftWidth) as $line) {
            $leftLines[] = $line;
        }
        foreach (wrapText($webdoorsText, $leftWidth) as $line) {
            $leftLines[] = $line;
        }

        $rightLines = [];
        $rightLines[] = strtoupper($systemName);
        foreach (wrapText($siteText, $rightWidth) as $line) {
            $rightLines[] = $line;
        }
        if ($extraText !== '') {
            $rightLines[] = '';
            foreach (wrapText($extraText, $rightWidth) as $line) {
                $rightLines[] = $line;
            }
        }

        $rows = max(count($leftLines), count($rightLines));
        for ($i = 0; $i < $rows; $i++) {
            $leftText = $leftLines[$i] ?? '';
            $rightText = $rightLines[$i] ?? '';
            $leftPart = "\x1b[1;33m" . str_pad($leftText, $leftWidth) . "\x1b[0m";
            $rightPart = "\x1b[1;36m" . str_pad($rightText, $rightWidth) . "\x1b[0m";
            $lines[] = formatAnsiLineRaw($leftPart . $rightPart, $width);
        }
    } elseif ($variant === 2) {
        // Badge header: framed title banner then details
        $title = strtoupper($systemName);
        $badge = "[ " . $title . " ]";
        $lines[] = formatAnsiLine(centerText($badge, $width), "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine("Sysop: {$sysopName}", "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine("Location: {$location}", "\x1b[0;37m", $width);
        foreach (wrapText($domainText, $width - 2) as $domainLine) {
            $lines[] = formatAnsiLine($domainLine, "\x1b[0;32m", $width);
        }
        foreach (wrapText($webdoorsText, $width - 2) as $doorLine) {
            $lines[] = formatAnsiLine($doorLine, "\x1b[0;32m", $width);
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
        foreach (wrapText($webdoorsText, $width - 2) as $doorLine) {
            $lines[] = formatAnsiLine($doorLine, "\x1b[0;32m", $width);
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
        // Signal strip with pattern
        $pattern = str_repeat('-=-', intdiv(($width - 2), 3) + 1);
        $pattern = substr($pattern, 0, $width - 2);
        $lines[] = formatAnsiLine($pattern, "\x1b[0;37m", $width);
        $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine($pattern, "\x1b[0;37m", $width);
        $lines[] = formatAnsiLine("Sysop: {$sysopName}", "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine("Location: {$location}", "\x1b[0;37m", $width);
        foreach (wrapText($domainText, $width - 2) as $domainLine) {
            $lines[] = formatAnsiLine($domainLine, "\x1b[0;32m", $width);
        }
        foreach (wrapText($webdoorsText, $width - 2) as $doorLine) {
            $lines[] = formatAnsiLine($doorLine, "\x1b[0;32m", $width);
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
    } elseif ($variant === 6) {
        // Star border ANSI ad (ACiD-style layout)
        $titleLines = buildTitleLines($systemName, $width, $titleFont);
        if (!$titleLines) {
            $titleLines = buildBlockTextLines($systemName, $width);
        }
        $lines[] = formatAnsiLine('', "\x1b[1;35m", $width);
        if ($titleLines) {
            foreach ($titleLines as $line) {
                $lines[] = formatAnsiLine(centerText($line, $width), "\x1b[1;35m", $width);
            }
        } else {
            $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;35m", $width);
        }
        $lines[] = formatAnsiLine('', "\x1b[1;35m", $width);
        $lines[] = formatAnsiLine("Message Nets: " . (empty($domains) ? 'None' : implode(', ', $domains)), "\x1b[1;35m", $width);
        $lines[] = formatAnsiLine("Door Games: " . (empty($webdoors) ? 'None' : implode(', ', $webdoors)), "\x1b[1;35m", $width);
        if ($extraText !== '') {
            $lines[] = formatAnsiLine($extraText, "\x1b[1;35m", $width);
        } else {
            $lines[] = formatAnsiLine("Website: {$siteUrl}", "\x1b[1;35m", $width);
        }
    } elseif ($variant === 7) {
        // Showcase layout with 3 columns
        $blockText = buildTitleLines($systemName, $width, $titleFont);
        if (!$blockText) {
            $blockText = buildBlockTextLines($systemName, $width);
        }
        $lines[] = formatAnsiLine('', "\x1b[1;35m", $width);
        if ($blockText) {
            foreach ($blockText as $line) {
                $lines[] = formatAnsiLine(centerText($line, $width), "\x1b[1;35m", $width);
            }
        } else {
            $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;35m", $width);
        }

        if ($tagline !== '') {
            $lines[] = formatAnsiLine(centerText($tagline, $width), "\x1b[1;36m", $width);
        }
        if ($extraText !== '') {
            foreach (wrapText($extraText, $width - 2) as $extraLine) {
                $lines[] = formatAnsiLine(centerText($extraLine, $width), "\x1b[0;37m", $width);
            }
        }

        $lines[] = formatAnsiLine('', "\x1b[0;37m", $width);

        $colWidth = intdiv(($width - 2), 3);
        $leftTitle = 'Active Echomail Nets:';
        $midTitle = 'Doors:';
        $rightTitle = 'Contact:';

        $leftLines = [$leftTitle];
        foreach ($domains as $domain) {
            $leftLines[] = $domain;
        }

        $midLines = [$midTitle];
        foreach ($webdoors as $door) {
            $midLines[] = $door;
        }

        $rightLines = [$rightTitle];
        foreach (wrapText($siteUrl, $colWidth) as $line) {
            $rightLines[] = $line;
        }

        $rows = max(count($leftLines), count($midLines), count($rightLines));
        for ($i = 0; $i < $rows; $i++) {
            $l = $leftLines[$i] ?? '';
            $m = $midLines[$i] ?? '';
            $r = $rightLines[$i] ?? '';

            $l = str_pad($l, $colWidth);
            $m = str_pad($m, $colWidth);
            $r = str_pad($r, $colWidth);

            $line = "\x1b[1;33m" . $l . "\x1b[0m"
                . "\x1b[1;31m" . $m . "\x1b[0m"
                . "\x1b[1;32m" . $r . "\x1b[0m";
            $lines[] = formatAnsiLineRaw($line, $width);
        }
    } elseif ($variant === 8) {
        // Matrix/terminal vibe
        $lines[] = formatAnsiLineRaw(makeStarRow($width, 0.12), $width);
        $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;32m", $width);
        if ($tagline !== '') {
            $lines[] = formatAnsiLine(centerText($tagline, $width), "\x1b[0;32m", $width);
        }
        foreach (wrapText($extraText !== '' ? $extraText : 'SYSTEM ONLINE', $width - 2) as $extraLine) {
            $lines[] = formatAnsiLine(centerText($extraLine, $width), "\x1b[0;32m", $width);
        }
        $lines[] = formatAnsiLine("Nets: " . (empty($domains) ? 'None' : implode(', ', $domains)), "\x1b[0;32m", $width);
        $lines[] = formatAnsiLine("Doors: " . (empty($webdoors) ? 'None' : implode(', ', $webdoors)), "\x1b[0;32m", $width);
        $lines[] = formatAnsiLine("Web: {$siteUrl}", "\x1b[0;32m", $width);
        $lines[] = formatAnsiLineRaw(makeStarRow($width, 0.04), $width);
    } elseif ($variant === 9) {
        // Retro neon
        $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;35m", $width);
        if ($tagline !== '') {
            $lines[] = formatAnsiLine(centerText($tagline, $width), "\x1b[1;36m", $width);
        }
        $lines[] = formatAnsiLine('', "\x1b[0;37m", $width);
        $lines[] = formatAnsiLine("Website: {$siteUrl}", "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine("Networks: " . (empty($domains) ? 'None' : implode(', ', $domains)), "\x1b[1;35m", $width);
        $lines[] = formatAnsiLine("WebDoors: " . (empty($webdoors) ? 'None' : implode(', ', $webdoors)), "\x1b[1;35m", $width);
        if ($extraText !== '') {
            foreach (wrapText($extraText, $width - 2) as $extraLine) {
                $lines[] = formatAnsiLine($extraLine, "\x1b[0;37m", $width);
            }
        }
    } elseif ($variant === 10) {
        // Classic BBS menu
        $lines[] = formatAnsiLine(centerText(strtoupper($systemName), $width), "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine("1) Website: {$siteUrl}", "\x1b[0;37m", $width);
        $lines[] = formatAnsiLine("2) Sysop: {$sysopName}", "\x1b[0;37m", $width);
        $lines[] = formatAnsiLine("3) Location: {$location}", "\x1b[0;37m", $width);
        $lines[] = formatAnsiLine("4) Networks: " . (empty($domains) ? 'None' : implode(', ', $domains)), "\x1b[0;32m", $width);
        $lines[] = formatAnsiLine("5) WebDoors: " . (empty($webdoors) ? 'None' : implode(', ', $webdoors)), "\x1b[0;32m", $width);
        if ($extraText !== '') {
            $lines[] = formatAnsiLine("6) Note: {$extraText}", "\x1b[0;37m", $width);
        }
    } elseif ($variant === 11) {
        // Centered badge + stacked blocks
        $badge = "[ " . strtoupper($systemName) . " ]";
        $lines[] = formatAnsiLine(centerText($badge, $width), "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine(centerText("Sysop: {$sysopName}", $width), "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine(centerText("Location: {$location}", $width), "\x1b[0;37m", $width);
        $lines[] = formatAnsiLine(centerText("Website: {$siteUrl}", $width), "\x1b[1;37m", $width);
        $lines[] = formatAnsiLine(centerText("Networks: " . (empty($domains) ? 'None' : implode(', ', $domains)), $width), "\x1b[0;32m", $width);
        $lines[] = formatAnsiLine(centerText("WebDoors: " . (empty($webdoors) ? 'None' : implode(', ', $webdoors)), $width), "\x1b[0;32m", $width);
        if ($extraText !== '') {
            $lines[] = formatAnsiLine(centerText($extraText, $width), "\x1b[1;35m", $width);
        }
    } elseif ($variant === 12) {
        // Dual-panel: left contact, right nets/doors
        $leftWidth = 30;
        $rightWidth = ($width - 2) - $leftWidth;
        $leftLines = [];
        $leftLines[] = strtoupper($systemName);
        if ($tagline !== '') {
            $leftLines[] = $tagline;
        }
        $leftLines[] = "Website: {$siteUrl}";
        $leftLines[] = "Sysop: {$sysopName}";
        $leftLines[] = "Location: {$location}";
        if ($extraText !== '') {
            foreach (wrapText($extraText, $leftWidth) as $line) {
                $leftLines[] = $line;
            }
        }

        $rightLines = [];
        $rightLines[] = "Networks:";
        foreach ($domains as $domain) {
            $rightLines[] = $domain;
        }
        $rightLines[] = "WebDoors:";
        foreach ($webdoors as $door) {
            $rightLines[] = $door;
        }

        $rows = max(count($leftLines), count($rightLines));
        for ($i = 0; $i < $rows; $i++) {
            $l = $leftLines[$i] ?? '';
            $r = $rightLines[$i] ?? '';
            $leftPart = "\x1b[1;36m" . str_pad($l, $leftWidth) . "\x1b[0m";
            $rightPart = "\x1b[0;32m" . str_pad($r, $rightWidth) . "\x1b[0m";
            $lines[] = formatAnsiLineRaw($leftPart . $rightPart, $width);
        }
    } else {
        // Echo banner with chevrons
        $banner = '<< ' . strtoupper($systemName) . ' >>';
        $lines[] = formatAnsiLine(centerText($banner, $width), "\x1b[1;36m", $width);
        $lines[] = formatAnsiLine("Sysop: {$sysopName}", "\x1b[1;33m", $width);
        $lines[] = formatAnsiLine("Location: {$location}", "\x1b[0;37m", $width);
        foreach (wrapText($domainText, $width - 2) as $domainLine) {
            $lines[] = formatAnsiLine($domainLine, "\x1b[0;32m", $width);
        }
        foreach (wrapText($webdoorsText, $width - 2) as $doorLine) {
            $lines[] = formatAnsiLine($doorLine, "\x1b[0;32m", $width);
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

    $lines[] = $GLOBALS['borderStyle']['bottom'];

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
    echo "Usage: php scripts/generate_ad.php [--output=PATH] [--seed=SEED] [--variant=N] [--extra=TEXT] [--tagline=TEXT] [--title-font=STYLE]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --output=PATH  Write the ANSI ad to a specific file path.\n";
    echo "  --seed=SEED    Seed the random generator (int or string).\n";
    echo "  --variant=N    Force layout variant (1-12).\n";
    echo "  --extra=TEXT   Extra line centered near the bottom of the ad (or blurb for variant 7).\n";
    echo "  --tagline=TEXT Tagline line (used by variant 7).\n";
    echo "  --title-font=STYLE     Title font: block, outline, slant, banner.\n";
    echo "  --border-style=STYLE   Border style: default, gradient, gradient2.\n";
    echo "  --border-accent=LEVEL  Border accent level (default style only): none, rare, subtle, noticeable.\n";
    echo "  --help         Show this help message.\n";
    echo "\n";
    echo "Default behavior:\n";
    echo "  Prints the ANSI ad to stdout.\n";
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

$webdoors = [];
$webdoorsPath = __DIR__ . '/../config/webdoors.json';
if (file_exists($webdoorsPath)) {
    $webdoorsJson = json_decode((string)file_get_contents($webdoorsPath), true);
    if (is_array($webdoorsJson)) {
        foreach ($webdoorsJson as $doorId => $doorConfig) {
            if (is_array($doorConfig) && ($doorConfig['enabled'] ?? false) === true) {
                $webdoors[] = toCamelCase((string)$doorId);
            }
        }
    }
}
$webdoors = array_values(array_unique($webdoors));
sort($webdoors, SORT_NATURAL | SORT_FLAG_CASE);

$extraText = '';
if (isset($args['extra'])) {
    $extraText = trim((string)$args['extra']);
}

$tagline = '';
if (isset($args['tagline'])) {
    $tagline = trim((string)$args['tagline']);
}

$titleFont = 'block';
if (isset($args['title-font'])) {
    $candidate = strtolower(trim((string)$args['title-font']));
    if (in_array($candidate, ['block', 'outline', 'slant', 'banner'], true)) {
        $titleFont = $candidate;
    }
}

$borderAccent = 'none';
if (isset($args['border-accent'])) {
    $candidate = strtolower(trim((string)$args['border-accent']));
    if (in_array($candidate, ['none', 'rare', 'subtle', 'noticeable'], true)) {
        $borderAccent = $candidate;
    }
}

$borderStyleType = 'default';
if (isset($args['border-style'])) {
    $candidate = strtolower(trim((string)$args['border-style']));
    if (in_array($candidate, ['default', 'gradient', 'gradient2'], true)) {
        $borderStyleType = $candidate;
    }
}

$variant = isset($args['variant']) ? (int)$args['variant'] : 3;
if ($variant < 1 || $variant > 12) {
    $variant = 3;
}

$ansi = buildAnsiAd($systemName, $sysopName, $location, $domains, $webdoors, $siteUrl, $variant, $extraText, $borderAccent, $tagline, $titleFont, $borderStyleType);

if (isset($args['output'])) {
    $outputPath = $args['output'];
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    file_put_contents($outputPath, $ansi);
    echo "Wrote ANSI ad to: {$outputPath}\n";
} else {
    echo $ansi;
}
