<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Auth;
use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

echo "Testing HOROSCOPE API Endpoint Directly\n";
echo "======================================\n\n";

try {
    // Simulate what the API endpoint does
    echo "1. Testing Authentication:\n";
    $auth = new Auth();
    
    // Check if we can get a user (for testing, we'll use user ID 1)
    $db = Database::getInstance()->getPdo();
    $userStmt = $db->query("SELECT * FROM users LIMIT 1");
    $user = $userStmt->fetch();
    
    if (!$user) {
        echo "   ✗ No users in database\n";
        return;
    }
    
    echo "   ✓ Using test user: {$user['username']} (ID: {$user['id']})\n\n";
    
    // Simulate the API endpoint logic exactly
    echo "2. Simulating API endpoint logic:\n";
    
    // URL decode the echoarea parameter (like the API does)
    $echoarea = urldecode('HOROSCOPE');
    echo "   - Decoded echoarea: '$echoarea'\n";
    
    // Get user ID like the API does
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    echo "   - User ID: $userId\n";
    
    // Get parameters like the API does  
    $page = intval(1); // from ?page=1
    $filter = 'all';   // from &filter=all
    echo "   - Page: $page, Filter: $filter\n\n";
    
    // Call MessageHandler exactly like the API does
    echo "3. Calling MessageHandler:\n";
    $handler = new MessageHandler();
    $result = $handler->getEchomail($echoarea, $page, 25, $userId, $filter);
    
    echo "   - Messages returned: " . count($result['messages']) . "\n";
    echo "   - Unread count: " . ($result['unreadCount'] ?? 'N/A') . "\n";
    echo "   - Pagination total: " . ($result['pagination']['total'] ?? 'N/A') . "\n\n";
    
    // Show the exact JSON that would be returned
    echo "4. JSON Response (first 500 chars):\n";
    $json = json_encode($result);
    echo "   " . substr($json, 0, 500) . "...\n\n";
    
    // Test if it's a case sensitivity issue
    echo "5. Testing case sensitivity:\n";
    $variations = ['HOROSCOPE', 'horoscope', 'Horoscope'];
    foreach ($variations as $variation) {
        $testResult = $handler->getEchomail($variation, 1, 25, $userId, 'all');
        echo "   '$variation': " . count($testResult['messages']) . " messages\n";
    }
    echo "\n";
    
    // Check if there are any SQL errors by testing the query directly
    echo "6. Testing SQL query directly:\n";
    $stmt = $db->prepare("
        SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
               CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read
        FROM echomail em
        JOIN echoareas ea ON em.echoarea_id = ea.id
        LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
        WHERE ea.tag = ?
        ORDER BY CASE 
            WHEN em.date_written > datetime('now') THEN 0 
            ELSE 1 
        END, em.date_written DESC 
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute([$userId, 'HOROSCOPE', 25, 0]);
    $directMessages = $stmt->fetchAll();
    echo "   Direct SQL query returned: " . count($directMessages) . " messages\n";
    
    if (!empty($directMessages)) {
        $first = $directMessages[0];
        echo "   First message: ID={$first['id']}, Subject='{$first['subject']}', is_read={$first['is_read']}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nTest completed.\n";