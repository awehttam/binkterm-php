#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

class Installer
{
    private $db;
    private $interactive;
    
    public function __construct($interactive = true)
    {
        $this->interactive = $interactive;
    }
    
    public function run()
    {
        echo "BinktermPHP Installation Script\n";
        echo "==============================\n\n";
        
        try {
            // Initialize database connection
            $this->db = Database::getInstance()->getPdo();
            
            // Check if already installed
            if ($this->isAlreadyInstalled()) {
                echo "✗ Database appears to already be installed.\n";
                echo "  Use the upgrade script to migrate or manually delete the database file.\n";
                return false;
            }
            
            // Run installation steps
            $this->createTables();
            $this->runMigrations();
            $this->insertDefaultData();
            $this->createAdminUser();
            $this->fixDirectoryPermissions();

            echo "\n✓ Installation completed successfully!\n\n";
            $this->showPostInstallationInfo();
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Installation failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    private function isAlreadyInstalled()
    {
        try {
            // Check if users table exists (indicates proper installation)
            $stmt = $this->db->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'users')");
            $result = $stmt->fetch();
            
            if ($result['exists']) {
                // Check if there are any users
                $stmt = $this->db->query("SELECT COUNT(*) as count FROM users");
                $userCount = $stmt->fetch();
                return $userCount['count'] > 0;
            }
            
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function createTables()
    {
        echo "1. Creating database tables...\n";

        $schemaFile = __DIR__ . '/../database/postgresql_schema.sql';
        if (!file_exists($schemaFile)) {
            throw new Exception("PostgreSQL schema file not found: $schemaFile");
        }

        $schema = file_get_contents($schemaFile);

        // Remove SQL comments (lines starting with --)
        $schema = preg_replace('/^\s*--.*$/m', '', $schema);

        // Split schema by statements (semicolon followed by newline)
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*\n/', $schema)),
            function($stmt) { return !empty($stmt); }
        );

        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                try {
                    $this->db->exec($statement);
                } catch (Exception $e) {
                    // Skip statements that might fail (like ON CONFLICT DO NOTHING on existing data)
                    if (strpos($e->getMessage(), 'already exists') === false &&
                        strpos($e->getMessage(), 'duplicate key') === false) {
                        throw $e;
                    }
                }
            }
        }

        echo "   ✓ Database tables created successfully\n";
    }
    
    private function insertDefaultData()
    {
        echo "3. Inserting default data...\n";
        
        // Default echoareas are handled by schema
        echo "   ✓ Default echo areas created\n";
    }
    
    private function createAdminUser()
    {
        echo "4. Setting up administrator account...\n";
        
        if ($this->interactive) {
            $username = $this->promptInput("Admin username", "admin");
            $password = $this->promptPassword("Admin password");
            $realName = $this->promptInput("Admin real name", "System Administrator");
            $email = $this->promptInput("Admin email (optional)", "");
        } else {
            // Non-interactive defaults
            $username = "admin";
            $password = "admin123";
            $realName = "System Administrator"; 
            $email = "";
        }
        
        // Remove default admin user if it exists
        $stmt = $this->db->prepare("DELETE FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        // Create new admin user
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, real_name, email, is_admin, is_active)
            VALUES (?, ?, ?, ?, TRUE, TRUE)
        ");
        
        $stmt->execute([$username, $passwordHash, $realName, $email ?: null]);
        
        // Create default user settings
        $userId = $this->db->lastInsertId();
        $stmt = $this->db->prepare("
            INSERT INTO user_settings (user_id, timezone, messages_per_page, theme) 
            VALUES (?, 'America/Los_Angeles', 25, 'light')
        ");
        $stmt->execute([$userId]);
        
        echo "   ✓ Administrator account created: $username\n";
        
        if (!$this->interactive) {
            echo "   ⚠ Default password is: $password (CHANGE THIS IMMEDIATELY!)\n";
        }
    }
    
    private function promptInput($prompt, $default = "")
    {
        if ($default) {
            echo "$prompt [$default]: ";
        } else {
            echo "$prompt: ";
        }
        
        $input = trim(fgets(STDIN));
        return empty($input) ? $default : $input;
    }
    
    private function promptPassword($prompt = "Password")
    {
        echo "$prompt: ";
        
        // Hide password input on Unix-like systems
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            system('stty -echo');
            $password = trim(fgets(STDIN));
            system('stty echo');
            echo "\n";
        } else {
            $password = trim(fgets(STDIN));
        }
        
        if (empty($password)) {
            echo "Password cannot be empty. Please try again.\n";
            return $this->promptPassword($prompt);
        }
        
        return $password;
    }
    
