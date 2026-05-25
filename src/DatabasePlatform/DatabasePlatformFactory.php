<?php

namespace BinktermPHP\DatabasePlatform;

/**
 * Resolves the configured database platform implementation.
 */
class DatabasePlatformFactory
{
    /**
     * Create the platform implementation for the given database config.
     *
     * @param array<string, mixed> $config
     */
    public static function create(array $config): DatabasePlatformInterface
    {
        $driver = strtolower(trim((string)($config['driver'] ?? 'pgsql')));

        return match ($driver) {
            'pgsql', 'postgres', 'postgresql' => new PostgresPlatform(),
            default => throw new \RuntimeException("Unsupported database driver: {$driver}"),
        };
    }
}
