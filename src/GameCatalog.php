<?php
/**
 * Game Catalog
 *
 * Provides a unified view of playable experiences.
 * Built on existing door managers.
 *
 * @package BinkTermPHP
 */

namespace BinktermPHP;

class GameCatalog
{
    /**
     * Get enabled playable experiences.
     *
     * @return array
     */
    public function getEnabledGames(?array $user = null, string $surface = 'web'): array
    {
        $games = [];

        $sources = [
            'dos' => (new DoorManager())->getEnabledDoors(),
            'native' => (new NativeDoorManager())->getEnabledDoors(),
        ];

        foreach ($sources as $type => $doors) {
            foreach ($doors as $id => $door) {
                if (!empty($door['admin_only']) && empty($user['is_admin'])) {
                    continue;
                }

                // Web may hide terminal-only doors; terminal access intentionally
                // continues to expose them.
                if ($surface === 'web' && !empty($door['config']['hide_from_web'])) {
                    continue;
                }

                $game = $door;

                $games[$id] = [
                    'id' => $id,
                    'type' => $type . 'door',
                    'name' => $game['name'] ?? $door['name'] ?? $id,
                    'description' => $game['description'] ?? $door['description'] ?? '',
                    'author' => $door['author'] ?? null,
                    'version' => $door['game_version'] ?? null,
                    'path' => $id,
                    'icon' => $game['icon'] ?? null,
                    'icon_url' => "/door-assets/{$id}/icon",
                    'players' => $game['players'] ?? null,
                    'genre' => $game['genre'] ?? [],
                    'experience' => $door['experience'] ?? [
                        'category' => 'game',
                        'featured' => false,
                        'multiplayer' => false,
                    ],
                    'source' => $door,
                ];
            }
        }

        return $games;
    }
}
