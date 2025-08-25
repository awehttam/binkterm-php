<?php
/**
 * Script to dump the current database schema
 * Usage: php scripts/dump_schema.php
 */

// Set the database path
$dbPath = __DIR__ . '/../data/binktest.db';

if (!file_exists($dbPath)) {
    echo "Error: Database file not found at: $dbPath\n";
    exit(1);
}

try {
    // Connect to SQLite database
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Database Schema Dump\n";
    echo "====================\n\n";
    
    // Get all table names
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        echo "Table: $table\n";
        echo str_repeat('-', strlen($table) + 7) . "\n";
        
        // Get table schema
        $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'");
        $createSql = $stmt->fetchColumn();
        
        if ($createSql) {
            echo $createSql . ";\n\n";
        }
        
        // Get column information
        $stmt = $pdo->query("PRAGMA table_info($table)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Columns:\n";
        foreach ($columns as $column) {
            $nullable = $column['notnull'] ? 'NOT NULL' : 'NULLABLE';
            $default = $column['dflt_value'] !== null ? "DEFAULT {$column['dflt_value']}" : '';
            $pk = $column['pk'] ? 'PRIMARY KEY' : '';
            
            echo sprintf("  %-20s %-15s %-10s %-15s %s\n", 
                $column['name'], 
                $column['type'], 
                $nullable,
                $default,
                $pk
            );
        }
        
        // Get indexes for this table
        $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='$table' AND sql IS NOT NULL");
        $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($indexes)) {
            echo "\nIndexes:\n";
            foreach ($indexes as $indexSql) {
                echo "  $indexSql;\n";
            }
        }
        
        echo "\n" . str_repeat('=', 60) . "\n\n";
    }
    
    // Get views if any
    $stmt = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='view' ORDER BY name");
    $views = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($views)) {
        echo "Views:\n";
        echo "======\n\n";
        
        foreach ($views as $view) {
            echo "View: {$view['name']}\n";
            echo str_repeat('-', strlen($view['name']) + 6) . "\n";
            echo $view['sql'] . ";\n\n";
        }
    }
    
    // Get triggers if any
    $stmt = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='trigger' ORDER BY name");
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($triggers)) {
        echo "Triggers:\n";
        echo "=========\n\n";
        
        foreach ($triggers as $trigger) {
            echo "Trigger: {$trigger['name']}\n";
            echo str_repeat('-', strlen($trigger['name']) + 9) . "\n";
            echo $trigger['sql'] . ";\n\n";
        }
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>