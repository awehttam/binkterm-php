<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Nodelist\NodelistManager;

$nodelistManager = new NodelistManager();

echo "Testing search edge cases and formats...\n\n";

$testCases = [
    // Valid formats
    ['input' => '1:1', 'type' => 'Partial (zone:net)', 'expected' => 'multiple results'],
    ['input' => '1:153/149', 'type' => 'Full (zone:net/node)', 'expected' => 'single result'],
    ['input' => '2:5034/10.0', 'type' => 'Full with point (zone:net/node.point)', 'expected' => 'single result'],
    
    // Invalid formats that should fall back to text search
    ['input' => '1:', 'type' => 'Invalid (zone only)', 'expected' => 'text search fallback'],
    ['input' => ':153', 'type' => 'Invalid (net only)', 'expected' => 'text search fallback'], 
    ['input' => '1:153/', 'type' => 'Invalid (missing node)', 'expected' => 'text search fallback'],
    ['input' => '1/153', 'type' => 'Invalid (missing colon)', 'expected' => 'text search fallback'],
    
    // Regular text searches
    ['input' => 'Vancouver', 'type' => 'Text search (location)', 'expected' => 'text search'],
    ['input' => 'Andre', 'type' => 'Text search (sysop)', 'expected' => 'text search'],
];

foreach ($testCases as $test) {
    echo "Testing: '{$test['input']}' ({$test['type']})\n";
    
    $criteria = ['search_term' => $test['input']];
    $results = $nodelistManager->searchNodes($criteria);
    
    if ($results && count($results) > 0) {
        echo "  ✓ Found " . count($results) . " result(s)\n";
        if (count($results) <= 2) {
            foreach ($results as $node) {
                echo "    - {$node['full_address']} ({$node['sysop_name']})\n";
            }
        } else {
            echo "    - First: {$results[0]['full_address']} ({$results[0]['sysop_name']})\n";
            echo "    - ... and " . (count($results) - 1) . " more\n";
        }
    } else {
        echo "  ✗ No results found\n";
    }
    echo "\n";
}

echo "Edge case testing completed.\n";