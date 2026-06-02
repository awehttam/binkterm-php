<?php

namespace BinktermPHP;

use BinktermPHP\Pgp\ArmoredPublicKeyParser;
use InvalidArgumentException;
use PDO;
use Throwable;

class PgpKeyService
{
    private PDO $db;
    private ArmoredPublicKeyParser $parser;

    public function __construct(?PDO $db = null, ?ArmoredPublicKeyParser $parser = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->parser = $parser ?? new ArmoredPublicKeyParser();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listKeysForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT k.id, k.fingerprint, k.source, k.label, k.user_id_string, k.email,
                   k.key_algorithm, k.key_created_at, k.is_primary, k.created_at, k.updated_at,
                   CASE WHEN p.id IS NULL THEN FALSE ELSE TRUE END AS has_private_key
            FROM user_pgp_keys k
            LEFT JOIN user_pgp_private_keys p ON p.pgp_key_id = k.id
            WHERE k.user_id = ?
            ORDER BY k.is_primary DESC, k.created_at ASC, k.id ASC
        ");
        $stmt->execute([$userId]);
        return $this->normalizeRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadPublicKey(int $userId, string $armoredPublicKey, ?string $label = null): array
    {
        return $this->storeKey($userId, $armoredPublicKey, null, $label, 'uploaded');
    }

    /**
     * @return array<string, mixed>
     */
    public function storeManagedKeyPair(int $userId, string $armoredPublicKey, string $encryptedPrivateKey, ?string $label = null): array
    {
        $privateTrimmed = trim($encryptedPrivateKey);
        if ($privateTrimmed === '' || !str_contains($privateTrimmed, '-----BEGIN PGP PRIVATE KEY BLOCK-----')) {
            throw new InvalidArgumentException('Expected an ASCII-armored private key block.');
        }

        return $this->storeKey($userId, $armoredPublicKey, $privateTrimmed, $label, 'managed');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEncryptedPrivateKey(int $userId, string $fingerprint): ?array
    {
        $stmt = $this->db->prepare("
            SELECT k.fingerprint, p.encrypted_private_key
            FROM user_pgp_keys k
            INNER JOIN user_pgp_private_keys p ON p.pgp_key_id = k.id
            WHERE k.user_id = ? AND k.fingerprint = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, strtoupper($fingerprint)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function setPrimaryKey(int $userId, string $fingerprint): bool
    {
        $fingerprint = strtoupper(trim($fingerprint));
        $this->db->beginTransaction();

        try {
            $exists = $this->db->prepare("SELECT id FROM user_pgp_keys WHERE user_id = ? AND fingerprint = ?");
            $exists->execute([$userId, $fingerprint]);
            $key = $exists->fetch(PDO::FETCH_ASSOC);
            if (!$key) {
                $this->db->rollBack();
                return false;
            }

            $clear = $this->db->prepare("UPDATE user_pgp_keys SET is_primary = FALSE, updated_at = NOW() WHERE user_id = ?");
            $clear->execute([$userId]);

            $set = $this->db->prepare("UPDATE user_pgp_keys SET is_primary = TRUE, updated_at = NOW() WHERE user_id = ? AND fingerprint = ?");
            $ok = $set->execute([$userId, $fingerprint]);

            $this->db->commit();
            return $ok;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteKey(int $userId, string $fingerprint): bool
    {
        $fingerprint = strtoupper(trim($fingerprint));
        $this->db->beginTransaction();

        try {
            $find = $this->db->prepare("SELECT id, is_primary FROM user_pgp_keys WHERE user_id = ? AND fingerprint = ?");
            $find->execute([$userId, $fingerprint]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();
                return false;
            }

            $delete = $this->db->prepare("DELETE FROM user_pgp_keys WHERE id = ?");
            $delete->execute([(int)$row['id']]);

            if ($this->truthy($row['is_primary'])) {
                $promote = $this->db->prepare("
                    UPDATE user_pgp_keys
                    SET is_primary = TRUE, updated_at = NOW()
                    WHERE id = (
                        SELECT id
                        FROM user_pgp_keys
                        WHERE user_id = ?
                        ORDER BY created_at ASC, id ASC
                        LIMIT 1
                    )
                ");
                $promote->execute([$userId]);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPreferredPublicKeyForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT fingerprint, armored_public_key, source, label, user_id_string, email,
                   key_algorithm, key_created_at, is_primary, created_at
            FROM user_pgp_keys
            WHERE user_id = ?
            ORDER BY is_primary DESC, created_at ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeKeyRow($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPublicKeysForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT fingerprint, armored_public_key, source, label, user_id_string, email,
                   key_algorithm, key_created_at, is_primary, created_at
            FROM user_pgp_keys
            WHERE user_id = ?
            ORDER BY is_primary DESC, created_at ASC, id ASC
        ");
        $stmt->execute([$userId]);
        return $this->normalizeRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchPublicKeys(string $search = '', int $limit = 100): array
    {
        $search = trim($search);
        $limit = max(1, min($limit, 200));

        $sql = "
            SELECT k.fingerprint, k.armored_public_key, k.source, k.label, k.user_id_string, k.email,
                   k.key_algorithm, k.key_created_at, k.is_primary, k.created_at,
                   u.id AS owner_user_id, u.username, u.real_name
            FROM user_pgp_keys k
            INNER JOIN users u ON u.id = k.user_id
        ";

        $params = [];
        if ($search !== '') {
            $normalizedFingerprint = strtoupper(ltrim($search, '0x'));
            $sql .= "
                WHERE k.fingerprint = ?
                   OR LOWER(u.username) LIKE LOWER(?)
                   OR LOWER(u.real_name) LIKE LOWER(?)
                   OR LOWER(COALESCE(k.email, '')) LIKE LOWER(?)
                   OR LOWER(COALESCE(k.user_id_string, '')) LIKE LOWER(?)
                   OR LOWER(COALESCE(k.label, '')) LIKE LOWER(?)
            ";
            $like = '%' . $search . '%';
            $params = [$normalizedFingerprint, $like, $like, $like, $like, $like];
        }

        $sql .= " ORDER BY k.is_primary DESC, u.username ASC, k.created_at ASC LIMIT " . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->normalizeRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublicKey(string $search): ?array
    {
        $search = trim($search);
        if ($search === '') {
            return null;
        }

        $normalizedFingerprint = strtoupper(ltrim($search, '0x'));
        if (preg_match('/^[0-9A-F]{40}$/', $normalizedFingerprint) === 1) {
            $stmt = $this->db->prepare("
                SELECT k.fingerprint, k.armored_public_key, k.source, k.label, k.user_id_string, k.email,
                       k.key_algorithm, k.key_created_at, k.is_primary, k.created_at,
                       u.id AS owner_user_id, u.username, u.real_name
                FROM user_pgp_keys k
                INNER JOIN users u ON u.id = k.user_id
                WHERE k.fingerprint = ?
                LIMIT 1
            ");
            $stmt->execute([$normalizedFingerprint]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->normalizeKeyRow($row) : null;
        }

        $results = $this->searchPublicKeys($search, 1);
        return $results[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function storeKey(int $userId, string $armoredPublicKey, ?string $encryptedPrivateKey, ?string $label, string $source): array
    {
        $trimmedKey = trim($armoredPublicKey);
        if ($trimmedKey === '') {
            throw new InvalidArgumentException('A public key is required.');
        }

        $metadata = $this->parser->parse($trimmedKey);
        $fingerprint = $metadata['fingerprint'];
        $label = $this->normalizeLabel($label);

        $this->db->beginTransaction();

        try {
            $existingStmt = $this->db->prepare("SELECT id, user_id FROM user_pgp_keys WHERE fingerprint = ?");
            $existingStmt->execute([$fingerprint]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

            $hasAnyKeysStmt = $this->db->prepare("SELECT COUNT(*) FROM user_pgp_keys WHERE user_id = ?");
            $hasAnyKeysStmt->execute([$userId]);
            $hasAnyKeys = ((int)$hasAnyKeysStmt->fetchColumn()) > 0;
            $isPrimary = !$hasAnyKeys;

            if ($existing && (int)$existing['user_id'] !== $userId) {
                throw new InvalidArgumentException('That public key is already associated with another account.');
            }

            if ($existing) {
                $updateStmt = $this->db->prepare("
                    UPDATE user_pgp_keys
                    SET armored_public_key = ?, source = ?, label = ?, user_id_string = ?, email = ?,
                        key_algorithm = ?, key_created_at = ?, updated_at = NOW()
                    WHERE id = ?
                    RETURNING id, fingerprint, source, label, user_id_string, email, key_algorithm,
                              key_created_at, is_primary, created_at, updated_at
                ");
                $updateStmt->execute([
                    $trimmedKey,
                    $source,
                    $label,
                    $metadata['user_id_string'],
                    $metadata['email'],
                    $metadata['algorithm'],
                    $metadata['key_created_at'],
                    (int)$existing['id'],
                ]);
                $row = $updateStmt->fetch(PDO::FETCH_ASSOC);
                $keyId = (int)$row['id'];
            } else {
                $insertStmt = $this->db->prepare("
                    INSERT INTO user_pgp_keys (
                        user_id, fingerprint, armored_public_key, source, label, user_id_string, email,
                        key_algorithm, key_created_at, is_primary
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    RETURNING id, fingerprint, source, label, user_id_string, email, key_algorithm,
                              key_created_at, is_primary, created_at, updated_at
                ");
                $insertStmt->execute([
                    $userId,
                    $fingerprint,
                    $trimmedKey,
                    $source,
                    $label,
                    $metadata['user_id_string'],
                    $metadata['email'],
                    $metadata['algorithm'],
                    $metadata['key_created_at'],
                    $isPrimary ? 'true' : 'false',
                ]);
                $row = $insertStmt->fetch(PDO::FETCH_ASSOC);
                $keyId = (int)$row['id'];
            }

            if ($encryptedPrivateKey !== null) {
                $privateStmt = $this->db->prepare("
                    INSERT INTO user_pgp_private_keys (pgp_key_id, encrypted_private_key)
                    VALUES (?, ?)
                    ON CONFLICT (pgp_key_id) DO UPDATE
                    SET encrypted_private_key = EXCLUDED.encrypted_private_key
                ");
                $privateStmt->execute([$keyId, $encryptedPrivateKey]);
            }

            $this->db->commit();
            return $row ? $this->normalizeKeyRow($row) : [];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
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
     * @param mixed $value
     */
    private function truthy($value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        return array_map(fn(array $row): array => $this->normalizeKeyRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeKeyRow(array $row): array
    {
        foreach (['label', 'user_id_string', 'email', 'key_algorithm'] as $field) {
            if (array_key_exists($field, $row) && trim((string)$row[$field]) === '') {
                $row[$field] = null;
            }
        }
        if (array_key_exists('is_primary', $row)) {
            $row['is_primary'] = $this->truthy($row['is_primary']);
        }
        if (array_key_exists('has_private_key', $row)) {
            $row['has_private_key'] = $this->truthy($row['has_private_key']);
        }
        if (array_key_exists('id', $row)) {
            $row['id'] = (int)$row['id'];
        }
        if (array_key_exists('owner_user_id', $row)) {
            $row['owner_user_id'] = (int)$row['owner_user_id'];
        }
        return $row;
    }
}
