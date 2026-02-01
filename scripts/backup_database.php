#!/usr/bin/env php
<?php
/**
 * Database Backup Script for BinktermPHP
 * 
 * Creates PostgreSQL database backups using pg_dump with connection settings from .env
 * Saves backups to backups/ directory with timestamp in filename
 * 
 * Usage: php scripts/backup_database.php [options]
 * 
 * Options:
 *   --format=TYPE    Backup format: sql, custom, tar (default: sql)
 *   --compress       Enable compression (gzip for sql, built-in for custom/tar)
 *   --cleanup=DAYS   Delete backups older than X days (default: 30)
 *   --quiet          Suppress output except errors
 *   --help           Show this help message
 */

require_once __DIR__ . '/../vendor/autoload.php';

class DatabaseBackup
{
    private $dbHost;
    private $dbPort;
    private $dbName;
    private $dbUser;
    private $dbPass;
    private $backupPath;
    private $quiet = false;

    public function __construct()
    {
        // Load environment variables
        $this->loadEnvironment();
        
        // Set paths
        $this->backupPath = __DIR__ . '/../backups';
        
        // Ensure backup directory exists
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
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

    public function backup($options = [])
    {
        $format = $options['format'] ?? 'sql';
        $compress = $options['compress'] ?? false;
        $cleanup = $options['cleanup'] ?? 30;
        $this->quiet = $options['quiet'] ?? false;

        // Validate format
        $validFormats = ['sql', 'custom', 'tar'];
        if (!in_array($format, $validFormats)) {
            $this->error("Error: Invalid format '$format'. Valid formats: " . implode(', ', $validFormats));
        }

        $timestamp = date('Y-m-d_H-i-s');
        $baseFilename = "binktest_backup_{$timestamp}";
        
        // Determine file extension and pg_dump options
        switch ($format) {
            case 'sql':
                $filename = $compress ? "{$baseFilename}.sql.gz" : "{$baseFilename}.sql";
                $pgDumpFormat = '--format=plain';
                break;
            case 'custom':
                $filename = "{$baseFilename}.dump";
                $pgDumpFormat = '--format=custom';
                if ($compress) {
                    $pgDumpFormat .= ' --compress=9';
                }
                break;
            case 'tar':
                $filename = "{$baseFilename}.tar";
                $pgDumpFormat = '--format=tar';
                if ($compress) {
                    $pgDumpFormat .= ' --compress=9';
                }
                break;
        }

        $backupFile = $this->backupPath . '/' . $filename;

        $this->log("Creating database backup...");
        $this->log("Database: {$this->dbName}@{$this->dbHost}:{$this->dbPort}");
        $this->log("Format: $format" . ($compress ? ' (compressed)' : ''));
        $this->log("Output: $filename");

        // Build pg_dump command
        $cmd = $this->buildPgDumpCommand($pgDumpFormat, $backupFile, $format === 'sql' && $compress);

        $this->log("Executing backup...");
        $startTime = microtime(true);

        // Set PGPASSWORD environment variable for authentication
        putenv('PGPASSWORD=' . $this->dbPass);

        // Execute backup
        if ($format === 'sql' && $compress) {
            // For compressed SQL output, capture stdout and compress with PHP
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            $process = proc_open($cmd, $descriptors, $pipes);

            if (!is_resource($process)) {
                putenv('PGPASSWORD');
                $this->error("Failed to start pg_dump process");
            }

            // Close stdin
            fclose($pipes[0]);

            // Read stdout and compress
            $compressedFile = gzopen($backupFile, 'wb9');
            if (!$compressedFile) {
                putenv('PGPASSWORD');
                $this->error("Failed to open output file for writing: $backupFile");
            }

            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    gzwrite($compressedFile, $chunk);
                }
            }

            gzclose($compressedFile);
            fclose($pipes[1]);

            // Capture stderr
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $returnCode = proc_close($process);

            if ($returnCode !== 0) {
                putenv('PGPASSWORD');
                $this->error("Backup failed with return code $returnCode:\n" . $stderr);
            }
        } else {
            // Regular output (no compression or custom/tar format)
            $output = [];
            $returnCode = 0;
            exec($cmd . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                putenv('PGPASSWORD');
                $this->error("Backup failed with return code $returnCode:\n" . implode("\n", $output));
            }
        }

        // Clear the password from environment
        putenv('PGPASSWORD');

        $duration = microtime(true) - $startTime;

        // Check if backup file was created and has content
        if (!file_exists($backupFile) || filesize($backupFile) === 0) {
            $this->error("Backup file was not created or is empty");
        }

        $fileSize = $this->formatBytes(filesize($backupFile));
        $this->log("Backup completed successfully in " . number_format($duration, 2) . " seconds");
        $this->log("Backup size: $fileSize");

        // Cleanup old backups if requested
        if ($cleanup > 0) {
            $this->cleanupOldBackups($cleanup);
        }

