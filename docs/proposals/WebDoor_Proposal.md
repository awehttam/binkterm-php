# WebDoor Specification Proposal

Draft 1

## Overview

BinktermPHP **WebDoor** is a draft specification for embedding HTML5/JavaScript games ("web doors") into BBS software. It defines a secure API protocol between a host BBS and embedded games, enabling third-party game developers and BBS software authors to create interoperable experiences.

This specification document is not necessarily what is implemented in BinktermPHP.  For the BinktermPHP WebDoors documentation see [WebDoors.md](../WebDoors.md).  Some aspects of this specification may be present, but is not guaranteed.  This specification is present only for idea referencing.

The name "WebDoor" references the traditional BBS "door game" concept while indicating its web-based nature.

## Goals

1. **Secure Authentication** - Games must securely know which user is playing without exposing credentials
2. **Data Persistence** - Games can save/load user-specific game state via the host BBS
3. **Interoperability** - Any compliant BBS can run any compliant game
4. **Simplicity** - Easy for game developers to implement
5. **Multiplayer Support** - Real-time multiplayer capabilities built into the spec

## Hosting Models

WebDoor supports two hosting models:

### Local Games (Same Origin)
- Game files installed in a directory on the BBS server (e.g., `/webdoor/games/space-trader/`)
- Uses direct AJAX calls to BBS API endpoints
- Simpler authentication (session cookie works)
- Full API access