    private function showPostInstallationInfo()
    {
        echo "Post-Installation Information:\n";
        echo "=============================\n";
        echo "• Database: PostgreSQL (configured in .env)\n";
        echo "• Web root: public_html/\n";
        echo "• Templates: templates/\n";
        echo "• Scripts: scripts/\n\n";
        
        echo "Next Steps:\n";
        echo "----------\n";
        echo "1. Configure your web server to serve files from public_html/\n";
        echo "2. Set up your BinkP configuration (if using Fidonet connectivity)\n";
        echo "3. Log in to the web interface with your admin account\n";
        echo "4. Configure system settings and echo areas\n\n";
        
        echo "Web Interface:\n";
        echo "-------------\n";
        echo "Point your web browser to the configured web server URL\n";
        echo "Log in with the administrator credentials you just created\n\n";
        
        echo "Command Line Tools:\n";
        echo "------------------\n";
        echo "• scripts/post_message.php  - Post netmail/echomail from command line\n";
        echo "• scripts/binkp_poll.php    - Poll uplinks for new mail\n";
        echo "• scripts/binkp_status.php  - Check BinkP system status\n";
        echo "• scripts/process_packets.php - Manually process mail packets\n\n";
    }

    private function runMigrations()
    {
        echo "2. Running database migrations...\n";

        passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/upgrade.php'), $exitCode);

        if ($exitCode !== 0) {
            throw new Exception("Database migration failed with exit code: $exitCode");
        }

        echo "   ✓ Migrations complete\n";
    }

    private function fixDirectoryPermissions()
    {
        // Only run on Unix-like systems
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }

        echo "5. Setting directory permissions...\n";

        $dirs = [
            "Outbound queue" => [__DIR__ . '/../data/outbound', 01777],
            'File Base' => [__DIR__ . '/../data/files', 02777],
        ];


        foreach ($dirs as $label=>$dirparams) {

            if (is_dir($dirparams[0])) {

                $result = chmod($dirparams[0], $dirparams[1]);
                if ($result) {
                    echo "   ✓ Set permissions on ".$dirparams[0]." (".$dirparams[1].")\n";
                } else {
                    echo "   ⚠ Could not set permissions on ".$dirparams[0]."\n";
                    echo "     Run manually: chmod ".$dirparams[1]." ".$dirparams[0]."\n";
                }
            } else {
                echo "   ⚠ ".$dirparams[0]." (".$label.") directory not found\n";
            }
        }
    }
}

function showUsage()
{
    echo "Usage: php install.php [options]\n\n";
    echo "Options:\n";
    echo "  --non-interactive  Run without prompting for input (uses defaults)\n";
    echo "  --help            Show this help message\n\n";
    echo "Interactive mode will prompt you for administrator credentials.\n";
    echo "Non-interactive mode creates admin/admin123 (change immediately!).\n\n";
}

// Parse command line arguments
$interactive = true;
$showHelp = false;

for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--non-interactive':
        case '-n':
            $interactive = false;
            break;
        case '--help':
        case '-h':
            $showHelp = true;
            break;
        default:
            echo "Unknown option: {$argv[$i]}\n";
            $showHelp = true;
            break;
    }
}

if ($showHelp) {
    showUsage();
    exit(0);
}

// Run installer
$installer = new Installer($interactive);
$success = $installer->run();

exit($success ? 0 : 1);