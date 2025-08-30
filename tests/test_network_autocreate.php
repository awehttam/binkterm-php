<?php
/**
 * Test Network-Aware Echoarea Auto-Creation
 * 
 * Tests that echoareas are properly assigned to networks when auto-created
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\BinkdProcessor;
use BinktermPHP\AdminController;

echo "Testing Network-Aware Echoarea Auto-Creation\n";
echo "===========================================\n\n";

// Create test BinkdProcessor instance
$processor = new BinkdProcessor();
$adminController = new AdminController();

// Create some test networks first
echo "1. Creating test networks...\n";

try {
    $dovenetId = $adminController->createNetwork([
        'domain' => 'dovenet',
        'name' => 'DoveNet', 
        'description' => 'DoveNet - A friendly FTN network',
        'is_active' => true
    ]);
    echo "   ✓ Created DoveNet network (ID: $dovenetId)\n";
    
    $fsxnetId = $adminController->createNetwork([
        'domain' => 'fsxnet',
        'name' => 'fsxNet',
        'description' => 'fsxNet - Another popular FTN network', 
        'is_active' => true
    ]);
    echo "   ✓ Created fsxNet network (ID: $fsxnetId)\n";
    
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "   - Networks already exist, continuing...\n";
    } else {
        echo "   ✗ Error creating networks: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n2. Testing echoarea creation with different network addresses...\n";

// We need to use reflection to test the private getOrCreateEchoarea method
$reflection = new ReflectionClass($processor);
$method = $reflection->getMethod('getOrCreateEchoarea');
$method->setAccessible(true);

$testCases = [
    ['tag' => 'TEST_FIDONET', 'address' => '1:123/456@fidonet', 'expected_domain' => 'fidonet'],
    ['tag' => 'TEST_DOVENET', 'address' => '432:1/100@dovenet', 'expected_domain' => 'dovenet'],
    ['tag' => 'TEST_FSXNET', 'address' => '21:1/200@fsxnet', 'expected_domain' => 'fsxnet'],
    ['tag' => 'TEST_NONETWORK', 'address' => '1:234/567', 'expected_domain' => 'fidonet'], // Default to fidonet
];

$success = true;

foreach ($testCases as $test) {
    echo "   Testing {$test['tag']} with address {$test['address']}... ";
    
    try {
        // Call the private method to create echoarea
        $echoarea = $method->invoke($processor, $test['tag'], $test['address']);
        
        // Verify the echoarea was created with correct network
        $db = BinktermPHP\Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            SELECT ea.*, n.domain 
            FROM echoareas ea 
            JOIN networks n ON ea.network_id = n.id 
            WHERE ea.tag = ?
        ");
        $stmt->execute([$test['tag']]);
        $result = $stmt->fetch();
        
        if ($result && $result['domain'] === $test['expected_domain']) {
            echo "✓ PASSED (assigned to {$result['domain']})\n";
        } else {
            echo "✗ FAILED (expected {$test['expected_domain']}, got " . ($result['domain'] ?? 'NULL') . ")\n";
            $success = false;
        }
        
    } catch (Exception $e) {
        echo "✗ ERROR: " . $e->getMessage() . "\n";
        $success = false;
    }
}

echo "\n3. Verifying network assignment in database...\n";

$db = BinktermPHP\Database::getInstance()->getPdo();
$stmt = $db->query("
    SELECT ea.tag, n.domain, n.name 
    FROM echoareas ea 
    JOIN networks n ON ea.network_id = n.id 
    WHERE ea.tag LIKE 'TEST_%'
    ORDER BY n.domain, ea.tag
");
$results = $stmt->fetchAll();

$currentNetwork = '';
foreach ($results as $row) {
    if ($row['domain'] !== $currentNetwork) {
        $currentNetwork = $row['domain'];
        echo "   {$row['name']} ({$row['domain']}):\n";
    }
    echo "     - {$row['tag']}\n";
}

echo "\n=== Results ===\n";
if ($success) {
    echo "✓ All network assignment tests passed!\n";
    echo "Echoareas are correctly auto-assigned to networks based on sender address.\n";
} else {
    echo "✗ Some tests failed.\n";
}

// Cleanup - remove test echoareas
echo "\nCleaning up test echoareas...\n";
$stmt = $db->prepare("DELETE FROM echoareas WHERE tag LIKE 'TEST_%'");
$stmt->execute();
echo "✓ Test echoareas removed.\n";

exit($success ? 0 : 1);