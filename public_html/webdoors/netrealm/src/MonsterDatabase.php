<?php

/**
 * Static monster definitions for NetRealm RPG.
 *
 * Monsters are organized in 6 level tiers. Stats scale within tier
 * based on player level for balanced encounters.
 */
class MonsterDatabase
{
    /**
     * Get all monster definitions.
     *
     * @return array Keyed by monster_key
     */
    public static function getAll(): array
    {
        return [
            // === TIER 1: Level 1-5 ===
            'rat'              => [
                'name' => 'Giant Rat', 'tier' => 1, 'min_level' => 1, 'max_level' => 5,
                'base_hp' => 20, 'base_attack' => 5, 'base_defense' => 2,
                'xp_reward' => 15, 'gold_reward' => 8, 'loot_chance' => 0.15,
                'icon' => 'fa-paw',
            ],
            'slime'            => [
                'name' => 'Green Slime', 'tier' => 1, 'min_level' => 1, 'max_level' => 5,
                'base_hp' => 25, 'base_attack' => 4, 'base_defense' => 3,
                'xp_reward' => 18, 'gold_reward' => 10, 'loot_chance' => 0.18,
                'icon' => 'fa-droplet',
            ],
            'goblin'           => [
                'name' => 'Goblin Scout', 'tier' => 1, 'min_level' => 2, 'max_level' => 5,
                'base_hp' => 30, 'base_attack' => 7, 'base_defense' => 3,
                'xp_reward' => 22, 'gold_reward' => 15, 'loot_chance' => 0.20,
                'icon' => 'fa-person',
            ],
            'skeleton'         => [
                'name' => 'Skeleton Warrior', 'tier' => 1, 'min_level' => 3, 'max_level' => 5,
                'base_hp' => 35, 'base_attack' => 8, 'base_defense' => 4,
                'xp_reward' => 28, 'gold_reward' => 18, 'loot_chance' => 0.22,
                'icon' => 'fa-skull',
            ],
            'spider'           => [
                'name' => 'Cave Spider', 'tier' => 1, 'min_level' => 4, 'max_level' => 5,
                'base_hp' => 28, 'base_attack' => 10, 'base_defense' => 3,
                'xp_reward' => 30, 'gold_reward' => 20, 'loot_chance' => 0.20,
                'icon' => 'fa-spider',
            ],

            // === TIER 2: Level 6-10 ===
            'orc'              => [
                'name' => 'Orc Brute', 'tier' => 2, 'min_level' => 6, 'max_level' => 10,
                'base_hp' => 60, 'base_attack' => 15, 'base_defense' => 8,
                'xp_reward' => 45, 'gold_reward' => 30, 'loot_chance' => 0.20,
                'icon' => 'fa-hand-fist',
            ],
            'wolf'             => [
                'name' => 'Dire Wolf', 'tier' => 2, 'min_level' => 6, 'max_level' => 10,
                'base_hp' => 50, 'base_attack' => 18, 'base_defense' => 6,
                'xp_reward' => 42, 'gold_reward' => 25, 'loot_chance' => 0.18,
                'icon' => 'fa-dog',
            ],
            'bandit'           => [
                'name' => 'Road Bandit', 'tier' => 2, 'min_level' => 7, 'max_level' => 10,
                'base_hp' => 55, 'base_attack' => 16, 'base_defense' => 10,
                'xp_reward' => 50, 'gold_reward' => 40, 'loot_chance' => 0.25,
                'icon' => 'fa-mask',
            ],
            'troll'            => [
                'name' => 'Swamp Troll', 'tier' => 2, 'min_level' => 8, 'max_level' => 10,
                'base_hp' => 80, 'base_attack' => 14, 'base_defense' => 12,
                'xp_reward' => 55, 'gold_reward' => 35, 'loot_chance' => 0.22,
                'icon' => 'fa-tree',
            ],
            'harpy'            => [
                'name' => 'Harpy', 'tier' => 2, 'min_level' => 9, 'max_level' => 10,
                'base_hp' => 45, 'base_attack' => 20, 'base_defense' => 7,
                'xp_reward' => 52, 'gold_reward' => 32, 'loot_chance' => 0.20,
                'icon' => 'fa-feather',
            ],

            // === TIER 3: Level 11-20 ===
            'dark_knight'      => [
                'name' => 'Dark Knight', 'tier' => 3, 'min_level' => 11, 'max_level' => 20,
                'base_hp' => 120, 'base_attack' => 28, 'base_defense' => 18,
                'xp_reward' => 80, 'gold_reward' => 55, 'loot_chance' => 0.22,
                'icon' => 'fa-chess-knight',
            ],
            'wraith'           => [
                'name' => 'Wraith', 'tier' => 3, 'min_level' => 12, 'max_level' => 20,
                'base_hp' => 100, 'base_attack' => 32, 'base_defense' => 14,
                'xp_reward' => 85, 'gold_reward' => 50, 'loot_chance' => 0.20,
                'icon' => 'fa-ghost',
            ],
            'minotaur'         => [
                'name' => 'Minotaur', 'tier' => 3, 'min_level' => 14, 'max_level' => 20,
                'base_hp' => 150, 'base_attack' => 30, 'base_defense' => 20,
                'xp_reward' => 95, 'gold_reward' => 65, 'loot_chance' => 0.22,
                'icon' => 'fa-bullseye',
            ],
            'necromancer'      => [
                'name' => 'Necromancer', 'tier' => 3, 'min_level' => 16, 'max_level' => 20,
                'base_hp' => 110, 'base_attack' => 35, 'base_defense' => 16,
                'xp_reward' => 100, 'gold_reward' => 70, 'loot_chance' => 0.25,
                'icon' => 'fa-hat-wizard',
            ],
            'golem'            => [
                'name' => 'Stone Golem', 'tier' => 3, 'min_level' => 18, 'max_level' => 20,
                'base_hp' => 200, 'base_attack' => 25, 'base_defense' => 28,
                'xp_reward' => 110, 'gold_reward' => 75, 'loot_chance' => 0.22,
                'icon' => 'fa-mountain',
            ],

            // === TIER 4: Level 21-30 ===
            'demon'            => [
                'name' => 'Fire Demon', 'tier' => 4, 'min_level' => 21, 'max_level' => 30,
                'base_hp' => 220, 'base_attack' => 45, 'base_defense' => 25,
                'xp_reward' => 150, 'gold_reward' => 100, 'loot_chance' => 0.22,
                'icon' => 'fa-fire',
            ],
            'hydra'            => [
                'name' => 'Hydra', 'tier' => 4, 'min_level' => 23, 'max_level' => 30,
                'base_hp' => 280, 'base_attack' => 42, 'base_defense' => 30,
                'xp_reward' => 170, 'gold_reward' => 110, 'loot_chance' => 0.25,
                'icon' => 'fa-dragon',
            ],
            'lich'             => [
                'name' => 'Lich', 'tier' => 4, 'min_level' => 25, 'max_level' => 30,
                'base_hp' => 200, 'base_attack' => 50, 'base_defense' => 28,
                'xp_reward' => 180, 'gold_reward' => 120, 'loot_chance' => 0.25,
                'icon' => 'fa-skull-crossbones',
            ],
            'frost_giant'      => [
                'name' => 'Frost Giant', 'tier' => 4, 'min_level' => 27, 'max_level' => 30,
                'base_hp' => 320, 'base_attack' => 48, 'base_defense' => 35,
                'xp_reward' => 200, 'gold_reward' => 130, 'loot_chance' => 0.22,
                'icon' => 'fa-snowflake',
            ],
            'chimera'          => [
                'name' => 'Chimera', 'tier' => 4, 'min_level' => 29, 'max_level' => 30,
                'base_hp' => 260, 'base_attack' => 55, 'base_defense' => 30,
                'xp_reward' => 210, 'gold_reward' => 140, 'loot_chance' => 0.25,
                'icon' => 'fa-paw',
            ],

            // === TIER 5: Level 31-40 ===
            'void_walker'      => [
                'name' => 'Void Walker', 'tier' => 5, 'min_level' => 31, 'max_level' => 40,
                'base_hp' => 350, 'base_attack' => 65, 'base_defense' => 40,
                'xp_reward' => 280, 'gold_reward' => 180, 'loot_chance' => 0.22,
                'icon' => 'fa-circle-notch',
            ],
            'shadow_dragon'    => [
                'name' => 'Shadow Dragon', 'tier' => 5, 'min_level' => 33, 'max_level' => 40,
                'base_hp' => 420, 'base_attack' => 70, 'base_defense' => 45,
                'xp_reward' => 320, 'gold_reward' => 200, 'loot_chance' => 0.25,
                'icon' => 'fa-dragon',
            ],
            'death_knight'     => [
                'name' => 'Death Knight', 'tier' => 5, 'min_level' => 35, 'max_level' => 40,
                'base_hp' => 380, 'base_attack' => 75, 'base_defense' => 50,
                'xp_reward' => 350, 'gold_reward' => 220, 'loot_chance' => 0.25,
                'icon' => 'fa-chess-knight',
            ],
            'phoenix'          => [
                'name' => 'Dark Phoenix', 'tier' => 5, 'min_level' => 38, 'max_level' => 40,
                'base_hp' => 300, 'base_attack' => 80, 'base_defense' => 42,
                'xp_reward' => 370, 'gold_reward' => 240, 'loot_chance' => 0.25,
                'icon' => 'fa-fire-flame-curved',
            ],

            // === TIER 6: Level 41-50 ===
            'ancient_dragon'   => [
                'name' => 'Ancient Dragon', 'tier' => 6, 'min_level' => 41, 'max_level' => 50,
                'base_hp' => 550, 'base_attack' => 90, 'base_defense' => 55,
                'xp_reward' => 450, 'gold_reward' => 300, 'loot_chance' => 0.25,
                'icon' => 'fa-dragon',
            ],
            'demon_lord'       => [
                'name' => 'Demon Lord', 'tier' => 6, 'min_level' => 43, 'max_level' => 50,
                'base_hp' => 600, 'base_attack' => 95, 'base_defense' => 60,
                'xp_reward' => 500, 'gold_reward' => 350, 'loot_chance' => 0.28,
                'icon' => 'fa-fire',
            ],
            'titan'            => [
                'name' => 'Titan', 'tier' => 6, 'min_level' => 45, 'max_level' => 50,
                'base_hp' => 700, 'base_attack' => 88, 'base_defense' => 70,
                'xp_reward' => 550, 'gold_reward' => 380, 'loot_chance' => 0.25,
                'icon' => 'fa-mountain',
            ],
            'world_serpent'    => [
                'name' => 'World Serpent', 'tier' => 6, 'min_level' => 47, 'max_level' => 50,
                'base_hp' => 650, 'base_attack' => 100, 'base_defense' => 65,
                'xp_reward' => 600, 'gold_reward' => 400, 'loot_chance' => 0.28,
                'icon' => 'fa-worm',
            ],
            'elder_god'        => [
                'name' => 'Elder God', 'tier' => 6, 'min_level' => 49, 'max_level' => 50,
                'base_hp' => 800, 'base_attack' => 110, 'base_defense' => 75,
                'xp_reward' => 700, 'gold_reward' => 500, 'loot_chance' => 0.30,
                'icon' => 'fa-eye',
            ],
        ];
    }

