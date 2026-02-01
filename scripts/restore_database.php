#!/usr/bin/env php
<?php
/**
 * Database Restore Script for BinktermPHP
 *
 * Restores PostgreSQL database backups created by backup_database.php
 * Supports SQL, custom, and tar formats with optional compression
 *
 * Usage: php scripts/restore_database.php <backup_file> [options]
 *
 * Options:
 *   --drop           Drop the database before restoring (DESTRUCTIVE!)
 *   --create         Create the database if it doesn't exist
 *   --clean          Clean (drop) database objects before recreating
 *   --force          Skip confirmation prompts
 *   --quiet          Suppress output except errors
 *   --help           Show this help message
 *
 * IMPORTANT: This script will overwrite existing data. Always backup first!
 */

require_once __DIR__ . '/../vendor/autoload.php';

class DatabaseRestore
{
    private $dbHost;
    private $dbPort;
    private $dbName;
    private $dbUser;
    private $dbPass;
    private $quiet = false;

    public function __construct()
    {
        // Load environment variables
        $this->loadEnvironment();
    }

    private function loadEnvironment()
    {
        $envFile = __DIR__ . '/../.env';
        if (!file_exists($envFile)) {
            $this->error('Error: .env file not found');
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                $_ENV[$name] = $value;
            }
        }

        // Get database configuration
        $this->dbHost = $_ENV['DB_HOST'] ?? 'localhost';
        $this->dbPort = $_ENV['DB_PORT'] ?? '5432';
        $this->dbName = $_ENV['DB_NAME'] ?? '';
        $this->dbUser = $_ENV['DB_USER'] ?? '';
        $this->dbPass = $_ENV['DB_PASS'] ?? '';

