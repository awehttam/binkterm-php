<?php
/**
 * CHRS Kludge Migration Tool for Existing Echomail Messages
 * 
 * This tool finds existing echomail messages that contain CHRS kludge lines
 * and re-processes them with the correct character encoding to fix display issues.
 * 
 * Usage: php tools/migrate_chrs_echomail.php [options]
 * 
 * Options:
 *   --dry-run       Show what would be updated without making changes
 *   --limit=N       Process only N messages (default: all)
 *   --echoarea=TAG  Process only messages from specific echoarea
 *   --message-id=ID Process only specific message ID
 *   --verbose       Show detailed processing information
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

class ChrsEchomailMigrator
{
    private $db;
    private $processor;
    private $dryRun = false;
    private $verbose = false;
    private $limit = null;
    private $echoareaFilter = null;
    private $messageIdFilter = null;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->processor = new BinkdProcessor();
    }

    public function run($options = [])
    {
        $this->parseOptions($options);
        
        // Show help if no options provided
        if (empty($options) || in_array('--help', $options)) {
            $this->showHelp();
            return;
        }
        
        echo "=== CHRS Kludge Migration Tool ===\n";
        echo "Mode: " . ($this->dryRun ? "DRY RUN (no changes)" : "LIVE UPDATE") . "\n";
        if ($this->messageIdFilter) {
            echo "Target: message ID {$this->messageIdFilter}\n";
        } else {
            if ($this->limit) echo "Limit: {$this->limit} messages\n";
            if ($this->echoareaFilter) echo "Filter: echoarea '{$this->echoareaFilter}'\n";
        }
        echo "\n";

        // Find messages that need migration
        $messages = $this->findMessagesWithChrs();
        
        if (empty($messages)) {
            echo "No messages found with CHRS kludges that need migration.\n";
            return;
        }

        echo "Found " . count($messages) . " messages with CHRS kludges.\n\n";

        $updated = 0;
        $errors = 0;
        
        foreach ($messages as $message) {
            try {
                if ($this->migrateMessage($message)) {
                    $updated++;
                }
            } catch (Exception $e) {
                $errors++;
                echo "ERROR processing message ID {$message['id']}: " . $e->getMessage() . "\n";
            }
        }

        echo "\n=== Migration Summary ===\n";
        echo "Messages processed: " . count($messages) . "\n";
        echo "Successfully updated: $updated\n";
        echo "Errors: $errors\n";
        
        if ($this->dryRun) {
            echo "\nNOTE: This was a dry run. Run without --dry-run to apply changes.\n";
        }
    }

    private function showHelp()
    {
        echo "CHRS Kludge Migration Tool for Existing Echomail Messages\n";
        echo "=========================================================\n\n";
        echo "This tool finds existing echomail messages that contain CHRS kludge lines\n";
        echo "and re-processes them with the correct character encoding to fix display issues.\n\n";
        echo "Usage: php tools/migrate_chrs_echomail.php [options]\n\n";
        echo "Options:\n";
        echo "  --dry-run       Show what would be updated without making changes\n";
        echo "  --verbose       Show detailed processing information\n";
        echo "  --limit=N       Process only N messages (default: all)\n";
        echo "  --echoarea=TAG  Process only messages from specific echoarea\n";
        echo "  --message-id=ID Process only specific message ID\n";
        echo "  --help          Show this help message\n\n";
        echo "Examples:\n";
        echo "  # Preview what messages need migration\n";
        echo "  php tools/migrate_chrs_echomail.php --dry-run --verbose\n\n";
        echo "  # Process a specific message by ID\n";
        echo "  php tools/migrate_chrs_echomail.php --message-id=123 --dry-run\n\n";
        echo "  # Process only SYNCDATA echoarea messages\n";
        echo "  php tools/migrate_chrs_echomail.php --echoarea=SYNCDATA --dry-run\n\n";
        echo "  # Actually migrate the first 10 messages (remove --dry-run)\n";
        echo "  php tools/migrate_chrs_echomail.php --limit=10 --verbose\n\n";
        echo "Safety: Always run with --dry-run first to preview changes!\n";
    }

    private function parseOptions($options)
    {
        foreach ($options as $option) {
            if ($option === '--dry-run') {
                $this->dryRun = true;
            } elseif ($option === '--verbose') {
                $this->verbose = true;
            } elseif (strpos($option, '--limit=') === 0) {
                $this->limit = (int)substr($option, 8);
            } elseif (strpos($option, '--echoarea=') === 0) {
                $this->echoareaFilter = substr($option, 11);
            } elseif (strpos($option, '--message-id=') === 0) {
                $this->messageIdFilter = (int)substr($option, 13);
            }
        }
    }

    private function findMessagesWithChrs()
    {
        $sql = "
            SELECT e.id, e.subject, e.message_text, e.kludge_lines, e.from_name, e.to_name,
                   ea.tag as echoarea_tag
            FROM echomail e
            JOIN echoareas ea ON e.echoarea_id = ea.id
            WHERE e.kludge_lines LIKE '%CHRS:%'
        ";
        
        $params = [];
        
        if ($this->messageIdFilter) {
            $sql .= " AND e.id = ?";
            $params[] = $this->messageIdFilter;
        } else {
            if ($this->echoareaFilter) {
                $sql .= " AND ea.tag = ?";
                $params[] = $this->echoareaFilter;
            }
            
            $sql .= " ORDER BY e.id";
            
            if ($this->limit) {
                $sql .= " LIMIT ?";
                $params[] = $this->limit;
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function migrateMessage($message)
    {
        if ($this->verbose || $this->messageIdFilter) {
            echo "Processing message ID {$message['id']} in {$message['echoarea_tag']}\n";
            echo "  Subject: {$message['subject']}\n";
            echo "  From: {$message['from_name']}\n";
            echo "  To: {$message['to_name']}\n";
            if ($this->messageIdFilter) {
                echo "  Kludge lines preview: " . substr(str_replace(["\r", "\n"], ' ', $message['kludge_lines']), 0, 100) . "...\n";
            }
        }

        // Extract CHRS encoding from stored kludge lines
        $detectedEncoding = $this->extractChrsFromKludgeLines($message['kludge_lines']);
        
        if (!$detectedEncoding) {
            if ($this->verbose) {
                echo "  No valid CHRS encoding found, skipping.\n";
            }
            return false;
        }

        if ($this->verbose) {
            echo "  Detected encoding: $detectedEncoding\n";
        }

        // Get the original raw message data (we'll need to simulate this)
        // Since we don't have the original raw bytes, we'll try to reverse-convert
        // from UTF-8 back to the original encoding, then re-convert properly
        
        $originalSubject = $this->attemptReverseConversion($message['subject']);
        $originalMessageText = $this->attemptReverseConversion($message['message_text']);
        $originalFromName = $this->attemptReverseConversion($message['from_name']);
        $originalToName = $this->attemptReverseConversion($message['to_name']);

        // Re-convert with the correct encoding
        $newSubject = $this->convertWithEncoding($originalSubject, $detectedEncoding);
        $newMessageText = $this->convertWithEncoding($originalMessageText, $detectedEncoding);
        $newFromName = $this->convertWithEncoding($originalFromName, $detectedEncoding);
        $newToName = $this->convertWithEncoding($originalToName, $detectedEncoding);

        // Check if any changes were made
        $hasChanges = ($newSubject !== $message['subject']) ||
                      ($newMessageText !== $message['message_text']) ||
                      ($newFromName !== $message['from_name']) ||
                      ($newToName !== $message['to_name']);

        if (!$hasChanges) {
            if ($this->verbose) {
                echo "  No changes needed (already correctly encoded).\n";
            }
            return false;
        }

        if ($this->verbose) {
            echo "  Changes detected:\n";
            if ($newSubject !== $message['subject']) {
                echo "    Subject: '{$message['subject']}' -> '$newSubject'\n";
            }
            if ($newFromName !== $message['from_name']) {
                echo "    From: '{$message['from_name']}' -> '$newFromName'\n";
            }
            if ($newToName !== $message['to_name']) {
                echo "    To: '{$message['to_name']}' -> '$newToName'\n";
            }
            if ($newMessageText !== $message['message_text']) {
                $oldLen = strlen($message['message_text']);
                $newLen = strlen($newMessageText);
                echo "    Message text: $oldLen bytes -> $newLen bytes\n";
            }
        }

        if (!$this->dryRun) {
            $this->updateMessage($message['id'], $newSubject, $newMessageText, $newFromName, $newToName);
        }

        echo "  ✓ Updated message ID {$message['id']} with $detectedEncoding encoding\n";
        return true;
    }

    private function extractChrsFromKludgeLines($kludgeLines)
    {
        if (empty($kludgeLines)) {
            return null;
        }

        // Use the same extraction logic as BinkdProcessor
        $reflection = new ReflectionClass($this->processor);
        $method = $reflection->getMethod('extractChrsKludge');
        $method->setAccessible(true);
        
        return $method->invokeArgs($this->processor, [$kludgeLines]);
    }

    private function attemptReverseConversion($utf8String)
    {
        // This is tricky - we're trying to reverse a potentially lossy conversion
        // Best effort: try to convert back to common encodings and see what makes sense
        
        if (!mb_check_encoding($utf8String, 'UTF-8')) {
            // String might not be properly UTF-8, return as-is
            return $utf8String;
        }

        // For now, return the string as-is and rely on the new encoding detection
        // In a real-world scenario, you might want to store original raw bytes
        return $utf8String;
    }

    private function convertWithEncoding($string, $encoding)
    {
        // Use the same conversion logic as BinkdProcessor
        $reflection = new ReflectionClass($this->processor);
        $method = $reflection->getMethod('convertToUtf8');
        $method->setAccessible(true);
        
        return $method->invokeArgs($this->processor, [$string, $encoding]);
    }

    private function updateMessage($id, $subject, $messageText, $fromName, $toName)
    {
        $sql = "
            UPDATE echomail 
            SET subject = ?, message_text = ?, from_name = ?, to_name = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$subject, $messageText, $fromName, $toName, $id]);
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $options = array_slice($argv, 1); // Remove script name
    
    try {
        $migrator = new ChrsEchomailMigrator();
        $migrator->run($options);
    } catch (Exception $e) {
        echo "FATAL ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "This tool must be run from the command line.\n";
    exit(1);
}