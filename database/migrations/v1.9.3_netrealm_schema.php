<?php

/**
 * NetRealm RPG database schema migration.
 *
 * Uses PHP migration format because the DO $$ block for echo area creation
 * contains internal semicolons that the SQL statement splitter cannot handle.
 *
 * @param PDO $db Database connection provided by upgrade.php
 */

// Characters: one per user
$db->exec("
    CREATE TABLE IF NOT EXISTS netrealm_characters (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name VARCHAR(20) NOT NULL,
        level INTEGER NOT NULL DEFAULT 1,
        xp INTEGER NOT NULL DEFAULT 0,
        hp INTEGER NOT NULL DEFAULT 100,
        max_hp INTEGER NOT NULL DEFAULT 100,
        attack INTEGER NOT NULL DEFAULT 10,
        defense INTEGER NOT NULL DEFAULT 5,
        gold INTEGER NOT NULL DEFAULT 100,
        turns INTEGER NOT NULL DEFAULT 25,
        turns_last_reset DATE NOT NULL DEFAULT CURRENT_DATE,
        extra_turns_today INTEGER NOT NULL DEFAULT 0,
        rest_uses_today INTEGER NOT NULL DEFAULT 0,
        monsters_killed INTEGER NOT NULL DEFAULT 0,
        pvp_wins INTEGER NOT NULL DEFAULT 0,
        pvp_losses INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT netrealm_characters_user_unique UNIQUE (user_id),
        CONSTRAINT netrealm_characters_name_unique UNIQUE (name)
    )
");

$db->exec("CREATE INDEX IF NOT EXISTS idx_netrealm_characters_level ON netrealm_characters(level)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_netrealm_characters_xp ON netrealm_characters(xp DESC)");

// Inventory: items owned by characters
$db->exec("
    CREATE TABLE IF NOT EXISTS netrealm_inventory (
        id SERIAL PRIMARY KEY,
        character_id INTEGER NOT NULL REFERENCES netrealm_characters(id) ON DELETE CASCADE,
        item_key VARCHAR(50) NOT NULL,
        item_name VARCHAR(100) NOT NULL,
        item_type VARCHAR(20) NOT NULL,
        rarity VARCHAR(20) NOT NULL DEFAULT 'common',
        attack_bonus INTEGER NOT NULL DEFAULT 0,
        defense_bonus INTEGER NOT NULL DEFAULT 0,
        hp_bonus INTEGER NOT NULL DEFAULT 0,
        buy_price INTEGER NOT NULL DEFAULT 0,
        is_equipped BOOLEAN NOT NULL DEFAULT FALSE,
        acquired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("CREATE INDEX IF NOT EXISTS idx_netrealm_inventory_character ON netrealm_inventory(character_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_netrealm_inventory_equipped ON netrealm_inventory(character_id, is_equipped) WHERE is_equipped = TRUE");

// Combat log: PvE and PvP fight history
$db->exec("
    CREATE TABLE IF NOT EXISTS netrealm_combat_log (
        id SERIAL PRIMARY KEY,
        character_id INTEGER NOT NULL REFERENCES netrealm_characters(id) ON DELETE CASCADE,
        combat_type VARCHAR(10) NOT NULL DEFAULT 'pve',
        opponent_name VARCHAR(100) NOT NULL,
        result VARCHAR(10) NOT NULL,
        xp_gained INTEGER NOT NULL DEFAULT 0,
        gold_gained INTEGER NOT NULL DEFAULT 0,
        loot_item VARCHAR(100),
        details JSONB,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("CREATE INDEX IF NOT EXISTS idx_netrealm_combat_log_character ON netrealm_combat_log(character_id, created_at DESC)");

// Network players: PvP player registry (local users for Phase 1)
$db->exec("
    CREATE TABLE IF NOT EXISTS netrealm_network_players (
        id SERIAL PRIMARY KEY,
        player_name VARCHAR(100) NOT NULL,
        node_address VARCHAR(50) NOT NULL,
        character_id INTEGER REFERENCES netrealm_characters(id) ON DELETE SET NULL,
        level INTEGER NOT NULL DEFAULT 1,
        attack INTEGER NOT NULL DEFAULT 10,
        defense INTEGER NOT NULL DEFAULT 5,
        hp INTEGER NOT NULL DEFAULT 100,
        max_hp INTEGER NOT NULL DEFAULT 100,
        pvp_wins INTEGER NOT NULL DEFAULT 0,
        pvp_losses INTEGER NOT NULL DEFAULT 0,
        last_synced TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT netrealm_network_player_unique UNIQUE (player_name, node_address)
    )
");

// PvP cooldowns: one challenge per opponent per day
$db->exec("
    CREATE TABLE IF NOT EXISTS netrealm_pvp_cooldowns (
        id SERIAL PRIMARY KEY,
        attacker_id INTEGER NOT NULL REFERENCES netrealm_characters(id) ON DELETE CASCADE,
        defender_id INTEGER NOT NULL REFERENCES netrealm_network_players(id) ON DELETE CASCADE,
        cooldown_date DATE NOT NULL DEFAULT CURRENT_DATE,
        CONSTRAINT netrealm_pvp_cooldown_unique UNIQUE (attacker_id, defender_id, cooldown_date)
    )
");

// Processed echomail messages for deduplication
$db->exec("
    CREATE TABLE IF NOT EXISTS netrealm_processed_messages (
        id SERIAL PRIMARY KEY,
        message_id VARCHAR(255) NOT NULL UNIQUE,
        processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// Create NETREALM_SYNC echo area (local only, sysop only)
$db->exec("
    INSERT INTO echoareas (tag, description, domain, is_local, is_sysop_only, is_active)
    VALUES ('NETREALM_SYNC', 'NetRealm RPG PvP sync area (local)', 'lovlynet', TRUE, TRUE, TRUE)
    ON CONFLICT (tag, domain) DO NOTHING
");

return true;
