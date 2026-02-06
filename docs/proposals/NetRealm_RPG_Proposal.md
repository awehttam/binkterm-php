# NetRealm: Inter-Node RPG Game Proposal

**Status**: Draft Proposal
**Generated**: 2026-02-05
**Note**: This is an AI-generated draft proposal and has not been reviewed for technical accuracy or feasibility.

## Overview

NetRealm is a turn-based RPG WebDoor game that uses echomail to enable cross-node gameplay across LovlyNet. Players fight monsters, level up their characters, collect loot, and compete with players on other BBS nodes through asynchronous PvP combat and global leaderboards.

**Architecture**: NetRealm is a **self-contained WebDoor** that requires **zero modifications to core BinktermPHP**. All game logic, API endpoints, and routing are handled within the WebDoor's own `index.php`. The echomail processor is registered via configuration, not code changes.

## Core Concept

- **Daily Turns**: Each player gets a set number of turns per day (e.g., 25 turns)
- **Local PvE**: Fight monsters, explore dungeons, gather resources
- **Cross-Node PvP**: Challenge players on other nodes via echomail
- **Global Leaderboards**: Rankings visible across all participating nodes
- **Persistent Character**: Character stats, inventory, and progress saved between sessions

## Unified Local and Network Gameplay

### Local Gameplay (No Echomail)
Direct database operations for single-player activities:
- **PvE Combat**: Fight monsters, gain XP/gold/items
- **Inventory Management**: Equip/unequip items
- **Shopping**: Buy/sell items
- **Character Management**: View stats, level up
- **Healing/Resting**: Recover HP

**Flow**: User action → API call → Database update → Instant result

### PvP (Unified via Echomail)
All PvP challenges (local or remote) use the same echomail-based flow:

**Local PvP** (both players on same node):
1. Alice challenges Bob (both local)
2. Game posts PvP message to NETREALM_SYNC echomail
3. Message saved to `echomail` table (from_address = local node)
4. Batch processor runs (1-5 min later)
5. Processes combat, updates both players' stats
6. Players see result on next page load

**Remote PvP** (players on different nodes):
1. Alice challenges Dave (on remote node)
2. Game posts PvP message to NETREALM_SYNC echomail
3. Message saved to `echomail` table AND transmitted via binkp
4. Both nodes' batch processors handle the message
5. Updates local game state on both nodes
6. Players see result on next page load

**Incoming Remote Challenge**:
1. Remote node posts PvP challenge
2. Arrives via binkp → saved to local `echomail` table
3. Batch processor runs → processes combat
4. Local player sees they were challenged and the result

### Benefits of Unified Approach
- **Single code path**: PvP logic is identical for local and remote
- **No special cases**: Processor doesn't care about message origin
- **Consistent UX**: All PvP has same ~1-5 min delay (acceptable for turn-based game)
- **Simple**: Echomail table is single source of truth

## Game Mechanics

### Character System

**Stats**:
- **Level**: 1-50, determines power and unlocks
- **HP (Hit Points)**: Current/Max health
- **Attack**: Damage dealt in combat
- **Defense**: Damage reduction
- **Experience**: Progress toward next level
- **Gold**: Currency for purchases

**Classes** (Optional Phase 2):
- Warrior (high HP, defense)
- Rogue (high attack, critical hits)
- Mage (magic attacks, buffs)

### Turn Economy

- Players receive 25 turns per day at reset (configurable)
- Turns regenerate at midnight (system timezone)
- Premium: Extra turns available for credits
- Actions that consume turns:
  - Combat encounter: 1 turn
  - Dungeon exploration: 2 turns
  - Rest/healing: 0 turns (but limited uses)
  - PvP challenge: 3 turns

### Combat System

**PvE Combat** (vs Monsters):
- Random encounters based on player level
- Simple combat formula: `damage = attack * (1 + random(0.8, 1.2)) - defense`
- Victory: Gain XP, gold, chance of loot
- Defeat: Lose gold, return to town

**PvP Combat** (vs Other Players):
- **Unified system**: Works the same for local and remote opponents
- **Asynchronous**: Attacker initiates, defender auto-defends (uses stored stats)
- **Echomail-based**: All PvP flows through NETREALM_SYNC echomail → batch processor
- **Processing delay**: 1-5 minutes (acceptable for turn-based game)
- **Results**: Winner gets XP, gold bounty, ranking points
- **Penalties**: Loser loses gold, ranking points (but not items/levels)
- **Visibility**: Results visible to both players and broadcast to network

