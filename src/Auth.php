<?php

namespace BinktermPHP;

class Auth
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    public function login($username, $password)
    {
        $stmt = $this->db->prepare('SELECT id, password_hash FROM users WHERE username = ? AND is_active = TRUE');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $sessionId = $this->createSession($user['id']);
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
            SELECT s.user_id, u.username, u.real_name, u.email, u.is_admin, u.password_hash, u.created_at, u.last_login, u.location, u.fidonet_address
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

    public function createSession($userId)
    {
        $sessionId = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare('
            INSERT INTO user_sessions (session_id, user_id, expires_at, ip_address, user_agent, last_activity)
            VALUES (?, ?, NOW() + INTERVAL \'' . Config::SESSION_LIFETIME . ' seconds\', ?, ?, NOW())
        ');
        $stmt->execute([
            $sessionId,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
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
            return $this->validateSession($sessionId);
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
                s.ip_address
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
}