### Third-Party Games (Cross Origin)
- Game hosted on external server
- Uses CORS-enabled API endpoints with token authentication
- More restricted for security

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      Host BBS Website                           │
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                    Game Container                         │  │
│  │  ┌────────────────────────────────────────────────────┐  │  │
│  │  │              WebDoor Game (HTML5/JS)               │  │  │
│  │  │                                                    │  │  │
│  │  │  Local: Direct AJAX to /api/webdoor/*              │  │  │
│  │  │  Remote: Token-authenticated API with CORS         │  │  │
│  │  └────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                 │
│  WebDoor API: /api/webdoor/*                                    │
│  WebSocket: /ws/webdoor (for multiplayer)                       │
└─────────────────────────────────────────────────────────────────┘
```

---

## Specification Components

### 1. Game Manifest (`webdoor.json`)

Each game provides a manifest file describing its capabilities:

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "space-trader",
    "name": "Space Trader",
    "version": "1.2.0",
    "author": "Game Developer",
    "description": "Trade goods across the galaxy",
    "entry_point": "index.html",
    "icon": "icon.png",
    "screenshots": ["screen1.png", "screen2.png"]
  },
  "requirements": {
    "min_host_version": "1.0",
    "features": ["storage", "leaderboard"],
    "permissions": ["user_display_name"]
  },
  "storage": {
    "max_size_kb": 100,
    "save_slots": 3
  },
  "config": {
    "start_bet": 10,
    "max_bet": 500
  },
  "multiplayer": {
    "enabled": true,
    "min_players": 2,
    "max_players": 8
  }
}
```

**Config Overrides**

Each WebDoor may include a top-level `config` object. When a door is enabled (activated), the host merges any keys from `config` into that door's entry in `config/webdoors.json` if they are not already present. This provides default settings for per-door configuration while allowing sysops to override values later.

**Auto-Discovery & Configuration Flow (BinktermPHP Implementation):**

1. **Discovery**: `WebDoorManifest::listManifests()` scans `public_html/webdoors/` for directories containing `webdoor.json`
2. **Admin UI**: Admin interface queries `/admin/api/webdoors-available` which returns all discovered doors
3. **Display**: UI shows both configured doors and newly discovered doors (marked "not in config")
4. **Activation**: When sysop enables a door via toggle switch:
   - Door's manifest `config` section is read
   - Config keys are merged into the door's entry in `webdoors.json`
   - Only keys not already present are added (preserves manual overrides)
5. **Save**: AdminDaemonServer applies manifest config during save operation
6. **Result**: Door is enabled with sensible defaults, sysop can adjust values later

Example manifest with config:
```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "blackjack",
    "name": "Blackjack"
  },
  "config": {
    "enabled": true,
    "start_bet": 10,
    "max_bet": 500,
    "play_cost": 0
  }
}
```

Resulting `config/webdoors.json` entry after activation:
```json
{
  "blackjack": {
    "enabled": true,
    "start_bet": 10,
    "max_bet": 500,
    "play_cost": 0
  }
}
```

This approach enables true "drop-in" game installation - simply copy a game directory with its manifest, and it appears in the admin UI ready to enable with appropriate defaults.

---

### 2. Authentication Flow

#### Local Games (Same Origin)

1. User clicks "Play Game" on BBS
2. BBS generates a game session and redirects/loads game page
3. Game makes AJAX call to `GET /api/webdoor/session`
4. BBS validates existing session cookie, returns user context
5. Game is authenticated via standard session

#### Third-Party Games (Cross Origin)

1. User clicks "Play Game" on BBS
2. BBS generates a signed, time-limited **game token** containing:
   - User ID hash (not raw ID)
   - Game session ID (unique per game session)
   - Game ID
   - Timestamp + expiration (5 minutes)
   - Allowed permissions
   - HMAC signature using BBS secret key
3. BBS loads game with token:
   ```
   https://games.example.com/space-trader/?webdoor_token=eyJhbGc...&webdoor_api=https://bbs.example.com/api/webdoor
   ```
4. Game calls `POST /api/webdoor/auth` with token to validate and start session
5. API returns session token for subsequent requests (longer-lived, 1 hour)
6. All subsequent API calls include `Authorization: Bearer <session_token>`

---

### 3. REST API

All API endpoints are prefixed with `/api/webdoor/`. For cross-origin games, include `Authorization: Bearer <token>` header.

#### 3.1 Session Management

**Start/Get Session:**
```
GET /api/webdoor/session
POST /api/webdoor/auth  (for cross-origin token exchange)
```

Response:
```json
{
  "session_id": "sess-abc123",
  "user": {
    "display_name": "SpaceCowboy",
    "user_id_hash": "a1b2c3..."
  },
  "host": {
    "name": "Awesome BBS",
    "version": "1.0.0",
    "features": ["storage", "leaderboard", "multiplayer"]
  },
  "game": {
    "id": "space-trader",
    "name": "Space Trader"
  },
  "token": "eyJ...",
  "expires_at": "2025-01-25T13:00:00Z"
}
```

**End Session:**
```
POST /api/webdoor/session/end
Body: { "playtime_seconds": 3600 }
```

#### 3.2 Storage API

**List Saves:**
```
GET /api/webdoor/storage
```
Response:
```json
{
  "slots": [
    { "slot": 0, "metadata": { "save_name": "Auto-save" }, "saved_at": "2025-01-25T12:00:00Z" },
    { "slot": 1, "metadata": { "save_name": "My Save" }, "saved_at": "2025-01-24T10:00:00Z" }
  ],
  "max_slots": 5,
  "used_bytes": 15000,
  "max_bytes": 102400
}
```

**Load Save:**
```
GET /api/webdoor/storage/{slot}
```
Response:
```json
{
  "slot": 1,
  "data": { "credits": 5000, "ship": "falcon", "cargo": ["ore", "food"] },
  "metadata": { "save_name": "My Save", "playtime_seconds": 3600 },
  "saved_at": "2025-01-25T12:00:00Z"
}
```

**Save Game:**
```
PUT /api/webdoor/storage/{slot}
Body: {
  "data": { "credits": 5000, "ship": "falcon", "cargo": ["ore", "food"] },
  "metadata": { "save_name": "My Save", "playtime_seconds": 3600 }
}
```

**Delete Save:**
```
DELETE /api/webdoor/storage/{slot}
```

#### 3.3 Leaderboard API

**Get Leaderboard:**
```
GET /api/webdoor/leaderboard/{board}?limit=10&scope=all
```
Scopes: `all`, `today`, `week`, `month`

Response:
```json
{
  "board": "high_scores",
  "entries": [
    { "rank": 1, "display_name": "Player1", "score": 50000, "metadata": {...}, "date": "2025-01-25" },
    { "rank": 2, "display_name": "Player2", "score": 45000, "metadata": {...}, "date": "2025-01-24" }
  ],
  "user_entry": {
    "rank": 15,
    "score": 15000,
    "date": "2025-01-20"
  },
  "total_entries": 150
}
```

**Submit Score:**
```
POST /api/webdoor/leaderboard/{board}
Body: {
  "score": 15000,
  "metadata": { "level_reached": 10, "enemies_defeated": 50 }
}
```
Response:
```json
{
  "accepted": true,
  "rank": 15,
  "is_personal_best": true,
  "previous_best": 12000
}
```

---

### 4. Multiplayer API

WebDoor provides real-time multiplayer through WebSocket connections.

#### 4.1 Connection

**WebSocket Endpoint:**
```
ws://bbs.example.com/api/webdoor/multiplayer
```

**Initial Handshake:**
```json
// Client sends after connection
{
  "type": "auth",
  "session_id": "sess-abc123",
  "game_id": "space-trader"
}

// Server responds
{
  "type": "auth_ok",
  "player_id": "player-xyz",
  "display_name": "SpaceCowboy"
}
```

#### 4.2 Lobby System

**List Lobbies:**
```
GET /api/webdoor/multiplayer/lobbies
```
Response:
```json
{
  "lobbies": [
    {
      "id": "lobby-123",
      "name": "Casual Game",
      "host": "SpaceCowboy",
      "players": 2,
      "max_players": 4,
      "status": "waiting",
      "settings": { "difficulty": "normal" }
    }
  ]
}
```

**Create Lobby:**
```
POST /api/webdoor/multiplayer/lobbies
Body: {
  "name": "My Game Room",
  "max_players": 4,
  "settings": { "difficulty": "hard", "map": "asteroid_field" },
  "password": null
}
```

**Join Lobby (via WebSocket):**
```json
{ "type": "lobby.join", "lobby_id": "lobby-123", "password": null }

// Response
{ "type": "lobby.joined", "lobby": { ... }, "players": [ ... ] }

// Broadcast to other players
{ "type": "lobby.player_joined", "player": { "id": "...", "display_name": "NewPlayer" } }
```

**Leave Lobby:**
```json
{ "type": "lobby.leave" }
```

**Start Game (host only):**
```json
{ "type": "lobby.start" }

// Broadcast to all players
{ "type": "game.starting", "countdown": 3 }
{ "type": "game.started", "initial_state": { ... } }
```

#### 4.3 In-Game Messaging

**Game State Update (client → server):**
```json
{
  "type": "game.state",
  "data": {
    "position": { "x": 100, "y": 200 },
    "velocity": { "x": 5, "y": 0 },
    "action": "firing"
  },
  "seq": 1234
}
```

**Broadcast to Players (server → clients):**
```json
{
  "type": "game.state",
  "player_id": "player-xyz",
  "data": { ... },
  "seq": 1234,
  "server_time": 1706234567890
}
```

**Game Event (significant events, reliably delivered):**
```json
// Client → Server
{
  "type": "game.event",
  "event": "player_scored",
  "data": { "points": 100, "target": "player-abc" }
}

// Server validates and broadcasts
{
  "type": "game.event",
  "player_id": "player-xyz",
  "event": "player_scored",
  "data": { "points": 100, "target": "player-abc" },
  "server_time": 1706234567890
}
```

**Chat Message:**
```json
{ "type": "chat", "message": "Good game everyone!" }

// Broadcast
{ "type": "chat", "player_id": "player-xyz", "display_name": "SpaceCowboy", "message": "Good game everyone!" }
```

#### 4.4 Game End

```json
{
  "type": "game.ended",
  "reason": "completed",
  "results": {
    "winner": "player-xyz",
    "scores": [
      { "player_id": "player-xyz", "display_name": "SpaceCowboy", "score": 5000 },
      { "player_id": "player-abc", "display_name": "StarPilot", "score": 3500 }
    ]
  }
}
```

#### 4.5 Matchmaking (Optional)

For games that want automatic matchmaking instead of lobbies:

```json
// Client requests match
{ "type": "matchmaking.join", "mode": "ranked", "settings": { "skill_range": 100 } }

// Server updates
{ "type": "matchmaking.status", "status": "searching", "players_in_queue": 15 }

// Match found
{ "type": "matchmaking.found", "lobby_id": "lobby-456", "players": [ ... ] }
```

---

### 5. Security Considerations

#### 5.1 Token Security
- Tokens are HMAC-signed with a server-side secret
- Launch tokens expire quickly (5 minutes)
- Session tokens are longer-lived (1 hour) but can be revoked
- Tokens are single-use for initial auth (tracked by session ID)

#### 5.2 Origin Verification
- For cross-origin games, host verifies Origin header on API requests
- CORS configured to only allow registered game origins
- Local games use same-origin policy naturally

#### 5.3 Data Validation
- Host validates all data sizes against manifest limits
- Host sanitizes all stored data (JSON schema validation)
- Rate limiting on all API endpoints
- Scores can include proof-of-work or game state hashes for anti-cheat

#### 5.4 Iframe Sandboxing (for third-party games)
```html
<iframe
  src="https://games.example.com/game/?webdoor_token=..."
  sandbox="allow-scripts allow-same-origin allow-forms"
  referrerpolicy="no-referrer"
  allow="gamepad">
</iframe>
```

#### 5.5 WebSocket Security
- WebSocket connections require valid session token
- All messages validated against expected schema
- Rate limiting on messages per second
- Server authoritative for game state in competitive modes

---

### 6. Host Implementation Requirements

#### 6.1 Database Schema (Reference)

```sql
-- Registered games
CREATE TABLE webdoor_games (
    id SERIAL PRIMARY KEY,
    game_id VARCHAR(100) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    game_path VARCHAR(500),
    game_url VARCHAR(500),
    is_local BOOLEAN DEFAULT true,
    manifest JSONB NOT NULL,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- User game sessions
CREATE TABLE webdoor_sessions (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(100) UNIQUE NOT NULL,
    user_id INTEGER REFERENCES users(id),
    game_id INTEGER REFERENCES webdoor_games(id),
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP,
    playtime_seconds INTEGER DEFAULT 0
);

-- Game saves (per user per game)
CREATE TABLE webdoor_saves (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    game_id INTEGER REFERENCES webdoor_games(id),
    slot INTEGER NOT NULL,
    data JSONB NOT NULL,
    metadata JSONB,
    size_bytes INTEGER DEFAULT 0,
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, game_id, slot)
);

-- Leaderboards
CREATE TABLE webdoor_leaderboard (
    id SERIAL PRIMARY KEY,
    game_id INTEGER REFERENCES webdoor_games(id),
    board_name VARCHAR(100) NOT NULL,
    user_id INTEGER REFERENCES users(id),
    score BIGINT NOT NULL,
    metadata JSONB,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_leaderboard_score ON webdoor_leaderboard(game_id, board_name, score DESC);

-- Multiplayer lobbies
CREATE TABLE webdoor_lobbies (
    id SERIAL PRIMARY KEY,
    lobby_id VARCHAR(100) UNIQUE NOT NULL,
    game_id INTEGER REFERENCES webdoor_games(id),
    host_user_id INTEGER REFERENCES users(id),
    name VARCHAR(255) NOT NULL,
    max_players INTEGER DEFAULT 4,
    password_hash VARCHAR(255),
    settings JSONB,
    status VARCHAR(20) DEFAULT 'waiting',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP,
    ended_at TIMESTAMP
);

-- Lobby players
CREATE TABLE webdoor_lobby_players (
    id SERIAL PRIMARY KEY,
    lobby_id INTEGER REFERENCES webdoor_lobbies(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id),
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_ready BOOLEAN DEFAULT false,
    UNIQUE(lobby_id, user_id)
);
```

#### 6.2 Required Host API Endpoints

**Game API (used by games):**
- `GET /api/webdoor/session` - Get current session
- `POST /api/webdoor/auth` - Exchange launch token for session token
- `POST /api/webdoor/session/end` - End session
- `GET/PUT/DELETE /api/webdoor/storage/{slot}` - Save management
- `GET/POST /api/webdoor/leaderboard/{board}` - Leaderboards
- `GET/POST /api/webdoor/multiplayer/lobbies` - Lobby management
- `WS /api/webdoor/multiplayer` - Real-time multiplayer

**Admin API:**
- `GET /api/webdoor/admin/games` - List all games
- `POST /api/webdoor/admin/games` - Register a game
- `PUT /api/webdoor/admin/games/{id}` - Update game
- `DELETE /api/webdoor/admin/games/{id}` - Remove game
- `GET /api/webdoor/admin/games/{id}/stats` - Game statistics

---

### 7. Game Developer SDK

```javascript
// webdoor-sdk.js
class WebDoor {
  constructor(options = {}) {
    this.apiBase = options.apiBase || '/api/webdoor';
    this.session = null;
    this.ws = null;
  }

  // Initialize and authenticate
  async init() {
    const response = await fetch(`${this.apiBase}/session`, { credentials: 'include' });
    this.session = await response.json();
    return this.session;
  }

  // Storage
  async listSaves() { /* GET /storage */ }
  async load(slot) { /* GET /storage/{slot} */ }
  async save(slot, data, metadata) { /* PUT /storage/{slot} */ }
  async deleteSave(slot) { /* DELETE /storage/{slot} */ }

  // Leaderboards
  async getLeaderboard(board, options = {}) { /* GET /leaderboard/{board} */ }
  async submitScore(board, score, metadata = {}) { /* POST /leaderboard/{board} */ }

  // Multiplayer
  async connectMultiplayer() {
    const wsUrl = this.apiBase.replace(/^http/, 'ws') + '/multiplayer';
    this.ws = new WebSocket(wsUrl);
    // ... setup handlers
  }
  async listLobbies() { /* GET /multiplayer/lobbies */ }
  async createLobby(name, settings) { /* POST /multiplayer/lobbies */ }
  joinLobby(lobbyId, password) { /* WS: lobby.join */ }
  leaveLobby() { /* WS: lobby.leave */ }
  startGame() { /* WS: lobby.start */ }
  sendState(data) { /* WS: game.state */ }
  sendEvent(event, data) { /* WS: game.event */ }
  sendChat(message) { /* WS: chat */ }

  // Event handling
  on(event, callback) { /* Register event handler */ }
  off(event, callback) { /* Remove event handler */ }
}

