<?php

namespace BinktermPHP;

class BbsConfig
{
    private static ?array $config = null;
    private static bool $loaded = false;

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
        error_log("example bbs.json.example missing or corrupt?");
        return [
            'features' => [
                'webdoors' => true,
                'shoutbox' => true,
                'advertising' => true,
                'voting_booth' => true
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
                error_log("Unable to load ANY bbs configuration");
                throw new \Exception("Unable to load any BBS configuration");
            }
            return;
        }

        $merged = $defaults;
        if (isset($config['features']) && is_array($config['features'])) {
            foreach ($defaults['features'] as $key => $value) {
                if (array_key_exists($key, $config['features'])) {
                    $merged['features'][$key] = (bool)$config['features'][$key];
                }
            }
        }
        self::$config = $merged;
    }

    public static function getConfig(): array
    {
        self::load();
        return self::$config ?? self::getDefaults();
    }

    public static function reload(): void
    {
        self::$loaded = false;
        self::$config = null;
    }

    public static function isFeatureEnabled(string $feature): bool
    {
        self::load();
        $features = self::$config['features'] ?? [];
        return !empty($features[$feature]);
    }

    public static function saveConfig(array $config): bool
    {
        $defaults = self::getDefaults();
        $sanitized = $defaults;

        if (isset($config['features']) && is_array($config['features'])) {
            foreach ($defaults['features'] as $key => $value) {
                if (array_key_exists($key, $config['features'])) {
                    $sanitized['features'][$key] = (bool)$config['features'][$key];
                }
            }
        }

        $path = self::getConfigPath();
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
