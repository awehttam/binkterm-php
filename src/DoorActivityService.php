<?php

namespace BinktermPHP;

use PDO;

/**
 * Builds the "Recent Door Activity" dashboard data: the most played doors over a
 * recent window and the latest players seen on door leaderboards (no scores).
 */
class DoorActivityService
{
    private const WINDOW_DAYS = 30;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array $user Authenticated user row
     * @param int $limit Maximum entries in each list
     * @return array{popular: array, recent_players: array}
     */
    public function getActivity(array $user, int $limit = 5): array
    {
        $doors = $this->getVisibleDoors(!empty($user['is_admin']));
        $window = self::WINDOW_DAYS . ' days';

        $stmt = $this->db->prepare("
            SELECT door_id, COUNT(*) AS plays
            FROM (
                SELECT game_id AS door_id FROM webdoor_sessions
                WHERE created_at >= (NOW() AT TIME ZONE 'UTC') - CAST(? AS INTERVAL)
                UNION ALL
                SELECT door_id FROM door_sessions
                WHERE started_at >= NOW() - CAST(? AS INTERVAL)
            ) s
            GROUP BY door_id
            ORDER BY plays DESC, door_id ASC
            LIMIT 50
        ");
        $stmt->execute([$window, $window]);

        $popular = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($doors[$row['door_id']])) {
                continue;
            }
            $popular[] = [
                'door_id' => $row['door_id'],
                'name' => $doors[$row['door_id']],
                'plays' => (int)$row['plays'],
            ];
            if (count($popular) >= $limit) {
                break;
            }
        }

        $stmt = $this->db->query("
            SELECT username, game_id, created_at
            FROM (
                SELECT DISTINCT ON (l.user_id, l.game_id) u.username, l.game_id, l.created_at
                FROM webdoor_leaderboards l
                JOIN users u ON u.id = l.user_id
                ORDER BY l.user_id, l.game_id, l.created_at DESC
            ) t
            ORDER BY created_at DESC
            LIMIT 50
        ");

        $recent = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($doors[$row['game_id']])) {
                continue;
            }
            $recent[] = [
                'username' => $row['username'],
                'door_id' => $row['game_id'],
                'name' => $doors[$row['game_id']],
                'played_at' => str_replace(' ', 'T', substr($row['created_at'], 0, 19)) . 'Z',
            ];
            if (count($recent) >= $limit) {
                break;
            }
        }

        return ['popular' => $popular, 'recent_players' => $recent];
    }

    /**
     * Map of door id => display name for doors the user may see.
     *
     * @return array<string, string>
     */
    private function getVisibleDoors(bool $isAdmin): array
    {
        $doors = [];

        foreach (WebDoorManifest::listManifests() as $entry) {
            $game = $entry['manifest']['game'] ?? null;
            if (!$game || !GameConfig::isEnabled($entry['id'])) {
                continue;
            }
            $config = GameConfig::getGameConfig($entry['id']);
            $doors[$entry['id']] = !empty($config['display_name'])
                ? $config['display_name']
                : ($game['name'] ?? $entry['id']);
        }

        $managers = [new DoorManager(), new NativeDoorManager()];
        foreach ($managers as $manager) {
            foreach ($manager->getEnabledDoors() as $doorId => $door) {
                if (!empty($door['admin_only']) && !$isAdmin) {
                    continue;
                }
                if (!empty($door['config']['hide_from_web'])) {
                    continue;
                }
                $doors[$doorId] = DoorCategoryHelper::cleanDoorTitle($door['name'] ?? $doorId);
            }
        }

        return $doors;
    }
}
