<?php

namespace BinktermPHP;

class WebDoorManifest
{
    public static function getManifestDirectory(): string
    {
        return __DIR__ . '/../public_html/webdoors';
    }

    public static function listManifests(): array
    {
        $dir = self::getManifestDirectory();
        if (!is_dir($dir)) {
            return [];
        }

        $entries = scandir($dir);
        if (!$entries) {
            return [];
        }

        $manifests = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $manifestPath = $dir . '/' . $entry . '/webdoor.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifestJson = @file_get_contents($manifestPath);
            $manifest = json_decode($manifestJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($manifest)) {
                continue;
            }

            $game = $manifest['game'] ?? [];
            $gameId = $game['id'] ?? $entry;

            $manifests[] = [
                'id' => $gameId,
                'path' => $entry,
                'manifest' => $manifest
            ];
        }

        return $manifests;
    }
}