export default WebDoor;
```

**Example Single-Player Game:**
```javascript
import WebDoor from 'webdoor-sdk';

const wd = new WebDoor();

async function startGame() {
  const { user } = await wd.init();
  console.log(`Welcome, ${user.display_name}!`);

  // Load auto-save if exists
  const { slots } = await wd.listSaves();
  if (slots.find(s => s.slot === 0)) {
    const { data } = await wd.load(0);
    restoreGameState(data);
  }

  // Auto-save periodically
  setInterval(() => wd.save(0, getGameState()), 60000);
}

async function gameOver(score) {
  const result = await wd.submitScore('high_scores', score, { level: currentLevel });
  console.log(`You ranked #${result.rank}!`);
}
```

**Example Multiplayer Game:**
```javascript
import WebDoor from 'webdoor-sdk';

const wd = new WebDoor();

async function startMultiplayer() {
  await wd.init();
  await wd.connectMultiplayer();

  // List available games
  const { lobbies } = await wd.listLobbies();
  showLobbyList(lobbies);

  // Event handlers
  wd.on('lobby.player_joined', (player) => addPlayerToList(player));
  wd.on('lobby.player_left', (player) => removePlayerFromList(player));
  wd.on('game.started', (data) => initGame(data.initial_state));
  wd.on('game.state', (data) => updateRemotePlayer(data.player_id, data.data));
  wd.on('game.ended', (data) => showResults(data.results));
}

