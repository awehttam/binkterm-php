<?php

namespace BinktermPHP;

class AddressBookController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Get all address book entries for a user with optional search
     */
    public function getUserEntries($userId, $search = '')
    {
        $sql = "
            SELECT id, full_name, node_address, email, description, created_at, updated_at
            FROM address_book 
            WHERE user_id = ?
        ";
        
        $params = [$userId];
        
        if (!empty($search)) {
            $sql .= " AND (full_name ILIKE ? OR node_address ILIKE ? OR description ILIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY full_name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Get a specific address book entry
     */
    public function getEntry($entryId, $userId)
    {
        $stmt = $this->db->prepare("
            SELECT id, full_name, node_address, email, description, created_at, updated_at
            FROM address_book 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$entryId, $userId]);
        
        return $stmt->fetch();
    }

    /**
     * Create a new address book entry
     */
    public function createEntry($userId, $data)
    {
        // Validate required fields
        if (empty($data['full_name']) || empty($data['node_address'])) {
            throw new \Exception('Full name and node address are required');
        }

        // Validate Fidonet address format
        if (!$this->isValidFidonetAddress($data['node_address'])) {
            throw new \Exception('Invalid Fidonet address format. Use format like 1:234/567 or 1:234/567.0');
        }

        // Check for duplicate entry
        $checkStmt = $this->db->prepare("
            SELECT id FROM address_book 
            WHERE user_id = ? AND full_name = ? AND node_address = ?
        ");
        $checkStmt->execute([$userId, $data['full_name'], $data['node_address']]);
        
        if ($checkStmt->fetch()) {
            throw new \Exception('An entry with this name and address already exists');
        }

        $stmt = $this->db->prepare("
            INSERT INTO address_book (user_id, full_name, node_address, email, description)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $userId,
            $data['full_name'],
            $data['node_address'],
            $data['email'] ?? null,
            $data['description'] ?? null
        ]);

        if ($result) {
            return $this->db->lastInsertId();
        }
        
        throw new \Exception('Failed to create address book entry');
    }

    /**
     * Update an existing address book entry
     */
    public function updateEntry($entryId, $userId, $data)
    {
        // Verify entry exists and belongs to user
        $entry = $this->getEntry($entryId, $userId);
        if (!$entry) {
            throw new \Exception('Address book entry not found');
        }

        // Validate required fields
        if (empty($data['full_name']) || empty($data['node_address'])) {
            throw new \Exception('Full name and node address are required');
        }

        // Validate Fidonet address format
        if (!$this->isValidFidonetAddress($data['node_address'])) {
            throw new \Exception('Invalid Fidonet address format. Use format like 1:234/567 or 1:234/567.0');
        }

        // Check for duplicate entry (excluding current entry)
        $checkStmt = $this->db->prepare("
            SELECT id FROM address_book 
            WHERE user_id = ? AND full_name = ? AND node_address = ? AND id != ?
        ");
        $checkStmt->execute([$userId, $data['full_name'], $data['node_address'], $entryId]);
        
        if ($checkStmt->fetch()) {
            throw new \Exception('An entry with this name and address already exists');
        }

        $stmt = $this->db->prepare("
            UPDATE address_book 
            SET full_name = ?, node_address = ?, email = ?, description = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND user_id = ?
        ");
        
        return $stmt->execute([
            $data['full_name'],
            $data['node_address'],
            $data['email'] ?? null,
            $data['description'] ?? null,
            $entryId,
            $userId
        ]);
    }

    /**
     * Delete an address book entry
     */
    public function deleteEntry($entryId, $userId)
    {
        $stmt = $this->db->prepare("DELETE FROM address_book WHERE id = ? AND user_id = ?");
        return $stmt->execute([$entryId, $userId]);
    }

    /**
     * Search address book entries for autocomplete
     */
    public function searchEntries($userId, $query, $limit = 10)
    {
        $searchTerm = '%' . $query . '%';
        
        $stmt = $this->db->prepare("
            SELECT id, full_name, node_address, email
            FROM address_book 
            WHERE user_id = ? 
                AND (full_name ILIKE ? OR node_address ILIKE ?)
            ORDER BY 
                CASE 
                    WHEN full_name ILIKE ? THEN 1
                    WHEN node_address ILIKE ? THEN 2
                    ELSE 3
                END,
                full_name ASC
            LIMIT ?
        ");
        
        // Priority search: exact matches first, then partial matches
        $exactTerm = $query . '%';
        $stmt->execute([$userId, $searchTerm, $searchTerm, $exactTerm, $exactTerm, $limit]);
        
        return $stmt->fetchAll();
    }

    /**
     * Get address book statistics for a user
     */
    public function getUserStats($userId)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_entries,
                COUNT(email) as entries_with_email,
                COUNT(description) as entries_with_description
            FROM address_book 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        
        return $stmt->fetch();
    }

    /**
     * Validate Fidonet address format
     */
    private function isValidFidonetAddress($address)
    {
        // Basic Fidonet address validation: zone:net/node or zone:net/node.point
        return preg_match('/^\d+:\d+\/\d+(\.\d+)?$/', $address);
    }

    /**
     * Import address book entry from a netmail message
     * This can be called when replying to messages to automatically add senders
     */
    public function importFromMessage($userId, $fromName, $fromAddress, $autoAdd = false)
    {
        // Check if entry already exists
        $checkStmt = $this->db->prepare("
            SELECT id FROM address_book 
            WHERE user_id = ? AND node_address = ?
        ");
        $checkStmt->execute([$userId, $fromAddress]);
        
        if ($checkStmt->fetch()) {
            return false; // Entry already exists
        }

        if ($autoAdd) {
            try {
                return $this->createEntry($userId, [
                    'full_name' => $fromName,
                    'node_address' => $fromAddress,
                    'description' => 'Auto-added from netmail'
                ]);
            } catch (\Exception $e) {
                error_log("Failed to auto-add address book entry: " . $e->getMessage());
                return false;
            }
        }

        return ['suggest' => true, 'name' => $fromName, 'address' => $fromAddress];
    }
}