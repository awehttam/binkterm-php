<?php

namespace BinktermPHP;

use PDO;

/**
 * FileAreaManager - Manages file areas and files
 *
 * Handles CRUD operations for file areas and file records
 */
class FileAreaManager
{
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
        return $stmt->fetchAll();
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
        $domain = trim($data['domain'] ?? 'fidonet');
        $maxFileSize = intval($data['max_file_size'] ?? 10485760);
        $allowedExtensions = trim($data['allowed_extensions'] ?? '');
        $blockedExtensions = trim($data['blocked_extensions'] ?? '');
        $replaceExisting = (bool)($data['replace_existing'] ?? false);
        $isLocal = (bool)($data['is_local'] ?? false);
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
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ");

        $stmt->execute([
            $tag, $description, $domain, $isLocal ? 1 : 0, $isActive ? 1 : 0,
            $maxFileSize, $allowedExtensions, $blockedExtensions, $replaceExisting ? 1 : 0
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
        $domain = trim($data['domain'] ?? 'fidonet');
        $maxFileSize = intval($data['max_file_size'] ?? 10485760);
        $allowedExtensions = trim($data['allowed_extensions'] ?? '');
        $blockedExtensions = trim($data['blocked_extensions'] ?? '');
        $replaceExisting = (bool)($data['replace_existing'] ?? false);
        $isLocal = (bool)($data['is_local'] ?? false);
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
                replace_existing = ?, updated_at = NOW()
            WHERE id = ?
        ");

        $result = $stmt->execute([
            $tag, $description, $domain, $isLocal ? 1 : 0, $isActive ? 1 : 0,
            $maxFileSize, $allowedExtensions, $blockedExtensions, $replaceExisting ? 1 : 0, $id
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

        // Check for duplicates
        $existingFile = $this->checkDuplicate($fileAreaId, $fileHash);
        if ($existingFile) {
            throw new \Exception('This file already exists in this area');
        }

        // Create area directory if needed
        $filesBasePath = __DIR__ . '/../data/files';
        $areaDir = $filesBasePath . '/' . $fileArea['tag'];
        if (!is_dir($areaDir)) {
            mkdir($areaDir, 0755, true);
        }

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

        chmod($storagePath, 0644);
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

        // Generate TIC files for distribution to uplinks (if not a local area)
        if (empty($fileArea['is_local']) && empty($fileArea['is_private'])) {
            try {
                // Fetch the complete file record for TIC generation
                $fileRecord = $this->getFileById($fileId);

                $ticGenerator = new TicFileGenerator();
                $createdTics = $ticGenerator->createTicFilesForUplinks($fileRecord, $fileArea);

                if (count($createdTics) > 0) {
                    error_log("Generated " . count($createdTics) . " TIC file(s) for file: {$filename}");
                }
            } catch (\Exception $e) {
                // Log error but don't fail the upload
                error_log("Failed to generate TIC files for uploaded file: " . $e->getMessage());
            }
        }

        return $fileId;
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
        $filesBasePath = __DIR__ . '/../data/files';
        $areaDir = $filesBasePath . '/' . $fileArea['tag'];
        if (!is_dir($areaDir)) {
            mkdir($areaDir, 0755, true);
        }

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

        chmod($storagePath, 0644);
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
}
