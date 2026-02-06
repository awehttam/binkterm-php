#!/usr/bin/env php
<?php

/*
 * Database Maintenance Script
 *
 * Performs routine cleanup and maintenance on various database tables.
 * Run this script periodically (e.g., daily via cron) to keep the database clean.
 *
 * Usage: php scripts/database_maintenance.php [--verbose] [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

// Parse command line arguments
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);
$dryRun = in_array('--dry-run', $argv);

if ($dryRun) {
    echo "DRY RUN MODE - No changes will be made\n";
    echo str_repeat('=', 60) . "\n\n";
}

try {
    $db = Database::getInstance()->getPdo();

    echo "Database Maintenance Script\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n";
    echo str_repeat('=', 60) . "\n\n";

    $totalCleaned = 0;

    // ========================================================================
    // 1. Clean up old registration attempts (older than 30 days)
    // ========================================================================
    echo "[1] Cleaning old registration attempts...\n";

    // Check if table exists first
    $tableCheck = $db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_name = 'registration_attempts'
        )
    ");

    if ($tableCheck->fetchColumn()) {
        if ($dryRun) {
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM registration_attempts
                WHERE attempt_time < NOW() - INTERVAL '30 days'
            ");
            $result = $stmt->fetch();
            echo "    Would delete {$result['count']} registration attempts older than 30 days\n";
        } else {
            $stmt = $db->prepare("
                DELETE FROM registration_attempts
                WHERE attempt_time < NOW() - INTERVAL '30 days'
            ");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            $totalCleaned += $deleted;
            echo "    Deleted $deleted old registration attempts\n";
        }
    } else {
        echo "    Table 'registration_attempts' does not exist, skipping\n";
    }

    // ========================================================================
    // 2. Permanently delete netmail deleted by both sender and recipient
    // ========================================================================
    echo "\n[2] Cleaning soft-deleted netmail...\n";

    if ($dryRun) {
        $stmt = $db->query("
            SELECT COUNT(*) as count
            FROM netmail
            WHERE deleted_by_sender = TRUE AND deleted_by_recipient = TRUE
        ");
        $result = $stmt->fetch();
        echo "    Would permanently delete {$result['count']} netmail messages\n";
    } else {
        $stmt = $db->prepare("
            DELETE FROM netmail
            WHERE deleted_by_sender = TRUE AND deleted_by_recipient = TRUE
        ");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        $totalCleaned += $deleted;
        echo "    Permanently deleted $deleted netmail messages\n";
    }

    // ========================================================================
    // 3. Clean up expired password reset tokens (older than 24 hours)
    // ========================================================================
    echo "\n[3] Cleaning expired password reset tokens...\n";

    if ($dryRun) {
        $stmt = $db->query("
            SELECT COUNT(*) as count
            FROM password_reset_tokens
            WHERE created_at < NOW() - INTERVAL '24 hours'
        ");
        $result = $stmt->fetch();
        echo "    Would delete {$result['count']} expired password reset tokens\n";
    } else {
        $stmt = $db->prepare("
            DELETE FROM password_reset_tokens
            WHERE created_at < NOW() - INTERVAL '24 hours'
        ");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        $totalCleaned += $deleted;
        echo "    Deleted $deleted expired password reset tokens\n";
    }

    // ========================================================================
    // 4. Clean up expired gateway tokens
    // ========================================================================
    echo "\n[4] Cleaning expired gateway tokens...\n";

    // Check if table exists first
    $tableCheck = $db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_name = 'gateway_tokens'
        )
    ");

    if ($tableCheck->fetchColumn()) {
        if ($dryRun) {
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM gateway_tokens
                WHERE expires_at < NOW() OR used_at IS NOT NULL
            ");
            $result = $stmt->fetch();
            echo "    Would delete {$result['count']} expired/used gateway tokens\n";
        } else {
            $stmt = $db->prepare("
                DELETE FROM gateway_tokens
                WHERE expires_at < NOW() OR used_at IS NOT NULL
            ");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            $totalCleaned += $deleted;
            echo "    Deleted $deleted expired/used gateway tokens\n";
        }
    } else {
        echo "    Table 'gateway_tokens' does not exist, skipping\n";
    }

    // ========================================================================
    // 5. Clean up expired webshare links
    // ========================================================================
    echo "\n[5] Cleaning expired webshare links...\n";

    // Check if table exists first
    $tableCheck = $db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_name = 'webshare'
        )
    ");

    if ($tableCheck->fetchColumn()) {
        if ($dryRun) {
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM webshare
                WHERE expires_at IS NOT NULL AND expires_at < NOW()
            ");
            $result = $stmt->fetch();
            echo "    Would delete {$result['count']} expired webshare links\n";
        } else {
            $stmt = $db->prepare("
                DELETE FROM webshare
                WHERE expires_at IS NOT NULL AND expires_at < NOW()
            ");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            $totalCleaned += $deleted;
            echo "    Deleted $deleted expired webshare links\n";
        }
    } else {
        echo "    Table 'webshare' does not exist, skipping\n";
    }


    // ========================================================================
    // 6. Clean up old rejected pending users (older than 90 days)
    // ========================================================================
    if(0) { // TODO: Check if .env variable is set and use for number of days
        echo "\n[6] Cleaning old rejected pending users...\n";

        if ($dryRun) {
            $stmt = $db->query("
            SELECT COUNT(*) as count
            FROM pending_users
            WHERE status = 'rejected'
            AND requested_at < NOW() - INTERVAL '90 days'
        ");
            $result = $stmt->fetch();
            echo "    Would delete {$result['count']} old rejected pending users\n";
        } else {
            $stmt = $db->prepare("
            DELETE FROM pending_users
            WHERE status = 'rejected'
            AND requested_at < NOW() - INTERVAL '90 days'
        ");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            $totalCleaned += $deleted;
            echo "    Deleted $deleted old rejected pending users\n";
        }
    }
    // ========================================================================
    // 7. Clean up old login attempts (older than 30 days)
    // ========================================================================
    echo "\n[7] Cleaning old login attempts...\n";

    // Check if table exists first
    $tableCheck = $db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_name = 'login_attempts'
        )
    ");

    if ($tableCheck->fetchColumn()) {
        if ($dryRun) {
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM login_attempts
                WHERE attempt_time < NOW() - INTERVAL '30 days'
            ");
            $result = $stmt->fetch();
            echo "    Would delete {$result['count']} old login attempts\n";
        } else {
            $stmt = $db->prepare("
                DELETE FROM login_attempts
                WHERE attempt_time < NOW() - INTERVAL '30 days'
            ");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            $totalCleaned += $deleted;
            echo "    Deleted $deleted old login attempts\n";
        }
    } else {
        echo "    Table 'login_attempts' does not exist, skipping\n";
    }

    // ========================================================================
    // 8. PostgreSQL VACUUM and ANALYZE (if not dry run)
    // ========================================================================
    if (!$dryRun) {
        echo "\n[8] Running VACUUM and ANALYZE...\n";

        // Get list of tables to vacuum
        $tables = [
            'registration_attempts',
            'netmail',
            'password_reset_tokens',
            'gateway_tokens',
            'webshare',
            'pending_users',
            'echomail',
            'users'
        ];

        foreach ($tables as $table) {
            try {
                // Check if table exists
                $checkStmt = $db->prepare("
                    SELECT EXISTS (
                        SELECT FROM information_schema.tables
                        WHERE table_name = ?
                    )
                ");
                $checkStmt->execute([$table]);

                if ($checkStmt->fetchColumn()) {
                    $db->exec("VACUUM ANALYZE $table");
                    if ($verbose) {
                        echo "    Vacuumed and analyzed: $table\n";
                    }
                }
            } catch (Exception $e) {
                echo "    Warning: Could not vacuum $table: " . $e->getMessage() . "\n";
            }
        }

        if (!$verbose) {
            echo "    Database vacuum and analyze completed\n";
        }
    } else {
        echo "\n[8] Skipping VACUUM and ANALYZE (dry run)\n";
    }

    // ========================================================================
    // Summary
    // ========================================================================
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "Maintenance completed: " . date('Y-m-d H:i:s') . "\n";

    if ($dryRun) {
        echo "DRY RUN - No changes were made\n";
    } else {
        echo "Total records cleaned: $totalCleaned\n";
    }

    exit(0);

} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Maintenance failed!\n";
    exit(1);
}
