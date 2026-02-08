<?php

/**
 * Shop system for NetRealm RPG.
 *
 * Level-filtered items from ItemDatabase. Always available stock.
 * Sell at configurable percentage of buy price.
 */
class Shop
{
    private \PDO $db;
    private Character $character;
    private Inventory $inventory;

    public function __construct(\PDO $db, Character $character, Inventory $inventory)
    {
        $this->db = $db;
        $this->character = $character;
        $this->inventory = $inventory;
    }

    /**
     * Get shop items available for a character's level.
     *
     * @param int $level Character level
     * @return array Items with keys preserved
     */
    public function getAvailableItems(int $level): array
    {
        $items = ItemDatabase::getForLevel($level);
        $result = [];
        foreach ($items as $key => $item) {
            $item['item_key'] = $key;
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Buy an item from the shop.
     *
     * @param int $characterId
     * @param string $itemKey
     * @param array $config Game configuration
     * @return array ['success' => bool, 'error' => string|null, 'item' => array|null]
     */
    public function buy(int $characterId, string $itemKey, array $config = []): array
    {
        $char = $this->character->getById($characterId);
        if (!$char) {
            return ['success' => false, 'error' => 'Character not found.'];
        }

        $itemDef = ItemDatabase::get($itemKey);
        if (!$itemDef) {
            return ['success' => false, 'error' => 'Item not found in shop.'];
        }

        if ($char['level'] < $itemDef['min_level']) {
            return ['success' => false, 'error' => "You need to be level {$itemDef['min_level']} to buy this."];
        }

        if ($char['gold'] < $itemDef['buy_price']) {
            return ['success' => false, 'error' => "Not enough gold. Need {$itemDef['buy_price']}, have {$char['gold']}."];
        }

        // Check inventory space
        $maxInventory = (int)($config['max_inventory_size'] ?? 20);
        $count = $this->inventory->getCount($characterId);
        if ($count >= $maxInventory) {
            return ['success' => false, 'error' => "Inventory full ({$count}/{$maxInventory}). Sell items first."];
        }

        // Deduct gold
        if (!$this->character->deductGold($characterId, $itemDef['buy_price'])) {
            return ['success' => false, 'error' => 'Not enough gold.'];
        }

        // Shop items are always common rarity
        $newItemId = $this->inventory->addItem($characterId, $itemKey, $itemDef, 'common');

        $item = $this->inventory->getItem($newItemId, $characterId);
        return ['success' => true, 'error' => null, 'item' => $item];
    }

    /**
     * Sell an item from inventory.
     *
     * @param int $characterId
     * @param int $itemId
     * @param int $sellPercent Percentage of buy price to receive
     * @return array ['success' => bool, 'error' => string|null, 'gold_received' => int]
     */
    public function sell(int $characterId, int $itemId, int $sellPercent = 50): array
    {
        $item = $this->inventory->getItem($itemId, $characterId);
        if (!$item) {
            return ['success' => false, 'error' => 'Item not found.', 'gold_received' => 0];
        }

        if ($item['is_equipped']) {
            return ['success' => false, 'error' => 'Unequip item before selling.', 'gold_received' => 0];
        }

        $sellPrice = max(1, (int)floor($item['buy_price'] * ($sellPercent / 100)));

        // Remove item
        $this->inventory->removeItem($itemId, $characterId);

        // Award gold
        $this->character->awardGold($characterId, $sellPrice);

        return [
            'success' => true,
            'error' => null,
            'gold_received' => $sellPrice,
            'item_name' => $item['item_name'],
        ];
    }
}
