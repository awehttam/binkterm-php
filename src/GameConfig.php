<?php

namespace BinktermPHP;

class GameConfig
{
    private static ?array $config = null;
    private static bool $loaded = false;

    private static function getConfigPath(): string
    {
        return __DIR__ . '/../config/webdoors.json';
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;
        $path = self::getConfigPath();

        if (!file_exists($path)) {
            self::$config = null;
            return;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            self::$config = $data;
        } else {
            self::$config = null;
        }
    }

    public static function isGameSystemEnabled(): bool
    {
        self::load();
        return self::$config !== null;
    }

    public static function isEnabled(string $game): bool
    {
        self::load();

        if (self::$config === null) {
            return false;
        }

        if (!isset(self::$config[$game])) {
            return false;
        }

        return !empty(self::$config[$game]['enabled']);
    }

    public static function getGameConfig(string $game): ?array
    {
        self::load();

        if (self::$config === null) {
            return null;
        }

        return self::$config[$game] ?? null;
    }
}
