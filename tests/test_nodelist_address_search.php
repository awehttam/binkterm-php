<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Nodelist\NodelistManager;

$nodelistManager = new NodelistManager();

echo "Testing nodelist address search functionality...\n\n";

// Test cases for address search
$testAddresses = [
    '2:5034/10',     // Standard format without point
    '1:123/456.0',   // Standard format with zero point
    '3:123/456.789', // Standard format with point
    '2:5034',        // Invalid format (should not match)
    'john',          // Regular text search
    '1:123/456'      // Standard format
];

foreach ($testAddresses as $testAddress) {
    echo "Searching for: '$testAddress'\n";
    
    $criteria = ['search_term' => $testAddress];
    $results = $nodelistManager->searchNodes($criteria);
    
    if ($results) {
        echo "  Found " . count($results) . " result(s):\n";
        foreach (array_slice($results, 0, 3) as $node) { // Show first 3 results
            echo "    - {$node['full_address']} ({$node['sysop_name']}) in {$node['location']}\n";
        }
        if (count($results) > 3) {
            echo "    ... and " . (count($results) - 3) . " more\n";
        }
    } else {
        echo "  No results found\n";
    }
    echo "\n";
}

echo "Test completed.\n";