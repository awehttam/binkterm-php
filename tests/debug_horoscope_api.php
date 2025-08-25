<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

echo "Debugging HOROSCOPE Echoarea API Issue\n";
echo "=====================================\n\n";

try {
    $db = Database::getInstance()->getPdo();
    
    // Check if HOROSCOPE echoarea exists
    echo "1. Checking echoarea existence:\n";
    $areaStmt = $db->prepare("SELECT * FROM echoareas WHERE tag = ? OR tag LIKE ?");
    $areaStmt->execute(['HOROSCOPE', '%HOROSCOPE%']);
    $areas = $areaStmt->fetchAll();
    
    if ($areas) {
        foreach ($areas as $area) {
            echo "   - Found area: {$area['tag']} (ID: {$area['id']}) - {$area['description']}\n";
        }
    } else {
        echo "   ✗ No HOROSCOPE echoarea found\n";
        echo "   Let me check all available echoareas:\n";
        $allAreas = $db->query("SELECT tag, description FROM echoareas")->fetchAll();
        foreach ($allAreas as $area) {
            echo "     - {$area['tag']} - {$area['description']}\n";
        }
    }
    echo "\n";
    
    // Check for messages in HOROSCOPE area
    echo "2. Checking for HOROSCOPE messages:\n";
    $msgStmt = $db->prepare("
        SELECT em.*, ea.tag as echoarea 
        FROM echomail em 
        JOIN echoareas ea ON em.echoarea_id = ea.id 
        WHERE ea.tag = ? 
        LIMIT 5
    ");
    $msgStmt->execute(['HOROSCOPE']);
    $messages = $msgStmt->fetchAll();
    
    if ($messages) {
        echo "   ✓ Found " . count($messages) . " messages:\n";
        foreach ($messages as $msg) {
            echo "     - ID: {$msg['id']}, Subject: {$msg['subject']}, From: {$msg['from_name']}\n";
        }
    } else {
        echo "   ✗ No messages found in HOROSCOPE area\n";
        
        // Check total echomail count
        $totalStmt = $db->query("SELECT COUNT(*) as count FROM echomail");
        $total = $totalStmt->fetch()['count'];
        echo "   Total echomail messages in database: $total\n";
        
        // Show some sample messages
        if ($total > 0) {
            echo "   Sample messages from database:\n";
            $sampleStmt = $db->query("
                SELECT em.id, em.subject, em.from_name, ea.tag 
                FROM echomail em 
                JOIN echoareas ea ON em.echoarea_id = ea.id 
                LIMIT 5
            ");
            $samples = $sampleStmt->fetchAll();
            foreach ($samples as $sample) {
                echo "     - [{$sample['tag']}] {$sample['subject']} from {$sample['from_name']}\n";
            }
        }
    }
    echo "\n";
    
    // Test the MessageHandler directly
    echo "3. Testing MessageHandler directly:\n";
    $handler = new MessageHandler();
    
    // Test with user ID 1
    $result = $handler->getEchomail('HOROSCOPE', 1, 25, 1, 'all');
    echo "   Result structure:\n";
    echo "     - Messages count: " . count($result['messages']) . "\n";
    echo "     - Unread count: " . ($result['unreadCount'] ?? 'not set') . "\n";
    echo "     - Total in pagination: " . ($result['pagination']['total'] ?? 'not set') . "\n";
    
    if (!empty($result['messages'])) {
        echo "   First message details:\n";
        $first = $result['messages'][0];
        echo "     - ID: " . ($first['id'] ?? 'N/A') . "\n";
        echo "     - Subject: " . ($first['subject'] ?? 'N/A') . "\n";
        echo "     - From: " . ($first['from_name'] ?? 'N/A') . "\n";
        echo "     - Echoarea: " . ($first['echoarea'] ?? 'N/A') . "\n";
    }
    echo "\n";
    
    // Test URL decoding
    echo "4. Testing URL decoding:\n";
    $testCases = ['HOROSCOPE', urlencode('HOROSCOPE'), 'horoscope', 'Horoscope'];
    foreach ($testCases as $test) {
        $decoded = urldecode($test);
        echo "   '$test' -> '$decoded'\n";
        
        $testStmt = $db->prepare("SELECT COUNT(*) as count FROM echoareas WHERE tag = ?");
        $testStmt->execute([$decoded]);
        $count = $testStmt->fetch()['count'];
        echo "     Matches: $count echoareas\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nDebug completed.\n";