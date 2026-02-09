<?php

/**
 * Static item definitions for NetRealm RPG.
 *
 * Items are organized by type (weapon, armor, accessory) and level tier.
 * Rarity multipliers are applied at creation time to bonus columns.
 */
class ItemDatabase
{
    /** @var array Rarity multipliers applied to item bonuses */
    public const RARITY_MULTIPLIERS = [
        'common'    => 1.0,
        'uncommon'  => 1.1,
        'rare'      => 1.25,
        'epic'      => 1.5,
        'legendary' => 2.0,
    ];

    /** @var array Rarity drop weights (out of 100) */
    public const RARITY_WEIGHTS = [
        'common'    => 60,
        'uncommon'  => 25,
        'rare'      => 10,
        'epic'      => 4,
        'legendary' => 1,
    ];

    /** @var array Rarity display colors */
    public const RARITY_COLORS = [
        'common'    => '#9d9d9d',
        'uncommon'  => '#1eff00',
        'rare'      => '#0070dd',
        'epic'      => '#a335ee',
        'legendary' => '#ff8000',
    ];

    /**
     * Get all item definitions.
     *
     * @return array Keyed by item_key
     */
    public static function getAll(): array
    {
        return [
            // === WEAPONS (Level 1-5) ===
            'rusty_dagger'     => [
                'name' => 'Rusty Dagger', 'type' => 'weapon', 'min_level' => 1,
                'attack_bonus' => 3, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 25,
            ],
            'wooden_club'      => [
                'name' => 'Wooden Club', 'type' => 'weapon', 'min_level' => 1,
                'attack_bonus' => 4, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 40,
            ],
            'short_sword'      => [
                'name' => 'Short Sword', 'type' => 'weapon', 'min_level' => 3,
                'attack_bonus' => 6, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 75,
            ],
            'hunting_bow'      => [
                'name' => 'Hunting Bow', 'type' => 'weapon', 'min_level' => 4,
                'attack_bonus' => 7, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 100,
            ],

            // === WEAPONS (Level 6-10) ===
            'steel_longsword'  => [
                'name' => 'Steel Longsword', 'type' => 'weapon', 'min_level' => 6,
                'attack_bonus' => 10, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 200,
            ],
            'war_axe'          => [
                'name' => 'War Axe', 'type' => 'weapon', 'min_level' => 8,
                'attack_bonus' => 13, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 300,
            ],
            'crossbow'         => [
                'name' => 'Crossbow', 'type' => 'weapon', 'min_level' => 9,
                'attack_bonus' => 14, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 350,
            ],

            // === WEAPONS (Level 11-20) ===
            'flamebrand'       => [
                'name' => 'Flamebrand', 'type' => 'weapon', 'min_level' => 11,
                'attack_bonus' => 18, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 600,
            ],
            'ice_halberd'      => [
                'name' => 'Ice Halberd', 'type' => 'weapon', 'min_level' => 15,
                'attack_bonus' => 24, 'defense_bonus' => 2, 'hp_bonus' => 0, 'buy_price' => 900,
            ],
            'thunder_mace'     => [
                'name' => 'Thunder Mace', 'type' => 'weapon', 'min_level' => 18,
                'attack_bonus' => 28, 'defense_bonus' => 0, 'hp_bonus' => 10, 'buy_price' => 1200,
            ],

            // === WEAPONS (Level 21-30) ===
            'shadow_blade'     => [
                'name' => 'Shadow Blade', 'type' => 'weapon', 'min_level' => 21,
                'attack_bonus' => 34, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 1800,
            ],
            'crystal_spear'    => [
                'name' => 'Crystal Spear', 'type' => 'weapon', 'min_level' => 25,
                'attack_bonus' => 40, 'defense_bonus' => 3, 'hp_bonus' => 0, 'buy_price' => 2500,
            ],
            'doom_hammer'      => [
                'name' => 'Doom Hammer', 'type' => 'weapon', 'min_level' => 28,
                'attack_bonus' => 45, 'defense_bonus' => 0, 'hp_bonus' => 15, 'buy_price' => 3000,
            ],

            // === WEAPONS (Level 31-40) ===
            'void_katana'      => [
                'name' => 'Void Katana', 'type' => 'weapon', 'min_level' => 31,
                'attack_bonus' => 52, 'defense_bonus' => 0, 'hp_bonus' => 0, 'buy_price' => 4000,
            ],
            'phoenix_bow'      => [
                'name' => 'Phoenix Bow', 'type' => 'weapon', 'min_level' => 35,
                'attack_bonus' => 58, 'defense_bonus' => 0, 'hp_bonus' => 20, 'buy_price' => 5000,
            ],

            // === WEAPONS (Level 41-50) ===
            'dragonslayer'     => [
                'name' => 'Dragonslayer', 'type' => 'weapon', 'min_level' => 41,
                'attack_bonus' => 68, 'defense_bonus' => 5, 'hp_bonus' => 0, 'buy_price' => 7000,
            ],
            'eternal_blade'    => [
                'name' => 'Eternal Blade', 'type' => 'weapon', 'min_level' => 45,
                'attack_bonus' => 78, 'defense_bonus' => 0, 'hp_bonus' => 30, 'buy_price' => 9000,
            ],

            // === ARMOR (Level 1-5) ===
            'cloth_tunic'      => [
                'name' => 'Cloth Tunic', 'type' => 'armor', 'min_level' => 1,
                'attack_bonus' => 0, 'defense_bonus' => 2, 'hp_bonus' => 5, 'buy_price' => 20,
            ],
            'leather_vest'     => [
                'name' => 'Leather Vest', 'type' => 'armor', 'min_level' => 3,
                'attack_bonus' => 0, 'defense_bonus' => 4, 'hp_bonus' => 10, 'buy_price' => 60,
            ],
            'chain_mail'       => [
                'name' => 'Chain Mail', 'type' => 'armor', 'min_level' => 5,
                'attack_bonus' => 0, 'defense_bonus' => 6, 'hp_bonus' => 15, 'buy_price' => 120,
            ],

            // === ARMOR (Level 6-10) ===
            'steel_plate'      => [
                'name' => 'Steel Plate', 'type' => 'armor', 'min_level' => 6,
                'attack_bonus' => 0, 'defense_bonus' => 9, 'hp_bonus' => 20, 'buy_price' => 250,
            ],
            'reinforced_mail'  => [
                'name' => 'Reinforced Mail', 'type' => 'armor', 'min_level' => 9,
                'attack_bonus' => 0, 'defense_bonus' => 12, 'hp_bonus' => 25, 'buy_price' => 400,
            ],

            // === ARMOR (Level 11-20) ===
            'enchanted_plate'  => [
                'name' => 'Enchanted Plate', 'type' => 'armor', 'min_level' => 11,
                'attack_bonus' => 2, 'defense_bonus' => 16, 'hp_bonus' => 30, 'buy_price' => 650,
            ],
            'dragonhide_armor' => [
                'name' => 'Dragonhide Armor', 'type' => 'armor', 'min_level' => 16,
                'attack_bonus' => 0, 'defense_bonus' => 22, 'hp_bonus' => 40, 'buy_price' => 1000,
            ],
            'mithril_coat'     => [
                'name' => 'Mithril Coat', 'type' => 'armor', 'min_level' => 20,
                'attack_bonus' => 3, 'defense_bonus' => 26, 'hp_bonus' => 45, 'buy_price' => 1400,
            ],

            // === ARMOR (Level 21-30) ===
            'shadow_cloak'     => [
                'name' => 'Shadow Cloak', 'type' => 'armor', 'min_level' => 22,
                'attack_bonus' => 5, 'defense_bonus' => 30, 'hp_bonus' => 50, 'buy_price' => 2000,
            ],
            'crystal_armor'    => [
                'name' => 'Crystal Armor', 'type' => 'armor', 'min_level' => 27,
                'attack_bonus' => 0, 'defense_bonus' => 38, 'hp_bonus' => 60, 'buy_price' => 2800,
            ],

            // === ARMOR (Level 31-40) ===
            'void_plate'       => [
                'name' => 'Void Plate', 'type' => 'armor', 'min_level' => 32,
                'attack_bonus' => 5, 'defense_bonus' => 44, 'hp_bonus' => 70, 'buy_price' => 4200,
            ],
            'phoenix_robes'    => [
                'name' => 'Phoenix Robes', 'type' => 'armor', 'min_level' => 37,
                'attack_bonus' => 8, 'defense_bonus' => 50, 'hp_bonus' => 80, 'buy_price' => 5500,
            ],

            // === ARMOR (Level 41-50) ===
            'eternal_aegis'    => [
                'name' => 'Eternal Aegis', 'type' => 'armor', 'min_level' => 42,
                'attack_bonus' => 5, 'defense_bonus' => 60, 'hp_bonus' => 100, 'buy_price' => 8000,
            ],

            // === ACCESSORIES (Level 1-5) ===
            'copper_ring'      => [
                'name' => 'Copper Ring', 'type' => 'accessory', 'min_level' => 1,
                'attack_bonus' => 1, 'defense_bonus' => 1, 'hp_bonus' => 5, 'buy_price' => 30,
            ],
            'lucky_charm'      => [
                'name' => 'Lucky Charm', 'type' => 'accessory', 'min_level' => 3,
                'attack_bonus' => 2, 'defense_bonus' => 2, 'hp_bonus' => 0, 'buy_price' => 50,
            ],

            // === ACCESSORIES (Level 6-10) ===
            'silver_amulet'    => [
                'name' => 'Silver Amulet', 'type' => 'accessory', 'min_level' => 6,
                'attack_bonus' => 3, 'defense_bonus' => 3, 'hp_bonus' => 15, 'buy_price' => 180,
            ],
            'wolf_fang'        => [
                'name' => 'Wolf Fang Pendant', 'type' => 'accessory', 'min_level' => 8,
                'attack_bonus' => 5, 'defense_bonus' => 2, 'hp_bonus' => 10, 'buy_price' => 250,
            ],

            // === ACCESSORIES (Level 11-20) ===
            'fire_gem'         => [
                'name' => 'Fire Gem', 'type' => 'accessory', 'min_level' => 12,
                'attack_bonus' => 8, 'defense_bonus' => 3, 'hp_bonus' => 15, 'buy_price' => 500,
            ],
            'frost_pendant'    => [
                'name' => 'Frost Pendant', 'type' => 'accessory', 'min_level' => 16,
                'attack_bonus' => 5, 'defense_bonus' => 8, 'hp_bonus' => 25, 'buy_price' => 750,
            ],

            // === ACCESSORIES (Level 21-30) ===
            'shadow_ring'      => [
                'name' => 'Shadow Ring', 'type' => 'accessory', 'min_level' => 22,
                'attack_bonus' => 12, 'defense_bonus' => 8, 'hp_bonus' => 30, 'buy_price' => 1500,
            ],
            'crystal_orb'      => [
                'name' => 'Crystal Orb', 'type' => 'accessory', 'min_level' => 27,
                'attack_bonus' => 10, 'defense_bonus' => 12, 'hp_bonus' => 40, 'buy_price' => 2200,
            ],

            // === ACCESSORIES (Level 31-40) ===
            'void_talisman'    => [
                'name' => 'Void Talisman', 'type' => 'accessory', 'min_level' => 33,
                'attack_bonus' => 16, 'defense_bonus' => 14, 'hp_bonus' => 50, 'buy_price' => 3500,
            ],

            // === ACCESSORIES (Level 41-50) ===
            'dragon_heart'     => [
                'name' => 'Dragon Heart', 'type' => 'accessory', 'min_level' => 42,
                'attack_bonus' => 20, 'defense_bonus' => 18, 'hp_bonus' => 75, 'buy_price' => 7500,
            ],
        ];
    }

