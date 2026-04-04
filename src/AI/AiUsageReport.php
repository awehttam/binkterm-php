<?php

namespace BinktermPHP\AI;

use BinktermPHP\Database;

/**
 * Aggregates AI request ledger data for admin reporting.
 */
class AiUsageReport
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    public function isAvailable(): bool
    {
        try {
            $stmt = $this->db->query("SELECT to_regclass('public.ai_requests') AS table_name");
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return !empty($row['table_name']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(string $period = '7d', string $timezone = 'UTC'): array
    {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'period' => $this->normalizePeriod($period),
                'summary' => [
                    'requests' => 0,
                    'estimated_cost_usd' => 0.0,
                    'failures' => 0,
                    'total_tokens' => 0,
                ],
                'by_feature' => [],
                'by_provider_model' => [],
                'recent_failures' => [],
                'recent_requests' => [],
            ];
        }

        $period = $this->normalizePeriod($period);
        $cutoff = $this->getCutoffUtc($period);

        return [
            'available' => true,
            'period' => $period,
            'summary' => $this->getSummary($cutoff),
            'by_feature' => $this->getByFeature($cutoff),
            'by_provider_model' => $this->getByProviderModel($cutoff),
            'recent_failures' => $this->getRecentFailures($cutoff, $timezone),
            'recent_requests' => $this->getRecentRequests($cutoff, $timezone),
        ];
    }

    private function normalizePeriod(string $period): string
    {
        return in_array($period, ['1d', '7d', '30d', 'all'], true) ? $period : '7d';
    }

    private function getCutoffUtc(string $period): ?string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return match ($period) {
            '1d' => $now->sub(new \DateInterval('P1D'))->format('Y-m-d H:i:sP'),
            '7d' => $now->sub(new \DateInterval('P7D'))->format('Y-m-d H:i:sP'),
            '30d' => $now->sub(new \DateInterval('P30D'))->format('Y-m-d H:i:sP'),
            default => null,
        };
    }

    /**
     * @return array{requests:int,estimated_cost_usd:float,failures:int,total_tokens:int,input_tokens:int,output_tokens:int,cached_input_tokens:int}
     */
    private function getSummary(?string $cutoff): array
    {
        $params = [];
        $where = $this->buildCutoffWhere($cutoff, $params);

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS requests,
                COALESCE(SUM(estimated_cost_usd), 0) AS estimated_cost_usd,
                COALESCE(SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END), 0) AS failures,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                COALESCE(SUM(input_tokens), 0) AS input_tokens,
                COALESCE(SUM(output_tokens), 0) AS output_tokens,
                COALESCE(SUM(cached_input_tokens), 0) AS cached_input_tokens
            FROM ai_requests
            {$where}
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'requests' => (int)($row['requests'] ?? 0),
            'estimated_cost_usd' => (float)($row['estimated_cost_usd'] ?? 0),
            'failures' => (int)($row['failures'] ?? 0),
            'total_tokens' => (int)($row['total_tokens'] ?? 0),
            'input_tokens' => (int)($row['input_tokens'] ?? 0),
            'output_tokens' => (int)($row['output_tokens'] ?? 0),
            'cached_input_tokens' => (int)($row['cached_input_tokens'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getByFeature(?string $cutoff): array
    {
        $params = [];
        $where = $this->buildCutoffWhere($cutoff, $params);

        $stmt = $this->db->prepare("
            SELECT
                feature,
                COUNT(*) AS requests,
                COALESCE(SUM(estimated_cost_usd), 0) AS estimated_cost_usd,
                COALESCE(SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END), 0) AS failures,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                COALESCE(SUM(input_tokens), 0) AS input_tokens,
                COALESCE(SUM(output_tokens), 0) AS output_tokens,
                COALESCE(SUM(cached_input_tokens), 0) AS cached_input_tokens
            FROM ai_requests
            {$where}
            GROUP BY feature
            ORDER BY estimated_cost_usd DESC, requests DESC, feature ASC
            LIMIT 20
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getByProviderModel(?string $cutoff): array
    {
        $params = [];
        $where = $this->buildCutoffWhere($cutoff, $params);

        $stmt = $this->db->prepare("
            SELECT
                provider,
                model,
                COUNT(*) AS requests,
                COALESCE(SUM(estimated_cost_usd), 0) AS estimated_cost_usd,
                COALESCE(SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END), 0) AS failures,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                COALESCE(SUM(input_tokens), 0) AS input_tokens,
                COALESCE(SUM(output_tokens), 0) AS output_tokens,
                COALESCE(SUM(cached_input_tokens), 0) AS cached_input_tokens
            FROM ai_requests
            {$where}
            GROUP BY provider, model
            ORDER BY estimated_cost_usd DESC, requests DESC, provider ASC, model ASC
            LIMIT 20
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentFailures(?string $cutoff, string $timezone): array
    {
        $params = [];
        $where = $this->buildCutoffWhere($cutoff, $params, 'status = ?');
        array_unshift($params, 'error');

        $stmt = $this->db->prepare("
            SELECT created_at, feature, provider, model, http_status, error_code, error_message
            FROM ai_requests
            {$where}
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmt->execute($params);
        return $this->formatRows($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], $timezone);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentRequests(?string $cutoff, string $timezone): array
    {
        $params = [];
        $where = $this->buildCutoffWhere($cutoff, $params);

        $stmt = $this->db->prepare("
            SELECT created_at, feature, provider, model, operation, status,
                   total_tokens, input_tokens, output_tokens, cached_input_tokens,
                   estimated_cost_usd
            FROM ai_requests
            {$where}
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmt->execute($params);
        return $this->formatRows($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], $timezone);
    }

    /**
     * @param array<int, mixed> $params
     */
    private function buildCutoffWhere(?string $cutoff, array &$params, string $baseClause = ''): string
    {
        $clauses = [];
        if ($baseClause !== '') {
            $clauses[] = $baseClause;
        }
        if ($cutoff !== null) {
            $clauses[] = 'created_at >= ?';
            $params[] = $cutoff;
        }

        if (empty($clauses)) {
            return '';
        }

        return 'WHERE ' . implode(' AND ', $clauses);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function formatRows(array $rows, string $timezone): array
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }

        foreach ($rows as &$row) {
            if (!empty($row['created_at'])) {
                $dt = new \DateTimeImmutable((string)$row['created_at'], new \DateTimeZone('UTC'));
                $row['created_at_local'] = $dt->setTimezone($tz)->format('Y-m-d H:i:s T');
            } else {
                $row['created_at_local'] = null;
            }
        }
        unset($row);

        return $rows;
    }
}