    /**
     * Get a single monster definition by key.
     *
     * @param string $key
     * @return array|null
     */
    public static function get(string $key): ?array
    {
        $monsters = self::getAll();
        return $monsters[$key] ?? null;
    }

    /**
     * Get monsters available for a given player level.
     *
     * @param int $playerLevel
     * @return array Keyed by monster_key, with scaled stats
     */
    public static function getForLevel(int $playerLevel): array
    {
        $result = [];
        foreach (self::getAll() as $key => $monster) {
            if ($playerLevel >= $monster['min_level'] && $playerLevel <= $monster['max_level']) {
                $result[$key] = self::scaleMonster($monster, $playerLevel);
                $result[$key]['key'] = $key;
            }
        }
        return $result;
    }

    /**
     * Scale monster stats based on player level within the monster's tier.
     *
     * @param array $monster Base monster definition
     * @param int $playerLevel
     * @return array Monster with scaled stats
     */
    public static function scaleMonster(array $monster, int $playerLevel): array
    {
        $tierRange = $monster['max_level'] - $monster['min_level'];
        $levelInTier = $playerLevel - $monster['min_level'];

        if ($tierRange > 0) {
            $scaleFactor = 1.0 + ($levelInTier / $tierRange) * 0.4;
        } else {
            $scaleFactor = 1.0;
        }

        $monster['hp'] = (int)round($monster['base_hp'] * $scaleFactor);
        $monster['attack'] = (int)round($monster['base_attack'] * $scaleFactor);
        $monster['defense'] = (int)round($monster['base_defense'] * $scaleFactor);
        $monster['xp_reward'] = (int)round($monster['xp_reward'] * $scaleFactor);
        $monster['gold_reward'] = (int)round($monster['gold_reward'] * $scaleFactor);

        return $monster;
    }
}
