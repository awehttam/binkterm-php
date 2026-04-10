<?php

namespace BinktermPHP\Realtime;

use BinktermPHP\Config;
use BinktermPHP\MarkdownRenderer;
use PDO;

class StreamService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getMaxSseId(): int
    {
        $row = $this->db->query("SELECT COALESCE(MAX(id), 0) AS max_id FROM sse_events")->fetch(PDO::FETCH_ASSOC);
        return (int)($row['max_id'] ?? 0);
    }

    public function getAnchorCursor(int $lastEventId): int
    {
        return $lastEventId > 0 ? $lastEventId : $this->getMaxSseId();
    }

    public function getConnectedPayload(array $user, int $cursor): array
    {
        return [
            'user_id' => (int)($user['user_id'] ?? $user['id'] ?? 0),
            'cursor' => $cursor,
        ];
    }

    public function fetchEventsSince(array $user, int $fromId, int $limit = 200): array
    {
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        $isAdmin = !empty($user['is_admin']);
        $adminLiteral = $isAdmin ? 'TRUE' : 'FALSE';

        $stmt = $this->db->prepare("
            SELECT id AS sse_id, event_type, payload::text AS event_data
            FROM sse_events
            WHERE id > ?
              AND (user_id IS NULL OR user_id = ?)
              AND (admin_only = FALSE OR admin_only = $adminLiteral)
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $fromId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $events = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data = (string)$row['event_data'];

            // Enrich chat_message events with server-rendered markdown so the
            // client can display formatted output without a client-side renderer.
            if ($row['event_type'] === 'chat_message') {
                $payload = json_decode($data, true);
                if (is_array($payload) && isset($payload['body']) && !isset($payload['markup_html'])) {
                    $payload['markup_html'] = MarkdownRenderer::toHtml((string)$payload['body']);
                    $data = json_encode($payload);
                }
            }

            $events[] = [
                'id' => (int)$row['sse_id'],
                'event' => (string)$row['event_type'],
                'data' => $data,
            ];
        }

        return $events;
    }

    public function resolveWindowSeconds(bool $isDevServer): int
    {
        if ($isDevServer) {
            return 0;
        }

        $configuredTransportMode = strtolower(trim((string)Config::env('BINKSTREAM_TRANSPORT_MODE', Config::env('REALTIME_TRANSPORT_MODE', Config::env('SSE_TRANSPORT_MODE', 'auto')))));
        if (!in_array($configuredTransportMode, ['auto', 'sse', 'ws'], true)) {
            $configuredTransportMode = 'auto';
        }

        $serverSoftware = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
        $isApacheServer = str_contains($serverSoftware, 'apache');
        $configuredWindow = Config::env('SSE_WINDOW_SECONDS', null);
        $defaultWindowSeconds = ($isApacheServer && $configuredTransportMode === 'auto') ? 2 : 60;

        return (int)($configuredWindow ?? $defaultWindowSeconds);
    }
}
