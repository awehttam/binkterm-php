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

/**
 * FileAreaManager - Manages file areas and files
 *
 * Handles CRUD operations for file areas and file records
 */
class FileAreaManager
{
    // Upload permission constants
    public const UPLOAD_ADMIN_ONLY = 0;
    public const UPLOAD_USERS_ALLOWED = 1;
    public const UPLOAD_READ_ONLY = 2;
    const DIR_PERM = 2775;      // Directory permissions use 2775 to ensure group sticky access between web server and local user

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Check if file areas feature is enabled
     *
     * @return bool
     */
    public static function isFeatureEnabled(): bool
    {
        return BbsConfig::isFeatureEnabled('file_areas');
    }

    /**
     * Get all file areas with optional filtering
     *
     * @param string $filter Filter: 'active', 'inactive', or 'all'
     * @param int|null $userId User ID for filtering private areas (null = exclude all private areas)
     * @param bool $isAdmin Whether the user is an admin (admins see all areas)
     * @return array Array of file areas
     */
    public function getFileAreas(string $filter = 'active', ?int $userId = null, bool $isAdmin = false): array
    {
        $sql = "SELECT * FROM file_areas WHERE 1=1";
        $params = [];

        if ($filter === 'active') {
            $sql .= " AND is_active = TRUE";
        } elseif ($filter === 'inactive') {
            $sql .= " AND is_active = FALSE";
        }

        // Always hide private areas from file areas list
        // Private areas are system-managed (e.g., netmail attachments)
        // Users can still access their files via direct download links
        $sql .= " AND (is_private = FALSE OR is_private IS NULL)";

        $sql .= " ORDER BY tag ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $areas = $stmt->fetchAll();

        return $areas;
    }

    /**
     * Get a single file area by ID
     *
     * @param int $id File area ID
     * @return array|null File area record or null if not found
     */
    public function getFileAreaById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM file_areas WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Check if a user can access a file area
     *
     * @param int $areaId File area ID
     * @param int|null $userId User ID (null for anonymous)
     * @param bool $isAdmin Whether user is admin
     * @return bool True if user has access, false otherwise
     */
    public function canAccessFileArea(int $areaId, ?int $userId, bool $isAdmin = false): bool
    {
        // Admins can access all areas
        if ($isAdmin) {
            return true;
        }

        $area = $this->getFileAreaById($areaId);
        if (!$area) {
            return false;
        }

        // Check if it's a private area
        if (!empty($area['is_private'])) {
            // Private areas are only accessible by their owner
            if ($userId === null) {
                return false;
            }
            // Check if this is the user's private area (format: PRIVATE_USER_{id})
            $expectedTag = 'PRIVATE_USER_' . $userId;
            return $area['tag'] === $expectedTag;
        }

        // Public areas are accessible to everyone
        return true;
    }

