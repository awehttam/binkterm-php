<?php

namespace BinktermPHP\DatabasePlatform;

use PDO;

/**
 * Defines database-engine-specific bootstrap behavior.
 */
interface DatabasePlatformInterface
{
    /**
     * Return the logical platform name.
     */
    public function getName(): string;

    /**
     * Build a PDO DSN from the normalized database config array.
     *
     * @param array<string, mixed> $config
     */
    public function createDsn(array $config): string;

    /**
     * Apply session-level initialization such as timezone or application name.
     */
    public function initializeSession(PDO $pdo, bool $useUtcTimezone): void;

    /**
     * Return the absolute path to the base schema file for this platform.
     */
    public function getBaseSchemaPath(string $projectRoot): string;

    /**
     * Whether the platform supports native realtime notifications.
     */
    public function supportsRealtimeNotify(): bool;
}
