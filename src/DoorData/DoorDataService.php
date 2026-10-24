<?php

namespace BinktermPHP\DoorData;

use BinktermPHP\DosBoxDoorManifest;
use BinktermPHP\NativeDoorManifest;

/**
 * Resolves a DOS or native door's data directory and runs its registered parser.
 *
 * A door opts in by setting "data_parser" (and optionally "data_dir") in
 * its dosdoor.jsn or nativedoor.json manifest.
 *
 * @package BinktermPHP
 */
class DoorDataService
{
    private string $basePath;
    private DosBoxDoorManifest $manifests;
    private NativeDoorManifest $nativeManifests;

    /**
     * @param string|null $basePath BinktermPHP base path
     */
    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BINKTERMPHP_BASEDIR')
            ? BINKTERMPHP_BASEDIR
            : dirname(__DIR__, 2));
        $this->manifests = new DosBoxDoorManifest($this->basePath);
        $this->nativeManifests = new NativeDoorManifest($this->basePath);
    }

    /**
     * Load a door manifest, preferring DOS doors and falling back to native doors.
     *
     * @param string $doorId Door identifier
     * @return array|null Normalized manifest or null if not installed
     */
    private function getManifest(string $doorId): ?array
    {
        return $this->manifests->getDoorManifest($doorId)
            ?? $this->nativeManifests->getDoorManifest($doorId);
    }

    /**
     * Whether the door has a configured, known parser.
     *
     * @param string $doorId Door identifier
     * @return bool
     */
    public function isSupported(string $doorId): bool
    {
        $manifest = $this->getManifest($doorId);
        return $manifest !== null
            && !empty($manifest['data_parser'])
            && DoorDataParserRegistry::get((string)$manifest['data_parser']) !== null;
    }

    /**
     * Parse a door's players, hiding deleted slots, sorted by score descending.
     *
     * @param string $doorId Door identifier
     * @return array<int, array> Normalized player entries
     * @throws DoorDataParseException When unsupported or the data cannot be parsed
     */
    public function getPlayers(string $doorId): array
    {
        $manifest = $this->getManifest($doorId);
        if ($manifest === null || empty($manifest['data_parser'])) {
            throw new DoorDataParseException("Door '$doorId' has no data parser configured");
        }
        $parser = DoorDataParserRegistry::get((string)$manifest['data_parser']);
        if ($parser === null) {
            throw new DoorDataParseException("Unknown data parser '{$manifest['data_parser']}'");
        }

        $players = array_values(array_filter(
            $parser->parsePlayers($this->resolveDataDir($manifest)),
            static fn(array $p): bool => empty($p['deleted'])
        ));
        usort($players, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return $players;
    }

    /**
     * Resolve and validate the data directory for a manifest.
     *
     * @param array $manifest Normalized door manifest
     * @return string Absolute directory path
     * @throws DoorDataParseException If the path escapes the door type's doors directory
     */
    private function resolveDataDir(array $manifest): string
    {
        $isNative = ($manifest['type'] ?? '') === 'nativedoor';
        $root = realpath($this->basePath . ($isNative ? '/native-doors/doors' : '/dosbox-bridge/dos/DOORS'));
        $dir = realpath($this->basePath . '/' . $manifest['directory'] . '/' . ($manifest['data_dir'] ?? '.'));
        if ($root === false || $dir === false || !is_dir($dir)
            || ($dir !== $root && strpos($dir, $root . DIRECTORY_SEPARATOR) !== 0)) {
            throw new DoorDataParseException("Invalid data directory for door '{$manifest['door_id']}'");
        }
        return $dir;
    }
}
