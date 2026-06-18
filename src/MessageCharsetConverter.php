<?php

namespace BinktermPHP;

class MessageCharsetConverter
{
    /**
     * @return string[]
     */
    public static function getFallbackCharsetOrder(): array
    {
        return ['CP437', 'CP850', 'ISO-8859-1', 'CP1252'];
    }

    public static function normalizeSupportedCharset(?string $charset): ?string
    {
        $value = trim((string)$charset);
        if ($value === '') {
            return null;
        }

        $normalized = \BinktermPHP\Binkp\Config\BinkpConfig::normalizeCharset($value);
        return self::isSupportedCharset($normalized) ? $normalized : null;
    }

    public static function normalizeDecodableCharset(?string $charset): ?string
    {
        $value = trim((string)$charset);
        if ($value === '') {
            return null;
        }

        $normalized = \BinktermPHP\Binkp\Config\BinkpConfig::normalizeCharset($value);
        $allowed = [
            'UTF-8',
            'CP437',
            'CP850',
            'CP852',
            'CP866',
            'CP1250',
            'CP1251',
            'CP1252',
            'ISO-8859-1',
            'ISO-8859-2',
            'ISO-8859-5',
            'KOI8-R',
            'KOI8-U',
        ];

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    public static function isSupportedCharset(string $charset): bool
    {
        $normalized = \BinktermPHP\Binkp\Config\BinkpConfig::normalizeCharset($charset);

        foreach (\BinktermPHP\Binkp\Config\BinkpConfig::getSupportedCharsets() as $supported) {
            if (($supported['value'] ?? '') === $normalized) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeRawBytes(mixed $rawBytes): string
    {
        if (is_resource($rawBytes)) {
            $rawBytes = stream_get_contents($rawBytes);
        }

        if (is_string($rawBytes) && str_starts_with($rawBytes, '\\x')) {
            $decoded = @hex2bin(substr($rawBytes, 2));
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return is_string($rawBytes) ? $rawBytes : '';
    }

    /**
     * @param string[]|null $fallbackCharsets
     * @return array{text: string, charset: string|null}
     */
    public static function decodeToUtf8WithCharset(string $bytes, ?string $preferredCharset = null, ?array $fallbackCharsets = null): array
    {
        $preferred = self::normalizeDecodableCharset($preferredCharset);
        if ($preferred !== null) {
            return [
                'text' => self::convertUsingCharset($bytes, $preferred),
                'charset' => $preferred,
            ];
        }

        if ($bytes === '' || mb_check_encoding($bytes, 'UTF-8')) {
            return ['text' => $bytes, 'charset' => 'UTF-8'];
        }

        $encodings = $fallbackCharsets ?? self::getFallbackCharsetOrder();
        foreach ($encodings as $encoding) {
            $normalized = self::normalizeDecodableCharset($encoding);
            if ($normalized === null) {
                continue;
            }

            return [
                'text' => self::convertUsingCharset($bytes, $normalized),
                'charset' => $normalized,
            ];
        }

        return [
            'text' => mb_convert_encoding($bytes, 'UTF-8', 'UTF-8'),
            'charset' => null,
        ];
    }

    public static function decodeStoredMessageBytes(mixed $rawBytes, ?string $charset): ?string
    {
        $bytes = self::normalizeRawBytes($rawBytes);
        if ($bytes === '') {
            return null;
        }

        $preferred = self::normalizeDecodableCharset($charset);
        if ($preferred === null) {
            return null;
        }

        return self::convertUsingCharset($bytes, $preferred);
    }

    private static function convertUsingCharset(string $bytes, string $charset): string
    {
        if ($charset === 'UTF-8') {
            return mb_check_encoding($bytes, 'UTF-8')
                ? $bytes
                : mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        }

        if (function_exists('iconv')) {
            try {
                $converted = iconv($charset, 'UTF-8//IGNORE', $bytes);
                if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                    return $converted;
                }
            } catch (\Throwable) {
            }
        }

        try {
            $converted = mb_convert_encoding($bytes, 'UTF-8', $charset);
            if (mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        } catch (\ValueError) {
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
    }
}
