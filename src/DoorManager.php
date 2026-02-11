<?php
/**
 * DOS Door Manager
 *
 * Combines manifest scanning (DosBoxDoorManifest) with configuration (DoorConfig)
 * to provide a complete view of available doors with their settings.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class DoorManager
{
    private $manifestScanner;

    public function __construct()
    {
        $this->manifestScanner = new DosBoxDoorManifest();
    }

    /**
     * Get all installed doors with their configuration merged
     *
     * @return array Doors with manifest + config data
     */
    public function getAllDoors(): array
    {
        $manifests = $this->manifestScanner->scanInstalledDoors();
        $config = DoorConfig::getAllDoors();

        $doors = [];

        foreach ($manifests as $doorId => $manifest) {
            $doorConfig = $config[$doorId] ?? [];

            // Merge manifest defaults with saved config
            $doors[$doorId] = array_merge($manifest, [
                'config' => array_merge(
                    $manifest['config'] ?? [],
                    $doorConfig
                )
            ]);
        }

        return $doors;
    }

    /**
     * Get enabled doors only (both installed and configured as enabled)
     *
     * @return array Enabled doors with full data
     */
    public function getEnabledDoors(): array
    {
        $allDoors = $this->getAllDoors();

        $enabled = [];
        foreach ($allDoors as $doorId => $door) {
            if (!empty($door['config']['enabled'])) {
                $enabled[$doorId] = $door;
            }
        }

        return $enabled;
    }

    /**
     * Get a specific door with merged config
     *
     * @param string $doorId Door identifier
     * @return array|null Door data or null if not found
     */
    public function getDoor(string $doorId): ?array
    {
        $manifest = $this->manifestScanner->getDoorManifest($doorId);

        if (!$manifest) {
            return null;
        }

        $config = DoorConfig::getDoorConfig($doorId) ?? [];

        return array_merge($manifest, [
            'config' => array_merge(
                $manifest['config'] ?? [],
                $config
            )
        ]);
    }

    /**
     * Check if a door is both installed and enabled
     *
     * @param string $doorId Door identifier
     * @return bool True if door is available to play
     */
    public function isDoorAvailable(string $doorId): bool
    {
        $door = $this->getDoor($doorId);

        if (!$door) {
            return false;
        }

        return !empty($door['config']['enabled']);
    }

    /**
     * Update door configuration
     *
     * @param string $doorId Door identifier
     * @param array $config Configuration to save
     * @return bool Success
     */
    public function updateDoorConfig(string $doorId, array $config): bool
    {
        // Verify door is installed
        if (!$this->manifestScanner->isDoorInstalled($doorId)) {
            return false;
        }

        return DoorConfig::setDoorConfig($doorId, $config);
    }

    /**
     * Get list of installed but not yet configured doors
     *
     * @return array Door IDs that are installed but not in config
     */
    public function getUnconfiguredDoors(): array
    {
        $manifests = $this->manifestScanner->scanInstalledDoors();
        $configured = DoorConfig::getAllDoors();

        $unconfigured = [];
        foreach ($manifests as $doorId => $manifest) {
            if (!isset($configured[$doorId])) {
                $unconfigured[] = $doorId;
            }
        }

        return $unconfigured;
    }

    /**
     * Add a door to configuration with default settings
     *
     * @param string $doorId Door identifier
     * @return bool Success
     */
    public function addDoorToConfig(string $doorId): bool
    {
        $manifest = $this->manifestScanner->getDoorManifest($doorId);

        if (!$manifest) {
            return false;
        }

        // Use manifest defaults as initial config
        $config = $manifest['config'] ?? [
            'enabled' => false,
            'credit_cost' => 0,
            'max_time_minutes' => 30,
            'cpu_cycles' => 10000,
            'max_concurrent_sessions' => 10
        ];

        return DoorConfig::setDoorConfig($doorId, $config);
    }
}
