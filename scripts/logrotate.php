#!/usr/bin/env php
<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */

// Log rotation utility for data/logs
// Usage: php scripts/logrotate.php [--keep=10] [--max-size=SIZE] [--logs-dir=PATH] [--dry-run]
//
//   --keep=N        Number of compressed generations to retain per log (default 10).
//   --max-size=SIZE Only rotate a *.log file at least this large. Accepts a plain
//                   byte count or a K/M/G suffix (e.g. 10M). Omit to rotate every
//                   log regardless of size (the historical behaviour). Use this
//                   when scheduling the script frequently so small logs are left
//                   alone and only a log that has actually grown is rolled.
//   --logs-dir=PATH Directory to rotate (default: <project>/data/logs). Mainly for
//                   tests; the compressed generations still go in PATH/old/.
//   --dry-run       Print the actions without touching any file.

/**
 * Parse a size string ("1048576", "10M", "512K", "1G") into bytes.
 * Returns null when the value is not a valid size.
 */
function parseSizeToBytes(string $value): ?int
{
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMGkmg]?)(?:[Bb])?\s*$/', $value, $m)) {
        return null;
    }
    $multipliers = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3];
    return (int) round((float) $m[1] * $multipliers[strtoupper($m[2])]);
}

$rootDir = dirname(__DIR__);
$logsDir = $rootDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'logs';

$options = getopt('', ['keep::', 'max-size::', 'logs-dir::', 'dry-run']);
$keep = 10;
if (isset($options['keep']) && is_numeric($options['keep'])) {
    $keep = max(1, (int)$options['keep']);
}
$dryRun = array_key_exists('dry-run', $options);

if (isset($options['logs-dir']) && $options['logs-dir'] !== '') {
    $logsDir = rtrim($options['logs-dir'], DIRECTORY_SEPARATOR);
}
$oldDir = $logsDir . DIRECTORY_SEPARATOR . 'old';

$maxSize = null;
if (isset($options['max-size']) && $options['max-size'] !== '') {
    $maxSize = parseSizeToBytes((string) $options['max-size']);
    if ($maxSize === null || $maxSize < 0) {
        fwrite(STDERR, "Invalid --max-size value: {$options['max-size']}\n");
        exit(1);
    }
}

if (!is_dir($logsDir)) {
    fwrite(STDERR, "Logs directory not found: $logsDir\n");
    exit(1);
}

if (!is_dir($oldDir)) {
    if ($dryRun) {
        echo "[dry-run] mkdir $oldDir\n";
    } else {
        if (!mkdir($oldDir, 0775, true)) {
            fwrite(STDERR, "Failed to create old logs directory: $oldDir\n");
            exit(1);
        }
    }
}

$logFiles = glob($logsDir . DIRECTORY_SEPARATOR . '*.log');
if ($logFiles === false) {
    fwrite(STDERR, "Failed to read log files in $logsDir\n");
    exit(1);
}

foreach ($logFiles as $logPath) {
    if (!is_file($logPath)) {
        continue;
    }

    $base = basename($logPath);
    $oldBase = $oldDir . DIRECTORY_SEPARATOR . $base;

    // Skip logs that have not grown past the threshold (when one is set), so the
    // script can be scheduled frequently without churning small logs into empty
    // generations.
    clearstatcache(true, $logPath);
    if ($maxSize !== null && filesize($logPath) < $maxSize) {
        continue;
    }

    // Remove any generations at or beyond the retention count (handles --keep
    // being lowered between runs, not just the single oldest slot).
    for ($i = $keep - 1; $i < $keep + 64; $i++) {
        $stale = $oldBase . '.' . $i . '.gz';
        if (!file_exists($stale)) {
            continue;
        }
        if ($dryRun) {
            echo "[dry-run] delete $stale\n";
        } else {
            unlink($stale);
        }
    }

    // Shift existing rotations up
    for ($i = $keep - 2; $i >= 0; $i--) {
        $src = $oldBase . '.' . $i . '.gz';
        $dst = $oldBase . '.' . ($i + 1) . '.gz';
        if (file_exists($src)) {
            if ($dryRun) {
                echo "[dry-run] move $src -> $dst\n";
            } else {
                rename($src, $dst);
            }
        }
    }

    // Copy + truncate current log to avoid issues with open handles
    $rotated = $oldBase . '.0';
    if ($dryRun) {
        echo "[dry-run] copy $logPath -> $rotated\n";
        echo "[dry-run] truncate $logPath\n";
    } else {
        if (!copy($logPath, $rotated)) {
            fwrite(STDERR, "Failed to copy $logPath to $rotated\n");
            continue;
        }
        // Truncate original log
        $fh = fopen($logPath, 'c+');
        if ($fh) {
            ftruncate($fh, 0);
            fclose($fh);
        }
    }

    // Compress rotated file
    $gzPath = $rotated . '.gz';
    if ($dryRun) {
        echo "[dry-run] gzip $rotated -> $gzPath\n";
        echo "[dry-run] delete $rotated\n";
    } else {
        $in = fopen($rotated, 'rb');
        if (!$in) {
            fwrite(STDERR, "Failed to open $rotated for reading\n");
            continue;
        }
        $out = gzopen($gzPath, 'wb9');
        if (!$out) {
            fclose($in);
            fwrite(STDERR, "Failed to open $gzPath for writing\n");
            continue;
        }
        while (!feof($in)) {
            $buffer = fread($in, 1024 * 1024);
            if ($buffer === false) {
                break;
            }
            gzwrite($out, $buffer);
        }
        fclose($in);
        gzclose($out);
        unlink($rotated);
    }

    echo ($dryRun ? "[dry-run] " : "") . "Rotated $base -> " . basename($gzPath) . "\n";
}

