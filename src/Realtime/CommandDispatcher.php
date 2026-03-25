<?php

namespace BinktermPHP\Realtime;

use BinktermPHP\DashboardStatsService;
use PDO;
use RuntimeException;

class CommandDispatcher
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function dispatch(array $user, string $command, array $payload = []): array
    {
        return match ($command) {
            'ping' => [
                'success' => true,
                'result' => [
                    'pong' => true,
                    'ts' => (int)round(microtime(true) * 1000),
                ],
            ],
            'get_dashboard_stats' => [
                'success' => true,
                'result' => (new DashboardStatsService($this->db))->getStats($user),
            ],
            default => throw new RuntimeException('unknown_command'),
        };
    }
}