        if (empty($this->dbName) || empty($this->dbUser)) {
            $this->error('Error: Database name and user must be configured in .env');
        }
    }

    public function restore($backupFile, $options = [])
    {
        $drop = $options['drop'] ?? false;
        $create = $options['create'] ?? false;
        $clean = $options['clean'] ?? false;
        $force = $options['force'] ?? false;
        $this->quiet = $options['quiet'] ?? false;

        // Validate backup file exists
        if (!file_exists($backupFile)) {
            $this->error("Error: Backup file not found: $backupFile");
        }

        // Detect backup format
        $format = $this->detectFormat($backupFile);
        $this->log("Detected backup format: $format");

        // Confirm destructive operations
        if (!$force && ($drop || $clean)) {
            $this->log("\nWARNING: This operation will modify or destroy existing data!");
            $this->log("Database: {$this->dbName}@{$this->dbHost}:{$this->dbPort}");
            if ($drop) {
                $this->log("Action: DROP DATABASE (all data will be lost!)");
            }
            if ($clean) {
                $this->log("Action: CLEAN (drop all objects before restore)");
            }

            echo "\nType 'yes' to continue: ";
            $confirmation = trim(fgets(STDIN));

            if (strtolower($confirmation) !== 'yes') {
                $this->log("Restore cancelled.");
                exit(0);
            }
        }

        $this->log("Starting database restore...");
        $this->log("Database: {$this->dbName}@{$this->dbHost}:{$this->dbPort}");
        $this->log("Backup file: " . basename($backupFile));

        $startTime = microtime(true);

        // Drop database if requested
        if ($drop) {
            $this->dropDatabase();
            $create = true; // Must create if we dropped
        }

        // Create database if requested
        if ($create) {
            $this->createDatabase();
        }

        // Restore based on format
        switch ($format) {
            case 'sql':
            case 'sql.gz':
                $this->restoreSql($backupFile, $format === 'sql.gz');
                break;
            case 'custom':
            case 'tar':
                $this->restoreCustom($backupFile, $clean);
                break;
            default:
                $this->error("Unknown backup format: $format");
        }

        $duration = microtime(true) - $startTime;
        $this->log("Restore completed successfully in " . number_format($duration, 2) . " seconds");

        return true;
    }

    private function detectFormat($backupFile)
    {
        $ext = pathinfo($backupFile, PATHINFO_EXTENSION);
        $basename = basename($backupFile);

        if (preg_match('/\.sql\.gz$/', $basename)) {
            return 'sql.gz';
        } elseif ($ext === 'sql') {
            return 'sql';
        } elseif ($ext === 'dump') {
            return 'custom';
        } elseif ($ext === 'tar') {
            return 'tar';
        }

        // Try to detect by file content
        $header = file_get_contents($backupFile, false, null, 0, 5);
        if ($header === "\x1f\x8b") {
            return 'sql.gz'; // Gzip magic number
        }

        $this->error("Could not detect backup format. Supported: .sql, .sql.gz, .dump, .tar");
    }

    private function dropDatabase()
    {
        $this->log("Dropping database: {$this->dbName}");

        $psql = $this->findPsql();

        // Connect to 'postgres' database to drop the target database
        $cmd = escapeshellarg($psql);
        $cmd .= ' --host=' . escapeshellarg($this->dbHost);
        $cmd .= ' --port=' . escapeshellarg($this->dbPort);
        $cmd .= ' --username=' . escapeshellarg($this->dbUser);
        $cmd .= ' --dbname=postgres';
        $cmd .= ' --command=' . escapeshellarg("DROP DATABASE IF EXISTS {$this->dbName}");

        putenv('PGPASSWORD=' . $this->dbPass);

        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);

        putenv('PGPASSWORD');

        if ($returnCode !== 0) {
            $this->error("Failed to drop database:\n" . implode("\n", $output));
        }

        $this->log("Database dropped successfully");
    }

    private function createDatabase()
    {
        $this->log("Creating database: {$this->dbName}");

        $psql = $this->findPsql();

        // Connect to 'postgres' database to create the target database
        $cmd = escapeshellarg($psql);
        $cmd .= ' --host=' . escapeshellarg($this->dbHost);
        $cmd .= ' --port=' . escapeshellarg($this->dbPort);
        $cmd .= ' --username=' . escapeshellarg($this->dbUser);
        $cmd .= ' --dbname=postgres';
        $cmd .= ' --command=' . escapeshellarg("CREATE DATABASE {$this->dbName}");

        putenv('PGPASSWORD=' . $this->dbPass);

        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);

        putenv('PGPASSWORD');

        // Ignore error if database already exists
        if ($returnCode !== 0 && !preg_match('/already exists/', implode("\n", $output))) {
            $this->error("Failed to create database:\n" . implode("\n", $output));
        }

        $this->log("Database ready");
    }

    private function restoreSql($backupFile, $isGzipped = false)
    {
        $this->log("Restoring SQL backup...");

        $psql = $this->findPsql();

        // Build psql command
        $cmd = escapeshellarg($psql);
        $cmd .= ' --host=' . escapeshellarg($this->dbHost);
        $cmd .= ' --port=' . escapeshellarg($this->dbPort);
        $cmd .= ' --username=' . escapeshellarg($this->dbUser);
        $cmd .= ' --dbname=' . escapeshellarg($this->dbName);
        $cmd .= ' --quiet';

        putenv('PGPASSWORD=' . $this->dbPass);

        if ($isGzipped) {
            // Decompress and pipe to psql
            $this->log("Decompressing backup file...");

            $gz = gzopen($backupFile, 'rb');
            if (!$gz) {
                putenv('PGPASSWORD');
                $this->error("Failed to open gzipped backup file");
            }

            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            $process = proc_open($cmd, $descriptors, $pipes);

            if (!is_resource($process)) {
                putenv('PGPASSWORD');
                $this->error("Failed to start psql process");
            }

            // Write decompressed data to psql stdin
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 8192);
                if ($chunk !== false && $chunk !== '') {
                    fwrite($pipes[0], $chunk);
                }
            }

            gzclose($gz);
            fclose($pipes[0]);

            // Capture output
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $returnCode = proc_close($process);

            if ($returnCode !== 0) {
                putenv('PGPASSWORD');
                $this->error("Restore failed:\n" . $stderr);
            }
        } else {
            // Direct restore from SQL file
            $cmd .= ' --file=' . escapeshellarg($backupFile);

            $output = [];
            $returnCode = 0;
            exec($cmd . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                putenv('PGPASSWORD');
                $this->error("Restore failed:\n" . implode("\n", $output));
            }
        }

        putenv('PGPASSWORD');
        $this->log("SQL backup restored successfully");
    }

    private function restoreCustom($backupFile, $clean = false)
    {
        $this->log("Restoring custom/tar backup...");

        $pgRestore = $this->findPgRestore();

        // Build pg_restore command
        $cmd = escapeshellarg($pgRestore);
        $cmd .= ' --host=' . escapeshellarg($this->dbHost);
        $cmd .= ' --port=' . escapeshellarg($this->dbPort);
        $cmd .= ' --username=' . escapeshellarg($this->dbUser);
        $cmd .= ' --dbname=' . escapeshellarg($this->dbName);
        $cmd .= ' --verbose --no-password';

        if ($clean) {
            $cmd .= ' --clean';
        }

        $cmd .= ' ' . escapeshellarg($backupFile);

        putenv('PGPASSWORD=' . $this->dbPass);

        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);

        putenv('PGPASSWORD');

        if ($returnCode !== 0) {
            $this->error("Restore failed:\n" . implode("\n", $output));
        }

        $this->log("Custom/tar backup restored successfully");
    }

    /**
     * Find psql executable
     */
    private function findPsql()
    {
        return $this->findPostgresTool('psql');
    }

    /**
     * Find pg_restore executable
     */
    private function findPgRestore()
    {
        return $this->findPostgresTool('pg_restore');
    }

    /**
     * Find PostgreSQL tool in system PATH or common installation directories
     */
    private function findPostgresTool($toolName)
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $exeName = $toolName . ($isWindows ? '.exe' : '');

        // Try finding in PATH first
        $which = $isWindows ? 'where' : 'which';
        exec("$which $toolName 2>&1", $output, $returnCode);
        if ($returnCode === 0) {
            return $toolName;
        }

        // Try common Windows PostgreSQL installation paths
        if ($isWindows) {
            $commonPaths = [
                'C:\Program Files\PostgreSQL\16\bin\\' . $exeName,
                'C:\Program Files\PostgreSQL\15\bin\\' . $exeName,
                'C:\Program Files\PostgreSQL\14\bin\\' . $exeName,
                'C:\Program Files\PostgreSQL\13\bin\\' . $exeName,
                'C:\Program Files (x86)\PostgreSQL\16\bin\\' . $exeName,
                'C:\Program Files (x86)\PostgreSQL\15\bin\\' . $exeName,
            ];

            foreach ($commonPaths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        return $toolName;
    }

    private function log($message)
    {
        if (!$this->quiet) {
            echo "[" . date('Y-m-d H:i:s') . "] $message\n";
        }
    }

    private function error($message)
    {
        fwrite(STDERR, "$message\n");
        exit(1);
    }
}

