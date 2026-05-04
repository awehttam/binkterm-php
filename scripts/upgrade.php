#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

class DatabaseUpgrader
{
    private $db;
    private $migrationsPath;
    private $useUtcTimezone = true;
    private $migrationTimezone = null;
    
    public function __construct()
    {
        $this->migrationsPath = __DIR__ . '/../database/migrations/';
    }
    
    public function run()
    {
        echo "BinktermPHP Database Upgrade Script\n";
        echo "===================================\n\n";
        
        try {
            // Initialize database connection
            $this->db = Database::getInstance(true)->getPdo();
            
            // Create migrations table if it doesn't exist
            $this->createMigrationsTable();
            
            // Get current database migration
            $currentVersion = $this->getCurrentVersion();
            echo "Current database migration: $currentVersion\n";
            
            // Get available migrations
            $migrations = $this->getAvailableMigrations();
            $pendingMigrations = $this->getPendingMigrations($migrations);
            
            if (empty($pendingMigrations)) {
                echo "✓ Database is up to date. No migrations needed.\n";
                return true;
            }
            
            echo "Found " . count($pendingMigrations) . " pending migration(s):\n";
            foreach ($pendingMigrations as $migration) {
                echo "  - {$migration['version']}: {$migration['description']}\n";
            }
            echo "\n";
            
            // Apply migrations
            $this->applyMigrations($pendingMigrations);
            
            echo "\n✓ Database upgrade completed successfully!\n";
            echo "New database migration: " . $this->getCurrentVersion() . "\n";
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Upgrade failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private function createMigrationsTable()
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS database_migrations (
                id SERIAL PRIMARY KEY,
                version VARCHAR(32) NOT NULL UNIQUE,
                description TEXT,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                checksum VARCHAR(64)
            )
        ");

        $this->db->exec("ALTER TABLE database_migrations ALTER COLUMN version TYPE VARCHAR(32)");
        
        // Insert initial version if no migrations exist
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM database_migrations");
        $count = $stmt->fetch()['count'];
        
        if ($count == 0) {
            $this->db->prepare("
                INSERT INTO database_migrations (version, description) 
                VALUES ('1.0.0', 'Initial database schema')
            ")->execute();
        }
    }
    
    private function getCurrentVersion()
    {
        // Order by ID (insertion order). Timestamped migrations are not semantic
        // versions, so the last applied migration is the best status indicator.
        $stmt = $this->db->query("
            SELECT version FROM database_migrations
            ORDER BY id DESC
            LIMIT 1
        ");
        $result = $stmt->fetch();
        return $result ? $result['version'] : '0.0.0';
    }
    
    private function getAvailableMigrations()
    {
        $migrations = [];
        $versions = [];
        
        // Create migrations directory if it doesn't exist
        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0755, true);
        }
        
        // Scan for migration files
        $files = array_merge(
            glob($this->migrationsPath . '*.sql') ?: [],
            glob($this->migrationsPath . '*.php') ?: []
        );
        
        foreach ($files as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            $filename = basename($file, '.' . $extension);
            
            // Expected formats:
            // - legacy: v1.1.0_description_here.sql (supports 3+ part versions)
            // - current: vYYYYMMDDHHMMSS_description_here.sql
            if (preg_match('/^v((?:\d+(?:\.\d+){2,})|(?:\d{12}|\d{14}))_(.+)$/', $filename, $matches)) {
                $version = $matches[1];
                $description = str_replace('_', ' ', $matches[2]);
                if (isset($versions[$version])) {
                    throw new Exception("Duplicate migration version detected: {$version}");
                }
                $versions[$version] = true;

                $migrations[] = [
                    'version' => $version,
                    'description' => ucfirst($description),
                    'file' => $file,
                    'checksum' => md5_file($file),
                    'type' => $extension
                ];
            }
        }
        
        // Sort legacy semantic migrations before timestamped migrations, then
        // sort timestamped migrations chronologically.
        usort($migrations, function($a, $b) {
            return $this->compareMigrationVersions($a['version'], $b['version']);
        });
        
        return $migrations;
    }

    private function getAppliedVersions(): array
    {
        $stmt = $this->db->query("SELECT version FROM database_migrations");
        $versions = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return array_fill_keys($versions, true);
    }

    private function getPendingMigrations(array $migrations): array
    {
        $appliedVersions = $this->getAppliedVersions();

        return array_values(array_filter($migrations, function($migration) use ($appliedVersions) {
            return !isset($appliedVersions[$migration['version']]);
        }));
    }

    private function isTimestampMigrationVersion(string $version): bool
    {
        return preg_match('/^\d{12}(\d{2})?$/', $version) === 1;
    }

    private function compareMigrationVersions(string $a, string $b): int
    {
        $aIsTimestamp = $this->isTimestampMigrationVersion($a);
        $bIsTimestamp = $this->isTimestampMigrationVersion($b);

        if ($aIsTimestamp && $bIsTimestamp) {
            return strcmp($a, $b);
        }

        if ($aIsTimestamp !== $bIsTimestamp) {
            return $aIsTimestamp ? 1 : -1;
        }

        return version_compare($a, $b);
    }
    
    private function applyMigrations($migrations)
    {
        foreach ($migrations as $migration) {
            echo "Applying migration {$migration['version']}: {$migration['description']}...\n";
            
            try {
                $this->ensureTimezoneForMigration($migration['version']);

                // Begin transaction
                $this->db->beginTransaction();
                
                // Read and execute migration file
                if (($migration['type'] ?? 'sql') === 'php') {
                    $db = $this->db;
                    $migrationFunction = include $migration['file'];

                    // If the migration returns a callable, execute it with $db
                    if (is_callable($migrationFunction)) {
                        $result = $migrationFunction($db);
                    } else {
                        $result = $migrationFunction;
                    }

                    if ($result === false) {
                        throw new Exception("PHP migration returned false");
                    }
                } else {
                    $sql = file_get_contents($migration['file']);
                    
                    // Split into individual statements
                    // Remove comments (both full line and inline)
                    $cleanSql = preg_replace('/--.*$/m', '', $sql); // Remove inline comments
                    $cleanSql = preg_replace('/^\s*$/m', '', $cleanSql); // Remove empty lines
                    $statements = array_filter(
                        array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $cleanSql)),
                        function($stmt) { return !empty($stmt); }
                    );
                    
                    foreach ($statements as $statement) {
                        if (!empty(trim($statement))) {
                            $this->db->exec($statement);
                        }
                    }
                }
                
                // Record migration as completed
                $stmt = $this->db->prepare("
                    INSERT INTO database_migrations (version, description, checksum) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([
                    $migration['version'],
                    $migration['description'],
                    $migration['checksum']
                ]);
                
                // Commit transaction
                $this->db->commit();
                
                echo "   ✓ Migration {$migration['version']} applied successfully\n";
                
            } catch (Exception $e) {
                // Rollback transaction
                $this->db->rollBack();
                throw new Exception("Migration {$migration['version']} failed: " . $e->getMessage());
            }
        }
    }