    /**
     * Get a file area by tag and domain
     *
     * @param string $tag File area tag
     * @param string $domain Domain (default: 'fidonet')
     * @return array|null File area record or null if not found
     */
    public function getFileAreaByTag(string $tag, string $domain = 'fidonet'): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM file_areas WHERE tag = ? AND domain = ?");
        $stmt->execute([$tag, $domain]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get the domain for a file area tag
     *
     * @param string $tag
     * @return string
     */
    public function getDomainForArea(string $tag): string
    {
        $stmt = $this->db->prepare("SELECT domain FROM file_areas WHERE tag = ? LIMIT 1");
        $stmt->execute([$tag]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['domain'] ?? 'fidonet';
    }

    /**
     * Create a new file area
     *
     * @param array $data File area data
     * @return int New file area ID
     * @throws \Exception If validation fails or area already exists
     */
    public function createFileArea(array $data): int
    {
        $tag = strtoupper(trim($data['tag'] ?? ''));
        $description = trim($data['description'] ?? '');
        $domain = trim($data['domain'] ?? '');
        $maxFileSize = intval($data['max_file_size'] ?? 10485760);
        $allowedExtensions = trim($data['allowed_extensions'] ?? '');
        $blockedExtensions = trim($data['blocked_extensions'] ?? '');
        $replaceExisting = (bool)($data['replace_existing'] ?? false);
        $allowDuplicateHash = (bool)($data['allow_duplicate_hash'] ?? false);
        $isLocal = (bool)($data['is_local'] ?? false);
        $uploadPermission = intval($data['upload_permission'] ?? self::UPLOAD_USERS_ALLOWED);
        $scanVirus = (bool)($data['scan_virus'] ?? true);
        $isActive = (bool)($data['is_active'] ?? false);

        if (empty($tag) || empty($description)) {
            throw new \Exception('Tag and description are required');
        }

        // Check for duplicate
        if ($this->getFileAreaByTag($tag, $domain)) {
            throw new \Exception('File area with this tag already exists in this domain');
        }

        $stmt = $this->db->prepare("
            INSERT INTO file_areas (
                tag, description, domain, is_local, is_active,
                max_file_size, allowed_extensions, blocked_extensions, replace_existing,
                allow_duplicate_hash,
                upload_permission, scan_virus,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ");

        $stmt->execute([
            $tag, $description, $domain, $isLocal ? 1 : 0, $isActive ? 1 : 0,
            $maxFileSize, $allowedExtensions, $blockedExtensions, $replaceExisting ? 1 : 0,
            $allowDuplicateHash ? 1 : 0,
            $uploadPermission, $scanVirus ? 1 : 0
        ]);

        $result = $stmt->fetch();
        return $result['id'];
    }

    /**
     * Update an existing file area
     *
     * @param int $id File area ID
     * @param array $data Updated file area data
     * @return bool True if updated successfully
     * @throws \Exception If validation fails or area not found
     */
    public function updateFileArea(int $id, array $data): bool
    {
        $tag = strtoupper(trim($data['tag'] ?? ''));
        $description = trim($data['description'] ?? '');
        $domain = trim($data['domain'] ?? '');
        $maxFileSize = intval($data['max_file_size'] ?? 10485760);
        $allowedExtensions = trim($data['allowed_extensions'] ?? '');
        $blockedExtensions = trim($data['blocked_extensions'] ?? '');
        $replaceExisting = (bool)($data['replace_existing'] ?? false);
        $allowDuplicateHash = (bool)($data['allow_duplicate_hash'] ?? false);
        $isLocal = (bool)($data['is_local'] ?? false);
        $uploadPermission = intval($data['upload_permission'] ?? self::UPLOAD_USERS_ALLOWED);
        $scanVirus = (bool)($data['scan_virus'] ?? true);
        $isActive = (bool)($data['is_active'] ?? false);

        if (empty($tag) || empty($description)) {
            throw new \Exception('Tag and description are required');
        }

        // Check for duplicate tag (excluding current record)
        $existing = $this->getFileAreaByTag($tag, $domain);
        if ($existing && $existing['id'] != $id) {
            throw new \Exception('File area with this tag already exists in this domain');
        }

        $stmt = $this->db->prepare("
            UPDATE file_areas
            SET tag = ?, description = ?, domain = ?, is_local = ?, is_active = ?,
                max_file_size = ?, allowed_extensions = ?, blocked_extensions = ?,
                replace_existing = ?, allow_duplicate_hash = ?, upload_permission = ?, scan_virus = ?, updated_at = NOW()
            WHERE id = ?
        ");

        $result = $stmt->execute([
            $tag, $description, $domain, $isLocal ? 1 : 0, $isActive ? 1 : 0,
            $maxFileSize, $allowedExtensions, $blockedExtensions, $replaceExisting ? 1 : 0,
            $allowDuplicateHash ? 1 : 0,
            $uploadPermission, $scanVirus ? 1 : 0, $id
        ]);

        if (!$result || $stmt->rowCount() === 0) {
            throw new \Exception('File area not found');
        }

        return true;
    }

    /**
     * Delete a file area and all its files
     *
     * @param int $id File area ID
     * @return bool True if deleted successfully
     * @throws \Exception If file area not found
     */
    public function deleteFileArea(int $id): bool
    {
        // Get all files in this area to delete them from disk
        $stmt = $this->db->prepare("SELECT storage_path FROM files WHERE file_area_id = ?");
        $stmt->execute([$id]);
        $files = $stmt->fetchAll();

        // Delete files from disk
        foreach ($files as $file) {
            if (file_exists($file['storage_path'])) {
                unlink($file['storage_path']);
            }
        }

        // Delete file area (CASCADE will delete files table records)
        $stmt = $this->db->prepare("DELETE FROM file_areas WHERE id = ?");
        $result = $stmt->execute([$id]);

        if (!$result || $stmt->rowCount() === 0) {
            throw new \Exception('File area not found');
        }

        return true;
    }

    /**
     * Get file area statistics
     *
     * @return array Statistics array with active_count, total_files, total_size
     */
    public function getStats(): array
    {
        $activeCount = $this->db->query("SELECT COUNT(*) as count FROM file_areas WHERE is_active = TRUE")->fetch()['count'];
        $totalFiles = $this->db->query("SELECT SUM(file_count) as count FROM file_areas")->fetch()['count'] ?? 0;
        $totalSize = $this->db->query("SELECT SUM(total_size) as size FROM file_areas")->fetch()['size'] ?? 0;

        return [
            'active_count' => (int)$activeCount,
            'total_files' => (int)$totalFiles,
            'total_size' => (int)$totalSize
        ];
    }

    /**
     * Get files in a file area
     *
     * @param int $areaId File area ID
     * @return array Array of files
     */
    public function getFiles(int $areaId): array
    {
        $stmt = $this->db->prepare("
            SELECT f.*, fa.tag as area_tag
            FROM files f
            JOIN file_areas fa ON f.file_area_id = fa.id
            WHERE f.file_area_id = ? AND f.status = 'approved'
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$areaId]);
        return $stmt->fetchAll();
    }

    /**
     * Get a single file by ID
     *
     * @param int $id File ID
     * @return array|null File record or null if not found
     */
    public function getFileById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT f.*, fa.tag as area_tag, fa.domain
            FROM files f
            JOIN file_areas fa ON f.file_area_id = fa.id
            WHERE f.id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Upload a file to a file area
     *
     * @param int $fileAreaId File area ID
     * @param array $fileData Uploaded file data from $_FILES
     * @param string $shortDescription Short description
     * @param string $longDescription Long description (optional)
     * @param string $uploadedBy User's FidoNet address or username
     * @param int|null $ownerId User ID of file owner (NULL for TIC files)
     * @return int File ID
     * @throws \Exception If upload fails
     */
    public function uploadFile(int $fileAreaId, array $fileData, string $shortDescription, string $longDescription = '', string $uploadedBy = '', ?int $ownerId = null): int
    {
        // Get file area
        $fileArea = $this->getFileAreaById($fileAreaId);
        if (!$fileArea || !$fileArea['is_active']) {
            throw new \Exception('File area not found or inactive');
        }

        // Validate file upload
        if (!isset($fileData['tmp_name']) || !is_uploaded_file($fileData['tmp_name'])) {
            throw new \Exception('Invalid file upload');
        }

        $filename = basename($fileData['name']);
        $fileSize = $fileData['size'];
        $tmpPath = $fileData['tmp_name'];

        // Check file size
        if ($fileSize > $fileArea['max_file_size']) {
            $maxMB = round($fileArea['max_file_size'] / 1048576, 2);
            throw new \Exception("File size exceeds maximum allowed size of {$maxMB} MB");
        }

        // Check file extension
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!empty($fileArea['blocked_extensions'])) {
            $blockedExts = array_map('trim', explode(',', strtolower($fileArea['blocked_extensions'])));
            if (in_array($ext, $blockedExts)) {
                throw new \Exception("File type .{$ext} is not allowed");
            }
        }

        if (!empty($fileArea['allowed_extensions'])) {
            $allowedExts = array_map('trim', explode(',', strtolower($fileArea['allowed_extensions'])));
            if (!in_array($ext, $allowedExts)) {
                throw new \Exception("File type .{$ext} is not allowed. Allowed types: " . $fileArea['allowed_extensions']);
            }
        }

        // Calculate hash
        $fileHash = hash_file('sha256', $tmpPath);

        // Check for duplicates (allow override per area)
        $allowDuplicate = !empty($fileArea['allow_duplicate_hash']);
        if ($allowDuplicate) {
            $fileHash = $this->makeUniqueHash($fileAreaId, $fileHash);
        } else {
            $existingFile = $this->checkDuplicate($fileAreaId, $fileHash);
            if ($existingFile) {
                throw new \Exception('This file already exists in this area');
            }
        }

        // Create area directory if needed
        $areaDir = $this->getAreaStorageDir($fileArea);
        self::ensureDirectoryExists($areaDir);

        // Determine storage path
        $storagePath = $areaDir . '/' . $filename;

        // Handle filename conflicts based on replace_existing setting
        if (file_exists($storagePath)) {
            if ($fileArea['replace_existing']) {
                // Replace mode: delete old file and database record
                $oldFile = $this->getFileByPath($storagePath);
                if ($oldFile) {
                    $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
                    $stmt->execute([$oldFile['id']]);
                }
                unlink($storagePath);
            } else {
                // Version mode: add suffix
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

        // Move uploaded file
        if (!move_uploaded_file($tmpPath, $storagePath)) {
            throw new \Exception('Failed to save uploaded file');
        }

        chmod($storagePath, 0664);
        $storagePath = realpath($storagePath);

        // Store in database
        $stmt = $this->db->prepare("
            INSERT INTO files (
                file_area_id, filename, filesize, file_hash, storage_path,
                uploaded_from_address, source_type,
                short_description, long_description,
                owner_id, status, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, 'user_upload',
                ?, ?,
                ?, 'approved', NOW()
            ) RETURNING id
        ");

        $stmt->execute([
            $fileAreaId,
            $filename,
            $fileSize,
            $fileHash,
            $storagePath,
            $uploadedBy,
            $shortDescription,
            $longDescription,
            $ownerId
        ]);

        $result = $stmt->fetch();
        $fileId = $result['id'];

        // Update file area statistics
        $this->updateFileAreaStats($fileAreaId);

        // Scan for viruses if enabled for this file area
        if (!empty($fileArea['scan_virus'])) {
            $scanResult = $this->scanFileForViruses($fileId, $storagePath);
            if (($scanResult['result'] ?? '') === 'infected') {
                throw new \Exception('File rejected: virus detected.');
            }
        }

        // Run file area automation rules
        try {
            $ruleProcessor = new FileAreaRuleProcessor();
            $ruleResult = $ruleProcessor->processFile($storagePath, $fileArea['tag']);
            if (!empty($ruleResult['output'])) {
                error_log("File area rules output for {$filename}: " . $ruleResult['output']);
            }
        } catch (\Exception $e) {
            error_log("File area rules error for {$filename}: " . $e->getMessage());
        }

        // If rules deleted the file, skip TIC generation
        $fileRecord = $this->getFileById($fileId);
        if (!$fileRecord) {
            return $fileId;
        }

        // Generate TIC files for distribution to uplinks (if not a local area)
        if (empty($fileArea['is_local']) && empty($fileArea['is_private'])) {
            try {
                $ticGenerator = new TicFileGenerator();
                $createdTics = $ticGenerator->createTicFilesForUplinks($fileRecord, $fileArea);

                if (count($createdTics) > 0) {
                    error_log("Generated " . count($createdTics) . " TIC file(s) for file: {$filename}");
                }
            } catch (\Throwable $e) {
                // Log error but don't fail the upload
                error_log("Failed to generate TIC files for uploaded file: " . $e->getMessage());
            }
        }

        return $fileId;
    }

    /**
     * Scan a file for viruses using ClamAV
     *
     * @param int $fileId File ID in database
     * @param string $filePath Path to file to scan
     * @return void
     */
    private function scanFileForViruses(int $fileId, string $filePath): array
    {
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
            error_log("VIRUS DETECTED: File ID {$fileId} infected with {$result['signature']}");

            // Delete infected file immediately
            if (file_exists($filePath)) {
                unlink($filePath);
                error_log("Deleted infected file: {$filePath}");
            }

            // Mark file record as rejected
            $stmt = $this->db->prepare("UPDATE files SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$fileId]);
        } elseif ($result['result'] === 'error') {
            error_log("Virus scan error for file ID {$fileId}: {$result['error']}");
        }

        return $result;
    }

    /**
     * Delete a file
     *
     * @param int $fileId File ID
     * @param int $userId User ID requesting deletion
     * @param bool $isAdmin Whether user is admin
     * @return bool True if deleted
     * @throws \Exception If user doesn't have permission or file not found
     */
    public function deleteFile(int $fileId, int $userId, bool $isAdmin): bool
    {
        $file = $this->getFileById($fileId);
        if (!$file) {
            throw new \Exception('File not found');
        }

        // Check permissions: admin or file owner
        if (!$isAdmin && ($file['owner_id'] === null || $file['owner_id'] != $userId)) {
            throw new \Exception('You do not have permission to delete this file');
        }

        // Delete file from disk
        if (file_exists($file['storage_path'])) {
            unlink($file['storage_path']);
        }

        // Delete from database
        $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
        $result = $stmt->execute([$fileId]);

        if ($result && $stmt->rowCount() > 0) {
            // Update file area statistics
            $this->updateFileAreaStats($file['file_area_id']);
            return true;
        }

        throw new \Exception('Failed to delete file');
    }

    /**
     * Delete a file by storage path (used by automation rules)
     *
     * @param string $filepath
     * @return bool
     */
    public function deleteFileByPath(string $filepath): bool
    {
        $record = $this->getFileRecordByPath($filepath);
        if ($record) {
            if (file_exists($record['storage_path'])) {
                unlink($record['storage_path']);
            }
            $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
            $stmt->execute([$record['id']]);
            $this->updateFileAreaStats((int)$record['file_area_id']);
            return true;
        }

        if (file_exists($filepath)) {
            unlink($filepath);
        }

        return true;
    }

    /**
     * Move a file to another file area (used by automation rules)
     *
     * @param string $filepath
     * @param string $targetArea
     * @return bool
     */
    public function moveFileToArea(string $filepath, string $targetArea): bool
    {
        $record = $this->getFileRecordByPath($filepath);
        if (!$record) {
            return false;
        }

        $targetArea = strtoupper(trim($targetArea));
        if ($targetArea === '') {
            return false;
        }

        $stmt = $this->db->prepare("SELECT * FROM file_areas WHERE tag = ? AND domain = ? AND is_active = TRUE LIMIT 1");
        $stmt->execute([$targetArea, $record['domain'] ?? 'fidonet']);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            return false;
        }

        $targetDir = $this->getAreaStorageDir($target);
        self::ensureDirectoryExists($targetDir);

        $filename = $record['filename'];
        $targetPath = $targetDir . '/' . $filename;

        if (file_exists($targetPath)) {
            if (!empty($target['replace_existing'])) {
                $existing = $this->getFileByPath($targetPath);
                if ($existing) {
                    $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
                    $stmt->execute([$existing['id']]);
                }
                unlink($targetPath);
            } else {
                $counter = 1;
                while (file_exists($targetPath)) {
                    $pathInfo = pathinfo($filename);
                    $newFilename = $pathInfo['filename'] . '_' . $counter;
                    if (!empty($pathInfo['extension'])) {
                        $newFilename .= '.' . $pathInfo['extension'];
                    }
                    $filename = $newFilename;
                    $targetPath = $targetDir . '/' . $filename;
                    $counter++;
                }
            }
        }

        if (!rename($record['storage_path'], $targetPath)) {
            return false;
        }

        chmod($targetPath, 0664);
        $targetPath = realpath($targetPath) ?: $targetPath;

        $stmt = $this->db->prepare("UPDATE files SET file_area_id = ?, filename = ?, storage_path = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([
            $target['id'],
            $filename,
            $targetPath,
            $record['id']
        ]);

        $this->updateFileAreaStats((int)$record['file_area_id']);
        $this->updateFileAreaStats((int)$target['id']);

        return true;
    }

    /**
     * Archive a file (used by automation rules)
     *
     * @param string $filepath
     * @param string $areatag
     * @return bool
     */
    public function archiveFileByPath(string $filepath, string $areatag): bool
    {
        $record = $this->getFileRecordByPath($filepath);
        $baseDir = realpath(__DIR__ . '/..');
        $archiveDir = $baseDir . '/data/archive/' . $areatag;

        self::ensureDirectoryExists($archiveDir);

        $filename = basename($filepath);
        $timestamp = date('Ymd_His');
        $targetPath = $archiveDir . '/' . $timestamp . '_' . $filename;

        if (!rename($filepath, $targetPath)) {
            return false;
        }

        if ($record) {
            $stmt = $this->db->prepare("UPDATE files SET status = 'archived', storage_path = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$targetPath, $record['id']]);
            $this->updateFileAreaStats((int)$record['file_area_id']);
        }

        return true;
    }

    /**
     * Get file record by storage path (includes area details)
     *
     * @param string $filepath
     * @return array|null
     */
    public function getFileRecordByPath(string $filepath): ?array
    {
        $realPath = realpath($filepath);
        if (!$realPath) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT f.*, fa.tag as area_tag, fa.domain, fa.replace_existing
            FROM files f
            JOIN file_areas fa ON f.file_area_id = fa.id
            WHERE f.storage_path = ?
            LIMIT 1
        ");
        $stmt->execute([$realPath]);
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
        $result = $stmt->fetch();

        return $result ?: null;
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
        $result = $stmt->fetch();

        return $result ?: null;
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

    /**
     * Get or create a user's private file area
     *
     * @param int $userId User ID
     * @return array File area record
     */
    public function getOrCreatePrivateFileArea(int $userId): array
    {
        // Check if user already has a private file area
        $stmt = $this->db->prepare("
            SELECT * FROM file_areas
            WHERE tag = ? AND is_private = TRUE
            LIMIT 1
        ");
        $tag = "PRIVATE_USER_{$userId}";
        $stmt->execute([$tag]);
        $fileArea = $stmt->fetch();

        if ($fileArea) {
            return $fileArea;
        }

        // Create private file area for user
        $stmt = $this->db->prepare("
            INSERT INTO file_areas (
                tag, description, domain, is_private, is_local, is_active,
                max_file_size, replace_existing,
                created_at, updated_at
            ) VALUES (?, ?, 'private', TRUE, TRUE, TRUE, 104857600, FALSE, NOW(), NOW())
            RETURNING *
        ");

        $description = "Private file area for user {$userId}";
        $stmt->execute([$tag, $description]);
        $fileArea = $stmt->fetch();

        return $fileArea;
    }

    /**
     * Store a netmail attachment
     *
     * @param int $userId User ID (recipient)
     * @param string $filePath Path to file in inbound
     * @param string $filename Original filename
     * @param int|null $messageId Netmail message ID
     * @param string $fromAddress Sender's address
     * @return int File ID
     * @throws \Exception If storage fails
     */
    public function storeNetmailAttachment(int $userId, string $filePath, string $filename, ?int $messageId, string $fromAddress): int
    {
        if (!file_exists($filePath)) {
            throw new \Exception("Attachment file not found: {$filePath}");
        }

        // Get or create user's private file area
        $fileArea = $this->getOrCreatePrivateFileArea($userId);

        // Calculate file info
        $fileSize = filesize($filePath);
        $fileHash = hash_file('sha256', $filePath);

        // Create area directory if needed
        $areaDir = $this->getAreaStorageDir($fileArea);
        self::ensureDirectoryExists($areaDir);

        // Determine storage path (with versioning for duplicates)
        $storagePath = $areaDir . '/' . $filename;
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

        // Move file from inbound to storage
        if (!rename($filePath, $storagePath)) {
            throw new \Exception("Failed to move attachment file");
        }

        chmod($storagePath, 0664);
        $storagePath = realpath($storagePath);

        // Store in database
        $stmt = $this->db->prepare("
            INSERT INTO files (
                file_area_id, filename, filesize, file_hash, storage_path,
                uploaded_from_address, source_type,
                short_description, owner_id, message_id, message_type,
                status, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, 'netmail_attachment',
                ?, ?, ?, 'netmail',
                'approved', NOW()
            ) RETURNING id
        ");

        $shortDescription = "Netmail attachment from {$fromAddress}";

        $stmt->execute([
            $fileArea['id'],
            $filename,
            $fileSize,
            $fileHash,
            $storagePath,
            $fromAddress,
            $shortDescription,
            $userId,
            $messageId,
        ]);

        $result = $stmt->fetch();
        $fileId = $result['id'];

        // Scan for viruses (private file areas should be scanned)
        if (!empty($fileArea['scan_virus'])) {
            $this->scanFileForViruses($fileId, $storagePath);
        }

        // Update file area statistics
        $this->updateFileAreaStats($fileArea['id']);

        return $fileId;
    }

    /**
     * Get files attached to a message
     *
     * @param int $messageId Message ID
     * @param string $messageType 'netmail' or 'echomail'
     * @return array Array of file records
     */
    public function getMessageAttachments(int $messageId, string $messageType): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM files
            WHERE message_id = ? AND message_type = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$messageId, $messageType]);
        return $stmt->fetchAll();
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
     * Get storage directory for a file area
     *
     * @param array $fileArea
     * @return string
     */
    private function getAreaStorageDir(array $fileArea): string
    {
        $tag = $fileArea['tag'] ?? 'AREA';
        $id = $fileArea['id'] ?? null;
        $dirName = $id ? ($tag . '-' . $id) : $tag;
        return __DIR__ . '/../data/files/' . $dirName;
    }

    /**
     * Ensure directory exists with correct permissions for file areas
     *
     * Creates directory with 0775 permissions (rwxrwxr-x) to allow both
     * user and group read/write/execute access
     *
     * @param string $directory Directory path to create
     * @param bool $recursive Create parent directories if needed (default: true)
     * @throws \Exception If directory creation fails
     * @return void
     */
    public static function ensureDirectoryExists(string $directory, bool $recursive = true): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, self::DIR_PERM, $recursive)) {
            throw new \Exception("Failed to create directory: {$directory}");
        }
    }

    // Lazy migration removed; directory changes should be handled explicitly.
}

