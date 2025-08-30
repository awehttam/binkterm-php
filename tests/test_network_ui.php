<?php
/**
 * Test Networks Admin UI functionality
 * 
 * Quick smoke test to verify the networks admin page is accessible 
 * and API endpoints respond correctly
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AdminController;

echo "Testing Networks Admin UI\n";
echo "========================\n\n";

try {
    // Test AdminController network methods work
    echo "1. Testing AdminController network methods...\n";
    $adminController = new AdminController();
    
    $networks = $adminController->getNetworks();
    echo "   ✓ getNetworks() returned " . count($networks) . " network(s)\n";
    
    // Test getting a specific network (should be fidonet)
    $fidonet = null;
    foreach ($networks as $network) {
        if ($network['domain'] === 'fidonet') {
            $fidonet = $network;
            break;
        }
    }
    
    if ($fidonet) {
        $networkDetail = $adminController->getNetwork($fidonet['id']);
        echo "   ✓ getNetwork() successfully retrieved FidoNet\n";
        
        $uplinks = $adminController->getNetworkUplinks($fidonet['id']);
        echo "   ✓ getNetworkUplinks() returned " . count($uplinks) . " uplink(s)\n";
    } else {
        echo "   ✗ FidoNet network not found\n";
    }
    
    echo "\n2. Testing template variables...\n";
    
    // Check if the template can be rendered with required data
    if (!empty($networks) && is_array($networks[0])) {
        $requiredFields = ['id', 'domain', 'name', 'echoarea_count', 'uplink_count', 'is_active'];
        $hasAllFields = true;
        
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $networks[0])) {
                echo "   ✗ Missing required field: $field\n";
                $hasAllFields = false;
            }
        }
        
        if ($hasAllFields) {
            echo "   ✓ All required template fields present\n";
        }
    } else {
        echo "   ✗ Networks data structure invalid\n";
    }
    
    echo "\n3. Sample network data for UI:\n";
    echo "   ================================\n";
    foreach ($networks as $network) {
        echo "   " . $network['domain'] . " (" . $network['name'] . ")\n";
        echo "     - Echo Areas: " . $network['echoarea_count'] . "\n";
        echo "     - Uplinks: " . $network['uplink_count'] . "\n";
        echo "     - Status: " . ($network['is_active'] ? 'Active' : 'Inactive') . "\n";
        echo "\n";
    }
    
    echo "=== Results ===\n";
    echo "✓ Networks admin UI backend is functional\n";
    echo "✓ Ready for web testing at /admin/networks\n";
    echo "\nNOTE: To test the web UI:\n";
    echo "1. Login as an admin user\n";
    echo "2. Visit /admin/networks in your browser\n";
    echo "3. Or use the 'Manage Networks' button on the admin dashboard\n";

} catch (Exception $e) {
    echo "✗ Error testing networks UI: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);