<?php

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

    // Network Management Methods

    public function getNetworks()
    {
        $stmt = $this->db->query("
            SELECT n.*, 
                   (SELECT COUNT(*) FROM echoareas ea WHERE ea.network_id = n.id) as echoarea_count,
                   (SELECT COUNT(*) FROM network_uplinks nu WHERE nu.network_id = n.id) as uplink_count
            FROM networks n
            ORDER BY n.domain
        ");
        return $stmt->fetchAll();
    }

    public function getNetwork($networkId)
    {
        $stmt = $this->db->prepare("SELECT * FROM networks WHERE id = ?");
        $stmt->execute([$networkId]);
        return $stmt->fetch();
    }

    public function createNetwork($data)
    {
        // Validate required fields
        if (empty($data['domain']) || empty($data['name'])) {
            throw new \Exception('Domain and name are required');
        }

        // Check if domain already exists
        $stmt = $this->db->prepare("SELECT id FROM networks WHERE domain = ?");
        $stmt->execute([$data['domain']]);
        if ($stmt->fetch()) {
            throw new \Exception('Network domain already exists');
        }

        $stmt = $this->db->prepare("
            INSERT INTO networks (domain, name, description, is_active)
            VALUES (?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['domain'],
            $data['name'],
            $data['description'] ?? null,
            isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1
        ]);

        if ($result) {
            return $this->db->lastInsertId();
        }
        
        throw new \Exception('Failed to create network');
    }

    public function updateNetwork($networkId, $data)
    {
        $network = $this->getNetwork($networkId);
        if (!$network) {
            throw new \Exception('Network not found');
        }

        // Check if domain is being changed and if it's unique
        if (isset($data['domain']) && $data['domain'] !== $network['domain']) {
            $stmt = $this->db->prepare("SELECT id FROM networks WHERE domain = ? AND id != ?");
            $stmt->execute([$data['domain'], $networkId]);
            if ($stmt->fetch()) {
                throw new \Exception('Network domain already exists');
            }
        }

        $updates = [];
        $params = [];

        if (isset($data['domain'])) {
            $updates[] = 'domain = ?';
            $params[] = $data['domain'];
        }

        if (isset($data['name'])) {
            $updates[] = 'name = ?';
            $params[] = $data['name'];
        }

        if (isset($data['description'])) {
            $updates[] = 'description = ?';
            $params[] = $data['description'];
        }

        if (isset($data['is_active'])) {
            $updates[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 1 : 0;
        }

        if (empty($updates)) {
            return true; // Nothing to update
        }

        $updates[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $networkId;
        $sql = "UPDATE networks SET " . implode(', ', $updates) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteNetwork($networkId)
    {
        $network = $this->getNetwork($networkId);
        if (!$network) {
            throw new \Exception('Network not found');
        }

        // Don't allow deleting fidonet network or networks with echoareas
        if ($network['domain'] === 'fidonet') {
            throw new \Exception('Cannot delete the FidoNet network');
        }

        // Check if network has echoareas
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM echoareas WHERE network_id = ?");
        $stmt->execute([$networkId]);
        $echoareaCount = $stmt->fetch()['count'];

        if ($echoareaCount > 0) {
            throw new \Exception("Cannot delete network with $echoareaCount associated echoareas");
        }

        // Delete network uplinks first
        $this->db->prepare("DELETE FROM network_uplinks WHERE network_id = ?")->execute([$networkId]);

        // Delete network
        $stmt = $this->db->prepare("DELETE FROM networks WHERE id = ?");
        return $stmt->execute([$networkId]);
    }

    public function getNetworkUplinks($networkId)
    {
        $stmt = $this->db->prepare("
            SELECT nu.*, n.domain as network_domain, n.name as network_name
            FROM network_uplinks nu
            JOIN networks n ON nu.network_id = n.id
            WHERE nu.network_id = ?
            ORDER BY nu.is_default DESC, nu.address
        ");
        $stmt->execute([$networkId]);
        return $stmt->fetchAll();
    }

    public function createNetworkUplink($data)
    {
        // Validate required fields
        if (empty($data['network_id']) || empty($data['address']) || empty($data['hostname'])) {
            throw new \Exception('Network ID, address, and hostname are required');
        }

        // Check if address already exists for this network
        $stmt = $this->db->prepare("SELECT id FROM network_uplinks WHERE network_id = ? AND address = ?");
        $stmt->execute([$data['network_id'], $data['address']]);
        if ($stmt->fetch()) {
            throw new \Exception('Uplink address already exists for this network');
        }

        // If this is being set as default, unset any existing defaults for this network
        if (isset($data['is_default']) && $data['is_default']) {
            $this->db->prepare("UPDATE network_uplinks SET is_default = FALSE WHERE network_id = ?")
                    ->execute([$data['network_id']]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO network_uplinks (network_id, address, hostname, port, password, is_enabled, is_default, compression, crypt, poll_schedule)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['network_id'],
            $data['address'],
            $data['hostname'],
            $data['port'] ?? 24554,
            $data['password'] ?? '',
            isset($data['is_enabled']) ? ($data['is_enabled'] ? 1 : 0) : 1,
            isset($data['is_default']) ? ($data['is_default'] ? 1 : 0) : 0,
            isset($data['compression']) ? ($data['compression'] ? 1 : 0) : 0,
            isset($data['crypt']) ? ($data['crypt'] ? 1 : 0) : 0,
            $data['poll_schedule'] ?? '0 */4 * * *'
        ]);

        if ($result) {
            return $this->db->lastInsertId();
        }
        
        throw new \Exception('Failed to create network uplink');
    }

    public function updateNetworkUplink($uplinkId, $data)
    {
        $stmt = $this->db->prepare("SELECT * FROM network_uplinks WHERE id = ?");
        $stmt->execute([$uplinkId]);
        $uplink = $stmt->fetch();

        if (!$uplink) {
            throw new \Exception('Network uplink not found');
        }

        // Check if address is being changed and if it's unique within the network
        if (isset($data['address']) && $data['address'] !== $uplink['address']) {
            $stmt = $this->db->prepare("SELECT id FROM network_uplinks WHERE network_id = ? AND address = ? AND id != ?");
            $stmt->execute([$uplink['network_id'], $data['address'], $uplinkId]);
            if ($stmt->fetch()) {
                throw new \Exception('Uplink address already exists for this network');
            }
        }

        // If this is being set as default, unset any existing defaults for this network
        if (isset($data['is_default']) && $data['is_default']) {
            $this->db->prepare("UPDATE network_uplinks SET is_default = FALSE WHERE network_id = ? AND id != ?")
                    ->execute([$uplink['network_id'], $uplinkId]);
        }

        $updates = [];
        $params = [];

        $fields = ['address', 'hostname', 'port', 'password', 'poll_schedule'];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        $boolFields = ['is_enabled', 'is_default', 'compression', 'crypt'];
        foreach ($boolFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field] ? 1 : 0;
            }
        }

        if (empty($updates)) {
            return true; // Nothing to update
        }

        $updates[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $uplinkId;
        $sql = "UPDATE network_uplinks SET " . implode(', ', $updates) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteNetworkUplink($uplinkId)
    {
        $stmt = $this->db->prepare("DELETE FROM network_uplinks WHERE id = ?");
        return $stmt->execute([$uplinkId]);
    }

    public function getEchoareasWithNetwork($page = 1, $limit = 25, $search = '')
    {
        $offset = ($page - 1) * $limit;
        $searchTerm = '%' . $search . '%';
        
        $sql = "
            SELECT ea.*, n.domain as network_domain, n.name as network_name
            FROM echoareas ea
            LEFT JOIN networks n ON ea.network_id = n.id
            WHERE ea.tag LIKE ? OR ea.description LIKE ? OR n.domain LIKE ?
            ORDER BY n.domain, ea.tag
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limit, $offset]);
        $echoareas = $stmt->fetchAll();

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM echoareas ea
            LEFT JOIN networks n ON ea.network_id = n.id
            WHERE ea.tag LIKE ? OR ea.description LIKE ? OR n.domain LIKE ?
        ");
        $countStmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $total = $countStmt->fetch()['total'];

        return [
            'echoareas' => $echoareas,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    public function updateEchoareaNetwork($echoareaId, $networkId)
    {
        $stmt = $this->db->prepare("UPDATE echoareas SET network_id = ? WHERE id = ?");
        return $stmt->execute([$networkId, $echoareaId]);
    }
}