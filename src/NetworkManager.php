<?php

namespace BinktermPHP;

use PDO;

class NetworkManager
{
    public const NETWORK_TYPE_FIDONET = 1;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("
            SELECT id, domain, name, description, website, network_type, allow_markup, allow_media,
                   default_charset, posting_name_policy, is_builtin, created_at, updated_at
            FROM networks
            ORDER BY is_builtin DESC, LOWER(name), LOWER(domain)
        ");

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, domain, name, description, website, network_type, allow_markup, allow_media,
                   default_charset, posting_name_policy, is_builtin, created_at, updated_at
            FROM networks
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeRow($row) : null;
    }

    public function getByDomain(string $domain): ?array
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === '') {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, domain, name, description, website, network_type, allow_markup, allow_media,
                   default_charset, posting_name_policy, is_builtin, created_at, updated_at
            FROM networks
            WHERE LOWER(domain) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeRow($row) : null;
    }

    public function exists(string $domain): bool
    {
        return $this->getByDomain($domain) !== null;
    }

    public function create(array $data): array
    {
        $domain = $this->normalizeDomain((string)($data['domain'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        if ($domain === '' || $name === '') {
            throw new \InvalidArgumentException('Domain and name are required');
        }
        if (!$this->isValidDomain($domain)) {
            throw new \InvalidArgumentException('Invalid domain slug');
        }
        if ($this->exists($domain)) {
            throw new \InvalidArgumentException('A network with that domain already exists');
        }

        $values = $this->normalizeSettings($data);
        $stmt = $this->db->prepare("
            INSERT INTO networks (
                domain, name, description, website, network_type, allow_markup, allow_media,
                default_charset, posting_name_policy, is_builtin
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $domain,
            $name,
            $values['description'],
            $values['website'],
            $values['network_type'],
            $values['allow_markup'] ? 'true' : 'false',
            $values['allow_media'] ? 'true' : 'false',
            $values['default_charset'],
            $values['posting_name_policy'],
            !empty($data['is_builtin']) ? 'true' : 'false',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->getById((int)($row['id'] ?? 0)) ?? [];
    }

    public function update(int $id, array $data): array
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new \InvalidArgumentException('Network not found');
        }

        $name = trim((string)($data['name'] ?? $existing['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required');
        }

        $values = $this->normalizeSettings(array_merge($existing, $data));
        $stmt = $this->db->prepare("
            UPDATE networks
            SET name = ?,
                description = ?,
                website = ?,
                network_type = ?,
                allow_markup = ?,
                allow_media = ?,
                default_charset = ?,
                posting_name_policy = ?,
                updated_at = NOW() AT TIME ZONE 'UTC'
            WHERE id = ?
        ");
        $stmt->execute([
            $name,
            $values['description'],
            $values['website'],
            $values['network_type'],
            $values['allow_markup'] ? 'true' : 'false',
            $values['allow_media'] ? 'true' : 'false',
            $values['default_charset'],
            $values['posting_name_policy'],
            $id,
        ]);

        return $this->getById($id) ?? [];
    }

    public function delete(int $id): void
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new \InvalidArgumentException('Network not found');
        }

        $stmt = $this->db->prepare("DELETE FROM networks WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function renameDomain(int $id, string $newDomain): array
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new \InvalidArgumentException('Network not found');
        }

        $normalized = $this->normalizeDomain($newDomain);
        if ($normalized === '' || !$this->isValidDomain($normalized)) {
            throw new \InvalidArgumentException('Invalid domain slug');
        }
        if (strcasecmp((string)$existing['domain'], $normalized) !== 0 && $this->exists($normalized)) {
            throw new \InvalidArgumentException('A network with that domain already exists');
        }

        $stmt = $this->db->prepare("
            UPDATE networks
            SET domain = ?, updated_at = NOW() AT TIME ZONE 'UTC'
            WHERE id = ?
        ");
        $stmt->execute([$normalized, $id]);

        return $this->getById($id) ?? [];
    }

    public function upsertLovlyNetDefaults(): array
    {
        $existing = $this->getByDomain('lovlynet');
        $data = [
            'domain' => 'lovlynet',
            'name' => 'LovlyNet',
            'description' => 'LovlyNet FTN network',
            'website' => 'https://lovelybits.org',
            'allow_markup' => true,
            'allow_media' => true,
            'default_charset' => 'UTF-8',
            'posting_name_policy' => 'username',
            'is_builtin' => true,
            'network_type' => self::NETWORK_TYPE_FIDONET,
        ];

        return $existing ? $this->update((int)$existing['id'], $data) : $this->create($data);
    }

    public static function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    public static function isValidDomain(string $domain): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $domain);
    }

    private function normalizeSettings(array $data): array
    {
        $charset = trim((string)($data['default_charset'] ?? ''));
        $policy = strtolower(trim((string)($data['posting_name_policy'] ?? 'real_name')));

        return [
            'description' => trim((string)($data['description'] ?? '')) ?: null,
            'website' => trim((string)($data['website'] ?? '')) ?: null,
            'network_type' => $this->normalizeNetworkType($data['network_type'] ?? self::NETWORK_TYPE_FIDONET),
            'allow_markup' => filter_var($data['allow_markup'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'allow_media' => filter_var($data['allow_media'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'default_charset' => $charset !== '' ? \BinktermPHP\Binkp\Config\BinkpConfig::normalizeCharset($charset) : null,
            'posting_name_policy' => in_array($policy, ['real_name', 'username'], true) ? $policy : 'real_name',
        ];
    }

    private function normalizeNetworkType(mixed $value): int
    {
        $type = (int)$value;
        return $type === self::NETWORK_TYPE_FIDONET ? $type : self::NETWORK_TYPE_FIDONET;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        foreach (['allow_markup', 'allow_media', 'is_builtin'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = filter_var($row[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (array_key_exists('network_type', $row)) {
            $row['network_type'] = (int)$row['network_type'];
        }
        if (array_key_exists('id', $row)) {
            $row['id'] = (int)$row['id'];
        }

        return $row;
    }
}
