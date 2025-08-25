<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\MessageHandler;
use BinktermPHP\Database;

// Initialize database
Database::getInstance();

echo "Comparing HOROSCOPE with Other Echoareas\n";
echo "=======================================\n\n";

try {
    $db = Database::getInstance()->getPdo();
    
    // Get all echoareas with message counts
    echo "1. All echoareas with message counts:\n";
    $stmt = $db->query("
        SELECT ea.id, ea.tag, ea.description, ea.is_active, COUNT(em.id) as message_count
        FROM echoareas ea
        LEFT JOIN echomail em ON ea.id = em.echoarea_id
        GROUP BY ea.id, ea.tag, ea.description, ea.is_active
        ORDER BY message_count DESC
    ");
    $areas = $stmt->fetchAll();
    
    foreach ($areas as $area) {
        $status = $area['is_active'] ? 'ACTIVE' : 'INACTIVE';
        echo "   - {$area['tag']} ({$area['id']}): {$area['message_count']} messages [$status]\n";
        echo "     Description: {$area['description']}\n";
    }
    echo "\n";
    
    // Test MessageHandler with different areas
    echo "2. Testing MessageHandler with different areas:\n";
    $handler = new MessageHandler();
    
    // Test with working areas and HOROSCOPE
    $testAreas = [];
    foreach ($areas as $area) {
        if ($area['message_count'] > 0) {
            $testAreas[] = $area['tag'];
        }
        if (count($testAreas) >= 5) break; // Test up to 5 areas
    }
    
    if (!in_array('HOROSCOPE', $testAreas)) {
        $testAreas[] = 'HOROSCOPE';
    }
    
    foreach ($testAreas as $areaTag) {
        echo "   Testing '$areaTag':\n";
        $result = $handler->getEchomail($areaTag, 1, 5, 1, 'all');
        echo "     - Messages: " . count($result['messages']) . "\n";
        echo "     - Total: " . ($result['pagination']['total'] ?? 0) . "\n";
        echo "     - Unread: " . ($result['unreadCount'] ?? 0) . "\n";
        
        if (!empty($result['messages'])) {
            $first = $result['messages'][0];
            echo "     - First message ID: {$first['id']}, Subject: '{$first['subject']}'\n";
            echo "     - Echoarea field: '{$first['echoarea']}'\n";
        }
        echo "\n";
    }
    
    // Check for any special characters or encoding issues in HOROSCOPE
    echo "3. Detailed HOROSCOPE analysis:\n";
    $horoscopeStmt = $db->prepare("
        SELECT ea.*, 
               HEX(ea.tag) as tag_hex,
               LENGTH(ea.tag) as tag_length
        FROM echoareas ea 
        WHERE ea.tag = ? OR ea.tag LIKE '%HOROSCOPE%'
    ");
    $horoscopeStmt->execute(['HOROSCOPE']);
    $horoscopes = $horoscopeStmt->fetchAll();
    
    foreach ($horoscopes as $h) {
        echo "   - Tag: '{$h['tag']}'\n";
        echo "   - Tag Length: {$h['tag_length']}\n";
        echo "   - Tag Hex: {$h['tag_hex']}\n";
        echo "   - ID: {$h['id']}\n";
        echo "   - Active: " . ($h['is_active'] ? 'YES' : 'NO') . "\n";
        echo "   - Description: '{$h['description']}'\n\n";
    }
    
    // Check HOROSCOPE messages directly
    echo "4. HOROSCOPE messages analysis:\n";
    $msgStmt = $db->prepare("
        SELECT em.id, em.subject, em.from_name, em.date_received, 
               ea.tag as area_tag, em.echoarea_id
        FROM echomail em
        JOIN echoareas ea ON em.echoarea_id = ea.id
        WHERE ea.tag = 'HOROSCOPE'
        ORDER BY em.date_received DESC
    ");
    $msgStmt->execute();
    $messages = $msgStmt->fetchAll();
    
    foreach ($messages as $msg) {
        echo "   - ID: {$msg['id']}\n";
        echo "   - Subject: '{$msg['subject']}'\n";
        echo "   - From: '{$msg['from_name']}'\n";
        echo "   - Date: {$msg['date_received']}\n";
        echo "   - Echoarea ID: {$msg['echoarea_id']}\n";
        echo "   - Area Tag: '{$msg['area_tag']}'\n";
        echo "\n";
    }
    
    // Test the exact SQL query used by MessageHandler for HOROSCOPE
    echo "5. Testing exact MessageHandler SQL for HOROSCOPE:\n";
    $exactStmt = $db->prepare("
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
    $exactStmt->execute([1, 'HOROSCOPE', 25, 0]);
    $exactResults = $exactStmt->fetchAll();
    
    echo "   SQL returned " . count($exactResults) . " results\n";
    if (!empty($exactResults)) {
        $first = $exactResults[0];
        echo "   First result echoarea field: '{$first['echoarea']}'\n";
        echo "   First result color: '{$first['echoarea_color']}'\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nComparison completed.\n";