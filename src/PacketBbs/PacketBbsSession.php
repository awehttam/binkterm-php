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
use BinktermPHP\BbsConfig;
use BinktermPHP\Config;

/**
 * Manages per-node session state persisted in packet_bbs_sessions.
 *
 * Authentication expires independently of retained sender rows. QUIT or stale-row cleanup deletes rows.
 */
class PacketBbsSession
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * Load an existing session, expiring idle authentication without refreshing activity.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $nodeId): ?array
    {
        $this->expireIdleAuthentication($nodeId);
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
             ON CONFLICT (node_id) DO NOTHING
             RETURNING *'
        );
        $stmt->execute([$nodeId, $bridgeNodeId]);
        $created = $stmt->fetch(\PDO::FETCH_ASSOC);
        // Claim legacy rows and check binding before loading/expiring any state.
        if ($bridgeNodeId !== null && $bridgeNodeId !== ''
            && !$this->isBridgeAuthorized($nodeId, $bridgeNodeId)) {
            return null;
        }
        $row = $created ? $this->normalizeRow($created) : $this->load($nodeId);

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

        if (array_key_exists('user_id', $changes)) {
            $this->withSessionLock($nodeId, function () use ($nodeId, $setClauses, $params): void {
                $this->db->prepare('DELETE FROM packet_bbs_outbound_queue WHERE node_id = ? AND sent_at IS NULL')
                    ->execute([$nodeId]);
                $sql = 'UPDATE packet_bbs_sessions SET ' . implode(', ', $setClauses) . ' WHERE node_id = ?';
                $this->db->prepare($sql)->execute(array_merge($params, [$nodeId]));
            });
            return;
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
     * - a legacy row is atomically claimed by this bridge, or
     * - the session's bridge_node_id matches $bridgeNodeId.
     */
    public function isBridgeAuthorized(string $nodeId, string $bridgeNodeId): bool
    {
        if ($bridgeNodeId === '') {
            return false;
        }
        // Only the first authorized caller can claim a legacy row. A competing
        // UPDATE rechecks the predicate after the first claimant releases its lock.
        $this->db->prepare(
            "UPDATE packet_bbs_sessions SET bridge_node_id = ?
             WHERE node_id = ? AND (bridge_node_id IS NULL OR bridge_node_id = '')"
        )->execute([$bridgeNodeId, $nodeId]);

        $stmt = $this->db->prepare(
            'SELECT bridge_node_id FROM packet_bbs_sessions WHERE node_id = ?'
        );
        $stmt->execute([$nodeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return true;
        }

        $existing = $row['bridge_node_id'] ?? null;
        return $existing === $bridgeNodeId;
    }

    /**
     * Delete a session (on QUIT or explicit logout), also removing the linked
     * user_sessions row so the user goes offline immediately.
     */
    public function destroy(string $nodeId): void
    {
        $this->withSessionLock($nodeId, function () use ($nodeId): void {
            $this->db->prepare(
                "WITH retired AS (
                    DELETE FROM packet_bbs_sessions WHERE node_id = ?
                    RETURNING node_id, bbs_session_id
                 ), discarded AS (
                    DELETE FROM packet_bbs_outbound_queue q USING retired r
                    WHERE q.node_id = r.node_id AND q.sent_at IS NULL
                 ) DELETE FROM user_sessions WHERE session_id IN (SELECT bbs_session_id FROM retired)"
            )->execute([$nodeId]);
        });
    }

    /**
     * Coordinate identity transitions with chat producers holding FOR SHARE.
     * Purge in a separate statement after locking: its fresh READ COMMITTED
     * snapshot must include notifications committed while we waited for the lock.
     */
    private function withSessionLock(string $nodeId, callable $operation): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare('SELECT node_id FROM packet_bbs_sessions WHERE node_id = ? FOR UPDATE')
                ->execute([$nodeId]);
            $operation();
            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Revoke idle authentication without changing the inactivity timestamp or
     * sender/bridge binding. The retained notice is consumed by the next command.
     * All authenticated interaction state, including queued chat, is discarded.
     */
    public function expireIdleAuthentication(?string $nodeId = null): void
    {
        $minutes = (int)(BbsConfig::getConfig()['packet_bbs']['session_timeout_minutes'] ?? 15);
        $params = [$minutes];
        $nodeFilter = '';
        if ($nodeId !== null) {
            $nodeFilter = ' AND node_id = ?';
            $params[] = $nodeId;
        }
        $this->revokeAuthentication("last_activity_at < NOW() - INTERVAL '1 minute' * ?$nodeFilter", $params);
    }

    /**
     * Revoke one node's authentication immediately because its linked
     * user_sessions row no longer validates (revoked elsewhere: admin kick,
     * revoke-all, logout, expiry). Same reset as idle expiry -- identity,
     * link, menu, drafts and queued private content are all discarded and the
     * expiry notice is retained -- without touching the inactivity timestamp
     * and without creating any replacement user_sessions row.
     */
    public function revokeUnlinkedAuthentication(string $nodeId): void
    {
        $this->revokeAuthentication('node_id = ?', [$nodeId]);
    }

    /**
     * Lock the matching authenticated rows and revoke their identities in one
     * statement. $predicate is ANDed with user_id IS NOT NULL.
     *
     * @param list<mixed> $params
     */
    private function revokeAuthentication(string $predicate, array $params): void
    {
        $this->db->prepare(
            "WITH expired AS (
                SELECT node_id, bbs_session_id FROM packet_bbs_sessions
                WHERE user_id IS NOT NULL
                  AND $predicate
                FOR UPDATE
             ), reset AS (
                UPDATE packet_bbs_sessions s SET
                    user_id = NULL, bbs_session_id = NULL, menu_state = 'main',
                    pagination_cursor = 1, pagination_context = NULL,
                    compose_buffer = NULL, compose_type = NULL, compose_meta = NULL,
                    session_state = '{\"auth_expired\":true}'::jsonb
                FROM expired e WHERE s.node_id = e.node_id
             ), discarded AS (
                DELETE FROM packet_bbs_outbound_queue q USING expired e
                WHERE q.node_id = e.node_id AND q.sent_at IS NULL
             )
             DELETE FROM user_sessions WHERE session_id IN (SELECT bbs_session_id FROM expired)"
        )->execute($params);
    }

    /**
     * Prune stale sender rows using independent retention (default 24 hours).
     * Authentication expiry never refreshes the timestamp used by this cleanup.
     */
    public function cleanExpired(): void
    {
        $this->expireIdleAuthentication();
        $seconds = (int)Config::env('PACKETBBS_SESSION_RETENTION_SECONDS', '86400');
        if ($seconds <= 0) {
            $seconds = 86400;
        }
        $this->db->prepare(
            "WITH stale AS (
                SELECT node_id, bbs_session_id FROM packet_bbs_sessions
                WHERE last_activity_at < NOW() - INTERVAL '1 second' * ? FOR UPDATE
             ), removed AS (
                DELETE FROM packet_bbs_sessions s USING stale t WHERE s.node_id = t.node_id
             ), discarded AS (
                DELETE FROM packet_bbs_outbound_queue q USING stale t
                WHERE q.node_id = t.node_id AND q.sent_at IS NULL
             )
             DELETE FROM user_sessions WHERE session_id IN (SELECT bbs_session_id FROM stale)"
        )->execute([$seconds]);
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