        return $backupFile;
    }

    private function buildPgDumpCommand($format, $outputFile, $gzipOutput = false)
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        // Find pg_dump executable
        $pgDump = $this->findPgDump();

        // Build base command
        $cmd = escapeshellarg($pgDump);

        // Add connection parameters
        $cmd .= ' --host=' . escapeshellarg($this->dbHost);
        $cmd .= ' --port=' . escapeshellarg($this->dbPort);
        $cmd .= ' --username=' . escapeshellarg($this->dbUser);
        $cmd .= ' --dbname=' . escapeshellarg($this->dbName);

        // Add format option
        $cmd .= ' ' . $format;

        // Add other options
        $cmd .= ' --verbose --no-password';

        // Handle output redirection
        if ($gzipOutput) {
            // For gzip output, we'll use pg_dump to stdout and compress with PHP
            // This avoids platform-specific shell piping issues
            $cmd .= ' --file=-';  // Output to stdout
        } else {
            $cmd .= ' --file=' . escapeshellarg($outputFile);
        }

        // On Windows, we need to set PGPASSWORD differently
        // We'll use putenv() before exec() instead of inline environment variable
        // So we don't prepend PGPASSWORD= here

        return $cmd;
    }

    /**
     * Find pg_dump executable in system PATH or common PostgreSQL installation directories
     */
    private function findPgDump()
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        // Try finding in PATH first
        $pgDump = 'pg_dump';
        $which = $isWindows ? 'where' : 'which';

        exec("$which pg_dump 2>&1", $output, $returnCode);
        if ($returnCode === 0) {
            return 'pg_dump';  // Found in PATH
        }

        // Try common Windows PostgreSQL installation paths
        if ($isWindows) {
            $commonPaths = [
                'C:\Program Files\PostgreSQL\16\bin\pg_dump.exe',
                'C:\Program Files\PostgreSQL\15\bin\pg_dump.exe',
                'C:\Program Files\PostgreSQL\14\bin\pg_dump.exe',
                'C:\Program Files\PostgreSQL\13\bin\pg_dump.exe',
                'C:\Program Files (x86)\PostgreSQL\16\bin\pg_dump.exe',
                'C:\Program Files (x86)\PostgreSQL\15\bin\pg_dump.exe',
            ];

            foreach ($commonPaths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        // Return default and let it fail with a helpful error
        return 'pg_dump';
    }

    private function cleanupOldBackups($days)
    {
        $this->log("Cleaning up backups older than $days days...");
        
        $cutoffTime = time() - ($days * 24 * 60 * 60);
        $cleaned = 0;
        $totalSize = 0;

        $files = glob($this->backupPath . '/binktest_backup_*');
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                $size = filesize($file);
                if (unlink($file)) {
                    $cleaned++;
                    $totalSize += $size;
                    $this->log("Deleted: " . basename($file) . " (" . $this->formatBytes($size) . ")");
                }
            }
        }

        if ($cleaned > 0) {
            $this->log("Cleanup completed: $cleaned files removed, " . $this->formatBytes($totalSize) . " freed");
        } else {
            $this->log("No old backups found to clean up");
        }
    }

    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
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
function parseOptions($argv)
{
    $options = [];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--help') {
            showHelp();
            exit(0);
        } elseif ($arg === '--compress') {
            $options['compress'] = true;
        } elseif ($arg === '--quiet') {
            $options['quiet'] = true;
        } elseif (strpos($arg, '--format=') === 0) {
            $options['format'] = substr($arg, 9);
        } elseif (strpos($arg, '--cleanup=') === 0) {
            $options['cleanup'] = (int) substr($arg, 10);
        } else {
            fwrite(STDERR, "Unknown option: $arg\nUse --help for usage information.\n");
            exit(1);
        }
    }
    
    return $options;
}

function showHelp()
{
    echo "Database Backup Script for BinktermPHP\n";
    echo "=====================================\n\n";
    echo "Usage: php scripts/backup_database.php [options]\n\n";
    echo "Options:\n";
    echo "  --format=TYPE    Backup format: sql, custom, tar (default: sql)\n";
    echo "  --compress       Enable compression (gzip for sql, built-in for custom/tar)\n";
    echo "  --cleanup=DAYS   Delete backups older than X days (default: 30)\n";
    echo "  --quiet          Suppress output except errors\n";
    echo "  --help           Show this help message\n\n";
    echo "Examples:\n";
    echo "  php scripts/backup_database.php\n";
    echo "  php scripts/backup_database.php --format=custom --compress\n";
    echo "  php scripts/backup_database.php --cleanup=7 --quiet\n\n";
    echo "Backup files are saved to: backups/\n";
    echo "Database connection settings are read from .env file\n";
}

// Main execution
if (php_sapi_name() === 'cli') {
    try {
        $options = parseOptions($argv);
        $backup = new DatabaseBackup();
        $backupFile = $backup->backup($options);
        
        if (!($options['quiet'] ?? false)) {
            echo "\nBackup completed successfully!\n";
            echo "Backup file: " . basename($backupFile) . "\n";
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