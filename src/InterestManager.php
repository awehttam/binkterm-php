<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 */

namespace BinktermPHP;

/**
 * Manages interests: admin-defined topic groups that bundle echo areas and file areas.
 * Users can subscribe to an interest to auto-subscribe to its member echo areas.
 */
class InterestManager
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    // -------------------------------------------------------------------------
    // Public query methods (shared between user-facing and admin)
    // -------------------------------------------------------------------------

    /**
     * Return all interests with echoarea count, filearea count, and subscriber count.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getInterests(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE i.is_active = TRUE' : '';
        $stmt = $this->db->query("
            SELECT
                i.*,
                (SELECT COUNT(*) FROM interest_echoareas ie WHERE ie.interest_id = i.id) AS echoarea_count,
                (SELECT COUNT(*) FROM interest_fileareas if2 WHERE if2.interest_id = i.id) AS filearea_count,
                (SELECT COUNT(*) FROM user_interest_subscriptions uis WHERE uis.interest_id = i.id) AS subscriber_count
            FROM interests i
            {$where}
            ORDER BY i.sort_order ASC, i.name ASC
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Return a single interest by ID with its echo area and file area lists.
     *
     * @return array<string,mixed>|null
     */
    public function getInterest(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM interests WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['echoareas'] = $this->getInterestEchoAreaDetails($id);
        $row['fileareas'] = $this->getInterestFileareas($id);
        return $row;
    }

    /**
     * Return a single interest by slug.
     *
     * @return array<string,mixed>|null
     */
    public function getInterestBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM interests WHERE slug = ?");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $row;
    }

    /**
     * Return echo area IDs for an interest.
     *
     * @return int[]
     */
    public function getInterestEchoareaIds(int $interestId): array
    {
        $stmt = $this->db->prepare("SELECT echoarea_id FROM interest_echoareas WHERE interest_id = ?");
        $stmt->execute([$interestId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Return file area IDs for an interest (flat array of ints).
     *
     * @return int[]
     */
    public function getInterestFileareaIds(int $interestId): array
    {
        $stmt = $this->db->prepare("SELECT filearea_id FROM interest_fileareas WHERE interest_id = ?");
        $stmt->execute([$interestId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Return only the interest echo areas the given user is actively subscribed to.
     *
     * @return int[]
     */
    public function getUserSubscribedInterestEchoareaIds(int $interestId, int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT ie.echoarea_id
            FROM interest_echoareas ie
            INNER JOIN echoareas e ON e.id = ie.echoarea_id
            INNER JOIN user_echoarea_subscriptions ues
                ON ues.echoarea_id = ie.echoarea_id
               AND ues.user_id = ?
               AND ues.is_active = TRUE
            WHERE ie.interest_id = ?
              AND e.is_active = TRUE
        ");
        $stmt->execute([$userId, $interestId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Return echo area rows (id, tag, description) for an interest.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getInterestEchoAreaDetails(int $interestId): array
    {
        $stmt = $this->db->prepare("
            SELECT e.id, e.tag, e.description
            FROM echoareas e
            INNER JOIN interest_echoareas ie ON ie.echoarea_id = e.id
            WHERE ie.interest_id = ?
            ORDER BY e.tag ASC
        ");
        $stmt->execute([$interestId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Return file area rows for an interest.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getInterestFileareas(int $interestId): array
    {
        $stmt = $this->db->prepare("
            SELECT f.id, f.tag, f.description
            FROM file_areas f
            INNER JOIN interest_fileareas if2 ON if2.filearea_id = f.id
            WHERE if2.interest_id = ?
            ORDER BY f.tag ASC
        ");
        $stmt->execute([$interestId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Return a map of echoarea_id => [interest_id, ...] for all active interests.
     * Used to annotate echo area lists with their interest memberships.
     *
     * @return array<int,int[]>
     */
    public function getEchoareaInterestMap(): array
    {
        $stmt = $this->db->query("
            SELECT ie.echoarea_id, ie.interest_id
            FROM interest_echoareas ie
            INNER JOIN interests i ON i.id = ie.interest_id AND i.is_active = TRUE
        ");
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['echoarea_id']][] = (int)$row['interest_id'];
        }
        return $map;
    }

    /**
     * Return IDs of interests the user is subscribed to.
     *
     * @return int[]
     */
    public function getUserSubscribedInterestIds(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT interest_id FROM user_interest_subscriptions WHERE user_id = ?");
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // -------------------------------------------------------------------------
    // User subscription methods
    // -------------------------------------------------------------------------

    /**
     * Subscribe a user to an interest.
     * Also subscribes the user to all member echo areas they haven't explicitly unsubscribed from.
     */
    public function subscribeUser(int $userId, int $interestId): bool
    {
        // Insert the interest-level subscription (idempotent)
        $stmt = $this->db->prepare("
            INSERT INTO user_interest_subscriptions (user_id, interest_id)
            VALUES (?, ?)
            ON CONFLICT (user_id, interest_id) DO NOTHING
        ");
        $stmt->execute([$userId, $interestId]);

        // Subscribe to echo areas that the user hasn't explicitly unsubscribed from
        $echoareaIds = $this->getInterestEchoareaIds($interestId);
        foreach ($echoareaIds as $echoareaId) {
            $this->subscribeUserToEchoarea($userId, $interestId, $echoareaId);
        }

        return true;
    }

    /**
     * Unsubscribe a user from an interest.
     *
     * Removes the source tracking rows for this interest, then removes each
     * echo area subscription that has no remaining interest sources — i.e.
     * only if no other interest the user is still subscribed to also covers
     * that echo area.
     */
    public function unsubscribeUser(int $userId, int $interestId): bool
    {
        // Remove the interest-level subscription.
        $stmt = $this->db->prepare("DELETE FROM user_interest_subscriptions WHERE user_id = ? AND interest_id = ?");
        $stmt->execute([$userId, $interestId]);

        // Remove source-tracking rows for this interest.
        $stmt = $this->db->prepare("DELETE FROM user_echoarea_interest_sources WHERE user_id = ? AND interest_id = ?");
        $stmt->execute([$userId, $interestId]);

        // Delete echo area subscriptions that are no longer covered by any
        // remaining interest source for this user.
        $stmt = $this->db->prepare("
            DELETE FROM user_echoarea_subscriptions
            WHERE user_id = ?
              AND interest_id = ?
              AND echoarea_id NOT IN (
                  SELECT echoarea_id
                  FROM user_echoarea_interest_sources
                  WHERE user_id = ?
              )
        ");
        $stmt->execute([$userId, $interestId, $userId]);

        return true;
    }

    /**
     * Subscribe a user to a specific subset of echo areas within an interest.
     * Records the interest-level subscription (idempotent) then subscribes only
     * the provided echo areas.
     *
     * @param int[] $echoareaIds
     */
    public function subscribeUserToSelectedEchoareas(int $userId, int $interestId, array $echoareaIds): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_interest_subscriptions (user_id, interest_id)
            VALUES (?, ?)
            ON CONFLICT (user_id, interest_id) DO NOTHING
        ");
        $stmt->execute([$userId, $interestId]);

        foreach ($echoareaIds as $echoareaId) {
            $this->subscribeUserToEchoarea($userId, $interestId, (int)$echoareaId);
        }
        return true;
    }

    /**
     * Unsubscribe a user from a specific subset of echo areas within an interest.
     * Removes the interest-level subscription if no sourced areas remain.
     *
     * @param int[] $echoareaIds
     */
    public function unsubscribeUserFromSelectedEchoareas(int $userId, int $interestId, array $echoareaIds): bool
    {
        foreach ($echoareaIds as $echoareaId) {
            // Remove source tracking for this (user, area, interest) tuple.
            $stmt = $this->db->prepare("
                DELETE FROM user_echoarea_interest_sources
                WHERE user_id = ? AND echoarea_id = ? AND interest_id = ?
            ");
            $stmt->execute([$userId, (int)$echoareaId, $interestId]);

            // Remove the subscription only if no other interest still sources it.
            $stmt = $this->db->prepare("
                DELETE FROM user_echoarea_subscriptions
                WHERE user_id = ? AND echoarea_id = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM user_echoarea_interest_sources
                      WHERE user_id = ? AND echoarea_id = ?
                  )
            ");
            $stmt->execute([$userId, (int)$echoareaId, $userId, (int)$echoareaId]);
        }

        // Remove the interest-level subscription if no sourced areas remain.
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_echoarea_interest_sources
            WHERE user_id = ? AND interest_id = ?
        ");
        $stmt->execute([$userId, $interestId]);
        $remaining = (int)$stmt->fetchColumn();

        if ($remaining === 0) {
            $stmt = $this->db->prepare("
                DELETE FROM user_interest_subscriptions WHERE user_id = ? AND interest_id = ?
            ");
            $stmt->execute([$userId, $interestId]);
        }

        return true;
    }

    /**
     * Check whether a user is subscribed to an interest.
     */
    public function isUserSubscribed(int $userId, int $interestId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM user_interest_subscriptions WHERE user_id = ? AND interest_id = ?
        ");
        $stmt->execute([$userId, $interestId]);
        return (bool)$stmt->fetch();
    }

    // -------------------------------------------------------------------------
    // Admin CRUD methods
    // -------------------------------------------------------------------------

    /**
     * Create a new interest. Generates a slug from name if not provided.
     * Returns the new row ID.
     */
    public function createInterest(array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Interest name is required.');
        }

        $slug = isset($data['slug']) && trim((string)$data['slug']) !== ''
            ? $this->normalizeSlug((string)$data['slug'])
            : $this->generateSlug($name);

        $stmt = $this->db->prepare("
            INSERT INTO interests (slug, name, description, icon, color, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $slug,
            $name,
            trim((string)($data['description'] ?? '')),
            trim((string)($data['icon'] ?? 'fa-layer-group')) ?: 'fa-layer-group',
            trim((string)($data['color'] ?? '#6c757d')) ?: '#6c757d',
            (int)($data['sort_order'] ?? 0),
            isset($data['is_active']) ? ($data['is_active'] ? 'true' : 'false') : 'true',
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)$row['id'];
    }

    /**
     * Update interest metadata.
     */
    public function updateInterest(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = trim((string)$data['name']);
        }
        if (isset($data['slug'])) {
            $fields[] = 'slug = ?';
            $params[] = $this->normalizeSlug((string)$data['slug']);
        }
        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[] = trim((string)$data['description']);
        }
        if (isset($data['icon'])) {
            $fields[] = 'icon = ?';
            $params[] = trim((string)$data['icon']) ?: 'fa-layer-group';
        }
        if (isset($data['color'])) {
            $fields[] = 'color = ?';
            $params[] = trim((string)$data['color']) ?: '#6c757d';
        }
        if (isset($data['sort_order'])) {
            $fields[] = 'sort_order = ?';
            $params[] = (int)$data['sort_order'];
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 'true' : 'false';
        }

        if (empty($fields)) {
            return true;
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $id;

        $sql = 'UPDATE interests SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an interest. Cascades to junction tables and user subscriptions.
     */
    public function deleteInterest(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM interests WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Atomically replace all echo areas for an interest.
     * After replacing, propagates new areas to existing interest subscribers.
     *
     * @param int[] $echoareaIds
     */
    public function setEchoareas(int $interestId, array $echoareaIds): void
    {
        $this->db->beginTransaction();
        try {
            // Get currently assigned areas before replacing
            $existingIds = $this->getInterestEchoareaIds($interestId);

            // Replace junction rows
            $this->db->prepare("DELETE FROM interest_echoareas WHERE interest_id = ?")->execute([$interestId]);
            foreach ($echoareaIds as $echoareaId) {
                $stmt = $this->db->prepare("
                    INSERT INTO interest_echoareas (interest_id, echoarea_id) VALUES (?, ?)
                    ON CONFLICT DO NOTHING
                ");
                $stmt->execute([$interestId, (int)$echoareaId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Propagate newly added areas to existing interest subscribers
        $newIds = array_diff($echoareaIds, $existingIds);
        if (!empty($newIds)) {
            $this->propagateNewEchoareasToSubscribers($interestId, $newIds);
        }
    }

    /**
     * Atomically replace all file areas for an interest.
     *
     * @param int[] $fileareaIds
     */
    public function setFileareas(int $interestId, array $fileareaIds): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM interest_fileareas WHERE interest_id = ?")->execute([$interestId]);
            foreach ($fileareaIds as $fileareaId) {
                $stmt = $this->db->prepare("
                    INSERT INTO interest_fileareas (interest_id, filearea_id) VALUES (?, ?)
                    ON CONFLICT DO NOTHING
                ");
                $stmt->execute([$interestId, (int)$fileareaId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Slug helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a unique URL-friendly slug from a name.
     * Appends -2, -3, etc. on collision.
     */
    public function generateSlug(string $name): string
    {
        $base = $this->normalizeSlug($name);
        if ($base === '') {
            $base = 'interest';
        }

        $slug = $base;
        $counter = 2;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $counter;
            $counter++;
        }
        return $slug;
    }

    /**
     * Normalize a string into a URL-safe slug.
     */
    private function normalizeSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Check whether a slug is already in use.
     */
    private function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare("SELECT 1 FROM interests WHERE slug = ? AND id != ?");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $this->db->prepare("SELECT 1 FROM interests WHERE slug = ?");
            $stmt->execute([$slug]);
        }
        return (bool)$stmt->fetch();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Subscribe a single user to a single echo area on behalf of an interest.
     * Skips areas the user has explicitly unsubscribed from (is_active = false).
     */
    private function subscribeUserToEchoarea(int $userId, int $interestId, int $echoareaId): void
    {
        // Skip if the user explicitly unsubscribed from this area
        $stmt = $this->db->prepare("
            SELECT is_active FROM user_echoarea_subscriptions
            WHERE user_id = ? AND echoarea_id = ?
        ");
        $stmt->execute([$userId, $echoareaId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing !== false) {
            if ($existing['is_active'] == false) {
                // User explicitly unsubscribed — respect that, do not re-subscribe
                return;
            }
            // Already active — record the source so unsubscribe from this interest
            // doesn't remove the area if another interest also covers it.
            $stmt = $this->db->prepare("
                INSERT INTO user_echoarea_interest_sources (user_id, echoarea_id, interest_id)
                VALUES (?, ?, ?)
                ON CONFLICT DO NOTHING
            ");
            $stmt->execute([$userId, $echoareaId, $interestId]);
            return;
        }

        // No existing row: create a new interest-sourced subscription and record the source.
        $stmt = $this->db->prepare("
            INSERT INTO user_echoarea_subscriptions
                (user_id, echoarea_id, is_active, subscription_type, interest_id)
            VALUES (?, ?, 'true', 'interest', ?)
            ON CONFLICT (user_id, echoarea_id) DO NOTHING
        ");
        $stmt->execute([$userId, $echoareaId, $interestId]);

        $stmt = $this->db->prepare("
            INSERT INTO user_echoarea_interest_sources (user_id, echoarea_id, interest_id)
            VALUES (?, ?, ?)
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute([$userId, $echoareaId, $interestId]);
    }

    /**
     * Propagate newly added echo areas to all existing subscribers of an interest.
     *
     * @param int[] $newEchoareaIds
     */
    private function propagateNewEchoareasToSubscribers(int $interestId, array $newEchoareaIds): void
    {
        // Get all current subscribers
        $stmt = $this->db->prepare("SELECT user_id FROM user_interest_subscriptions WHERE interest_id = ?");
        $stmt->execute([$interestId]);
        $subscribers = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($subscribers as $userId) {
            foreach ($newEchoareaIds as $echoareaId) {
                $this->subscribeUserToEchoarea((int)$userId, $interestId, (int)$echoareaId);
            }
        }
    }
}
