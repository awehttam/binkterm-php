<?php

namespace BinktermPHP;

use BinktermPHP\Binkp\Config\BinkpConfig;

/**
 * TicFileGenerator - Creates TIC files for outbound file distribution
 *
 * Generates TIC (File Information Control) files for distributing files
 * to FidoNet uplinks via file echo areas.
 */
class TicFileGenerator
{
    private BinkpConfig $config;
    private string $outboundPath;

    public function __construct()
    {
        $this->config = BinkpConfig::getInstance();
        $this->outboundPath = $this->config->getOutboundPath();
    }

    /**
     * Create TIC files for a file to be distributed to uplinks
     *
     * @param array $file File record from database
     * @param array $fileArea File area record from database
     * @return array Array of created TIC file paths
     */
    public function createTicFilesForUplinks(array $file, array $fileArea): array
    {
        // Don't create TIC files for local-only areas
        if (!empty($fileArea['is_local'])) {
            return [];
        }

        // Don't create TIC files for private areas
        if (!empty($fileArea['is_private'])) {
            return [];
        }

        $domain = $fileArea['domain'] ?? 'fidonet';
        $uplinks = $this->getUplinksForDomain($domain);

        if (empty($uplinks)) {
            error_log("No uplinks found for domain: {$domain}");
            return [];
        }

        $createdTics = [];

        foreach ($uplinks as $uplink) {
            try {
                $ticPath = $this->createTicFile($file, $fileArea, $uplink);
                if ($ticPath) {
                    $createdTics[] = $ticPath;
                }
            } catch (\Exception $e) {
                error_log("Failed to create TIC for uplink {$uplink['address']}: " . $e->getMessage());
            }
        }

        return $createdTics;
    }

    /**
     * Create a single TIC file for an uplink
     *
     * @param array $file File record
     * @param array $fileArea File area record
     * @param array $uplink Uplink configuration
     * @return string|null Path to created TIC file or null on failure
     */
    private function createTicFile(array $file, array $fileArea, array $uplink): ?string
    {
        $uplinkAddress = $uplink['address'];
        $filename = $file['filename'];
        $storagePath = $file['storage_path'];

        if (!file_exists($storagePath)) {
            throw new \Exception("Source file not found: {$storagePath}");
        }

        // Create outbound directory if needed
        if (!is_dir($this->outboundPath)) {
            mkdir($this->outboundPath, 0755, true);
        }

        // Copy data file with original filename
        $outboundDataPath = $this->outboundPath . '/' . $filename;

        // Handle collisions if file already exists
        if (file_exists($outboundDataPath)) {
            // If exact same file (by hash), skip copying
            if (file_exists($outboundDataPath) && hash_file('sha256', $outboundDataPath) === $file['file_hash']) {
                // Same file already in outbound, just create a new TIC for it
            } else {
                // Different file with same name - version it
                $counter = 1;
                $pathInfo = pathinfo($filename);
                while (file_exists($outboundDataPath)) {
                    $versionedName = $pathInfo['filename'] . '_' . $counter;
                    if (isset($pathInfo['extension'])) {
                        $versionedName .= '.' . $pathInfo['extension'];
                    }
                    $outboundDataPath = $this->outboundPath . '/' . $versionedName;
                    $filename = $versionedName;
                    $counter++;
                }

                if (!copy($storagePath, $outboundDataPath)) {
                    throw new \Exception("Failed to copy file to outbound: {$outboundDataPath}");
                }
            }
        } else {
            if (!copy($storagePath, $outboundDataPath)) {
                throw new \Exception("Failed to copy file to outbound: {$outboundDataPath}");
            }
        }

        // Generate randomized TIC filename to prevent collisions
        // Multiple TIC files can reference the same data file
        $ticFilename = bin2hex(random_bytes(4)) . '.tic';
        $ticPath = $this->outboundPath . '/' . $ticFilename;

        // Ensure TIC filename is unique
        while (file_exists($ticPath)) {
            $ticFilename = bin2hex(random_bytes(4)) . '.tic';
            $ticPath = $this->outboundPath . '/' . $ticFilename;
        }

        // Get our address for this uplink (use the "me" field from uplink config)
        $myAddress = $uplink['me'] ?? $this->config->getSystemAddress();

        // Build TIC content
        $ticContent = $this->buildTicContent($file, $fileArea, $filename, $myAddress);

        // Write TIC file
        if (file_put_contents($ticPath, $ticContent) === false) {
            // Clean up data file if TIC creation failed
            unlink($outboundDataPath);
            throw new \Exception("Failed to write TIC file: {$ticPath}");
        }

        error_log("Created TIC file for {$uplinkAddress}: {$ticPath}");

        return $ticPath;
    }

    /**
     * Build TIC file content
     *
     * @param array $file File record
     * @param array $fileArea File area record
     * @param string $filename Original filename (what the file should be named when received)
     * @param string $fromAddress Our FidoNet address
     * @return string TIC file content
     */
    private function buildTicContent(array $file, array $fileArea, string $filename, string $fromAddress): string
    {
        $lines = [];

        // Area tag (required)
        $lines[] = 'Area ' . strtoupper($fileArea['tag']);

        // File name (required) - original filename, not the randomized outbound name
        $lines[] = 'File ' . $filename;

        // Short description
        if (!empty($file['short_description'])) {
            $lines[] = 'Desc ' . $file['short_description'];
        }

        // Long description (multiple LDesc lines)
        if (!empty($file['long_description'])) {
            $longDescLines = explode("\n", $file['long_description']);
            foreach ($longDescLines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[] = 'LDesc ' . $line;
                }
            }
        }

        // Origin address (required)
        $lines[] = 'From ' . $fromAddress;

        // CRC32 checksum (required)
        $crc = $this->calculateCrc32($file['storage_path']);
        $lines[] = 'Crc ' . strtoupper(dechex($crc));

        // Path line (shows routing path)
        $lines[] = 'Path ' . $fromAddress;

        // Created by
        $lines[] = 'Created BinktermPHP ' . \BinktermPHP\Version::getVersion();

        // Add final newline
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Calculate CRC32 checksum for a file
     *
     * @param string $filePath Path to file
     * @return int CRC32 checksum
     */
    private function calculateCrc32(string $filePath): int
    {
        $content = file_get_contents($filePath);
        return crc32($content);
    }

    /**
     * Get uplinks for a specific domain
     *
     * @param string $domain Domain name
     * @return array Array of uplink configurations
     */
    private function getUplinksForDomain(string $domain): array
    {
        return $this->config->getUplinksForDomain($domain);
    }
}
