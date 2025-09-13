<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Nodelist\NodelistManager;

$nodelistManager = new NodelistManager();

echo "Testing address search with real nodelist data...\n\n";

// Test with addresses that should exist based on the sample
$testAddresses = [
    '1:1/1',      // Should find Nick Andre
    '1:1/19',     // Should find Andrew Leary  
    '1:1/101',    // Should find Jason Bock
];

foreach ($testAddresses as $testAddress) {
    echo "=== Searching for: '$testAddress' ===\n";
    
    // Test our new search_term logic
    $criteria = ['search_term' => $testAddress];
    $results = $nodelistManager->searchNodes($criteria);
    
    if ($results && count($results) > 0) {
        echo "✓ Found " . count($results) . " result(s) using search_term:\n";
        foreach ($results as $node) {
            echo "    {$node['full_address']} - {$node['sysop_name']} ({$node['location']})\n";
        }
    } else {
        echo "✗ No results found using search_term\n";
    }
    
    // Also test the direct findNode method for comparison
    $directResult = $nodelistManager->findNode($testAddress);
    if ($directResult) {
        echo "✓ Direct findNode result: {$directResult['full_address']} - {$directResult['sysop_name']}\n";
    } else {
        echo "✗ Direct findNode: No result\n";
    }
    
    echo "\n";
}

// Also test a text search to make sure we didn't break that
echo "=== Testing text search (should still work) ===\n";
$textCriteria = ['search_term' => 'Nick'];
$textResults = $nodelistManager->searchNodes($textCriteria);
if ($textResults && count($textResults) > 0) {
    echo "✓ Found " . count($textResults) . " result(s) for text search 'Nick':\n";
    foreach (array_slice($textResults, 0, 3) as $node) {
        echo "    {$node['full_address']} - {$node['sysop_name']} ({$node['location']})\n";
    }
} else {
    echo "✗ No results found for text search 'Nick'\n";
}