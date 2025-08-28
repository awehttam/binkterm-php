<?php
/**
 * SQLite to PostgreSQL Data Migration Tool
 * 
 * This script migrates all data from the existing SQLite database 
 * to the new PostgreSQL database.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Config;

class SqliteToPostgresMigration 
{
    private $sqlitePdo;
    private $postgresPdo;
    
    const SQLITE_DB_PATH = __DIR__ . '/../data/binktest.db';
    
    public function __construct()
    {
        $this->connectToSqlite();
        $this->connectToPostgres();
    }
    
    private function connectToSqlite()
    {
        try {
            if (!file_exists(self::SQLITE_DB_PATH)) {
                throw new Exception("SQLite database not found at: " . self::SQLITE_DB_PATH);
            }
            
            $this->sqlitePdo = new \PDO('sqlite:' . self::SQLITE_DB_PATH);
            $this->sqlitePdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            echo "Connected to SQLite database\n";
        } catch (\PDOException $e) {
            die('SQLite connection failed: ' . $e->getMessage() . "\n");
        }
    }
    
    private function connectToPostgres()
    {
        try {
            $config = Config::getDatabaseConfig();
            
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'],
                $config['port'],
                $config['database']
            );
            
            $this->postgresPdo = new \PDO(
                $dsn, 
                $config['username'], 
                $config['password'],
                $config['options'] ?? [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
                ]
            );
            echo "Connected to PostgreSQL database\n";
        } catch (\PDOException $e) {
            die('PostgreSQL connection failed: ' . $e->getMessage() . "\n");
        }
    }
    
    public function migrate()
    {
        echo "Starting migration from SQLite to PostgreSQL...\n\n";
        
        // Order matters due to foreign key constraints
        $tables = [
            'users',
            'echoareas', 
            'nodes',
            'sessions',
            'user_sessions',
            'user_settings',
            'netmail',
            'echomail',
            'packets',
            'message_links',
            'message_read_status',
            'pending_users'
        ];
        
        foreach ($tables as $table) {
            $this->migrateTable($table);
        }
        
        $this->updateSequences();
        
        echo "\nMigration completed successfully!\n";
    }
    
    private function migrateTable($tableName)
    {
        echo "Migrating table: $tableName\n";
        
        try {
            // Check if table exists in SQLite
            $checkStmt = $this->sqlitePdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $checkStmt->execute([$tableName]);
            if (!$checkStmt->fetch()) {
                echo "  Table $tableName does not exist in SQLite, skipping\n";
                return;
            }
            
            // Get all data from SQLite
            $selectStmt = $this->sqlitePdo->prepare("SELECT * FROM $tableName");
            $selectStmt->execute();
            $rows = $selectStmt->fetchAll(\PDO::FETCH_ASSOC);
            
            if (empty($rows)) {
                echo "  No data found in $tableName\n";
                return;
            }
            
            echo "  Found " . count($rows) . " rows\n";
            
            // Clear existing data in PostgreSQL (for clean migration)
            $this->postgresPdo->exec("TRUNCATE TABLE $tableName RESTART IDENTITY CASCADE");
            
            // Prepare insert statement for PostgreSQL
            $columns = array_keys($rows[0]);
            $placeholders = ':' . implode(', :', $columns);
            $insertSql = "INSERT INTO $tableName (" . implode(', ', $columns) . ") VALUES ($placeholders)";
            $insertStmt = $this->postgresPdo->prepare($insertSql);
            
            // Insert all rows
            $successCount = 0;
            foreach ($rows as $row) {
                try {
                    // Convert data types for PostgreSQL compatibility
                    foreach ($row as $key => $value) {
                        // Handle text encoding conversion
                        if (is_string($value) && !empty($value)) {
                            $row[$key] = $this->convertToUtf8($value);
                        }
                        
                        // Convert SQLite boolean values (1/0) to PostgreSQL boolean (true/false)
                        if (is_numeric($value) && ($value === '1' || $value === '0' || $value === 1 || $value === 0)) {
                            if ($this->isBooleanColumn($tableName, $key)) {
                                $row[$key] = $value ? 'true' : 'false';
                            }
                        }
                        
                        // Handle IP address columns (INET type in PostgreSQL)
                        if ($this->isIpAddressColumn($tableName, $key)) {
                            if (empty($value) || $value === '' || $value === '0.0.0.0') {
                                $row[$key] = null; // PostgreSQL INET accepts NULL for empty/invalid IPs
                            } elseif (!filter_var($value, FILTER_VALIDATE_IP)) {
                                $row[$key] = null; // Invalid IP, set to NULL
                            }
                        }
                    }
                    
                    $insertStmt->execute($row);
                    $successCount++;
                } catch (\PDOException $e) {
                    echo "  Error inserting row: " . $e->getMessage() . "\n";
                }
            }
            
            echo "  Successfully migrated $successCount rows\n";
            
        } catch (\PDOException $e) {
            echo "  Error migrating $tableName: " . $e->getMessage() . "\n";
        }
    }
    
    private function isBooleanColumn($tableName, $columnName)
    {
        // Define boolean columns for each table
        $booleanColumns = [
            'users' => ['is_active', 'is_admin'],
            'nodes' => ['is_active'],
            'echoareas' => ['is_active'],
            'netmail' => ['is_read', 'is_sent'],
            'user_settings' => ['show_origin', 'show_tearline', 'auto_refresh']
        ];
        
        return isset($booleanColumns[$tableName]) && 
               in_array($columnName, $booleanColumns[$tableName]);
    }
    
    private function isIpAddressColumn($tableName, $columnName)
    {
        // Define IP address columns for each table (columns that use INET type in PostgreSQL)
        $ipColumns = [
            'sessions' => ['ip_address'],
            'user_sessions' => ['ip_address'],
            'pending_users' => ['ip_address']
        ];
        
        return isset($ipColumns[$tableName]) && 
               in_array($columnName, $ipColumns[$tableName]);
    }
    
    private function convertToUtf8($text)
    {
        // Skip conversion if already valid UTF-8
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        
        // Use iconv for better encoding support (supports CP437, CP850, etc.)
        $encodings = ['CP437', 'CP850', 'ISO-8859-1', 'Windows-1252'];
        
        foreach ($encodings as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $text);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }
        
        // Fallback: try to clean up the original text
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($converted !== false) {
            return $converted;
        }
        
        // Last resort: remove non-printable characters and invalid bytes
        $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/', '?', $text);
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    
    private function updateSequences()
    {
        echo "Updating PostgreSQL sequences...\n";
        
        $tables = [
            'users' => 'id',
            'echoareas' => 'id', 
            'nodes' => 'id',
            'netmail' => 'id',
            'echomail' => 'id',
            'packets' => 'id',
            'message_links' => 'id',
            'message_read_status' => 'id',
            'pending_users' => 'id'
        ];
        
        foreach ($tables as $table => $idColumn) {
            try {
                // Get the maximum ID value
                $stmt = $this->postgresPdo->prepare("SELECT COALESCE(MAX($idColumn), 0) + 1 AS next_id FROM $table");
                $stmt->execute();
                $result = $stmt->fetch();
                $nextId = $result['next_id'];
                
                // Update the sequence
                $sequenceName = $table . '_' . $idColumn . '_seq';
                $this->postgresPdo->exec("SELECT setval('$sequenceName', $nextId, false)");
                
                echo "  Updated sequence $sequenceName to start at $nextId\n";
            } catch (\PDOException $e) {
                echo "  Warning: Could not update sequence for $table: " . $e->getMessage() . "\n";
            }
        }
    }
    
    public function validateMigration()
    {
        echo "\nValidating migration...\n";
        
        $tables = ['users', 'echoareas', 'nodes', 'netmail', 'echomail', 'packets'];
        
        foreach ($tables as $table) {
            try {
                // Count rows in both databases
                $sqliteStmt = $this->sqlitePdo->prepare("SELECT COUNT(*) as count FROM $table");
                $sqliteStmt->execute();
                $sqliteCount = $sqliteStmt->fetch()['count'];
                
                $postgresStmt = $this->postgresPdo->prepare("SELECT COUNT(*) as count FROM $table");
                $postgresStmt->execute();
                $postgresCount = $postgresStmt->fetch()['count'];
                
                echo "  $table: SQLite=$sqliteCount, PostgreSQL=$postgresCount";
                if ($sqliteCount == $postgresCount) {
                    echo " ✓\n";
                } else {
                    echo " ✗\n";
                }
                
            } catch (\PDOException $e) {
                echo "  Error validating $table: " . $e->getMessage() . "\n";
            }
        }
    }
}

// Command line usage
if (php_sapi_name() === 'cli') {
    echo "SQLite to PostgreSQL Migration Tool\n";
    echo "===================================\n\n";
    
    if ($argc > 1) {
        if ($argv[1] === '--validate-only') {
            $migrator = new SqliteToPostgresMigration();
            $migrator->validateMigration();
            exit(0);
        }
        if ($argv[1] === '--help') {
            echo "Usage: php sqlite_to_postgres_migration.php [options]\n";
            echo "Options:\n";
            echo "  --validate-only  Only validate existing migration\n";
            echo "  --help          Show this help message\n";
            exit(0);
        }
    }
    
    echo "This will migrate all data from SQLite to PostgreSQL.\n";
    echo "Make sure PostgreSQL is running and the database exists.\n";
    echo "Continue? (y/N): ";
    
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (strtolower(trim($line)) !== 'y') {
        echo "Migration cancelled.\n";
        exit(0);
    }
    
    try {
        $migrator = new SqliteToPostgresMigration();
        $migrator->migrate();
        $migrator->validateMigration();
    } catch (Exception $e) {
        echo "Migration failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}