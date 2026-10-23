<?php

namespace BinktermPHP\DoorData;

use BinktermPHP\Database;

/**
 * Copies parsed door game scores into webdoor_leaderboards so they appear in
 * the /games Top Scores table alongside WebDoor scores.
 *
 * Game players are linked to BBS users by matching the real name stored in the
 * game record against users.username or users.real_name. Ambiguous or
 * unmatched players are skipped. A row is written only when a user's score
 * differs from their most recent row for the door, so unchanged players add
 * nothing.
 *
 * @package BinktermPHP
 */
class DoorLeaderboardSync
{
    public const BOARD = 'default';

    private \PDO $db;
    private DoorDataService $service;

    /**
     * @param DoorDataService|null $service Data service (defaults to a new instance)
     * @param \PDO|null $db Database connection (defaults to the shared connection)
     */
    public function __construct(?DoorDataService $service = null, ?\PDO $db = null)
    {
        $this->service = $service ?? new DoorDataService();
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * Sync one door.
     *
     * @param string $doorId Door identifier (directory name)
     * @return array{inserted:int, unchanged:int, unmatched:int}
     * @throws DoorDataParseException When the door has no parser or its data cannot be read
     */
    public function syncDoor(string $doorId): array
    {
        $result = ['inserted' => 0, 'unchanged' => 0, 'unmatched' => 0];

        foreach ($this->service->getPlayers($doorId) as $player) {
            $userId = $this->findUserId($player['real_name'] ?? null);
            if ($userId === null) {
                $result['unmatched']++;
                continue;
            }

            $score = (int)$player['score'];
            if ($score <= 0 || $this->latestScore($userId, $doorId) === $score) {
                $result['unchanged']++;
                continue;
            }

            $stmt = $this->db->prepare('
                INSERT INTO webdoor_leaderboards (user_id, game_id, board, score, metadata)
                VALUES (?, ?, ?, ?, ?::jsonb)
            ');
            $stmt->execute([
                $userId,
                $doorId,
                self::BOARD,
                $score,
                json_encode(['handle' => $player['handle'], 'stats' => $player['stats']]),
            ]);
            $result['inserted']++;
        }

        return $result;
    }

    /**
     * Find the single BBS user matching a name, or null if none or ambiguous.
     *
     * @param string|null $name Name from the game record
     * @return int|null
     */
    private function findUserId(?string $name): ?int
    {
        $name = trim((string)$name);
        if ($name === '') {
            return null;
        }
        $stmt = $this->db->prepare('
            SELECT id FROM users
            WHERE LOWER(username) = LOWER(?)
               OR LOWER(REPLACE(username, \'_\', \' \')) = LOWER(?)
               OR LOWER(real_name) = LOWER(?)
            LIMIT 2
        ');
        $stmt->execute([$name, $name, $name]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return count($rows) === 1 ? (int)$rows[0]['id'] : null;
    }

    /**
     * @param int $userId BBS user id
     * @param string $doorId Door identifier
     * @return int|null Most recent stored score, or null if none
     */
    private function latestScore(int $userId, string $doorId): ?int
    {
        $stmt = $this->db->prepare('
            SELECT score FROM webdoor_leaderboards
            WHERE user_id = ? AND game_id = ? AND board = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute([$userId, $doorId, self::BOARD]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (int)$row['score'] : null;
    }
}