function createGame() {
  wd.createLobby('My Game', { map: 'arena', maxPlayers: 4 });
}

function joinGame(lobbyId) {
  wd.joinLobby(lobbyId);
}

// Game loop sends state updates
function gameLoop() {
  wd.sendState({ position: player.pos, velocity: player.vel });
  requestAnimationFrame(gameLoop);
}
```

---

## Current Implementation Status (BinktermPHP)

### ✅ Implemented Features

**Manifest Auto-Discovery System:**
- `WebDoorManifest` class scans `public_html/webdoors/` for games
- Automatically parses `webdoor.json` manifests
- Extracts game metadata (id, name, version, description, icons)
- Reads configuration defaults from manifest `config` section

**Configuration Management:**
- Admin UI displays all discovered WebDoors
- Per-door enable/disable toggles
- Configuration stored in `config/webdoors.json`
- Manifest config defaults auto-merge when door is enabled
- Admin endpoint: `GET /admin/api/webdoors-available` lists discovered doors

**Local Game Support:**
- Games installed in `public_html/webdoors/{game-id}/` directory structure
- Entry point served from manifest `entry_point` field
- Session cookie authentication for local games
- Game library page at `/games` shows enabled doors
- Individual game launch at `/webdoors/{game-id}/`

**Storage API (Implemented):**
- `GET /api/webdoor/storage` - List save slots
- `GET /api/webdoor/storage/{slot}` - Load save data
- `PUT /api/webdoor/storage/{slot}` - Save game state
- `DELETE /api/webdoor/storage/{slot}` - Delete save
- Per-user, per-game save slots with size limits from manifest

**Leaderboard API (Implemented):**
- `GET /api/webdoor/leaderboard/{board}` - Get leaderboard with scope filtering (all/today/week/month)
- `POST /api/webdoor/leaderboard/{board}` - Submit score
- Per-game, per-board leaderboards with metadata support
- Personal best tracking and ranking

**Credits Integration:**
- WebDoors can read user credit balance
- Games can charge/reward credits through API (if enabled)
- Configured per-door (e.g., `play_cost: 10` in webdoors.json)
- Admin configures credit costs/rewards per WebDoor

**Session Management:**
- `GET /api/webdoor/session` - Get authenticated user session
- Session includes user info, game info, host capabilities
- Tracks playtime and session statistics

### 🚧 Partially Implemented

**Database Schema:**
- Core tables exist for saves and leaderboards
- Session tracking implemented
- Multiplayer tables not yet created

### ❌ Not Yet Implemented

**Multiplayer System:**
- WebSocket server integration
- Lobby system (create/join/leave)
- Real-time state broadcasting
- Multiplayer WebSocket endpoint

**Cross-Origin Support:**
- Token-based authentication for external games
- CORS configuration for third-party origins
- POST /api/webdoor/auth endpoint for token exchange

**Advanced Features:**
- Achievements system
- Matchmaking
- Spectator mode
- Game replays
- Anti-cheat mechanisms

---

## Implementation Phases

### Phase 1: Core Infrastructure ✅ COMPLETE
1. ✅ Database schema for games, sessions, saves, leaderboards
2. ✅ REST API endpoints for session, storage, leaderboards
3. ❌ Token generation and validation for cross-origin games
4. ✅ Game library page and game player page
5. ✅ Basic admin interface to register/manage games

### Phase 2: Local Game Support ✅ COMPLETE
1. ✅ Game installation directory structure (`public_html/webdoors/`)
2. ✅ Manifest parsing and validation (WebDoorManifest class)
3. ✅ Local game serving with proper routing
4. ✅ Example games (blackjack, reverse polish calculator, terminal)

### Phase 3: Multiplayer
1. WebSocket server integration (may require separate process)
2. Lobby system (create, join, leave, start)
3. Real-time state broadcasting
4. Chat system
5. Database tables for lobbies and active games

### Phase 4: SDK & Documentation
1. webdoor-sdk.js library (full implementation)
2. Formal specification document (separate from this proposal)
3. Example single-player game
4. Example multiplayer game
5. Integration guide for BBS developers
6. Game developer guide

### Phase 5: Polish & Extended Features
1. Achievements system (optional feature)
2. Cross-origin game support with CORS
3. Anti-cheat considerations
4. Rate limiting and abuse prevention
5. Game statistics and analytics

### Future Considerations
1. Credits/currency system (separate spec)
2. Tournaments/competitions
3. Spectator mode
4. Game replays

---

## Maintaining This Document

**This specification is evolving.** When implementing new WebDoor functionality:

1. **Update Implementation Status** - Mark features as ✅ Implemented, 🚧 Partially Implemented, or ❌ Not Yet Implemented
2. **Document API Changes** - Add or update REST/WebSocket endpoint documentation
3. **Update Examples** - Ensure code examples reflect current API
4. **Note Breaking Changes** - Clearly mark any changes that break compatibility
5. **Update SDK Section** - Keep SDK examples current with actual implementation

**Key Areas to Update:**
- **Section 3 (REST API)** - When adding/modifying endpoints
- **Section 4 (Multiplayer API)** - When implementing WebSocket features
- **Current Implementation Status** - When completing features
- **Config Overrides** - When changing manifest config behavior
- **Security Considerations** - When adding security features

This document should remain the source of truth for WebDoor integration.

---

## Open Questions

1. **WebSocket Architecture** - Use PHP WebSocket library (Ratchet) or separate Node.js service?
2. **Offline Support** - Should games be able to work offline with sync later?
3. **Score Verification** - How to prevent cheating in leaderboards? (proof-of-play, server validation)
4. **Game Size Limits** - Maximum size for local game installations?
5. **Third-Party Trust** - How to vet/approve third-party game origins?
6. **API Versioning** - How to handle breaking changes while maintaining backward compatibility?

---

## Name Alternatives

- **WebDoor** (recommended) - Clear reference to BBS door games
- **WebChain** - Suggests linked/connected systems
- **DoorFrame** - The "frame" that hosts door games
- **PortalDoor** - Web portal + door game
- **OpenDoor** - Emphasizes open specification
- **DoorJS** - Emphasizes JavaScript nature~~