### Monster Tiers

Level-appropriate monsters with scaling rewards:

| Level Range | Monster Examples | XP | Gold |
|-------------|-----------------|-----|------|
| 1-5 | Rat, Goblin, Slime | 10-25 | 5-15 |
| 6-10 | Wolf, Bandit, Skeleton | 30-60 | 20-40 |
| 11-20 | Orc, Dark Mage, Troll | 70-150 | 50-100 |
| 21-30 | Vampire, Demon, Drake | 200-400 | 150-300 |
| 31-40 | Dragon, Lich, Giant | 500-900 | 400-700 |
| 41-50 | Elder Dragon, Demon Lord | 1000-2000 | 1000-2000 |

### Items & Equipment

**Equipment Slots**:
- Weapon (+Attack)
- Armor (+Defense)
- Accessory (+HP or special bonus)

**Item Rarity**:
- Common (white): Basic stats
- Uncommon (green): +10% stats
- Rare (blue): +25% stats, 1 bonus
- Epic (purple): +50% stats, 2 bonuses
- Legendary (orange): +100% stats, 3 bonuses

**Shop System**:
- Town shop with basic items
- Prices scale with item power
- Sell items for 50% of purchase price

### Progression

**Leveling**:
- XP required: `level * 100` (e.g., Level 5→6 = 500 XP)
- Each level grants: +10 HP, +2 Attack, +1 Defense
- Heal to full HP on level up

**Daily Reset**:
- Turns regenerate
- Daily quest available
- Random event chance (market discount, bonus XP day, etc.)

## Inter-Node Features (Echomail Integration)

### Echomail Area

**NETREALM_SYNC_SYNC** - Game synchronization echo area (sysop-only) for:
- PvP combat results
- Leaderboard updates (daily/weekly)
- Rare item discoveries (legendary drops)
- World events
- Tournament announcements

**Auto-Creation**: On first run, NetRealm automatically creates the NETREALM_SYNC_SYNC echo area if it doesn't exist:
- **Domain**: lovlynet
- **Access**: Sysop only (read/write restricted)
- **Description**: "NetRealm RPG game synchronization area"

### Message Ordering and Reliability

**Important**: Echomail messages may arrive out of order due to network delays and store-and-forward routing. The game handles this by:

1. **UTC Timestamps**: All messages include a UTC timestamp (ISO 8601 format) for proper ordering across timezones
2. **Message IDs**: Each message has a unique ID for duplicate detection
3. **Idempotent processing**: Processing the same message twice is safe
4. **Last-write-wins**: For leaderboards, newer timestamps override older data

### Message Types

#### 1. PvP Challenge Result
```
Subject: [NetRealm] PvP: Alice(1:1/1) defeated Bob(1:2/3)
From: BBS Node 1:1/1

{
  "type": "pvp_result",
  "attacker": {
    "name": "Alice",
    "node": "1:1/1",
    "level": 15
  },
  "defender": {
    "name": "Bob",
    "node": "1:2/3",
    "level": 14
  },
  "result": "victory",
  "xp_gained": 150,
  "gold_won": 75,
  "timestamp": "2026-02-05T14:23:00Z"  // UTC timestamp (ISO 8601)
}
```

#### 2. Leaderboard Update
```
Subject: [NetRealm] Daily Leaderboard - 2026-02-05
From: Master Node (configurable)

{
  "type": "leaderboard",
  "period": "daily",
  "date": "2026-02-05",
  "top_players": [
    {"rank": 1, "name": "Alice", "node": "1:1/1", "level": 25, "score": 15000},
    {"rank": 2, "name": "Charlie", "node": "1:3/5", "level": 23, "score": 12500},
    {"rank": 3, "name": "Bob", "node": "1:2/3", "level": 22, "score": 11000}
  ]
}
```

#### 3. Legendary Item Drop
```
Subject: [NetRealm] LEGENDARY DROP: Dragon Slayer Sword found!
From: BBS Node 1:1/1

{
  "type": "legendary_drop",
  "player": "Alice",
  "node": "1:1/1",
  "item": {
    "name": "Dragon Slayer Sword",
    "rarity": "legendary",
    "stats": {
      "attack": 150
    }
  },
  "source": "Elder Dragon",
  "timestamp": "2026-02-05T16:45:00Z"
}
```

