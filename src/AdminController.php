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
        
        try {
            $sql = "
                SELECT id, username, email, real_name, fidonet_address, created_at, last_login, last_reminded, is_active, is_admin
                FROM users
                WHERE is_system = FALSE
                  AND (username ILIKE ? OR real_name ILIKE ? OR email ILIKE ? OR fidonet_address ILIKE ?)
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit, $offset]);
        } catch (\PDOException $e) {
            // is_system column not yet present (migration v1.10.18 not run) — fall back
            $sql = "
                SELECT id, username, email, real_name, fidonet_address, created_at, last_login, last_reminded, is_active, is_admin
                FROM users
                WHERE username ILIKE ? OR real_name ILIKE ? OR email ILIKE ? OR fidonet_address ILIKE ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit, $offset]);
        }
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

        try {
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM users
                WHERE is_system = FALSE
                  AND (username ILIKE ? OR real_name ILIKE ? OR email ILIKE ? OR fidonet_address ILIKE ?)
            ");
            $countStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        } catch (\PDOException $e) {
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM users
                WHERE username ILIKE ? OR real_name ILIKE ? OR email ILIKE ? OR fidonet_address ILIKE ?
            ");
            $countStmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
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

        // Check for username/real_name uniqueness including cross-collisions.
        // A username must not match any existing username or real_name, and vice versa,
        // to prevent netmail misrouting.
        $realName = $data['real_name'] ?? '';
        $stmt = $this->db->prepare("
            SELECT 1 FROM users
            WHERE LOWER(username) = LOWER(?) OR LOWER(username) = LOWER(?)
               OR LOWER(real_name) = LOWER(?) OR LOWER(real_name) = LOWER(?)
        ");
        $stmt->execute([$data['username'], $realName, $data['username'], $realName]);
        if ($stmt->fetch()) {
            throw new \Exception('Username or real name conflicts with an existing user');
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

        // Check if username is being changed — must not collide with any existing
        // username or real_name (cross-check prevents netmail misrouting).
        if (isset($data['username']) && $data['username'] !== $user['username']) {
            $stmt = $this->db->prepare("
                SELECT id FROM users
                WHERE (LOWER(username) = LOWER(?) OR LOWER(real_name) = LOWER(?)) AND id != ?
            ");
            $stmt->execute([$data['username'], $data['username'], $userId]);
            if ($stmt->fetch()) {
                throw new \Exception('Username conflicts with an existing username or real name');
            }
        }

        // Check if real_name is being changed — must not collide with any existing
        // username or real_name.
        if (isset($data['real_name']) && $data['real_name'] !== $user['real_name']) {
            $stmt = $this->db->prepare("
                SELECT id FROM users
                WHERE (LOWER(username) = LOWER(?) OR LOWER(real_name) = LOWER(?)) AND id != ?
            ");
            $stmt->execute([$data['real_name'], $data['real_name'], $userId]);
            if ($stmt->fetch()) {
                throw new \Exception('Real name conflicts with an existing username or real name');
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
        
        try {
            $stats['total_users'] = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_system = FALSE")->fetch()['count'];
            $stats['active_users'] = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_system = FALSE AND is_active = TRUE")->fetch()['count'];
            $stats['admin_users'] = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_system = FALSE AND is_admin = TRUE")->fetch()['count'];
        } catch (\PDOException $e) {
            $stats['total_users'] = $this->db->query("SELECT COUNT(*) as count FROM users")->fetch()['count'];
            $stats['active_users'] = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_active = TRUE")->fetch()['count'];
            $stats['admin_users'] = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_admin = TRUE")->fetch()['count'];
        }
        $stats['total_netmail'] = $this->db->query("SELECT COUNT(*) as count FROM netmail")->fetch()['count'];
        $stats['total_echomail'] = $this->db->query("SELECT COUNT(*) as count FROM echomail")->fetch()['count'];
        $stats['active_sessions'] = $this->db->query("SELECT COUNT(*) as count FROM sessions WHERE expires_at > NOW()")->fetch()['count'];
        
        return $stats;
    }

    public function getEconomyStats(string $period = '30d'): array
    {
        $creditsConfig = UserCredit::getCreditsConfig();
        $periodMap = [
            '7d' => "NOW() - INTERVAL '7 days'",
            '30d' => "NOW() - INTERVAL '30 days'",
            '90d' => "NOW() - INTERVAL '90 days'",
            'all' => null
        ];
        $periodKey = array_key_exists($period, $periodMap) ? $period : '30d';
        $periodSql = $periodMap[$periodKey];
        $periodWhere = $periodSql ? "WHERE created_at >= {$periodSql}" : '';

        $summary = $this->db->query("
            SELECT
                COUNT(*) AS total_users,
                COUNT(*) FILTER (WHERE credit_balance > 0) AS funded_users,
                COALESCE(SUM(credit_balance), 0) AS total_credits,
                COALESCE(AVG(credit_balance), 0) AS avg_balance,
                COALESCE(MAX(credit_balance), 0) AS max_balance
            FROM users
        ")->fetch(\PDO::FETCH_ASSOC);

        $medianStmt = $this->db->query("
            SELECT COALESCE(
                percentile_cont(0.5) WITHIN GROUP (ORDER BY credit_balance),
                0
            ) AS median_balance
            FROM users
        ");
        $medianBalance = $medianStmt->fetchColumn();

        $richestUser = $this->db->query("
            SELECT id, username, real_name, credit_balance
            FROM users
            ORDER BY credit_balance DESC, username ASC
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC) ?: null;

        $periodSummary = $this->db->query("
            SELECT
                COUNT(*) AS transaction_count,
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS credits_earned,
                COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS credits_spent,
                COALESCE(SUM(amount), 0) AS net_flow,
                COUNT(DISTINCT user_id) AS active_users
            FROM user_transactions
            {$periodWhere}
        ")->fetch(\PDO::FETCH_ASSOC);

        $transactionTypes = $this->db->query("
            SELECT
                transaction_type,
                COUNT(*) AS transaction_count,
                COUNT(DISTINCT user_id) AS unique_users,
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS inflow,
                COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS outflow,
                COALESCE(SUM(amount), 0) AS net_amount
            FROM user_transactions
            {$periodWhere}
            GROUP BY transaction_type
            ORDER BY transaction_count DESC, transaction_type ASC
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $topEarners = $this->db->query("
            SELECT
                u.id,
                u.username,
                u.real_name,
                COALESCE(SUM(ut.amount), 0) AS total_earned,
                COUNT(*) AS transaction_count
            FROM user_transactions ut
            INNER JOIN users u ON u.id = ut.user_id
            " . ($periodSql ? "WHERE ut.created_at >= {$periodSql} AND ut.amount > 0" : "WHERE ut.amount > 0") . "
            GROUP BY u.id, u.username, u.real_name
            ORDER BY total_earned DESC, transaction_count DESC, u.username ASC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $topSpenders = $this->db->query("
            SELECT
                u.id,
                u.username,
                u.real_name,
                COALESCE(SUM(ABS(ut.amount)), 0) AS total_spent,
                COUNT(*) AS transaction_count
            FROM user_transactions ut
            INNER JOIN users u ON u.id = ut.user_id
            " . ($periodSql ? "WHERE ut.created_at >= {$periodSql} AND ut.amount < 0" : "WHERE ut.amount < 0") . "
            GROUP BY u.id, u.username, u.real_name
            ORDER BY total_spent DESC, transaction_count DESC, u.username ASC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $richestUsers = $this->db->query("
            SELECT id, username, real_name, credit_balance
            FROM users
            WHERE credit_balance > 0
            ORDER BY credit_balance DESC, username ASC
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $recentTransactions = $this->db->query("
            SELECT
                ut.id,
                ut.amount,
                ut.balance_after,
                ut.description,
                ut.transaction_type,
                ut.created_at,
                u.username,
                u.real_name
            FROM user_transactions ut
            INNER JOIN users u ON u.id = ut.user_id
            " . ($periodSql ? "WHERE ut.created_at >= {$periodSql}" : '') . "
            ORDER BY ut.created_at DESC
            LIMIT 15
        ")->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'credits_enabled' => (bool)$creditsConfig['enabled'],
            'credits_symbol' => (string)$creditsConfig['symbol'],
            'period' => $periodKey,
            'summary' => [
                'total_users' => (int)($summary['total_users'] ?? 0),
                'funded_users' => (int)($summary['funded_users'] ?? 0),
                'total_credits' => (int)($summary['total_credits'] ?? 0),
                'avg_balance' => (float)($summary['avg_balance'] ?? 0),
                'median_balance' => (float)$medianBalance,
                'max_balance' => (int)($summary['max_balance'] ?? 0)
            ],
            'period_summary' => [
                'transaction_count' => (int)($periodSummary['transaction_count'] ?? 0),
                'credits_earned' => (int)($periodSummary['credits_earned'] ?? 0),
                'credits_spent' => (int)($periodSummary['credits_spent'] ?? 0),
                'net_flow' => (int)($periodSummary['net_flow'] ?? 0),
                'active_users' => (int)($periodSummary['active_users'] ?? 0)
            ],
            'richest_user' => $richestUser ? [
                'id' => (int)$richestUser['id'],
                'username' => $richestUser['username'],
                'real_name' => $richestUser['real_name'],
                'credit_balance' => (int)$richestUser['credit_balance']
            ] : null,
            'transaction_types' => array_map(static function(array $row): array {
                return [
                    'transaction_type' => $row['transaction_type'],
                    'transaction_count' => (int)$row['transaction_count'],
                    'unique_users' => (int)$row['unique_users'],
                    'inflow' => (int)$row['inflow'],
                    'outflow' => (int)$row['outflow'],
                    'net_amount' => (int)$row['net_amount']
                ];
            }, $transactionTypes),
            'top_earners' => array_map(static function(array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'username' => $row['username'],
                    'real_name' => $row['real_name'],
                    'total_earned' => (int)$row['total_earned'],
                    'transaction_count' => (int)$row['transaction_count']
                ];
            }, $topEarners),
            'top_spenders' => array_map(static function(array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'username' => $row['username'],
                    'real_name' => $row['real_name'],
                    'total_spent' => (int)$row['total_spent'],
                    'transaction_count' => (int)$row['transaction_count']
                ];
            }, $topSpenders),
            'richest_users' => array_map(static function(array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'username' => $row['username'],
                    'real_name' => $row['real_name'],
                    'credit_balance' => (int)$row['credit_balance']
                ];
            }, $richestUsers),
            'recent_transactions' => array_map(static function(array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'amount' => (int)$row['amount'],
                    'balance_after' => (int)$row['balance_after'],
                    'description' => $row['description'],
                    'transaction_type' => $row['transaction_type'],
                    'created_at' => $row['created_at'],
                    'username' => $row['username'],
                    'real_name' => $row['real_name']
                ];
            }, $recentTransactions)
        ];
    }

    public function getDatabaseVersion(): string
    {
        try {
            $stmt = $this->db->query("SELECT version FROM database_migrations");
            $versions = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if (empty($versions)) {
                return 'n/a';
            }
            usort($versions, 'version_compare');
            return end($versions);
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
            ($data['allow_receive'] ?? true) ? 'true' : 'false',
            ($data['allow_send'] ?? false) ? 'true' : 'false',
            $data['max_messages_per_session'] ?? 100,
            ($data['is_active'] ?? true) ? 'true' : 'false',
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
        $booleanFields = ['allow_receive', 'allow_send', 'is_active'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                $params[] = in_array($field, $booleanFields)
                    ? ($data[$field] ? 'true' : 'false')
                    : $data[$field];
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

