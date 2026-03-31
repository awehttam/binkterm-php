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

namespace BinktermPHP\Antivirus;

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;

/**
 * ClamAV scanner backend using clamdscan.
 *
 * Requires the clamd daemon to be running and clamdscan to be installed.
 * Uses --fdpass to pass file descriptors rather than paths, avoiding
 * permission issues when PHP and clamd run as different users.
 *
 * .env configuration:
 *   CLAMDSCAN          Full path to clamdscan binary (auto-detected if unset)
 *   FILES_ALLOW_INFECTED  Set to 'true' to keep infected files (default: false)
 */
class ClamavScanner implements ScannerInterface
{
    private ?string $clamdscanPath;
    private bool $enabled;
    private Logger $logger;

    public function __construct()
    {
        $this->logger        = new Logger(Config::getLogPath('server.log'), Logger::LEVEL_INFO, false);
        $this->clamdscanPath = $this->findClamdscan();
        $this->enabled       = !empty($this->clamdscanPath) && $this->isClamdRunning();

        if (!$this->enabled) {
            $this->logger->warning('ClamavScanner: clamdscan not available or clamd not running — ClamAV scanning disabled');
        }
    }

    public function getName(): string
    {
        return 'clamav';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Scan a file using clamdscan.
     *
     * @param  string      $filePath
     * @param  string|null $sha256   Unused by ClamAV; accepted for interface compatibility
     * @return array
     */
    public function scanFile(string $filePath, ?string $sha256 = null): array
    {
        if (!$this->enabled) {
            return [
                'scanned'    => false,
                'result'     => 'skipped',
                'signature'  => null,
                'error_code' => 'errors.virus_scanner.not_available',
                'error'      => 'ClamAV not available',
            ];
        }

        if (!file_exists($filePath)) {
            return [
                'scanned'    => false,
                'result'     => 'error',
                'signature'  => null,
                'error_code' => 'errors.virus_scanner.file_not_found',
                'error'      => 'File not found for virus scan',
            ];
        }

        $command    = escapeshellarg($this->clamdscanPath) . ' --fdpass --no-summary ' . escapeshellarg($filePath);
        $output     = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);

        $outputStr = implode("\n", $output);

        // clamdscan exit codes: 0 = clean, 1 = infected, 2 = error
        if ($returnCode === 0) {
            return ['scanned' => true, 'result' => 'clean', 'signature' => null, 'error_code' => '', 'error' => null];
        }

        if ($returnCode === 1) {
            return [
                'scanned'    => true,
                'result'     => 'infected',
                'signature'  => $this->extractSignature($outputStr),
                'error_code' => '',
                'error'      => null,
            ];
        }

        return [
            'scanned'    => true,
            'result'     => 'error',
            'signature'  => null,
            'error_code' => 'errors.virus_scanner.scan_error',
            'error'      => 'clamdscan exited with code ' . $returnCode,
        ];
    }

    /**
     * Get ClamAV version information.
     *
     * @return array{scanner: string, version: ?string, available: bool}
     */
    public function getVersion(): array
    {
        if (!$this->enabled) {
            return ['scanner' => 'clamdscan', 'version' => null, 'available' => false];
        }

        $output = trim((string)shell_exec(escapeshellarg($this->clamdscanPath) . ' --version 2>&1'));
        return ['scanner' => 'clamdscan', 'version' => $output, 'available' => true];
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Locate the clamdscan binary.
     */
    private function findClamdscan(): ?string
    {
        $envPath = Config::env('CLAMDSCAN');

        if (PHP_OS_FAMILY === 'Windows') {
            if (!empty($envPath) && file_exists($envPath)) {
                return $envPath;
            }
        } else {
            if (!empty($envPath) && file_exists($envPath) && is_executable($envPath)) {
                return $envPath;
            }
        }

        foreach (['/usr/bin/clamdscan', '/usr/local/bin/clamdscan', '/opt/clamav/bin/clamdscan'] as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        $which = trim((string)shell_exec('which clamdscan 2>/dev/null'));
        return (!empty($which) && file_exists($which)) ? $which : null;
    }

    /**
     * Ping clamd to confirm the daemon is running.
     */
    private function isClamdRunning(): bool
    {
        if (empty($this->clamdscanPath)) {
            return false;
        }
        $output = trim((string)shell_exec(escapeshellarg($this->clamdscanPath) . ' --ping 1'));
        return stripos($output, 'PONG') !== false;
    }

    /**
     * Extract virus signature name from clamdscan output.
     * Format: "/path/to/file: Virus.Name FOUND"
     */
    private function extractSignature(string $output): string
    {
        if (preg_match('/:\s*(.+?)\s+FOUND/i', $output, $matches)) {
            return trim($matches[1]);
        }
        return 'Unknown';
    }
}
