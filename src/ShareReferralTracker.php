<?php

namespace BinktermPHP;

/**
 * Tracks external referrers for shared links and returns per-share top referrers.
 */
class ShareReferralTracker
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?: Database::getInstance()->getPdo();
    }

    public function recordMessageShareAccess(int $shareId, ?string $referrerUrl = null): void
    {
        $this->recordShareAccess('message', $shareId, $referrerUrl);
    }

    public function recordFileShareAccess(int $shareId, ?string $referrerUrl = null): void
    {
        $this->recordShareAccess('file', $shareId, $referrerUrl);
    }

    /**
     * @param int[] $shareIds
     * @return array<int, array<int, array{url:string, host:?string, access_count:int}>>
     */
    public function getTopReferrersForMessageShares(array $shareIds, int $limit = 10): array
    {
        return $this->getTopReferrersForShares('message', $shareIds, $limit);
    }

    /**
     * @param int[] $shareIds
     * @return array<int, array<int, array{url:string, host:?string, access_count:int}>>
     */
    public function getTopReferrersForFileShares(array $shareIds, int $limit = 10): array
    {
        return $this->getTopReferrersForShares('file', $shareIds, $limit);
    }

    private function recordShareAccess(string $shareType, int $shareId, ?string $referrerUrl = null): void
    {
        $normalizedUrl = $this->normalizeExternalReferrerUrl($referrerUrl);
        if ($normalizedUrl === null) {
            return;
        }

        $host = parse_url($normalizedUrl, PHP_URL_HOST);
        $stmt = $this->db->prepare("
            INSERT INTO shared_link_referrals (share_type, share_id, referrer_url, referrer_host, access_count, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, ?, 1, NOW(), NOW())
            ON CONFLICT (share_type, share_id, referrer_url)
            DO UPDATE SET
                access_count = shared_link_referrals.access_count + 1,
                last_seen_at = NOW(),
                referrer_host = EXCLUDED.referrer_host
        ");
        $stmt->execute([
            $shareType,
            $shareId,
            $normalizedUrl,
            $host !== false ? strtolower((string)$host) : null,
        ]);
    }

    /**
     * @param int[] $shareIds
     * @return array<int, array<int, array{url:string, host:?string, access_count:int}>>
     */
    private function getTopReferrersForShares(string $shareType, array $shareIds, int $limit): array
    {
        $shareIds = array_values(array_filter(array_map('intval', $shareIds), static function ($value) {
            return $value > 0;
        }));
        if ($shareIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($shareIds), '?'));
        $sql = "
            SELECT share_id, referrer_url, referrer_host, access_count
            FROM (
                SELECT share_id,
                       referrer_url,
                       referrer_host,
                       access_count,
                       ROW_NUMBER() OVER (
                           PARTITION BY share_id
                           ORDER BY access_count DESC, last_seen_at DESC, referrer_url ASC
                       ) AS row_num
                FROM shared_link_referrals
                WHERE share_type = ?
                  AND share_id IN ($placeholders)
            ) ranked
            WHERE row_num <= ?
            ORDER BY share_id ASC, access_count DESC, referrer_url ASC
        ";

        $stmt = $this->db->prepare($sql);
        $params = array_merge([$shareType], $shareIds, [$limit]);
        $stmt->execute($params);

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $shareId = (int)$row['share_id'];
            if (!isset($result[$shareId])) {
                $result[$shareId] = [];
            }
            $result[$shareId][] = [
                'url' => (string)$row['referrer_url'],
                'host' => $row['referrer_host'] !== null ? (string)$row['referrer_host'] : null,
                'access_count' => (int)$row['access_count'],
            ];
        }

        return $result;
    }

    private function normalizeExternalReferrerUrl(?string $referrerUrl): ?string
    {
        $referrerUrl = trim((string)$referrerUrl);
        if ($referrerUrl === '' || !filter_var($referrerUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($referrerUrl);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($scheme === '' || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $siteHost = strtolower((string)parse_url(Config::getSiteUrl(), PHP_URL_HOST));
        if ($siteHost !== '' && $host === $siteHost) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return substr($scheme . '://' . $host . $port . $path . $query, 0, 2000);
    }
}
