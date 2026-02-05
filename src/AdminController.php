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
            SELECT id, username, email, real_name, fidonet_address, created_at, last_login, last_reminded, is_active, is_admin 
            FROM users 
            WHERE username ILIKE ? OR real_name ILIKE ? OR email ILIKE ? OR fidonet_address ILIKE ?
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit, $offset]);
        $users = $stmt->fetchAll();

        // Add calculated fields for days since reminder
        foreach ($users as &$user) {
            //error_log("[ADMIN] Processing user {$user['username']}: " . print_r(array_keys($user), true));
            if (array_key_exists('last_reminded', $user)) {
//                error_log("[ADMIN] User {$user['username']} last_reminded: " . ($user['last_reminded'] ?? 'NULL'));
                $user['days_since_reminder'] = $this->calculateDaysSinceReminder($user['last_reminded']);
            } else {
                // Column doesn't exist yet - migration hasn't been run
//                error_log("[ADMIN] last_reminded column missing from users table - migration v1.4.8 needs to be run");
                $user['days_since_reminder'] = null;
            }
        }

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM users 
            WHERE username ILIKE ? OR real_name ILIKE ? OR email ILIKE ? OR fidonet_address ILIKE ?
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
            $newUserId = $this->db->lastInsertId();

            // Create default echoarea subscriptions
            $subscriptionManager = new EchoareaSubscriptionManager();
            $subscriptionManager->createDefaultSubscriptions($newUserId);

            return $newUserId;
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
        $adminCount = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_admin = TRUE")->fetch()['count'];
        
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
        $stats['active_users'] = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_active = TRUE")->fetch()['count'];
        $stats['admin_users'] = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_admin = TRUE")->fetch()['count'];
        $stats['total_netmail'] = $this->db->query("SELECT COUNT(*) as count FROM netmail")->fetch()['count'];
        $stats['total_echomail'] = $this->db->query("SELECT COUNT(*) as count FROM echomail")->fetch()['count'];
        $stats['active_sessions'] = $this->db->query("SELECT COUNT(*) as count FROM sessions WHERE expires_at > NOW()")->fetch()['count'];
        
        return $stats;
    }

    public function getDatabaseVersion(): string
    {
        try {
            $stmt = $this->db->query("
                SELECT version
                FROM database_migrations
                ORDER BY version DESC
                LIMIT 1
            ");
            $result = $stmt->fetch();
            return $result ? $result['version'] : 'n/a';
        } catch (\Exception $e) {
            return 'n/a';
        }
    }

    public function getShoutboxMessages(int $limit = 100): array
    {
        $stmt = $this->db->prepare("
            SELECT s.id, s.message, s.is_hidden, s.created_at, u.username
            FROM shoutbox_messages s
            INNER JOIN users u ON s.user_id = u.id
            ORDER BY s.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function setShoutboxHidden(int $id, bool $hidden): bool
    {
        $stmt = $this->db->prepare("
            UPDATE shoutbox_messages
            SET is_hidden = ?
            WHERE id = ?
        ");
        return $stmt->execute([$hidden ? 1 : 0, $id]);
    }

    public function deleteShoutboxMessage(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM shoutbox_messages WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getUsersNeedingReminder()
    {
        $stmt = $this->db->query("
            SELECT id, username, real_name, email, created_at 
            FROM users 
            WHERE last_login IS NULL 
              AND is_active = TRUE 
              AND created_at < NOW() - INTERVAL '24 hours'
            ORDER BY created_at ASC
        ");
        
        return $stmt->fetchAll();
    }

    /**
     * Calculate days since last reminder was sent
     */
    private function calculateDaysSinceReminder($lastReminded)
    {
        if (!$lastReminded) {
            return null; // Never reminded
        }

        $reminderDate = new \DateTime($lastReminded);
        $currentDate = new \DateTime();
        $interval = $currentDate->diff($reminderDate);

        error_log("[ADMIN] calculateDaysSinceReminder: lastReminded='$lastReminded', days={$interval->days}");

        return $interval->days;
    }

    // ========================================
    // Insecure Nodes Management
    // ========================================

    /**
     * Get all insecure node allowlist entries
     */
    public function getInsecureNodes(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM binkp_insecure_nodes
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Add a node to the insecure allowlist
     */
    public function addInsecureNode(array $data): int
    {
        if (empty($data['address'])) {
            throw new \Exception('FTN address is required');
        }

        // Check if address already exists
        $stmt = $this->db->prepare("SELECT id FROM binkp_insecure_nodes WHERE address = ?");
        $stmt->execute([$data['address']]);
        if ($stmt->fetch()) {
            throw new \Exception('Address already in allowlist');
        }

        $stmt = $this->db->prepare("
            INSERT INTO binkp_insecure_nodes
            (address, description, allow_receive, allow_send, max_messages_per_session, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $data['address'],
            $data['description'] ?? null,
            $data['allow_receive'] ?? true,
            $data['allow_send'] ?? false,
            $data['max_messages_per_session'] ?? 100,
            $data['is_active'] ?? true
        ]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Update an insecure node entry
     */
    public function updateInsecureNode(int $id, array $data): bool
    {
        $updates = [];
        $params = [];

        $allowedFields = [
            'address', 'description', 'allow_receive', 'allow_send',
            'max_messages_per_session', 'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE binkp_insecure_nodes SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete an insecure node entry
     */
    public function deleteInsecureNode(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM binkp_insecure_nodes WHERE id = ?");
        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    // ========================================
    // Crashmail Queue Management
    // ========================================

    /**
     * Get crashmail queue statistics
     */
    public function getCrashmailStats(): array
    {
        $service = new \BinktermPHP\Crashmail\CrashmailService();
        return $service->getQueueStats();
    }

    /**
     * Get crashmail queue items
     */
    public function getCrashmailQueue(?string $status = null, int $limit = 50): array
    {
        $service = new \BinktermPHP\Crashmail\CrashmailService();
        return $service->getQueueItems($status, $limit);
    }

    /**
     * Retry a failed crashmail
     */
    public function retryCrashmail(int $id): bool
    {
        $service = new \BinktermPHP\Crashmail\CrashmailService();
        return $service->retryCrashmail($id);
    }

    /**
     * Cancel a queued crashmail
     */
    public function cancelCrashmail(int $id): bool
    {
        $service = new \BinktermPHP\Crashmail\CrashmailService();
        return $service->cancelCrashmail($id);
    }

    // ========================================
    // Binkp Session Log
    // ========================================

    /**
     * Get binkp session log entries
     */
    public function getBinkpSessions(array $filters = [], int $limit = 50): array
    {
        return \BinktermPHP\Binkp\SessionLogger::getRecentSessions($limit, $filters);
    }

    /**
     * Get binkp session statistics
     */
    public function getBinkpSessionStats(string $period = 'day'): array
    {
        return \BinktermPHP\Binkp\SessionLogger::getSessionStats($period);
    }
}

