#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

class SetupManager
{
    public function run()
    {
        echo "BinktermPHP Setup Manager\n";
        echo "========================\n\n";

        try {
            // Check if database exists and is initialized
            if ($this->isDatabaseInitialized()) {
                echo "Database is already initialized.\n";
                echo "Running upgrade check...\n\n";
                $result = $this->runUpgrade();
            } else {
                echo "Database not found or not initialized.\n";
                echo "Running installation...\n\n";
                $result = $this->runInstall();
            }

            // Fix directory permissions
            $this->fixDirectoryPermissions();

            return $result;

        } catch (Exception $e) {
            echo "✗ Setup failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function fixDirectoryPermissions()
    {
        // Only run on Unix-like systems
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }

        echo "\nSetting directory permissions...\n";

        $outboundDir = __DIR__ . '/../data/outbound';

        if (is_dir($outboundDir)) {
            // chmod a+rwxt (1777) - world writable with sticky bit
            $result = chmod($outboundDir, 01777);
            if ($result) {
                echo "✓ Set permissions on data/outbound (a+rwxt)\n";
            } else {
                echo "⚠ Could not set permissions on data/outbound\n";
                echo "  Run manually: chmod a+rwxt data/outbound\n";
            }
        } else {
            echo "⚠ data/outbound directory not found\n";
        }
    }
    
    private function isDatabaseInitialized()
    {
        try {
            $db = Database::getInstance()->getPdo();
            
            // Check if users table exists and has data
            $stmt = $db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'users')");
            $result = $stmt->fetch();
            
            if (!$result['exists']) {
                return false;
            }
            
            $stmt = $db->query("SELECT COUNT(*) as count FROM users");
            $result = $stmt->fetch();
            
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function runInstall()
    {
        $installScript = __DIR__ . '/install.php';
        $command = "php \"$installScript\" --non-interactive";

        echo "Executing: $command\n";

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        echo implode("\n", $output) . "\n";

        if ($returnCode !== 0) {
            return false;
        }

        // After base schema install, run migrations to bring database up to date
        echo "\nApplying database migrations...\n";
        return $this->runUpgrade();
    }
    
    private function runUpgrade()
    {
        $upgradeScript = __DIR__ . '/upgrade.php';
        $command = "php \"$upgradeScript\"";
        
        echo "Executing: $command\n";
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        echo implode("\n", $output) . "\n";
        
        return $returnCode === 0;
    }
    
    public function status()
    {
        echo "BinktermPHP System Status\n";
        echo "========================\n\n";
        
        try {
            // Database status
            echo "Database:\n";
            echo "---------\n";
            
            if ($this->isDatabaseInitialized()) {
                $db = Database::getInstance()->getPdo();
                
                echo "✓ Database initialized\n";
                echo "  PostgreSQL connection established\n";
                
                // User count
                $stmt = $db->query("SELECT COUNT(*) as count FROM users");
                $userCount = $stmt->fetch()['count'];
                echo "  Users: $userCount\n";
                
                // Admin count
                $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE is_admin = TRUE");
                $adminCount = $stmt->fetch()['count'];
                echo "  Admins: $adminCount\n";
                
                // Echo areas count
                $stmt = $db->query("SELECT COUNT(*) as count FROM echoareas");
                $areaCount = $stmt->fetch()['count'];
                echo "  Echo areas: $areaCount\n";
                
                // Messages count
                $stmt = $db->query("SELECT COUNT(*) as count FROM netmail");
                $netmailCount = $stmt->fetch()['count'];
                $stmt = $db->query("SELECT COUNT(*) as count FROM echomail");
                $echomailCount = $stmt->fetch()['count'];
                echo "  Messages: $netmailCount netmail, $echomailCount echomail\n";
                
            } else {
                echo "✗ Database not initialized\n";
                echo "  Run: php scripts/setup.php\n";
            }
            
            echo "\n";
            
            // Web server status
            echo "Web Server:\n";
            echo "-----------\n";
            $webRoot = __DIR__ . '/../public_html';
            if (is_dir($webRoot) && file_exists($webRoot . '/index.php')) {
                echo "✓ Web root exists: $webRoot\n";
                
                // Check if web server is running (basic test)
                $ports = [80, 8080, 8000, 1244];
                $serverRunning = false;
                
                foreach ($ports as $port) {
                    $connection = @fsockopen('localhost', $port, $errno, $errstr, 1);
                    if ($connection) {
                        echo "✓ Web server detected on port $port\n";
                        fclose($connection);
                        $serverRunning = true;
                        break;
                    }
                }
                
                if (!$serverRunning) {
                    echo "⚠ No web server detected on common ports\n";
                    echo "  To start built-in server: php -S localhost:1244 -t public_html\n";
                }
                
            } else {
                echo "✗ Web root not found or incomplete\n";
            }
            
            echo "\n";
            
            // Migration status
            echo "Migrations:\n";
            echo "-----------\n";
            $upgradeScript = __DIR__ . '/upgrade.php';
            $command = "php \"$upgradeScript\" status";
            
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            
            // Extract just the migration info
            $inMigrations = false;
            foreach ($output as $line) {
                if (strpos($line, 'Current version:') === 0) {
                    echo "✓ $line\n";
                } elseif (strpos($line, 'Applied migrations:') === 0 || 
                         strpos($line, 'Pending migrations:') === 0) {
                    $inMigrations = true;
                    continue;
                } elseif ($inMigrations && (strpos($line, '  ') === 0 || trim($line) === 'None - database is up to date')) {
                    echo "$line\n";
                } elseif ($inMigrations && trim($line) === '') {
                    $inMigrations = false;
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Status check failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

function showUsage()
{
    echo "Usage: php setup.php [command]\n\n";
    echo "Commands:\n";
    echo "  setup     Auto-detect and run install or upgrade (default)\n";
    echo "  status    Show system status and configuration\n";
    echo "  --help    Show this help message\n\n";
    echo "The setup command will:\n";
    echo "- Run installation if database is not initialized\n";
    echo "- Run upgrade if database exists but needs migrations\n\n";
}

// Parse command line arguments
$command = 'setup';

if ($argc > 1) {
    $command = $argv[1];
}

// Handle help
if ($command === '--help' || $command === '-h') {
    showUsage();
    exit(0);
}

// Create setup manager instance
$manager = new SetupManager();
$success = false;

// Execute command
switch ($command) {
    case 'setup':
        $success = $manager->run();
        break;
        
    case 'status':
        $success = $manager->status();
        break;
        
    default:
        echo "Unknown command: $command\n";
        showUsage();
        exit(1);
}

exit($success ? 0 : 1);