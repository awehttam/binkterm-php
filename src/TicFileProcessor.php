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

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->filesBasePath = __DIR__ . '/../data/files';
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
                        'error' => "Failed to create file area: {$ticData['Area']}"
                    ];
                }

                error_log("Auto-created file area: {$ticData['Area']} (id={$fileArea['id']})");
            }

            // Validate file
            if (!$this->validateFile($ticData, $filePath)) {
                return [
                    'success' => false,
                    'error' => 'File validation failed (size/CRC mismatch)'
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
                    error_log("TIC file already exists: {$ticData['File']} (file_id={$existingFile['id']})");

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
            if (($scanResult['result'] ?? '') === 'infected') {
                return [
                    'success' => false,
                    'error' => 'File rejected: virus detected.'
                ];
            }

            // Update file area statistics
            $this->updateFileAreaStats($fileArea['id']);

            // Run file area automation rules (skip if infected)
            try {
                $ruleProcessor = new FileAreaRuleProcessor();
                $ruleResult = $ruleProcessor->processFile($storagePath, $fileArea['tag']);
                if (!empty($ruleResult['output'])) {
                    error_log("File area rules output for {$ticData['File']}: " . $ruleResult['output']);
                }
            } catch (\Exception $e) {
                error_log("File area rules error for {$ticData['File']}: " . $e->getMessage());
            }

            // Clean up temp file after successful storage
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            return [
                'success' => true,
                'file_id' => $fileId,
                'area' => $ticData['Area'],
                'filename' => $ticData['File'],
                'duplicate' => false
            ];

        } catch (\Exception $e) {
            error_log("TIC processing error: " . $e->getMessage());
            return [
                'success' => false,
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
    protected function parseTicFile(string $ticPath): array
    {
        if (!file_exists($ticPath)) {
            throw new \Exception("TIC file not found: $ticPath");
        }

        $content = file_get_contents($ticPath);
        $lines = explode("\n", $content);

        $ticData = [
            'Path' => [],
            'Seenby' => [],
            'LDesc' => []
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Parse key value pairs
            if (preg_match('/^(\w+)\s+(.+)$/i', $line, $matches)) {
                $key = $matches[1];
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
            error_log("TIC validation failed: file not found: $filePath");
            return false;
        }

        // Validate size
        if (isset($ticData['Size'])) {
            $actualSize = filesize($filePath);
            $expectedSize = intval($ticData['Size']);
            if ($actualSize !== $expectedSize) {
                error_log("TIC size mismatch: expected $expectedSize, got $actualSize");
                return false;
            }
        }

        // Validate CRC32
        if (isset($ticData['Crc'])) {
            $actualCrc = $this->calculateCrc32($filePath);
            if (strtoupper($actualCrc) !== strtoupper($ticData['Crc'])) {
                error_log("TIC CRC mismatch: expected {$ticData['Crc']}, got $actualCrc");
                return false;
            }
        }

        return true;
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
                SELECT id, tag, max_file_size, is_active, replace_existing, scan_virus, allow_duplicate_hash
                FROM file_areas
                WHERE tag = ? AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$tag]);
        } else {
            $stmt = $this->db->prepare("
                SELECT id, tag, max_file_size, is_active, replace_existing, scan_virus, allow_duplicate_hash
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
        $stmt = $this->db->prepare("
            INSERT INTO file_areas (
                tag, description, domain, is_local, is_active,
                max_file_size, replace_existing,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, FALSE, TRUE,
                10485760, FALSE,
                NOW(), NOW()
            ) RETURNING id
        ");

        $stmt->execute([$areaTag, $description, $domain]);
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
        $filename = $ticData['File'];
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
                    error_log("Replaced existing file: {$filename} (old file_id={$oldFile['id']})");
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
        $shortDesc = $ticData['Desc'] ?? '';
        $longDesc = !empty($ticData['LDesc']) ? implode("\n", $ticData['LDesc']) : '';

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
            $ticData['From'] ?? '',
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
                'error' => 'Virus scanning not enabled'
            ];
        }

        // Get file path from database
        $stmt = $this->db->prepare("SELECT storage_path FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $fileRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fileRecord || !file_exists($fileRecord['storage_path'])) {
            error_log("Cannot scan file {$fileId}: file not found");
            return [
                'scanned' => false,
                'result' => 'error',
                'signature' => null,
                'error' => 'File not found'
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
            error_log("VIRUS DETECTED in TIC file: File ID {$fileId} infected with {$result['signature']}");

            // Delete infected file immediately
            if (file_exists($filePath)) {
                unlink($filePath);
                error_log("Deleted infected TIC file: {$filePath}");
            }

            // Mark file record as rejected
            $stmt = $this->db->prepare("UPDATE files SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$fileId]);
        } elseif ($result['result'] === 'error') {
            error_log("Virus scan error for TIC file ID {$fileId}: {$result['error']}");
        }

        return $result;
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

