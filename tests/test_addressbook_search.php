<?php

/**
 * Test address book search functionality directly
 */

require_once __DIR__ . '/vendor/autoload.php';

$srcFiles = glob(__DIR__ . '/src/*.php');
foreach ($srcFiles as $file) {
    require_once $file;
}

try {
    $controller = new \BinktermPHP\AddressBookController();
    
    echo "Testing address book search functionality...\n\n";
    
    // Test search with no query (should return all entries)
    echo "=== Testing with no search query ===\n";
    $allEntries = $controller->getUserEntries(1, '');
    echo "Found " . count($allEntries) . " total entries\n";
    
    if (count($allEntries) > 0) {
        echo "First few entries:\n";
        foreach (array_slice($allEntries, 0, 3) as $entry) {
            echo "- ID: " . $entry['id'] . ", Name: " . $entry['name'] . ", User ID: " . $entry['messaging_user_id'] . "\n";
        }
    }
    
    // Test search with a query
    echo "\n=== Testing search with query 'Aug' ===\n";
    $searchEntries = $controller->getUserEntries(1, 'Aug');
    echo "Found " . count($searchEntries) . " entries matching 'Aug'\n";
    
    if (count($searchEntries) > 0) {
        foreach ($searchEntries as $entry) {
            echo "- ID: " . $entry['id'] . ", Name: " . $entry['name'] . ", User ID: " . $entry['messaging_user_id'] . "\n";
        }
    }
    
    // Test search with another query
    echo "\n=== Testing search with query '460' ===\n";
    $addressSearchEntries = $controller->getUserEntries(1, '460');
    echo "Found " . count($addressSearchEntries) . " entries matching '460'\n";
    
    if (count($addressSearchEntries) > 0) {
        foreach ($addressSearchEntries as $entry) {
            echo "- ID: " . $entry['id'] . ", Name: " . $entry['name'] . ", Address: " . $entry['node_address'] . "\n";
        }
    }
    
    // Test the search entries method (used for autocomplete)
    echo "\n=== Testing searchEntries method ===\n";
    $autoCompleteEntries = $controller->searchEntries(1, 'Aug', 5);
    echo "Found " . count($autoCompleteEntries) . " autocomplete entries for 'Aug'\n";
    
    if (count($autoCompleteEntries) > 0) {
        foreach ($autoCompleteEntries as $entry) {
            echo "- " . $entry['name'] . " <" . $entry['messaging_user_id'] . "> (" . $entry['node_address'] . ")\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

?>