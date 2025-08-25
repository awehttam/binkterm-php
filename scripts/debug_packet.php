<?php

require_once __DIR__ . '/../vendor/autoload.php';

function showUsage()
{
    echo "Usage: php debug_packet.php <packet_file>\n";
    echo "Debug and analyze FTN packet file format\n";
    echo "\n";
}

function hexDump($data, $offset = 0, $length = null)
{
    if ($length === null) {
        $length = strlen($data);
    }
    
    $data = substr($data, $offset, $length);
    $hex = '';
    $ascii = '';
    
    for ($i = 0; $i < strlen($data); $i++) {
        if ($i % 16 === 0) {
            if ($i > 0) {
                echo sprintf("%08X: %s %s\n", $offset + $i - 16, $hex, $ascii);
                $hex = '';
                $ascii = '';
            }
        }
        
        $byte = ord($data[$i]);
        $hex .= sprintf('%02X ', $byte);
        $ascii .= ($byte >= 32 && $byte <= 126) ? chr($byte) : '.';
    }
    
    // Pad the last line
    while (strlen($hex) < 48) {
        $hex .= '   ';
    }
    
    if (strlen($data) > 0) {
        echo sprintf("%08X: %s %s\n", $offset + strlen($data) - (strlen($data) % 16), $hex, $ascii);
    }
}

function analyzePacketHeader($header)
{
    $headerLen = strlen($header);
    echo "=== PACKET HEADER ANALYSIS ===\n";
    echo "Header length: $headerLen bytes\n";
    
    if ($headerLen < 22) {
        echo "ERROR: Header too short ($headerLen bytes, need at least 22)\n";
        echo "\n=== RAW HEADER HEX DUMP ===\n";
        hexDump($header, 0, $headerLen);
        return false;
    }
    
    // Parse basic FTS-0001 header (first 24 bytes)
    $data = unpack('vorigNode/vdestNode/vyear/vmonth/vday/vhour/vminute/vsecond/vbaud/vpacketVersion/vorigNet/vdestNet', substr($header, 0, 24));
    
    if ($data === false) {
        echo "ERROR: Failed to parse basic header\n";
        echo "\n=== RAW HEADER HEX DUMP ===\n";
        hexDump($header, 0, min($headerLen, 58));
        return false;
    }
    
    printf("Origin: %d:%d/%d\n", 1, $data['origNet'], $data['origNode']);
    printf("Destination: %d:%d/%d\n", 1, $data['destNet'], $data['destNode']);
    printf("Date: %04d-%02d-%02d %02d:%02d:%02d\n", 
        $data['year'], $data['month'] + 1, $data['day'],
        $data['hour'], $data['minute'], $data['second']);
    printf("Baud rate: %d\n", $data['baud']);
    printf("Packet version: %d\n", $data['packetVersion']);
    
    // Try to parse password if we have enough data
    if ($headerLen >= 42) {
        $password = substr($header, 34, 8);
        echo "Password: ";
        for ($i = 0; $i < 8; $i++) {
            $char = ord($password[$i]);
            if ($char === 0) break;
            if ($char >= 32 && $char <= 126) {
                echo chr($char);
            } else {
                echo "\\x" . sprintf("%02X", $char);
            }
        }
        echo "\n";
    } else {
        echo "Password: Not enough data\n";
    }
    
    echo "\n=== RAW HEADER HEX DUMP ===\n";
    hexDump($header, 0, min($headerLen, 58));
    
    // Determine where messages should start based on header format
    $messageStart = 58; // Standard FTS-0001
    
    // Check if this looks like the old BinkTest format (64 bytes)
    if ($headerLen >= 64) {
        // Look for message type at different offsets
        $type58 = ($headerLen > 59) ? unpack('v', substr($header, 58, 2)) : false;
        $type64 = ($headerLen > 65) ? unpack('v', substr($header, 64, 2)) : false;
        
        if ($type58 && ($type58[1] === 0 || $type58[1] === 2)) {
            $messageStart = 58;
            echo "\nDetected standard FTS-0001 format (58-byte header)\n";
        } elseif ($type64 && ($type64[1] === 0 || $type64[1] === 2)) {
            $messageStart = 64;
            echo "\nDetected non-standard format (64-byte header)\n";
        } else {
            echo "\nCannot determine message start offset, assuming 58\n";
            $messageStart = 58;
        }
    } else {
        echo "\nHeader too short for standard format, using header length as start\n";
        $messageStart = $headerLen;
    }
    
    echo "Messages should start at offset: $messageStart\n";
    
    return $messageStart;
}