// Parse command line options
function parseRestoreOptions($argv)
{
    $options = [];
    $backupFile = null;

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if ($arg === '--help') {
            showRestoreHelp();
            exit(0);
        } elseif ($arg === '--drop') {
            $options['drop'] = true;
        } elseif ($arg === '--create') {
            $options['create'] = true;
        } elseif ($arg === '--clean') {
            $options['clean'] = true;
        } elseif ($arg === '--force') {
            $options['force'] = true;
        } elseif ($arg === '--quiet') {
            $options['quiet'] = true;
        } elseif (strpos($arg, '--') !== 0) {
            // Assume it's the backup file
            $backupFile = $arg;
        } else {
            fwrite(STDERR, "Unknown option: $arg\nUse --help for usage information.\n");
            exit(1);
        }
    }

    return [$backupFile, $options];
}

function showRestoreHelp()
{
    echo "Database Restore Script for BinktermPHP\n";
    echo "=======================================\n\n";
    echo "Usage: php scripts/restore_database.php <backup_file> [options]\n\n";
    echo "Options:\n";
    echo "  --drop           Drop the database before restoring (DESTRUCTIVE!)\n";
    echo "  --create         Create the database if it doesn't exist\n";
    echo "  --clean          Clean (drop) database objects before recreating\n";
    echo "  --force          Skip confirmation prompts\n";
    echo "  --quiet          Suppress output except errors\n";
    echo "  --help           Show this help message\n\n";
    echo "Examples:\n";
    echo "  # Restore to existing database\n";
    echo "  php scripts/restore_database.php backups/binktest_backup_2024-01-15_10-30-00.sql\n\n";
    echo "  # Drop and recreate database (DESTRUCTIVE!)\n";
    echo "  php scripts/restore_database.php backups/backup.sql --drop\n\n";
    echo "  # Restore custom format with clean\n";
    echo "  php scripts/restore_database.php backups/backup.dump --clean\n\n";
    echo "  # Create new database from backup\n";
    echo "  php scripts/restore_database.php backups/backup.sql --create\n\n";
    echo "WARNING: Restore operations can destroy existing data!\n";
    echo "         Always backup your database before restoring.\n";
    echo "         Use --force to skip confirmation prompts (use with caution).\n";
}

// Main execution
if (php_sapi_name() === 'cli') {
    try {
        list($backupFile, $options) = parseRestoreOptions($argv);

        if (!$backupFile) {
            fwrite(STDERR, "Error: No backup file specified\n\n");
            showRestoreHelp();
            exit(1);
        }

        $restore = new DatabaseRestore();
        $restore->restore($backupFile, $options);

        if (!($options['quiet'] ?? false)) {
            echo "\nRestore completed successfully!\n";
        }

        exit(0);
    } catch (Exception $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}
