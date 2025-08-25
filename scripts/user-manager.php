#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

class UserManager
{
    private $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }
    
    public function listUsers($showAdmin = false, $showInactive = false)
    {
        echo "BinktermPHP User List\n";
        echo "====================\n\n";
        
        try {
            $whereClause = "WHERE 1=1";
            $params = [];
            
            if (!$showAdmin) {
                $whereClause .= " AND is_admin = 0";
            }
            
            if (!$showInactive) {
                $whereClause .= " AND is_active = 1";
            }
            
            $stmt = $this->db->prepare("
                SELECT u.*, ur.name as role_name, us.timezone, us.messages_per_page, us.theme
                FROM users u 
                LEFT JOIN user_roles ur ON u.role_id = ur.id
                LEFT JOIN user_settings us ON u.id = us.user_id
                $whereClause
                ORDER BY u.username
            ");
            $stmt->execute($params);
            
            $users = $stmt->fetchAll();
            
            if (empty($users)) {
                echo "No users found.\n";
                return true;
            }
            
            echo sprintf("%-15s %-25s %-15s %-10s %-10s %-12s %-10s\n", 
                "USERNAME", "REAL NAME", "EMAIL", "ADMIN", "ACTIVE", "ROLE", "LAST LOGIN");
            echo str_repeat("-", 100) . "\n";
            
            foreach ($users as $user) {
                $lastLogin = $user['last_login'] ? 
                    date('Y-m-d', strtotime($user['last_login'])) : 'Never';
                    
                echo sprintf("%-15s %-25s %-15s %-10s %-10s %-12s %-10s\n",
                    substr($user['username'], 0, 14),
                    substr($user['real_name'] ?? '', 0, 24),
                    substr($user['email'] ?? '', 0, 14),
                    $user['is_admin'] ? 'Yes' : 'No',
                    $user['is_active'] ? 'Yes' : 'No',
                    substr($user['role_name'] ?? 'none', 0, 11),
                    $lastLogin
                );
            }
            
            echo "\nTotal users: " . count($users) . "\n";
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Failed to list users: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function showUser($username)
    {
        echo "User Details: $username\n";
        echo str_repeat("=", 25 + strlen($username)) . "\n\n";
        
        try {
            $stmt = $this->db->prepare("
                SELECT u.*, ur.name as role_name, ur.description as role_description,
                       us.timezone, us.messages_per_page, us.theme, us.show_origin, 
                       us.show_tearline, us.auto_refresh
                FROM users u 
                LEFT JOIN user_roles ur ON u.role_id = ur.id
                LEFT JOIN user_settings us ON u.id = us.user_id
                WHERE u.username = ?
            ");
            $stmt->execute([$username]);
            
            $user = $stmt->fetch();
            
            if (!$user) {
                echo "✗ User '$username' not found.\n";
                return false;
            }
            
            // Basic info
            echo "Basic Information:\n";
            echo "  ID: {$user['id']}\n";
            echo "  Username: {$user['username']}\n";
            echo "  Real Name: " . ($user['real_name'] ?? 'Not set') . "\n";
            echo "  Email: " . ($user['email'] ?? 'Not set') . "\n";
            echo "  Created: " . date('Y-m-d H:i:s', strtotime($user['created_at'])) . "\n";
            echo "  Last Login: " . ($user['last_login'] ? 
                date('Y-m-d H:i:s', strtotime($user['last_login'])) : 'Never') . "\n";
            echo "\n";
            
            // Status
            echo "Status:\n";
            echo "  Active: " . ($user['is_active'] ? 'Yes' : 'No') . "\n";
            echo "  Administrator: " . ($user['is_admin'] ? 'Yes' : 'No') . "\n";
            echo "  Role: " . ($user['role_name'] ?? 'None assigned') . "\n";
            if ($user['role_description']) {
                echo "  Role Description: {$user['role_description']}\n";
            }
            echo "\n";
            
            // Settings
            echo "Preferences:\n";
            echo "  Timezone: " . ($user['timezone'] ?? 'Default') . "\n";
            echo "  Messages per page: " . ($user['messages_per_page'] ?? '25') . "\n";
            echo "  Theme: " . ($user['theme'] ?? 'light') . "\n";
            echo "  Show origin: " . (isset($user['show_origin']) ? ($user['show_origin'] ? 'Yes' : 'No') : 'Default') . "\n";
            echo "  Show tearline: " . (isset($user['show_tearline']) ? ($user['show_tearline'] ? 'Yes' : 'No') : 'Default') . "\n";
            echo "  Auto refresh: " . (isset($user['auto_refresh']) ? ($user['auto_refresh'] ? 'Yes' : 'No') : 'Default') . "\n";
            echo "\n";
            
            // Message counts
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM netmail WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $netmailCount = $stmt->fetch()['count'];
            
            echo "Message Activity:\n";
            echo "  Netmail messages: $netmailCount\n";
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Failed to show user details: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function changePassword($username, $newPassword = null, $interactive = true)
    {
        echo "Change Password for: $username\n";
        echo str_repeat("=", 22 + strlen($username)) . "\n\n";
        
        try {
            // Check if user exists
            $stmt = $this->db->prepare("SELECT id, username, real_name FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                echo "✗ User '$username' not found.\n";
                return false;
            }
            
            echo "User: {$user['real_name']} ({$user['username']})\n\n";
            
            // Get new password
            if ($newPassword === null && $interactive) {
                $newPassword = $this->promptPassword("New password");
                $confirmPassword = $this->promptPassword("Confirm password");
                
                if ($newPassword !== $confirmPassword) {
                    echo "✗ Passwords do not match.\n";
                    return false;
                }
            } elseif ($newPassword === null) {
                echo "✗ Password must be provided in non-interactive mode.\n";
                return false;
            }
            
            // Validate password
            if (strlen($newPassword) < 6) {
                echo "✗ Password must be at least 6 characters long.\n";
                return false;
            }
            
            // Hash and update password
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $stmt = $this->db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$passwordHash, $user['id']]);
            
            echo "✓ Password changed successfully for user '{$user['username']}'.\n";
            
            if (!$interactive) {
                echo "⚠ Password was set via command line. Consider changing it through the web interface.\n";
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Failed to change password: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function createUser($username, $realName, $email = null, $password = null, $isAdmin = false, $interactive = true)
    {
        echo "Create New User: $username\n";
        echo str_repeat("=", 17 + strlen($username)) . "\n\n";
        
        try {
            // Check if user already exists
            $stmt = $this->db->prepare("SELECT username FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                echo "✗ User '$username' already exists.\n";
                return false;
            }
            
            // Get password if not provided
            if ($password === null && $interactive) {
                $password = $this->promptPassword("Password for $username");
                $confirmPassword = $this->promptPassword("Confirm password");
                
                if ($password !== $confirmPassword) {
                    echo "✗ Passwords do not match.\n";
                    return false;
                }
            } elseif ($password === null) {
                // Generate random password for non-interactive mode
                $password = bin2hex(random_bytes(8));
                echo "Generated password: $password\n";
            }
            
            // Validate password
            if (strlen($password) < 6) {
                echo "✗ Password must be at least 6 characters long.\n";
                return false;
            }
            
            // Create user
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO users (username, password_hash, real_name, email, is_admin, is_active) 
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$username, $passwordHash, $realName, $email, $isAdmin ? 1 : 0]);
            
            $userId = $this->db->lastInsertId();
            
            // Create default user settings
            $stmt = $this->db->prepare("
                INSERT INTO user_settings (user_id, timezone, messages_per_page, theme) 
                VALUES (?, 'America/Los_Angeles', 25, 'light')
            ");
            $stmt->execute([$userId]);
            
            // Assign role if role system exists
            $stmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_roles'");
            if ($stmt->fetch()) {
                $roleName = $isAdmin ? 'admin' : 'user';
                $stmt = $this->db->prepare("
                    UPDATE users SET role_id = (SELECT id FROM user_roles WHERE name = ?) 
                    WHERE id = ?
                ");
                $stmt->execute([$roleName, $userId]);
            }
            
            $this->db->commit();
            
            echo "✓ User created successfully:\n";
            echo "  Username: $username\n";
            echo "  Real Name: $realName\n";
            echo "  Email: " . ($email ?? 'Not set') . "\n";
            echo "  Admin: " . ($isAdmin ? 'Yes' : 'No') . "\n";
            echo "  User ID: $userId\n";
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            echo "✗ Failed to create user: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function deleteUser($username, $confirm = false)
    {
        echo "Delete User: $username\n";
        echo str_repeat("=", 13 + strlen($username)) . "\n\n";
        
        try {
            // Check if user exists
            $stmt = $this->db->prepare("SELECT id, username, real_name, is_admin FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                echo "✗ User '$username' not found.\n";
                return false;
            }
            
            echo "User: {$user['real_name']} ({$user['username']})\n";
            echo "Admin: " . ($user['is_admin'] ? 'Yes' : 'No') . "\n\n";
            
            if ($user['is_admin']) {
                echo "⚠ Warning: This is an administrator account!\n\n";
            }
            
            if (!$confirm) {
                echo "This will permanently delete the user and all associated data.\n";
                echo "Use --confirm flag to proceed with deletion.\n";
                return false;
            }
            
            $this->db->beginTransaction();
            
            // Delete user data
            $this->db->prepare("DELETE FROM user_settings WHERE user_id = ?")->execute([$user['id']]);
            $this->db->prepare("DELETE FROM message_read_status WHERE user_id = ?")->execute([$user['id']]);
            $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$user['id']]);
            $this->db->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$user['id']]);
            
            // Update netmail to remove user association but keep messages
            $this->db->prepare("UPDATE netmail SET user_id = NULL WHERE user_id = ?")->execute([$user['id']]);
            
            // Delete the user
            $this->db->prepare("DELETE FROM users WHERE id = ?")->execute([$user['id']]);
            
            $this->db->commit();
            
            echo "✓ User '$username' has been deleted successfully.\n";
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            echo "✗ Failed to delete user: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    public function toggleUserStatus($username, $active = null)
    {
        try {
            $stmt = $this->db->prepare("SELECT id, username, is_active FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                echo "✗ User '$username' not found.\n";
                return false;
            }
            
            $newStatus = ($active !== null) ? $active : !$user['is_active'];
            
            $stmt = $this->db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$newStatus ? 1 : 0, $user['id']]);
            
            $statusText = $newStatus ? 'activated' : 'deactivated';
            echo "✓ User '$username' has been $statusText.\n";
            
            return true;
            
        } catch (Exception $e) {
            echo "✗ Failed to update user status: " . $e->getMessage() . "\n";
            return false;
        }
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
}

function showUsage()
{
    echo "BinktermPHP User Management Tool\n";
    echo "Usage: php user-manager.php <command> [options]\n\n";
    
    echo "Commands:\n";
    echo "  list                    List all users\n";
    echo "  show <username>         Show detailed user information\n";
    echo "  passwd <username>       Change user password (interactive)\n";
    echo "  create <username>       Create new user (interactive)\n";
    echo "  delete <username>       Delete user (requires --confirm)\n";
    echo "  activate <username>     Activate user account\n";
    echo "  deactivate <username>   Deactivate user account\n\n";
    
    echo "Options:\n";
    echo "  --password=<pwd>        Set password (non-interactive)\n";
    echo "  --real-name=<name>      Set real name for new users\n";
    echo "  --email=<email>         Set email for new users\n";
    echo "  --admin                 Create user as administrator\n";
    echo "  --show-admin            Include admins in user list\n";
    echo "  --show-inactive         Include inactive users in list\n";
    echo "  --confirm               Confirm destructive operations\n";
    echo "  --non-interactive       Don't prompt for input\n";
    echo "  --help                  Show this help message\n\n";
    
    echo "Examples:\n";
    echo "  php user-manager.php list                              # List active non-admin users\n";
    echo "  php user-manager.php list --show-admin --show-inactive # List all users\n";
    echo "  php user-manager.php show admin                        # Show admin user details\n";
    echo "  php user-manager.php passwd john                       # Change john's password\n";
    echo "  php user-manager.php create alice --real-name=\"Alice Smith\" --email=alice@example.com\n";
    echo "  php user-manager.php create sysop --admin --password=secret123\n";
    echo "  php user-manager.php delete testuser --confirm         # Delete user\n";
    echo "  php user-manager.php deactivate spammer                # Deactivate user\n\n";
}

// Parse command line arguments
$command = '';
$username = '';
$options = [
    'password' => null,
    'real-name' => null,
    'email' => null,
    'admin' => false,
    'show-admin' => false,
    'show-inactive' => false,
    'confirm' => false,
    'non-interactive' => false,
    'help' => false
];

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    
    if ($arg === '--help' || $arg === '-h') {
        $options['help'] = true;
    } elseif (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];
        $value = isset($parts[1]) ? $parts[1] : true;
        
        if (array_key_exists($key, $options)) {
            $options[$key] = $value;
        } else {
            echo "Unknown option: --$key\n";
            exit(1);
        }
    } elseif (empty($command)) {
        $command = $arg;
    } elseif (empty($username)) {
        $username = $arg;
    } else {
        echo "Unexpected argument: $arg\n";
        exit(1);
    }
}

if ($options['help'] || empty($command)) {
    showUsage();
    exit(0);
}

// Create user manager and execute command
$manager = new UserManager();
$success = false;

$interactive = !$options['non-interactive'];

switch ($command) {
    case 'list':
        $success = $manager->listUsers($options['show-admin'], $options['show-inactive']);
        break;
        
    case 'show':
        if (empty($username)) {
            echo "Username required for show command.\n";
            exit(1);
        }
        $success = $manager->showUser($username);
        break;
        
    case 'passwd':
        if (empty($username)) {
            echo "Username required for passwd command.\n";
            exit(1);
        }
        $success = $manager->changePassword($username, $options['password'], $interactive);
        break;
        
    case 'create':
        if (empty($username)) {
            echo "Username required for create command.\n";
            exit(1);
        }
        $success = $manager->createUser(
            $username, 
            $options['real-name'] ?? $username, 
            $options['email'], 
            $options['password'], 
            $options['admin'], 
            $interactive
        );
        break;
        
    case 'delete':
        if (empty($username)) {
            echo "Username required for delete command.\n";
            exit(1);
        }
        $success = $manager->deleteUser($username, $options['confirm']);
        break;
        
    case 'activate':
        if (empty($username)) {
            echo "Username required for activate command.\n";
            exit(1);
        }
        $success = $manager->toggleUserStatus($username, true);
        break;
        
    case 'deactivate':
        if (empty($username)) {
            echo "Username required for deactivate command.\n";
            exit(1);
        }
        $success = $manager->toggleUserStatus($username, false);
        break;
        
    default:
        echo "Unknown command: $command\n";
        showUsage();
        exit(1);
}

exit($success ? 0 : 1);