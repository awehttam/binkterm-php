<?php
/**
 * Command-line nodelist import script for binkterm-php
 * Usage: php import_nodelist.php <nodelist_file> [--force]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\Nodelist\NodelistManager;

function printUsage() {
    echo "Usage: php import_nodelist.php <nodelist_file> [--force]\n";
    echo "\nOptions:\n";
    echo "  --force     Skip confirmation prompts\n";
    echo "  --help      Show this help message\n";
    echo "\nExample:\n";
    echo "  php import_nodelist.php NODELIST.001\n";
    echo "  php import_nodelist.php NODELIST.150 --force\n";
}

function main($argc, $argv) {
    if ($argc < 2 || in_array('--help', $argv)) {
        printUsage();
        exit(0);
    }
    
    $nodelistFile = $argv[1];
    $force = in_array('--force', $argv);
    
    if (!file_exists($nodelistFile)) {
        echo "Error: Nodelist file not found: {$nodelistFile}\n";
        exit(1);
    }
    
    if (!is_readable($nodelistFile)) {
        echo "Error: Cannot read nodelist file: {$nodelistFile}\n";
        exit(1);
    }
    
    echo "BinkTerm-PHP Nodelist Importer\n";
    echo "==============================\n";
    echo "File: {$nodelistFile}\n";
    echo "Size: " . number_format(filesize($nodelistFile)) . " bytes\n\n";
    
    try {
        $nodelistManager = new NodelistManager();
        
        // Check for existing nodelist
        $activeNodelist = $nodelistManager->getActiveNodelist();
        if ($activeNodelist && !$force) {
            echo "Warning: An active nodelist already exists:\n";
            echo "  File: {$activeNodelist['filename']}\n";
            echo "  Date: {$activeNodelist['release_date']}\n";
            echo "  Nodes: " . number_format($activeNodelist['total_nodes']) . "\n\n";
            echo "This will archive the current nodelist and import the new one.\n";
            echo "Continue? (y/N): ";
            
            $response = trim(fgets(STDIN));
            if (strtolower($response) !== 'y' && strtolower($response) !== 'yes') {
                echo "Import cancelled.\n";
                exit(0);
            }
            echo "\n";
        }
        
        echo "Starting import...\n";
        $startTime = microtime(true);
        
        $result = $nodelistManager->importNodelist($nodelistFile, true);
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        echo "Import completed successfully!\n\n";
        echo "Results:\n";
        echo "  Filename: {$result['filename']}\n";
        echo "  Total nodes: " . number_format($result['total_nodes']) . "\n";
        echo "  Inserted nodes: " . number_format($result['inserted_nodes']) . "\n";
        echo "  Duration: {$duration} seconds\n\n";
        
        // Show statistics
        $stats = $nodelistManager->getNodelistStats();
        echo "Current nodelist statistics:\n";
        echo "  Total nodes: " . number_format($stats['total_nodes']) . "\n";
        echo "  Zones: " . $stats['total_zones'] . "\n";
        echo "  Nets: " . $stats['total_nets'] . "\n";
        echo "  Points: " . number_format($stats['point_nodes']) . "\n";
        echo "  Special nodes: " . number_format($stats['special_nodes']) . "\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Run the script
main($argc, $argv);