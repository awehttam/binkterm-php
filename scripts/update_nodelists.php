#!/usr/bin/env php
<?php
/**
 * Nodelist download and import script for cron
 *
 * Usage: php update_nodelists.php [--quiet] [--force]
 *
 * Configure nodelist sources in config/nodelists.json or via environment variables.
 *
 * URL Macros:
 *   |DAY|    - Day of year (1-366)
 *   |YEAR|   - 4-digit year (2026)
 *   |YY|     - 2-digit year (26)
 *   |MONTH|  - 2-digit month (01-12)
 *   |DATE|   - 2-digit day of month (01-31)
 *
 * Example config/nodelists.json:
 * {
 *   "sources": [
 *     {
 *       "name": "FidoNet",
 *       "domain": "fidonet",
 *       "url": "https://darkrealms.ca/NODELIST.Z|DAY|",
 *       "enabled": true
 *     },
 *     {
 *       "name": "FSXNet",
 *       "domain": "fsxnet",
 *       "url": "https://bbs.nz/fsxnet/FSXNET.ZIP",
 *       "enabled": true
 *     }
 *   ]
 * }
 */

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';

class NodelistUpdater
{
    private $configFile;
    private $downloadDir;
    private $quiet;
    private $force;
    private $logFile;

    public function __construct($quiet = false, $force = false)
    {
        $this->quiet = $quiet;
        $this->force = $force;
        $this->configFile = __DIR__ . '/../config/nodelists.json';
        $this->downloadDir = __DIR__ . '/../data/nodelists';
        $this->logFile = __DIR__ . '/../data/logs/nodelist_update.log';

        if (!is_dir($this->downloadDir)) {
            mkdir($this->downloadDir, 0755, true);
        }
    }

    public function log($message, $level = 'INFO')
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n";