#### 4. World Event
```
Subject: [NetRealm] EVENT: Double XP Weekend!
From: Game System

{
  "type": "event",
  "name": "Double XP Weekend",
  "description": "All XP gains doubled for 48 hours!",
  "start": "2026-02-07T00:00:00Z",
  "end": "2026-02-09T00:00:00Z",
  "multiplier": 2.0
}
```

### Cross-Node Player Discovery

- Players can search for opponents on other nodes
- View basic stats: Name, Node, Level, Win/Loss record
- Challenge via echomail (async combat resolution)
- Combat results posted back to both nodes

### Global Leaderboards

**Rankings**:
1. **Overall**: Total experience/level
2. **PvP**: Win/loss ratio, ranking points
3. **Wealth**: Total gold + item value
4. **Monster Slayer**: Total monsters defeated

**Update Frequency**:
- Real-time on local node
- Synced via echomail daily at reset
- Nodes can query other nodes for current standings

## Technical Architecture

**IMPORTANT**: NetRealm is a self-contained WebDoor that requires **no changes to core BinktermPHP**. All game logic, API endpoints, and routing are handled within the WebDoor directory using its own `index.php` entry point.

### File Structure

```
public_html/webdoors/netrealm/
├── webdoor.json              # Manifest
├── index.php                 # Entry point - handles ALL routing and API endpoints
├── src/
│   ├── NetRealmGame.php      # Main game class
│   ├── Character.php         # Character management
│   ├── Combat.php            # Combat system
│   ├── Inventory.php         # Inventory/equipment
│   ├── Shop.php              # Shop system
│   ├── Network.php           # Cross-node player registry
│   ├── Leaderboard.php       # Leaderboard management
│   └── NetRealmProcessor.php # Echomail processor (registered via config)
├── game.html                 # Game interface (loaded by main BBS webdoor launcher)
├── css/
│   ├── netrealm.css          # Game styles
│   └── rpg-theme.css         # RPG-themed UI
├── js/
│   ├── game.js               # Main game logic
│   ├── combat.js             # Combat system
│   ├── inventory.js          # Item management
│   └── network.js            # API communication
├── assets/
│   ├── sprites/              # Character/monster sprites
│   └── sounds/               # Combat sounds (optional)
└── migrations/
    └── v1.0.0_netrealm_schema.sql  # Database schema
```

### Database Schema

