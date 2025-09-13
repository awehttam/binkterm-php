<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Nodelist\NodelistManager;

$nodelistManager = new NodelistManager();

echo "Testing partial address search functionality...\n\n";

// Test partial searches
$testPartials = [
    '1:1',       // Should find all nodes in zone 1, net 1
    '1:153',     // Should find all nodes in zone 1, net 153  
    '2:5034',    // Should find all nodes in zone 2, net 5034
];

foreach ($testPartials as $testPartial) {
    echo "=== Searching for net: '$testPartial' ===\n";
    
    $criteria = ['search_term' => $testPartial];
    $results = $nodelistManager->searchNodes($criteria);
    
    if ($results && count($results) > 0) {
        echo "✓ Found " . count($results) . " nodes in net {$testPartial}:\n";
        
        // Show first 5 results
        foreach (array_slice($results, 0, 5) as $node) {
            echo "    {$node['full_address']} - {$node['sysop_name']} ({$node['location']})\n";
        }
        
        if (count($results) > 5) {
            echo "    ... and " . (count($results) - 5) . " more nodes\n";
        }
    } else {
        echo "✗ No nodes found in net {$testPartial}\n";
    }
    echo "\n";
}

// Test that full address search still works
echo "=== Verifying full address search still works ===\n";
$fullAddress = '2:5034/10';
$fullResults = $nodelistManager->searchNodes(['search_term' => $fullAddress]);
if ($fullResults && count($fullResults) > 0) {
    echo "✓ Full address search for {$fullAddress}: {$fullResults[0]['sysop_name']}\n";
} else {
    echo "✗ Full address search failed for {$fullAddress}\n";
}

// Test that text search still works
echo "\n=== Verifying text search still works ===\n";
$textSearch = 'Nick';
$textResults = $nodelistManager->searchNodes(['search_term' => $textSearch]);
if ($textResults && count($textResults) > 0) {
    echo "✓ Text search for '{$textSearch}' found " . count($textResults) . " results\n";
} else {
    echo "✗ Text search failed for '{$textSearch}'\n";
}

echo "\nTesting completed.\n";