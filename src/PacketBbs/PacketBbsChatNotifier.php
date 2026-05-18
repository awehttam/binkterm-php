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

/**
 * Queues chat room and DM messages to the PacketBBS outbound queue so that
 * bridge nodes whose operators are currently in a chat room or DM session
 * receive messages posted by web and terminal users.
 */
class PacketBbsChatNotifier
{
    /**
     * Enqueue a room chat message for every PacketBBS session currently sitting
     * in that room. Called after the chat_messages row is committed.
     *
     * Failures are silently swallowed — chat must never break because of this.
     */
    public static function enqueueForRoom(\PDO $db, int $roomId, int $fromUserId, string $body): void
    {
        try {
            $userStmt = $db->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([$fromUserId]);
            $user = $userStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$user) {
                return;
            }

            $username = (string)$user['username'];
            $body     = str_replace(["\r\n", "\r", "\n"], ' ', trim($body));
            $payload  = mb_substr($username . ': ' . $body, 0, 200);

            // Find sessions that are currently in this chat room.
            // The JSONB cast is NULL-safe: missing key → NULL → NULL = ? → false.
            $stmt = $db->prepare(
                "SELECT node_id
                 FROM packet_bbs_sessions
                 WHERE menu_state = 'chat'
                   AND (session_state->'current_chat_room'->>'id')::int = ?"
            );
            $stmt->execute([$roomId]);
            $sessions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($sessions)) {
                return;
            }

            $insert = $db->prepare(
                'INSERT INTO packet_bbs_outbound_queue (node_id, payload) VALUES (?, ?)'
            );
            foreach ($sessions as $session) {
                $insert->execute([(string)$session['node_id'], $payload]);
            }
        } catch (\Throwable $e) {
            // Never let notification failures surface to the caller.
        }
    }

    /**
     * Enqueue a direct message for any PacketBBS session belonging to the
     * recipient that is currently in a DM conversation with the sender.
     * Called after the chat_messages row is committed.
     *
     * Failures are silently swallowed — DMs must never break because of this.
     */
    public static function enqueueForDm(\PDO $db, int $toUserId, int $fromUserId, string $body): void
    {
        try {
            $userStmt = $db->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([$fromUserId]);
            $user = $userStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$user) {
                return;
            }

            $username = (string)$user['username'];
            $body     = str_replace(["\r\n", "\r", "\n"], ' ', trim($body));
            $payload  = mb_substr($username . ': ' . $body, 0, 200);

            // Find sessions belonging to the recipient that are in DM mode with
            // the sender. Both conditions must match so we only deliver when the
            // recipient is actively viewing this conversation.
            $stmt = $db->prepare(
                "SELECT node_id
                 FROM packet_bbs_sessions
                 WHERE menu_state = 'chat'
                   AND user_id = ?
                   AND (session_state->'current_chat_dm'->>'user_id')::int = ?"
            );
            $stmt->execute([$toUserId, $fromUserId]);
            $sessions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($sessions)) {
                return;
            }

            $insert = $db->prepare(
                'INSERT INTO packet_bbs_outbound_queue (node_id, payload) VALUES (?, ?)'
            );
            foreach ($sessions as $session) {
                $insert->execute([(string)$session['node_id'], $payload]);
            }
        } catch (\Throwable $e) {
            // Never let notification failures surface to the caller.
        }
    }
}
