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
        $stmt = $this->db->prepare("
            INSERT INTO bbs_directory
                (name, sysop, location, os, telnet_host, telnet_port, website, notes,
                 source, status, is_active, submitted_by_user_id, created_at, updated_at)
            VALUES
                (:name, :sysop, :location, :os, :telnet_host, :telnet_port, :website, :notes,
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
     * Upsert a BBS entry by name (case-insensitive). Sets source='auto' and updates last_seen.
     * Used by robot processors and import scripts.
     *
     * @param array $data Must contain 'name'; optionally sysop, location, os, telnet_host,
     *                    telnet_port, ssh_port, website, software
     * @return int The ID of the inserted or updated row
     */
    public function upsertByName(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bbs_directory
                (name, sysop, location, os, telnet_host, telnet_port, ssh_port, website, software, source, status, last_seen, is_active, created_at, updated_at)
            VALUES
                (:name, :sysop, :location, :os, :telnet_host, :telnet_port, :ssh_port, :website, :software, 'auto', 'active', NOW(), TRUE, NOW(), NOW())
            ON CONFLICT (LOWER(name)) DO UPDATE SET
                sysop        = COALESCE(EXCLUDED.sysop,       bbs_directory.sysop),
                location     = COALESCE(EXCLUDED.location,    bbs_directory.location),
                os           = COALESCE(EXCLUDED.os,          bbs_directory.os),
                telnet_host  = COALESCE(EXCLUDED.telnet_host, bbs_directory.telnet_host),
                telnet_port  = EXCLUDED.telnet_port,
                ssh_port     = COALESCE(EXCLUDED.ssh_port,    bbs_directory.ssh_port),
                website      = COALESCE(EXCLUDED.website,     bbs_directory.website),
                software     = COALESCE(EXCLUDED.software,    bbs_directory.software),
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
            ':telnet_host' => $data['telnet_host'] ?? null,
            ':telnet_port' => isset($data['telnet_port']) && $data['telnet_port'] !== '' ? (int)$data['telnet_port'] : 23,
            ':ssh_port'    => isset($data['ssh_port']) && $data['ssh_port'] !== '' ? (int)$data['ssh_port'] : null,
            ':website'     => $data['website'] ?? null,
            ':software'    => $data['software'] ?? null,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            // Entry exists but is protected (is_local=TRUE) — return its id without modifying it
            $sel = $this->db->prepare("SELECT id FROM bbs_directory WHERE LOWER(name) = LOWER(?)");
            $sel->execute([$data['name']]);
            $row = $sel->fetch(\PDO::FETCH_ASSOC);
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
        $stmt = $this->db->prepare("
            INSERT INTO bbs_directory
                (name, sysop, location, os, telnet_host, telnet_port, website, notes, source, is_active, is_local, created_at, updated_at)
            VALUES
                (:name, :sysop, :location, :os, :telnet_host, :telnet_port, :website, :notes, 'manual', :is_active, :is_local, NOW(), NOW())
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
            ':is_active'   => isset($data['is_active']) ? ($data['is_active'] ? 'true' : 'false') : 'true',
            ':is_local'    => !empty($data['is_local']) ? 'true' : 'false',
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
