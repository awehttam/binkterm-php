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

class Auth
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    public function login($username, $password, string $service = 'web')
    {
        $stmt = $this->db->prepare('SELECT id, password_hash FROM users WHERE username = ? AND is_active = TRUE');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $sessionId = $this->createSession($user['id'], $service);
            $this->updateLastLogin($user['id']);
            return $sessionId;
        }

        return false;
    }

    public function logout($sessionId)
    {
        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE session_id = ?');
        $stmt->execute([$sessionId]);
    }

    public function validateSession($sessionId)
    {
        $stmt = $this->db->prepare('
            SELECT s.user_id, u.username, u.real_name, u.email, u.is_admin, u.password_hash, u.created_at, u.last_login, u.location, u.fidonet_address, u.timezone
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.session_id = ? AND s.expires_at > NOW() AND u.is_active = TRUE
        ');
        $stmt->execute([$sessionId]);
        $user = $stmt->fetch();

        // Update last_activity for online tracking
        if ($user) {
            $this->updateLastActivity($sessionId);
        }

        return $user;
    }

    /**
     * Update session last_activity timestamp
     */
    private function updateLastActivity($sessionId)
    {
        $stmt = $this->db->prepare('UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ?');
        $stmt->execute([$sessionId]);
    }

    public function updateSessionActivity(string $sessionId, string $activity): void
    {
        $activity = trim($activity);
        if ($activity === '') {
            return;
        }
        $stmt = $this->db->prepare('UPDATE user_sessions SET last_activity = NOW(), activity = ? WHERE session_id = ?');
        $stmt->execute([mb_substr($activity, 0, 255), $sessionId]);
    }

    public function createSession($userId, string $service = 'web')
    {
        $sessionId = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare('
            INSERT INTO user_sessions (session_id, user_id, expires_at, ip_address, user_agent, last_activity, service)
            VALUES (?, ?, NOW() + INTERVAL \'' . Config::SESSION_LIFETIME . ' seconds\', ?, ?, NOW(), ?)
        ');
        $stmt->execute([
            $sessionId,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $service
        ]);

        return $sessionId;
    }

    private function updateLastLogin($userId)
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $stmt->execute([$userId]);
    }

    public function getCurrentUser()
    {
        $sessionId = $_COOKIE['binktermphp_session'] ?? null;
        if ($sessionId) {
            $user = $this->validateSession($sessionId);
            if ($user) {
                $userId = $user['user_id'] ?? $user['id'] ?? null;
                if ($userId) {
                    $shouldCheck = true;
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $key = 'daily_credit_last_check_' . (int)$userId;
                        $lastCheck = isset($_SESSION[$key]) ? (int)$_SESSION[$key] : 0;
                        if (time() - $lastCheck < 60) {
                            $shouldCheck = false;
                        } else {
                            $_SESSION[$key] = time();
                        }
                    }

                    if ($shouldCheck) {
                        try {
                            UserCredit::processDaily((int)$userId);
                            UserCredit::process14DayReturn((int)$userId);
                        } catch (\Throwable $e) {
                            error_log('[CREDITS] Daily processing failed: ' . $e->getMessage());
                        }
                    }

                }
            }
            return $user;
        }
        return null;
    }

    public function requireAuth()
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            header('HTTP/1.1 401 Unauthorized');
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        return $user;
    }

    public function cleanExpiredSessions()
    {
        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE expires_at < NOW()');
        $stmt->execute();
    }

    /**
     * Get list of online users (active within specified minutes)
     *
     * @param int $minutes Consider users online if active within this many minutes
     * @return array List of online users
     */
    public function getOnlineUsers(int $minutes = 15): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT ON (u.id)
                u.id as user_id,
                u.username,
                u.real_name,
                u.location,
                u.fidonet_address,
                s.last_activity,
                s.activity,
                s.ip_address,
                s.service
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.last_activity > NOW() - INTERVAL '1 minute' * ?
              AND s.expires_at > NOW()
              AND u.is_active = TRUE
            ORDER BY u.id, s.last_activity DESC
        ");
        $stmt->execute([$minutes]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get count of online users
     *
     * @param int $minutes Consider users online if active within this many minutes
     * @return int Number of online users
     */
    public function getOnlineUserCount(int $minutes = 15): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT s.user_id) as count
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.last_activity > NOW() - INTERVAL '1 minute' * ?
              AND s.expires_at > NOW()
              AND u.is_active = TRUE
        ");
        $stmt->execute([$minutes]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Update user's location
     *
     * @param int $userId User ID
     * @param string $location Location string
     * @return bool Success
     */
    public function updateUserLocation(int $userId, string $location): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET location = ? WHERE id = ?');
        return $stmt->execute([$location, $userId]);
    }

    /**
     * Generate a gateway token for external service authentication
     *
     * @param int $userId User ID
     * @param string|null $door Optional door/service name
     * @param int $ttlSeconds Token lifetime in seconds (default 300 = 5 minutes)
     * @return string The generated token
     */
    public function generateGatewayToken(int $userId, ?string $door = null, int $ttlSeconds = 300): string
    {
        // Clean up expired tokens for this user
        $this->cleanupExpiredGatewayTokens($userId);

        // Generate a secure random token
        $token = bin2hex(random_bytes(32));

        // Use database NOW() + INTERVAL to avoid timezone issues
        $stmt = $this->db->prepare("
            INSERT INTO gateway_tokens (user_id, token, expires_at, door)
            VALUES (?, ?, NOW() + INTERVAL '1 second' * ?, ?)
        ");
        $stmt->execute([$userId, $token, $ttlSeconds, $door]);

        return $token;
    }

    /**
     * Verify a gateway token and return user info if valid
     *
     * @param int $userId User ID
     * @param string $token The token to verify
     * @return array|false User info array if valid, false if invalid
     */
    public function verifyGatewayToken(int $userId, string $token): array|false
    {
        // Debug: Check if token exists at all
        $debugStmt = $this->db->prepare("
            SELECT gt.*, u.is_active as user_active
            FROM gateway_tokens gt
            LEFT JOIN users u ON gt.user_id = u.id
            WHERE gt.token = ?
        ");
        $debugStmt->execute([$token]);
        $debugResult = $debugStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$debugResult) {
            error_log("[GATEWAY] Token not found in database: $token");
        } else {
            // Check database NOW() vs expires_at
            $nowStmt = $this->db->query("SELECT NOW() as db_now");
            $dbNow = $nowStmt->fetch(\PDO::FETCH_ASSOC)['db_now'];
            //error_log("[GATEWAY] Token found - user_id in token: {$debugResult['user_id']}, requested user_id: $userId, expires_at: {$debugResult['expires_at']}, db_now: $dbNow, used_at: " . ($debugResult['used_at'] ?? 'NULL') . ", user_active: " . ($debugResult['user_active'] ? 'true' : 'false'));
        }

        // Find the token
        $stmt = $this->db->prepare("
            SELECT gt.*, u.username, u.real_name, u.email, u.location
            FROM gateway_tokens gt
            JOIN users u ON gt.user_id = u.id
            WHERE gt.user_id = ? AND gt.token = ? AND gt.expires_at > NOW() AND gt.used_at IS NULL AND u.is_active = TRUE
        ");
        $stmt->execute([$userId, $token]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        // Mark token as used
        $updateStmt = $this->db->prepare("UPDATE gateway_tokens SET used_at = NOW() WHERE id = ?");
        $updateStmt->execute([$result['id']]);

        return [
            'user_id' => $result['user_id'],
            'username' => $result['username'],
            //'real_name' => $result['real_name'],
            //'email' => $result['email'],
            //'location' => $result['location'],
            'door' => $result['door']
        ];
    }

    /**
     * Clean up expired gateway tokens for a user
     *
     * @param int|null $userId Optional user ID, or null to clean all expired tokens
     */
    public function cleanupExpiredGatewayTokens(?int $userId = null): void
    {
        if ($userId !== null) {
            $stmt = $this->db->prepare("DELETE FROM gateway_tokens WHERE user_id = ? AND (expires_at < NOW() OR used_at IS NOT NULL)");
            $stmt->execute([$userId]);
        } else {
            $this->db->exec("DELETE FROM gateway_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL");
        }
    }
}

