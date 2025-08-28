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
            SELECT s.user_id, u.username, u.real_name, u.email, u.is_admin, u.password_hash, u.created_at, u.last_login
            FROM user_sessions s 
            JOIN users u ON s.user_id = u.id 
            WHERE s.session_id = ? AND s.expires_at > NOW() AND u.is_active = TRUE
        ');
        $stmt->execute([$sessionId]);
        return $stmt->fetch();
    }

    public function createSession($userId)
    {
        $sessionId = bin2hex(random_bytes(32));
        
        $stmt = $this->db->prepare('
            INSERT INTO user_sessions (session_id, user_id, expires_at, ip_address, user_agent) 
            VALUES (?, ?, NOW() + INTERVAL \'' . Config::SESSION_LIFETIME . ' seconds\', ?, ?)
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
}