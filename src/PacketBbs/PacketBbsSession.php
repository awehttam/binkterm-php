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

namespace BinktermPHP\PacketBbs;

use BinktermPHP\Database;

/**
 * Manages per-node session state persisted in packet_bbs_sessions.
 *
 * Session rows are upserted on first contact and deleted on QUIT or timeout.
 */
class PacketBbsSession
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Load an existing session row, or null if none exists.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $nodeId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM packet_bbs_sessions WHERE node_id = ?');
        $stmt->execute([$nodeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    /**
     * Get an existing session or create a fresh one for the node.
     *
     * When $bridgeNodeId is provided it is written on creation. If the session
     * already exists and belongs to a different bridge, this method returns
     * null so the caller can reject the request.
     *
     * @return array<string,mixed>|null  null when the bridge binding is violated
     */
    public function getOrCreate(string $nodeId, ?string $bridgeNodeId = null): ?array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO packet_bbs_sessions (node_id, bridge_node_id)
             VALUES (?, ?)
             ON CONFLICT (node_id) DO UPDATE SET last_activity_at = NOW()
             RETURNING *'
        );
        $stmt->execute([$nodeId, $bridgeNodeId]);
        $row = $this->normalizeRow($stmt->fetch(\PDO::FETCH_ASSOC) ?: []);

        // Reject if the session is owned by a different bridge.
        if ($bridgeNodeId !== null && $bridgeNodeId !== '') {
            $existing = $row['bridge_node_id'] ?? null;
            if ($existing !== null && $existing !== '' && $existing !== $bridgeNodeId) {
                return null;
            }
        }

        return $row;
    }

    /**
     * Update arbitrary session columns for a node.
     *
     * Pass null values to clear a column (sets it to NULL in the DB).
     * The last_activity_at column is always refreshed.
     *
     * @param array<string,mixed> $changes
     */
    public function update(string $nodeId, array $changes): void
    {
        $setClauses = ['last_activity_at = NOW()'];
        $params     = [];

        $allowed = [
            'user_id', 'bbs_session_id', 'menu_state', 'pagination_cursor', 'pagination_context',
            'compose_buffer', 'compose_type', 'compose_meta', 'session_state',
        ];

        foreach ($allowed as $col) {
            if (!array_key_exists($col, $changes)) {
                continue;
            }
            $setClauses[] = "$col = ?";
            $val = $changes[$col];
            // JSON-encode array values for JSONB columns
            if (is_array($val)) {
                $val = json_encode($val);
            }
            $params[] = $val;
        }

        $params[] = $nodeId;
        $sql = 'UPDATE packet_bbs_sessions SET ' . implode(', ', $setClauses) . ' WHERE node_id = ?';
        $this->db->prepare($sql)->execute($params);
    }

    /**
     * Return true when the given bridge is permitted to act for $nodeId.
     *
     * Returns true when:
     * - no session exists yet (first contact — bridge will own it on getOrCreate), or
     * - the session has no recorded bridge (legacy row), or
     * - the session's bridge_node_id matches $bridgeNodeId.
     */
    public function isBridgeAuthorized(string $nodeId, string $bridgeNodeId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT bridge_node_id FROM packet_bbs_sessions WHERE node_id = ?'
        );
        $stmt->execute([$nodeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return true;
        }

        $existing = $row['bridge_node_id'] ?? null;
        return $existing === null || $existing === '' || $existing === $bridgeNodeId;
    }

    /**
     * Delete a session (on QUIT or explicit logout), also removing the linked
     * user_sessions row so the user goes offline immediately.
     */
    public function destroy(string $nodeId): void
    {
        $stmt = $this->db->prepare(
            'SELECT bbs_session_id FROM packet_bbs_sessions WHERE node_id = ?'
        );
        $stmt->execute([$nodeId]);
        $bbsSessionId = $stmt->fetchColumn();
        if ($bbsSessionId) {
            $this->db->prepare('DELETE FROM user_sessions WHERE session_id = ?')
                ->execute([$bbsSessionId]);
        }

        $this->db->prepare('DELETE FROM packet_bbs_sessions WHERE node_id = ?')->execute([$nodeId]);
    }

    /**
     * Prune sessions inactive for more than $minutes, also removing their
     * linked user_sessions rows so timed-out users go offline.
     */
    public function cleanExpired(int $minutes): void
    {
        $this->db->prepare(
            "DELETE FROM user_sessions
             WHERE session_id IN (
                 SELECT bbs_session_id FROM packet_bbs_sessions
                 WHERE last_activity_at < NOW() - INTERVAL '1 minute' * ?
                   AND bbs_session_id IS NOT NULL
             )"
        )->execute([$minutes]);

        $this->db->prepare(
            "DELETE FROM packet_bbs_sessions WHERE last_activity_at < NOW() - INTERVAL '1 minute' * ?"
        )->execute([$minutes]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        foreach (['compose_meta', 'session_state'] as $key) {
            if (!isset($row[$key]) || $row[$key] === '' || $row[$key] === null) {
                $row[$key] = [];
                continue;
            }

            if (is_array($row[$key])) {
                continue;
            }

            $decoded = json_decode((string)$row[$key], true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
}
