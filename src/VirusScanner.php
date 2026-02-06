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

/**
 * VirusScanner - ClamAV virus scanning using clamdscan
 *
 * Requires: clamav-daemon and clamdscan packages
 * Debian/Ubuntu: apt-get install clamav-daemon clamdscan
 */
class VirusScanner
{
    private ?string $clamdscanPath;
    private bool $enabled;

    public function __construct()
    {
        // Check if clamdscan is available
        $this->clamdscanPath = $this->findClamdscan();
        $this->enabled = !empty($this->clamdscanPath) && $this->isClamdRunning();

        if (!$this->enabled) {
            error_log("VirusScanner: clamdscan not available or clamd not running - virus scanning disabled");
        }
    }

    /**
     * Check if virus scanning is available
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Scan a file for viruses
     *
     * @param string $filePath Path to file to scan
     * @return array Scan result with keys: scanned, result, signature, error
     */
    public function scanFile(string $filePath): array
    {
        if (!$this->enabled) {
            return [
                'scanned' => false,
                'result' => 'skipped',
                'signature' => null,
                'error' => 'Virus scanning not available'
            ];
        }

        if (!file_exists($filePath)) {
            return [
                'scanned' => false,
                'result' => 'error',
                'signature' => null,
                'error' => 'File not found'
            ];
        }

        // Run clamdscan on the file
        // --fdpass passes file descriptors to clamd instead of paths (works around permission issues)
        // --no-summary suppresses statistics output
        $command = escapeshellarg($this->clamdscanPath) . ' --fdpass --no-summary ' . escapeshellarg($filePath);
        $output = [];
        $returnCode = 0;

        exec($command . ' 2>&1', $output, $returnCode);

        $outputStr = implode("\n", $output);

        // Parse clamdscan return codes:
        // 0 = No virus found
        // 1 = Virus found
        // 2 = Error
        if ($returnCode === 0) {
            return [
                'scanned' => true,
                'result' => 'clean',
                'signature' => null,
                'error' => null
            ];
        } elseif ($returnCode === 1) {
            // Extract virus signature from output
            // Format: "filename: Virus.Name FOUND"
            $signature = $this->extractSignature($outputStr);

            return [
                'scanned' => true,
                'result' => 'infected',
                'signature' => $signature,
                'error' => null
            ];
        } else {
            return [
                'scanned' => true,
                'result' => 'error',
                'signature' => null,
                'error' => 'Scan error: ' . trim($outputStr)
            ];
        }
    }

    /**
     * Find clamdscan binary
     *
     * @return string|null Path to clamdscan or null if not found
     */
    private function findClamdscan(): ?string
    {
        // Check environment variable first
        $envPath = Config::env('CLAMDSCAN');
        if (PHP_OS_FAMILY === 'Windows') {                  // is_executable is bogus on Windows
            if (!empty($envPath) && file_exists($envPath)) {
                return $envPath;
            }
        } else {
            if (!empty($envPath) && file_exists($envPath) && is_executable($envPath)) {
                return $envPath;
            }
        }

        // Fall back to common locations
        $paths = [
            '/usr/bin/clamdscan',
            '/usr/local/bin/clamdscan',
            '/opt/clamav/bin/clamdscan'
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try which command as last resort
        $which = trim((string)shell_exec('which clamdscan 2>/dev/null'));
        if (!empty($which) && file_exists($which)) {
            return $which;
        }

        return null;
    }

    /**
     * Check if clamd daemon is running
     *
     * @return bool
     */
    private function isClamdRunning(): bool
    {
        if (empty($this->clamdscanPath)) {
            return false;
        }

        // Try to ping clamd
        $command = escapeshellarg($this->clamdscanPath) . ' --ping 1';
        $output = trim((string)shell_exec($command));

        // clamdscan --ping should return "PONG" if clamd is running
        return stripos($output, 'PONG') !== false;
    }

    /**
     * Extract virus signature from clamdscan output
     *
     * @param string $output Scanner output
     * @return string|null Virus signature name
     */
    private function extractSignature(string $output): ?string
    {
        // clamdscan output format: "/path/to/file: Virus.Name FOUND"
        if (preg_match('/:\s*(.+?)\s+FOUND/i', $output, $matches)) {
            return trim($matches[1]);
        }

        return 'Unknown';
    }

    /**
     * Get scanner version information
     *
     * @return array Version info with keys: scanner, version, database
     */
    public function getVersion(): array
    {
        if (!$this->enabled) {
            return [
                'scanner' => 'clamdscan',
                'version' => null,
                'database' => null,
                'available' => false
            ];
        }

        $command = escapeshellarg($this->clamdscanPath) . ' --version 2>&1';
        $output = trim((string)shell_exec($command));

        return [
            'scanner' => 'clamdscan',
            'version' => $output,
            'database' => null,
            'available' => true
        ];
    }
}

