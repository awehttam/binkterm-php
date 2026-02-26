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
        $ticContent = $this->buildTicContent($file, $fileArea, $filename, $myAddress, $uplinkAddress, $uplink);

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
     * Build TIC file content according to FSC-87 specification
     *
     * @param array $file File record
     * @param array $fileArea File area record
     * @param string $filename Original filename (what the file should be named when received)
     * @param string $fromAddress Our FidoNet address
     * @param string $toAddress Destination uplink address
     * @param array $uplink Uplink configuration
     * @return string TIC file content
     */
    private function buildTicContent(array $file, array $fileArea, string $filename, string $fromAddress, string $toAddress, array $uplink): string
    {
        $lines = [];

        // Area tag (required by FSC-87)
        $lines[] = 'Area ' . strtoupper($fileArea['tag']);

        // File name (required by FSC-87) - original filename, not the randomized outbound name
        $lines[] = 'File ' . $filename;

        // File size in bytes (required by FSC-87)
        $fileSize = filesize($file['storage_path']);
        $lines[] = 'Size ' . $fileSize;

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

        // Upload date/time (optional)
        if (!empty($file['uploaded_at'])) {
            $timestamp = strtotime($file['uploaded_at']);
            $lines[] = 'Date ' . $timestamp;
        }

        // Origin address (required by FSC-87) - where file originated
        $lines[] = 'Origin ' . $fromAddress;

        // From address (immediate sender, same as origin for files we create)
        $lines[] = 'From ' . $fromAddress;

        // To address (required by FSC-87) - destination
        $lines[] = 'To ' . $toAddress;

        // CRC32 checksum (required by FSC-87)
        $crc = $this->calculateCrc32($file['storage_path']);
        $lines[] = 'Crc ' . strtoupper(dechex($crc));

        // Path line (shows routing path)
        $lines[] = 'Path ' . $fromAddress;

        // Seenby (required by FSC-87) - at least our address
        $lines[] = 'Seenby ' . $fromAddress;

        // Password (required by FSC-87) - file area password, not session password
        // For files we originate, use the area's password if configured, otherwise empty
        // NOTE: This is the file echo area password, NOT the binkp session password
        $password = $fileArea['password'] ?? '';
        $lines[] = 'Pw ' . $password;

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