function analyzeMessages($handle, $startOffset = 58)
{
    echo "\n=== MESSAGE ANALYSIS ===\n";
    
    fseek($handle, $startOffset);
    $messageCount = 0;
    $position = $startOffset;
    
    while (!feof($handle)) {
        $pos = ftell($handle);
        $msgType = fread($handle, 2);
        
        if (strlen($msgType) < 2) {
            echo "End of file reached\n";
            break;
        }
        
        $typeData = unpack('v', $msgType);
        if ($typeData === false) {
            echo "ERROR: Cannot unpack message type at position $pos\n";
            break;
        }
        
        $type = $typeData[1];
        printf("\nMessage at offset 0x%08X: Type = %d (0x%04X)\n", $pos, $type, $type);
        
        if ($type === 0) {
            echo "  -> End of packet marker\n";
            break;
        } elseif ($type === 2) {
            echo "  -> FTS-0001 stored message\n";
            $messageCount++;
            
            // Read message header
            $msgHeader = fread($handle, 14);
            if (strlen($msgHeader) < 14) {
                echo "ERROR: Message header too short\n";
                break;
            }
            
            $header = unpack('vorigNode/vdestNode/vorigNet/vdestNet/vattr/vcost', $msgHeader);
            if ($header !== false) {
                printf("    From: %d:%d/%d\n", 1, $header['origNet'], $header['origNode']);
                printf("    To: %d:%d/%d\n", 1, $header['destNet'], $header['destNode']);
                printf("    Attributes: 0x%04X\n", $header['attr']);
                printf("    Cost: %d\n", $header['cost']);
            }
            
            // Read null-terminated strings
            $dateTime = readNullString($handle, 20);
            $toName = readNullString($handle, 36);
            $fromName = readNullString($handle, 36);
            $subject = readNullString($handle, 72);
            
            echo "    Date: $dateTime\n";
            echo "    From name: $fromName\n";
            echo "    To name: $toName\n";
            echo "    Subject: $subject\n";
            
            // Read message text
            $textLength = 0;
            while (($char = fread($handle, 1)) !== false && ord($char) !== 0) {
                $textLength++;
                if ($textLength > 1000) {
                    fseek($handle, -1, SEEK_CUR);
                    skipToNull($handle);
                    break;
                }
            }
            echo "    Text length: $textLength bytes\n";
            
        } else {
            echo "  -> UNKNOWN MESSAGE TYPE!\n";
            echo "  -> This might indicate packet corruption or wrong offset\n";
            
            // Show hex dump around this position
            echo "  -> Hex dump around position:\n";
            $currentPos = ftell($handle);
            fseek($handle, $pos);
            $hexData = fread($handle, 32);
            hexDump($hexData, $pos);
            fseek($handle, $currentPos);
            break;
        }
    }
    
    echo "\nTotal messages found: $messageCount\n";
}

function readNullString($handle, $maxLen)
{
    $string = '';
    $count = 0;
    
    while ($count < $maxLen) {
        $char = fread($handle, 1);
        if ($char === false || ord($char) === 0) {
            break;
        }
        $string .= $char;
        $count++;
    }
    
    return $string;
}

function skipToNull($handle)
{
    while (($char = fread($handle, 1)) !== false && ord($char) !== 0) {
        // Skip to null terminator
    }
}

// Main execution
if ($argc < 2) {
    showUsage();
    exit(1);
}

$packetFile = $argv[1];

if (!file_exists($packetFile)) {
    echo "Error: Packet file not found: $packetFile\n";
    exit(1);
}

if (!is_readable($packetFile)) {
    echo "Error: Packet file not readable: $packetFile\n";
    exit(1);
}

echo "Analyzing packet file: $packetFile\n";
echo "File size: " . filesize($packetFile) . " bytes\n";
echo "Modified: " . date('Y-m-d H:i:s', filemtime($packetFile)) . "\n";
echo "\n";

$handle = fopen($packetFile, 'rb');
if (!$handle) {
    echo "Error: Cannot open packet file\n";
    exit(1);
}

// Read header - try up to 64 bytes to handle non-standard headers
$header = fread($handle, 64);
$messageStart = analyzePacketHeader($header);
if ($messageStart !== false) {
    // Analyze messages starting from the correct offset
    analyzeMessages($handle, $messageStart);
}

fclose($handle);
echo "\nAnalysis complete.\n";