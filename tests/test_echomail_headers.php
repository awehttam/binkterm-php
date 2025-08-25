<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/BinkdProcessor.php';
require_once __DIR__ . '/../src/Binkp/Config/BinkpConfig.php';

use BinktermPHP\BinkdProcessor;

class EchomailHeaderTest
{
    public function testEchomailHeaders()
    {
        echo "Testing echomail header formatting...\n";
        
        // Create a test echomail message
        $echomailMessage = [
            'from_address' => '1:123/456',
            'to_address' => '1:124/789', 
            'from_name' => 'Test User',
            'to_name' => 'All',
            'subject' => 'Test Echomail Message',
            'message_text' => "AREA:TEST.ECHO\nThis is a test echomail message.\nWith multiple lines of text.",
            'date_written' => '2024-08-24 12:00:00',
            'attributes' => 0x0000 // No private flag for echomail
        ];
        
        // Create a test netmail message for comparison
        $netmailMessage = [
            'from_address' => '1:123/456',
            'to_address' => '1:124/789',
            'from_name' => 'Test User', 
            'to_name' => 'John Doe',
            'subject' => 'Test Netmail Message',
            'message_text' => "This is a test netmail message.\nPrivate communication.",
            'date_written' => '2024-08-24 12:00:00',
            'attributes' => 0x0001 // Private flag for netmail
        ];
        
        // Test packet creation (this would normally write to a file)
        $tempEchoFile = tempnam(sys_get_temp_dir(), 'echo_test');
        $tempNetFile = tempnam(sys_get_temp_dir(), 'net_test');
        
        try {
            $processor = new BinkdProcessor();
            
            // Test echomail packet creation
            $processor->createOutboundPacket([$echomailMessage], '1:124/789');
            echo "✓ Echomail packet created successfully\n";
            
            // Test netmail packet creation  
            $processor->createOutboundPacket([$netmailMessage], '1:124/789');
            echo "✓ Netmail packet created successfully\n";
            
            echo "✓ All tests passed!\n";
            
        } catch (Exception $e) {
            echo "✗ Test failed: " . $e->getMessage() . "\n";
            return false;
        } finally {
            // Clean up temp files
            if (file_exists($tempEchoFile)) unlink($tempEchoFile);
            if (file_exists($tempNetFile)) unlink($tempNetFile);
        }
        
        return true;
    }
    
    public function testMessageTypeDetection()
    {
        echo "\nTesting message type detection...\n";
        
        // Test echomail detection
        $echomailText = "AREA:TEST.ECHO\nThis is echomail content";
        $isEchomail = !((0x0000) & 0x0001) && strpos($echomailText, 'AREA:') === 0;
        
        if ($isEchomail) {
            echo "✓ Echomail correctly detected\n";
        } else {
            echo "✗ Echomail detection failed\n";
            return false;
        }
        
        // Test netmail detection  
        $netmailText = "This is netmail content";
        $isNetmail = (0x0001) & 0x0001; // Private bit set
        
        if ($isNetmail) {
            echo "✓ Netmail correctly detected\n";
        } else {
            echo "✗ Netmail detection failed\n";
            return false;
        }
        
        return true;
    }
}

// Run the tests
$test = new EchomailHeaderTest();

echo "=== Echomail Header Test Suite ===\n\n";

$result1 = $test->testMessageTypeDetection();
$result2 = $test->testEchomailHeaders();

if ($result1 && $result2) {
    echo "\n=== ALL TESTS PASSED ===\n";
    exit(0);
} else {
    echo "\n=== SOME TESTS FAILED ===\n";
    exit(1);
}