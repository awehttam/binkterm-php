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
            return \BinktermPHP\Binkp\Config\BinkpConfig::normalizeCharset($encoding);
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

        $hasAnsiSequences = preg_match('/\x1b\[[0-9;?]*[A-Za-z]/', $rawBody) === 1;
        if ($hasAnsiSequences && self::isAmigaAnsiEncoding($normalizedEncoding)) {
            return 'amiga_ansi';
        }

        if ($hasAnsiSequences) {
            return 'ansi';
        }

        return null;
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
}
