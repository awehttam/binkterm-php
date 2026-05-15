#!/usr/bin/env php
<?php
/**
 * Restore a database backup from the backups/ directory.
 *
 * Usage:
 *   php scripts/restore_backup.php --list
 *   php scripts/restore_backup.php <backup_name_or_latest> [restore options...]
 *
 * Examples:
 *   php scripts/restore_backup.php latest --drop --create --force
 *   php scripts/restore_backup.php binktest_backup_2026-02-09_23-06-52.sql --clean
 *
 * Notes:
 * - This is a thin wrapper around scripts/restore_database.php
 * - Any extra arguments are passed through to restore_database.php
 */

$backupsDir = realpath(__DIR__ . '/../backups');
if ($backupsDir === false) {
    fwrite(STDERR, "Backups directory not found.\n");
    exit(1);
}

$args = $argv;
array_shift($args);

if (in_array('--list', $args, true) || in_array('-l', $args, true)) {
    $files = glob($backupsDir . DIRECTORY_SEPARATOR . '*');
    if (!$files) {
        echo "No backups found in {$backupsDir}\n";
        exit(0);
    }

    usort($files, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    echo "Backups in {$backupsDir}:\n";
    foreach ($files as $file) {
        if (is_file($file)) {
            $name = basename($file);
            $size = filesize($file);
            $time = date('Y-m-d H:i:s', filemtime($file));
            echo "  {$name}  (" . number_format($size) . " bytes, {$time})\n";
        }
    }
    exit(0);
}

if (count($args) === 0) {
    fwrite(STDERR, "Usage: php scripts/restore_backup.php <backup_name_or_latest> [restore options...]\n");
    fwrite(STDERR, "Run with --list to see available backups.\n");
    exit(1);
}

$selection = array_shift($args);
$backupPath = null;

if ($selection === 'latest') {
    $files = glob($backupsDir . DIRECTORY_SEPARATOR . '*');
    if (!$files) {
        fwrite(STDERR, "No backups found in {$backupsDir}\n");
        exit(1);
    }
    usort($files, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });
    $backupPath = $files[0];
} else {
    $candidate = $backupsDir . DIRECTORY_SEPARATOR . $selection;
    if (is_file($candidate)) {
        $backupPath = $candidate;
    } elseif (is_file($selection)) {
        $backupPath = $selection;
    }
}

if ($backupPath === null) {
    fwrite(STDERR, "Backup not found: {$selection}\n");
    fwrite(STDERR, "Run with --list to see available backups.\n");
    exit(1);
}

$restoreScript = __DIR__ . '/restore_database.php';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($restoreScript) . ' ' . escapeshellarg($backupPath);
foreach ($args as $arg) {
    $cmd .= ' ' . escapeshellarg($arg);
}

passthru($cmd, $exitCode);
exit($exitCode);
