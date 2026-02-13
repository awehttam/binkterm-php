<?php
/**
 * DOS Door Configuration Manager
 *
 * Manages configuration for DOS door games from config/dosdoors.json
 * Similar to GameConfig for WebDoors
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class DoorConfig
{
    private static ?array $config = null;
    private static bool $loaded = false;

    /**
     * Get path to dosdoors.json config file
     */
    private static function getConfigPath(): string
    {
        $basePath = defined('BINKTERMPHP_BASEDIR')
            ? BINKTERMPHP_BASEDIR
            : __DIR__ . '/..';

        return $basePath . '/config/dosdoors.json';
    }

    /**
     * Load door configuration from file
     */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;
        $path = self::getConfigPath();

        if (!file_exists($path)) {
            self::$config = [];
            return;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            self::$config = $data;
        } else {
            self::$config = [];
        }
    }

    /**
     * Check if door system is enabled (config file exists)
     */
    public static function isDoorSystemEnabled(): bool
    {
        self::load();
        return self::$config !== null;
    }

    /**
     * Check if a specific door is enabled
     *
     * @param string $doorId Door identifier
     * @return bool True if door is enabled
     */
    public static function isEnabled(string $doorId): bool
    {
        self::load();

        if (!isset(self::$config[$doorId])) {
            return false;
        }

        return !empty(self::$config[$doorId]['enabled']);
    }

    /**
     * Get configuration for a specific door
     *
     * @param string $doorId Door identifier
     * @return array|null Door configuration or null if not configured
     */
    public static function getDoorConfig(string $doorId): ?array
    {
        self::load();
        return self::$config[$doorId] ?? null;
    }

    /**
     * Get all configured doors
     *
     * @return array All door configurations
     */
    public static function getAllDoors(): array
    {
        self::load();
        return self::$config ?? [];
    }

    /**
     * Save door configuration to file
     *
     * @param array $config Complete configuration array
     * @return bool Success
     */
    public static function saveConfig(array $config): bool
    {
        $path = self::getConfigPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = file_put_contents($path, $json);

        // Clear cache to force reload
        self::$loaded = false;
        self::$config = null;

        return $result !== false;
    }

    /**
     * Add or update a door configuration
     *
     * @param string $doorId Door identifier
     * @param array $doorConfig Door configuration
     * @return bool Success
     */
    public static function setDoorConfig(string $doorId, array $doorConfig): bool
    {
        self::load();

        self::$config[$doorId] = $doorConfig;

        return self::saveConfig(self::$config);
    }

    /**
     * Remove a door from configuration
     *
     * @param string $doorId Door identifier
     * @return bool Success
     */
    public static function removeDoor(string $doorId): bool
    {
        self::load();

        if (!isset(self::$config[$doorId])) {
            return false;
        }

        unset(self::$config[$doorId]);

        return self::saveConfig(self::$config);
    }

    /**
     * Get enabled doors only
     *
     * @return array Enabled door configurations
     */
    public static function getEnabledDoors(): array
    {
        self::load();

        $enabled = [];
        foreach (self::$config as $doorId => $config) {
            if (!empty($config['enabled'])) {
                $enabled[$doorId] = $config;
            }
        }

        return $enabled;
    }

    /**
     * Force reload configuration from disk
     */
    public static function reload(): void
    {
        self::$loaded = false;
        self::$config = null;
        self::load();
    }
}
