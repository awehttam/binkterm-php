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
            SELECT id, name, messaging_user_id, node_address, email, description, created_at, updated_at
            FROM address_book 
            WHERE user_id = ?
        ";
        
        $params = [$userId];
        
        if (!empty($search)) {
            $sql .= " AND (name ILIKE ? OR messaging_user_id ILIKE ? OR node_address ILIKE ? OR description ILIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY name ASC";
        
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
            SELECT id, name, messaging_user_id, node_address, email, description, created_at, updated_at
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
        if (empty($data['name']) || empty($data['messaging_user_id']) || empty($data['node_address'])) {
            throw new \Exception('Name, user ID, and node address are required');
        }

        // Validate Fidonet address format
        if (!$this->isValidFidonetAddress($data['node_address'])) {
            throw new \Exception('Invalid Fidonet address format. Use format like 1:234/567 or 1:234/567.0');
        }

        // Check for duplicate entry
        $checkStmt = $this->db->prepare("
            SELECT id FROM address_book 
            WHERE user_id = ? AND messaging_user_id = ? AND node_address = ?
        ");
        $checkStmt->execute([$userId, $data['messaging_user_id'], $data['node_address']]);
        
        if ($checkStmt->fetch()) {
            throw new \Exception('An entry with this name and address already exists');
        }

        $stmt = $this->db->prepare("
            INSERT INTO address_book (user_id, name, messaging_user_id, node_address, email, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $userId,
            $data['name'],
            $data['messaging_user_id'],
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
        if (empty($data['name']) || empty($data['messaging_user_id']) || empty($data['node_address'])) {
            throw new \Exception('Name, user ID, and node address are required');
        }

        // Validate Fidonet address format
        if (!$this->isValidFidonetAddress($data['node_address'])) {
            throw new \Exception('Invalid Fidonet address format. Use format like 1:234/567 or 1:234/567.0');
        }

        // Check for duplicate entry (excluding current entry)
        $checkStmt = $this->db->prepare("
            SELECT id FROM address_book 
            WHERE user_id = ? AND messaging_user_id = ? AND node_address = ? AND id != ?
        ");
        $checkStmt->execute([$userId, $data['messaging_user_id'], $data['node_address'], $entryId]);
        
        if ($checkStmt->fetch()) {
            throw new \Exception('An entry with this name and address already exists');
        }

        $stmt = $this->db->prepare("
            UPDATE address_book 
            SET name = ?, messaging_user_id = ?, node_address = ?, email = ?, description = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND user_id = ?
        ");
        
        return $stmt->execute([
            $data['name'],
            $data['messaging_user_id'],
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
            SELECT id, name, messaging_user_id, node_address, email
            FROM address_book 
            WHERE user_id = ? 
                AND (name ILIKE ? OR messaging_user_id ILIKE ? OR node_address ILIKE ?)
            ORDER BY 
                CASE 
                    WHEN name ILIKE ? THEN 1
                    WHEN messaging_user_id ILIKE ? THEN 2
                    WHEN node_address ILIKE ? THEN 3
                    ELSE 4
                END,
                name ASC
            LIMIT ?
        ");
        
        // Priority search: exact matches first, then partial matches
        $exactTerm = $query . '%';
        $stmt->execute([$userId, $searchTerm, $searchTerm, $searchTerm, $exactTerm, $exactTerm, $exactTerm, $limit]);
        
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
                    'name' => $fromName,
                    'messaging_user_id' => $fromName,
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
