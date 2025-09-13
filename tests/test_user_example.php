<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Nodelist\NodelistManager;

$nodelistManager = new NodelistManager();

echo "Testing with user's specific example: 2:5034/10\n\n";

$testAddress = '2:5034/10';
$criteria = ['search_term' => $testAddress];
$results = $nodelistManager->searchNodes($criteria);

if ($results && count($results) > 0) {
    echo "✓ Found " . count($results) . " result(s):\n";
    foreach ($results as $node) {
        echo "    {$node['full_address']} - {$node['sysop_name']} ({$node['location']})\n";
        if (isset($node['system_name']) && $node['system_name']) {
            echo "      System: {$node['system_name']}\n";
        }
        if (isset($node['phone']) && $node['phone'] && $node['phone'] !== '-Unpublished-') {
            echo "      Phone: {$node['phone']}\n";
        }
    }
} else {
    echo "✗ No results found for {$testAddress}\n";
    echo "This specific address may not exist in the current nodelist.\n\n";
    
    // Let's check what zone 2 addresses do exist
    echo "Checking for any Zone 2 addresses...\n";
    $zone2Results = $nodelistManager->searchNodes(['zone' => 2]);
    if ($zone2Results && count($zone2Results) > 0) {
        echo "Found " . count($zone2Results) . " nodes in Zone 2, showing first 5:\n";
        foreach (array_slice($zone2Results, 0, 5) as $node) {
            echo "    {$node['full_address']} - {$node['sysop_name']} ({$node['location']})\n";
        }
    } else {
        echo "No Zone 2 addresses found in this nodelist.\n";
    }
}

echo "\nTesting completed.\n";