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

class AddressBookException extends \RuntimeException
{
    private string $errorCode;
    private int $httpStatus;

    public function __construct(string $errorCode, ?string $message = null, int $httpStatus = 400)
    {
        parent::__construct($message ?? self::fallbackMessage($errorCode));
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    private static function fallbackMessage(string $errorCode): string
    {
        static $translator = null;
        if ($translator === null) {
            $translator = new \BinktermPHP\I18n\Translator();
        }

        $translated = $translator->translate($errorCode, [], 'en', ['errors']);
        if ($translated !== $errorCode) {
            return $translated;
        }

        return 'Address book error';
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}

class AddressBookController
{
    private $db;
    private PgpContactKeyService $pgpContactKeyService;
    private ?bool $hasPgpContactKeySupport = null;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->pgpContactKeyService = new PgpContactKeyService($this->db);
    }

    /**
     * Get all address book entries for a user with optional search
     */
    public function getUserEntries($userId, $search = '')
    {
        if ($this->supportsPgpContactKeys()) {
            $sql = "
                SELECT ab.id, ab.name, ab.messaging_user_id, ab.node_address, ab.email, ab.description,
                       ab.always_crashmail, ab.created_at, ab.updated_at, ab.pgp_contact_key_id,
                       pk.fingerprint AS pgp_key_fingerprint, pk.user_id_string AS pgp_key_user_id_string,
                       pk.label AS pgp_key_label
                FROM address_book ab
                LEFT JOIN user_pgp_contact_keys pk ON pk.id = ab.pgp_contact_key_id
                WHERE ab.user_id = ?
            ";
        } else {
            $sql = "
                SELECT ab.id, ab.name, ab.messaging_user_id, ab.node_address, ab.email, ab.description,
                       ab.always_crashmail, ab.created_at, ab.updated_at,
                       NULL::integer AS pgp_contact_key_id,
                       NULL::varchar AS pgp_key_fingerprint,
                       NULL::varchar AS pgp_key_user_id_string,
                       NULL::varchar AS pgp_key_label
                FROM address_book ab
                WHERE ab.user_id = ?
            ";
        }
        
        $params = [$userId];
        
        if (!empty($search)) {
            $sql .= " AND (
                ab.name ILIKE ? OR ab.messaging_user_id ILIKE ? OR ab.node_address ILIKE ? OR ab.description ILIKE ?
                OR COALESCE(pk.user_id_string, '') ILIKE ? OR COALESCE(pk.fingerprint, '') ILIKE ?
            )";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY ab.name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }

    /**
     * Get a specific address book entry
     */
    public function getEntry($entryId, $userId)
    {
        if ($this->supportsPgpContactKeys()) {
            $stmt = $this->db->prepare("
                SELECT ab.id, ab.name, ab.messaging_user_id, ab.node_address, ab.email, ab.description,
                       ab.always_crashmail, ab.created_at, ab.updated_at, ab.pgp_contact_key_id,
                       pk.fingerprint AS pgp_key_fingerprint, pk.user_id_string AS pgp_key_user_id_string,
                       pk.label AS pgp_key_label, pk.armored_public_key AS pgp_armored_public_key
                FROM address_book ab
                LEFT JOIN user_pgp_contact_keys pk ON pk.id = ab.pgp_contact_key_id
                WHERE ab.id = ? AND ab.user_id = ?
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT ab.id, ab.name, ab.messaging_user_id, ab.node_address, ab.email, ab.description,
                       ab.always_crashmail, ab.created_at, ab.updated_at,
                       NULL::integer AS pgp_contact_key_id,
                       NULL::varchar AS pgp_key_fingerprint,
                       NULL::varchar AS pgp_key_user_id_string,
                       NULL::varchar AS pgp_key_label,
                       NULL::text AS pgp_armored_public_key
                FROM address_book ab
                WHERE ab.id = ? AND ab.user_id = ?
            ");
        }
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
            throw new AddressBookException(
                'errors.address_book.required_fields',
                null,
                400
            );
        }

        // Validate Fidonet address format
        if (!$this->isValidFidonetAddress($data['node_address'])) {
            throw new AddressBookException(
                'errors.address_book.invalid_fidonet_format',
                null,
                400
            );
        }

        // Check for duplicate entry
        $checkStmt = $this->db->prepare("
            SELECT id FROM address_book 
            WHERE user_id = ? AND messaging_user_id = ? AND node_address = ?
        ");
        $checkStmt->execute([$userId, $data['messaging_user_id'], $data['node_address']]);
        
        if ($checkStmt->fetch()) {
            throw new AddressBookException(
                'errors.address_book.duplicate_entry',
                null,
                409
            );
        }

        $alwaysCrashmail = !empty($data['always_crashmail']);
        $pgpContactKeyId = null;

        $this->db->beginTransaction();

        try {
            if ($this->supportsPgpContactKeys()) {
                $pgpContactKeyId = $this->resolvePgpContactKeyId($userId, $data);

                $stmt = $this->db->prepare("
                    INSERT INTO address_book (
                        user_id, name, messaging_user_id, node_address, email, description, always_crashmail, pgp_contact_key_id
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    RETURNING id
                ");

                $stmt->execute([
                    $userId,
                    $data['name'],
                    $data['messaging_user_id'],
                    $data['node_address'],
                    $data['email'] ?? null,
                    $data['description'] ?? null,
                    $alwaysCrashmail ? 'true' : 'false',
                    $pgpContactKeyId,
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO address_book (
                        user_id, name, messaging_user_id, node_address, email, description, always_crashmail
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    RETURNING id
                ");

                $stmt->execute([
                    $userId,
                    $data['name'],
                    $data['messaging_user_id'],
                    $data['node_address'],
                    $data['email'] ?? null,
                    $data['description'] ?? null,
                    $alwaysCrashmail ? 'true' : 'false',
                ]);
            }

            $row = $stmt->fetch();
            $entryId = $row ? (int)$row['id'] : 0;
            if ($entryId <= 0) {
                throw new AddressBookException('errors.address_book.create_failed', null, 400);
            }

            $this->db->commit();
            return $entryId;
        } catch (AddressBookException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } catch (\InvalidArgumentException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new AddressBookException('errors.pgp.invalid_key', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new AddressBookException('errors.address_book.create_failed', null, 400);
        }
    }

    /**
     * Update an existing address book entry
     */
    public function updateEntry($entryId, $userId, $data)
    {
        // Verify entry exists and belongs to user
        $entry = $this->getEntry($entryId, $userId);
        if (!$entry) {
            throw new AddressBookException(
                'errors.address_book.not_found',
                null,
                404
            );
        }

        // Validate required fields
        if (empty($data['name']) || empty($data['messaging_user_id']) || empty($data['node_address'])) {
            throw new AddressBookException(
                'errors.address_book.required_fields',
                null,
                400
            );
        }

        // Validate Fidonet address format
        if (!$this->isValidFidonetAddress($data['node_address'])) {
            throw new AddressBookException(
                'errors.address_book.invalid_fidonet_format',
                null,
                400
            );
        }

        // Check for duplicate entry (excluding current entry)
        $checkStmt = $this->db->prepare("
            SELECT id FROM address_book 
            WHERE user_id = ? AND messaging_user_id = ? AND node_address = ? AND id != ?
        ");
        $checkStmt->execute([$userId, $data['messaging_user_id'], $data['node_address'], $entryId]);
        
        if ($checkStmt->fetch()) {
            throw new AddressBookException(
                'errors.address_book.duplicate_entry',
                null,
                409
            );
        }

        $alwaysCrashmail = !empty($data['always_crashmail']);

        $this->db->beginTransaction();

        try {
            if ($this->supportsPgpContactKeys()) {
                $pgpContactKeyId = $this->resolvePgpContactKeyId($userId, $data);

                $stmt = $this->db->prepare("
                    UPDATE address_book
                    SET name = ?, messaging_user_id = ?, node_address = ?, email = ?, description = ?,
                        always_crashmail = ?, pgp_contact_key_id = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND user_id = ?
                ");

                $result = $stmt->execute([
                    $data['name'],
                    $data['messaging_user_id'],
                    $data['node_address'],
                    $data['email'] ?? null,
                    $data['description'] ?? null,
                    $alwaysCrashmail ? 'true' : 'false',
                    $pgpContactKeyId,
                    $entryId,
                    $userId,
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE address_book
                    SET name = ?, messaging_user_id = ?, node_address = ?, email = ?, description = ?,
                        always_crashmail = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND user_id = ?
                ");

                $result = $stmt->execute([
                    $data['name'],
                    $data['messaging_user_id'],
                    $data['node_address'],
                    $data['email'] ?? null,
                    $data['description'] ?? null,
                    $alwaysCrashmail ? 'true' : 'false',
                    $entryId,
                    $userId,
                ]);
            }

            $this->db->commit();
            return $result;
        } catch (AddressBookException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } catch (\InvalidArgumentException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new AddressBookException('errors.pgp.invalid_key', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new AddressBookException('errors.address_book.update_failed', null, 400);
        }
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
     * Search address book entries for autocomplete.
     * Includes system_name from the nodelist for entries that have a node address.
     */
    public function searchEntries($userId, $query, $limit = 10)
    {
        $searchTerm = '%' . $query . '%';
        $exactTerm  = $query . '%';
        $normalizedQuery = trim((string)$query);

        $pgpSelect = $this->supportsPgpContactKeys()
            ? "ab.pgp_contact_key_id,
                pk.fingerprint AS pgp_key_fingerprint,
                pk.user_id_string AS pgp_key_user_id_string,
                pk.label AS pgp_key_label"
            : "NULL::integer AS pgp_contact_key_id,
                NULL::varchar AS pgp_key_fingerprint,
                NULL::varchar AS pgp_key_user_id_string,
                NULL::varchar AS pgp_key_label";

        $pgpJoin = $this->supportsPgpContactKeys()
            ? "LEFT JOIN user_pgp_contact_keys pk ON pk.id = ab.pgp_contact_key_id"
            : "";

        $stmt = $this->db->prepare("
            SELECT ab.id, ab.name, ab.messaging_user_id, ab.node_address, ab.email, ab.always_crashmail,
                {$pgpSelect},
                nl.system_name AS node_system_name,
                nl.domain      AS node_domain
            FROM address_book ab
            {$pgpJoin}
            LEFT JOIN LATERAL (
                SELECT n.system_name, n.domain FROM nodelist n
                WHERE ab.node_address ~ E'^\\d+:\\d+/\\d+'
                  AND n.zone  = (regexp_match(ab.node_address, E'^(\\d+):'))[1]::integer
                  AND n.net   = (regexp_match(ab.node_address, E':(\\d+)/'))[1]::integer
                  AND n.node  = (regexp_match(ab.node_address, E'/(\\d+)'))[1]::integer
                  AND n.point = 0
                ORDER BY n.id
                LIMIT 1
            ) nl ON true
            WHERE ab.user_id = ?
                AND (ab.name ILIKE ? OR ab.messaging_user_id ILIKE ? OR ab.node_address ILIKE ?)
            ORDER BY
                CASE
                    WHEN ab.name ILIKE ? THEN 1
                    WHEN ab.messaging_user_id ILIKE ? THEN 2
                    WHEN ab.node_address ILIKE ? THEN 3
                    ELSE 4
                END,
                ab.name ASC
            LIMIT ?
        ");

        $stmt->execute([$userId, $searchTerm, $searchTerm, $searchTerm, $exactTerm, $exactTerm, $exactTerm, $limit]);

        $entries = $stmt->fetchAll();
        $results = [];
        $seenMessagingIds = [];
        $seenNames = [];

        $addResult = static function (array $entry) use (&$results, &$seenMessagingIds, &$seenNames): void {
            $messagingId = strtolower(trim((string)($entry['messaging_user_id'] ?? '')));
            $name        = strtolower(trim((string)($entry['name'] ?? '')));

            if ($messagingId !== '' && isset($seenMessagingIds[$messagingId])) {
                return;
            }
            if ($name !== '' && isset($seenNames[$name])) {
                return;
            }

            if ($messagingId !== '') {
                $seenMessagingIds[$messagingId] = true;
            }
            if ($name !== '') {
                $seenNames[$name] = true;
            }

            $results[] = $entry;
        };

        foreach ($entries as $entry) {
            $addResult($entry);
        }

        if (strcasecmp($normalizedQuery, 'sysop') === 0) {
            try {
                $sysopName = trim((string)\BinktermPHP\Binkp\Config\BinkpConfig::getInstance()->getSystemSysop());
            } catch (\Throwable $e) {
                $sysopName = '';
            }

            if ($sysopName !== '') {
                $addResult([
                    'id' => null,
                    'name' => $sysopName,
                    'messaging_user_id' => $sysopName,
                    'node_address' => '',
                    'email' => null,
                    'always_crashmail' => false,
                    'pgp_contact_key_id' => null,
                    'pgp_key_fingerprint' => null,
                    'pgp_key_user_id_string' => null,
                    'pgp_key_label' => null,
                    'node_system_name' => '',
                    'node_domain' => '',
                ]);
            }
        }

        $remaining = max(0, $limit - count($results));
        if ($remaining > 0) {
            $userStmt = $this->db->prepare("
                SELECT id, username, real_name
                FROM users
                WHERE is_active = TRUE
                  AND (
                    real_name ILIKE ?
                    OR username ILIKE ?
                  )
                ORDER BY
                    CASE
                        WHEN real_name ILIKE ? THEN 1
                        WHEN username ILIKE ? THEN 2
                        ELSE 3
                    END,
                    real_name ASC,
                    username ASC
                LIMIT ?
            ");
            $userStmt->execute([$searchTerm, $searchTerm, $exactTerm, $exactTerm, $remaining]);

            foreach ($userStmt->fetchAll() as $user) {
                $realName = trim((string)($user['real_name'] ?? ''));
                $username = trim((string)($user['username'] ?? ''));
                if ($realName === '' && $username === '') {
                    continue;
                }

                $addResult([
                    'id' => null,
                    'name' => $realName !== '' ? $realName : $username,
                    'messaging_user_id' => $username !== '' ? $username : $realName,
                    'node_address' => '',
                    'email' => null,
                    'always_crashmail' => false,
                    'pgp_contact_key_id' => null,
                    'pgp_key_fingerprint' => null,
                    'pgp_key_user_id_string' => null,
                    'pgp_key_label' => null,
                    'node_system_name' => '',
                    'node_domain' => '',
                ]);
            }
        }

        return array_slice($results, 0, $limit);
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

    private function resolvePgpContactKeyId(int $userId, array $data): ?int
    {
        if (!$this->supportsPgpContactKeys()) {
            return null;
        }

        $armoredPublicKey = trim((string)($data['pgp_public_key'] ?? ''));
        if ($armoredPublicKey === '') {
            return null;
        }

        $label = trim((string)($data['pgp_key_label'] ?? $data['name'] ?? ''));
        $key = $this->pgpContactKeyService->savePublicKey($userId, $armoredPublicKey, $label, 'address_book');
        return isset($key['id']) ? (int)$key['id'] : null;
    }

    private function supportsPgpContactKeys(): bool
    {
        if ($this->hasPgpContactKeySupport !== null) {
            return $this->hasPgpContactKeySupport;
        }

        $stmt = $this->db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'address_book'
              AND column_name = 'pgp_contact_key_id'
            LIMIT 1
        ");
        $stmt->execute();
        $this->hasPgpContactKeySupport = (bool)$stmt->fetchColumn();

        return $this->hasPgpContactKeySupport;
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
                getServerLogger()->error("Failed to auto-add address book entry: " . $e->getMessage());
                return false;
            }
        }

        return ['suggest' => true, 'name' => $fromName, 'address' => $fromAddress];
    }
}