        // Always write to log file
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);

        // Only output to console if not quiet, or if it's an error
        if (!$this->quiet || $level === 'ERROR') {
            if ($level === 'ERROR') {
                fwrite(STDERR, $message . "\n");
            } else {
                echo $message . "\n";
            }
        }
    }

    /**
     * Expand URL macros with current date values
     *
     * Supported macros:
     *   |DAY|   - Day of year (1-366)
     *   |YEAR|  - 4-digit year (2026)
     *   |YY|    - 2-digit year (26)
     *   |MONTH| - 2-digit month (01-12)
     *   |DATE|  - 2-digit day of month (01-31)
     */
    public function expandUrlMacros($url)
    {
        $macros = [
            '|DAY|'   => date('z') + 1,  // day of year (0-365) + 1 = (1-366)
            '|YEAR|'  => date('Y'),       // 4-digit year
            '|YY|'    => date('y'),       // 2-digit year
            '|MONTH|' => date('m'),       // 2-digit month
            '|DATE|'  => date('d'),       // 2-digit day of month
        ];

        $expandedUrl = str_replace(array_keys($macros), array_values($macros), $url);

        if ($expandedUrl !== $url) {
            $this->log("URL expanded: {$url} -> {$expandedUrl}", 'DEBUG');
        }

        return $expandedUrl;
    }

    public function loadConfig()
    {
        if (!file_exists($this->configFile)) {
            $this->log("Config file not found: {$this->configFile}", 'ERROR');
            $this->log("Creating example config file...");
            $this->createExampleConfig();
            return null;
        }

        $config = json_decode(file_get_contents($this->configFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("Invalid JSON in config file: " . json_last_error_msg(), 'ERROR');
            return null;
        }

        return $config;
    }

    public function createExampleConfig()
    {
        $example = [
            'sources' => [
                [
                    'name' => 'FidoNet',
                    'domain' => 'fidonet',
                    'url' => 'https://darkrealms.ca/NODELIST.Z|DAY|',
                    'enabled' => false,
                    'comment' => 'Enable and verify URL before use'
                ],
                [
                    'name' => 'FSXNet',
                    'domain' => 'fsxnet',
                    'url' => 'https://github.com/fsxnet/nodelist/raw/refs/heads/master/FSXNET.Z|DAY|',
                    'enabled' => false,
                    'comment' => 'Enable and verify URL before use'
                ]
            ],
            'settings' => [
                'keep_downloads' => 3,
                'timeout' => 300,
                'user_agent' => 'BinktermPHP Nodelist Updater'
            ]
        ];

        $configDir = dirname($this->configFile);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        file_put_contents(
            $this->configFile,
            json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->log("Example config created at: {$this->configFile}");
        $this->log("Edit this file to configure your nodelist sources.");
    }

    public function download($url, $destFile, $settings = [])
    {
        $timeout = $settings['timeout'] ?? 300;
        $userAgent = $settings['user_agent'] ?? 'BinktermPHP Nodelist Updater';

        $this->log("Downloading: {$url}");

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => $userAgent,
                'follow_location' => true,
                'max_redirects' => 5
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $error = error_get_last();
            throw new Exception("Download failed: " . ($error['message'] ?? 'Unknown error'));
        }

        if (strlen($content) < 1000) {
            throw new Exception("Downloaded file too small (" . strlen($content) . " bytes), likely an error page");
        }

        file_put_contents($destFile, $content);
        $this->log("Downloaded " . number_format(strlen($content)) . " bytes to " . basename($destFile));

        return $destFile;
    }

    public function importNodelist($file, $domain)
    {
        $importScript = __DIR__ . '/import_nodelist.php';

        if (!file_exists($importScript)) {
            throw new Exception("Import script not found: {$importScript}");
        }

        $cmd = sprintf(
            'php %s %s %s --force 2>&1',
            escapeshellarg($importScript),
            escapeshellarg($file),
            escapeshellarg($domain)
        );

        $this->log("Running import: {$domain}");

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("Import failed with code {$returnCode}: " . implode("\n", $output));
        }

        // Log import output at DEBUG level
        foreach ($output as $line) {
            if (!empty(trim($line))) {
                $this->log("  " . $line, 'DEBUG');
            }
        }

        return true;
    }

    public function cleanupOldDownloads($keep = 3)
    {
        $files = glob($this->downloadDir . '/*');

        // Group files by base name (without date/number suffix)
        $groups = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                // Extract base name (e.g., "nodelist" from "nodelist_2024-01-15.zip")
                $basename = preg_replace('/[_-]\d{4}[-_]\d{2}[-_]\d{2}.*$/', '', basename($file));
                $basename = preg_replace('/\.\d{3}$/', '', $basename);
                $groups[$basename][] = $file;
            }
        }

        foreach ($groups as $basename => $groupFiles) {
            // Sort by modification time, newest first
            usort($groupFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            // Delete old files beyond the keep limit
            $toDelete = array_slice($groupFiles, $keep);
            foreach ($toDelete as $file) {
                unlink($file);
                $this->log("Cleaned up old download: " . basename($file), 'DEBUG');
            }
        }
    }

    public function run()
    {
        $this->log("=== Nodelist Update Started ===");

        $config = $this->loadConfig();
        if (!$config) {
            return 1;
        }

        $sources = $config['sources'] ?? [];
        $settings = $config['settings'] ?? [];

        if (empty($sources)) {
            $this->log("No nodelist sources configured", 'ERROR');
            return 1;
        }

        $enabledSources = array_filter($sources, fn($s) => $s['enabled'] ?? false);
        if (empty($enabledSources)) {
            $this->log("No enabled nodelist sources found", 'ERROR');
            return 1;
        }

        $errors = 0;
        $updated = 0;

        foreach ($enabledSources as $source) {
            $name = $source['name'] ?? 'Unknown';
            $domain = $source['domain'] ?? null;
            $url = $source['url'] ?? null;

            if (!$domain || !$url) {
                $this->log("Skipping {$name}: missing domain or url", 'WARNING');
                continue;
            }

            try {
                $this->log("Processing: {$name} ({$domain})");

                // Expand URL macros (e.g., |DAY| -> 023)
                $expandedUrl = $this->expandUrlMacros($url);

                // Generate download filename with date
                $ext = pathinfo(parse_url($expandedUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'zip';
                $downloadFile = $this->downloadDir . '/' . strtolower($domain) . '_' . date('Y-m-d') . '.' . $ext;

                // Download
                $this->download($expandedUrl, $downloadFile, $settings);

                // Import
                $this->importNodelist($downloadFile, $domain);

                $updated++;
                $this->log("Successfully updated: {$name}");

            } catch (Exception $e) {
                $errors++;
                $this->log("Failed to update {$name}: " . $e->getMessage(), 'ERROR');
            }
        }

        // Cleanup old downloads
        $keepDownloads = $settings['keep_downloads'] ?? 3;
        $this->cleanupOldDownloads($keepDownloads);

        $this->log("=== Nodelist Update Complete ===");
        $this->log("Updated: {$updated}, Errors: {$errors}");

        return $errors > 0 ? 1 : 0;
    }
}

// Parse arguments
$quiet = in_array('--quiet', $argv) || in_array('-q', $argv);
$force = in_array('--force', $argv) || in_array('-f', $argv);

if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo "Usage: php update_nodelists.php [options]\n";
    echo "\nOptions:\n";
    echo "  -q, --quiet   Suppress output except errors\n";
    echo "  -f, --force   Force update even if recently updated\n";
    echo "  -h, --help    Show this help message\n";
    echo "\nURL Macros:\n";
    echo "  |DAY|    Day of year (1-366)\n";
    echo "  |YEAR|   4-digit year (e.g., 2026)\n";
    echo "  |YY|     2-digit year (e.g., 26)\n";
    echo "  |MONTH|  2-digit month (01-12)\n";
    echo "  |DATE|   2-digit day of month (01-31)\n";
    echo "\n  Example: https://example.com/NODELIST.Z|DAY| -> NODELIST.Z23\n";
    echo "\nConfiguration:\n";
    echo "  Edit config/nodelists.json to configure nodelist sources.\n";
    echo "  Run without config to generate an example configuration.\n";
    echo "\nCron example (daily at 3am):\n";
    echo "  0 3 * * * cd /path/to/binkterm-php && php scripts/update_nodelists.php --quiet\n";
    exit(0);
}

$updater = new NodelistUpdater($quiet, $force);
exit($updater->run());
