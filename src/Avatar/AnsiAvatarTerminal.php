<?php

declare(strict_types=1);

namespace BinktermPHP\Avatar;

/**
 * Synthesizes terminal SGR from validated avatar cells.
 * Stored BIN bytes are never written to the terminal, and CP437 control-range
 * bytes are never emitted as raw controls.
 *
 * The returned lines are already in the session character set. Callers must
 * not pass them through encodeForTerminal(): that conversion turns some
 * Unicode stand-ins into multi-column text and can reintroduce byte 0x1B.
 *
 * Copyright (c) 2026 Cythu contributors. Adapted for BinktermPHP.
 * BSD-3-Clause. See the repository LICENSE.
 */
final class AnsiAvatarTerminal
{
    private const PC_TO_ANSI = [0, 4, 2, 6, 1, 5, 3, 7];

    /** One-column ASCII stand-ins for CP437 bytes that are terminal controls. */
    private const CONTROLS = [
        1 => 'o', 2 => 'o', 3 => '*', 4 => '*', 5 => '*', 6 => '*', 7 => '.',
        8 => '#', 9 => 'o', 10 => '#', 11 => '*', 12 => '*', 13 => '*', 14 => '*', 15 => '*',
        16 => '>', 17 => '<', 18 => '|', 19 => '!', 20 => 'P', 21 => 'S', 22 => '_', 23 => '|',
        24 => '^', 25 => 'v', 26 => '>', 27 => '<', 28 => 'L', 29 => '-', 30 => '^', 31 => 'v',
        127 => '^',
    ];

    /**
     * Six final display lines, each reset at the end, or null when color is off.
     * A null result means the caller should show the handle or initials.
     *
     * @return list<string>|null
     */
    public static function lines(AnsiAvatar $avatar, bool $colorEnabled, string $charset = 'utf8'): ?array
    {
        if (!$colorEnabled) {
            return null;
        }
        if (!in_array($charset, ['utf8', 'cp437', 'ascii'], true)) {
            throw new \InvalidArgumentException('Unsupported avatar terminal charset');
        }
        $lines = [];
        foreach ($avatar->cells() as $row) {
            $line = '';
            foreach ($row as $cell) {
                [$glyph, $fg, $bg] = self::fit($cell['char'], $cell['fg'], $cell['bg'], $charset);
                $line .= "\x1b[" . self::sgr($fg, $bg) . 'm' . $glyph;
            }
            $lines[] = $line . "\x1b[0m";
        }
        return $lines;
    }

    /**
     * Safe initials for a no-color or line-shell fallback. Non-ASCII names
     * collapse to ASCII on cp437 and ascii sessions.
     */
    public static function initials(string $name, string $charset = 'ascii'): string
    {
        $name = trim($name);
        if ($charset === 'utf8') {
            $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $pick = '';
            foreach ($chars as $char) {
                if (preg_match('/^\s$/u', $char) === 1) {
                    continue;
                }
                if (mb_strwidth($char, 'UTF-8') !== 1) {
                    continue;
                }
                $pick .= $char;
                if (mb_strlen($pick, 'UTF-8') >= 2) {
                    break;
                }
            }
            if ($pick !== '') {
                return mb_strtoupper($pick, 'UTF-8');
            }
        }
        $ascii = preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '';
        $ascii = strtoupper(substr($ascii, 0, 2));
        return $ascii !== '' ? $ascii : '?';
    }

    /**
     * Visible columns after synthesized SGR is removed.
     * Throws if a control byte survived outside an SGR sequence.
     */
    public static function visibleColumns(string $line, string $charset): int
    {
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line;
        if (preg_match('/[\x00-\x1F\x7F]/', $plain) === 1) {
            throw new \RuntimeException('Control byte leaked into avatar glyph data');
        }
        if ($charset === 'utf8') {
            return mb_strwidth($plain, 'UTF-8');
        }
        return strlen($plain);
    }

    public static function sgr(int $fg, int $bg): string
    {
        if ($fg < 0 || $fg > 15 || $bg < 0 || $bg > 7) {
            throw new \InvalidArgumentException('errors.avatar.invalid');
        }
        return '0;' . ($fg >= 8 ? '1;' : '')
            . (30 + self::PC_TO_ANSI[$fg & 7])
            . ';' . (40 + self::PC_TO_ANSI[$bg]);
    }

    /**
     * @return array{0:string,1:int,2:int}
     */
    private static function fit(int $char, int $fg, int $bg, string $charset): array
    {
        $char &= 255;
        if (in_array($char, [0, 32, 255], true)) {
            return [' ', $fg, $bg];
        }
        if ($charset === 'utf8') {
            $glyph = Cp437::glyph($char);
            if (!self::isOneSafeColumn($glyph, 'utf8')) {
                $glyph = self::asciiOf($char);
            }
            return [$glyph, $fg, $bg];
        }
        if (isset(self::CONTROLS[$char])) {
            return [self::CONTROLS[$char], $fg, $bg];
        }
        if ($charset === 'cp437') {
            // 0x80-0xFF are CP437 graphics on a CP437 session, including
            // letters such as C-cedilla at 0x80. The forbidden raw controls
            // are 0x01-0x1F and 0x7F, already replaced above.
            return [chr($char), $fg, $bg];
        }
        if ($char === 0xDB) {
            return [' ', $fg, $fg & 7];
        }
        if ($char < 0x80) {
            return [chr($char), $fg, $bg];
        }
        return [self::asciiOf($char), $fg, $bg];
    }

    private static function isOneSafeColumn(string $glyph, string $charset): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $glyph) === 1) {
            return false;
        }
        $width = $charset === 'utf8' ? mb_strwidth($glyph, 'UTF-8') : strlen($glyph);
        return $width === 1;
    }

    private static function asciiOf(int $char): string
    {
        if (isset(self::CONTROLS[$char])) {
            return self::CONTROLS[$char];
        }
        if ($char === 0xDB) {
            return '#';
        }
        if (in_array($char, [0xDC, 0xDF, 0xDD, 0xDE, 0xB2, 0xFE], true)) {
            return '#';
        }
        if ($char === 0xB1) {
            return ':';
        }
        if ($char === 0xB0) {
            return '.';
        }
        if (in_array($char, [0xC4, 0xCD], true)) {
            return '-';
        }
        if (in_array($char, [0xB3, 0xBA], true)) {
            return '|';
        }
        if ($char >= 0xB4 && $char <= 0xDA) {
            return '+';
        }
        if ($char >= 0x20 && $char < 0x7F) {
            return chr($char);
        }
        $glyph = Cp437::glyph($char);
        $folded = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $glyph) : false;
        if (is_string($folded)) {
            $folded = preg_replace('/[^ -~]/', '', $folded) ?? '';
            if (strlen($folded) === 1) {
                return $folded;
            }
        }
        return '?';
    }
}
