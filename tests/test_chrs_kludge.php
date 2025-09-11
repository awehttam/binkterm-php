<?php
/**
 * Test script for CHRS kludge detection functionality
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\BinkdProcessor;

// Create a test class that exposes the private extractChrsKludge method
class TestBinkdProcessor extends BinkdProcessor 
{
    public function __construct() 
    {
        // Skip parent constructor to avoid database dependency
    }
    
    public function testExtractChrsKludge($messageText)
    {
        // Use reflection to access private method
        $reflection = new ReflectionClass($this);
        $method = $reflection->getMethod('extractChrsKludge');
        $method->setAccessible(true);
        
        return $method->invokeArgs($this, [$messageText]);
    }
    
    public function testConvertToUtf8($string, $preferredEncoding = null)
    {
        // Use reflection to access private method
        $reflection = new ReflectionClass($this);
        $method = $reflection->getMethod('convertToUtf8');
        $method->setAccessible(true);
        
        return $method->invokeArgs($this, [$string, $preferredEncoding]);
    }
}

// Test CHRS kludge detection
$processor = new TestBinkdProcessor();

echo "=== CHRS Kludge Detection Tests ===\n\n";

// Test 1: CP866 example (your original example)
$message1 = "AREA:TEST.ECHO\x01\nSome message text\n\x01CHRS: CP866 2\nMore content here";
$detected1 = $processor->testExtractChrsKludge($message1);
echo "Test 1 - CP866 detection:\n";
echo "Input: " . str_replace("\x01", "^A", $message1) . "\n";
echo "Detected: " . ($detected1 ?? 'null') . "\n";
echo "Expected: CP866\n";
echo "Result: " . ($detected1 === 'CP866' ? 'PASS' : 'FAIL') . "\n\n";

// Test 2: UTF-8 example
$message2 = "\x01CHRS: UTF-8 4\nHello world with UTF-8 content";
$detected2 = $processor->testExtractChrsKludge($message2);
echo "Test 2 - UTF-8 detection:\n";
echo "Input: " . str_replace("\x01", "^A", $message2) . "\n";
echo "Detected: " . ($detected2 ?? 'null') . "\n";
echo "Expected: UTF-8\n";
echo "Result: " . ($detected2 === 'UTF-8' ? 'PASS' : 'FAIL') . "\n\n";

// Test 3: Windows-1251 example  
$message3 = "Regular message start\n\x01CHRS: CP1251 2\nRussian text content";
$detected3 = $processor->testExtractChrsKludge($message3);
echo "Test 3 - Windows-1251 mapping:\n";
echo "Input: " . str_replace("\x01", "^A", $message3) . "\n";
echo "Detected: " . ($detected3 ?? 'null') . "\n";
echo "Expected: Windows-1251\n";
echo "Result: " . ($detected3 === 'Windows-1251' ? 'PASS' : 'FAIL') . "\n\n";

// Test 4: No CHRS kludge
$message4 = "AREA:TEST.ECHO\nJust a normal message\nNo encoding specified";
$detected4 = $processor->testExtractChrsKludge($message4);
echo "Test 4 - No CHRS kludge:\n";
echo "Input: " . $message4 . "\n";
echo "Detected: " . ($detected4 ?? 'null') . "\n";
echo "Expected: null\n";
echo "Result: " . ($detected4 === null ? 'PASS' : 'FAIL') . "\n\n";

// Test 5: CHRS without \x01 prefix (some implementations)
$message5 = "CHRS: CP850 2\nMessage content here";
$detected5 = $processor->testExtractChrsKludge($message5);
echo "Test 5 - CHRS without control char:\n";
echo "Input: " . $message5 . "\n";
echo "Detected: " . ($detected5 ?? 'null') . "\n";
echo "Expected: CP850\n";
echo "Result: " . ($detected5 === 'CP850' ? 'PASS' : 'FAIL') . "\n\n";

echo "=== Encoding Conversion Tests ===\n\n";

// Test simple ASCII string (should return unchanged)
$ascii = "Hello World";
$converted1 = $processor->testConvertToUtf8($ascii, 'CP866');
echo "Test 6 - ASCII with CP866 preference:\n";
echo "Input: $ascii\n";
echo "Output: $converted1\n";
echo "Result: " . ($ascii === $converted1 ? 'PASS' : 'FAIL') . "\n\n";

// Test already UTF-8 string 
$utf8 = "Hello 世界";
$converted2 = $processor->testConvertToUtf8($utf8, 'CP437');
echo "Test 7 - UTF-8 with CP437 preference:\n";
echo "Input: $utf8\n";
echo "Output: $converted2\n";  
echo "Result: " . ($utf8 === $converted2 ? 'PASS' : 'FAIL') . "\n\n";

echo "=== Subject Line Encoding Tests ===\n\n";

// Test 8: Subject line with CP866 encoding
// Simulate the actual byte sequence for Cyrillic text in CP866
$cp866Bytes = "\xC0\xEB\xE4\xEF"; // This represents "Алдо" in CP866
$messageWithSubject = "Message with Cyrillic subject\n\x01CHRS: CP866 2\nMessage body here";

// Test the conversion directly
$convertedSubject = $processor->testConvertToUtf8($cp866Bytes, 'CP866');
echo "Test 8 - CP866 subject conversion:\n";
echo "Input bytes: " . bin2hex($cp866Bytes) . "\n";
echo "Converted: $convertedSubject\n";
echo "Length: " . strlen($convertedSubject) . " bytes\n";
echo "Valid UTF-8: " . (mb_check_encoding($convertedSubject, 'UTF-8') ? 'YES' : 'NO') . "\n";
echo "Result: " . (mb_check_encoding($convertedSubject, 'UTF-8') ? 'PASS' : 'FAIL') . "\n\n";

// Test 9: Subject line fallback when no CHRS detected
$latin1Bytes = "\xE1\xE9\xED\xF3\xFA"; // "áéíóú" in Latin-1
$convertedFallback = $processor->testConvertToUtf8($latin1Bytes, null); // No CHRS encoding
echo "Test 9 - Subject fallback encoding:\n";  
echo "Input bytes: " . bin2hex($latin1Bytes) . "\n";
echo "Converted: $convertedFallback\n";
echo "Valid UTF-8: " . (mb_check_encoding($convertedFallback, 'UTF-8') ? 'YES' : 'NO') . "\n";
echo "Result: " . (mb_check_encoding($convertedFallback, 'UTF-8') ? 'PASS' : 'FAIL') . "\n\n";

echo "All tests completed!\n";