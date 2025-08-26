<?php

namespace BinktermPHP;

use BinktermPHP\Binkp\Config\BinkpConfig;

class BinkdProcessor
{
    private $db;
    private $inboundPath;
    private $outboundPath;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->config = BinkpConfig::getInstance();
        $this->inboundPath = $this->config->getInboundPath();
        $this->outboundPath = $this->config->getOutboundPath();
        
        // The BinkpConfig methods already handle directory creation
    }

    public function processInboundPackets()
    {
        $processed = 0;
        
        // Process individual packet files
        $pktFiles = glob($this->inboundPath . '/*.pkt');
        foreach ($pktFiles as $file) {
            try {
                if ($this->processPacket($file)) {
                    $processed++;
                    unlink($file); // Remove processed packet
                }
            } catch (\Exception $e) {
                $this->logPacketError($file, $e->getMessage());
                $this->moveToErrorDir($file);
            }
        }
        
        // Process compressed bundles (various formats)
        $bundlePatterns = [
            '/*.zip',    // Standard ZIP
            '/*.su?',    // Sunday bundles: .su0, .su1, etc.
            '/*.mo?',    // Monday bundles: .mo0, .mo1, etc.
            '/*.tu?',    // Tuesday bundles: .tu0, .tu1, etc.
            '/*.we?',    // Wednesday bundles: .we0, .we1, etc.
            '/*.th?',    // Thursday bundles: .th0, .th1, etc.
            '/*.fr?',    // Friday bundles: .fr0, .fr1, etc.
            '/*.sa?',    // Saturday bundles: .sa0, .sa1, etc.
            '/*.arc',    // ARC compressed
            '/*.arj',    // ARJ compressed
            '/*.lzh',    // LHA compressed
            '/*.rar'     // RAR compressed
        ];
        
        foreach ($bundlePatterns as $pattern) {
            $bundleFiles = glob($this->inboundPath . $pattern);
            foreach ($bundleFiles as $file) {
                try {
                    $extractedCount = $this->processBundle($file);
                    if ($extractedCount > 0) {
                        $processed += $extractedCount;
                        unlink($file); // Remove processed bundle
                    }
                } catch (\Exception $e) {
                    $this->logPacketError($file, $e->getMessage());
                    $this->moveToErrorDir($file);
                }
            }
        }
        
        return $processed;
    }

    public function processPacket($filename)
    {
        $packetName = basename($filename);
        $this->logPacket($filename, 'IN', 'pending');
        
        try {
            $handle = fopen($filename, 'rb');
            if (!$handle) {
                $error = "Cannot open packet file: $packetName";
                error_log("[BINKD] $error");
                throw new \Exception($error);
            }

            // Read packet header (58 bytes)
            $header = fread($handle, 58);
            if (strlen($header) < 58) {
                fclose($handle);
                $error = "Invalid packet header in $packetName: only " . strlen($header) . " bytes read, expected 58";
                error_log("[BINKD] $error");
                throw new \Exception($error);
            }

            try {
                $packetInfo = $this->parsePacketHeader($header);
                error_log("[BINKD] Processing packet $packetName from " . $packetInfo['orig_address'] . " to " . $packetInfo['dest_address']);
            } catch (\Exception $e) {
                fclose($handle);
                $error = "Failed to parse packet header for $packetName: " . $e->getMessage();
                error_log("[BINKD] $error");
                throw new \Exception($error);
            }
            
            // Process messages in packet
            $messageCount = 0;
            $failedMessages = 0;
            while (!feof($handle)) {
                try {
                    $message = $this->readMessage($handle, $packetInfo);
                    if ($message) {
                        $this->storeMessage($message, $packetInfo);
                        $messageCount++;
                    }
                } catch (\Exception $e) {
                    $failedMessages++;
                    error_log("[BINKD] Failed to process message #" . ($messageCount + $failedMessages) . " in $packetName: " . $e->getMessage());
                    // Continue processing other messages
                }
            }
            
            fclose($handle);
            
            error_log("[BINKD] Packet $packetName processed: $messageCount messages stored, $failedMessages failed");
            $this->logPacket($filename, 'IN', 'processed');
            
            // Return true even if some messages failed, as long as the packet was readable
            return true;
            
        } catch (\Exception $e) {
            $error = "Packet processing failed for $packetName: " . $e->getMessage();
            error_log("[BINKD] $error");
            $this->logPacket($filename, 'IN', 'error');
            throw $e;
        }
    }

    private function parsePacketHeader($header)
    {
        // Basic FTS-0001 packet header parsing (58 bytes)
        if (strlen($header) < 58) {
            throw new \Exception('Packet header too short: ' . strlen($header) . ' bytes');
        }
        
        // Parse first 24 bytes: standard FTS-0001 header
        $data = unpack('vorigNode/vdestNode/vyear/vmonth/vday/vhour/vminute/vsecond/vbaud/vpacketVersion/vorigNet/vdestNet', substr($header, 0, 24));
        
        if ($data === false) {
            throw new \Exception('Failed to parse packet header');
        }
        
        // Try to extract zone information from extended headers
        $origZone = 1; // Default to zone 1
        $destZone = 1; // Default to zone 1
        
        if (strlen($header) >= 58) {
            // Try FSC-39 (Type-2e) format first: origZone at offset 0x22 (34), destZone at 0x24 (36)
            if (strlen($header) >= 38) {
                $zoneData = unpack('vorigZone/vdestZone', substr($header, 34, 4));
                if ($zoneData && $zoneData['origZone'] > 0 && $zoneData['destZone'] > 0) {
                    $origZone = $zoneData['origZone'];
                    $destZone = $zoneData['destZone'];
                }
                // If FSC-39 zones are zero, try FSC-48 (Type-2+) format: origZone at 0x2E (46), destZone at 0x30 (48)
                elseif (strlen($header) >= 50) {
                    $zoneData = unpack('vorigZone/vdestZone', substr($header, 46, 4));
                    if ($zoneData && $zoneData['origZone'] > 0 && $zoneData['destZone'] > 0) {
                        $origZone = $zoneData['origZone'];
                        $destZone = $zoneData['destZone'];
                    }
                }
            }
        }
        
        return [
            'origNode' => $data['origNode'],
            'destNode' => $data['destNode'], 
            'origNet' => $data['origNet'],
            'destNet' => $data['destNet'],
            'origZone' => $origZone,
            'destZone' => $destZone,
            'year' => $data['year'],
            'month' => $data['month'],
            'day' => $data['day'],
            'hour' => $data['hour'],
            'minute' => $data['minute'],
            'second' => $data['second'],
        ];
    }

    private function readMessage($handle, $packetInfo = null)
    {
        // Read message header (2 bytes message type)
        $msgType = fread($handle, 2);
        if (strlen($msgType) < 2) {
            return null;
        }
        
        $type = unpack('v', $msgType)[1];
        if ($type !== 2) { // Type 2 = stored message
            return null;
        }

        // Read message structure
        $msgHeader = fread($handle, 14);
        if (strlen($msgHeader) < 14) {
            return null;
        }

        // Parse message header
        $header = unpack('vorigNode/vdestNode/vorigNet/vdestNet/vattr/vcost', $msgHeader);
        
        // Read null-terminated strings
        $dateTime = $this->readNullString($handle, 20);
        $toName = $this->readNullString($handle, 36);
        $fromName = $this->readNullString($handle, 36);
        $subject = $this->readNullString($handle, 72);
        
        // Read message text until null terminator
        $messageText = '';
        while (($char = fread($handle, 1)) !== false && ord($char) !== 0) {
            $messageText .= $char;
        }

        // Use packet zone information as fallback if not available in message header
        $origZone = $packetInfo['origZone'] ?? 1;
        $destZone = $packetInfo['destZone'] ?? 1;
        
        return [
            'origAddr' => $origZone . ':' . $header['origNet'] . '/' . $header['origNode'],
            'destAddr' => $destZone . ':' . $header['destNet'] . '/' . $header['destNode'], 
            'fromName' => trim($fromName),
            'toName' => trim($toName),
            'subject' => trim($subject),
            'dateTime' => trim($dateTime),
            'text' => $messageText,
            'attributes' => $header['attr']
        ];
    }

    private function readNullString($handle, $maxLen)
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

    private function storeMessage($message, $packetInfo = null)
    {
        // Determine if this is netmail or echomail based on attributes
        $isNetmail = ($message['attributes'] & 0x0001) > 0; // Private bit set
        
        if ($isNetmail) {
            $this->storeNetmail($message, $packetInfo);
        } else {
            $this->storeEchomail($message, $packetInfo);
        }
    }

    private function storeNetmail($message, $packetInfo = null)
    {
        // Find target user using hybrid matching approach
        $userId = $this->findTargetUser($message['destAddr'], $message['toName']);
        
        $stmt = $this->db->prepare("
            INSERT INTO netmail (user_id, from_address, to_address, from_name, to_name, subject, message_text, date_written, date_received, attributes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), ?)
        ");
        
        $dateWritten = $this->parseFidonetDate($message['dateTime'], $packetInfo);
        
        $stmt->execute([
            $userId,
            $message['origAddr'],
            $message['destAddr'],
            $message['fromName'],
            $message['toName'],
            $message['subject'],
            $message['text'],
            $dateWritten,
            $message['attributes']
        ]);
    }

    private function storeEchomail($message, $packetInfo = null)
    {
        // Extract echo area from message text (should be first line)
        // Handle different line ending formats (FTN uses \r\n or \r)
        $messageText = $message['text'];
        $messageText = str_replace("\r\n", "\n", $messageText); // Normalize \r\n to \n
        $messageText = str_replace("\r", "\n", $messageText);   // Normalize \r to \n
        
        $lines = explode("\n", $messageText);
        $echoareaTag = 'UNKNOWN';
        $cleanedLines = [];
        $kludgeLines = [];
        $messageId = null;
        $originLine = null;
        $originalAuthorAddress = null;
        
        foreach ($lines as $i => $line) {
            // Extract AREA: tag from first line
            if ($i === 0 && strpos($line, 'AREA:') === 0) {
                // Extract just the echoarea name, strip any control characters or extra data
                $areaLine = trim(substr($line, 5));
                // Split on any whitespace or control characters and take first part
                $parts = preg_split('/[\s\x01-\x1F]+/', $areaLine);
                $echoareaTag = trim($parts[0]);
                // Ensure we have a valid echoarea tag
                if (empty($echoareaTag) || strlen($echoareaTag) > 50) {
                    $echoareaTag = 'MALFORMED';
                }
                $kludgeLines[] = "AREA:" . $areaLine;
                continue; // Don't include AREA: line in message body
            }
            
            // Process kludge lines (lines starting with \x01)
            if (strlen($line) > 0 && ord($line[0]) === 0x01) {
                $kludgeLines[] = $line;
                
                // Extract MSGID for storage and original author address
                if (strpos($line, "\x01MSGID:") === 0) {
                    $messageId = trim(substr($line, 7)); // Remove "\x01MSGID:" prefix
                    
                    // Extract original author address from MSGID
                    // MSGID format: "MSGID: address serial" (e.g., "1:123/456 12345678")
                    if (preg_match('/^(\d+:\d+\/\d+(?:\.\d+)?)\s+/', $messageId, $matches)) {
                        $originalAuthorAddress = $matches[1];
                        error_log("DEBUG: Extracted original author address from MSGID: " . $originalAuthorAddress);
                    }
                }
                
                error_log("Echomail kludge line: " . $line);
                continue; // Don't include in message body
            }
            
            // Process SEEN-BY and PATH lines for storage
            if (strpos($line, 'SEEN-BY:') === 0 || strpos($line, 'PATH:') === 0) {
                $kludgeLines[] = $line;
                error_log("Echomail control line: " . $line);
                continue; // Don't include in message body
            }
            
            // Check for origin line (starts with " * Origin:")
            if (strpos($line, ' * Origin:') === 0) {
                $originLine = $line;
                
                // Extract original author address from Origin line
                // Origin format: " * Origin: System Name (1:123/456)"
                if (preg_match('/\((\d+:\d+\/\d+(?:\.\d+)?)\)/', $line, $matches)) {
                    $originalAuthorAddress = $matches[1];
                    error_log("DEBUG: Extracted original author address from Origin: " . $originalAuthorAddress);
                }
                
                $cleanedLines[] = $line; // Keep origin line in message body
                continue;
            }
            
            $cleanedLines[] = $line;
        }
        
        $messageText = implode("\n", $cleanedLines);
        
        // Get or create echoarea
        $echoarea = $this->getOrCreateEchoarea($echoareaTag);
        
        $stmt = $this->db->prepare("
            INSERT INTO echomail (echoarea_id, from_address, from_name, to_name, subject, message_text, date_written, date_received, message_id, origin_line, kludge_lines)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), ?, ?, ?)
        ");
        
        $dateWritten = $this->parseFidonetDate($message['dateTime'], $packetInfo);
        $kludgeText = implode("\n", $kludgeLines);
        
        // Use original author address from MSGID if available, otherwise fall back to packet sender
        $fromAddress = $originalAuthorAddress ?: $message['origAddr'];
        
        error_log("DEBUG: Storing echomail - MSGID author: " . ($originalAuthorAddress ?: 'none') . 
                  ", Packet sender: " . $message['origAddr'] . 
                  ", Using: " . $fromAddress);
        
        $stmt->execute([
            $echoarea['id'],
            $fromAddress,
            $message['fromName'],
            $message['toName'],
            $message['subject'],
            $messageText,
            $dateWritten,
            $messageId,
            $originLine,
            $kludgeText
        ]);
        
        // Update message count
        $this->db->prepare("UPDATE echoareas SET message_count = message_count + 1 WHERE id = ?")
                 ->execute([$echoarea['id']]);
    }

    private function getOrCreateEchoarea($tag)
    {
        $stmt = $this->db->prepare("SELECT * FROM echoareas WHERE tag = ?");
        $stmt->execute([$tag]);
        $echoarea = $stmt->fetch();
        
        if (!$echoarea) {
            $stmt = $this->db->prepare("INSERT INTO echoareas (tag, description, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$tag, 'Auto-created: ' . $tag]);
            
            $stmt = $this->db->prepare("SELECT * FROM echoareas WHERE tag = ?");
            $stmt->execute([$tag]);
            $echoarea = $stmt->fetch();
        }
        
        return $echoarea;
    }

    private function parseFidonetDate($dateStr, $packetInfo = null)
    {
        // Parse Fidonet date format - can be incomplete like "Aug 25  17:42:39"
        $dateStr = trim($dateStr);
        
        // Debug: Log the raw date string being parsed
        error_log("DEBUG: Parsing Fidonet date: '$dateStr'");
        
        // Handle incomplete date format (missing day and year)
        if (preg_match('/(\w{3})\s+(\d{1,2})\s+(\d{1,2}):(\d{2}):(\d{2})/', $dateStr, $matches)) {
            $monthName = $matches[1];
            $day = (int)$matches[2];
            $hour = (int)$matches[3];
            $minute = (int)$matches[4];
            $second = (int)$matches[5];
            
            // Use current year as fallback, but cap at 2024 for sanity
            $currentYear = date('Y');

            
            $year = $currentYear;
            if ($packetInfo && isset($packetInfo['year'])) {
                $year = $packetInfo['year'];
                // Handle Y2K: if packet year is < 1980, it's probably 20xx
                if ($year < 1980 && $year > 70) {
                    $year += 1900; // 70-99 = 1970-1999
                } elseif ($year < 70) {
                    $year += 2000; // 00-69 = 2000-2069
                }
                
                // Sanity check: don't allow years beyond 2024
                //if ($year > 2024) {
//                  $year = $currentYear;
  //            }
            }
            
            $fullDateStr = "$day $monthName $year $hour:$minute:$second";
            $timestamp = strtotime($fullDateStr);
            if ($timestamp) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
        
        // Handle full date format: "01 Jan 70  02:34:56" or "24 Aug 25  17:37:38"
        if (preg_match('/(\d{1,2})\s+(\w{3})\s+(\d{2})\s+(\d{1,2}):(\d{2}):(\d{2})/', $dateStr, $matches)) {
            $day = (int)$matches[1];
            $monthName = $matches[2];
            $year2digit = (int)$matches[3];
            $hour = (int)$matches[4];
            $minute = (int)$matches[5];
            $second = (int)$matches[6];
            
            // Convert 2-digit year to 4-digit using Fidonet convention
            if ($year2digit >= 80) {
                $year4digit = 1900 + $year2digit;  // 80-99 = 1980-1999
            } else {
                $year4digit = 2000 + $year2digit;  // 00-79 = 2000-2079
            }
            
            $fullDateStr = "$day $monthName $year4digit $hour:$minute:$second";
            $timestamp = strtotime($fullDateStr);
            if ($timestamp) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
        
        // Fallback to original parsing for non-standard formats
        $timestamp = strtotime($dateStr);
        if ($timestamp) {
            return date('Y-m-d H:i:s', $timestamp);
        }
        
        return date('Y-m-d H:i:s'); // Current time as fallback
    }

    public function createOutboundPacket($messages, $destAddr)
    {
        $filename = $this->outboundPath . '/' . time() . '.pkt';
        $handle = fopen($filename, 'wb');
        
        if (!$handle) {
            throw new \Exception('Cannot create outbound packet');
        }

        // Write packet header
        $this->writePacketHeader($handle, $destAddr);
        
        // Write messages
        foreach ($messages as $message) {
            $this->writeMessage($handle, $message);
        }
        
        // Write packet terminator
        fwrite($handle, pack('v', 0));
        fclose($handle);
        
        $this->logPacket($filename, 'OUT', 'created');
        return $filename;
    }

    private function writePacketHeader($handle, $destAddr)
    {
        // Parse destination address (format: zone:net/node or zone:net/node.point)  
        $destAddr = trim($destAddr);
        list($destZone, $destNetNode) = explode(':', $destAddr);
        list($destNet, $destNodePoint) = explode('/', $destNetNode);
        $destNode = explode('.', $destNodePoint)[0]; // Remove point if present
        
        // Parse origin address
        $systemAddress = trim($this->config->getSystemAddress());
        list($origZone, $origNetNode) = explode(':', $systemAddress);
        list($origNet, $origNodePoint) = explode('/', $origNetNode);
        $origNode = explode('.', $origNodePoint)[0]; // Remove point if present
        
        $now = time();
        
        // Standard FTS-0001 58-byte packet header
        $header = pack('vvvvvvvvvvvv',
            $origNode,           // 0-1:   Origin node
            $destNode,           // 2-3:   Destination node  
            date('Y', $now),     // 4-5:   Year
            date('n', $now) - 1, // 6-7:   Month (0-based)
            date('j', $now),     // 8-9:   Day
            date('G', $now),     // 10-11: Hour
            date('i', $now),     // 12-13: Minute
            date('s', $now),     // 14-15: Second
            0,                   // 16-17: Baud rate
            2,                   // 18-19: Packet version (2)
            $origNet,            // 20-21: Origin net
            $destNet             // 22-23: Destination net
        );
        
        // Remaining 34 bytes of standard header with zone information
        $header .= str_pad('', 8, "\0");     // 24-31: Product code (low)
        $header .= str_pad('', 2, "\0");     // 32-33: Product code (high)
        
        // FSC-39 (Type-2e) zone information at offset 34-37
        $header .= pack('vv', $origZone, $destZone);  // 34-37: Origin zone, dest zone
        
        $header .= str_pad('', 8, "\0");     // 38-45: Password  
        $header .= str_pad('', 6, "\0");     // 46-51: Reserved
        
        // Additional zone info for FSC-48 compatibility
        $header .= pack('vv', $origZone, $destZone);  // 52-55: Orig zone, dest zone (FSC-48)
        $header .= str_pad('', 2, "\0");     // 56-57: Auxiliary net info
        
        // Verify we have exactly 58 bytes
        if (strlen($header) !== 58) {
            throw new \Exception('Invalid packet header length: ' . strlen($header));
        }
        
        fwrite($handle, $header);
    }

    private function writeMessage($handle, $message)
    {
        // Parse origin address (format: zone:net/node or zone:net/node.point)
        $fromAddress = trim($message['from_address']);
        $toAddress = trim($message['to_address']);
        
        // Debug logging
        error_log("DEBUG: Writing message from: " . $fromAddress . " to: " . $toAddress);
        
        list($origZone, $origNetNode) = explode(':', $fromAddress);
        list($origNet, $origNodePoint) = explode('/', $origNetNode);
        $origNode = explode('.', $origNodePoint)[0]; // Remove point if present
        
        // Parse destination address
        list($destZone, $destNetNode) = explode(':', $toAddress);
        list($destNet, $destNodePoint) = explode('/', $destNetNode);
        $destNode = explode('.', $destNodePoint)[0]; // Remove point if present
        
        // Message text with proper FTN control lines
        $messageText = $message['message_text'];
        
        // Determine message type based on attributes and content
        $isNetmail = ($message['attributes'] ?? 0) & 0x0001; // Private bit set
        // For echomail detection, we need a different approach since we'll add the kludge line
        // We can pass this information via a message flag or detect it differently
        $isEchomail = !$isNetmail && isset($message['is_echomail']) && $message['is_echomail'];
        
        // Debug logging
        error_log("DEBUG: Message attributes: " . ($message['attributes'] ?? 0));
        error_log("DEBUG: Message text starts with: " . substr($messageText, 0, 50));
        error_log("DEBUG: Detected as netmail: " . ($isNetmail ? 'YES' : 'NO'));
        error_log("DEBUG: Detected as echomail: " . ($isEchomail ? 'YES' : 'NO'));
        
        // For echomail, keep the actual destination address in message header
        
        // Write message type (2 bytes)
        fwrite($handle, pack('v', 2));
        
        // Write message header (14 bytes total) - MUST come immediately after message type
        $msgHeader = pack('vvvvvv',
            $origNode,      // Origin node
            $destNode,      // Destination node
            $origNet,       // Origin net
            $destNet,       // Destination net
            $message['attributes'] ?? 0, // Attributes
            0               // Cost
        );
        
        fwrite($handle, $msgHeader);
        
        // Null-terminated strings (after message header)
        // Create Fidonet date format - database stores UTC, convert to local time
        $dateWritten = $message['date_written'];
        if ($dateWritten) {
            // Parse as UTC date and convert to local time for Fidonet packet
            $dt = new \DateTime($dateWritten, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $fidonetDate = $dt->format('d M y  H:i:s');
        } else {
            // Fallback to current time in local timezone
            $fidonetDate = date('d M y  H:i:s');
        }
        fwrite($handle, $fidonetDate . "\0");
        fwrite($handle, substr($message['to_name'], 0, 35) . "\0");
        fwrite($handle, substr($message['from_name'], 0, 35) . "\0");
        fwrite($handle, substr($message['subject'], 0, 71) . "\0");
        
        // Add appropriate kludge lines based on message type
        $kludgeLines = '';
        
        if ($isNetmail) {
            // Netmail-specific kludge lines
            
            // Add MSGID kludge (required for netmail)
            $msgId = time() . sprintf('%04x', rand(0, 65535)); // Simple message ID
            $kludgeLines .= "\x01MSGID: {$fromAddress} {$msgId}\r\n";
            
            // Add reply address information in multiple formats for compatibility
            $kludgeLines .= "\x01REPLYADDR {$fromAddress}\r\n";
            $kludgeLines .= "\x01REPLYTO {$fromAddress}\r\n";
            
            // Add INTL kludge for zone routing (required for inter-zone mail)
            list($fromZone, $fromRest) = explode(':', $fromAddress);
            list($toZone, $toRest) = explode(':', $toAddress);
            $kludgeLines .= "\x01INTL {$toZone}:{$toRest} {$fromZone}:{$fromRest}\r\n";
            
            // Add FMPT/TOPT kludges for point addressing if needed
            if (strpos($fromAddress, '.') !== false) {
                list($mainAddr, $point) = explode('.', $fromAddress);
                $kludgeLines .= "\x01FMPT {$point}\r\n";
            }
            
            if (strpos($toAddress, '.') !== false) {
                list($mainAddr, $point) = explode('.', $toAddress);  
                $kludgeLines .= "\x01TOPT {$point}\r\n";
            }
            
            // Add FLAGS kludge for netmail attributes
            $flags = [];
            if (($message['attributes'] ?? 0) & 0x0001) $flags[] = 'PVT'; // Private
            if (($message['attributes'] ?? 0) & 0x0004) $flags[] = 'RCV'; // Received
            if (($message['attributes'] ?? 0) & 0x0008) $flags[] = 'SNT'; // Sent
            if (!empty($flags)) {
                $kludgeLines .= "\x01FLAGS " . implode(' ', $flags) . "\r\n";
            }
        } elseif ($isEchomail) {
            // Test plain text AREA: format (no SOH character)
            if (isset($message['echoarea_tag'])) {
                // Try plain text AREA: line as first line (no ^A prefix)
                $messageText = "AREA:{$message['echoarea_tag']}\r\n" . $messageText;
                error_log("DEBUG: PLAIN TEXT - AREA line added: " . bin2hex("AREA:{$message['echoarea_tag']}\r\n"));
            }
        }
        
        $messageText = $kludgeLines . $messageText;
        
        // Add tearline and origin
        if (!empty($messageText) && !str_ends_with($messageText, "\r\n")) {
            $messageText .= "\r\n";
        }
        $messageText .= "--- BinkTest v1.0\r\n";
        
        // Origin line should show the actual system address (including point if it's a point system)
        $systemAddress = $fromAddress; // Use the full system address including point
        
        $messageText .= " * Origin: " . $this->config->getSystemSysop() . " (" . $systemAddress . ")";
        
        // Add echomail-specific control lines after origin
        if ($isEchomail) {
            $messageText .= "\r\n";
            
            // Parse system address for SEEN-BY and PATH lines
            list($zone, $netNode) = explode(':', $systemAddress);
            list($net, $nodePoint) = explode('/', $netNode);
            $hostNode = explode('.', $nodePoint)[0]; // Host node without point
            
            // Add SEEN-BY line (required for echomail) - uses host node only
            $messageText .= "SEEN-BY: {$net}/{$hostNode}\r\n";
            
            // Add PATH line (required for echomail routing) - includes point if present
            if (strpos($nodePoint, '.') !== false) {
                // Point system: include full node.point in PATH
                $messageText .= "\x01PATH: {$net}/{$nodePoint}\r\n";
            } else {
                // Regular node: just net/node
                $messageText .= "\x01PATH: {$net}/{$hostNode}\r\n";
            }
        }
        
        fwrite($handle, $messageText . "\0");
    }

    private function logPacket($filename, $direction, $status)
    {
        $stmt = $this->db->prepare("
            INSERT INTO packets (filename, packet_type, status, created_at) 
            VALUES (?, ?, ?, datetime('now'))
        ");
        $stmt->execute([basename($filename), $direction, $status]);
    }

    private function logPacketError($filename, $error)
    {
        $stmt = $this->db->prepare("
            INSERT INTO packets (filename, packet_type, status, error_message, created_at) 
            VALUES (?, 'IN', 'error', ?, datetime('now'))
        ");
        $stmt->execute([basename($filename), $error]);
    }

    private function findTargetUser($destAddr, $toName)
    {
        // Strategy 1: Exact address match
        $stmt = $this->db->prepare("SELECT id FROM users WHERE fidonet_address = ? LIMIT 1");
        $stmt->execute([$destAddr]);
        $user = $stmt->fetch();
        if ($user) {
            return $user['id'];
        }
        
        // Strategy 2: Point address match - extract host address for point routing
        if (strpos($destAddr, '.') !== false) {
            // Extract host address (remove point)
            list($hostAddr, $point) = explode('.', $destAddr);
            $stmt = $this->db->prepare("SELECT id FROM users WHERE fidonet_address = ? LIMIT 1");
            $stmt->execute([$hostAddr]);
            $user = $stmt->fetch();
            if ($user) {
                return $user['id'];
            }
        }
        
        // Strategy 3: Name-based matching (case-insensitive)
        if (!empty($toName)) {
            $stmt = $this->db->prepare("
                SELECT id FROM users 
                WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$toName, $toName]);
            $user = $stmt->fetch();
            if ($user) {
                return $user['id'];
            }
        }
        
        // Strategy 4: Fallback to system administrator (user ID 1)
        $stmt = $this->db->prepare("SELECT id FROM users ORDER BY id LIMIT 1");
        $stmt->execute();
        $user = $stmt->fetch();
        return $user ? $user['id'] : 1; // Default to ID 1 if no users exist
    }

    private function processBundle($bundleFile)
    {
        $extension = strtolower(pathinfo($bundleFile, PATHINFO_EXTENSION));
        $processed = 0;
        $tempDir = $this->inboundPath . '/temp_' . time() . '_' . rand(1000, 9999);
        
        try {
            // Create temporary extraction directory
            if (!mkdir($tempDir, 0755, true)) {
                throw new \Exception("Cannot create temporary directory: $tempDir");
            }
            
            // Handle different bundle formats
            if ($extension === 'zip') {
                $processed = $this->extractZipBundle($bundleFile, $tempDir);
            } elseif ($this->isFidonetDayBundle($extension)) {
                // Fidonet daily bundles (su0, mo1, etc.) are typically ZIP format
                $processed = $this->extractZipBundle($bundleFile, $tempDir);
            } elseif (in_array($extension, ['arc', 'arj', 'lzh', 'rar'])) {
                // For now, try to handle these as ZIP (some may work)
                // In the future, could add specific handlers for each format
                try {
                    $processed = $this->extractZipBundle($bundleFile, $tempDir);
                } catch (\Exception $e) {
                    throw new \Exception("Unsupported bundle format: $extension (file: $bundleFile)");
                }
            } else {
                throw new \Exception("Unknown bundle format: $extension (file: $bundleFile)");
            }
            
        } finally {
            // Always clean up temporary directory
            $this->cleanupTempDir($tempDir);
        }
        
        $this->logPacket($bundleFile, 'IN', $processed > 0 ? 'processed' : 'empty');
        return $processed;
    }
    
    private function extractZipBundle($bundleFile, $tempDir)
    {
        $zip = new \ZipArchive();
        $result = $zip->open($bundleFile);
        
        if ($result !== TRUE) {
            throw new \Exception("Cannot open bundle file: $bundleFile (Error code: $result)");
        }
        
        $processed = 0;
        
        try {
            // Extract all files to temporary directory
            if (!$zip->extractTo($tempDir)) {
                throw new \Exception("Cannot extract bundle to: $tempDir");
            }
            
            $zip->close();
            
            // Process all .pkt files in the extracted bundle
            $extractedFiles = glob($tempDir . '/*.pkt');
            foreach ($extractedFiles as $pktFile) {
                try {
                    if ($this->processPacket($pktFile)) {
                        $processed++;
                    }
                    unlink($pktFile); // Remove processed packet
                } catch (\Exception $e) {
                    error_log("Error processing extracted packet $pktFile: " . $e->getMessage());
                    // Move failed packet to error directory
                    $this->moveToErrorDir($pktFile);
                }
            }
            
        } catch (\Exception $e) {
            $zip->close();
            throw $e;
        }
        
        return $processed;
    }
    
    private function cleanupTempDir($tempDir)
    {
        if (!is_dir($tempDir)) {
            return;
        }
        
        // Clean up any remaining files in temp directory
        $remainingFiles = glob($tempDir . '/*');
        foreach ($remainingFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        rmdir($tempDir);
    }

    private function isFidonetDayBundle($extension)
    {
        // Check for Fidonet daily bundle extensions: su0-su9, mo0-mo9, etc.
        if (strlen($extension) !== 3) {
            return false;
        }
        
        $dayPrefix = substr($extension, 0, 2);
        $dayNumber = substr($extension, 2, 1);
        
        $validPrefixes = ['su', 'mo', 'tu', 'we', 'th', 'fr', 'sa'];
        return in_array($dayPrefix, $validPrefixes) && ctype_digit($dayNumber);
    }

    private function moveToErrorDir($file)
    {
        $errorDir = $this->inboundPath . '/error';
        if (!is_dir($errorDir)) {
            mkdir($errorDir, 0755, true);
        }
        $destFile = $errorDir . '/' . basename($file);
        
        // Handle duplicate filenames by appending timestamp
        if (file_exists($destFile)) {
            $pathInfo = pathinfo($destFile);
            $destFile = $errorDir . '/' . $pathInfo['filename'] . '_' . time() . '.' . $pathInfo['extension'];
        }
        
        rename($file, $destFile);
    }
}