    private function ensureTimezoneForMigration(string $version): void
    {
        $shouldUseUtc = $version !== '1.8.0' && $version !== '1.9.3.9';
        $desiredMigrationTimezone = null;

        if ($version === '1.9.3.9') {
            $desiredMigrationTimezone = date_default_timezone_get();
        }

        if ($this->useUtcTimezone === $shouldUseUtc && $this->migrationTimezone === $desiredMigrationTimezone) {
            return;
        }

        $this->useUtcTimezone = $shouldUseUtc;
        $this->db = Database::reconnect($shouldUseUtc)->getPdo();

        $this->migrationTimezone = $desiredMigrationTimezone;
        if ($this->migrationTimezone !== null) {
            $this->db->exec("SET TIME ZONE '" . str_replace("'", "''", $this->migrationTimezone) . "'");
        }
    }
    
    public function rollback($targetVersion = null)
    {
        echo "BinktermPHP Database Rollback\n";
        echo "============================\n\n";
        
        if ($targetVersion === null) {
            echo "⚠ Warning: This will rollback the last migration only.\n";
            echo "For more control, specify a target version.\n\n";
        }
        
        try {
            $this->db = Database::getInstance(true)->getPdo();
            
            $currentVersion = $this->getCurrentVersion();
            echo "Current database version: $currentVersion\n";
            
            if ($targetVersion === null) {
                // Rollback last migration
                $stmt = $this->db->query("
                    SELECT version
                    FROM database_migrations
                    WHERE id < (
                        SELECT id
                        FROM database_migrations
                        ORDER BY id DESC
                        LIMIT 1
                    )
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $result = $stmt->fetch();
                $targetVersion = $result ? $result['version'] : '1.0.0';
            }
            
            echo "Target version: $targetVersion\n\n";
            
            // For now, rollback is manual - we don't have down migrations
            echo "⚠ Automatic rollback is not implemented.\n";
            echo "To rollback:\n";
            echo "1. Backup your database\n";
            echo "2. Manually reverse the changes from migrations newer than $targetVersion\n";
            echo "3. Delete affected migration records from database_migrations after verifying the rollback point\n\n";
            
            return false;
            
        } catch (Exception $e) {
            echo "✗ Rollback failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function status()
    {
        echo "BinktermPHP Database Status\n";
        echo "===========================\n\n";
        
        try {
            $this->db = Database::getInstance(true)->getPdo();
            $this->createMigrationsTable();
            
            echo "Current migration: " . $this->getCurrentVersion() . "\n\n";
            
            echo "Applied migrations:\n";
            echo "-------------------\n";
            $stmt = $this->db->query("
                SELECT version, description, executed_at 
                FROM database_migrations 
                ORDER BY id
            ");
            
            while ($row = $stmt->fetch()) {
                echo "  {$row['version']}: {$row['description']} ({$row['executed_at']})\n";
            }
            
            echo "\nPending migrations:\n";
            echo "-------------------\n";
            $migrations = $this->getAvailableMigrations();
            $pending = $this->getPendingMigrations($migrations);
            
            if (empty($pending)) {
                echo "  None - database is up to date\n";
            } else {
                foreach ($pending as $migration) {
                    echo "  {$migration['version']}: {$migration['description']}\n";
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Status check failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

function showUsage()
{
    echo "Usage: php upgrade.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  upgrade              Apply pending database migrations (default)\n";
    echo "  status               Show current database migration and migration status\n";
    echo "  rollback [version]   Rollback to specified version (manual process)\n";
    echo "  --help               Show this help message\n\n";
    echo "Examples:\n";
    echo "  php upgrade.php                              # Apply all pending migrations\n";
    echo "  php upgrade.php status                       # Show migration status\n";
    echo "  php upgrade.php rollback 1.0.0               # Rollback to version 1.0.0\n\n";
}

// Parse command line arguments
$command = 'upgrade';
$args = [];

if ($argc > 1) {
    $command = $argv[1];
    $args = array_slice($argv, 2);
}

// Handle help
if ($command === '--help' || $command === '-h') {
    showUsage();
    exit(0);
}

// Create upgrader instance
$upgrader = new DatabaseUpgrader();
$success = false;

// Execute command
switch ($command) {
    case 'upgrade':
        $success = $upgrader->run();
        break;
        
    case 'status':
        $success = $upgrader->status();
        break;
        
    case 'rollback':
        $targetVersion = isset($args[0]) ? $args[0] : null;
        $success = $upgrader->rollback($targetVersion);
        break;
        
    default:
        echo "Unknown command: $command\n";
        showUsage();
        exit(1);
}

exit($success ? 0 : 1);
