<?php

namespace BinktermPHP\DatabasePlatform;

use BinktermPHP\Version;
use PDO;

/**
 * PostgreSQL-specific database bootstrap behavior.
 */
class PostgresPlatform implements DatabasePlatformInterface
{
    /**
     * Return the logical platform name.
     */
    public function getName(): string
    {
        return 'postgresql';
    }

    /**
     * Build a PostgreSQL PDO DSN.
     *
     * @param array<string, mixed> $config
     */
    public function createDsn(array $config): string
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database']
        );

        $ssl = $config['ssl'] ?? [];
        if (!empty($ssl['enabled'])) {
            $dsn .= ';sslmode=require';
            if (!empty($ssl['ca_cert'])) {
                $dsn .= ';sslrootcert=' . $ssl['ca_cert'];
            }
            if (!empty($ssl['client_cert'])) {
                $dsn .= ';sslcert=' . $ssl['client_cert'];
            }
            if (!empty($ssl['client_key'])) {
                $dsn .= ';sslkey=' . $ssl['client_key'];
            }
        }

        return $dsn;
    }

    /**
     * Apply PostgreSQL session initialization.
     */
    public function initializeSession(PDO $pdo, bool $useUtcTimezone): void
    {
        $applicationName = sprintf('%s v%s', Version::getAppName(), Version::getVersion());
        $quotedApplicationName = $pdo->quote($applicationName);
        if ($quotedApplicationName !== false) {
            $pdo->exec("SET application_name = {$quotedApplicationName}");
        }

        if ($useUtcTimezone) {
            $pdo->exec("SET TIME ZONE 'UTC'");
        }
    }

    /**
     * Return the PostgreSQL base schema file path.
     */
    public function getBaseSchemaPath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/\\') . '/database/postgresql_schema.sql';
    }

    /**
     * PostgreSQL supports native LISTEN/NOTIFY.
     */
    public function supportsRealtimeNotify(): bool
    {
        return true;
    }
}
