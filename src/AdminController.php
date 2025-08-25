<?php

namespace Binktest;

class AdminController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    public function requireAdmin($currentUser)
    {
        if (!$currentUser || !$currentUser['is_admin']) {
            http_response_code(403);
            throw new \Exception('Admin access required');
        }
    }

    public function getAllUsers($page = 1, $limit = 25, $search = '')
    {
        $offset = ($page - 1) * $limit;
        $searchTerm = '%' . $search . '%';
        
        $sql = "
            SELECT id, username, email, real_name, fidonet_address, created_at, last_login, is_active, is_admin 
            FROM users 
            WHERE username LIKE ? OR real_name LIKE ? OR email LIKE ? OR fidonet_address LIKE ?
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit, $offset]);
        $users = $stmt->fetchAll();

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM users 
            WHERE username LIKE ? OR real_name LIKE ? OR email LIKE ? OR fidonet_address LIKE ?
        ");
        $countStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $total = $countStmt->fetch()['total'];

        return [
            'users' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    public function getUser($userId)
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    public function createUser($data)
    {
        // Validate required fields
        if (empty($data['username']) || empty($data['password'])) {
            throw new \Exception('Username and password are required');
        }

        // Check if username already exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$data['username']]);
        if ($stmt->fetch()) {
            throw new \Exception('Username already exists');
        }

        // Check if Fidonet address is unique (if provided)
        if (!empty($data['fidonet_address'])) {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE fidonet_address = ?");
            $stmt->execute([$data['fidonet_address']]);
            if ($stmt->fetch()) {
                throw new \Exception('Fidonet address already in use');
            }
        }

        // Hash password
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, email, real_name, fidonet_address, is_active, is_admin)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['username'],
            $passwordHash,
            $data['email'] ?? null,
            $data['real_name'] ?? null,
            $data['fidonet_address'] ?? null,
            isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
            isset($data['is_admin']) ? ($data['is_admin'] ? 1 : 0) : 0
        ]);

        if ($result) {
            return $this->db->lastInsertId();
        }
        
        throw new \Exception('Failed to create user');
    }

    public function updateUser($userId, $data)
    {
        $user = $this->getUser($userId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        // Check if username is being changed and if it's unique
        if (isset($data['username']) && $data['username'] !== $user['username']) {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$data['username'], $userId]);
            if ($stmt->fetch()) {
                throw new \Exception('Username already exists');
            }
        }

        // Check if Fidonet address is unique (if being changed)
        if (isset($data['fidonet_address']) && $data['fidonet_address'] !== $user['fidonet_address']) {
            if (!empty($data['fidonet_address'])) {
                $stmt = $this->db->prepare("SELECT id FROM users WHERE fidonet_address = ? AND id != ?");
                $stmt->execute([$data['fidonet_address'], $userId]);
                if ($stmt->fetch()) {
                    throw new \Exception('Fidonet address already in use');
                }
            }
        }

        $updates = [];
        $params = [];

        if (isset($data['username'])) {
            $updates[] = 'username = ?';
            $params[] = $data['username'];
        }

        if (isset($data['email'])) {
            $updates[] = 'email = ?';
            $params[] = $data['email'];
        }

        if (isset($data['real_name'])) {
            $updates[] = 'real_name = ?';
            $params[] = $data['real_name'];
        }

        if (isset($data['fidonet_address'])) {
            $updates[] = 'fidonet_address = ?';
            $params[] = $data['fidonet_address'];
        }

        if (isset($data['is_active'])) {
            $updates[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 1 : 0;
        }

        if (isset($data['is_admin'])) {
            $updates[] = 'is_admin = ?';
            $params[] = $data['is_admin'] ? 1 : 0;
        }

        // Update password if provided
        if (!empty($data['password'])) {
            $updates[] = 'password_hash = ?';
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (empty($updates)) {
            return true; // Nothing to update
        }

        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteUser($userId)
    {
        // Don't allow deleting the last admin
        $adminCount = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_admin = 1")->fetch()['count'];
        
        $user = $this->getUser($userId);
        if ($user && $user['is_admin'] && $adminCount <= 1) {
            throw new \Exception('Cannot delete the last admin user');
        }

        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$userId]);
    }

    public function getUserStats($userId)
    {
        $stats = [];
        
        // Netmail stats
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM netmail WHERE user_id = ?");
        $stmt->execute([$userId]);
        $stats['netmail_received'] = $stmt->fetch()['count'];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM netmail WHERE from_address = (SELECT fidonet_address FROM users WHERE id = ?)");
        $stmt->execute([$userId]);
        $stats['netmail_sent'] = $stmt->fetch()['count'];
        
        // Echomail stats  
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM echomail WHERE from_address = (SELECT fidonet_address FROM users WHERE id = ?)");
        $stmt->execute([$userId]);
        $stats['echomail_posted'] = $stmt->fetch()['count'];
        
        // Session stats
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM sessions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $stats['active_sessions'] = $stmt->fetch()['count'];
        
        return $stats;
    }

    public function getSystemStats()
    {
        $stats = [];
        
        $stats['total_users'] = $this->db->query("SELECT COUNT(*) as count FROM users")->fetch()['count'];
        $stats['active_users'] = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_active = 1")->fetch()['count'];
        $stats['admin_users'] = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_admin = 1")->fetch()['count'];
        $stats['total_netmail'] = $this->db->query("SELECT COUNT(*) as count FROM netmail")->fetch()['count'];
        $stats['total_echomail'] = $this->db->query("SELECT COUNT(*) as count FROM echomail")->fetch()['count'];
        $stats['active_sessions'] = $this->db->query("SELECT COUNT(*) as count FROM sessions WHERE expires_at > datetime('now')")->fetch()['count'];
        
        return $stats;
    }
}