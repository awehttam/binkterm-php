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
    const DIR_PERM = 02775;      // Directory permissions use 02775 (octal) to ensure group sticky access between web server and local user

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    private function isFreqExperimentalEnabled(): bool
    {
        return Config::env('ENABLE_FREQ_EXPERIMENTAL', 'false') === 'true';
    }

    /**
     * LovlyNet file areas are generally uploadable, except the project release
     * area which must stay read-only.
     */
    public static function getDefaultUploadPermissionForArea(string $tag, string $domain = ''): int
    {
        $normalizedTag = strtoupper(trim($tag));
        $normalizedDomain = strtolower(trim($domain));

        if ($normalizedDomain === 'lovlynet') {
            return $normalizedTag === 'LVLY_BINKTERMPHP'
                ? self::UPLOAD_READ_ONLY
                : self::UPLOAD_USERS_ALLOWED;
        }

        return self::UPLOAD_READ_ONLY;
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
        $sql = "SELECT id, tag, description, domain, is_local, is_active,
                       max_file_size, allowed_extensions, blocked_extensions,
                       replace_existing, allow_duplicate_hash, upload_permission,
                       scan_virus, file_count, total_size, created_at, updated_at,
                       gemini_public, freq_enabled
                FROM file_areas WHERE 1=1";
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
     * Get the private file area for a user if it already exists.
     * Does NOT create it — returns null when the user has no private area yet.
     *
     * @param int $userId
     * @return array|null File area record or null
     */
    public function getPrivateFileArea(int $userId): ?array
    {
        $tag  = 'PRIVATE_USER_' . $userId;
        $stmt = $this->db->prepare("SELECT * FROM file_areas WHERE tag = ? AND is_private = TRUE LIMIT 1");
        $stmt->execute([$tag]);
        $result = $stmt->fetch();
        return $result ?: null;
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

        // Areas flagged is_public allow unauthenticated (guest) access
        if (!empty($area['is_public'])) {
            return true;
        }

        // Non-public areas require a logged-in user
        if ($userId === null) {
            return false;
        }

        return true;
    }

    /**
     * Get a file area by tag and domain
     *
     * @param string $tag File area tag
     * @param string $domain Domain (default: 'fidonet')
     * @return array|null File area record or null if not found
     */
    public function getFileAreaByTag(string $tag, string $domain = ''): ?array
    {
        if ($domain === '') {
            $stmt = $this->db->prepare("SELECT * FROM file_areas WHERE tag = ? LIMIT 1");
            $stmt->execute([$tag]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM file_areas WHERE tag = ? AND domain = ?");
            $stmt->execute([$tag, $domain]);
        }
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create a file area if one with the same tag/domain does not already exist.
     *
     * @param array $data File area data
     * @return int Existing or newly created file area ID
     */
    public function createIfMissing(array $data): int
    {
        $tag = strtoupper(trim((string)($data['tag'] ?? '')));
        if ($tag === '') {
            throw new \InvalidArgumentException('File area tag is required');
        }

        $domain = trim((string)($data['domain'] ?? ''));
        $existing = $this->getFileAreaByTag($tag, $domain);
        if ($existing) {
            return (int)$existing['id'];
        }

        $defaults = [
            'tag' => $tag,
            'description' => trim((string)($data['description'] ?? '')) ?: $tag,
            'domain' => $domain,
            'is_local' => false,
            'is_active' => true,
            'upload_permission' => self::getDefaultUploadPermissionForArea($tag, $domain),
            'replace_existing' => true,
            'allow_duplicate_hash' => false,
            'scan_virus' => true,
            'max_file_size' => 10485760,
            'allowed_extensions' => '',
            'blocked_extensions' => '',
            'password' => null,
            'area_type' => 'normal',
            'gemini_public' => false,
        ];

        return $this->createFileArea(array_merge($defaults, $data, [
            'tag' => $tag,
            'domain' => $domain,
        ]));
    }

    /**
     * @param array<int, array<string, mixed>> $areas
     * @return array<int, array<string, mixed>>
     */
    public function annotateAreasWithLocalStatus(array $areas, array $domains = []): array
    {
        if ($areas === []) {
            return $areas;
        }

        $tags = array_values(array_unique(array_filter(array_map(static function ($area) {
            return strtoupper(trim((string)($area['tag'] ?? '')));
        }, $areas))));

        if ($tags === []) {
            return $areas;
        }

        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        [$domainClause, $domainParams] = $this->buildDomainWhereClause($domains);
        $stmt = $this->db->prepare("
            SELECT UPPER(tag) AS tag_key, id, domain, description
            FROM file_areas
            WHERE UPPER(tag) IN ($placeholders)
              AND {$domainClause}
        ");
        $stmt->execute(array_merge($tags, $domainParams));
        $localRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $localByTag = [];
        foreach ($localRows as $row) {
            $tagKey = (string)($row['tag_key'] ?? '');
            if ($tagKey !== '' && !isset($localByTag[$tagKey])) {
                $localByTag[$tagKey] = $row;
            }
        }

        foreach ($areas as &$area) {
            $tagKey = strtoupper(trim((string)($area['tag'] ?? '')));
            $local = $localByTag[$tagKey] ?? null;
            $area['local_exists'] = $local !== null;
            $area['local_filearea_id'] = $local !== null ? (int)$local['id'] : null;
            $area['local_domain'] = $local['domain'] ?? null;
            $area['local_description'] = $local['description'] ?? null;
            $remoteDescription = trim((string)($area['description'] ?? ''));
            $localDescription = trim((string)($local['description'] ?? ''));
            $area['description_mismatch'] = $local !== null && $remoteDescription !== '' && $remoteDescription !== $localDescription;
        }
        unset($area);

        return $areas;
    }

    public function updateDescription(int $id, string $description): bool
    {
        if ($id <= 0) {
            return false;
        }

        $normalizedDescription = trim($description);
        if ($normalizedDescription === '') {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE file_areas SET description = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$normalizedDescription, $id]);
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
     * @return array{0:string,1:array<int,string>}
     */
    private function buildDomainWhereClause(array $domains): array
    {
        $normalized = [];
        foreach ($domains as $domain) {
            $value = strtolower(trim((string)$domain));
            if ($value === '') {
                $normalized[] = '';
            } elseif (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if ($normalized === []) {
            return ['1=1', []];
        }

        $parts = [];
        $params = [];
        foreach ($normalized as $domain) {
            if ($domain === '') {
                $parts[] = "(domain IS NULL OR domain = '')";
            } else {
                $parts[] = "LOWER(domain) = ?";
                $params[] = $domain;
            }
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * Resolve the filesystem path for a file record.
     *
     * For ISO-imported files the path is reconstructed from the area's current
     * mount point and the file's relative path. For normal files the stored
     * storage_path is returned unchanged.
     *
     * @param array $file   File record from files table (must include file_area_id, source_type, iso_rel_path, storage_path)
     * @return string       Absolute filesystem path
     */
    public function resolveFilePath(array $file): string
    {
        if (($file['source_type'] ?? '') === 'iso_import' && !empty($file['iso_rel_path'])) {
            $area = $this->getFileAreaById($file['file_area_id']);
            if ($area && !empty($area['iso_mount_point'])) {
                return rtrim($area['iso_mount_point'], '/') . '/' . ltrim($file['iso_rel_path'], '/');
            }
        }
        return $file['storage_path'];
    }

    /**
     * Update the ISO last-indexed timestamp for a file area.
     *
     * @param int $id File area ID
     */
    public function updateIsoLastIndexed(int $id): void
    {
        $this->db->prepare("UPDATE file_areas SET iso_last_indexed = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$id]);
    }

    /**
     * Dry-run scan of an ISO-backed file area.
     *
     * Returns one entry per discovered subdirectory (not individual files).
     * Each entry includes the catalogue description, current DB description,
     * approximate file count, and whether the directory is already indexed.
     *
     * @param int  $areaId
     * @param bool $flat          When true, all files are at root — no directory entries
     * @param bool $catalogueOnly When true, only import files listed in a catalogue (FILES.BBS etc.)
     * @return array{entries: array, summary: array}
     * @throws \Exception
     */
    public function previewIsoImport(int $areaId, bool $flat = false, bool $catalogueOnly = false): array
    {
        $area = $this->getFileAreaById($areaId);
        if (!$area) {
            throw new \Exception("File area {$areaId} not found");
        }
        if (($area['area_type'] ?? 'normal') !== 'iso') {
            throw new \Exception("File area {$areaId} is not ISO-backed");
        }
        $mountPoint = rtrim($area['iso_mount_point'] ?? '', '/\\');
        if (empty($mountPoint) || !is_dir($mountPoint)) {
            throw new \Exception("ISO is not mounted");
        }

        $catalogueNames = ['FILES.BBS', 'DESCRIPT.ION', 'FILE_LIST.BBS', '00INDEX.TXT', '00_INDEX.TXT', 'INDEX.TXT'];
        $entries = [];
        $this->collectPreviewDirs($mountPoint, $mountPoint, $areaId, $catalogueNames, $flat, $catalogueOnly, $entries);

        $newDirs = $existingDirs = $totalFiles = 0;
        foreach ($entries as $e) {
            $e['status'] === 'new' ? $newDirs++ : $existingDirs++;
            $totalFiles += $e['file_count'];
        }

        return [
            'entries' => $entries,
            'summary' => [
                'new_dirs'      => $newDirs,
                'existing_dirs' => $existingDirs,
                'total_files'   => $totalFiles,
            ],
        ];
    }

    /**
     * Recursively collect directory entries for ISO preview (no DB writes).
     */
    private function collectPreviewDirs(
        string $dirPath,
        string $mountPoint,
        int $areaId,
        array $catalogueNames,
        bool $flat,
        bool $catalogueOnly,
        array &$entries
    ): void {
        $relDir = ltrim(str_replace(['/', '\\'], '/', substr($dirPath, strlen($mountPoint))), '/');

        if ($relDir !== '' && !$flat) {
            $dirName   = basename($relDir);
            $catalogue = $this->loadIsoCatalogue($dirPath, $catalogueNames);
            $catDesc   = $this->toUtf8($catalogue[strtolower($dirName)][0] ?? '') ?: $dirName;
            $catDesc   = mb_substr($catDesc, 0, 255);

            // Check for existing iso_subdir record
            $stmt = $this->db->prepare("
                SELECT short_description FROM files
                WHERE file_area_id = ? AND source_type = 'iso_subdir' AND iso_rel_path = ?
                LIMIT 1
            ");
            $stmt->execute([$areaId, $relDir]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Count immediate files in this directory (respecting catalogue_only filter)
            $fileCount = 0;
            $hasCatalogue = !empty($catalogue);
            foreach (@scandir($dirPath) ?: [] as $f) {
                if ($f === '.' || $f === '..' || !is_file($dirPath . DIRECTORY_SEPARATOR . $f)) {
                    continue;
                }
                if ($catalogueOnly && $hasCatalogue && !isset($catalogue[strtolower($f)])) {
                    continue;
                }
                $fileCount++;
            }

            $entries[] = [
                'rel_path'              => $relDir,
                'name'                  => $dirName,
                'catalogue_description' => $catDesc,
                'db_description'        => $existing ? ($existing['short_description'] ?? null) : null,
                'status'                => $existing ? 'existing' : 'new',
                'file_count'            => $fileCount,
                'has_catalogue'         => $hasCatalogue,
            ];
        }

        // Recurse into subdirectories
        foreach (@scandir($dirPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $dirPath . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($fullPath)) {
                $this->collectPreviewDirs($fullPath, $mountPoint, $areaId, $catalogueNames, $flat, $catalogueOnly, $entries);
            }
        }
    }

    /**
     * Import/re-index files from a mounted ISO-backed file area.
     *
     * Walks the mount point directory tree, reads FILES.BBS / DESCRIPT.ION
     * catalogues, and inserts or updates file records in the database.
     *
     * @param int    $areaId        File area ID
     * @param bool   $update        Update descriptions for already-imported files
     * @param string|null $filterDir Only scan this subdirectory of the mount point
     * @param bool   $flat          If true, import all files without subfolder grouping
     * @param array  $overrides     Per-directory overrides keyed by rel_path
     * @param bool   $catalogueOnly If true, only import files listed in a catalogue (FILES.BBS etc.)
     * @return array {imported: int, updated: int, skipped: int, no_description: int, errors: int}
     * @throws \Exception If the area is not found, not ISO-backed, or not mounted
     */
    public function importIsoFiles(int $areaId, bool $update = false, ?string $filterDir = null, bool $flat = false, array $overrides = [], bool $catalogueOnly = false): array
    {
        $area = $this->getFileAreaById($areaId);
        if (!$area) {
            throw new \Exception("File area {$areaId} not found");
        }
        if (($area['area_type'] ?? 'normal') !== 'iso') {
            throw new \Exception("File area {$areaId} is not an ISO-backed area");
        }

        $mountPoint = rtrim($area['iso_mount_point'] ?? '', '/\\');
        if (empty($mountPoint) || !is_dir($mountPoint)) {
            throw new \Exception("ISO area is not mounted (mount_point: '{$mountPoint}')");
        }

        $scanRoot = $filterDir
            ? rtrim($mountPoint, '/\\') . DIRECTORY_SEPARATOR . ltrim($filterDir, '/\\')
            : $mountPoint;

        if (!is_dir($scanRoot)) {
            throw new \Exception("Scan directory not found: {$scanRoot}");
        }

        $catalogueNames = ['FILES.BBS', 'DESCRIPT.ION', 'FILE_LIST.BBS', '00INDEX.TXT', '00_INDEX.TXT', 'INDEX.TXT'];
        $counters = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'no_description' => 0, 'errors' => 0];

        $this->scanIsoDirectory($scanRoot, $mountPoint, $areaId, $catalogueNames, $update, $counters, $flat, $overrides, $catalogueOnly);
        $this->updateIsoLastIndexed($areaId);
        $this->updateFileAreaStats($areaId);

        return $counters;
    }

    /**
     * Recursively scan a directory and import files into the database.
     *
     * @param bool $flat  If true, all files are stored without subfolder grouping
     */
    private function scanIsoDirectory(
        string $dirPath,
        string $mountPoint,
        int $areaId,
        array $catalogueNames,
        bool $update,
        array &$counters,
        bool $flat = false,
        array $overrides = [],
        bool $catalogueOnly = false
    ): void {
        $catalogue = $this->loadIsoCatalogue($dirPath, $catalogueNames);

        $relDir    = ltrim(str_replace(['/', '\\'], '/', substr($dirPath, strlen($mountPoint))), '/');
        $subfolder = ($flat || $relDir === '') ? null : $relDir;

        // If this directory is marked skip, omit its subdir record and files but still
        // recurse so that selected child directories (e.g. games/apogee) are imported.
        $override = $relDir !== '' ? ($overrides[$relDir] ?? null) : null;
        $skipThis = $override && !empty($override['skip']);

        $insertStmt = $this->db->prepare("
            INSERT INTO files (
                filename, short_description, long_description,
                file_area_id, storage_path, filesize, file_hash,
                iso_rel_path, subfolder, source_type, status,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'iso_import', 'approved', ?, NOW())
            ON CONFLICT DO NOTHING
        ");

        $updateStmt = $this->db->prepare("
            UPDATE files
            SET short_description = CASE WHEN (short_description IS NULL OR short_description = filename) THEN ? ELSE short_description END,
                long_description  = CASE WHEN long_description IS NULL THEN ? ELSE long_description END,
                storage_path = ?, filesize = ?, updated_at = NOW()
            WHERE file_area_id = ? AND iso_rel_path = ?
        ");

        $updateFlatStmt = $this->db->prepare("
            UPDATE files
            SET short_description = CASE WHEN (short_description IS NULL OR short_description = filename) THEN ? ELSE short_description END,
                long_description  = CASE WHEN long_description IS NULL THEN ? ELSE long_description END,
                storage_path = ?, filesize = ?, subfolder = NULL, updated_at = NOW()
            WHERE file_area_id = ? AND iso_rel_path = ?
        ");

        $checkStmt = $this->db->prepare("
            SELECT id FROM files WHERE file_area_id = ? AND iso_rel_path = ? LIMIT 1
        ");

        $entries = @scandir($dirPath);
        if ($entries === false) {
            return;
        }

        // Upsert an iso_subdir record for this directory itself (skip the ISO root).
        // This lets admins set a human-readable description on each subfolder.
        if ($relDir !== '' && !$flat && !$skipThis) {
            $dirName    = basename($relDir);
            $parentDir  = dirname($relDir);
            $parentSubfolder = ($parentDir === '.' || $parentDir === '') ? null : $parentDir;
            $descKey    = strtolower($dirName);
            $dirDesc    = $this->toUtf8($catalogue[$descKey][0] ?? '');
            if ($dirDesc === '') {
                $dirDesc = $dirName;
            }
            $dirDesc = mb_substr($dirDesc, 0, 255);

            // Override description from user-submitted preview
            if ($override && isset($override['description']) && $override['description'] !== '') {
                $dirDesc = mb_substr($override['description'], 0, 255);
            }

            $existingStmt = $this->db->prepare(
                "SELECT id FROM files WHERE file_area_id = ? AND iso_rel_path = ? AND source_type = 'iso_subdir' LIMIT 1"
            );
            $existingStmt->execute([$areaId, $relDir]);
            $existingRow = $existingStmt->fetch();
            if (!$existingRow) {
                $ins = $this->db->prepare("
                    INSERT INTO files (
                        filename, short_description, file_area_id,
                        storage_path, filesize, file_hash, iso_rel_path, subfolder,
                        source_type, status, created_at, updated_at
                    ) VALUES (?, ?, ?, '', 0, '', ?, ?, 'iso_subdir', 'approved', NOW(), NOW())
                    ON CONFLICT DO NOTHING
                ");
                $ins->execute([$dirName, $dirDesc, $areaId, $relDir, $parentSubfolder]);
            } elseif ($override && isset($override['description'])) {
                // User explicitly set a description in the preview — always apply it
                $upd = $this->db->prepare(
                    "UPDATE files SET short_description = ?, updated_at = NOW() WHERE file_area_id = ? AND iso_rel_path = ? AND source_type = 'iso_subdir'"
                );
                $upd->execute([$dirDesc, $areaId, $relDir]);
            }

            // Ensure all ancestor directories have iso_subdir records so that
            // navigation works even when a parent dir was skipped during import.
            $this->ensureAncestorSubdirs($areaId, $relDir);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $dirPath . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($fullPath)) {
                $this->scanIsoDirectory($fullPath, $mountPoint, $areaId, $catalogueNames, $update, $counters, $flat, $overrides, $catalogueOnly);
                continue;
            }

            // Skip files in a directory that was unchecked (but recursion above still ran)
            if ($skipThis) {
                continue;
            }

            // Skip catalogue files
            foreach ($catalogueNames as $catName) {
                if (strtolower($entry) === strtolower($catName)) {
                    continue 2;
                }
            }

            if (!is_file($fullPath)) {
                continue;
            }

            $descKey   = strtolower($entry);

            // In catalogue-only mode, skip files not listed in the catalogue
            // (only when a catalogue actually exists; if there's no catalogue, import all)
            if ($catalogueOnly && !empty($catalogue) && !isset($catalogue[$descKey])) {
                $counters['skipped']++;
                continue;
            }

            $relPath   = ($relDir !== '' ? $relDir . '/' : '') . $entry;
            $fileSize  = @filesize($fullPath) ?: 0;
            $fileHash  = hash_file('sha256', $fullPath);
            $fileMtime = @filemtime($fullPath);
            $fileDate  = $fileMtime ? date('Y-m-d H:i:sO', $fileMtime) : null;

            $shortDesc = $this->toUtf8($catalogue[$descKey][0] ?? '');
            $longDesc  = $this->toUtf8($catalogue[$descKey][1] ?? '');
            if ($shortDesc === '') {
                $shortDesc = $entry;
                $counters['no_description']++;
            }
            $shortDesc = mb_substr($shortDesc, 0, 255);

            $checkStmt->execute([$areaId, $relPath]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                if ($update) {
                    $stmt = $flat ? $updateFlatStmt : $updateStmt;
                    $stmt->execute([$shortDesc, $longDesc ?: null, $fullPath, $fileSize, $areaId, $relPath]);
                    $counters['updated']++;
                } else {
                    $counters['skipped']++;
                }
            } else {
                try {
                    $insertStmt->execute([
                        $entry, $shortDesc, $longDesc ?: null,
                        $areaId, $fullPath, $fileSize, $fileHash,
                        $relPath, $subfolder, $fileDate,
                    ]);
                    $counters[$insertStmt->rowCount() > 0 ? 'imported' : 'skipped']++;
                } catch (\Exception $e) {
                    error_log("[IsoImport] Error importing {$relPath}: " . $e->getMessage());
                    $counters['errors']++;
                }
            }
        }
    }

    /**
     * Ensure iso_subdir records exist for every ancestor of $relDir.
     *
     * When a child directory is imported but its parent was skipped, the parent
     * has no iso_subdir record and becomes invisible to the folder navigator.
     * This method walks up the path and inserts minimal placeholder records for
     * any ancestors that don't already exist, using the directory name as the
     * description so they are at least navigable.
     *
     * @param int    $areaId  File area ID
     * @param string $relDir  Relative path of the directory just imported (e.g. "games/apogee")
     */
    private function ensureAncestorSubdirs(int $areaId, string $relDir): void
    {
        $parts = explode('/', $relDir);
        array_pop($parts); // ancestors only — the dir itself is handled by the caller

        $path = '';
        foreach ($parts as $part) {
            $path = $path === '' ? $part : $path . '/' . $part;
            $parentPath = dirname($path);
            $parentSubfolder = ($parentPath === '.' || $parentPath === '') ? null : $parentPath;

            $check = $this->db->prepare(
                "SELECT id FROM files WHERE file_area_id = ? AND iso_rel_path = ? AND source_type = 'iso_subdir' LIMIT 1"
            );
            $check->execute([$areaId, $path]);
            if (!$check->fetch()) {
                $ins = $this->db->prepare("
                    INSERT INTO files (
                        filename, short_description, file_area_id,
                        storage_path, filesize, file_hash, iso_rel_path, subfolder,
                        source_type, status, created_at, updated_at
                    ) VALUES (?, ?, ?, '', 0, '', ?, ?, 'iso_subdir', 'approved', NOW(), NOW())
                    ON CONFLICT DO NOTHING
                ");
                $ins->execute([basename($path), basename($path), $areaId, $path, $parentSubfolder]);
            }
        }
    }

    /**
     * Load a FILES.BBS or DESCRIPT.ION catalogue from a directory.
     *
     * @return array filename_lowercase => [short_desc, long_desc]
     */
    private function loadIsoCatalogue(string $dirPath, array $catalogueNames): array
    {
        foreach ($catalogueNames as $catalogueName) {
            foreach (@scandir($dirPath) ?: [] as $entry) {
                if (strtolower($entry) !== strtolower($catalogueName)) {
                    continue;
                }
                $content = @file_get_contents($dirPath . DIRECTORY_SEPARATOR . $entry);
                if ($content === false) {
                    continue;
                }
                $lower = strtolower($catalogueName);
                if ($lower === 'descript.ion') {
                    $parsed = $this->parseDescriptIon($content);
                } elseif ($lower === '00_index.txt') {
                    $parsed = $this->parseZeroZeroIndex($content);
                } else {
                    $parsed = $this->parseFilesBbs($content);
                }
                // Only use this catalogue if it produced entries; otherwise
                // fall through to the next catalogue name so that e.g. an
                // empty FILES.BBS doesn't block a populated 00_INDEX.TXT.
                if (!empty($parsed)) {
                    return $parsed;
                }
            }
        }
        return [];
    }

    /**
     * Parse a FILES.BBS catalogue.
     *
     * @return array filename_lowercase => [short_desc, long_desc]
     */
    /**
     * Convert a string to valid UTF-8.
     * Attempts CP437→UTF-8 conversion first (common on DOS-era CDs),
     * then falls back to stripping any remaining invalid bytes.
     */
    private function toUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // If already valid UTF-8, return as-is
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        // Try CP437 → UTF-8
        $converted = @iconv('CP437', 'UTF-8//IGNORE', $text);
        if ($converted !== false && $converted !== '') {
            return $converted;
        }
        // Last resort: strip any byte sequence PostgreSQL would reject
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    private function parseFilesBbs(string $content): array
    {
        $entries  = [];
        $lastKey  = null;
        foreach (preg_split('/\r?\n/', $content) as $line) {
            if (preg_match('/^[;-]/', $line) || trim($line) === '') {
                continue;
            }
            // Continuation line
            if (preg_match('/^[ \t]+(\S.*)$/', $line, $m) && $lastKey !== null) {
                $entries[$lastKey][1] = trim(($entries[$lastKey][1] ?? '') . "\n" . trim($m[1]));
                continue;
            }
            // Normal entry: FILENAME  Description
            if (preg_match('/^(\S+)(?:\t|  +)(.*)$/', $line, $m)) {
                $lastKey           = strtolower($m[1]);
                $entries[$lastKey] = [trim($m[2]), ''];
            }
        }
        return $entries;
    }

    /**
     * Parse a DESCRIPT.ION catalogue.
     *
     * @return array filename_lowercase => [short_desc, long_desc]
     */
    private function parseDescriptIon(string $content): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\S+)\s+(.+)$/', $line, $m)) {
                $entries[strtolower($m[1])] = [trim($m[2], '"'), ''];
            }
        }
        return $entries;
    }

    /**
     * Parse a 00_INDEX.TXT catalogue (Simtel-style).
     *
     * Format:
     *   Directory: path/to/dir
     *
     *   File: filename.zip
     *
     *       Multi-line description text indented with spaces.
     *
     * @return array filename_lowercase => [short_desc, long_desc]
     */
    private function parseZeroZeroIndex(string $content): array
    {
        $entries = [];
        $currentFile = null;
        $descLines = [];

        foreach (preg_split('/\r?\n/', $content) as $line) {
            // New file entry
            if (preg_match('/^File:\s+(\S+)/i', $line, $m)) {
                if ($currentFile !== null) {
                    $desc = trim(implode("\n", $descLines));
                    $short = $desc !== '' ? (explode("\n", $desc)[0]) : '';
                    $entries[$currentFile] = [$short, $desc];
                }
                $currentFile = strtolower($m[1]);
                $descLines = [];
                continue;
            }
            // Directory header — skip
            if (preg_match('/^Directory:/i', $line)) {
                continue;
            }
            // Description line (indented or blank continuation)
            if ($currentFile !== null) {
                $descLines[] = trim($line);
            }
        }

        // Flush last entry
        if ($currentFile !== null) {
            $desc = trim(implode("\n", $descLines));
            $short = $desc !== '' ? (explode("\n", $desc)[0]) : '';
            $entries[$currentFile] = [$short, $desc];
        }

        return $entries;
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
        $password = trim((string)($data['password'] ?? ''));
        $password = $password === '' ? null : $password;

        // ISO fields
        $areaType      = in_array($data['area_type'] ?? 'normal', ['normal', 'iso']) ? ($data['area_type'] ?? 'normal') : 'normal';
        $isoMountPoint = $areaType === 'iso' ? (trim($data['iso_mount_point'] ?? '') ?: null) : null;
        // Force read-only for ISO areas
        if ($areaType === 'iso') {
            $uploadPermission = self::UPLOAD_READ_ONLY;
        }

        if (empty($tag) || empty($description)) {
            throw new \Exception('Tag and description are required');
        }

        // Check for duplicate
        if ($this->getFileAreaByTag($tag, $domain)) {
            throw new \Exception('File area with this tag already exists in this domain');
        }

        $geminiPublic = (bool)($data['gemini_public'] ?? false);
        if ($this->isFreqExperimentalEnabled()) {
            $freqEnabled  = (bool)($data['freq_enabled'] ?? false);
            $freqPassword = trim((string)($data['freq_password'] ?? ''));
            $freqPassword = $freqPassword === '' ? null : $freqPassword;
        } else {
            $freqEnabled = false;
            $freqPassword = null;
        }

        $commentEchoareaId = isset($data['comment_echoarea_id']) && $data['comment_echoarea_id'] !== '' && $data['comment_echoarea_id'] !== null
            ? (int)$data['comment_echoarea_id']
            : null;

        $stmt = $this->db->prepare("
            INSERT INTO file_areas (
                tag, description, domain, is_local, is_active,
                max_file_size, allowed_extensions, blocked_extensions, replace_existing,
                allow_duplicate_hash, password,
                upload_permission, scan_virus, gemini_public, freq_enabled, freq_password,
                area_type, iso_mount_point, comment_echoarea_id,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ");

        $stmt->execute([
            $tag, $description, $domain, $isLocal ? 1 : 0, $isActive ? 1 : 0,
            $maxFileSize, $allowedExtensions, $blockedExtensions, $replaceExisting ? 1 : 0,
            $allowDuplicateHash ? 1 : 0, $password,
            $uploadPermission, $scanVirus ? 1 : 0, $geminiPublic ? 'true' : 'false',
            $freqEnabled ? 'true' : 'false', $freqPassword,
            $areaType, $isoMountPoint, $commentEchoareaId
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
        // Tag is immutable after creation — always use the stored value.
        $currentArea = $this->getFileAreaById($id);
        if (!$currentArea) {
            throw new \Exception('File area not found');
        }
        $tag = $currentArea['tag'];

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
        $password = trim((string)($data['password'] ?? ''));
        $password = $password === '' ? null : $password;

        if (empty($description)) {
            throw new \Exception('Description is required');
        }
        $areaType    = in_array($data['area_type'] ?? $currentArea['area_type'] ?? 'normal', ['normal', 'iso'])
            ? ($data['area_type'] ?? $currentArea['area_type'] ?? 'normal')
            : 'normal';

        $manualMountPoint = $areaType === 'iso' && array_key_exists('iso_mount_point', $data)
            ? (trim($data['iso_mount_point'] ?? '') ?: null)
            : null; // null = not supplied, don't touch existing value

        // Force read-only for ISO areas
        if ($areaType === 'iso') {
            $uploadPermission = self::UPLOAD_READ_ONLY;
        }

        $geminiPublic = (bool)($data['gemini_public'] ?? false);
        $isPublic = \BinktermPHP\License::isValid() ? (bool)($data['is_public'] ?? false) : false;
        if ($this->isFreqExperimentalEnabled()) {
            $freqEnabled  = (bool)($data['freq_enabled'] ?? false);
            $freqPassword = trim((string)($data['freq_password'] ?? ''));
            $freqPassword = $freqPassword === '' ? null : $freqPassword;
        } else {
            $freqEnabled = !empty($currentArea['freq_enabled']);
            $freqPassword = trim((string)($currentArea['freq_password'] ?? ''));
            $freqPassword = $freqPassword === '' ? null : $freqPassword;
        }

        // Only touch iso_mount_point when the sysop explicitly provided a value.
        $mountCols   = '';
        $mountParams = [];
        if ($manualMountPoint !== null) {
            $mountCols   = ', iso_mount_point = ?';
            $mountParams = [$manualMountPoint];
        } elseif ($areaType === 'normal' && ($currentArea['area_type'] ?? 'normal') === 'iso') {
            // Area type changed from iso → normal: clear mount point
            $mountCols = ', iso_mount_point = NULL';
        }

        // comment_echoarea_id: only update if key is present in the input data
        $commentEchoareaCols   = '';
        $commentEchoareaParams = [];
        if (array_key_exists('comment_echoarea_id', $data)) {
            $commentEchoareaId = ($data['comment_echoarea_id'] !== null && $data['comment_echoarea_id'] !== '')
                ? (int)$data['comment_echoarea_id']
                : null;
            $commentEchoareaCols   = ', comment_echoarea_id = ?';
            $commentEchoareaParams = [$commentEchoareaId];
        }

        $sql = "
            UPDATE file_areas
            SET tag = ?, description = ?, domain = ?, is_local = ?, is_active = ?,
                max_file_size = ?, allowed_extensions = ?, blocked_extensions = ?,
                replace_existing = ?, allow_duplicate_hash = ?, password = ?,
                upload_permission = ?, scan_virus = ?, gemini_public = ?, is_public = ?,
                freq_enabled = ?, freq_password = ?,
                area_type = ?
                {$mountCols}
                {$commentEchoareaCols},
                updated_at = NOW()
            WHERE id = ?
        ";

        $params = [
            $tag, $description, $domain, $isLocal ? 1 : 0, $isActive ? 1 : 0,
            $maxFileSize, $allowedExtensions, $blockedExtensions, $replaceExisting ? 1 : 0,
            $allowDuplicateHash ? 1 : 0, $password,
            $uploadPermission, $scanVirus ? 1 : 0, $geminiPublic ? 'true' : 'false', $isPublic ? 'true' : 'false',
            $freqEnabled ? 'true' : 'false', $freqPassword,
            $areaType,
            ...$mountParams,
            ...$commentEchoareaParams,
            $id
        ];

        $stmt   = $this->db->prepare($sql);
        $result = $stmt->execute($params);

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
     * Get most recently uploaded files across all active file areas
     *
     * @param int $limit Maximum number of files to return
     * @return array Array of files ordered by upload date descending
     */
    /**
     * Return recent files for the activity feed.
     *
     * Regular (non-ISO) files are returned individually.
     * ISO subfolder files are deduplicated at the SQL level so that each
     * (file_area_id, subfolder) combination contributes at most one row —
     * preventing a single large ISO import from flooding the list.
     *
     * @param int $limit Maximum rows to return
     * @return array
     */
    public function getRecentFiles(int $limit = 25): array
    {
        $sharedJoin = "
            LEFT JOIN shared_files sf ON sf.file_id = f.id
                AND sf.is_active = TRUE
                AND (sf.expires_at IS NULL OR sf.expires_at > NOW())
        ";
        $areaFilter = "
            AND fa.is_active = TRUE
            AND (fa.is_private = FALSE OR fa.is_private IS NULL)
        ";

        $stmt = $this->db->prepare("
            WITH regular AS (
                SELECT f.*, fa.tag AS area_tag, fa.domain, fa.is_local,
                       CASE WHEN sf.id IS NOT NULL THEN TRUE ELSE FALSE END AS is_shared,
                       NULL::text AS subfolder_label
                FROM files f
                JOIN file_areas fa ON f.file_area_id = fa.id
                {$sharedJoin}
                WHERE f.status = 'approved'
                  {$areaFilter}
                  AND (f.source_type IS DISTINCT FROM 'iso_subdir')
                  AND (f.source_type IS DISTINCT FROM 'iso_import' OR f.subfolder IS NULL)
            ),
            iso_subdirs AS (
                SELECT DISTINCT ON (f.file_area_id, f.subfolder)
                       f.*, fa.tag AS area_tag, fa.domain, fa.is_local,
                       CASE WHEN sf.id IS NOT NULL THEN TRUE ELSE FALSE END AS is_shared,
                       m.short_description AS subfolder_label
                FROM files f
                JOIN file_areas fa ON f.file_area_id = fa.id
                {$sharedJoin}
                LEFT JOIN files m ON m.file_area_id = f.file_area_id
                                 AND m.source_type = 'iso_subdir'
                                 AND m.iso_rel_path = f.subfolder
                                 AND m.status = 'approved'
                WHERE f.status = 'approved'
                  {$areaFilter}
                  AND f.source_type = 'iso_import'
                  AND f.subfolder IS NOT NULL
                ORDER BY f.file_area_id, f.subfolder, f.created_at DESC
            )
            SELECT * FROM (
                SELECT * FROM regular
                UNION ALL
                SELECT * FROM iso_subdirs
            ) combined
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get files in a file area
     *
     * @param int $areaId File area ID
     * @return array Array of files
     */
    /**
     * Get files in a file area, optionally filtered by subfolder.
     *
     * @param int         $areaId    File area ID
     * @param string|null $subfolder Subfolder name, or NULL for root-level files only
     * @param bool        $allDepths When true, return all files regardless of subfolder
     * @return array
     */
    public function getFiles(int $areaId, ?string $subfolder = null, bool $allDepths = false): array
    {
        if ($allDepths) {
            $sql = "
                SELECT f.*, fa.tag as area_tag,
                       CASE WHEN sf.id IS NOT NULL THEN TRUE ELSE FALSE END AS is_shared
                FROM files f
                JOIN file_areas fa ON f.file_area_id = fa.id
                LEFT JOIN shared_files sf ON sf.file_id = f.id
                    AND sf.is_active = TRUE
                    AND (sf.expires_at IS NULL OR sf.expires_at > NOW())
                WHERE f.file_area_id = ? AND f.status = 'approved'
                  AND (f.source_type IS DISTINCT FROM 'iso_subdir')
                ORDER BY f.subfolder NULLS FIRST, f.filename ASC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$areaId]);
        } elseif ($subfolder === null) {
            $sql = "
                SELECT f.*, fa.tag as area_tag,
                       CASE WHEN sf.id IS NOT NULL THEN TRUE ELSE FALSE END AS is_shared
                FROM files f
                JOIN file_areas fa ON f.file_area_id = fa.id
                LEFT JOIN shared_files sf ON sf.file_id = f.id
                    AND sf.is_active = TRUE
                    AND (sf.expires_at IS NULL OR sf.expires_at > NOW())
                WHERE f.file_area_id = ? AND f.status = 'approved' AND f.subfolder IS NULL
                  AND (f.source_type IS DISTINCT FROM 'iso_subdir')
                ORDER BY f.created_at DESC, f.filename ASC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$areaId]);
        } else {
            $sql = "
                SELECT f.*, fa.tag as area_tag,
                       CASE WHEN sf.id IS NOT NULL THEN TRUE ELSE FALSE END AS is_shared
                FROM files f
                JOIN file_areas fa ON f.file_area_id = fa.id
                LEFT JOIN shared_files sf ON sf.file_id = f.id
                    AND sf.is_active = TRUE
                    AND (sf.expires_at IS NULL OR sf.expires_at > NOW())
                WHERE f.file_area_id = ? AND f.status = 'approved' AND f.subfolder = ?
                  AND (f.source_type IS DISTINCT FROM 'iso_subdir')
                ORDER BY f.created_at DESC, f.filename ASC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$areaId, $subfolder]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Get distinct subfolders that exist within a file area.
     *
     * @param int $areaId File area ID
     * @return string[] Sorted list of subfolder names (never includes NULL)
     */
    /**
     * Return the display label for a subfolder: the iso_subdir short_description if set
     * and different from the raw directory name, otherwise the raw directory name.
     *
     * @param int    $areaId    File area ID
     * @param string $subfolder Subfolder path
     * @return string
     */
    public function getSubfolderLabel(int $areaId, string $subfolder): string
    {
        $stmt = $this->db->prepare("
            SELECT short_description FROM files
            WHERE file_area_id = ? AND source_type = 'iso_subdir' AND iso_rel_path = ?
            LIMIT 1
        ");
        $stmt->execute([$areaId, $subfolder]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $dirName = basename($subfolder);
        if ($row && !empty($row['short_description']) && $row['short_description'] !== $subfolder && $row['short_description'] !== $dirName) {
            return $row['short_description'];
        }
        return $dirName;
    }

    /**
     * Get immediate child subfolders at the given parent path within a file area.
     *
     * At root ($parentPath = null) returns only top-level folders (no '/' in path).
     * When $parentPath is set returns only direct children of that path, so
     * 'msdos' returns 'msdos/util' but not 'msdos/util/sub'.
     *
     * @param int         $areaId     File area ID
     * @param string|null $parentPath Current directory path, or null for root
     * @return array[] Array of ['subfolder' => string, 'description' => string|null, 'subdir_id' => int|null]
     */
    public function getSubfolders(int $areaId, ?string $parentPath = null): array
    {
        if ($parentPath === null) {
            $whereFiles  = "AND f.subfolder NOT LIKE '%/%'";
            $whereSubdir = "AND s.iso_rel_path NOT LIKE '%/%'";
            $params      = [$areaId, $areaId];
        } else {
            $prefix      = $parentPath . '/';
            $whereFiles  = "AND f.subfolder LIKE ? AND f.subfolder NOT LIKE ?";
            $whereSubdir = "AND s.iso_rel_path LIKE ? AND s.iso_rel_path NOT LIKE ?";
            $params      = [$areaId, $prefix . '%', $prefix . '%/%',
                            $areaId, $prefix . '%', $prefix . '%/%'];
        }

        // Union two sources so that directories with no direct files (only
        // subdirectories) are still visible via their iso_subdir records.
        $stmt = $this->db->prepare("
            SELECT subfolder, description, long_description, subdir_id
            FROM (
                SELECT DISTINCT f.subfolder,
                       m.short_description AS description,
                       m.long_description  AS long_description,
                       m.id                AS subdir_id
                FROM files f
                LEFT JOIN files m ON m.file_area_id = f.file_area_id
                                  AND m.source_type = 'iso_subdir'
                                  AND m.iso_rel_path = f.subfolder
                                  AND m.status = 'approved'
                WHERE f.file_area_id = ?
                  AND f.status = 'approved'
                  AND f.subfolder IS NOT NULL
                  AND (f.source_type IS DISTINCT FROM 'iso_subdir')
                  {$whereFiles}

                UNION

                SELECT s.iso_rel_path AS subfolder,
                       s.short_description AS description,
                       s.long_description  AS long_description,
                       s.id                AS subdir_id
                FROM files s
                WHERE s.file_area_id = ?
                  AND s.status = 'approved'
                  AND s.source_type = 'iso_subdir'
                  {$whereSubdir}
            ) combined
            ORDER BY subfolder
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
            SELECT f.*, fa.tag as area_tag, fa.domain, fa.iso_mount_point,
                   u.username as owner_username,
                   COALESCE(
                       NULLIF(TRIM(f.uploaded_from_address), ''),
                       u.username
                   ) as display_from
            FROM files f
            JOIN file_areas fa ON f.file_area_id = fa.id
            LEFT JOIN users u ON f.owner_id = u.id
            WHERE f.id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Upload a file to a file area from an existing filesystem path.
     *
     * Identical to uploadFile() but accepts a pre-existing file path (e.g. a
     * ZMODEM receive temp file) instead of a $_FILES entry.  The source file
     * is moved into the area storage directory; callers must not rely on the
     * source path remaining valid after this call.
     *
     * @param int    $fileAreaId       File area ID
     * @param string $sourcePath       Absolute path to the source file
     * @param string $shortDescription Short description
     * @param string $longDescription  Long description (optional)
     * @param string $uploadedBy       Username or FidoNet address of uploader
     * @param int|null $ownerId        User ID of file owner
     * @return int File ID
     * @throws \Exception If upload fails
     */
    public function uploadFileFromPath(int $fileAreaId, string $sourcePath, string $shortDescription, string $longDescription = '', string $uploadedBy = '', ?int $ownerId = null): int
    {
        $fileArea = $this->getFileAreaById($fileAreaId);
        if (!$fileArea || !$fileArea['is_active']) {
            throw new \Exception('File area not found or inactive');
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \Exception('Source file not found or not readable');
        }

        $filename = str_replace(' ', '_', basename($sourcePath));
        $fileSize = filesize($sourcePath);

        if ($fileSize > $fileArea['max_file_size']) {
            $maxMB = round($fileArea['max_file_size'] / 1048576, 2);
            throw new \Exception("File size exceeds maximum allowed size of {$maxMB} MB");
        }

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

        $fileHash = hash_file('sha256', $sourcePath);

        $allowDuplicate = !empty($fileArea['allow_duplicate_hash']);
        if ($allowDuplicate) {
            $fileHash = $this->makeUniqueHash($fileAreaId, $fileHash);
        } else {
            $existingFile = $this->checkDuplicate($fileAreaId, $fileHash);
            if ($existingFile) {
                // When replace_existing is on, a hash match for the same filename is fine —
                // the old record will be deleted in the replacement block below.
                $isSameFile = $fileArea['replace_existing']
                    && strcasecmp((string)$existingFile['filename'], $filename) === 0;
                if (!$isSameFile) {
                    throw new \Exception('This file already exists in this area');
                }
            }
        }

        $areaDir     = $this->getAreaStorageDir($fileArea);
        self::ensureDirectoryExists($areaDir);
        $storagePath = $areaDir . '/' . $filename;

        if (file_exists($storagePath)) {
            if ($fileArea['replace_existing']) {
                $oldFile = $this->getFileByPath($storagePath);
                if ($oldFile) {
                    $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
                    $stmt->execute([$oldFile['id']]);
                }
                unlink($storagePath);
            } else {
                $counter = 1;
                while (file_exists($storagePath)) {
                    $pathInfo    = pathinfo($filename);
                    $newFilename = $pathInfo['filename'] . '_' . $counter;
                    if (isset($pathInfo['extension'])) {
                        $newFilename .= '.' . $pathInfo['extension'];
                    }
                    $storagePath = $areaDir . '/' . $newFilename;
                    $filename    = $newFilename;
                    $counter++;
                }
            }
        }

        if (!rename($sourcePath, $storagePath)) {
            throw new \Exception('Failed to move uploaded file into storage');
        }

        chmod($storagePath, 0664);
        $storagePath = realpath($storagePath);

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
            $ownerId,
        ]);

        $result = $stmt->fetch();
        $fileId = $result['id'];

        $this->updateFileAreaStats($fileAreaId);

        if (!empty($fileArea['scan_virus'])) {
            $scanResult = $this->scanFileForViruses($fileId, $storagePath);
            if (($scanResult['result'] ?? '') === 'infected' && Config::env('FILES_ALLOW_INFECTED', 'false') !== 'true') {
                throw new \Exception('File rejected: virus detected.');
            }
        }

        try {
            $ruleProcessor = new FileAreaRuleProcessor();
            $ruleResult    = $ruleProcessor->processFile($storagePath, $fileArea['tag']);
            if (!empty($ruleResult['output'])) {
                error_log("File area rules output for {$filename}: " . $ruleResult['output']);
            }
        } catch (\Exception $e) {
            error_log("File area rules error for {$filename}: " . $e->getMessage());
        }

        return $fileId;
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

        $filename = str_replace(' ', '_', basename($fileData['name']));
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
                // When replace_existing is on, a hash match for the same filename is fine —
                // the old record will be deleted in the replacement block below.
                $isSameFile = $fileArea['replace_existing']
                    && strcasecmp((string)$existingFile['filename'], $filename) === 0;
                if (!$isSameFile) {
                    throw new \Exception('This file already exists in this area');
                }
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
            if (($scanResult['result'] ?? '') === 'infected' && Config::env('FILES_ALLOW_INFECTED', 'false') !== 'true') {
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
        if (Config::env('VIRUS_SCAN_DISABLED', 'false') === 'true' ||
            Config::env('VIRUS_SCAN_NOAUTO', 'false') === 'true') {
            return ['scanned' => false, 'result' => 'skipped', 'signature' => null, 'error_code' => '', 'error' => null];
        }

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
            $allowInfected = Config::env('FILES_ALLOW_INFECTED', 'false') === 'true';
            error_log("VIRUS DETECTED: File ID {$fileId} infected with {$result['signature']}" . ($allowInfected ? ' (FILES_ALLOW_INFECTED: keeping file)' : ''));

            \BinktermPHP\Admin\AdminDaemonClient::log('WARNING', 'Virus detected in uploaded file', [
                'file_id'   => $fileId,
                'signature' => $result['signature'] ?? 'unknown',
                'file_path' => $filePath,
                'allowed'   => $allowInfected,
            ]);

            if ($allowInfected) {
                // Keep the file but leave its scan result recorded as infected
            } else {
                // Delete infected file immediately
                if (file_exists($filePath)) {
                    unlink($filePath);
                    error_log("Deleted infected file: {$filePath}");
                }

                // Mark file record as rejected and update area stats
                $stmt = $this->db->prepare("UPDATE files SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$fileId]);

                $areaStmt = $this->db->prepare("SELECT file_area_id FROM files WHERE id = ?");
                $areaStmt->execute([$fileId]);
                $areaRow = $areaStmt->fetch();
                if ($areaRow) {
                    $this->updateFileAreaStats((int)$areaRow['file_area_id']);
                }
            }
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
     * Delete all files and iso_subdir records belonging to a subfolder (and any
     * nested sub-paths) within a file area. For ISO-backed files the physical
     * file is not removed (the ISO is read-only); only the DB records are deleted.
     *
     * @param int    $areaId    File area ID
     * @param string $subfolder Subfolder path to delete (e.g. "001A" or "TEXT/DIR9")
     * @return int Number of rows deleted
     */
    public function deleteSubfolder(int $areaId, string $subfolder): int
    {
        // Collect non-ISO files so we can unlink them from disk.
        $stmt = $this->db->prepare("
            SELECT storage_path FROM files
            WHERE file_area_id = ?
              AND source_type NOT IN ('iso_import', 'iso_subdir')
              AND (subfolder = ? OR subfolder LIKE ?)
        ");
        $stmt->execute([$areaId, $subfolder, $subfolder . '/%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $path) {
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }

        // Delete all file records in this subfolder (including nested paths)
        // and the iso_subdir records for the subfolder itself and any children.
        $del = $this->db->prepare("
            DELETE FROM files
            WHERE file_area_id = ?
              AND (
                  subfolder = ? OR subfolder LIKE ?
                  OR (source_type = 'iso_subdir' AND (iso_rel_path = ? OR iso_rel_path LIKE ?))
              )
        ");
        $like = $subfolder . '/%';
        $del->execute([$areaId, $subfolder, $like, $subfolder, $like]);
        $deleted = $del->rowCount();

        if ($deleted > 0) {
            $this->updateFileAreaStats($areaId);
        }

        return $deleted;
    }

    /**
     * Rename a file (on disk and in the database).
     *
     * @param int    $fileId      ID of the file to rename
     * @param string $newFilename New filename (basename only; no path components)
     * @param int    $userId      Requesting user ID
     * @param bool   $isAdmin     Whether the requesting user is an admin
     * @return bool
     * @throws \Exception If not found, no permission, invalid name, or name collision
     */
    public function renameFile(int $fileId, string $newFilename, int $userId, bool $isAdmin): bool
    {
        $file = $this->getFileById($fileId);
        if (!$file) {
            throw new \Exception('File not found');
        }

        // Only owner or admin may rename
        if (!$isAdmin && ($file['owner_id'] === null || $file['owner_id'] != $userId)) {
            throw new \Exception('You do not have permission to rename this file');
        }

        // Sanitize: strip any directory component
        $newFilename = basename(trim($newFilename));
        if ($newFilename === '' || $newFilename === '.' || $newFilename === '..') {
            throw new \Exception('Invalid filename');
        }
        if (strlen($newFilename) > 255) {
            throw new \Exception('Filename too long');
        }

        // No change?
        if ($newFilename === $file['filename']) {
            return true;
        }

        // Check for collision in the same area directory
        $newPath = dirname($file['storage_path']) . DIRECTORY_SEPARATOR . $newFilename;
        if (file_exists($newPath)) {
            throw new \Exception('A file with that name already exists in this area');
        }

        // Rename on disk
        if (file_exists($file['storage_path'])) {
            if (!rename($file['storage_path'], $newPath)) {
                throw new \Exception('Failed to rename file on disk');
            }
        } else {
            // File missing on disk — update DB record only
            $newPath = $file['storage_path'];
        }

        $stmt = $this->db->prepare(
            "UPDATE files SET filename = ?, storage_path = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$newFilename, $newPath, $fileId]);

        return true;
    }

    /**
     * Update a file's description fields.
     *
     * @param int    $fileId           File ID
     * @param string $shortDescription Short description (required, max 255 chars)
     * @param string|null $longDescription  Optional extended description
     * @param int    $userId           Requesting user ID
     * @param bool   $isAdmin          Whether the user is an admin
     * @return bool
     * @throws \Exception If not found, no permission, or validation fails
     */
    public function updateFileDescription(int $fileId, string $shortDescription, ?string $longDescription, int $userId, bool $isAdmin): bool
    {
        $file = $this->getFileById($fileId);
        if (!$file) {
            throw new \Exception('File not found');
        }

        if (!$isAdmin && ($file['owner_id'] === null || $file['owner_id'] != $userId)) {
            throw new \Exception('You do not have permission to edit this file');
        }

        $shortDescription = trim($shortDescription);
        if ($shortDescription === '') {
            throw new \Exception('Short description is required');
        }
        if (strlen($shortDescription) > 255) {
            throw new \Exception('Short description is too long');
        }

        $stmt = $this->db->prepare(
            "UPDATE files SET short_description = ?, long_description = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$shortDescription, $longDescription ?: null, $fileId]);

        return true;
    }

    /**
     * Move a file to a different file area (admin only).
     *
     * Moves the physical file to the target area's storage directory and
     * updates the database record. Throws if the filename already exists
     * in the target area.
     *
     * @param int  $fileId       File ID
     * @param int  $targetAreaId Target file area ID
     * @param bool $isAdmin      Must be true; only admins may move files
     * @return bool
     * @throws \Exception If not admin, file/area not found, collision, or move fails
     */
    public function moveFile(int $fileId, int $targetAreaId, bool $isAdmin): bool
    {
        if (!$isAdmin) {
            throw new \Exception('You do not have permission to move files');
        }

        $file = $this->getFileById($fileId);
        if (!$file) {
            throw new \Exception('File not found');
        }

        if ((int)$file['file_area_id'] === $targetAreaId) {
            return true; // already in the target area — nothing to do
        }

        $targetArea = $this->getFileAreaById($targetAreaId);
        if (!$targetArea) {
            throw new \Exception('Target file area not found');
        }

        $targetDir  = $this->getAreaStorageDir($targetArea);
        self::ensureDirectoryExists($targetDir);

        $filename   = $file['filename'];
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($targetPath)) {
            throw new \Exception('A file with that name already exists in the target area');
        }

        if (file_exists($file['storage_path'])) {
            if (!rename($file['storage_path'], $targetPath)) {
                throw new \Exception('Failed to move file on disk');
            }
            $newPath = realpath($targetPath);
        } else {
            // File missing on disk — update DB to new expected path
            $newPath = $targetPath;
        }

        $stmt = $this->db->prepare(
            "UPDATE files SET file_area_id = ?, storage_path = ?, subfolder = NULL, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$targetAreaId, $newPath, $fileId]);

        return true;
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
    public function storeNetmailAttachment(int $userId, string $filePath, string $filename, ?int $messageId, string $fromAddress, string $sourceType = 'netmail_attachment', ?string $shortDescription = null): int
    {
        if (!file_exists($filePath)) {
            throw new \Exception("Attachment file not found: {$filePath}");
        }

        // Get or create user's private file area
        $fileArea = $this->getOrCreatePrivateFileArea($userId);

        // Calculate file info
        $fileSize = filesize($filePath);
        $fileHash = hash_file('sha256', $filePath);

        // Store under an "attachments" subdirectory within the private area
        $areaDir        = $this->getAreaStorageDir($fileArea);
        $attachmentsDir = $areaDir . '/attachments';
        self::ensureDirectoryExists($attachmentsDir);

        // Determine storage path (with versioning for duplicates)
        $storagePath = $attachmentsDir . '/' . $filename;
        $counter = 1;
        while (file_exists($storagePath)) {
            $pathInfo = pathinfo($filename);
            $newFilename = $pathInfo['filename'] . '_' . $counter;
            if (isset($pathInfo['extension'])) {
                $newFilename .= '.' . $pathInfo['extension'];
            }
            $storagePath = $attachmentsDir . '/' . $newFilename;
            $filename    = $newFilename;
            $counter++;
        }

        // Move file from temp dir to storage
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
                subfolder, status, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, 'netmail',
                'attachments', 'approved', NOW()
            ) RETURNING id
        ");

        $shortDescription = $shortDescription ?? "Netmail attachment from {$fromAddress}";

        $stmt->execute([
            $fileArea['id'],
            $filename,
            $fileSize,
            $fileHash,
            $storagePath,
            $fromAddress,
            $sourceType,
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
     * Store a file received via FREQ into the user's private file area under
     * an "incoming" subdirectory.  Used by freq_getfile.php after a session.
     *
     * @param int    $userId      ID of the user who made the request
     * @param string $filePath    Absolute path to the file (e.g. in data/inbound/)
     * @param string $fromAddress FTN address of the node that sent the file
     * @return int   Inserted file ID
     * @throws \Exception on filesystem or database error
     */
    public function storeFreqIncoming(int $userId, string $filePath, string $fromAddress): int
    {
        if (!file_exists($filePath)) {
            throw new \Exception("FREQ incoming file not found: {$filePath}");
        }

        $fileArea = $this->getOrCreatePrivateFileArea($userId);
        $filename = basename($filePath);
        $fileSize = filesize($filePath);
        $fileHash = hash_file('sha256', $filePath);

        // Store under an "incoming" subdirectory within the private area
        $areaDir = $this->getAreaStorageDir($fileArea);
        $incomingDir = $areaDir . '/incoming';
        self::ensureDirectoryExists($incomingDir);

        // Avoid filename collisions
        $storagePath = $incomingDir . '/' . $filename;
        $counter = 1;
        while (file_exists($storagePath)) {
            $pathInfo = pathinfo($filename);
            $newFilename = $pathInfo['filename'] . '_' . $counter;
            if (isset($pathInfo['extension'])) {
                $newFilename .= '.' . $pathInfo['extension'];
            }
            $storagePath = $incomingDir . '/' . $newFilename;
            $filename    = $newFilename;
            $counter++;
        }

        if (!rename($filePath, $storagePath)) {
            throw new \Exception("Failed to move FREQ file to private area: {$filePath}");
        }

        chmod($storagePath, 0664);
        $storagePath = realpath($storagePath);

        $stmt = $this->db->prepare("
            INSERT INTO files (
                file_area_id, filename, filesize, file_hash, storage_path,
                uploaded_from_address, source_type,
                short_description, owner_id, subfolder,
                status, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, 'freq_incoming',
                ?, ?, 'incoming',
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
            "FREQ download from {$fromAddress}",
            $userId,
        ]);

        $fileId = $stmt->fetch()['id'];

        $this->updateFileAreaStats($fileArea['id']);

        return $fileId;
    }

    /**
     * Get files attached to a message, filtered to what the viewer can access.
     *
     * When multiple copies of an attachment exist (e.g. sender copy + recipient copy),
     * only the copy in an area the viewer can access is returned.  Private areas are
     * only visible to their owner; public areas are visible to everyone.
     *
     * @param int $messageId Message ID
     * @param string $messageType 'netmail' or 'echomail'
     * @param int|null $viewerUserId The ID of the user viewing the message (null = no filtering)
     * @return array Array of file records
     */
    public function getMessageAttachments(int $messageId, string $messageType, ?int $viewerUserId = null): array
    {
        $stmt = $this->db->prepare("
            SELECT f.*, fa.is_private, fa.tag AS area_tag
            FROM files f
            JOIN file_areas fa ON fa.id = f.file_area_id
            WHERE f.message_id = ? AND f.message_type = ?
            ORDER BY f.created_at ASC
        ");
        $stmt->execute([$messageId, $messageType]);
        $rows = $stmt->fetchAll();

        if ($viewerUserId === null) {
            return $rows;
        }

        // For each distinct original filename, prefer the copy the viewer can access.
        // A viewer can access a file if:
        //   - the area is public (is_private = false), OR
        //   - the area tag matches their own private area (PRIVATE_USER_{viewerUserId})
        $viewerPrivateTag = 'PRIVATE_USER_' . $viewerUserId;

        $accessible = [];
        $inaccessible = [];

        foreach ($rows as $row) {
            $isPrivate = ($row['is_private'] === true || $row['is_private'] === 't' || $row['is_private'] === '1' || $row['is_private'] === 1);
            if (!$isPrivate || $row['area_tag'] === $viewerPrivateTag) {
                $accessible[] = $row;
            } else {
                $inaccessible[] = $row;
            }
        }

        // If we have at least one accessible copy, return only accessible copies.
        // Fall back to all copies only when the viewer has no accessible copy at all
        // (e.g. admin viewing a message between other users).
        return !empty($accessible) ? $accessible : $rows;
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
                    SELECT COUNT(*) FROM files
                    WHERE file_area_id = ? AND status = 'approved'
                      AND (source_type IS DISTINCT FROM 'iso_subdir')
                ),
                total_size = (
                    SELECT COALESCE(SUM(filesize), 0) FROM files
                    WHERE file_area_id = ? AND status = 'approved'
                      AND (source_type IS DISTINCT FROM 'iso_subdir')
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
     * Creates directory with 02775 permissions (rwxrwsr-x) to allow both
     * user and group read/write/execute access with setgid bit
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

        // Save current umask and set to 0000 to ensure permissions are applied correctly
        $oldUmask = umask(0000);

        try {
            if (!mkdir($directory, self::DIR_PERM, $recursive)) {
                throw new \Exception("Failed to create directory: {$directory}");
            }
        } finally {
            // Always restore original umask
            umask($oldUmask);
        }
    }

    // Lazy migration removed; directory changes should be handled explicitly.

    // -------------------------------------------------------------------------
    // File sharing methods
    // -------------------------------------------------------------------------

    /**
     * Create a share link for a file, or return an existing active one.
     * The public URL is /shared/file/{AREA_TAG}/{filename} — human-readable and stable.
     *
     * @param int $fileId File ID to share
     * @param int $userId User creating the share
     * @param int|null $expiresHours Hours until expiry (null = never)
     * @return array ['success'=>bool, 'share_url'=>string, 'share_id'=>int, 'existing'=>bool]
     */
    public function createFileShare(int $fileId, int $userId, ?int $expiresHours = null, bool $freqAccessible = true): array
    {
        $file = $this->getFileById($fileId);
        if (!$file || $file['status'] !== 'approved') {
            return [
                'success' => false,
                'error_code' => 'errors.files.not_found',
                'error' => 'File not found'
            ];
        }

        if (!$this->canAccessFileArea($file['file_area_id'], $userId)) {
            return [
                'success' => false,
                'error_code' => 'errors.files.access_denied',
                'error' => 'Access denied to this file area'
            ];
        }

        $shareUrl = $this->buildFileShareUrl($file['area_tag'], $file['filename']);

        // Return the existing global share if one is already active for this file
        $existing = $this->getExistingFileShare($fileId);
        if ($existing) {
            return [
                'success'   => true,
                'share_url' => $shareUrl,
                'share_id'  => (int)$existing['id'],
                'access_count' => (int)($existing['access_count'] ?? 0),
                'last_accessed_at' => $existing['last_accessed_at'] ?? null,
                'existing'  => true,
            ];
        }

        // share_key remains a random token used only for DB uniqueness and revocation tracking
        $shareKey = bin2hex(random_bytes(16));

        if ($expiresHours !== null) {
            $expiresHours = (int)$expiresHours;
            $stmt = $this->db->prepare("
                INSERT INTO shared_files (file_id, shared_by_user_id, share_key, expires_at, freq_accessible, created_at)
                VALUES (?, ?, ?, NOW() + INTERVAL '1 hour' * ?, ?, NOW())
                RETURNING id
            ");
            $stmt->execute([$fileId, $userId, $shareKey, $expiresHours, $freqAccessible ? 'true' : 'false']);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO shared_files (file_id, shared_by_user_id, share_key, expires_at, freq_accessible, created_at)
                VALUES (?, ?, ?, NULL, ?, NOW())
                RETURNING id
            ");
            $stmt->execute([$fileId, $userId, $shareKey, $freqAccessible ? 'true' : 'false']);
        }

        $row = $stmt->fetch();

        return [
            'success'   => true,
            'share_url' => $shareUrl,
            'share_id'  => (int)$row['id'],
            'access_count' => 0,
            'last_accessed_at' => null,
            'existing'  => false,
        ];
    }

    /**
     * Fetch a shared file by area tag and filename, updating access statistics.
     * Picks the most recently created active share for the given file.
     *
     * @param string $areaTag File area tag (e.g. "SOFTDIST")
     * @param string $filename Filename (e.g. "DOOM11.ZIP")
     * @param int|null $requestingUserId Logged-in user ID (null = anonymous)
     * @return array ['success'=>bool, 'file'=>array, 'share_info'=>array]
     *               or ['success'=>false, 'error_code'=>string, 'error'=>string]
     */
    public function getSharedFile(string $areaTag, string $filename, ?int $requestingUserId = null): array
    {
        $this->cleanupExpiredFileShares();

        $stmt = $this->db->prepare("
            SELECT sf.id AS share_id,
                   sf.share_key,
                   sf.expires_at,
                   sf.access_count,
                   sf.last_accessed_at,
                   sf.created_at AS share_created_at,
                   sf.shared_by_user_id,
                   u.username AS shared_by_username,
                   f.id AS file_id,
                   f.filename,
                   f.filesize,
                   f.short_description,
                   f.long_description,
                   f.created_at AS file_created_at,
                   f.virus_scanned,
                   f.virus_scan_result,
                   f.file_area_id,
                   fa.tag AS area_tag,
                   fa.domain,
                   fa.description AS area_description
            FROM shared_files sf
            JOIN files f ON sf.file_id = f.id
            JOIN file_areas fa ON f.file_area_id = fa.id
            JOIN users u ON sf.shared_by_user_id = u.id
            WHERE LOWER(fa.tag) = LOWER(?)
              AND LOWER(f.filename) = LOWER(?)
              AND sf.is_active = TRUE
              AND (sf.expires_at IS NULL OR sf.expires_at > NOW())
              AND f.status = 'approved'
            ORDER BY sf.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$areaTag, $filename]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'success' => false,
                'error_code' => 'errors.files.share_not_found_or_forbidden',
                'error' => 'Share link not found or not permitted'
            ];
        }

        // Update access statistics
        $upd = $this->db->prepare("
            UPDATE shared_files
            SET access_count = access_count + 1, last_accessed_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$row['share_id']]);

        $file = [
            'id'               => (int)$row['file_id'],
            'filename'         => $row['filename'],
            'filesize'         => (int)$row['filesize'],
            'short_description'=> $row['short_description'],
            'long_description' => $row['long_description'],
            'created_at'       => $row['file_created_at'],
            'virus_scanned'    => $row['virus_scanned'],
            'virus_scan_result'=> $row['virus_scan_result'],
            'file_area_id'     => (int)$row['file_area_id'],
            'area_tag'         => $row['area_tag'],
            'area_description' => $row['area_description'],
            'domain'           => $row['domain'] ?? 'fidonet',
        ];

        $shareInfo = [
            'share_id'     => (int)$row['share_id'],
            'shared_by'    => $row['shared_by_username'],
            'created_at'   => $row['share_created_at'],
            'expires_at'   => $row['expires_at'],
            'access_count' => (int)$row['access_count'] + 1,
            'share_url'    => $this->buildFileShareUrl($row['area_tag'], $row['filename']),
            'is_logged_in' => $requestingUserId !== null,
        ];

        return ['success' => true, 'file' => $file, 'share_info' => $shareInfo];
    }

    /**
     * Get the active global share for a file (one share per file, any creator)
     *
     * @param int $fileId File ID
     * @return array|null Share record or null
     */
    public function getExistingFileShare(int $fileId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM shared_files
            WHERE file_id = ?
              AND is_active = TRUE
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$fileId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Revoke a file share
     *
     * @param int $shareId Share ID
     * @param int $userId Requesting user ID
     * @param bool $isAdmin Whether the user is an admin
     * @return bool True if revoked
     */
    public function revokeFileShare(int $shareId, int $userId, bool $isAdmin = false): bool
    {
        if ($isAdmin) {
            $stmt = $this->db->prepare("UPDATE shared_files SET is_active = FALSE WHERE id = ?");
            $stmt->execute([$shareId]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE shared_files SET is_active = FALSE
                WHERE id = ? AND shared_by_user_id = ?
            ");
            $stmt->execute([$shareId, $userId]);
        }
        return $stmt->rowCount() > 0;
    }

    /**
     * Deactivate expired file shares
     */
    private function cleanupExpiredFileShares(): void
    {
        $this->db->exec("
            UPDATE shared_files
            SET is_active = FALSE
            WHERE expires_at IS NOT NULL AND expires_at < NOW() AND is_active = TRUE
        ");
    }

    /**
     * Build the public URL for a file share using the human-readable area/filename path
     *
     * @param string $areaTag File area tag (e.g. "SOFTDIST")
     * @param string $filename Filename (e.g. "DOOM11.ZIP")
     * @return string
     */
    private function buildFileShareUrl(string $areaTag, string $filename): string
    {
        return Config::getSiteUrl()
            . '/shared/file/'
            . rawurlencode($areaTag)
            . '/'
            . rawurlencode($filename);
    }
}
