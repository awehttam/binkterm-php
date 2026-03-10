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

use PDO;
use BinktermPHP\FileArea\FileAreaRuleProcessor;
use BinktermPHP\Binkp\Config\BinkpConfig;

/**
 * TicFileProcessor - Process incoming TIC files from Fidonet
 *
 * TIC (file catalog) files contain metadata about files being distributed
 * through Fidonet file areas. This class parses TIC files, validates the
 * associated data files, and stores them in the appropriate file area.
 */
class TicFileProcessor
{
    private PDO $db;
    private string $filesBasePath;
    private string $logFile;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->filesBasePath = __DIR__ . '/../data/files';

        // Set up logging to packets.log (same as BinkdProcessor)
        $logDir = __DIR__ . '/../data/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/packets.log';
    }

    /**
     * Log a message to the packets log file
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[$timestamp] [TIC] $message\n";
        @file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Process TIC file received via binkp
     *
     * @param string $ticPath Path to .TIC file
     * @param string $filePath Path to associated data file
     * @return array Result array with success status
     */
    public function processTicFile(string $ticPath, string $filePath): array
    {
        try {
            // Parse TIC file
            $ticData = $this->parseTicFile($ticPath);

            // Supplement missing descriptions from FILE_ID.DIZ inside the archive
            $this->enrichFromFileIdDiz($ticData, $filePath);

            // Determine domain for this TIC (based on From address)
            $domain = $this->getDomainFromTicData($ticData);

            // Check if we have this file area, auto-create if not
            $fileArea = $this->getFileArea($ticData['Area'], $domain);
            if (!$fileArea) {
                // Auto-create file area from TIC
                $fileAreaId = $this->autoCreateFileArea($ticData['Area'], $ticData, $domain);
                $fileArea = $this->getFileArea($ticData['Area'], $domain);

                if (!$fileArea) {
                    return [
                        'success' => false,
                        'error_code' => 'errors.tic.file_area_create_failed',
                        'error' => 'Failed to create file area from TIC metadata'
                    ];
                }

                $this->log("Auto-created file area: {$ticData['Area']} (id={$fileArea['id']})");
            }

            // Validate TIC password — area password takes precedence, uplink tic_password is the fallback
            $this->validateTicPassword($ticData, $fileArea, $ticData['From'] ?? '');

            // Validate file
            if (!$this->validateFile($ticData, $filePath)) {
                return [
                    'success' => false,
                    'error_code' => 'errors.tic.validation_failed',
                    'error' => 'TIC file validation failed'
                ];
            }

            // Calculate hash before any storage operations
            $contentHash = hash_file('sha256', $filePath);
            $fileHash = $contentHash;

            // Check for duplicates (same content in same area)
            $allowDuplicate = !empty($fileArea['allow_duplicate_hash']);
            if ($allowDuplicate) {
                $fileHash = $this->makeUniqueHash($fileArea['id'], $fileHash);
            } else {
                $existingFile = $this->checkDuplicate($fileArea['id'], $fileHash);

                if ($existingFile) {
                    // File already exists - skip it
                    $this->log("TIC file already exists: {$ticData['File']} (file_id={$existingFile['id']})");

                    // Clean up temp file since we're not storing it
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }

                    return [
                        'success' => true,
                        'file_id' => $existingFile['id'],
                        'area' => $ticData['Area'],
                        'filename' => $existingFile['filename'],
                        'duplicate' => true
                    ];
                }
            }

            // Store file (will copy, not move)
            $stored = $this->storeFile($ticData, $filePath, $fileArea, $contentHash, $fileHash);
            $fileId = $stored['id'];
            $storagePath = $stored['storage_path'];

            // Scan for viruses if enabled for this file area
            $scanResult = $this->scanFileForViruses($fileId, $fileArea);
            if (($scanResult['result'] ?? '') === 'infected' && \BinktermPHP\Config::env('CLAMAV_ALLOW_INFECTED', 'false') !== 'true') {
                return [
                    'success' => false,
                    'error_code' => 'errors.tic.virus_detected',
                    'error' => 'File rejected: virus detected'
                ];
            }

            // Update file area statistics
            $this->updateFileAreaStats($fileArea['id']);

            // Run file area automation rules (skip if infected)
            try {
                $ruleProcessor = new FileAreaRuleProcessor();
                $ruleResult = $ruleProcessor->processFile($storagePath, $fileArea['tag']);
                if (!empty($ruleResult['output'])) {
                    $this->log("File area rules output for {$ticData['File']}: " . $ruleResult['output']);
                }
            } catch (\Exception $e) {
                $this->log("File area rules error for {$ticData['File']}: " . $e->getMessage());
            }

            // Clean up temp file after successful storage
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Log successful TIC processing
            $this->log("TIC file processed successfully: {$ticData['File']} -> area {$ticData['Area']} (file_id={$fileId})");

            return [
                'success' => true,
                'file_id' => $fileId,
                'area' => $ticData['Area'],
                'filename' => $ticData['File'],
                'duplicate' => false
            ];

        } catch (\Exception $e) {
            $this->log("TIC processing error: " . $e->getMessage());
            return [
                'success' => false,
                'error_code' => 'errors.tic.processing_failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Parse TIC file content
     *
     * @param string $ticPath Path to TIC file
     * @return array Parsed TIC data
     * @throws \Exception If TIC file is invalid
     */

    /**
     * Supplement $ticData descriptions from FILE_ID.DIZ inside a ZIP archive.
     * Only fills fields that are absent or empty in the TIC data; explicit TIC
     * Desc/LDesc values always take precedence.
     *
     * @param array  $ticData   TIC data array, modified in place
     * @param string $filePath  Path to the data file accompanying the TIC
     */
    protected function enrichFromFileIdDiz(array &$ticData, string $filePath): void
    {
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'zip') {
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return;
        }

        // Locate FILE_ID.DIZ case-insensitively (may appear as file_id.diz, FILE_ID.DIZ, etc.)
        $dizContent = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strcasecmp(basename($name), 'FILE_ID.DIZ') === 0) {
                $dizContent = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();

        if ($dizContent === false || $dizContent === null || trim($dizContent) === '') {
            return;
        }

        $dizContent = $this->sanitizeToUtf8($dizContent);
        $dizContent = str_replace(["\r\n", "\r"], "\n", $dizContent);
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $dizContent)), fn($l) => $l !== ''));

        if (empty($lines)) {
            return;
        }

        // Use first line as short description if TIC provided none
        if (empty($ticData['Desc'])) {
            $ticData['Desc'] = mb_substr($lines[0], 0, 255);
        }

        // Use full DIZ content as long description if TIC provided none
        if (empty($ticData['LDesc'])) {
            $ticData['LDesc'] = $lines;
            $this->log("Populated description from FILE_ID.DIZ in " . basename($filePath));
        }
    }

    protected function parseTicFile(string $ticPath): array
    {
        if (!file_exists($ticPath)) {
            throw new \Exception("TIC file not found: $ticPath");
        }

        $content = file_get_contents($ticPath);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);

        $ticData = [
            'Path' => [],
            'Seenby' => [],
            'LDesc' => []
        ];

        // Canonical field name map - TIC field names are case-insensitive (DOS-era software uses all-caps)
        $keyMap = [
            'area' => 'Area', 'file' => 'File', 'from' => 'From',
            'pw' => 'Pw', 'desc' => 'Desc', 'ldesc' => 'LDesc',
            'size' => 'Size', 'crc' => 'Crc', 'path' => 'Path',
            'seenby' => 'Seenby', 'created' => 'Created', 'origin' => 'Origin',
            'to' => 'To', 'areadesc' => 'Areadesc',
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Parse key value pairs
            if (preg_match('/^(\w+)\s+(.+)$/i', $line, $matches)) {
                $key = $keyMap[strtolower($matches[1])] ?? $matches[1];
                $value = trim($matches[2]);

                // Multi-line fields
                if ($key === 'Path' || $key === 'Seenby') {
                    $ticData[$key][] = $value;
                } elseif ($key === 'LDesc') {
                    $ticData['LDesc'][] = $value;
                } else {
                    $ticData[$key] = $value;
                }
            }
        }

        // Validate required fields
        $required = ['Area', 'File', 'From'];
        foreach ($required as $field) {
            if (!isset($ticData[$field])) {
                throw new \Exception("Missing required TIC field: $field");
            }
        }

        return $ticData;
    }

    /**
     * Validate file against TIC metadata
     *
     * @param array $ticData Parsed TIC data
     * @param string $filePath Path to data file
     * @return bool True if valid
     */
    protected function validateFile(array $ticData, string $filePath): bool
    {
        if (!file_exists($filePath)) {
            $this->log("TIC validation failed: file not found: $filePath");
            return false;
        }

        // Validate size
        if (isset($ticData['Size'])) {
            $actualSize = filesize($filePath);
            $expectedSize = intval($ticData['Size']);
            if ($actualSize !== $expectedSize) {
                $this->log("TIC size mismatch: expected $expectedSize, got $actualSize");
                return false;
            }
        }

        // Validate CRC32
        if (isset($ticData['Crc'])) {
            $actualCrc = $this->calculateCrc32($filePath);
            if (strtoupper($actualCrc) !== strtoupper($ticData['Crc'])) {
                $this->log("TIC CRC mismatch: expected {$ticData['Crc']}, got $actualCrc");
                return false;
            }
        }

        return true;
    }

    /**
     * Validate TIC file area password if configured.
     *
     * @param array $ticData Parsed TIC data
     * @param array $fileArea File area record
     * @throws \Exception If password validation fails
     */
    protected function validateTicPassword(array $ticData, array $fileArea, string $fromAddress = ''): void
    {
        // Per-area password takes precedence; fall back to uplink-level tic_password
        $expected = $fileArea['password'] ?? '';
        if ($expected === '' && $fromAddress !== '') {
            $expected = BinkpConfig::getInstance()->getTicPasswordForAddress($fromAddress);
        }

        if ($expected === '') {
            return; // No password required
        }

        $provided = $ticData['Pw'] ?? '';
        if (!hash_equals(strtolower($expected), strtolower($provided))) {
            throw new \Exception('TIC password rejected for file area');
        }
    }

    /**
     * Calculate CRC32 checksum
     *
     * @param string $filePath Path to file
     * @return string CRC32 checksum (8 hex digits)
     */
    protected function calculateCrc32(string $filePath): string
    {
        $hash = hash_file('crc32b', $filePath);
        return strtoupper($hash);
    }

    /**
     * Get file area by tag
     *
     * @param string $tag File area tag
     * @return array|null File area record or null
     */
    protected function getFileArea(string $tag, ?string $domain = null): ?array
    {
        if ($domain === null || $domain === '') {
            $stmt = $this->db->prepare("
                SELECT id, tag, max_file_size, is_active, replace_existing, scan_virus, allow_duplicate_hash, password
                FROM file_areas
                WHERE tag = ? AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$tag]);
        } else {
            $stmt = $this->db->prepare("
                SELECT id, tag, max_file_size, is_active, replace_existing, scan_virus, allow_duplicate_hash, password
                FROM file_areas
                WHERE tag = ? AND domain = ? AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$tag, $domain]);
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Auto-create file area from TIC file
     *
     * @param string $areaTag File area tag
     * @param array $ticData TIC data for generating description
     * @return int File area ID
     */
    protected function autoCreateFileArea(string $areaTag, array $ticData, ?string $domain = null): int
    {
        // Generate description from TIC data if available
        $description = "Auto-created from TIC file";
        if (isset($ticData['Desc'])) {
            $description = "Auto-created: " . $ticData['Desc'];
        }

        $domain = $domain ?: $this->getDomainFromTicData($ticData);

        // Create file area with sensible defaults
        // Set upload_permission to READ_ONLY (2) since this is an auto-created file area from TIC
        $stmt = $this->db->prepare("
            INSERT INTO file_areas (
                tag, description, domain, is_local, is_active,
                max_file_size, replace_existing, upload_permission,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, FALSE, TRUE,
                10485760, FALSE, ?,
                NOW(), NOW()
            ) RETURNING id
        ");

        $stmt->execute([$areaTag, $description, $domain, FileAreaManager::UPLOAD_READ_ONLY]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['id'];
    }

    /**
     * Map TIC From address to domain using BinkpConfig.
     *
     * @param array $ticData
     * @return string
     */
    protected function getDomainFromTicData(array $ticData): string
    {
        $from = $ticData['From'] ?? '';
        if ($from === '') {
            return 'unknownnet';
        }

        $binkpConfig = BinkpConfig::getInstance();
        $domain = $binkpConfig->getDomainByAddress($from);

        return $domain ?: 'unknownnet';
    }

    /**
     * Check if file already exists in file area (by hash)
     *
     * @param int $fileAreaId File area ID
     * @param string $fileHash SHA256 hash
     * @return array|null Existing file record or null
     */
    protected function checkDuplicate(int $fileAreaId, string $fileHash): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, filename
            FROM files
            WHERE file_area_id = ? AND file_hash = ?
            LIMIT 1
        ");
        $stmt->execute([$fileAreaId, $fileHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Get file record by storage path
     *
     * @param string $storagePath Full path to file
     * @return array|null File record or null
     */
    protected function getFileByPath(string $storagePath): ?array
    {
        // Normalize path for comparison
        $storagePath = realpath($storagePath);
        if (!$storagePath) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, filename, file_area_id
            FROM files
            WHERE storage_path = ?
            LIMIT 1
        ");
        $stmt->execute([$storagePath]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Store file in file area
     *
     * @param array $ticData Parsed TIC data
     * @param string $tempFilePath Path to temporary file
     * @param array $fileArea File area record
     * @param string $preCalculatedHash Pre-calculated SHA256 hash
     * @param string|null $storedHash Hash to store (allows duplicates when needed)
     * @return array File info with keys: id, storage_path, filename
     * @throws \Exception If storage fails
     */
    protected function storeFile(array $ticData, string $tempFilePath, array $fileArea, string $preCalculatedHash, ?string $storedHash = null): array
    {
        // Sanitize filename from TIC to prevent directory traversal
        $filename = basename($ticData['File']);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new \Exception("Invalid filename in TIC File field: " . $ticData['File']);
        }

        $areaTag = $fileArea['tag'];
        $areaId = $fileArea['id'] ?? null;
        $dirSuffix = $areaId ? ($areaTag . '-' . $areaId) : $areaTag;

        // Create area directory if needed
        $areaDir = $this->filesBasePath . '/' . $dirSuffix;
        FileAreaManager::ensureDirectoryExists($areaDir);

        // Determine storage path
        $storagePath = $areaDir . '/' . $filename;

        // Handle filename conflicts based on replace_existing setting
        if (file_exists($storagePath)) {
            if ($fileArea['replace_existing']) {
                // Replace mode: delete old file and database record
                $oldFile = $this->getFileByPath($storagePath);
                if ($oldFile) {
                    // Delete database record
                    $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
                    $stmt->execute([$oldFile['id']]);
                    $this->log("Replaced existing file: {$filename} (old file_id={$oldFile['id']})");
                }
                // Delete old file from disk
                unlink($storagePath);
            } else {
                // Version mode: add suffix to keep both versions
                $counter = 1;
                while (file_exists($storagePath)) {
                    $pathInfo = pathinfo($filename);
                    $newFilename = $pathInfo['filename'] . '_' . $counter;
                    if (isset($pathInfo['extension'])) {
                        $newFilename .= '.' . $pathInfo['extension'];
                    }
                    $storagePath = $areaDir . '/' . $newFilename;
                    $filename = $newFilename;
                    $counter++;
                }
            }
        }

        // Copy file to storage (don't move until we verify)
        if (!copy($tempFilePath, $storagePath)) {
            throw new \Exception("Failed to copy file to storage: $storagePath");
        }

        chmod($storagePath, 0664);

        // Verify the copy
        $copyHash = hash_file('sha256', $storagePath);
        if ($copyHash !== $preCalculatedHash) {
            // Copy failed - delete and throw error
            unlink($storagePath);
            throw new \Exception("File copy verification failed - hash mismatch");
        }

        // Normalize storage path
        $storagePath = realpath($storagePath);

        // Now that file is safely stored, get metadata
        $fileHash = $storedHash ?? $preCalculatedHash;
        $fileSize = filesize($storagePath);

        // Build descriptions
        $shortDesc = $this->sanitizeToUtf8($ticData['Desc'] ?? '');
        $longDesc = !empty($ticData['LDesc']) ? $this->sanitizeToUtf8(implode("\n", $ticData['LDesc'])) : '';

        // Truncate fields to fit database constraints (VARCHAR 255)
        $filename = mb_substr($filename, 0, 255);
        $shortDesc = mb_substr($shortDesc, 0, 255);
        $fromAddress = mb_substr($this->sanitizeToUtf8($ticData['From'] ?? ''), 0, 255);

        // Store in database
        $stmt = $this->db->prepare("
            INSERT INTO files (
                file_area_id, filename, filesize, file_hash, storage_path,
                uploaded_from_address, source_type,
                short_description, long_description,
                tic_path, tic_seenby, tic_crc,
                status, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, 'fidonet',
                ?, ?,
                ?, ?, ?,
                'approved', NOW()
            ) RETURNING id
        ");

        $stmt->execute([
            $fileArea['id'],
            $filename,
            $fileSize,
            $fileHash,
            $storagePath,
            $fromAddress,
            $shortDesc,
            $longDesc,
            implode(' ', $ticData['Path'] ?? []),
            implode(' ', $ticData['Seenby'] ?? []),
            $ticData['Crc'] ?? ''
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'id' => (int)$result['id'],
            'storage_path' => $storagePath,
            'filename' => $filename
        ];
    }

    /**
     * Update file area statistics
     *
     * @param int $fileAreaId File area ID
     */
    protected function updateFileAreaStats(int $fileAreaId): void
    {
        $stmt = $this->db->prepare("
            UPDATE file_areas
            SET file_count = (
                    SELECT COUNT(*) FROM files WHERE file_area_id = ? AND status = 'approved'
                ),
                total_size = (
                    SELECT COALESCE(SUM(filesize), 0) FROM files WHERE file_area_id = ? AND status = 'approved'
                ),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$fileAreaId, $fileAreaId, $fileAreaId]);
    }

    /**
     * Scan a file for viruses using ClamAV
     *
     * @param int $fileId File ID in database
     * @param array $fileArea File area record
     * @return void
     */
    protected function scanFileForViruses(int $fileId, array $fileArea): array
    {
        // Check if virus scanning is enabled for this area
        if (empty($fileArea['scan_virus'])) {
            return [
                'scanned' => false,
                'result' => 'skipped',
                'signature' => null,
                'error_code' => 'errors.virus_scanner.not_available',
                'error' => 'Virus scanning not available'
            ];
        }

        // Get file path from database
        $stmt = $this->db->prepare("SELECT storage_path FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $fileRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fileRecord || !file_exists($fileRecord['storage_path'])) {
            $this->log("Cannot scan file {$fileId}: file not found");
            return [
                'scanned' => false,
                'result' => 'error',
                'signature' => null,
                'error_code' => 'errors.virus_scanner.file_not_found',
                'error' => 'File not found for virus scan'
            ];
        }

        $filePath = $fileRecord['storage_path'];

        // Perform virus scan
        $scanner = new VirusScanner();
        $result = $scanner->scanFile($filePath);

        // Update database with scan results
        $stmt = $this->db->prepare("
            UPDATE files
            SET virus_scanned = ?,
                virus_scan_result = ?,
                virus_signature = ?,
                virus_scanned_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $result['scanned'] ? 1 : 0,
            $result['result'],
            $result['signature'],
            $fileId
        ]);

        // Handle infected files
        if ($result['result'] === 'infected') {
            $allowInfected = \BinktermPHP\Config::env('CLAMAV_ALLOW_INFECTED', 'false') === 'true';
            $this->log("VIRUS DETECTED in TIC file: File ID {$fileId} infected with {$result['signature']}" . ($allowInfected ? ' (CLAMAV_ALLOW_INFECTED: keeping file)' : ''));

            if (!$allowInfected) {
                // Delete infected file immediately
                if (file_exists($filePath)) {
                    unlink($filePath);
                    $this->log("Deleted infected TIC file: {$filePath}");
                }

                // Mark file record as rejected
                $stmt = $this->db->prepare("UPDATE files SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$fileId]);
            }
        } elseif ($result['result'] === 'error') {
            $this->log("Virus scan error for TIC file ID {$fileId}: {$result['error']}");
        }

        return $result;
    }

    /**
     * Convert a string to valid UTF-8, trying CP437 first (common FidoNet/DOS encoding).
     * Invalid bytes are dropped rather than causing a PostgreSQL encoding error.
     *
     * @param string $text Raw input that may be CP437, ISO-8859-1, or already UTF-8
     * @return string Valid UTF-8 string
     */
    private function sanitizeToUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('CP437', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                return $converted;
            }
            $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                return $converted;
            }
        }

        return mb_convert_encoding($text, 'UTF-8', 'CP437');
    }

    /**
     * Generate a unique hash if duplicates are allowed in this area
     *
     * @param int $fileAreaId
     * @param string $baseHash
     * @return string
     */
    private function makeUniqueHash(int $fileAreaId, string $baseHash): string
    {
        $hash = $baseHash;
        $counter = 1;
        while ($this->checkDuplicate($fileAreaId, $hash)) {
            $hash = hash('sha256', $baseHash . ':' . $counter);
            $counter++;
        }

        return $hash;
    }
}

