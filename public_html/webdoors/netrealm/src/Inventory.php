<?php

/**
 * Inventory and equipment management for NetRealm RPG.
 *
 * Handles item storage, equipment slots (weapon, armor, accessory),
 * and inventory size limits.
 */
class Inventory
{
    private \PDO $db;

    /** @var array Valid equipment slot types */
    public const EQUIPMENT_SLOTS = ['weapon', 'armor', 'accessory'];

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all inventory items for a character.
     *
     * @param int $characterId
     * @return array
     */
    public function getAll(int $characterId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM netrealm_inventory
            WHERE character_id = ?
            ORDER BY is_equipped DESC, item_type, item_name
        ');
        $stmt->execute([$characterId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get a single inventory item.
     *
     * @param int $itemId
     * @param int $characterId For ownership verification
     * @return array|null
     */
    public function getItem(int $itemId, int $characterId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM netrealm_inventory
            WHERE id = ? AND character_id = ?
        ');
        $stmt->execute([$itemId, $characterId]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $item ?: null;
    }

    /**
     * Get equipped items for a character.
     *
     * @param int $characterId
     * @return array Keyed by item_type
     */
    public function getEquipped(int $characterId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM netrealm_inventory
            WHERE character_id = ? AND is_equipped = TRUE
        ');
        $stmt->execute([$characterId]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $equipped = [];
        foreach ($items as $item) {
            $equipped[$item['item_type']] = $item;
        }
        return $equipped;
    }

    /**
     * Get inventory item count.
     *
     * @param int $characterId
     * @return int
     */
    public function getCount(int $characterId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM netrealm_inventory WHERE character_id = ?');
        $stmt->execute([$characterId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Equip an item. Auto-unequips current item in the same slot.
     *
     * @param int $itemId
     * @param int $characterId
     * @return array ['success' => bool, 'error' => string|null, 'unequipped' => string|null]
     */
    public function equip(int $itemId, int $characterId): array
    {
        $item = $this->getItem($itemId, $characterId);
        if (!$item) {
            return ['success' => false, 'error' => 'Item not found.'];
        }

        if ($item['is_equipped']) {
            return ['success' => false, 'error' => 'Item is already equipped.'];
        }

        if (!in_array($item['item_type'], self::EQUIPMENT_SLOTS, true)) {
            return ['success' => false, 'error' => 'This item cannot be equipped.'];
        }

        $unequippedName = null;

        // Unequip current item in same slot
        $stmt = $this->db->prepare('
            SELECT id, item_name FROM netrealm_inventory
            WHERE character_id = ? AND item_type = ? AND is_equipped = TRUE
        ');
        $stmt->execute([$characterId, $item['item_type']]);
        $current = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($current) {
            $this->db->prepare('UPDATE netrealm_inventory SET is_equipped = FALSE WHERE id = ?')
                ->execute([$current['id']]);
            $unequippedName = $current['item_name'];
        }

        // Equip new item
        $this->db->prepare('UPDATE netrealm_inventory SET is_equipped = TRUE WHERE id = ?')
            ->execute([$itemId]);

        return [
            'success' => true,
            'error' => null,
            'unequipped' => $unequippedName,
        ];
    }

    /**
     * Unequip an item.
     *
     * @param int $itemId
     * @param int $characterId
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function unequip(int $itemId, int $characterId): array
    {
        $item = $this->getItem($itemId, $characterId);
        if (!$item) {
            return ['success' => false, 'error' => 'Item not found.'];
        }

        if (!$item['is_equipped']) {
            return ['success' => false, 'error' => 'Item is not equipped.'];
        }

        $this->db->prepare('UPDATE netrealm_inventory SET is_equipped = FALSE WHERE id = ?')
            ->execute([$itemId]);

        return ['success' => true, 'error' => null];
    }

    /**
     * Add an item to inventory (used by shop purchases).
     *
     * @param int $characterId
     * @param string $itemKey
     * @param array $itemData
     * @param string $rarity
     * @return int New item ID
     */
    public function addItem(int $characterId, string $itemKey, array $itemData, string $rarity = 'common'): int
    {
        $item = ItemDatabase::applyRarity($itemData, $rarity);

        $stmt = $this->db->prepare('
            INSERT INTO netrealm_inventory
                (character_id, item_key, item_name, item_type, rarity, attack_bonus, defense_bonus, hp_bonus, buy_price)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $characterId,
            $itemKey,
            $item['name'],
            $item['type'],
            $rarity,
            $item['attack_bonus'],
            $item['defense_bonus'],
            $item['hp_bonus'],
            $item['buy_price'],
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Remove an item from inventory.
     *
     * @param int $itemId
     * @param int $characterId
     * @return bool
     */
    public function removeItem(int $itemId, int $characterId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM netrealm_inventory WHERE id = ? AND character_id = ?');
        $stmt->execute([$itemId, $characterId]);
        return $stmt->rowCount() > 0;
    }
}
