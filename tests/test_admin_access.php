<?php
/**
 * Test Admin Dashboard Access
 * 
 * Verifies admin routes and dashboard functionality
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\AdminController;

echo "Admin Dashboard Access Test\n";
echo "===========================\n\n";

try {
    // Test AdminController
    $adminController = new AdminController();
    $stats = $adminController->getSystemStats();
    
    echo "✓ AdminController working\n";
    echo "✓ System stats loaded: " . $stats['total_users'] . " users, " . $stats['admin_users'] . " admins\n";
    
    // Test networks loading
    $networks = $adminController->getNetworks();
    echo "✓ Networks loaded: " . count($networks) . " networks found\n";
    
    // Check admin users
    $db = Database::getInstance()->getPdo();
    $stmt = $db->query("SELECT username, is_admin FROM users WHERE is_admin = TRUE ORDER BY username");
    $admins = $stmt->fetchAll();
    
    echo "\n📋 Admin Users:\n";
    foreach ($admins as $admin) {
        echo "  - {$admin['username']}\n";
    }
    
    echo "\n🌐 Admin Dashboard URLs:\n";
    echo "  - Main Dashboard: http://yourserver/admin/\n";
    echo "  - Networks Page:  http://yourserver/admin/networks\n";
    echo "  - Users Page:     http://yourserver/admin/users\n";
    
    echo "\n🔑 To Access:\n";
    echo "  1. Make sure you're logged in as an admin user\n";
    echo "  2. Navigate to: http://yourserver/admin/ (note the trailing slash)\n";
    echo "  3. Look for the green 'Manage Networks' button in the Quick Actions section\n";
    echo "  4. Or go directly to: http://yourserver/admin/networks\n";
    
    if (count($admins) == 0) {
        echo "\n❌ WARNING: No admin users found!\n";
        echo "   You need to make a user admin first:\n";
        echo "   UPDATE users SET is_admin = TRUE WHERE username = 'yourusername';\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ Admin infrastructure is ready!\n";
exit(0);