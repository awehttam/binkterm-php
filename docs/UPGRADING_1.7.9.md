# UPGRADING_1.7.9

This upgrade note covers changes introduced in version 1.7.9.

## Summary of Changes Since 1.7.8

- **NetRealm RPG**: New turn-based RPG WebDoor game. Players fight monsters, level up to 50, collect loot with rarity tiers (common through legendary), equip gear across 3 slots (weapon, armor, accessory), buy and sell items at a shop, and battle other local players in PvP combat.
- NetRealm uses a daily turn system (configurable, default 25/day) with optional credit-based extra turn purchases.
- PvP results are posted to a `NETREALM_SYNC` local echo area for future cross-node networking support.
- Four leaderboard types: overall, PvP, wealth, and monster slayer.

## Database Migration

This release includes migration `v1.9.3` which creates the following tables:

- `netrealm_characters` - Player characters (one per user)
- `netrealm_inventory` - Item storage and equipment
- `netrealm_combat_log` - PvE and PvP fight history
- `netrealm_network_players` - PvP player registry
- `netrealm_pvp_cooldowns` - PvP challenge cooldowns
- `netrealm_processed_messages` - Echomail deduplication

The migration also creates a `NETREALM_SYNC` echo area (local-only, sysop-only) used for PvP message sync.

## Upgrade Steps

1. Pull the latest code: `git pull`
2. Install dependencies: `composer install`
3. Run setup: `php scripts/setup.php`

**Important:** Step 2 is required. This release adds a new composer dependency. Skipping it will cause a fatal error when running setup.php.

## Enabling NetRealm

After upgrading, NetRealm must be enabled in the WebDoors admin configuration:

1. Go to **Admin > WebDoors**
2. Enable the NetRealm RPG game
3. Adjust game settings as desired (daily turns, PvP toggle, credit costs, etc.)

The game will then appear in the `/games` page for all users.

## Configuration Options

The following settings are configurable via the WebDoors admin panel or `config/webdoors.json`:

| Setting | Default | Description |
|---------|---------|-------------|
| `daily_turns` | 25 | Turns granted per day |
| `starting_gold` | 100 | Gold given to new characters |
| `pvp_enabled` | true | Enable/disable PvP combat |
| `credits_per_extra_turn` | 5 | Credit cost per extra turn |
| `max_extra_turns_per_day` | 10 | Maximum purchasable turns/day |
| `max_inventory_size` | 20 | Maximum items per character |
| `max_rest_uses_per_day` | 3 | Rest actions allowed per day |
| `rest_heal_percent` | 50 | HP percentage restored per rest |
| `pvp_turn_cost` | 3 | Turns consumed per PvP fight |
| `pvp_level_range` | 5 | Max level difference for PvP |
| `sell_price_percent` | 50 | Percentage of buy price when selling |