    /**
     * Get a single item definition by key.
     *
     * @param string $key
     * @return array|null
     */
    public static function get(string $key): ?array
    {
        $items = self::getAll();
        return $items[$key] ?? null;
    }

    /**
     * Get items available at or below a given level.
     *
     * @param int $level
     * @return array Keyed by item_key
     */
    public static function getForLevel(int $level): array
    {
        $result = [];
        foreach (self::getAll() as $key => $item) {
            if ($item['min_level'] <= $level) {
                $result[$key] = $item;
            }
        }
        return $result;
    }

    /**
     * Get items of a specific type available at or below a given level.
     *
     * @param string $type weapon|armor|accessory
     * @param int $level
     * @return array Keyed by item_key
     */
    public static function getForLevelByType(string $type, int $level): array
    {
        $result = [];
        foreach (self::getAll() as $key => $item) {
            if ($item['type'] === $type && $item['min_level'] <= $level) {
                $result[$key] = $item;
            }
        }
        return $result;
    }

    /**
     * Roll a random rarity based on weighted distribution.
     *
     * @return string
     */
    public static function rollRarity(): string
    {
        $roll = random_int(1, 100);
        $cumulative = 0;
        foreach (self::RARITY_WEIGHTS as $rarity => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $rarity;
            }
        }
        return 'common';
    }

    /**
     * Apply rarity multiplier to an item's bonuses.
     *
     * @param array $item Base item definition
     * @param string $rarity
     * @return array Item with rarity-modified bonuses
     */
    public static function applyRarity(array $item, string $rarity): array
    {
        $mult = self::RARITY_MULTIPLIERS[$rarity] ?? 1.0;
        $item['rarity'] = $rarity;
        $item['attack_bonus'] = (int)round($item['attack_bonus'] * $mult);
        $item['defense_bonus'] = (int)round($item['defense_bonus'] * $mult);
        $item['hp_bonus'] = (int)round($item['hp_bonus'] * $mult);
        return $item;
    }

    /**
     * Get a random loot drop appropriate for a given level.
     *
     * @param int $level Player level
     * @return array|null Item with rarity applied, or null if no items available
     */
    public static function getRandomLoot(int $level): ?array
    {
        $available = self::getForLevel($level);
        if (empty($available)) {
            return null;
        }

        $keys = array_keys($available);
        $key = $keys[array_rand($keys)];
        $item = $available[$key];
        $rarity = self::rollRarity();

        $item = self::applyRarity($item, $rarity);
        $item['item_key'] = $key;

        return $item;
    }
}
