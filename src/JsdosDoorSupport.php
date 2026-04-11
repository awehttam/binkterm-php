<?php

namespace BinktermPHP;

/**
 * Helper utilities for JS-DOS door manifests, modes, and file sync storage.
 */
class JsdosDoorSupport
{
    /**
     * Normalize a manifest into a mode-aware structure while keeping backward compatibility.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function normalizeManifest(array $manifest): array
    {
        $emulator = (string)($manifest['emulator'] ?? 'jsdos');
        $modes = [];

        $playMode = [
            'id' => 'play',
            'label' => 'Play',
            'admin_only' => false,
            'keep_open' => false,
            'emulator' => $emulator,
            'emulator_config' => is_array($manifest['emulator_config'] ?? null) ? $manifest['emulator_config'] : [],
            'saves' => is_array($manifest['saves'] ?? null) ? $manifest['saves'] : [],
        ];

        $declaredModes = is_array($manifest['modes'] ?? null) ? $manifest['modes'] : [];
        if (is_array($declaredModes['play'] ?? null)) {
            $playMode = array_replace_recursive($playMode, $declaredModes['play']);
        }

        $modes['play'] = self::normalizeModeConfig('play', $playMode, $emulator);

        foreach ($declaredModes as $modeId => $modeConfig) {
            if ($modeId === 'play' || !is_string($modeId) || !is_array($modeConfig)) {
                continue;
            }

            $defaults = [
                'id' => $modeId,
                'label' => self::humanizeModeId($modeId),
                'admin_only' => false,
                'keep_open' => false,
                'emulator' => $emulator,
                'emulator_config' => [],
                'saves' => [],
            ];

            $modes[$modeId] = self::normalizeModeConfig($modeId, array_replace_recursive($defaults, $modeConfig), $emulator);
        }

        $manifest['modes'] = $modes;
        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, array<string, mixed>>
     */
    public static function listModes(array $manifest): array
    {
        $normalized = self::normalizeManifest($manifest);
        return is_array($normalized['modes'] ?? null) ? $normalized['modes'] : [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>|null
     */
    public static function getMode(array $manifest, string $modeId = 'play'): ?array
    {
        $modes = self::listModes($manifest);
        return $modes[$modeId] ?? null;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function hasMode(array $manifest, string $modeId): bool
    {
        return self::getMode($manifest, $modeId) !== null;
    }

    /**
     * @param array<string, mixed> $mode
     * @param array<string, mixed>|null $user
     */
    public static function canUserAccessMode(array $mode, ?array $user): bool
    {
        if (empty($mode['admin_only'])) {
            return true;
        }

        return !empty($user['is_admin']);
    }

    /**
     * @param array<string, mixed> $mode
     * @return array<string, mixed>
     */
    public static function getSaveConfig(array $mode): array
    {
        $config = is_array($mode['saves'] ?? null) ? $mode['saves'] : [];
        $paths = [];
        foreach (($config['save_paths'] ?? []) as $path) {
            if (is_string($path) && trim($path) !== '') {
                $paths[] = self::normalizeDosPattern($path);
            }
        }

        return [
            'enabled' => !empty($config['enabled']) && !empty($paths),
            'save_paths' => $paths,
            'max_size_kb' => max(1, (int)($config['max_size_kb'] ?? 512)),
            'scope' => (($config['scope'] ?? 'user') === 'shared') ? 'shared' : 'user',
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    public static function getSharedSaveModes(array $manifest): array
    {
        $shared = [];
        foreach (self::listModes($manifest) as $mode) {
            $saveConfig = self::getSaveConfig($mode);
            if ($saveConfig['enabled'] && $saveConfig['scope'] === 'shared') {
                $mode['saves'] = $saveConfig;
                $shared[] = $mode;
            }
        }

        return $shared;
    }

    public static function getSharedStorageDirectory(string $gameId): string
    {
        return __DIR__ . '/../data/jsdos-shared/' . basename($gameId);
    }

    public static function getUserStorageDirectory(int $userId, string $gameId): string
    {
        $fileAreaManager = new FileAreaManager();
        $privateArea = $fileAreaManager->getOrCreatePrivateFileArea($userId);
        $tag = (string)($privateArea['tag'] ?? ('PRIVATE_USER_' . $userId));
        $id = !empty($privateArea['id']) ? (string)$privateArea['id'] : '';
        $dirName = $id !== '' ? ($tag . '-' . $id) : $tag;

        return __DIR__ . '/../data/files/' . $dirName . '/doors/' . basename($gameId);
    }

    /**
     * @param array<string, mixed> $saveConfig
     * @return array<string, string>
     */
    public static function loadStoredFiles(string $baseDir, array $saveConfig): array
    {
        if (empty($saveConfig['enabled']) || !is_dir($baseDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $absolutePath = $fileInfo->getPathname();
            $relativePath = substr($absolutePath, strlen(rtrim($baseDir, '/\\')) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);
            $dosPath = 'C:/' . ltrim($relativePath, '/');

            if (!self::matchesAllowedPath($dosPath, $saveConfig['save_paths'])) {
                continue;
            }

            $contents = @file_get_contents($absolutePath);
            if ($contents === false) {
                continue;
            }

            $files[self::normalizeDosPattern($dosPath)] = $contents;
        }

        ksort($files);
        return $files;
    }

    /**
     * @param array<string, mixed> $saveConfig
     */
    public static function writeStoredFile(string $baseDir, array $saveConfig, string $dosPath, string $contents): void
    {
        $normalizedPath = self::normalizeDosPattern($dosPath);
        if (!self::matchesAllowedPath($normalizedPath, $saveConfig['save_paths'])) {
            throw new \RuntimeException('Path is not allowed for this JS-DOS mode');
        }

        $maxBytes = ((int)$saveConfig['max_size_kb']) * 1024;
        if (strlen($contents) > $maxBytes) {
            throw new \RuntimeException('File exceeds configured maximum size');
        }

        $relativePath = self::dosPathToRelative($normalizedPath);
        $targetPath = rtrim($baseDir, '/\\') . '/' . $relativePath;
        $targetDir = dirname($targetPath);

        FileAreaManager::ensureDirectoryExists($targetDir);

        if (@file_put_contents($targetPath, $contents) === false) {
            throw new \RuntimeException('Failed to write JS-DOS file');
        }
    }

    /**
     * @param array<string, mixed> $saveConfig
     */
    public static function deleteStoredFile(string $baseDir, array $saveConfig, string $dosPath): void
    {
        $normalizedPath = self::normalizeDosPattern($dosPath);
        if (!self::matchesAllowedPath($normalizedPath, $saveConfig['save_paths'])) {
            throw new \RuntimeException('Path is not allowed for this JS-DOS mode');
        }

        $relativePath = self::dosPathToRelative($normalizedPath);
        $targetPath = rtrim($baseDir, '/\\') . '/' . $relativePath;
        if (is_file($targetPath) && !@unlink($targetPath)) {
            throw new \RuntimeException('Failed to delete JS-DOS file');
        }
    }

    /**
     * @param array<int, string> $patterns
     */
    public static function matchesAllowedPath(string $dosPath, array $patterns): bool
    {
        $normalizedPath = self::normalizeDosPattern($dosPath);
        foreach ($patterns as $pattern) {
            if (preg_match(self::globToRegex(self::normalizeDosPattern($pattern)), $normalizedPath) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function dosPathToRelative(string $dosPath): string
    {
        $normalized = self::normalizeDosPattern($dosPath);
        if (!preg_match('/^[A-Z]:\/(.+)$/', $normalized, $matches)) {
            throw new \RuntimeException('Invalid DOS path');
        }

        $relative = $matches[1];
        if (str_contains($relative, '..')) {
            throw new \RuntimeException('Path traversal is not allowed');
        }

        return $relative;
    }

    public static function normalizeDosPattern(string $dosPath): string
    {
        $normalized = str_replace('\\', '/', trim($dosPath));
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

        if (preg_match('/^([A-Za-z]):/', $normalized, $matches) === 1) {
            $drive = strtoupper($matches[1]);
            $normalized = $drive . substr($normalized, 1);
        }

        return $normalized;
    }

    private static function humanizeModeId(string $modeId): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $modeId));
    }

    /**
     * @param array<string, mixed> $mode
     * @return array<string, mixed>
     */
    private static function normalizeModeConfig(string $modeId, array $mode, string $defaultEmulator): array
    {
        $mode['id'] = $modeId;
        $mode['label'] = (string)($mode['label'] ?? self::humanizeModeId($modeId));
        $mode['admin_only'] = !empty($mode['admin_only']);
        $mode['keep_open'] = !empty($mode['keep_open']);
        $mode['emulator'] = (string)($mode['emulator'] ?? $defaultEmulator);
        $mode['emulator_config'] = is_array($mode['emulator_config'] ?? null) ? $mode['emulator_config'] : [];
        $mode['saves'] = self::getSaveConfig($mode);

        return $mode;
    }

    private static function globToRegex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '/');
        $quoted = str_replace(['\*', '\?'], ['.*', '.'], $quoted);
        return '/^' . $quoted . '$/i';
    }
}
