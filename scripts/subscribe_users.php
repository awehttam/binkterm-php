#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

/**
 * Tool to forcefully subscribe users to echo areas
 * Useful for admins to ensure all users are subscribed to important areas
 */
class SubscribeUsers
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Subscribe all users to a specific echo area
     */
    public function subscribeAllToArea($areaTag, $domain)
    {
        try {
            // Get the echoarea ID
            $stmt = $this->db->prepare("SELECT id, tag, description FROM echoareas WHERE tag = ? AND domain = ?");
            $stmt->execute([$areaTag, $domain]);
            $area = $stmt->fetch();

            if (!$area) {
                echo "✗ Echo area '{$areaTag}@{$domain}' not found.\n";
                return false;
            }

            echo "Subscribing all users to: {$area['tag']}@{$domain} - {$area['description']}\n\n";

            // Get all active users
            $stmt = $this->db->prepare("SELECT id, username, real_name FROM users WHERE is_active = TRUE ORDER BY username");
            $stmt->execute();
            $users = $stmt->fetchAll();

            if (empty($users)) {
                echo "✗ No active users found.\n";
                return false;
            }

            $subscribed = 0;
            $skipped = 0;

            foreach ($users as $user) {
                // Check if already subscribed
                $stmt = $this->db->prepare("
                    SELECT id FROM user_echoarea_subscriptions
                    WHERE user_id = ? AND echoarea_id = ?
                ");
                $stmt->execute([$user['id'], $area['id']]);

                if ($stmt->fetch()) {
                    echo "  ⊘ {$user['username']} - already subscribed\n";
                    $skipped++;
                    continue;
                }

                // Subscribe the user
                $stmt = $this->db->prepare("
                    INSERT INTO user_echoarea_subscriptions (user_id, echoarea_id, subscription_type)
                    VALUES (?, ?, 'admin')
                ");
                $stmt->execute([$user['id'], $area['id']]);

                echo "  ✓ {$user['username']} - subscribed\n";
                $subscribed++;
            }

            echo "\n";
            echo "Summary:\n";
            echo "  Subscribed: {$subscribed}\n";
            echo "  Already subscribed: {$skipped}\n";
            echo "  Total users: " . count($users) . "\n";

            return true;

        } catch (Exception $e) {
            echo "✗ Failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Subscribe a specific user to an echo area
     */
    public function subscribeUserToArea($username, $areaTag, $domain)
    {
        try {
            // Get user
            $stmt = $this->db->prepare("SELECT id, username FROM users WHERE username = ? AND is_active = TRUE");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                echo "✗ User '{$username}' not found or inactive.\n";
                return false;
            }

            // Get echoarea
            $stmt = $this->db->prepare("SELECT id, tag, description FROM echoareas WHERE tag = ? AND domain = ?");
            $stmt->execute([$areaTag, $domain]);
            $area = $stmt->fetch();

            if (!$area) {
                echo "✗ Echo area '{$areaTag}@{$domain}' not found.\n";
                return false;
            }

            // Check if already subscribed
            $stmt = $this->db->prepare("
                SELECT id FROM user_echoarea_subscriptions
                WHERE user_id = ? AND echoarea_id = ?
            ");
            $stmt->execute([$user['id'], $area['id']]);

            if ($stmt->fetch()) {
                echo "⊘ User '{$username}' is already subscribed to {$area['tag']}@{$domain}\n";
                return true;
            }

            // Subscribe
            $stmt = $this->db->prepare("
                INSERT INTO user_echoarea_subscriptions (user_id, echoarea_id, subscription_type)
                VALUES (?, ?, 'admin')
            ");
            $stmt->execute([$user['id'], $area['id']]);

            echo "✓ User '{$username}' subscribed to {$area['tag']}@{$domain} - {$area['description']}\n";
            return true;

        } catch (Exception $e) {
            echo "✗ Failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * List all echo areas
     */
    public function listAreas()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT tag, domain, description, is_default_subscription,
                       (SELECT COUNT(*) FROM user_echoarea_subscriptions WHERE echoarea_id = echoareas.id) as subscriber_count
                FROM echoareas
                ORDER BY domain, tag
            ");
            $stmt->execute();
            $areas = $stmt->fetchAll();

            if (empty($areas)) {
                echo "No echo areas found.\n";
                return true;
            }

            echo "\nAvailable Echo Areas:\n";
            echo "====================\n\n";
            echo sprintf("%-30s %-15s %-10s %s\n", "AREA", "DOMAIN", "SUBS", "DESCRIPTION");
            echo str_repeat("-", 100) . "\n";

            foreach ($areas as $area) {
                $default = $area['is_default_subscription'] ? ' [DEFAULT]' : '';
                echo sprintf("%-30s %-15s %-10s %s%s\n",
                    substr($area['tag'], 0, 29),
                    substr($area['domain'], 0, 14),
                    $area['subscriber_count'],
                    substr($area['description'] ?? '', 0, 40),
                    $default
                );
            }

            echo "\nTotal areas: " . count($areas) . "\n";
            return true;

        } catch (Exception $e) {
            echo "✗ Failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Show subscription stats for an area
     */
    public function showAreaStats($areaTag, $domain)
    {
        try {
            // Get echoarea
            $stmt = $this->db->prepare("SELECT id, tag, description FROM echoareas WHERE tag = ? AND domain = ?");
            $stmt->execute([$areaTag, $domain]);
            $area = $stmt->fetch();

            if (!$area) {
                echo "✗ Echo area '{$areaTag}@{$domain}' not found.\n";
                return false;
            }

            echo "\nArea: {$area['tag']}@{$domain}\n";
            echo "Description: {$area['description']}\n\n";

            // Get subscriber list
            $stmt = $this->db->prepare("
                SELECT u.username, u.real_name, s.subscription_type, s.subscribed_at
                FROM user_echoarea_subscriptions s
                JOIN users u ON s.user_id = u.id
                WHERE s.echoarea_id = ? AND s.is_active = TRUE
                ORDER BY u.username
            ");
            $stmt->execute([$area['id']]);
            $subs = $stmt->fetchAll();

            if (empty($subs)) {
                echo "No subscribers.\n";
                return true;
            }

            echo "Subscribers: " . count($subs) . "\n";
            echo str_repeat("-", 80) . "\n";
            echo sprintf("%-20s %-25s %-10s %s\n", "USERNAME", "REAL NAME", "TYPE", "SUBSCRIBED");
            echo str_repeat("-", 80) . "\n";

            foreach ($subs as $sub) {
                echo sprintf("%-20s %-25s %-10s %s\n",
                    substr($sub['username'], 0, 19),
                    substr($sub['real_name'] ?? '', 0, 24),
                    $sub['subscription_type'],
                    date('Y-m-d', strtotime($sub['subscribed_at']))
                );
            }

            return true;

        } catch (Exception $e) {
            echo "✗ Failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

function showUsage()
{
    echo "Subscribe Users Tool\n";
    echo "===================\n\n";
    echo "Usage:\n";
    echo "  php subscribe_users.php list\n";
    echo "    List all echo areas with subscriber counts\n\n";
    echo "  php subscribe_users.php stats <TAG@DOMAIN>\n";
    echo "    Show subscription stats for a specific area\n\n";
    echo "  php subscribe_users.php all <TAG@DOMAIN>\n";
    echo "    Subscribe all active users to an echo area\n\n";
    echo "  php subscribe_users.php user <USERNAME> <TAG@DOMAIN>\n";
    echo "    Subscribe a specific user to an echo area\n\n";
    echo "Examples:\n";
    echo "  php subscribe_users.php list\n";
    echo "  php subscribe_users.php stats GENERAL@fidonet\n";
    echo "  php subscribe_users.php all ANNOUNCE@lovlynet\n";
    echo "  php subscribe_users.php user john GENERAL@fidonet\n";
}

// Main execution
if ($argc < 2) {
    showUsage();
    exit(1);
}

$manager = new SubscribeUsers();
$command = $argv[1];

switch ($command) {
    case 'list':
        exit($manager->listAreas() ? 0 : 1);

    case 'stats':
        if ($argc < 3) {
            echo "✗ Usage: php subscribe_users.php stats <TAG@DOMAIN>\n";
            exit(1);
        }
        $parts = explode('@', $argv[2]);
        if (count($parts) !== 2) {
            echo "✗ Invalid format. Use: TAG@DOMAIN\n";
            exit(1);
        }
        exit($manager->showAreaStats($parts[0], $parts[1]) ? 0 : 1);

    case 'all':
        if ($argc < 3) {
            echo "✗ Usage: php subscribe_users.php all <TAG@DOMAIN>\n";
            exit(1);
        }
        $parts = explode('@', $argv[2]);
        if (count($parts) !== 2) {
            echo "✗ Invalid format. Use: TAG@DOMAIN\n";
            exit(1);
        }
        exit($manager->subscribeAllToArea($parts[0], $parts[1]) ? 0 : 1);

    case 'user':
        if ($argc < 4) {
            echo "✗ Usage: php subscribe_users.php user <USERNAME> <TAG@DOMAIN>\n";
            exit(1);
        }
        $username = $argv[2];
        $parts = explode('@', $argv[3]);
        if (count($parts) !== 2) {
            echo "✗ Invalid format. Use: TAG@DOMAIN\n";
            exit(1);
        }
        exit($manager->subscribeUserToArea($username, $parts[0], $parts[1]) ? 0 : 1);

    default:
        echo "✗ Unknown command: {$command}\n\n";
        showUsage();
        exit(1);
}