```sql
-- Player characters
CREATE TABLE netrealm_characters (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(50) NOT NULL,
    level INTEGER DEFAULT 1,
    experience INTEGER DEFAULT 0,
    hp_current INTEGER DEFAULT 100,
    hp_max INTEGER DEFAULT 100,
    attack INTEGER DEFAULT 10,
    defense INTEGER DEFAULT 5,
    gold INTEGER DEFAULT 100,
    turns_remaining INTEGER DEFAULT 25,
    turns_last_reset TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_played TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id)
);

-- Equipment inventory
CREATE TABLE netrealm_inventory (
    id SERIAL PRIMARY KEY,
    character_id INTEGER NOT NULL REFERENCES netrealm_characters(id) ON DELETE CASCADE,
    item_id INTEGER NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    item_type VARCHAR(20) NOT NULL, -- weapon, armor, accessory
    rarity VARCHAR(20) NOT NULL, -- common, uncommon, rare, epic, legendary
    attack_bonus INTEGER DEFAULT 0,
    defense_bonus INTEGER DEFAULT 0,
    hp_bonus INTEGER DEFAULT 0,
    is_equipped BOOLEAN DEFAULT FALSE,
    acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Combat log (local fights)
CREATE TABLE netrealm_combat_log (
    id SERIAL PRIMARY KEY,
    character_id INTEGER NOT NULL REFERENCES netrealm_characters(id) ON DELETE CASCADE,
    opponent_type VARCHAR(20) NOT NULL, -- monster, player
    opponent_name VARCHAR(100) NOT NULL,
    opponent_node VARCHAR(50), -- NULL for monsters, node address for players
    result VARCHAR(10) NOT NULL, -- victory, defeat
    xp_gained INTEGER DEFAULT 0,
    gold_gained INTEGER DEFAULT 0,
    items_looted TEXT, -- JSON array
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Cross-node player registry (cached from echomail)
CREATE TABLE netrealm_network_players (
    id SERIAL PRIMARY KEY,
    player_name VARCHAR(50) NOT NULL,
    node_address VARCHAR(50) NOT NULL,
    level INTEGER NOT NULL,
    attack INTEGER NOT NULL,
    defense INTEGER NOT NULL,
    hp_max INTEGER NOT NULL,
    wins INTEGER DEFAULT 0,
    losses INTEGER DEFAULT 0,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(player_name, node_address)
);

-- Global leaderboard cache
CREATE TABLE netrealm_leaderboard (
    id SERIAL PRIMARY KEY,
    player_name VARCHAR(50) NOT NULL,
    node_address VARCHAR(50) NOT NULL,
    rank_type VARCHAR(20) NOT NULL, -- overall, pvp, wealth, monster_slayer
    rank_value INTEGER NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(player_name, node_address, rank_type)
);

-- Echomail message tracking (prevent duplicates)
CREATE TABLE netrealm_processed_messages (
    id SERIAL PRIMARY KEY,
    message_id VARCHAR(100) NOT NULL UNIQUE,
    message_type VARCHAR(50) NOT NULL,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### How Routing Works

**Request Flow**:
1. User loads WebDoor: Browser requests `/webdoors/netrealm/game.html`
2. JavaScript makes API call: `fetch('/webdoors/netrealm/api/character')`
3. Web server routes to: `/webdoors/netrealm/index.php`
4. `index.php` parses route, executes game logic, returns JSON
5. JavaScript updates UI with response

**Web Server Configuration** (Apache `.htaccess` in WebDoor directory):
```apache
# Redirect all requests to index.php except static assets
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/(.*)$ index.php [QSA,L]
```

This ensures all `/webdoors/netrealm/api/*` requests are handled by `index.php` without touching core routing.

### Entry Point: index.php

The WebDoor's `index.php` handles ALL routing and API endpoints internally:

```php
<?php
// public_html/webdoors/netrealm/index.php

require_once __DIR__ . '/../../../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\Auth;

// Get database connection
$db = Database::getInstance()->getPdo();

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userId = $auth->getUserId();

// Simple routing based on REQUEST_URI
$path = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Extract the path after /webdoors/netrealm/
preg_match('#/webdoors/netrealm/(.*)#', $path, $matches);
$route = $matches[1] ?? '';

// Remove query string
$route = strtok($route, '?');

// Load game classes
require_once __DIR__ . '/src/NetRealmGame.php';
require_once __DIR__ . '/src/Character.php';
require_once __DIR__ . '/src/Combat.php';
require_once __DIR__ . '/src/Inventory.php';
require_once __DIR__ . '/src/Shop.php';
require_once __DIR__ . '/src/Network.php';
require_once __DIR__ . '/src/Leaderboard.php';

$game = new NetRealmGame($db, $userId);

// API routing
header('Content-Type: application/json');

try {
    switch ($route) {
        // Character endpoints
        case 'api/character':
            if ($method === 'GET') {
                echo json_encode($game->getCharacter());
            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                echo json_encode($game->createCharacter($data['name']));
            }
            break;

        // Combat endpoints
        case 'api/combat':
            if ($method === 'GET') {
                echo json_encode($game->getAvailableMonsters());
            } elseif ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                echo json_encode($game->fightMonster($data['monster_id']));
            }
            break;

        // Inventory endpoints
        case 'api/inventory':
            echo json_encode($game->getInventory());
            break;

        case 'api/equip':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                echo json_encode($game->equipItem($data['item_id']));
            }
            break;

        // Shop endpoints
        case 'api/shop':
            echo json_encode($game->getShopItems());
            break;

        case 'api/shop/buy':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                echo json_encode($game->buyItem($data['item_id']));
            }
            break;

        case 'api/shop/sell':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                echo json_encode($game->sellItem($data['inventory_id']));
            }
            break;

        // Rest/heal
        case 'api/rest':
            echo json_encode($game->rest());
            break;

        // Network/PvP endpoints
        case 'api/network':
            echo json_encode($game->getNetworkPlayers());
            break;

        case 'api/pvp':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                echo json_encode($game->challengePlayer($data['player_id']));
            }
            break;

        // Leaderboard
        case 'api/leaderboard':
            $type = $_GET['type'] ?? 'overall';
            echo json_encode($game->getLeaderboard($type));
            break;

        // Credits integration
        case 'api/buy-turns':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                echo json_encode($game->buyTurns($data['amount']));
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
```

### WebDoor Manifest

**webdoor.json**:
```json
{
  "name": "NetRealm",
  "version": "1.0.0",
  "description": "Inter-node RPG adventure using echomail for cross-node gameplay",
  "author": "BinktermPHP",
  "category": "rpg",
  "display_name": "NetRealm RPG",
  "icon": "⚔️",
  "requires_auth": true,
  "api_version": "1.0",
  "entry_point": "game.html",
  "echomail_integration": {
    "echoarea": "NETREALM_SYNC",
    "message_types": ["pvp_result", "leaderboard", "legendary_drop", "event"],
    "processor_class": "NetRealmProcessor",
    "auto_subscribe": true
  },
  "config": {
    "daily_turns": 25,
    "turn_reset_hour": 0,
    "starting_gold": 100,
    "starting_level": 1,
    "pvp_enabled": true,
    "credits_per_extra_turn": 5,
    "max_extra_turns_per_day": 10
  }
}
```

**How It Works**:
1. User clicks "NetRealm RPG" in the WebDoors menu
2. BinktermPHP loads `game.html` (the `entry_point`) in an iframe/modal
3. JavaScript in `game.html` makes API calls to `/webdoors/netrealm/api/*`
4. All API calls are routed through `index.php` which handles game logic
5. Echomail messages are processed by `NetRealmProcessor` (registered via config)

**Zero Core Modifications**: The entire game is self-contained within the WebDoor directory.

### API Endpoints

All API endpoints are handled internally by the WebDoor's `index.php`. No core BinktermPHP routing changes required.

**Base URL**: `/webdoors/netrealm/`

```
GET  /webdoors/netrealm/api/character     - Get player's character
POST /webdoors/netrealm/api/character     - Create new character
GET  /webdoors/netrealm/api/combat        - Get available monsters
POST /webdoors/netrealm/api/combat        - Fight a monster
GET  /webdoors/netrealm/api/inventory     - Get inventory
POST /webdoors/netrealm/api/equip         - Equip/unequip item
GET  /webdoors/netrealm/api/shop          - Get shop items
POST /webdoors/netrealm/api/shop/buy      - Buy item
POST /webdoors/netrealm/api/shop/sell     - Sell item
GET  /webdoors/netrealm/api/rest          - Heal at inn (free, limited)
GET  /webdoors/netrealm/api/network       - List players on other nodes
POST /webdoors/netrealm/api/pvp           - Challenge another player
GET  /webdoors/netrealm/api/leaderboard   - Get global leaderboard
POST /webdoors/netrealm/api/buy-turns     - Purchase extra turns with credits
```

**Note**: JavaScript code in the game calls these endpoints relative to the WebDoor's base path.

### Echomail Processing

NetRealm includes its own echomail processor that integrates with BinktermPHP's echomail processor architecture (see `Echomail_Processor_Architecture.md`).

**Echo Area Auto-Creation**:

On first run (when no NETREALM_SYNC echo area exists), the game automatically creates it:

```php
// In NetRealmGame or initialization code
$stmt = $db->prepare("SELECT id FROM echoareas WHERE tag = ? AND domain = ?");
$stmt->execute(['NETREALM_SYNC', 'lovlynet']);

if (!$stmt->fetch()) {
    // Create the echo area
    $stmt = $db->prepare("
        INSERT INTO echoareas (tag, domain, description, sysop_only, created_at)
        VALUES (?, ?, ?, TRUE, NOW())
    ");
    $stmt->execute([
        'NETREALM_SYNC',
        'lovlynet',
        'NetRealm RPG game synchronization area (auto-created)'
    ]);
}
```

This ensures NETREALM_SYNC exists before any game messages are posted. Since it's sysop-only, regular users can't see it. The game posts/reads messages directly via SQL.

**Processing Flow**:
1. **Incoming echomail**: Packets arrive → messages saved to `echomail` table → queued for batch processing
2. **Local PvP**: Player initiates challenge → message posted to NETREALM_SYNC echomail → saved to `echomail` table → queued
3. **Batch daemon**: Runs every 1-5 minutes → processes queued messages → updates game state
4. **Unified**: All PvP (local + remote) flows through echomail table → processor

**Processor Registration** (`config/echomail_processors.json`):

Add this entry to register NetRealm's echomail processor:

```json
{
  "processors": [
    {
      "enabled": true,
      "class": "NetRealmProcessor",
      "autoload": "public_html/webdoors/netrealm/src/NetRealmProcessor.php",
      "comment": "NetRealm game - batch processor, runs on schedule",
      "config": {
        "validate_signatures": true,
        "auto_sync_leaderboard": true,
        "notify_players": true
      }
    }
  ]
}
```

**How It Works**:
1. ProcessorRegistry reads the config
2. Sees the `autoload` path and does `require_once` on it
3. Instantiates `NetRealmProcessor` with database and config
4. Processor runs in **batch mode** on a schedule (doesn't block packet processing)
5. Processes messages from echomail table every 1-5 minutes

**No core code changes** - just a config entry and the batch daemon runs the processor!

**Processor Class** (`src/NetRealmProcessor.php`):

The processor is a self-contained class within the WebDoor that:
- Extends `BinktermPHP\Echomail\EchomailProcessor`
- Subscribes to the NETREALM_SYNC echo area
- Processes incoming messages (PvP results, leaderboards, legendary drops, events)
- Updates local database tables
- Operates in **real-time mode** for instant notifications

**Class Definition**:
```php
<?php
// public_html/webdoors/netrealm/src/NetRealmProcessor.php

use BinktermPHP\Echomail\EchomailProcessor;

class NetRealmProcessor extends EchomailProcessor
{
    public function getEchoAreas()
    {
        return 'NETREALM_SYNC';
    }

    public function getProcessingMode(): string
    {
        return 'batch'; // Process on schedule, don't block packet processing
    }

    public function processMessage(array $message): bool
    {
        // Parse message JSON from message_text
        $data = json_decode($message['message_text'], true);

        // Route based on message type
        switch ($data['type'] ?? '') {
            case 'pvp_result':
                return $this->processPvPResult($data, $message);
            case 'leaderboard':
                return $this->processLeaderboard($data, $message);
            case 'legendary_drop':
                return $this->processLegendaryDrop($data, $message);
            case 'event':
                return $this->processEvent($data, $message);
        }

        return false;
    }
}
```

**Processing Schedule**:
1. Echomail daemon (`echomail_processor_daemon.php`) runs every 1-5 minutes
2. Checks `echomail_processing_queue` for pending NETREALM_SYNC messages
3. For each queued message:
   - Fetches message from `echomail` table
   - Calls `NetRealmProcessor::processMessage()`
   - Updates game tables (combat results, leaderboards, etc.)
   - Removes from queue
4. Players see updates on next page refresh or via notification

**No core changes required** - the processor is registered via configuration and processes messages on a schedule.

See the full processor implementation in the `Echomail_Processor_Architecture.md` proposal, specifically the "Example: NetRealm Processor (Real-time)" section.

## Game Balance

### Turn Economy
- 25 turns = ~15-20 minutes of gameplay
- Encourages daily engagement
- Extra turns via credits (5 credits per turn, max 10/day)

### Combat Difficulty
- Monsters scale with player level
- 80% win rate against level-appropriate monsters
- 20% chance of finding items on victory
- Rare drops (5% for rare+, 1% for epic+, 0.1% for legendary)

### PvP Balance
- Can only challenge players within ±5 levels
- Defender gets 20% defense bonus (home advantage)
- Win/loss ratios visible to discourage griefing
- Cooldown: Can only challenge same player once per day

### Progression Curve
- Level 1→20: Fast (1-2 days per level with active play)
- Level 20→35: Medium (3-5 days per level)
- Level 35→50: Slow (7-14 days per level)
- Endgame: PvP competition, legendary hunting, rankings

## Implementation Phases

**Key Principle**: All implementation happens within `/webdoors/netrealm/` - no core BinktermPHP modifications.

### Phase 1: Core Gameplay (MVP)
**Week 1-2**:
- Create WebDoor directory structure
- Database migration for NetRealm tables
- Build `index.php` routing system
- Implement game classes (Character, Combat, Inventory, Shop)
- Create basic game UI (`game.html` + CSS/JS)
- Character creation/management
- Basic PvE combat
- Turn system and daily reset
- Simple inventory and equipment
- Shop system

**Deliverables**:
- Playable single-node game
- Characters persist between sessions
- Daily turn regeneration works
- **No core changes made**

### Phase 2: Enhanced Gameplay
**Week 3-4**:
- Monster variety and balancing
- Item rarity system
- Combat animations/polish
- Rest/healing system
- Combat log and statistics
- Local leaderboard

**Deliverables**:
- Polished game experience
- Balanced progression
- Player engagement metrics
- **Still no core changes**

### Phase 3: Echomail Integration
**Week 5-6**:
- Create `NetRealmProcessor.php` (echomail processor)
- Register processor in `config/echomail_processors.json`
- Create NETREALM_SYNC echoarea on LovlyNet
- Implement echomail message generators (PvP results, leaderboards)
- Cross-node player registry
- PvP challenge system
- Global leaderboard sync
- Network player discovery

**Deliverables**:
- Cross-node PvP functional
- Leaderboards sync between nodes
- Battle results broadcast via echomail
- **Processor registered via config only - no core code changes**

### Phase 4: Advanced Features
**Week 7-8**:
- Daily quests
- World events
- Tournaments
- Credits integration (buy turns) - uses existing BinktermPHP credits API
- Admin interface within WebDoor
- Achievement system

**Deliverables**:
- Rich endgame content
- Monetization options
- Long-term engagement features
- **All features self-contained in WebDoor**

## Success Metrics

- **Engagement**: Daily active users, average turns used
- **Retention**: Return rate after 7 days, 30 days
- **Network Effect**: Number of cross-node PvP battles
- **Viral**: New player signups from echomail exposure
- **Monetization**: Credits spent on extra turns

## Future Enhancements

- **Guilds**: Cross-node player organizations
- **Raids**: Cooperative boss fights requiring multiple players
- **Crafting**: Combine items to create better equipment
- **Pet System**: Companions that assist in combat
- **Seasonal Events**: Limited-time content and rewards
- **Player Trading**: Item/gold exchange system
- **Tournaments**: Scheduled PvP competitions with prizes

## Technical Considerations

### Security
- Validate all echomail messages (check node signatures)
- Rate limiting on PvP challenges
- Server-side combat resolution (prevent cheating)
- Sanitize all user input

### Performance
- **Batch processing**: Processor runs every 1-5 minutes (configurable)
- **Non-blocking**: Packet processing not delayed by game logic
- Cache network player data (refresh daily)
- Index combat_log and inventory tables
- Lazy load leaderboards (don't fetch on every page load)
- Paginate combat history
- Consider faster batch interval (30-60 seconds) for more responsive PvP

### Scalability
- Echomail queuing for high-traffic nodes
- Database connection pooling
- Consider Redis for session/turn tracking
- Horizontal scaling for API endpoints

## Conclusion

NetRealm combines nostalgic BBS door game mechanics with modern web technology and FidoNet's unique distributed architecture. By using echomail for inter-node communication, it demonstrates the collaborative potential of LovlyNet while providing engaging daily gameplay.

The phased approach allows for rapid MVP development while leaving room for rich feature expansion based on player feedback and network adoption.

---

**Next Steps**:
1. Review and approve proposal
2. Create NETREALM_SYNC echoarea on LovlyNet
3. Create WebDoor directory: `public_html/webdoors/netrealm/`
4. Run database migration for NetRealm tables
5. Build `index.php` routing and API endpoint handler
6. Implement game classes and UI (Phase 1)
7. Register `NetRealmProcessor` in `config/echomail_processors.json`
8. Beta test with small group of nodes
9. Launch network-wide

**Installation for Sysops**:
1. Copy `/webdoors/netrealm/` directory to their BBS
2. Run NetRealm database migration
3. Add processor config entry to `config/echomail_processors.json`
4. Ensure batch daemon is running (`scripts/echomail_processor_daemon.php`)
   - Runs every 1-5 minutes to process game moves
   - Can configure interval in daemon script
5. Subscribe to NETREALM_SYNC echoarea
6. Game appears automatically in WebDoors menu

**Batch Processing Configuration**:
- Default: Process queue every 60 seconds (1 minute)
- For more responsive PvP: Set to 30 seconds
- For lower resource usage: Set to 300 seconds (5 minutes)
- Configure in `scripts/echomail_processor_daemon.php`: `sleep(60);`
