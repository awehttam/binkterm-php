<?php

namespace BinktermPHP;

use PDO;

class EchoareaManager
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    public function findByTagAndDomains(string $tag, array $domains = []): ?array
    {
        $normalizedTag = strtoupper(trim($tag));
        if ($normalizedTag === '') {
            return null;
        }

        [$domainClause, $params] = $this->buildDomainWhereClause($domains);
        $sql = "
            SELECT id, tag, description, domain, uplink_address, is_active, is_local
            FROM echoareas
            WHERE UPPER(tag) = UPPER(?)
              AND {$domainClause}
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$normalizedTag], $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, tag, description, domain, uplink_address, is_active, is_local
            FROM echoareas
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<int, array<string, mixed>> $areas
     * @return array<int, array<string, mixed>>
     */
    public function annotateAreasWithLocalStatus(array $areas, array $domains = []): array
    {
        if ($areas === []) {
            return $areas;
        }

        $tags = array_values(array_unique(array_filter(array_map(static function ($area) {
            return strtoupper(trim((string)($area['tag'] ?? '')));
        }, $areas))));

        if ($tags === []) {
            return $areas;
        }

        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        [$domainClause, $domainParams] = $this->buildDomainWhereClause($domains);
        $stmt = $this->db->prepare("
            SELECT UPPER(tag) AS tag_key, id, domain, description, is_sysop_only
            FROM echoareas
            WHERE UPPER(tag) IN ($placeholders)
              AND {$domainClause}
        ");
        $stmt->execute(array_merge($tags, $domainParams));
        $localRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $localByTag = [];
        foreach ($localRows as $row) {
            $tagKey = (string)($row['tag_key'] ?? '');
            if ($tagKey !== '' && !isset($localByTag[$tagKey])) {
                $localByTag[$tagKey] = $row;
            }
        }

        foreach ($areas as &$area) {
            $tagKey = strtoupper(trim((string)($area['tag'] ?? '')));
            $local = $localByTag[$tagKey] ?? null;
            $area['local_exists'] = $local !== null;
            $area['local_echoarea_id'] = $local !== null ? (int)$local['id'] : null;
            $area['local_domain'] = $local['domain'] ?? null;
            $area['local_description'] = $local['description'] ?? null;
            $area['local_is_sysop_only'] = $local !== null ? !empty($local['is_sysop_only']) : null;
            $remoteDescription = trim((string)($area['description'] ?? ''));
            $localDescription = trim((string)($local['description'] ?? ''));
            $area['description_mismatch'] = $local !== null && $remoteDescription !== '' && $remoteDescription !== $localDescription;
        }
        unset($area);

        return $areas;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createIfMissing(array $data, array $domains = []): int
    {
        $tag = strtoupper(trim((string)($data['tag'] ?? '')));
        if ($tag === '') {
            throw new \InvalidArgumentException('Echo area tag is required');
        }

        $existing = $this->findByTagAndDomains($tag, $domains);
        if ($existing) {
            return (int)$existing['id'];
        }

        $description = trim((string)($data['description'] ?? ''));
        if ($description === '') {
            $description = $tag;
        }

        $domain = $data['domain'] ?? null;
        $normalizedDomain = is_string($domain) ? trim($domain) : null;
        if ($normalizedDomain === '') {
            $normalizedDomain = null;
        }

        $uplinkAddress = $data['uplink_address'] ?? null;
        $normalizedUplinkAddress = is_string($uplinkAddress) ? trim($uplinkAddress) : null;
        if ($normalizedUplinkAddress === '') {
            $normalizedUplinkAddress = null;
        }

        $isLocal = array_key_exists('is_local', $data) ? (bool)$data['is_local'] : ($normalizedDomain === null);
        $isActive = array_key_exists('is_active', $data) ? (bool)$data['is_active'] : true;
        $isSysopOnly = array_key_exists('is_sysop_only', $data) ? (bool)$data['is_sysop_only'] : false;
        $geminiPublic = array_key_exists('gemini_public', $data) ? (bool)$data['gemini_public'] : false;
        $moderator = isset($data['moderator']) && trim((string)$data['moderator']) !== '' ? trim((string)$data['moderator']) : null;
        $postingNamePolicy = isset($data['posting_name_policy']) && trim((string)$data['posting_name_policy']) !== '' ? trim((string)$data['posting_name_policy']) : null;
        $artFormatHint = isset($data['art_format_hint']) && trim((string)$data['art_format_hint']) !== '' ? trim((string)$data['art_format_hint']) : null;
        $color = isset($data['color']) && trim((string)$data['color']) !== '' ? trim((string)$data['color']) : '#28a745';

        $insertStmt = $this->db->prepare("
            INSERT INTO echoareas (
                tag, description, moderator, uplink_address,
                posting_name_policy, art_format_hint, color,
                is_active, is_local, is_sysop_only, domain, gemini_public
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $tag,
            $description,
            $moderator,
            $normalizedUplinkAddress,
            $postingNamePolicy,
            $artFormatHint,
            $color,
            $isActive ? 'true' : 'false',
            $isLocal ? 'true' : 'false',
            $isSysopOnly ? 'true' : 'false',
            $normalizedDomain,
            $geminiPublic ? 'true' : 'false',
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function updateDescription(int $id, string $description): bool
    {
        if ($id <= 0) {
            return false;
        }

        $normalizedDescription = trim($description);
        if ($normalizedDescription === '') {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE echoareas SET description = ? WHERE id = ?");
        return $stmt->execute([$normalizedDescription, $id]);
    }

    public function updateSysopOnly(int $id, bool $isSysopOnly): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE echoareas SET is_sysop_only = ? WHERE id = ?");
        return $stmt->execute([$isSysopOnly ? 'true' : 'false', $id]);
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function buildDomainWhereClause(array $domains): array
    {
        $normalized = [];
        foreach ($domains as $domain) {
            $value = strtolower(trim((string)$domain));
            if ($value === '') {
                $normalized[] = '';
            } elseif (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if ($normalized === []) {
            return ['1=1', []];
        }

        $parts = [];
        $params = [];
        foreach ($normalized as $domain) {
            if ($domain === '') {
                $parts[] = "(domain IS NULL OR domain = '')";
            } else {
                $parts[] = "LOWER(domain) = ?";
                $params[] = $domain;
            }
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }
}
