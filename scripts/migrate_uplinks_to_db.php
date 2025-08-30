<?php
require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

function migrateUplinksToDatabase() {
    try {
        $db = Database::getInstance()->getPdo();
        
        // Read existing binkp.json
        $configPath = __DIR__ . '/../config/binkp.json';
        if (!file_exists($configPath)) {
            echo "No binkp.json config found at $configPath\n";
            return;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Error parsing binkp.json: " . json_last_error_msg() . "\n";
            return;
        }
        
        if (!isset($config['uplinks']) || !is_array($config['uplinks'])) {
            echo "No uplinks found in binkp.json\n";
            return;
        }
        
        // Get FidoNet network ID
        $stmt = $db->prepare("SELECT id FROM networks WHERE domain = 'fidonet'");
        $stmt->execute();
        $network = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$network) {
            echo "FidoNet network not found in database. Run the migration script first.\n";
            return;
        }
        
        $networkId = $network['id'];
        
        // Process each uplink
        foreach ($config['uplinks'] as $uplink) {
            // Extract domain from address if present, default to fidonet
            $address = $uplink['address'];
            $domain = 'fidonet'; // Default to fidonet for existing uplinks
            
            // Check if uplink already exists
            $checkStmt = $db->prepare("SELECT id FROM network_uplinks WHERE network_id = ? AND address = ?");
            $checkStmt->execute([$networkId, $address]);
            
            if ($checkStmt->fetch()) {
                echo "Uplink $address already exists, skipping\n";
                continue;
            }
            
            // Insert uplink
            $insertStmt = $db->prepare("
                INSERT INTO network_uplinks (
                    network_id, address, hostname, port, password, 
                    is_enabled, is_default, compression, crypt, poll_schedule
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $insertStmt->execute([
                $networkId,
                $address,
                $uplink['hostname'],
                $uplink['port'] ?? 24554,
                $uplink['password'] ?? null,
                $uplink['enabled'] ?? true,
                $uplink['default'] ?? false,
                $uplink['compression'] ?? false,
                $uplink['crypt'] ?? false,
                $uplink['poll_schedule'] ?? '0 */4 * * *'
            ]);
            
            echo "Migrated uplink: $address -> {$uplink['hostname']}:{$uplink['port']}\n";
        }
        
        echo "\nUplink migration completed successfully!\n";
        echo "You can now remove the 'uplinks' section from binkp.json if desired.\n";
        
    } catch (Exception $e) {
        echo "Error during migration: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
}

echo "Starting uplinks migration to database...\n";
migrateUplinksToDatabase();