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

class BbsConfig
{
    private static ?array $config = null;
    private static bool $loaded = false;

    /**
     * Config keys whose value is replaced wholesale (not recursively merged)
     * when present in config/bbs.json. Needed for blocks containing list-shaped
     * children (e.g. registration_screening.signals.rbl.zones) where
     * array_replace_recursive would merge list elements by index and leave
     * stale entries behind when the operator removes one.
     *
     * @var string[]
     */
    private const REPLACE_WHOLESALE_KEYS = ['registration_screening'];

    private static function getConfigPath(): string
    {
        return __DIR__ . '/../config/bbs.json';
    }

    private static function getExamplePath(): string
    {
        return __DIR__ . '/../config/bbs.json.example';
    }

    private static function loadJsonFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        return null;
    }

    private static function getDefaults(): array
    {
        $example = self::loadJsonFile(self::getExamplePath());
        if ($example !== null) {
            return $example;
        }
        // We shouldn't get here..
        getServerLogger()->warning("example bbs.json.example missing or corrupt?");
        return [
            'features' => [
                'webdoors' => true,
                'shoutbox' => true,
                'advertising' => true,
                'voting_booth' => true,
                'pgp' => false,
                'pgp_managed_keys' => false
            ]
        ];
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        $config = self::loadJsonFile(self::getConfigPath());
        $defaults = self::getDefaults();

        if ($config === null) {
            self::$config = $defaults;
            if(self::$config==null){
                getServerLogger()->error("Unable to load ANY bbs configuration");
                throw new \Exception("Unable to load any BBS configuration");
            }
            return;
        }

        $configWithoutFeatures = $config;
        unset($configWithoutFeatures['features']);
        foreach (self::REPLACE_WHOLESALE_KEYS as $key) {
            unset($configWithoutFeatures[$key]);
        }
        $merged = self::mergeConfigRecursive($defaults, $configWithoutFeatures);
        foreach (self::REPLACE_WHOLESALE_KEYS as $key) {
            if (array_key_exists($key, $config)) {
                $merged[$key] = $config[$key];
            }
        }

        $features = $defaults['features'] ?? [];
        if (isset($config['features']) && is_array($config['features'])) {
            foreach ($config['features'] as $key => $value) {
                if (array_key_exists($key, $features)) {
                    $features[$key] = (bool)$value;
                } else {
                    $features[$key] = $value;
                }
            }
        }
        $merged['features'] = $features;
        self::$config = $merged;
    }

    public static function getConfig(): array
    {
        self::load();
        return self::$config ?? self::getDefaults();
    }

    /**
     * Merge configuration arrays recursively so nested defaults survive partial
     * overrides in config/bbs.json.
     *
     * @param array $base
     * @param array $overrides
     * @return array
     */
    private static function mergeConfigRecursive(array $base, array $overrides): array
    {
        return array_replace_recursive($base, $overrides);
    }

    public static function reload(): void
    {
        self::$loaded = false;
        self::$config = null;
    }

    /**
     * Return the configured default outgoing packet charset.
     * Defaults to CP437 if not set in bbs.json.
     *
     * @return string Canonical charset name (e.g. "CP437", "UTF-8")
     */
    /**
     * Return the number of approved networked echomail posts required before
     * a user is automatically promoted to unmoderated posting.
     *
     * @return int
     */
    /**
     * @return int Seconds before the "Are you still there?" idle warning is shown.
     */
    public static function getTerminalIdleWarnSeconds(): int
    {
        self::load();
        $minutes = (int)(self::$config['terminal_idle']['warn_minutes'] ?? 5);
        return max(60, $minutes * 60);
    }

    /**
     * @return int Seconds before an idle session is disconnected (after the warning).
     */
    public static function getTerminalIdleDisconnectSeconds(): int
    {
        self::load();
        $minutes = (int)(self::$config['terminal_idle']['disconnect_minutes'] ?? 7);
        return max(self::getTerminalIdleWarnSeconds() + 60, $minutes * 60);
    }

    /**
     * @return string The sysop-configured default shell: 'tui' or 'line'.
     */
    public static function getTerminalDefaultShell(): string
    {
        self::load();
        $shell = strtolower(trim((string)(self::$config['terminal_server']['default_shell'] ?? 'tui')));
        return self::normalizeTerminalShell($shell);
    }

    /**
     * @return bool Whether the sysop forces all sessions to use the system default shell, ignoring user preference.
     */
    public static function getTerminalForceShell(): bool
    {
        self::load();
        return !empty(self::$config['terminal_server']['force_shell']);
    }

    /**
     * Return the shell modes sysops allow users to select via TERMSERVER_ALLOWEDSHELLS.
     *
     * The environment variable accepts a space-separated list such as "tui line".
     * When unset or empty, only the TUI shell is user-selectable.
     *
     * @return array<int, string>
     */
    public static function getAllowedTerminalShells(): array
    {
        $raw = trim((string)Config::env('TERMSERVER_ALLOWEDSHELLS', 'tui'));
        if ($raw === '') {
            return ['tui'];
        }

        $registered = TerminalShellRegistry::getRegisteredShellIds();
        $allowed = [];
        foreach (preg_split('/\s+/', $raw) ?: [] as $shell) {
            $normalized = strtolower(trim((string)$shell));
            if (in_array($normalized, $registered, true) && !in_array($normalized, $allowed, true)) {
                $allowed[] = $normalized;
            }
        }

        return $allowed !== [] ? $allowed : ['tui'];
    }

    public static function isTerminalShellAllowed(string $shell): bool
    {
        $normalized = strtolower(trim($shell));
        return in_array($normalized, self::getAllowedTerminalShells(), true);
    }

    /**
     * Normalize a requested shell name, falling back to TUI for invalid or disallowed values.
     */
    public static function normalizeTerminalShell(string $shell, string $fallback = 'tui'): string
    {
        $normalized = strtolower(trim($shell));
        if (self::isTerminalShellAllowed($normalized)) {
            return $normalized;
        }

        $fallback = strtolower(trim($fallback));
        if (in_array($fallback, TerminalShellRegistry::getRegisteredShellIds(), true)) {
            return $fallback;
        }

        return TerminalShellRegistry::getRegisteredShellIds()[0] ?? 'tui';
    }

    public static function getEchomailModerationThreshold(): int
    {
        self::load();
        $value = (int)(self::$config['echomail_moderation_threshold'] ?? 0);
        return max(0, $value);
    }

    public static function shouldRequireRegistrationApproval(): bool
    {
        self::load();
        return !array_key_exists('registration_requires_approval', self::$config)
            || !empty(self::$config['registration_requires_approval']);
    }

    /**
     * Return the normalized registration screening configuration block.
     *
     * Shape mirrors config/bbs.json.example -> registration_screening. Missing
     * keys fall back to safe defaults so callers never have to null-check.
     *
     * @return array{
     *     enabled: bool, mode: string, threshold: int, dns_timeout_ms: int,
     *     signals: array<string, array<string, mixed>>
     * }
     */
    public static function getRegistrationScreeningConfig(): array
    {
        self::load();
        $raw = self::$config['registration_screening'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $mode = strtolower(trim((string)($raw['mode'] ?? 'observe')));
        if (!in_array($mode, ['enforce', 'observe'], true)) {
            $mode = 'enforce';
        }

        $signals = is_array($raw['signals'] ?? null) ? $raw['signals'] : [];

        return [
            'enabled' => !empty($raw['enabled']),
            'mode' => $mode,
            'threshold' => max(0, (int)($raw['threshold'] ?? 30)),
            'dns_timeout_ms' => min(5000, max(100, (int)($raw['dns_timeout_ms'] ?? 750))),
            'signals' => $signals,
        ];
    }

    /**
     * @return bool Whether registration risk screening runs at all.
     */
    public static function isRegistrationScreeningEnabled(): bool
    {
        return self::getRegistrationScreeningConfig()['enabled'];
    }

    /**
     * @return string 'enforce' (may downgrade a risky signup to manual review)
     *                or 'observe' (compute + store flags only, never override).
     */
    public static function getRegistrationScreeningMode(): string
    {
        return self::getRegistrationScreeningConfig()['mode'];
    }

    /**
     * @return int risk_score at or above which a signup is forced to manual review.
     */
    public static function getRegistrationScreeningThreshold(): int
    {
        return self::getRegistrationScreeningConfig()['threshold'];
    }

    public static function getOutgoingCharset(): string
    {
        self::load();
        $charset = strtoupper(trim((string)(self::$config['outgoing_charset'] ?? 'CP437')));
        return $charset ?: 'CP437';
    }

    public static function getBulletinDisplayMode(): string
    {
        self::load();
        $mode = strtolower(trim((string)(self::$config['bulletin_display_mode'] ?? 'once')));
        return in_array($mode, ['once', 'always'], true) ? $mode : 'once';
    }

    public static function shouldAlwaysDisplayBulletins(): bool
    {
        return self::getBulletinDisplayMode() === 'always';
    }

    public static function isFeatureEnabled(string $feature): bool
    {
        self::load();
        $features = self::$config['features'] ?? [];
        return !empty($features[$feature]);
    }

    /**
     * Get a feature setting value with optional default
     *
     * @param string $feature Feature key to retrieve
     * @param mixed $default Default value if feature not set
     * @return mixed Feature value or default
     */
    public static function getFeatureSetting(string $feature, $default = null)
    {
        self::load();
        $features = self::$config['features'] ?? [];
        return $features[$feature] ?? $default;
    }

    public static function saveConfig(array $config): bool
    {
        $defaults = self::getDefaults();
        $path = self::getConfigPath();
        $existing = self::loadJsonFile($path) ?? [];

        $existingWithoutFeatures = $existing;
        unset($existingWithoutFeatures['features']);
        $configWithoutFeatures = $config;
        unset($configWithoutFeatures['features']);
        foreach (self::REPLACE_WHOLESALE_KEYS as $key) {
            unset($existingWithoutFeatures[$key], $configWithoutFeatures[$key]);
        }

        $sanitized = self::mergeConfigRecursive($defaults, $existingWithoutFeatures);
        $sanitized = self::mergeConfigRecursive($sanitized, $configWithoutFeatures);

        // Replace-wholesale blocks: prefer the incoming value, else keep what
        // was already on disk, else fall back to the example defaults.
        foreach (self::REPLACE_WHOLESALE_KEYS as $key) {
            if (array_key_exists($key, $config)) {
                $sanitized[$key] = $config[$key];
            } elseif (array_key_exists($key, $existing)) {
                $sanitized[$key] = $existing[$key];
            }
        }

        $features = $defaults['features'] ?? [];
        if (isset($existing['features']) && is_array($existing['features'])) {
            foreach ($existing['features'] as $key => $value) {
                if (array_key_exists($key, $features)) {
                    $features[$key] = (bool)$value;
                } else {
                    $features[$key] = $value;
                }
            }
        }
        if (isset($config['features']) && is_array($config['features'])) {
            foreach ($config['features'] as $key => $value) {
                if (array_key_exists($key, $features)) {
                    $features[$key] = (bool)$value;
                } else {
                    $features[$key] = $value;
                }
            }
        }
        $sanitized['features'] = $features;

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $result = @file_put_contents($path, $json . PHP_EOL);
        if ($result === false) {
            return false;
        }

        self::$config = $sanitized;
        return true;
    }
}

