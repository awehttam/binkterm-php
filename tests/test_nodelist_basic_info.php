<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Nodelist\NodelistManager;

$nodelistManager = new NodelistManager();

echo "Checking nodelist database status...\n\n";

try {
    // Check for active nodelist
    $activeNodelist = $nodelistManager->getActiveNodelist();
    if ($activeNodelist) {
        echo "Active nodelist found:\n";
        echo "  Filename: {$activeNodelist['filename']}\n";
        echo "  Total nodes: {$activeNodelist['total_nodes']}\n";
        echo "  Release date: {$activeNodelist['release_date']}\n";
        echo "  Imported at: {$activeNodelist['imported_at']}\n\n";
    } else {
        echo "No active nodelist found.\n\n";
    }
    
    // Get basic stats
    $stats = $nodelistManager->getNodelistStats();
    if ($stats) {
        echo "Nodelist statistics:\n";
        echo "  Total nodes: {$stats['total_nodes']}\n";
        echo "  Total zones: {$stats['total_zones']}\n";
        echo "  Total nets: {$stats['total_nets']}\n";
        echo "  Point nodes: {$stats['point_nodes']}\n";
        echo "  Special nodes: {$stats['special_nodes']}\n\n";
    }
    
    // Get some sample nodes if any exist
    $sampleNodes = $nodelistManager->searchNodes([]);
    if ($sampleNodes && count($sampleNodes) > 0) {
        echo "Sample nodes (first 5):\n";
        foreach (array_slice($sampleNodes, 0, 5) as $node) {
            echo "  - {$node['full_address']} ({$node['sysop_name']}) in {$node['location']}\n";
        }
    } else {
        echo "No nodes found in database.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}