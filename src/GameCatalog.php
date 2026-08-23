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
    public function getEnabledGames(): array
    {
        $games = [];

        $sources = [
            'dos' => (new DoorManager())->getEnabledDoors(),
            'native' => (new NativeDoorManager())->getEnabledDoors(),
        ];

        foreach ($sources as $type => $doors) {
            foreach ($doors as $id => $door) {
                $game = $door;

                $games[$id] = [
                    'id' => $id,
                    'type' => $type,
                    'name' => $game['name'] ?? $door['name'] ?? $id,
                    'description' => $game['description'] ?? $door['description'] ?? '',
                    'players' => $game['players'] ?? null,
                    'genre' => $game['genre'] ?? [],
                    'icon' => $game['icon'] ?? null,
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
