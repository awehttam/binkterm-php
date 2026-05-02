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
 * Tracks and rate-limits PacketBBS login attempts by sender node ID.
 *
 * Persists attempt records in packet_bbs_login_attempts. Blocks a node after
 * MAX_FAILURES failed attempts within a rolling WINDOW_MINUTES window. A
 * successful login clears the failure history for that node.
 */
class PacketBbsLoginRateLimit
{
    /** Maximum failed attempts allowed within the window before blocking. */
    private const MAX_FAILURES   = 5;

    /** Rolling window length in minutes. */
    private const WINDOW_MINUTES = 10;

    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Return true if the node is allowed to attempt a login, false if blocked.
     *
     * Blocks when either the source node or the target username has accumulated
     * MAX_FAILURES failed attempts within WINDOW_MINUTES, preventing brute-force
     * across multiple nodes targeting the same account.
     */
    public function check(string $nodeId, string $username = ''): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM packet_bbs_login_attempts
             WHERE node_id = ? AND success = FALSE
               AND attempted_at > NOW() - INTERVAL '1 minute' * ?"
        );
        $stmt->execute([$nodeId, self::WINDOW_MINUTES]);
        if ((int)$stmt->fetchColumn() >= self::MAX_FAILURES) {
            return false;
        }

        if ($username !== '') {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM packet_bbs_login_attempts
                 WHERE username = ? AND success = FALSE
                   AND attempted_at > NOW() - INTERVAL '1 minute' * ?"
            );
            $stmt->execute([$username, self::WINDOW_MINUTES]);
            if ((int)$stmt->fetchColumn() >= self::MAX_FAILURES) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record a failed login attempt for the node and username.
     */
    public function recordFailure(string $nodeId, string $username = ''): void
    {
        $this->db->prepare(
            "INSERT INTO packet_bbs_login_attempts (node_id, username, success) VALUES (?, ?, FALSE)"
        )->execute([$nodeId, $username !== '' ? $username : null]);
    }

    /**
     * Record a successful login and clear prior failure rows for the node and username.
     */
    public function recordSuccess(string $nodeId, string $username = ''): void
    {
        $this->db->prepare(
            "INSERT INTO packet_bbs_login_attempts (node_id, username, success) VALUES (?, ?, TRUE)"
        )->execute([$nodeId, $username !== '' ? $username : null]);
        $this->db->prepare(
            "DELETE FROM packet_bbs_login_attempts WHERE node_id = ? AND success = FALSE"
        )->execute([$nodeId]);
        if ($username !== '') {
            $this->db->prepare(
                "DELETE FROM packet_bbs_login_attempts WHERE username = ? AND success = FALSE"
            )->execute([$username]);
        }
    }

    /**
     * Delete attempt rows older than one hour (call opportunistically).
     */
    public function cleanOld(): void
    {
        $this->db->prepare(
            "DELETE FROM packet_bbs_login_attempts WHERE attempted_at < NOW() - INTERVAL '1 hour'"
        )->execute([]);
    }
}
