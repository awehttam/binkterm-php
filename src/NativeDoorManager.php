<?php
/**
 * Native Door Manager
 *
 * Combines manifest scanning (NativeDoorManifest) with configuration (NativeDoorConfig)
 * to provide a complete view of available native Linux doors with their settings.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class NativeDoorManager
{
    private $manifestScanner;

    public function __construct()
    {
        $this->manifestScanner = new NativeDoorManifest();
    }

    /**
     * Get all installed doors with their configuration merged
     *
     * @return array Doors with manifest + config data
     */
    public function getAllDoors(): array
    {
        $manifests = $this->manifestScanner->scanInstalledDoors();
        $config = NativeDoorConfig::getAllDoors();

        $doors = [];

        foreach ($manifests as $doorId => $manifest) {
            $doorConfig = $config[$doorId] ?? [];

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

        $config = NativeDoorConfig::getDoorConfig($doorId) ?? [];

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
        if (!$this->manifestScanner->isDoorInstalled($doorId)) {
            return false;
        }

        return NativeDoorConfig::setDoorConfig($doorId, $config);
    }

    /**
     * Get list of installed but not yet configured doors
     *
     * @return array Door IDs that are installed but not in config
     */
    public function getUnconfiguredDoors(): array
    {
        $manifests = $this->manifestScanner->scanInstalledDoors();
        $configured = NativeDoorConfig::getAllDoors();

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

        $config = $manifest['config'] ?? [
            'enabled' => false,
            'credit_cost' => 0,
            'max_time_minutes' => 30,
            'max_sessions' => 10,
        ];

        return NativeDoorConfig::setDoorConfig($doorId, $config);
    }

    /**
     * Sync enabled doors to database
     *
     * Reads enabled doors from config and ensures they exist in dosbox_doors table
     * with door_type = 'native'. Native doors share the session infrastructure.
     *
     * @return array ['synced' => count, 'errors' => [...]]
     */
    public function syncDoorsToDatabase(): array
    {
        $db = Database::getInstance()->getPdo();
        $allDoors = $this->getAllDoors();

        $synced = 0;
        $errors = [];

        foreach ($allDoors as $doorId => $door) {
            if (empty($door['config']['enabled'])) {
                continue;
            }

            try {
                $stmt = $db->prepare("SELECT id FROM dosbox_doors WHERE door_id = ?");
                $stmt->execute([$doorId]);
                $exists = $stmt->fetch();

                $config = $door['config'];

                if ($exists) {
                    $stmt = $db->prepare("
                        UPDATE dosbox_doors
                        SET name = ?,
                            description = ?,
                            executable = ?,
                            path = ?,
                            config = ?,
                            enabled = ?,
                            door_type = ?,
                            updated_at = NOW()
                        WHERE door_id = ?
                    ");
                    $stmt->execute([
                        $door['name'],
                        $door['description'] ?? '',
                        $door['executable'],
                        $door['directory'],
                        json_encode($config),
                        'true',
                        'native',
                        $doorId
                    ]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO dosbox_doors
                        (door_id, name, description, executable, path, config, enabled, door_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $doorId,
                        $door['name'],
                        $door['description'] ?? '',
                        $door['executable'],
                        $door['directory'],
                        json_encode($config),
                        'true',
                        'native'
                    ]);
                }

                $synced++;
            } catch (\Exception $e) {
                $errors[] = "Failed to sync native door '$doorId': " . $e->getMessage();
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }
}
