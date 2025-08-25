<?php

namespace Binktest\Binkp\Queue;

use Binktest\BinkdProcessor;
use Binktest\Binkp\Config\BinkpConfig;
use Binktest\Binkp\Protocol\BinkpClient;

class OutboundQueue
{
    private $config;
    private $logger;
    private $binkdProcessor;
    private $client;
    
    public function __construct($config = null, $logger = null)
    {
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->logger = $logger;
        $this->binkdProcessor = new BinkdProcessor();
        $this->client = new BinkpClient($this->config, $this->logger);
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
        $this->client->setLogger($logger);
    }
    
    public function log($message, $level = 'INFO')
    {
        if ($this->logger) {
            $this->logger->log($level, "[OUTBOUND] {$message}");
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] [{$level}] [OUTBOUND] {$message}\n";
        }
    }
    
    public function queueNetmail($fromAddress, $toAddress, $fromName, $toName, $subject, $messageText)
    {
        try {
            $message = [
                'from_address' => $fromAddress,
                'to_address' => $toAddress,
                'from_name' => $fromName,
                'to_name' => $toName,
                'subject' => $subject,
                'message_text' => $messageText,
                'date_written' => date('Y-m-d H:i:s'),
                'attributes' => 0x0001
            ];
            
            $filename = $this->binkdProcessor->createOutboundPacket([$message], $toAddress);
            
            $this->log("Netmail queued for delivery: {$toAddress} - {$subject}");
            return basename($filename);
            
        } catch (\Exception $e) {
            $this->log("Failed to queue netmail: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    public function queueEchomail($fromAddress, $echoareaTag, $fromName, $toName, $subject, $messageText)
    {
        try {
            $messageTextWithArea = "AREA:{$echoareaTag}\n{$messageText}";
            
            $message = [
                'from_address' => $fromAddress,
                'to_address' => $this->getEchoareaUplink($echoareaTag),
                'from_name' => $fromName,
                'to_name' => $toName,
                'subject' => $subject,
                'message_text' => $messageTextWithArea,
                'date_written' => date('Y-m-d H:i:s'),
                'attributes' => 0x0000
            ];
            
            $filename = $this->binkdProcessor->createOutboundPacket([$message], $message['to_address']);
            
            $this->log("Echomail queued for delivery: {$echoareaTag} - {$subject}");
            return basename($filename);
            
        } catch (\Exception $e) {
            $this->log("Failed to queue echomail: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    private function getEchoareaUplink($echoareaTag)
    {
        // First try to get the specific uplink for this echoarea from database
        try {
            $db = \Binktest\Database::getInstance()->getPdo();
            $stmt = $db->prepare("SELECT uplink_address FROM echoareas WHERE tag = ? AND is_active = 1");
            $stmt->execute([$echoareaTag]);
            $result = $stmt->fetch();
            
            if ($result && $result['uplink_address']) {
                return $result['uplink_address'];
            }
        } catch (\Exception $e) {
            // Fall through to config-based uplinks if database lookup fails
        }
        
        // Fall back to default uplink from config
        $defaultAddress = $this->config->getDefaultUplinkAddress();
        if ($defaultAddress) {
            return $defaultAddress;
        }
        
        // Ultimate fallback to first enabled uplink
        $uplinks = $this->config->getEnabledUplinks();
        if (empty($uplinks)) {
            throw new \Exception("No uplinks configured for echomail delivery");
        }
        
        return $uplinks[0]['address'];
    }
    
    public function processOutbound()
    {
        $outboundPath = $this->config->getOutboundPath();
        $files = glob($outboundPath . '/*.pkt');
        
        if (empty($files)) {
            return [];
        }
        
        $this->log("Processing " . count($files) . " outbound files");
        
        $uplinks = $this->config->getEnabledUplinks();
        if (empty($uplinks)) {
            $this->log("No uplinks configured for outbound processing", 'WARNING');
            return [];
        }
        
        $results = [];
        
        foreach ($uplinks as $uplink) {
            $address = $uplink['address'];
            
            try {
                $this->log("Sending outbound files to: {$address}");
                $result = $this->client->pollUplink($address);
                $results[$address] = $result;
                
                if ($result['success']) {
                    $this->log("Successfully sent files to: {$address}");
                } else {
                    $this->log("Failed to send files to {$address}: " . ($result['error'] ?? 'Unknown error'), 'ERROR');
                }
                
            } catch (\Exception $e) {
                $this->log("Error sending to {$address}: " . $e->getMessage(), 'ERROR');
                $results[$address] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    public function sendToUplink($address, $filename = null)
    {
        $uplink = $this->config->getUplinkByAddress($address);
        if (!$uplink) {
            throw new \Exception("Uplink not found: {$address}");
        }
        
        if ($filename) {
            $outboundPath = $this->config->getOutboundPath();
            $filepath = $outboundPath . '/' . $filename;
            
            if (!file_exists($filepath)) {
                throw new \Exception("Outbound file not found: {$filename}");
            }
            
            $this->log("Sending specific file {$filename} to: {$address}");
        } else {
            $this->log("Sending all outbound files to: {$address}");
        }
        
        return $this->client->pollUplink($address);
    }
    
    public function getOutboundFiles()
    {
        $outboundPath = $this->config->getOutboundPath();
        $files = glob($outboundPath . '/*.pkt');
        $fileInfo = [];
        
        foreach ($files as $file) {
            $stats = $this->analyzePacketFile($file);
            
            $fileInfo[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'created' => date('Y-m-d H:i:s', filectime($file)),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
                'path' => $file,
                'message_count' => $stats['message_count'],
                'dest_address' => $stats['dest_address'],
                'orig_address' => $stats['orig_address']
            ];
        }
        
        return $fileInfo;
    }
    
    private function analyzePacketFile($filepath)
    {
        $stats = [
            'message_count' => 0,
            'dest_address' => 'Unknown',
            'orig_address' => 'Unknown'
        ];
        
        try {
            $handle = fopen($filepath, 'rb');
            if (!$handle) {
                $this->log("Cannot open packet file: {$filepath}", 'WARNING');
                return $stats;
            }
            
            // Read and parse packet header
            $header = fread($handle, 58);
            if (strlen($header) >= 22) { // Minimum needed for basic parsing
                $packetInfo = $this->parsePacketHeader($header);
                if ($packetInfo['destNet'] > 0 && $packetInfo['destNode'] > 0) {
                    $stats['dest_address'] = $packetInfo['destNet'] . ':' . $packetInfo['destNode'];
                }
                if ($packetInfo['origNet'] > 0 && $packetInfo['origNode'] > 0) {
                    $stats['orig_address'] = $packetInfo['origNet'] . ':' . $packetInfo['origNode'];
                }
            }
            
            // Count messages in packet
            $messageCount = 0;
            $maxMessages = 1000; // Safety limit
            
            // Skip to start of messages (after 58-byte header)
            if (strlen($header) >= 58) {
                fseek($handle, 58);
            }
            
            while (!feof($handle) && $messageCount < $maxMessages) {
                $position = ftell($handle);
                $msgType = fread($handle, 2);
                if (strlen($msgType) < 2) break;
                
                $typeData = unpack('v', $msgType);
                if ($typeData === false) break;
                
                $type = $typeData[1];
                
                // Check for reasonable message type values
                if ($type === 2) { // FTS-0001 stored message type
                    $messageCount++;
                    
                    // Skip message header
                    $msgHeader = fread($handle, 14);
                    if (strlen($msgHeader) < 14) break;
                    
                    // Skip null-terminated strings safely
                    if (!$this->skipNullString($handle, 20)) break; // Date/time
                    if (!$this->skipNullString($handle, 36)) break; // To name
                    if (!$this->skipNullString($handle, 36)) break; // From name
                    if (!$this->skipNullString($handle, 72)) break; // Subject
                    
                    // Skip message text safely
                    $textBytes = 0;
                    while (($char = fread($handle, 1)) !== false && $char !== '' && ord($char) !== 0) {
                        $textBytes++;
                        if ($textBytes > 64000) { // Reasonable message size limit
                            $this->log("Message text too long in packet {$filepath}, stopping analysis", 'WARNING');
                            break 2;
                        }
                    }
                } elseif ($type === 0) {
                    // End of packet marker
                    break;
                } else {
                    // Check if this looks like a reasonable message type
                    if ($type > 10 || $type < 0) {
                        $this->log("Invalid message type {$type} at offset {$position} in packet {$filepath}, stopping analysis", 'WARNING');
                        break;
                    }
                    
                    // Unknown but potentially valid message type
                    $this->log("Unknown message type {$type} in packet {$filepath}, skipping", 'WARNING');
                    // Try to skip this message, but be careful
                    $skipBytes = 0;
                    while (($char = fread($handle, 1)) !== false && $skipBytes < 1000) {
                        $skipBytes++;
                        if (ord($char) === 0) {
                            // Found potential message boundary
                            break;
                        }
                    }
                }
            }
            
            $stats['message_count'] = $messageCount;
            
            fclose($handle);
            
        } catch (\Exception $e) {
            $this->log("Error analyzing packet {$filepath}: " . $e->getMessage(), 'WARNING');
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
        }
        
        return $stats;
    }
    
    private function parsePacketHeader($header)
    {
        // Basic FTS-0001 packet header parsing with error checking
        if (strlen($header) < 58) {
            return [
                'origNode' => 0,
                'destNode' => 0,
                'origNet' => 0,
                'destNet' => 0,
            ];
        }
        
        // Parse the standard 58-byte FTS-0001 header
        $data = unpack('vorigNode/vdestNode/vyear/vmonth/vday/vhour/vminute/vsecond/vbaud/vpacketVersion/vorigNet/vdestNet', substr($header, 0, 24));
        
        if ($data === false) {
            return [
                'origNode' => 0,
                'destNode' => 0,
                'origNet' => 0,
                'destNet' => 0,
            ];
        }
        
        return [
            'origNode' => $data['origNode'] ?? 0,
            'destNode' => $data['destNode'] ?? 0,
            'origNet' => $data['origNet'] ?? 0,
            'destNet' => $data['destNet'] ?? 0,
        ];
    }
    
    private function skipNullString($handle, $maxLen)
    {
        $count = 0;
        while ($count < $maxLen) {
            $char = fread($handle, 1);
            if ($char === false) {
                return false; // Read error
            }
            if (ord($char) === 0) {
                return true; // Found null terminator
            }
            $count++;
        }
        return true; // Reached max length
    }
    
    public function deleteOutboundFile($filename)
    {
        $outboundPath = $this->config->getOutboundPath();
        $filepath = $outboundPath . '/' . $filename;
        
        if (!file_exists($filepath)) {
            throw new \Exception("Outbound file not found: {$filename}");
        }
        
        if (unlink($filepath)) {
            $this->log("Deleted outbound file: {$filename}");
            return true;
        } else {
            throw new \Exception("Failed to delete outbound file: {$filename}");
        }
    }
    
    public function cleanupOldFiles($maxAgeHours = 48)
    {
        $outboundPath = $this->config->getOutboundPath();
        $cutoff = time() - ($maxAgeHours * 3600);
        $cleaned = [];
        
        $files = glob($outboundPath . '/*.pkt');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                $filename = basename($file);
                if (unlink($file)) {
                    $cleaned[] = $filename;
                    $this->log("Cleaned up old outbound file: {$filename}");
                }
            }
        }
        
        return $cleaned;
    }
    
    public function getStats()
    {
        $outboundPath = $this->config->getOutboundPath();
        $files = glob($outboundPath . '/*.pkt');
        
        $totalSize = 0;
        $totalMessages = 0;
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
            $stats = $this->analyzePacketFile($file);
            $totalMessages += $stats['message_count'];
        }
        
        return [
            'pending_files' => count($files),
            'total_size' => $totalSize,
            'total_messages' => $totalMessages,
            'outbound_path' => $outboundPath,
            'last_check' => date('Y-m-d H:i:s')
        ];
    }
    
    public function priorityPoll($address)
    {
        $this->log("Priority poll requested for: {$address}");
        return $this->sendToUplink($address);
    }
}