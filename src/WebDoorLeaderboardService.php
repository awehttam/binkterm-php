<?php

namespace BinktermPHP;

use PDO;

/**
 * Builds the monthly WebDoor "Top Scores" leaderboard shown on the web doors
 * page and in the terminal server doors menu.
 */
class WebDoorLeaderboardService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Resolve the UTC boundaries and display label for a month in the user's timezone.
     *
     * @param int $userId User whose timezone setting defines the month boundaries
     * @param int $monthOffset Months back from the current month (0 = current)
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, label: string}
     */
    public function getMonthRange(int $userId, int $monthOffset = 0): array
    {
        $monthOffset = max(0, min(120, $monthOffset));

        // Falls back to the server's PHP timezone when the user's setting is empty or invalid.
        $tz = new \DateTimeZone(date_default_timezone_get());
        try {
            $tzSetting = (new MessageHandler())->getUserSettings($userId)['timezone'] ?? '';
            if ($tzSetting !== '') {
                $tz = new \DateTimeZone($tzSetting);
            }
        } catch (\Throwable $e) {
            // invalid timezone name: keep the server default
        }
        $utc = new \DateTimeZone('UTC');

        $start = new \DateTimeImmutable('first day of this month 00:00:00', $tz);
        if ($monthOffset > 0) {
            $start = $start->modify("-{$monthOffset} months");
        }
        $end = $start->modify('+1 month');

        return [
            'start' => $start->setTimezone($utc),
            'end' => $end->setTimezone($utc),
            'label' => $start->format('F Y'),
        ];
    }

    /**
     * Fetch the best score per user/game/board within a month, highest first.
     *
     * @param \DateTimeImmutable $start Inclusive UTC start
     * @param \DateTimeImmutable $end Exclusive UTC end
     * @param int $limit Maximum rows
     * @param array<string, array{name: string, path?: ?string}> $gameLookup Game id => info
     * @return array<int, array{rank: int, display_name: string, score: int, game_id: string, game_name: string, game_path: ?string, board: string, date: string}>
     */
    public function getLeaderboard(\DateTimeImmutable $start, \DateTimeImmutable $end, int $limit, array $gameLookup): array
    {
        $stmt = $this->db->prepare('
            WITH best_scores AS (
                SELECT DISTINCT ON (l.user_id, l.game_id, l.board)
                    l.user_id, l.game_id, l.board, l.score, l.created_at
                FROM webdoor_leaderboards l
                WHERE l.created_at >= ?
                  AND l.created_at < ?
                ORDER BY l.user_id, l.game_id, l.board, l.score DESC, l.created_at DESC
            )
            SELECT b.game_id, b.board, u.real_name, u.username, b.score, b.created_at
            FROM best_scores b
            JOIN users u ON b.user_id = u.id
            ORDER BY b.score DESC, b.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $start->format('Y-m-d H:i:s'));
        $stmt->bindValue(2, $end->format('Y-m-d H:i:s'));
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $leaderboard = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index => $row) {
            $gameInfo = $gameLookup[$row['game_id']] ?? null;
            $leaderboard[] = [
                'rank' => $index + 1,
                'display_name' => $row['username'],
                'score' => (int)$row['score'],
                'game_id' => $row['game_id'],
                'game_name' => $gameInfo['name'] ?? ucfirst($row['game_id']),
                'game_path' => $gameInfo['path'] ?? null,
                'board' => $row['board'],
                'date' => substr($row['created_at'], 0, 10),
            ];
        }

        return $leaderboard;
    }
}
