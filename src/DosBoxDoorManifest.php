<?php
/**
 * DOSBox Door Manifest Scanner
 *
 * Scans for DOS door games with dosdoor.json manifests and provides
 * information about installed doors for configuration and display.
 *
 * Note: DOS doors use dosdoor.json (not webdoor.json) because they are
 * fundamentally different from WebDoors - they run in DOSBox via bridge,
 * not as web applications.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

use Exception;

class DosBoxDoorManifest
{
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

        // Base path for DOS doors
        $this->doorsBasePath = $this->basePath . '/dosbox-bridge/dos/doors';
    }

    /**
     * Scan for all installed DOS doors
     *
     * @return array Array of door manifests keyed by door_id
     */
    public function scanInstalledDoors(): array
    {
        $doors = [];

        if (!is_dir($this->doorsBasePath)) {
            return $doors;
        }

        // Scan each subdirectory for dosdoor.json
        $subdirs = glob($this->doorsBasePath . '/*', GLOB_ONLYDIR);

        foreach ($subdirs as $doorDir) {
            $manifestPath = $doorDir . '/dosdoor.json';

            if (file_exists($manifestPath)) {
                try {
                    $manifest = $this->parseManifest($manifestPath, basename($doorDir));
                    if ($manifest && $manifest['type'] === 'dosdoor') {
                        $doorId = basename($doorDir);
                        $doors[$doorId] = $manifest;
                    }
                } catch (Exception $e) {
                    // Skip doors with invalid manifests
                    error_log("Invalid door manifest at $manifestPath: " . $e->getMessage());
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
        // Check cache first
        if (isset($this->manifestCache[$doorId])) {
            return $this->manifestCache[$doorId];
        }

        $manifestPath = $this->doorsBasePath . '/' . $doorId . '/dosdoor.json';

        if (!file_exists($manifestPath)) {
            return null;
        }

        try {
            $manifest = $this->parseManifest($manifestPath, $doorId);
            $this->manifestCache[$doorId] = $manifest;
            return $manifest;
        } catch (Exception $e) {
            error_log("Error reading door manifest for $doorId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse a dosdoor.json manifest file
     *
     * @param string $manifestPath Path to dosdoor.json
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

        // Validate required fields
        if (!isset($data['type']) || $data['type'] !== 'dosdoor') {
            throw new Exception("Not a dosdoor type manifest");
        }

        if (!isset($data['game']['name'])) {
            throw new Exception("Missing game name");
        }

        if (!isset($data['door']['executable'])) {
            throw new Exception("Missing door executable");
        }

        // Build standardized manifest structure
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
            'screenshot' => $data['game']['screenshot'] ?? null,

            // Door technical info
            'executable' => $data['door']['executable'],
            'launch_command' => $data['door']['launch_command'] ?? null,
            'directory' => $data['door']['directory'] ?? "dosbox-bridge/dos/doors/$doorId",
            'dropfile_format' => $data['door']['dropfile_format'] ?? 'DOOR.SYS',
            'node_support' => $data['door']['node_support'] ?? true,
            'max_nodes' => $data['door']['max_nodes'] ?? 10,
            'fossil_required' => $data['door']['fossil_required'] ?? true,
            'ansi_required' => $data['door']['ansi_required'] ?? false,
            'time_per_day' => $data['door']['time_per_day'] ?? 30,

            // Requirements
            'requirements' => $data['requirements'] ?? [],

            // Default config (can be overridden in database)
            'config' => [
                'enabled' => $data['config']['enabled'] ?? false,
                'credit_cost' => $data['config']['credit_cost'] ?? 0,
                'max_time_minutes' => $data['config']['max_time_minutes'] ?? 30,
                'cpu_cycles' => $data['config']['cpu_cycles'] ?? 10000,
                'max_sessions' => $data['config']['max_sessions'] ?? 10,
            ],
        ];
    }

    /**
     * Check if a door is installed
     *
     * @param string $doorId Door identifier
     * @return bool True if door exists
     */
    public function isDoorInstalled(string $doorId): bool
    {
        $doorPath = $this->doorsBasePath . '/' . $doorId;
        $manifestPath = $doorPath . '/dosdoor.json';

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
