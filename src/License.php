<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

namespace BinktermPHP;

/**
 * Offline-signed license verification using Ed25519 (libsodium).
 *
 * The public key is embedded here. The private key never leaves the
 * project maintainer's machine. License files live at data/license.json
 * and are verified once per process/request, then cached in memory.
 *
 * Failure is always safe: missing or invalid licenses fall back to
 * Community Edition without disrupting mail processing or daemons.
 */
final class License
{
    /**
     * Ed25519 public key (base64-encoded, 32 bytes).
     * The corresponding private key is kept offline by the project maintainer.
     */
    private const PUBLIC_KEY_BASE64 = 'fopFI+s+0lx8Kyvs4THMz22sHm6ovbV72zJcQGuGr4k=';

    private const DEFAULT_LICENSE_PATH = 'data/license.json';

    /** @var array<string,mixed>|null */
    private static ?array $cached = null;

    /**
     * Return the full parsed license status array.
     *
     * Always includes at minimum:
     *   valid       bool
     *   tier        string  ('community', 'registered', 'sponsor')
     *   reason      string  ('valid', 'missing', 'malformed', 'invalid_signature', 'expired', …)
     *
     * @return array<string,mixed>
     */
    public static function getStatus(): array
    {
        return self::parse();
    }

    /** Whether a valid, non-expired license is loaded. */
    public static function isValid(): bool
    {
        return (bool)(self::parse()['valid'] ?? false);
    }

    /** License tier: 'community', 'registered', or 'sponsor'. */
    public static function getTier(): string
    {
        return (string)(self::parse()['tier'] ?? 'community');
    }

    /** Licensee name, or null when unlicensed. */
    public static function getLicensee(): ?string
    {
        $v = self::parse()['licensee'] ?? null;
        return is_string($v) ? $v : null;
    }

    /** System name from the license payload, or null when unlicensed. */
    public static function getSystemName(): ?string
    {
        $v = self::parse()['system_name'] ?? null;
        return is_string($v) ? $v : null;
    }

    /**
     * Check whether a specific named feature is unlocked by the license.
     *
     * Feature names are defined in the license payload's `features` array.
     */
    public static function hasFeature(string $feature): bool
    {
        $features = self::parse()['features'] ?? [];
        return is_array($features) && in_array($feature, $features, true);
    }

    /**
     * ISO 8601 expiry timestamp, or null for perpetual licenses.
     * Returns a value even when the license has expired.
     */
    public static function getExpiry(): ?string
    {
        $v = self::parse()['expires_at'] ?? null;
        return is_string($v) ? $v : null;
    }

    /**
     * Clear the cached parse result. Useful in tests or after uploading a new license.
     */
    public static function clearCache(): void
    {
        self::$cached = null;
    }

    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function parse(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $path = Config::env('LICENSE_FILE', self::DEFAULT_LICENSE_PATH);

        // Resolve relative paths against the project root (one level above src/).
        if (!self::isAbsolutePath($path)) {
            $path = dirname(__DIR__) . '/' . ltrim($path, '/\\');
        }

        self::$cached = self::load($path);
        return self::$cached;
    }

    /** @return array<string,mixed> */
    private static function load(string $path): array
    {
        $base = ['valid' => false, 'tier' => 'community', 'features' => []];

        if (!file_exists($path)) {
            return array_merge($base, ['reason' => 'missing']);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return array_merge($base, ['reason' => 'unreadable']);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['payload'], $data['signature'])) {
            return array_merge($base, ['reason' => 'malformed']);
        }

        if (!is_array($data['payload']) || !is_string($data['signature'])) {
            return array_merge($base, ['reason' => 'malformed']);
        }

        // Verify signature.
        // The signature is over the canonical JSON of the payload object,
        // encoded with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE.
        $payloadJson = json_encode($data['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            return array_merge($base, ['reason' => 'malformed']);
        }

        $signature = base64_decode($data['signature'], true);
        $publicKey = base64_decode(self::PUBLIC_KEY_BASE64, true);

        if ($signature === false || $publicKey === false) {
            return array_merge($base, ['reason' => 'invalid_key']);
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return array_merge($base, ['reason' => 'invalid_key']);
        }

        try {
            $signatureOk = sodium_crypto_sign_verify_detached($signature, $payloadJson, $publicKey);
        } catch (\Throwable $e) {
            $signatureOk = false;
        }

        if (!$signatureOk) {
            if (Config::env('LICENSE_LOG_INVALID', 'false') === 'true') {
                getServerLogger()->warning('[License] Signature verification failed for: ' . $path);
            }
            return array_merge($base, ['reason' => 'invalid_signature']);
        }

        $payload = $data['payload'];

        // Validate required schema fields.
        if (empty($payload['schema']) || empty($payload['license_id']) || empty($payload['tier'])) {
            return array_merge($base, ['reason' => 'malformed']);
        }

        $expiresAt = $payload['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            try {
                $expiry = new \DateTime($expiresAt, new \DateTimeZone('UTC'));
                if ($expiry < new \DateTime('now', new \DateTimeZone('UTC'))) {
                    return array_merge($base, [
                        'reason'      => 'expired',
                        'expires_at'  => $expiresAt,
                        'licensee'    => $payload['licensee'] ?? null,
                        'system_name' => $payload['system_name'] ?? null,
                        'tier'        => $payload['tier'],
                        'license_id'  => $payload['license_id'],
                    ]);
                }
            } catch (\Throwable $e) {
                // Unparseable expiry — treat as expired to be safe.
                return array_merge($base, ['reason' => 'expired']);
            }
        }

        return [
            'valid'       => true,
            'reason'      => 'valid',
            'tier'        => (string)($payload['tier'] ?? 'community'),
            'license_id'  => (string)($payload['license_id'] ?? ''),
            'licensee'    => $payload['licensee'] ?? null,
            'system_name' => $payload['system_name'] ?? null,
            'features'    => (array)($payload['features'] ?? []),
            'expires_at'  => $expiresAt ?: null,
            'issued_at'   => $payload['issued_at'] ?? null,
            'type'        => (string)($payload['type'] ?? 'perpetual'),
            'notes'       => $payload['notes'] ?? null,
        ];
    }

    private static function isAbsolutePath(string $path): bool
    {
        // Unix absolute or Windows absolute (C:\ or \\server\…).
        return str_starts_with($path, '/') ||
               str_starts_with($path, '\\') ||
               (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':');
    }
}
