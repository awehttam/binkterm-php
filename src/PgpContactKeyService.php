<?php

namespace BinktermPHP;

use BinktermPHP\Pgp\ArmoredPublicKeyParser;
use InvalidArgumentException;
use PDO;
use Throwable;

class PgpContactKeyService
{
    private PDO $db;
    private ArmoredPublicKeyParser $parser;

    public function __construct(?PDO $db = null, ?ArmoredPublicKeyParser $parser = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->parser = $parser ?? new ArmoredPublicKeyParser();
    }

    /**
     * @return array<string, mixed>
     */
    public function savePublicKey(int $userId, string $armoredPublicKey, ?string $label = null, string $source = 'address_book'): array
    {
        $trimmedKey = trim($armoredPublicKey);
        if ($trimmedKey === '') {
            throw new InvalidArgumentException('A public key is required.');
        }

        $metadata = $this->parser->parse($trimmedKey);
        $fingerprint = strtoupper((string)($metadata['fingerprint'] ?? ''));
        if ($fingerprint === '') {
            throw new InvalidArgumentException('Invalid PGP public key.');
        }

        $label = $this->normalizeLabel($label);

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $existingStmt = $this->db->prepare("
                SELECT id
                FROM user_pgp_contact_keys
                WHERE user_id = ? AND fingerprint = ?
                LIMIT 1
            ");
            $existingStmt->execute([$userId, $fingerprint]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $this->db->prepare("
                    UPDATE user_pgp_contact_keys
                    SET armored_public_key = ?, source = ?, label = ?, user_id_string = ?, email = ?,
                        key_algorithm = ?, key_created_at = ?, updated_at = NOW()
                    WHERE id = ?
                    RETURNING id, user_id, fingerprint, armored_public_key, source, label, user_id_string,
                              email, key_algorithm, key_created_at, created_at, updated_at
                ");
                $stmt->execute([
                    $trimmedKey,
                    $source,
                    $label,
                    $metadata['user_id_string'] ?? null,
                    $metadata['email'] ?? null,
                    $metadata['algorithm'] ?? null,
                    $metadata['key_created_at'] ?? null,
                    (int)$existing['id'],
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO user_pgp_contact_keys (
                        user_id, fingerprint, armored_public_key, source, label, user_id_string,
                        email, key_algorithm, key_created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    RETURNING id, user_id, fingerprint, armored_public_key, source, label, user_id_string,
                              email, key_algorithm, key_created_at, created_at, updated_at
                ");
                $stmt->execute([
                    $userId,
                    $fingerprint,
                    $trimmedKey,
                    $source,
                    $label,
                    $metadata['user_id_string'] ?? null,
                    $metadata['email'] ?? null,
                    $metadata['algorithm'] ?? null,
                    $metadata['key_created_at'] ?? null,
                ]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return $row ? $this->normalizeRow($row) : [];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getKeyByIdForUser(int $userId, int $keyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, user_id, fingerprint, armored_public_key, source, label, user_id_string,
                   email, key_algorithm, key_created_at, created_at, updated_at
            FROM user_pgp_contact_keys
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$keyId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchPublicKeysForUser(int $userId, string $search, string $destinationAddress = '', int $limit = 50): array
    {
        $search = trim($search);
        $destinationAddress = trim($destinationAddress);
        $limit = max(1, min($limit, 200));

        $whereClauses = ['k.user_id = ?'];
        $params = [$userId];

        $normalizedFingerprint = strtoupper(ltrim($search, '0x'));
        $like = '%' . $search . '%';
        $exact = $search . '%';

        if ($search !== '') {
            $whereClauses[] = "(
                k.fingerprint = ?
                OR LOWER(COALESCE(k.user_id_string, '')) LIKE LOWER(?)
                OR LOWER(COALESCE(k.email, '')) LIKE LOWER(?)
                OR LOWER(COALESCE(k.label, '')) LIKE LOWER(?)
                OR LOWER(COALESCE(ab.name, '')) LIKE LOWER(?)
                OR LOWER(COALESCE(ab.messaging_user_id, '')) LIKE LOWER(?)
                OR LOWER(COALESCE(ab.node_address, '')) LIKE LOWER(?)
            )";
            array_push($params, $normalizedFingerprint, $like, $like, $like, $like, $like, $like);
        }

        $sql = "
            SELECT k.id, k.user_id, k.fingerprint, k.armored_public_key, k.source, k.label,
                   k.user_id_string, k.email, k.key_algorithm, k.key_created_at, k.created_at, k.updated_at,
                   ab.id AS address_book_entry_id, ab.name AS address_book_name,
                   ab.messaging_user_id AS address_book_messaging_user_id, ab.node_address AS address_book_node_address
            FROM user_pgp_contact_keys k
            INNER JOIN address_book ab
                ON ab.user_id = k.user_id
               AND ab.pgp_contact_key_id = k.id
            WHERE " . implode(' AND ', $whereClauses) . "
            ORDER BY
                CASE
                    WHEN ? <> '' AND ab.node_address = ? THEN 0
                    WHEN LOWER(COALESCE(ab.messaging_user_id, '')) LIKE LOWER(?) THEN 1
                    WHEN LOWER(COALESCE(ab.name, '')) LIKE LOWER(?) THEN 2
                    WHEN LOWER(COALESCE(k.user_id_string, '')) LIKE LOWER(?) THEN 3
                    ELSE 4
                END,
                k.created_at ASC,
                k.id ASC
            LIMIT " . (int)$limit;

        array_push($params, $destinationAddress, $destinationAddress, $exact, $exact, $exact);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(fn(array $row): array => $this->normalizeRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublicKeyForUser(int $userId, string $search, string $destinationAddress = ''): ?array
    {
        $results = $this->searchPublicKeysForUser($userId, $search, $destinationAddress, 1);
        return $results[0] ?? null;
    }

    private function normalizeLabel(?string $label): ?string
    {
        $label = trim((string)$label);
        if ($label === '') {
            return null;
        }
        if (strlen($label) > 120) {
            $label = substr($label, 0, 120);
        }
        return $label;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        foreach (['label', 'user_id_string', 'email', 'key_algorithm', 'address_book_name', 'address_book_messaging_user_id', 'address_book_node_address'] as $field) {
            if (array_key_exists($field, $row) && trim((string)$row[$field]) === '') {
                $row[$field] = null;
            }
        }
        if (array_key_exists('id', $row)) {
            $row['id'] = (int)$row['id'];
        }
        if (array_key_exists('user_id', $row)) {
            $row['user_id'] = (int)$row['user_id'];
        }
        if (array_key_exists('address_book_entry_id', $row) && $row['address_book_entry_id'] !== null) {
            $row['address_book_entry_id'] = (int)$row['address_book_entry_id'];
        }
        return $row;
    }
}
