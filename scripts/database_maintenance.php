#!/usr/bin/env php
<?php

/*
 * Database Maintenance Script
 *
 * Performs routine cleanup and maintenance on various database tables.
 * Run this script periodically (e.g., daily via cron) to keep the database clean.
 *
 * Usage: php scripts/database_maintenance.php [--verbose] [--dry-run] [-r|--registration-attempts]
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\Config;

// Parse command line arguments
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);
$dryRun = in_array('--dry-run', $argv);
$cleanRegistrationAttempts = in_array('--registration-attempts', $argv) || in_array('-r', $argv);

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
    $binkpSessionLogRetentionDays = max(1, (int)Config::env('BINKP_SESSION_LOG_RETENTION_DAYS', '30'));

    // ========================================================================
    // 1. Clean up old registration attempts (older than 30 days)
    // ========================================================================
    echo "[1] Cleaning old registration attempts...\n";

    if (!$cleanRegistrationAttempts) {
        echo "    Skipping by default; pass -r or --registration-attempts to enable\n";
    } else {
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
    // 5. Clean up expired shared message links
    // ========================================================================
    echo "\n[5] Cleaning expired shared message links...\n";

    // Check if table exists first
    $tableCheck = $db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_name = 'shared_messages'
        )
    ");

    if ($tableCheck->fetchColumn()) {
        if ($dryRun) {
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM shared_messages
                WHERE expires_at IS NOT NULL AND expires_at < NOW()
            ");
            $result = $stmt->fetch();
            echo "    Would delete {$result['count']} expired shared message links\n";
        } else {
            $stmt = $db->prepare("
                DELETE FROM shared_messages
                WHERE expires_at IS NOT NULL AND expires_at < NOW()
            ");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            $totalCleaned += $deleted;
            echo "    Deleted $deleted expired shared message links\n";
        }
    } else {
        echo "    Table 'shared_messages' does not exist, skipping\n";
    }

    // ========================================================================
    // 6. Clean up expired shared file links
    // ========================================================================
    echo "\n[6] Cleaning expired shared file links...\n";

    $tableCheck = $db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_name = 'shared_files'
        )
    ");

    if ($tableCheck->fetchColumn()) {
        if ($dryRun) {
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM shared_files
                WHERE expires_at IS NOT NULL AND expires_at < NOW()
            ");
            $result = $stmt->fetch();
            echo "    Would deactivate {$result['count']} expired shared file links\n";
        } else {
            $stmt = $db->prepare("
                UPDATE shared_files
                SET is_active = FALSE
                WHERE is_active = TRUE
                    AND expires_at IS NOT NULL
                    AND expires_at < NOW()
            ");
            $stmt->execute();
            $deactivated = $stmt->rowCount();
            $totalCleaned += $deactivated;
            echo "    Deactivated $deactivated expired shared file links\n";
        }
    } else {
        echo "    Table 'shared_files' does not exist, skipping\n";
    }


    // ========================================================================
    // 7. Clean up old rejected pending users (older than 90 days)
    // ========================================================================
    if(0) { // TODO: Check if .env variable is set and use for number of days
        echo "\n[7] Cleaning old rejected pending users...\n";

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
    // 8. Clean up old BinkP session log entries
    // ========================================================================
    echo "\n[8] Cleaning old BinkP session log entries...\n";

    $tableCheck = $db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_name = 'binkp_session_log'
        )
    ");

    if ($tableCheck->fetchColumn()) {
        if ($dryRun) {
            $stmt = $db->query("
                SELECT COUNT(*) as count
                FROM binkp_session_log
                WHERE started_at < NOW() - INTERVAL '{$binkpSessionLogRetentionDays} days'
            ");
            $result = $stmt->fetch();
            echo "    Would delete {$result['count']} BinkP session log entries older than {$binkpSessionLogRetentionDays} days\n";
        } else {
            $stmt = $db->prepare("
                DELETE FROM binkp_session_log
                WHERE started_at < NOW() - INTERVAL '{$binkpSessionLogRetentionDays} days'
            ");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            $totalCleaned += $deleted;
            echo "    Deleted $deleted BinkP session log entries older than {$binkpSessionLogRetentionDays} days\n";
        }
    } else {
        echo "    Table 'binkp_session_log' does not exist, skipping\n";
    }

    // ========================================================================
    // 9. PostgreSQL VACUUM and ANALYZE (if not dry run)
    // ========================================================================
    if (!$dryRun) {
        echo "\n[9] Running VACUUM and ANALYZE...\n";

        $tables = $db->query("
            SELECT relname AS table_name
            FROM pg_stat_user_tables
            ORDER BY relname
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            try {
                echo "    Vacuuming $table...\n";
                $db->exec("VACUUM ANALYZE $table");
                echo "    Done: $table\n";
            } catch (Exception $e) {
                echo "    Warning: Could not vacuum $table: " . $e->getMessage() . "\n";
            }
        }
    } else {
        echo "\n[9] Skipping VACUUM and ANALYZE (dry run)\n";
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
