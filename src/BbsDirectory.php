<?php

namespace BinktermPHP;

/**
 * Data-access class for the BBS directory.
 */
class BbsDirectory
{
    /** @var \PDO */
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
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
            WHERE is_active = TRUE
            ORDER BY LOWER(name) ASC
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
     * Upsert a BBS entry by name (case-insensitive). Sets source='auto' and updates last_seen.
     * Used by robot processors.
     *
     * @param array $data Must contain 'name'; optionally sysop, location, os, telnet_host, telnet_port, website
     * @return int The ID of the inserted or updated row
     */
    public function upsertByName(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bbs_directory
                (name, sysop, location, os, telnet_host, telnet_port, website, source, last_seen, is_active, created_at, updated_at)
            VALUES
                (:name, :sysop, :location, :os, :telnet_host, :telnet_port, :website, 'auto', NOW(), TRUE, NOW(), NOW())
            ON CONFLICT (LOWER(name)) DO UPDATE SET
                sysop        = EXCLUDED.sysop,
                location     = EXCLUDED.location,
                os           = EXCLUDED.os,
                telnet_host  = EXCLUDED.telnet_host,
                telnet_port  = EXCLUDED.telnet_port,
                website      = COALESCE(EXCLUDED.website, bbs_directory.website),
                source       = CASE WHEN bbs_directory.source = 'manual' THEN 'manual' ELSE 'auto' END,
                last_seen    = NOW(),
                is_active    = TRUE,
                updated_at   = NOW()
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
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
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
        $stmt = $this->db->prepare("
            INSERT INTO bbs_directory
                (name, sysop, location, os, telnet_host, telnet_port, website, notes, source, is_active, created_at, updated_at)
            VALUES
                (:name, :sysop, :location, :os, :telnet_host, :telnet_port, :website, :notes, 'manual', :is_active, NOW(), NOW())
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
            ':is_active'   => isset($data['is_active']) ? ($data['is_active'] ? 'true' : 'false') : 'true',
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
                is_active   = :is_active,
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
            ':is_active'   => isset($data['is_active']) ? ($data['is_active'] ? 'true' : 'false') : 'true',
            ':id'          => $id,
        ]);

        return $stmt->rowCount() > 0;
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
}
