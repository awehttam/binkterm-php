<?php
/**
 * Native Door Manifest Scanner
 *
 * Scans for native Linux door games with nativedoor.json manifests and provides
 * information about installed doors for configuration and display.
 *
 * Native doors run directly via PTY (pseudo-terminal) with no emulator overhead.
 * User data is passed via DOOR.SYS drop file and environment variables.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

use Exception;

class NativeDoorManifest
{
    private $basePath;
    private $doorsBasePath;
    private $manifestCache = [];

    /**
     * Constructor
     *
     * @param string|null $basePath Base path for BinktermPHP
     */
    public function __construct($basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BINKTERMPHP_BASEDIR')
            ? BINKTERMPHP_BASEDIR
            : __DIR__ . '/..');

        $this->doorsBasePath = $this->basePath . '/native-doors/doors';
    }

    /**
     * Scan for all installed native doors
     *
     * @return array Array of door manifests keyed by door_id
     */
    public function scanInstalledDoors(): array
    {
        $doors = [];

        if (!is_dir($this->doorsBasePath)) {
            return $doors;
        }

        $subdirs = glob($this->doorsBasePath . '/*', GLOB_ONLYDIR);

        foreach ($subdirs as $doorDir) {
            $manifestPath = $doorDir . '/nativedoor.json';

            if (file_exists($manifestPath)) {
                try {
                    $manifest = $this->parseManifest($manifestPath, basename($doorDir));
                    if ($manifest && $manifest['type'] === 'nativedoor') {
                        $doorId = basename($doorDir);
                        $doors[$doorId] = $manifest;
                    }
                } catch (Exception $e) {
                    error_log("Invalid native door manifest at $manifestPath: " . $e->getMessage());
                }
            }
        }

        return $doors;
    }

    /**
     * Get manifest for a specific door
     *
     * @param string $doorId Door identifier (directory name)
     * @return array|null Manifest data or null if not found
     */
    public function getDoorManifest(string $doorId): ?array
    {
        if (isset($this->manifestCache[$doorId])) {
            return $this->manifestCache[$doorId];
        }

        $manifestPath = $this->doorsBasePath . '/' . $doorId . '/nativedoor.json';

        if (!file_exists($manifestPath)) {
            return null;
        }

        try {
            $manifest = $this->parseManifest($manifestPath, $doorId);
            $this->manifestCache[$doorId] = $manifest;
            return $manifest;
        } catch (Exception $e) {
            error_log("Error reading native door manifest for $doorId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse a nativedoor.json manifest file
     *
     * @param string $manifestPath Path to nativedoor.json
     * @param string $doorId Door identifier
     * @return array Parsed manifest data
     * @throws Exception If manifest is invalid
     */
    private function parseManifest(string $manifestPath, string $doorId): array
    {
        $json = file_get_contents($manifestPath);
        $data = json_decode($json, true);

        if ($data === null) {
            throw new Exception("Invalid JSON in manifest");
        }

        if (!isset($data['type']) || $data['type'] !== 'nativedoor') {
            throw new Exception("Not a nativedoor type manifest");
        }

        if (!isset($data['game']['name'])) {
            throw new Exception("Missing game name");
        }

        if (!isset($data['door']['executable'])) {
            throw new Exception("Missing door executable");
        }

        return [
            'door_id' => $doorId,
            'type' => $data['type'],
            'version' => $data['version'] ?? '1.0',

            // Game info
            'name' => $data['game']['name'],
            'short_name' => $data['game']['short_name'] ?? $data['game']['name'],
            'author' => $data['game']['author'] ?? 'Unknown',
            'game_version' => $data['game']['version'] ?? null,
            'release_year' => $data['game']['release_year'] ?? null,
            'description' => $data['game']['description'] ?? '',
            'genre' => $data['game']['genre'] ?? [],
            'players' => $data['game']['players'] ?? 'Single-player',
            'icon' => $data['game']['icon'] ?? null,
            'screenshot' => $data['game']['screenshot'] ?? null,

            // Door technical info
            'executable' => $data['door']['executable'],
            'launch_command' => $data['door']['launch_command'] ?? null,
            'directory' => 'native-doors/doors/' . $doorId,
            'dropfile_format' => $data['door']['dropfile_format'] ?? 'DOOR.SYS',
            'node_support' => true,
            'max_nodes' => $data['door']['max_nodes'] ?? 10,
            'ansi_required' => $data['door']['ansi_required'] ?? true,
            'time_per_day' => $data['door']['time_per_day'] ?? 30,

            // Requirements
            'requirements' => $data['requirements'] ?? [],
            'admin_only' => !empty($data['requirements']['admin_only']),

            // Default config (can be overridden via nativedoors.json)
            'config' => [
                'enabled' => $data['config']['enabled'] ?? false,
                'credit_cost' => $data['config']['credit_cost'] ?? 0,
                'max_time_minutes' => $data['config']['max_time_minutes'] ?? 30,
                'max_sessions' => $data['config']['max_sessions'] ?? 10,
            ],
        ];
    }

    /**
     * Check if a door is installed
     *
     * @param string $doorId Door identifier
     * @return bool True if door exists with a valid manifest
     */
    public function isDoorInstalled(string $doorId): bool
    {
        $doorPath = $this->doorsBasePath . '/' . $doorId;
        $manifestPath = $doorPath . '/nativedoor.json';

        return is_dir($doorPath) && file_exists($manifestPath);
    }

    /**
     * Get count of installed doors
     *
     * @return int Number of installed doors
     */
    public function getInstalledCount(): int
    {
        return count($this->scanInstalledDoors());
    }
}
