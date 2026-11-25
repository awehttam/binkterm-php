<?php

namespace BinktermPHP;

use PDO;

/**
 * Builds the "Recent Door Activity" dashboard data: the most played doors over a
 * recent window and the latest players, both drawn from door play events in
 * user_activity_log (web, DOS/native/rlogin, JS-DOS and Archive.org doors).
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

        $typeIds = [
            ActivityTracker::TYPE_WEBDOOR_PLAY,
            ActivityTracker::TYPE_DOSDOOR_PLAY,
            ActivityTracker::TYPE_ARCHIVEDOOR_PLAY,
        ];
        $typePlaceholders = implode(',', array_fill(0, count($typeIds), '?'));

        $stmt = $this->db->prepare("
            SELECT object_name AS door_id, COUNT(*) AS plays
            FROM user_activity_log
            WHERE activity_type_id IN ($typePlaceholders)
              AND object_name IS NOT NULL
              AND created_at >= NOW() - CAST(? AS INTERVAL)
            GROUP BY object_name
            ORDER BY plays DESC, object_name ASC
            LIMIT 50
        ");
        $stmt->execute(array_merge($typeIds, [$window]));

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

        $stmt = $this->db->prepare("
            SELECT username, door_id, played_at
            FROM (
                SELECT DISTINCT ON (a.user_id, a.object_name)
                    u.username,
                    a.object_name AS door_id,
                    a.created_at AT TIME ZONE 'UTC' AS played_at
                FROM user_activity_log a
                JOIN users u ON u.id = a.user_id
                WHERE a.activity_type_id IN ($typePlaceholders)
                  AND a.object_name IS NOT NULL
                  AND a.created_at >= NOW() - CAST(? AS INTERVAL)
                ORDER BY a.user_id, a.object_name, a.created_at DESC
            ) t
            ORDER BY played_at DESC
            LIMIT 50
        ");
        $stmt->execute(array_merge($typeIds, [$window]));

        $recent = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($doors[$row['door_id']])) {
                continue;
            }
            $recent[] = [
                'username' => $row['username'],
                'door_id' => $row['door_id'],
                'name' => $doors[$row['door_id']],
                'played_at' => str_replace(' ', 'T', substr($row['played_at'], 0, 19)) . 'Z',
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
    public function getVisibleDoors(bool $isAdmin): array
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
