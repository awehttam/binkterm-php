<?php

namespace BinktermPHP;

/**
 * Shared detector for message art formats and charset hints.
 */
class ArtFormatDetector
{
    public static function normalizeDetectedEncoding(?string $encoding, ?string $rawBody = null): ?string
    {
        if ($encoding !== null && trim($encoding) !== '') {
            return strtoupper(trim($encoding));
        }

        if (is_string($rawBody) && $rawBody !== '' && mb_check_encoding($rawBody, 'UTF-8')) {
            return 'UTF-8';
        }

        return null;
    }

    public static function detectArtFormat(?string $rawBody, ?string $detectedEncoding = null): ?string
    {
        if (!is_string($rawBody) || $rawBody === '') {
            return null;
        }

        $normalizedEncoding = strtoupper(trim((string)$detectedEncoding));

        if (self::isPetsciiEncoding($normalizedEncoding)) {
            return 'petscii';
        }

        $hasAnsiSequences = preg_match('/\x1b\[[0-9;?]*[A-Za-z]/', $rawBody) === 1;
        if ($hasAnsiSequences && self::isAmigaAnsiEncoding($normalizedEncoding)) {
            return 'amiga_ansi';
        }

        if ($hasAnsiSequences) {
            return 'ansi';
        }

        if (self::shouldUsePetsciiHeuristics($normalizedEncoding) && self::looksLikePetscii($rawBody)) {
            return 'petscii';
        }

        return null;
    }

    private static function shouldUsePetsciiHeuristics(string $encoding): bool
    {
        if ($encoding === '') {
            return true;
        }

        return $encoding !== 'UTF-8';
    }

    private static function isPetsciiEncoding(string $encoding): bool
    {
        if ($encoding === '') {
            return false;
        }

        $petsciiEncodings = [
            'PETSCII',
            'PETSCII-SHIFTED',
            'PETSCII-UNSHIFTED',
            'CBMASCII',
            'COMMODORE',
            'COMMODORE-64',
            'COMMODORE64',
            'C64',
            'C128',
        ];

        return in_array($encoding, $petsciiEncodings, true);
    }

    private static function isAmigaAnsiEncoding(string $encoding): bool
    {
        if ($encoding === '') {
            return false;
        }

        $amigaEncodings = [
            'AMIGA',
            'AMIGA-ANSI',
            'AMIGAASCII',
            'AMIGA-TOPAZ',
            'TOPAZ',
        ];

        return in_array($encoding, $amigaEncodings, true);
    }

    private static function looksLikePetscii(string $rawBody): bool
    {
        static $petsciiControlBytes = [
            0x05,
            0x11,
            0x12,
            0x13,
            0x1c,
            0x1d,
            0x1e,
            0x1f,
            0x81,
            0x90,
            0x91,
            0x92,
            0x93,
            0x95,
            0x96,
            0x97,
            0x98,
            0x99,
            0x9a,
            0x9b,
            0x9c,
            0x9d,
            0x9e,
            0x9f,
        ];

        $controlHits = 0;
        $length = strlen($rawBody);
        for ($i = 0; $i < $length; $i++) {
            if (in_array(ord($rawBody[$i]), $petsciiControlBytes, true)) {
                $controlHits++;
                if ($controlHits >= 2) {
                    return true;
                }
            }
        }

        return false;
    }
}
