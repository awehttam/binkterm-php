<?php

namespace BinktermPHP;

class SysK
{
    private const KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    private static function getKeyPath(): string
    {
        return __DIR__ . '/../data/sysk.dat';
    }

    private static function ensureKeyExists(): void
    {
        $path = self::getKeyPath();
        if (is_file($path)) {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create SysK directory');
        }

        $bytes = bin2hex(random_bytes(self::KEY_BYTES));
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to create SysK key file');
        }
    }

    private static function getKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('libsodium is required for SysK');
        }

        self::ensureKeyExists();
        $raw = trim((string)file_get_contents(self::getKeyPath()));
        $key = @hex2bin($raw);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException('Invalid SysK key material');
        }

        return $key;
    }

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::getKey());
        return base64_encode($nonce . $ciphertext);
    }

    public static function decrypt(?string $encoded): string
    {
        if ($encoded === null || trim($encoded) === '') {
            return '';
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid encrypted value');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::getKey());
        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt value');
        }

        return $plaintext;
    }
}
