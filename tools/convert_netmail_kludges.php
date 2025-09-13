<?php
/**
 * Convert existing messages to use separate kludge_lines storage
 * 
 * This tool extracts kludges from message_text and stores them in the new kludge_lines column,
 * then cleans up the message_text to remove the kludges.
 * 
 * Usage: php tools/convert_netmail_kludges.php [--dry-run] [--limit=N] [--type=netmail|echomail|both]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

class MessageKludgeConverter
{
    private $db;
    private $dryRun = false;
    private $limit = null;
    private $messageType = 'both';
    
    public function __construct($dryRun = false, $limit = null, $messageType = 'both')
    {
        $this->db = Database::getInstance()->getPdo();
        $this->dryRun = $dryRun;
        $this->limit = $limit;
        $this->messageType = $messageType;
    }
    
    public function convert()
    {
        echo "Message Kludge Conversion Tool\n";
        echo "=============================\n\n";
        
        if ($this->dryRun) {
            echo "DRY RUN MODE - No changes will be made\n\n";
        }
        
        $totalConverted = 0;
        $totalSkipped = 0;
        
        // Convert netmail if requested
        if ($this->messageType === 'netmail' || $this->messageType === 'both') {
            echo "Converting Netmail Messages:\n";
            echo "----------------------------\n";
            $result = $this->convertMessageType('netmail');
            $totalConverted += $result['converted'];
            $totalSkipped += $result['skipped'];
            echo "\n";
        }
        
        // Convert echomail if requested
        if ($this->messageType === 'echomail' || $this->messageType === 'both') {
            echo "Converting Echomail Messages:\n";
            echo "-----------------------------\n";
            $result = $this->convertMessageType('echomail');
            $totalConverted += $result['converted'];
            $totalSkipped += $result['skipped'];
            echo "\n";
        }
        
        echo "Overall Conversion Summary:\n";
        echo "==========================\n";
        echo "Total Converted: $totalConverted messages\n";
        echo "Total Skipped: $totalSkipped messages\n";
        
        if ($this->dryRun) {
            echo "\nRe-run without --dry-run to apply changes.\n";
        } else {
            echo "\nConversion complete!\n";
        }
    }
    
    private function convertMessageType($table)
    {
        // Get messages that need conversion (kludge_lines is NULL or empty)
        $sql = "SELECT id, message_text FROM $table WHERE kludge_lines IS NULL OR kludge_lines = ''";
        if ($this->limit) {
            $sql .= " LIMIT " . intval($this->limit);
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $messages = $stmt->fetchAll();
        
        echo "Found " . count($messages) . " $table messages to convert\n\n";
        
        if (empty($messages)) {
            echo "No $table messages need conversion.\n";
            return ['converted' => 0, 'skipped' => 0];
        }
        
        $converted = 0;
        $skipped = 0;
        
        foreach ($messages as $message) {
            $result = $this->convertMessage($message['id'], $message['message_text'], $table);
            
            if ($result['converted']) {
                $converted++;
                echo "✓ $table {$message['id']}: Extracted " . count($result['kludges']) . " kludges\n";
                if ($this->dryRun) {
                    echo "  Kludges would be: " . implode(', ', array_keys($result['kludges'])) . "\n";
                    echo "  Clean message length: " . strlen($result['clean_text']) . " chars\n";
                }
            } else {
                $skipped++;
                if (!empty($result['reason'])) {
                    echo "- $table {$message['id']}: {$result['reason']}\n";
                }
            }
        }
        
        echo "\n$table Conversion Summary:\n";
        echo "Converted: $converted messages\n";
        echo "Skipped: $skipped messages\n";
        
        return ['converted' => $converted, 'skipped' => $skipped];
    }
    
    private function convertMessage($messageId, $messageText, $table)
    {
        if (empty($messageText)) {
            return ['converted' => false, 'reason' => 'Empty message text'];
        }
        
        // Normalize line endings
        $messageText = str_replace("\r\n", "\n", $messageText);
        $messageText = str_replace("\r", "\n", $messageText);
        
        $lines = explode("\n", $messageText);
        $kludgeLines = [];
        $cleanLines = [];
        $foundKludges = [];
        
        foreach ($lines as $line) {
            // Detect kludge lines (start with \x01)
            if (strlen($line) > 0 && ord($line[0]) === 0x01) {
                $kludgeLines[] = $line;
                
                // Identify kludge type for reporting
                if (preg_match('/^\x01(\w+)/', $line, $matches)) {
                    $kludgeType = $matches[1];
                    $foundKludges[$kludgeType] = true;
                }
            } else {
                $cleanLines[] = $line;
            }
        }
        
        // If no kludges found, skip this message
        if (empty($kludgeLines)) {
            return ['converted' => false, 'reason' => 'No kludges found'];
        }
        
        $kludgeText = implode("\n", $kludgeLines);
        $cleanText = implode("\n", $cleanLines);
        
        // Update database if not in dry-run mode
        if (!$this->dryRun) {
            try {
                $stmt = $this->db->prepare("
                    UPDATE $table 
                    SET kludge_lines = ?, message_text = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$kludgeText, $cleanText, $messageId]);
            } catch (Exception $e) {
                return ['converted' => false, 'reason' => 'Database error: ' . $e->getMessage()];
            }
        }
        
        return [
            'converted' => true,
            'kludges' => $foundKludges,
            'clean_text' => $cleanText,
            'kludge_text' => $kludgeText
        ];
    }
}

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv);
$limit = null;
$messageType = 'both';

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = intval(substr($arg, 8));
    } elseif (strpos($arg, '--type=') === 0) {
        $type = substr($arg, 7);
        if (in_array($type, ['netmail', 'echomail', 'both'])) {
            $messageType = $type;
        } else {
            echo "Error: Invalid type '$type'. Must be 'netmail', 'echomail', or 'both'.\n";
            exit(1);
        }
    }
}

try {
    $converter = new MessageKludgeConverter($dryRun, $limit, $messageType);
    $converter->convert();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}