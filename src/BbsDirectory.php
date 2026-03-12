<?php

namespace BinktermPHP;

/**
 * Data-access class for the BBS directory.
 */
class BbsDirectory
{
    /** @var \PDO */
    private \PDO $db;
    private BbsDirectoryGeocoder $geocoder;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->geocoder = new BbsDirectoryGeocoder();
    }

    /**
     * Get all active entries ordered by name (for public listing).
     *
     * @return array
     */
    public function getActiveEntries(): array
    {
        $stmt = $this->db->query("
            SELECT *
            FROM bbs_directory
            WHERE status = 'active'
            ORDER BY LOWER(name) ASC
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all entries with status='pending', oldest first.
     *
     * @return array
     */
    public function getPendingEntries(): array
    {
        $stmt = $this->db->query("
            SELECT d.*, u.username AS submitted_by_username
            FROM bbs_directory d
            LEFT JOIN users u ON u.id = d.submitted_by_user_id
            WHERE d.status = 'pending'
            ORDER BY d.created_at ASC
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count entries currently pending review.
     *
     * @return int
     */
    public function getPendingCount(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM bbs_directory WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * Approve a pending entry (set status = 'active').
     *
     * @param int $id
     * @return bool
     */
    public function approveEntry(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE bbs_directory SET status = 'active', is_active = TRUE, updated_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reject a pending entry (set status = 'rejected').
     *
     * @param int $id
     * @return bool
     */
    public function rejectEntry(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE bbs_directory SET status = 'rejected', is_active = FALSE, updated_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Create a user-submitted listing in 'pending' state.
     *
     * @param array $data
     * @param int   $userId
     * @return int  New entry ID
     */
    public function createPendingEntry(array $data, int $userId): int
    {
        $coords = $this->resolveCoordinates(
            $data['location'] ?? null,
            null,
            true
        );

        $stmt = $this->db->prepare("
            INSERT INTO bbs_directory
                (name, sysop, location, os, telnet_host, telnet_port, website, notes, latitude, longitude,
                 source, status, is_active, submitted_by_user_id, created_at, updated_at)
            VALUES
                (:name, :sysop, :location, :os, :telnet_host, :telnet_port, :website, :notes, :latitude, :longitude,
                 'manual', 'pending', FALSE, :user_id, NOW(), NOW())
            RETURNING id
        ");

        $stmt->execute([
            ':name'        => $data['name'],
            ':sysop'       => $data['sysop'] ?? null,
            ':location'    => $data['location'] ?? null,
            ':os'          => $data['os'] ?? null,
            ':telnet_host' => $data['telnet_host'] ?? null,
            ':telnet_port' => isset($data['telnet_port']) ? (int)$data['telnet_port'] : 23,
            ':website'     => $data['website'] ?? null,
            ':notes'       => $data['notes'] ?? null,
            ':latitude'    => $coords['latitude'],
            ':longitude'   => $coords['longitude'],
            ':user_id'     => $userId,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)$row['id'];
    }

    /**
     * Get all entries with pagination and optional search (for admin listing).
     *
     * @param int    $page
     * @param int    $perPage
     * @param string $search
     * @return array
     */
    public function getAllEntries(int $page = 1, int $perPage = 25, string $search = ''): array
    {
        $offset = ($page - 1) * $perPage;

        if ($search !== '') {
            $stmt = $this->db->prepare("
                SELECT *
                FROM bbs_directory
                WHERE name ILIKE ? OR sysop ILIKE ? OR location ILIKE ? OR telnet_host ILIKE ?
                ORDER BY LOWER(name) ASC
                LIMIT ? OFFSET ?
            ");
            $like = '%' . $search . '%';
            $stmt->execute([$like, $like, $like, $like, $perPage, $offset]);
        } else {
            $stmt = $this->db->prepare("
                SELECT *
                FROM bbs_directory
                ORDER BY LOWER(name) ASC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$perPage, $offset]);
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get a single entry by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getEntry(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bbs_directory WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Upsert a BBS entry by telnet address (preferred) or name (fallback).
     * Sets source='auto' and updates last_seen. Used by robot processors and import scripts.
     *
     * Match priority:
     *   1. If telnet_host is provided, look for an existing entry with the same host+port.
     *      The same BBS may appear under different names in different sources, so address
     *      is a more reliable key than name.
     *   2. If no address match (or no address given), fall back to case-insensitive name match.
     *   3. If neither matches, insert a new row.
     *
     * Entries with is_local=TRUE are never modified.
     * When matched by address, the stored name is preserved (not overwritten by the caller's name).
     *
     * @param array $data Must contain 'name'; optionally sysop, location, os, telnet_host,
     *                    telnet_port, ssh_port, website, software
     * @return int The ID of the inserted or updated row
     */
    public function upsertByName(array $data): int
    {
        $telnetHost = !empty($data['telnet_host']) ? trim($data['telnet_host']) : null;
        $telnetPort = isset($data['telnet_port']) && $data['telnet_port'] !== '' ? (int)$data['telnet_port'] : 23;
        $existingByName = $this->findEntryByName((string)$data['name']);

        // Step 1: try to match by telnet address
        if ($telnetHost !== null) {
            $existing = $this->findEntryByTelnet($telnetHost, $telnetPort);
            if ($existing) {
                if ($existing['is_local']) {
                    return (int)$existing['id'];
                }

                $effectiveLocation = array_key_exists('location', $data)
                    ? $data['location']
                    : ($existing['location'] ?? null);
                $locationChanged = array_key_exists('location', $data)
                    && $this->normalizeLocation($data['location'] ?? null) !== $this->normalizeLocation($existing['location'] ?? null);
                $coords = $this->resolveCoordinates($effectiveLocation, $existing, $locationChanged);

                // Update in place; preserve the stored name
                $upd = $this->db->prepare("
                    UPDATE bbs_directory SET
                        sysop       = COALESCE(:sysop,     sysop),
                        location    = COALESCE(:location,  location),
                        os          = COALESCE(:os,        os),
                        telnet_host = :telnet_host,
                        telnet_port = :telnet_port,
                        ssh_port    = COALESCE(:ssh_port,  ssh_port),
                        website     = COALESCE(:website,   website),
                        software    = COALESCE(:software,  software),
                        latitude    = :latitude,
                        longitude   = :longitude,
                        source      = CASE WHEN source = 'manual' THEN 'manual' ELSE 'auto' END,
                        status      = 'active',
                        last_seen   = NOW(),
                        is_active   = TRUE,
                        updated_at  = NOW()
                    WHERE id = :id
                ");
                $upd->execute([
                    ':sysop'       => $data['sysop'] ?? null,
                    ':location'    => $data['location'] ?? null,
                    ':os'          => $data['os'] ?? null,
                    ':telnet_host' => $telnetHost,
                    ':telnet_port' => $telnetPort,
                    ':ssh_port'    => isset($data['ssh_port']) && $data['ssh_port'] !== '' ? (int)$data['ssh_port'] : null,
                    ':website'     => $data['website'] ?? null,
                    ':software'    => $data['software'] ?? null,
                    ':latitude'    => $coords['latitude'],
                    ':longitude'   => $coords['longitude'],
                    ':id'          => (int)$existing['id'],
                ]);
                return (int)$existing['id'];
            }
        }

        // Step 2: fall back to name-based upsert
        if ($existingByName && !empty($existingByName['is_local'])) {
            return (int)$existingByName['id'];
        }

        $effectiveLocation = array_key_exists('location', $data)
            ? $data['location']
            : ($existingByName['location'] ?? null);
        $locationChanged = array_key_exists('location', $data)
            && $this->normalizeLocation($data['location'] ?? null) !== $this->normalizeLocation($existingByName['location'] ?? null);
        $coords = $this->resolveCoordinates($effectiveLocation, $existingByName, $locationChanged);

        $stmt = $this->db->prepare("
            INSERT INTO bbs_directory
                (name, sysop, location, os, telnet_host, telnet_port, ssh_port, website, software, latitude, longitude, source, status, last_seen, is_active, created_at, updated_at)
            VALUES
                (:name, :sysop, :location, :os, :telnet_host, :telnet_port, :ssh_port, :website, :software, :latitude, :longitude, 'auto', 'active', NOW(), TRUE, NOW(), NOW())
            ON CONFLICT (LOWER(name)) DO UPDATE SET
                sysop        = COALESCE(EXCLUDED.sysop,       bbs_directory.sysop),
                location     = COALESCE(EXCLUDED.location,    bbs_directory.location),
                os           = COALESCE(EXCLUDED.os,          bbs_directory.os),
                telnet_host  = COALESCE(EXCLUDED.telnet_host, bbs_directory.telnet_host),
                telnet_port  = EXCLUDED.telnet_port,
                ssh_port     = COALESCE(EXCLUDED.ssh_port,    bbs_directory.ssh_port),
                website      = COALESCE(EXCLUDED.website,     bbs_directory.website),
                software     = COALESCE(EXCLUDED.software,    bbs_directory.software),
                latitude     = EXCLUDED.latitude,
                longitude    = EXCLUDED.longitude,
                source       = CASE WHEN bbs_directory.source = 'manual' THEN 'manual' ELSE 'auto' END,
                status       = 'active',
                last_seen    = NOW(),
                is_active    = TRUE,
                updated_at   = NOW()
            WHERE bbs_directory.is_local IS NOT TRUE
            RETURNING id
        ");

        $stmt->execute([
            ':name'        => $data['name'],
            ':sysop'       => $data['sysop'] ?? null,
            ':location'    => $data['location'] ?? null,
            ':os'          => $data['os'] ?? null,
            ':telnet_host' => $telnetHost,
            ':telnet_port' => $telnetPort,
            ':ssh_port'    => isset($data['ssh_port']) && $data['ssh_port'] !== '' ? (int)$data['ssh_port'] : null,
            ':website'     => $data['website'] ?? null,
            ':software'    => $data['software'] ?? null,
            ':latitude'    => $coords['latitude'],
            ':longitude'   => $coords['longitude'],
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            // Entry exists but is protected (is_local=TRUE); return its id without modifying it.
            $row = $this->findEntryByName((string)$data['name']);
        }

        return (int)$row['id'];
    }

    /**
     * Create a new manual BBS directory entry.
     *
     * @param array $data
     * @return int The new entry ID
     */
    public function createEntry(array $data): int
    {
        $coords = $this->resolveCoordinates(
            $data['location'] ?? null,
            null,
            true
        );
        $lastSeen = $this->normalizeLastSeenDate($data['last_seen'] ?? null, true);

        $stmt = $this->db->prepare("
            INSERT INTO bbs_directory
                (name, sysop, location, os, telnet_host, telnet_port, website, notes, latitude, longitude, source, last_seen, is_active, is_local, created_at, updated_at)
            VALUES
                (:name, :sysop, :location, :os, :telnet_host, :telnet_port, :website, :notes, :latitude, :longitude, 'manual', :last_seen, :is_active, :is_local, NOW(), NOW())
            RETURNING id
        ");

        $stmt->execute([
            ':name'        => $data['name'],
            ':sysop'       => $data['sysop'] ?? null,
            ':location'    => $data['location'] ?? null,
            ':os'          => $data['os'] ?? null,
            ':telnet_host' => $data['telnet_host'] ?? null,
            ':telnet_port' => isset($data['telnet_port']) ? (int)$data['telnet_port'] : 23,
            ':website'     => $data['website'] ?? null,
            ':notes'       => $data['notes'] ?? null,
            ':latitude'    => $coords['latitude'],
            ':longitude'   => $coords['longitude'],
            ':last_seen'   => $lastSeen,
            ':is_active'   => isset($data['is_active']) ? ($data['is_active'] ? 'true' : 'false') : 'true',
            ':is_local'    => !empty($data['is_local']) ? 'true' : 'false',
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)$row['id'];
    }

    /**
     * Update an existing BBS directory entry.
     *
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public function updateEntry(int $id, array $data): bool
    {
        $existing = $this->getEntry($id);
        $locationChanged = $this->normalizeLocation($data['location'] ?? null) !== $this->normalizeLocation($existing['location'] ?? null);
        $coords = $this->resolveCoordinates($data['location'] ?? null, $existing, $locationChanged);
        $lastSeen = $this->normalizeLastSeenDate($data['last_seen'] ?? ($existing['last_seen'] ?? null), true);

        $stmt = $this->db->prepare("
            UPDATE bbs_directory SET
                name        = :name,
                sysop       = :sysop,
                location    = :location,
                os          = :os,
                telnet_host = :telnet_host,
                telnet_port = :telnet_port,
                website     = :website,
                notes       = :notes,
                latitude    = :latitude,
                longitude   = :longitude,
                last_seen   = :last_seen,
                is_active   = :is_active,
                is_local    = :is_local,
                updated_at  = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':name'        => $data['name'],
            ':sysop'       => $data['sysop'] ?? null,
            ':location'    => $data['location'] ?? null,
            ':os'          => $data['os'] ?? null,
            ':telnet_host' => $data['telnet_host'] ?? null,
            ':telnet_port' => isset($data['telnet_port']) ? (int)$data['telnet_port'] : 23,
            ':website'     => $data['website'] ?? null,
            ':notes'       => $data['notes'] ?? null,
            ':latitude'    => $coords['latitude'],
            ':longitude'   => $coords['longitude'],
            ':last_seen'   => $lastSeen,
            ':is_active'   => isset($data['is_active']) ? ($data['is_active'] ? 'true' : 'false') : 'true',
            ':is_local'    => !empty($data['is_local']) ? 'true' : 'false',
            ':id'          => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function normalizeLastSeenDate($value, bool $defaultToToday = false): ?string
    {
        if ($value === null || $value === '') {
            return $defaultToToday ? gmdate('Y-m-d') : null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim((string)$value);
        if ($text === '') {
            return $defaultToToday ? gmdate('Y-m-d') : null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $text);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date instanceof \DateTimeImmutable && $errors['warning_count'] === 0 && $errors['error_count'] === 0) {
            return $date->format('Y-m-d');
        }

        $timestamp = strtotime($text);
        if ($timestamp !== false) {
            return gmdate('Y-m-d', $timestamp);
        }

        return $defaultToToday ? gmdate('Y-m-d') : null;
    }

    /**
     * Merge a duplicate entry into a target (keep) entry, then delete the duplicate.
     *
     * Fields that are null in the target are filled from the duplicate.
     * last_seen is set to whichever is more recent.
     * source is set to 'manual' if either entry has source='manual'.
     *
     * @param int $keepId      ID of the entry to keep
     * @param int $discardId   ID of the entry to delete after merging
     * @return bool            False if either entry is not found or keepId === discardId
     */
    public function mergeEntries(int $keepId, int $discardId): bool
    {
        if ($keepId === $discardId) {
            return false;
        }

        $keep    = $this->getEntry($keepId);
        $discard = $this->getEntry($discardId);

        if (!$keep || !$discard) {
            return false;
        }

        // Resolve merged field values in PHP, then issue a clean UPDATE
        $nullCoalesce = fn($a, $b) => $a !== null && $a !== '' ? $a : $b;

        // Telnet host+port: only substitute both together
        $mergedTelnetHost = $nullCoalesce($keep['telnet_host'], $discard['telnet_host']);
        $mergedTelnetPort = !empty($keep['telnet_host'])
            ? (int)$keep['telnet_port']
            : (int)($discard['telnet_port'] ?? 23);

        // last_seen: most recent non-null value
        $mergedLastSeen = $keep['last_seen'];
        if ($discard['last_seen'] !== null) {
            if ($mergedLastSeen === null || $discard['last_seen'] > $mergedLastSeen) {
                $mergedLastSeen = $discard['last_seen'];
            }
        }

        // source: 'manual' wins
        $mergedSource = ($keep['source'] === 'manual' || $discard['source'] === 'manual') ? 'manual' : $keep['source'];
        $mergedLocation = $nullCoalesce($keep['location'], $discard['location']);
        $locationChanged = $this->normalizeLocation($mergedLocation) !== $this->normalizeLocation($keep['location'] ?? null);
        $coords = $this->resolveCoordinates($mergedLocation, $keep, $locationChanged);

        $this->db->prepare("
            UPDATE bbs_directory SET
                sysop       = :sysop,
                location    = :location,
                os          = :os,
                telnet_host = :telnet_host,
                telnet_port = :telnet_port,
                ssh_port    = :ssh_port,
                website     = :website,
                software    = :software,
                notes       = :notes,
                latitude    = :latitude,
                longitude   = :longitude,
                last_seen   = :last_seen,
                source      = :source,
                updated_at  = NOW()
            WHERE id = :id
        ")->execute([
            ':sysop'       => $nullCoalesce($keep['sysop'],    $discard['sysop']),
            ':location'    => $mergedLocation,
            ':os'          => $nullCoalesce($keep['os'],       $discard['os']),
            ':telnet_host' => $mergedTelnetHost,
            ':telnet_port' => $mergedTelnetPort,
            ':ssh_port'    => $nullCoalesce($keep['ssh_port'], $discard['ssh_port']),
            ':website'     => $nullCoalesce($keep['website'],  $discard['website']),
            ':software'    => $nullCoalesce($keep['software'], $discard['software']),
            ':notes'       => $nullCoalesce($keep['notes'],    $discard['notes']),
            ':latitude'    => $coords['latitude'],
            ':longitude'   => $coords['longitude'],
            ':last_seen'   => $mergedLastSeen,
            ':source'      => $mergedSource,
            ':id'          => $keepId,
        ]);

        $this->deleteEntry($discardId);
        return true;
    }

    /**
     * Delete a BBS directory entry.
     *
     * @param int $id
     * @return bool
     */
    public function deleteEntry(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM bbs_directory WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get total count of entries, optionally filtered by search string.
     *
     * @param string $search
     * @return int
     */
    public function getTotalCount(string $search = ''): int
    {
        if ($search !== '') {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM bbs_directory
                WHERE name ILIKE ? OR sysop ILIKE ? OR location ILIKE ? OR telnet_host ILIKE ?
            ");
            $like = '%' . $search . '%';
            $stmt->execute([$like, $like, $like, $like]);
        } else {
            $stmt = $this->db->query("SELECT COUNT(*) FROM bbs_directory");
        }

        return (int)$stmt->fetchColumn();
    }

    /**
     * Backfill coordinates for entries that have a location but no coordinates.
     *
     * @param int|null $limit
     * @param bool $dryRun
     * @return array{selected:int, updated:int, skipped:int, failed:int, rows:array<int, array<string, mixed>>}
     */
    public function backfillMissingCoordinates(?int $limit = null, bool $dryRun = false): array
    {
        $sql = "
            SELECT id, name, location, latitude, longitude
            FROM bbs_directory
            WHERE location IS NOT NULL
              AND BTRIM(location) <> ''
              AND (latitude IS NULL OR longitude IS NULL)
            ORDER BY id ASC
        ";

        if ($limit !== null && $limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }

        $stmt = $this->db->query($sql);
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [
            'selected' => count($entries),
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'rows' => [],
        ];

        $updateStmt = $this->db->prepare("
            UPDATE bbs_directory
            SET latitude = :latitude,
                longitude = :longitude,
                updated_at = NOW()
            WHERE id = :id
        ");

        foreach ($entries as $entry) {
            $coords = $this->resolveCoordinates($entry['location'] ?? null, $entry, true);
            if ($coords['latitude'] === null || $coords['longitude'] === null) {
                $result['failed']++;
                $result['rows'][] = [
                    'id' => (int)$entry['id'],
                    'name' => $entry['name'] ?? '',
                    'location' => $entry['location'] ?? '',
                    'status' => 'failed',
                    'message' => 'Geocoding returned no coordinates',
                ];
                continue;
            }

            if ($dryRun) {
                $result['updated']++;
                $result['rows'][] = [
                    'id' => (int)$entry['id'],
                    'name' => $entry['name'] ?? '',
                    'location' => $entry['location'] ?? '',
                    'status' => 'updated',
                    'message' => 'Would update coordinates',
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ];
                continue;
            }

            $updateStmt->execute([
                ':latitude' => $coords['latitude'],
                ':longitude' => $coords['longitude'],
                ':id' => (int)$entry['id'],
            ]);

            if ($updateStmt->rowCount() > 0) {
                $result['updated']++;
                $result['rows'][] = [
                    'id' => (int)$entry['id'],
                    'name' => $entry['name'] ?? '',
                    'location' => $entry['location'] ?? '',
                    'status' => 'updated',
                    'message' => 'Updated coordinates',
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ];
            } else {
                $result['skipped']++;
                $result['rows'][] = [
                    'id' => (int)$entry['id'],
                    'name' => $entry['name'] ?? '',
                    'location' => $entry['location'] ?? '',
                    'status' => 'skipped',
                    'message' => 'Row was not modified',
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ];
            }
        }

        return $result;
    }

    private function findEntryByTelnet(string $telnetHost, int $telnetPort): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM bbs_directory
            WHERE LOWER(telnet_host) = LOWER(?) AND telnet_port = ?
            LIMIT 1
        ");
        $stmt->execute([$telnetHost, $telnetPort]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findEntryByName(string $name): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bbs_directory WHERE LOWER(name) = LOWER(?)");
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function resolveCoordinates(?string $location, ?array $existingEntry = null, bool $locationChanged = true): array
    {
        $normalizedLocation = $this->normalizeLocation($location);
        $existingLocation = $this->normalizeLocation($existingEntry['location'] ?? null);
        $hasExistingCoords = isset($existingEntry['latitude'], $existingEntry['longitude'])
            && $existingEntry['latitude'] !== null
            && $existingEntry['longitude'] !== null;

        if (!$locationChanged && $existingLocation === $normalizedLocation && $hasExistingCoords) {
            return [
                'latitude' => (float)$existingEntry['latitude'],
                'longitude' => (float)$existingEntry['longitude'],
            ];
        }

        if ($normalizedLocation === null) {
            return ['latitude' => null, 'longitude' => null];
        }

        $coords = $this->geocoder->geocodeLocation($normalizedLocation);
        if ($coords !== null) {
            return $coords;
        }

        return ['latitude' => null, 'longitude' => null];
    }

    private function normalizeLocation(?string $location): ?string
    {
        $location = trim((string)$location);
        if ($location === '') {
            return null;
        }

        $segments = array_map(static function ($segment) {
            $segment = trim((string)$segment);
            if ($segment === '') {
                return null;
            }

            $segment = preg_replace('/\s+/', ' ', $segment);
            return $segment === '' ? null : $segment;
        }, explode(',', $location));

        $segments = array_values(array_filter($segments, static fn($segment) => $segment !== null));
        if ($segments === []) {
            return null;
        }

        return implode(', ', $segments);
    }
}
