# ChessHive: Community Chess WebDoor Proposal

**Status**: Draft Proposal
**Generated**: 2026-02-05
**Note**: This is an AI-generated draft proposal and has not been reviewed for technical accuracy or feasibility.

## Overview

ChessHive is a collaborative chess WebDoor where the community plays together. Unlike traditional chess where two players face off, ChessHive allows **any player to make the next move** in an ongoing game. Multiple games run simultaneously, and players can contribute moves to different games each day, creating a unique social chess experience.

**Architecture**: ChessHive is a **self-contained WebDoor** that requires **zero modifications to core BinktermPHP**. All game logic, chess validation, and API endpoints are handled within the WebDoor directory.

## Core Concept

### The Hive Mind

- **Community Play**: No fixed teams - any player can make the next move for white or black
- **Multiple Games**: 3-10 concurrent chess games always in progress
- **Daily Move Limits**: Players can make moves on up to **half the active games (minimum 1)** per day
- **First-Come-First-Served**: Whoever makes the next valid move claims that turn
- **Spectator Mode**: Watch games in progress, see move history, analyze positions
- **Game Rotation**: When a game ends (checkmate/stalemate/draw), a new game starts automatically

### Why It's Fun

- **Accessible**: No need to commit to a full chess match
- **Social**: See what moves the community makes, discuss strategy
- **Educational**: Watch different playing styles, learn from others' moves
- **Casual**: Make 1-3 moves per day at your leisure
- **Variety**: Multiple games means different positions and challenges
- **Community Building**: Players work together (or compete) across games

## Game Mechanics

### Move Limits

**Daily Move Allowance**:
```
moves_per_day = max(1, floor(active_games / 2))
```

Examples:
- 2 active games → 1 move per day
- 4 active games → 2 moves per day
- 6 active games → 3 moves per day
- 10 active games → 5 moves per day

**Rules**:
- Limit resets at midnight (system timezone)
- Can't make consecutive moves for the same color in the same game
- Can make moves in different games
- Spectating is unlimited

### Game Lifecycle

**Active Game States**:
1. **In Progress**: Awaiting next move (white or black to move)
2. **Check**: King is in check, must resolve
3. **Checkmate**: Game over, winner declared
4. **Stalemate**: Game over, draw
5. **Draw by Agreement**: Community can vote to draw (future feature)
6. **Abandoned**: No moves for 7 days, archived

**Game Start**:
- New games start automatically when total active games drops below minimum (default: 5)
- Starting position: Standard chess setup
- First move: White goes first
- Game gets a sequential ID and creation timestamp

**Game End**:
- Checkmate: Winner recorded, game archived
- Stalemate/Draw: Marked as draw, game archived
- New game spawns immediately to maintain active game count

### Chess Rules

**Standard Chess**:
- All FIDE chess rules apply
- Piece movement validation
- Check/checkmate detection
- Castling (kingside/queenside)
- En passant
- Pawn promotion (defaults to Queen, can choose)

**Move Notation**:
- Algebraic notation (e4, Nf3, Qxd5, O-O, etc.)
- Long form accepted (e2-e4)
- Visual board also available (click to move)

**Move Validation**:
- Server-side validation prevents illegal moves
- Move must be legal for current board position
- Can't move into check
- If in check, must resolve check

### Player Participation

**Move History**:
- Each move records: player name, move notation, timestamp, board state (FEN)
- Full game PGN (Portable Game Notation) available for download
- Move list with annotations

**Statistics**:
- Moves made today / daily limit
- Total moves contributed (all-time)
- Checkmates delivered
- Games participated in
- Favorite pieces/openings (advanced stats)

**Leaderboard**:
- Most moves contributed
- Most checkmates
- Most active players (7-day rolling)
- Current streak (consecutive days with moves)

## User Interface

### Main Dashboard

**Game List**:
```
┌─────────────────────────────────────────┐
│ Active Games (5)            Your moves: 2/2 │
├─────────────────────────────────────────┤
│ Game #42  │ Move 15 │ White to move    │
│ [Board Preview]                          │
│ Last: Nf3 by Alice (2 min ago)          │
│ [View Game] [Make Move]                 │
├─────────────────────────────────────────┤
│ Game #41  │ Move 28 │ Black to move    │
│ [Board Preview]                          │
│ Last: d4 by Bob (15 min ago)            │
│ [View Game] [Make Move]                 │
└─────────────────────────────────────────┘
```

**Filters**:
- All games
- Your turn (games you haven't moved in yet today)
- White to move
- Black to move
- Completed games (archive)

### Game View

**Chess Board**:
- Visual 8x8 board with pieces
- Current turn indicator (White/Black to move)
- Click piece → click destination (or type algebraic notation)
- Legal moves highlighted on piece selection
- Last move highlighted
- Check indicator if applicable

**Move Entry**:
- Algebraic notation input field (e.g., "e4", "Nf3")
- Visual move (click-and-drag or click-click)
- Validate button
- Submit move button (after validation)

**Game Info Panel**:
```
Game #42 - Move 15
White to move

Move History:
15. ... Nf6 (Alice, 2 min ago)
15. d4 (Bob, 8 min ago)
14. ... e5 (Charlie, 1 hour ago)
14. Nf3 (Alice, 2 hours ago)
...

Download PGN
View Full History
```

**Board State**:
- FEN notation displayed (for analysis tools)
- Material count (pieces captured)
- Move clock (time since last move)

### Mobile Support

- Responsive chess board (scales to screen)
- Touch-friendly piece movement
- Swipe to navigate move history
- Portrait and landscape modes

## Technical Architecture

### File Structure

```
public_html/webdoors/chesshive/
├── webdoor.json              # Manifest
├── game.html                 # Main game interface
├── api.php                   # API endpoint handler
├── src/
│   ├── ChessHive.php         # Main game class
│   ├── ChessEngine.php       # Chess logic and validation
│   ├── Board.php             # Board state management
│   ├── Game.php              # Game lifecycle
│   ├── MoveValidator.php     # Move legality checking
│   ├── Notation.php          # Algebraic notation parser
│   └── Stats.php             # Player statistics
├── game.html                 # Game interface
├── css/
│   ├── chesshive.css         # Game styles
│   ├── chessboard.css        # Board styling
│   └── pieces.css            # Chess piece sprites/fonts
├── js/
│   ├── game.js               # Main game logic
│   ├── board.js              # Board rendering and interaction
│   ├── notation.js           # Move notation handling
│   └── api.js                # API communication
├── assets/
│   ├── pieces/               # Chess piece images/SVG
│   │   ├── white-king.svg
│   │   ├── white-queen.svg
│   │   ├── black-pawn.svg
│   │   └── ... (all pieces)
│   └── sounds/               # Move sounds (optional)
│       ├── move.mp3
│       ├── capture.mp3
│       └── check.mp3
└── migrations/
    └── v1.0.0_chesshive_schema.sql
```

### Database Schema

```sql
-- Active and completed chess games
CREATE TABLE chesshive_games (
    id SERIAL PRIMARY KEY,
    game_number INTEGER NOT NULL UNIQUE,  -- Human-friendly game ID
    status VARCHAR(20) NOT NULL,          -- active, checkmate, stalemate, draw, abandoned
    current_turn VARCHAR(5) NOT NULL,     -- white, black
    board_fen TEXT NOT NULL,              -- Current board state (Forsyth-Edwards Notation)
    move_count INTEGER DEFAULT 0,
    winner VARCHAR(10),                   -- white, black, draw, NULL
    pgn TEXT,                             -- Full game in PGN format
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    last_move_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Individual moves in each game
CREATE TABLE chesshive_moves (
    id SERIAL PRIMARY KEY,
    game_id INTEGER NOT NULL REFERENCES chesshive_games(id) ON DELETE CASCADE,
    move_number INTEGER NOT NULL,         -- Full move number (increments after black moves)
    color VARCHAR(5) NOT NULL,            -- white, black
    player_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    player_name VARCHAR(100) NOT NULL,
    move_notation VARCHAR(20) NOT NULL,   -- Algebraic notation (e.g., "e4", "Nf3")
    move_long VARCHAR(20),                -- Long form (e.g., "e2-e4")
    board_fen_after TEXT NOT NULL,        -- Board state after this move
    is_check BOOLEAN DEFAULT FALSE,
    is_checkmate BOOLEAN DEFAULT FALSE,
    is_capture BOOLEAN DEFAULT FALSE,
    piece_moved VARCHAR(10) NOT NULL,     -- pawn, knight, bishop, rook, queen, king
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(game_id, move_number, color)
);

-- Player daily move tracking
CREATE TABLE chesshive_player_moves (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    game_id INTEGER NOT NULL REFERENCES chesshive_games(id) ON DELETE CASCADE,
    move_date DATE NOT NULL DEFAULT CURRENT_DATE,
    move_count INTEGER DEFAULT 1,
    last_move_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, game_id, move_date)
);

-- Player statistics
CREATE TABLE chesshive_stats (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    total_moves INTEGER DEFAULT 0,
    checkmates_delivered INTEGER DEFAULT 0,
    games_participated INTEGER DEFAULT 0,
    current_streak_days INTEGER DEFAULT 0,
    longest_streak_days INTEGER DEFAULT 0,
    last_move_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Future: Echomail message tracking (prevent duplicate processing)
CREATE TABLE chesshive_processed_messages (
    id SERIAL PRIMARY KEY,
    echomail_id INTEGER NOT NULL UNIQUE REFERENCES echomail(id) ON DELETE CASCADE,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_games_status ON chesshive_games(status);
CREATE INDEX idx_games_last_move ON chesshive_games(last_move_at);
CREATE INDEX idx_moves_game ON chesshive_moves(game_id);
CREATE INDEX idx_player_moves_user_date ON chesshive_player_moves(user_id, move_date);
CREATE INDEX idx_stats_total_moves ON chesshive_stats(total_moves DESC);
```

### Chess Engine

ChessHive uses the `ryanhs/chess.php` library for chess logic and validation:

```json
// composer.json addition
{
  "require": {
    "ryanhs/chess.php": "^1.0"
  }
}
```

**Benefits**:
- Full chess rule implementation (all FIDE rules)
- Move validation
- Check/checkmate/stalemate detection
- FEN import/export
- PGN generation
- Well-tested in production environments

**Note on Maintenance Status**:
The `ryanhs/chess.php` library is no longer actively maintained by its original author. However, we've chosen to use it because:
- It provides complete, working chess functionality
- The chess rules are stable and unlikely to change
- The library is mature and has been used in production
- Implementing full chess logic from scratch is complex and error-prone
- The codebase can be forked if critical issues arise in the future

**Usage Example**:
```php
use Ryanhs\Chess\Chess;

// Load game state
$chess = new Chess($fenString);

// Validate and make move
$result = $chess->move('e4');
if ($result) {
    $newFen = $chess->fen();
    $isCheckmate = $chess->inCheckmate();
    $isCheck = $chess->inCheck();
    $pgn = $chess->pgn();
}
```

### API Endpoints

All endpoints use GET parameter routing via `api.php`:

```
GET  /webdoors/chesshive/api.php?endpoint=games
     - Get list of active games
     - Query params: status (active/completed), limit, offset

GET  /webdoors/chesshive/api.php?endpoint=game&id=42
     - Get specific game details (board state, move history)

POST /webdoors/chesshive/api.php?endpoint=move
     - Submit a move
     - Body: { game_id, move_notation }
     - Validates: legal move, daily limit, not consecutive same-color move

GET  /webdoors/chesshive/api.php?endpoint=player-stats
     - Get player's statistics and daily move count

GET  /webdoors/chesshive/api.php?endpoint=leaderboard
     - Get leaderboard (most moves, checkmates, streak)
     - Query params: type (moves/checkmates/streak), limit

GET  /webdoors/chesshive/api.php?endpoint=move-history&game_id=42
     - Get full move history for a game

GET  /webdoors/chesshive/api.php?endpoint=validate-move
     - Validate a move without submitting
     - Query params: game_id, move_notation
     - Returns: legal (bool), error message if illegal

GET  /webdoors/chesshive/api.php?endpoint=pgn&game_id=42
     - Download game PGN file

GET  /webdoors/chesshive/api.php?endpoint=my-games
     - Get games where player has moves remaining today
```

### API Example: api.php

```php
<?php
// public_html/webdoors/chesshive/api.php

require_once __DIR__ . '/../../../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\Auth;

$db = Database::getInstance()->getPdo();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userId = $auth->getUserId();

// Load game classes
require_once __DIR__ . '/src/ChessHive.php';
require_once __DIR__ . '/src/ChessEngine.php';
require_once __DIR__ . '/src/Board.php';
require_once __DIR__ . '/src/Game.php';
require_once __DIR__ . '/src/MoveValidator.php';
require_once __DIR__ . '/src/Notation.php';
require_once __DIR__ . '/src/Stats.php';

$chessHive = new ChessHive($db, $userId);

$endpoint = $_GET['endpoint'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

try {
    switch ($endpoint) {
        case 'games':
            $status = $_GET['status'] ?? 'active';
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = (int)($_GET['offset'] ?? 0);
            echo json_encode($chessHive->getGames($status, $limit, $offset));
            break;

        case 'game':
            $gameId = (int)($_GET['id'] ?? 0);
            echo json_encode($chessHive->getGame($gameId));
            break;

        case 'move':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                echo json_encode($chessHive->makeMove($data['game_id'], $data['move_notation']));
            }
            break;

        case 'validate-move':
            $gameId = (int)($_GET['game_id'] ?? 0);
            $move = $_GET['move_notation'] ?? '';
            echo json_encode($chessHive->validateMove($gameId, $move));
            break;

        case 'player-stats':
            echo json_encode($chessHive->getPlayerStats());
            break;

        case 'leaderboard':
            $type = $_GET['type'] ?? 'moves';
            $limit = (int)($_GET['limit'] ?? 10);
            echo json_encode($chessHive->getLeaderboard($type, $limit));
            break;

        case 'move-history':
            $gameId = (int)($_GET['game_id'] ?? 0);
            echo json_encode($chessHive->getMoveHistory($gameId));
            break;

        case 'pgn':
            $gameId = (int)($_GET['game_id'] ?? 0);
            $pgn = $chessHive->getGamePGN($gameId);
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="chesshive-game-' . $gameId . '.pgn"');
            echo $pgn;
            break;

        case 'my-games':
            echo json_encode($chessHive->getGamesWithMovesRemaining());
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

```json
{
  "name": "ChessHive",
  "version": "1.0.0",
  "description": "Community chess where any player can make the next move",
  "author": "BinktermPHP",
  "category": "games",
  "display_name": "ChessHive",
  "icon": "♟️",
  "requires_auth": true,
  "api_version": "1.0",
  "entry_point": "game.html",
  "echomail_integration": {
    "echoarea": "COMMCHESS_SYNC",
    "message_types": ["move", "game_end", "new_game"]
  },
  "config": {
    "min_active_games": 5,
    "max_active_games": 10,
    "moves_per_day_divisor": 2,
    "min_moves_per_day": 1,
    "game_abandon_days": 7,
    "enable_move_sounds": true,
    "allow_pawn_promotion_choice": true
  }
}
```

## Game Logic Implementation

### Move Validation Flow

```
1. Player selects game and enters move (e.g., "e4")
2. JavaScript calls: api.php?endpoint=validate-move&game_id=42&move_notation=e4
3. Server validates:
   - Game exists and is active
   - Player hasn't exceeded daily move limit
   - Player didn't make last move for this color
   - Move is legal (chess rules)
   - Board state allows move (not in check unless resolving)
4. Return: { legal: true/false, error: null/message }
5. If valid, player submits: POST api.php?endpoint=move
6. Server executes move:
   - Update board state (FEN)
   - Record move in database
   - Update player stats
   - Check for check/checkmate/stalemate
   - Update game status if game over
   - Spawn new game if needed
7. Return: { success: true, game_state: {...} }
```

### Daily Move Limit Enforcement

```php
// In ChessHive class
public function canMakeMove($gameId) {
    $userId = $this->userId;
    $today = date('Y-m-d');

    // Get active games count
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM chesshive_games WHERE status = 'active'");
    $stmt->execute();
    $activeGames = $stmt->fetchColumn();

    // Calculate daily limit
    $movesPerDay = max(1, floor($activeGames / 2));

    // Count moves today
    $stmt = $this->db->prepare("
        SELECT COUNT(DISTINCT game_id)
        FROM chesshive_player_moves
        WHERE user_id = ? AND move_date = ?
    ");
    $stmt->execute([$userId, $today]);
    $movesToday = $stmt->fetchColumn();

    if ($movesToday >= $movesPerDay) {
        return ['allowed' => false, 'reason' => 'Daily move limit reached'];
    }

    // Check if player made last move for this game's current color
    $game = $this->getGame($gameId);
    $lastMove = $this->getLastMove($gameId);

    if ($lastMove && $lastMove['player_id'] == $userId && $lastMove['color'] == $game['current_turn']) {
        return ['allowed' => false, 'reason' => 'Cannot make consecutive moves for same color'];
    }

    return ['allowed' => true];
}
```

### Checkmate/Stalemate Detection

```php
// Using chess library (ryanhs/chess.php)
use Ryanhs\Chess\Chess;

public function makeMove($gameId, $moveNotation) {
    $game = $this->getGame($gameId);

    // Load board state
    $chess = new Chess($game['board_fen']);

    // Attempt move
    $result = $chess->move($moveNotation);

    if (!$result) {
        throw new Exception('Illegal move');
    }

    // Get new board state
    $newFen = $chess->fen();

    // Check game status
    $status = 'active';
    $winner = null;

    if ($chess->inCheckmate()) {
        $status = 'checkmate';
        $winner = $game['current_turn']; // Current turn won (opponent is checkmated)
    } elseif ($chess->inStalemate() || $chess->inDraw()) {
        $status = 'stalemate';
        $winner = 'draw';
    }

    // Update game and record move
    // ...
}
```

### Game Spawning

```php
// Maintain minimum number of active games
public function maintainActiveGames() {
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM chesshive_games WHERE status = 'active'");
    $stmt->execute();
    $activeGames = $stmt->fetchColumn();

    $minGames = $this->config['min_active_games'] ?? 5;

    while ($activeGames < $minGames) {
        $this->createNewGame();
        $activeGames++;
    }
}

private function createNewGame() {
    // Get next game number
    $stmt = $this->db->prepare("SELECT COALESCE(MAX(game_number), 0) + 1 FROM chesshive_games");
    $stmt->execute();
    $gameNumber = $stmt->fetchColumn();

    // Starting position FEN
    $startFen = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    $stmt = $this->db->prepare("
        INSERT INTO chesshive_games (game_number, status, current_turn, board_fen, move_count)
        VALUES (?, 'active', 'white', ?, 0)
    ");
    $stmt->execute([$gameNumber, $startFen]);

    return $gameNumber;
}
```

## Phase 1: Local Gameplay (Week 1-2)

**Goal**: Fully functional local chess game with community mechanics.

**Tasks**:
1. Create WebDoor directory structure
2. Add `ryanhs/chess.php` to composer dependencies
3. Create database migration
4. Implement ChessHive core classes
5. Build API endpoints (api.php)
6. Create chess board UI (HTML/CSS/JS)
7. Implement move validation and submission
8. Add player statistics tracking
9. Create leaderboard
10. Test with multiple local users

**Deliverables**:
- Working community chess game
- Multiple concurrent games
- Daily move limits enforced
- Chess rules fully validated
- Statistics and leaderboards
- PGN export

## Phase 2: Enhanced UI (Week 3)

**Tasks**:
1. Visual piece movement (drag-and-drop or click-click)
2. Highlight legal moves when piece selected
3. Animated piece movement
4. Sound effects (move, capture, check, checkmate)
5. Mobile-responsive board
6. Move history navigation (step through game)
7. Board flipping (view from black's perspective)
8. Opening name detection (if using known opening)

**Deliverables**:
- Polished, intuitive UI
- Smooth animations
- Mobile support
- Enhanced user experience

## Phase 3: Multi-Node via Echomail (Week 4-5)

**Goal**: Synchronize games across multiple BBS nodes via COMMCHESS_SYNC echomail.

**Echomail Message Types**:

### 1. Move Message
```
Subject: [ChessHive] Game #42 Move 15: e4 (Node 1:1/1)
From: BBS Node 1:1/1

{
  "type": "move",
  "game_id": 42,
  "move_number": 15,
  "color": "white",
  "player_name": "Alice",
  "player_node": "1:1/1",
  "move_notation": "e4",
  "board_fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1",
  "is_check": false,
  "timestamp": "2026-02-05T14:23:00Z"
}
```

### 2. Game End Message
```
Subject: [ChessHive] Game #42 Completed - Checkmate! (Node 1:1/1)
From: BBS Node 1:1/1

{
  "type": "game_end",
  "game_id": 42,
  "result": "checkmate",
  "winner": "white",
  "final_move": "Qxf7#",
  "final_move_by": "Bob",
  "final_move_node": "1:2/3",
  "total_moves": 28,
  "pgn": "[Full PGN notation...]",
  "timestamp": "2026-02-05T15:30:00Z"
}
```

### 3. New Game Message
```
Subject: [ChessHive] New Game #43 Started (Node 1:1/1)
From: BBS Node 1:1/1

{
  "type": "new_game",
  "game_id": 43,
  "game_number": 43,
  "timestamp": "2026-02-05T15:31:00Z"
}
```

**Implementation**:

1. **COMMCHESS_SYNC Echo Area Auto-Creation**:
```php
// On first run, create echo area if needed
$stmt = $db->prepare("SELECT id FROM echoareas WHERE tag = ? AND domain = ?");
$stmt->execute(['COMMCHESS_SYNC', 'lovlynet']);

if (!$stmt->fetch()) {
    $stmt = $db->prepare("
        INSERT INTO echoareas (tag, domain, description, sysop_only, created_at)
        VALUES (?, ?, ?, TRUE, NOW())
    ");
    $stmt->execute([
        'COMMCHESS_SYNC',
        'lovlynet',
        'ChessHive community chess synchronization (auto-created)'
    ]);
}
```

2. **Post Move to Echomail**:
```php
use BinktermPHP\MessageHandler;

public function postMoveToNetwork($gameId, $moveData) {
    $messageHandler = new MessageHandler();

    $messageData = [
        'type' => 'move',
        'game_id' => $gameId,
        'move_number' => $moveData['move_number'],
        'color' => $moveData['color'],
        'player_name' => $moveData['player_name'],
        'player_node' => $this->getLocalNodeAddress(),
        'move_notation' => $moveData['move_notation'],
        'board_fen' => $moveData['board_fen'],
        'is_check' => $moveData['is_check'],
        'timestamp' => gmdate('c')
    ];

    $messageHandler->postEchomail(
        $this->getSystemUserId(),
        'COMMCHESS_SYNC',
        'lovlynet',
        'All',
        "[ChessHive] Game #{$gameId} Move {$moveData['move_number']}: {$moveData['move_notation']}",
        json_encode($messageData),
        null,
        'ChessHive'
    );
}
```

3. **Process Incoming Moves**:
```php
public function syncFromNetwork() {
    $messageHandler = new MessageHandler();

    $result = $messageHandler->getEchomail(
        'COMMCHESS_SYNC',
        'lovlynet',
        1,
        100,
        null,
        'all'
    );

    foreach ($result['messages'] as $msg) {
        if ($this->isMessageProcessed($msg['id'])) {
            continue;
        }

        $data = json_decode($msg['message_text'], true);

        switch ($data['type'] ?? '') {
            case 'move':
                $this->processNetworkMove($data);
                break;
            case 'game_end':
                $this->processGameEnd($data);
                break;
            case 'new_game':
                $this->processNewGame($data);
                break;
        }

        $this->markMessageProcessed($msg['id']);
    }
}
```

**Challenges**:
- **Game ID Conflicts**: Different nodes may create games with same ID
  - Solution: Use composite key (node_address + local_game_id)
  - Or: Master node assigns game IDs
- **Move Conflicts**: Two players on different nodes move simultaneously
  - Solution: Timestamp-based ordering (earlier timestamp wins)
  - Conflicting move gets rejected, player notified
- **Board State Sync**: Ensure all nodes have identical board state
  - Solution: Include full FEN in each move message
  - Periodically verify FEN matches expected state

**Deliverables**:
- Cross-node move synchronization
- Game state consistency across nodes
- Conflict resolution
- Network game discovery

## Phase 4: Advanced Features (Week 6+)

**Potential Features**:

1. **Move Voting System**: Instead of first-come-first-served, community votes on next move
   - Multiple players propose moves
   - Community votes (1 vote per player per game per turn)
   - Highest voted move executes after time window (e.g., 1 hour)
   - More democratic, strategic

2. **Team Mode**: Divide players into White Team and Black Team
   - Each team collaborates on their color's moves
   - Team chat/discussion
   - Voting within team for next move

3. **Puzzle Mode**: Daily chess puzzles
   - "Mate in 2", "Win the piece", etc.
   - First player to solve gets bonus credits

4. **Opening Library**: Show opening names
   - "Sicilian Defense", "King's Gambit", etc.
   - Educational tooltips

5. **Analysis Board**: Post-game analysis
   - Step through moves
   - See what other moves were possible
   - Computer analysis (if integrating Stockfish)

6. **Credits Integration**:
   - Purchase extra moves (beyond daily limit)
   - Bet credits on game outcomes
   - Reward active contributors

7. **Tournaments**: Scheduled events
   - Best-of-3 games
   - Timed moves (1 hour per move)
   - Prize pool

8. **Spectator Chat**: Real-time discussion
   - Comment on moves
   - Suggest next moves (without voting)
   - Predictions

## User Experience Considerations

### Onboarding

**First-Time User**:
1. Welcome modal explaining community chess concept
2. Quick tutorial: "Try making a move in Game #42"
3. Show move limit and reset time
4. Highlight active games list

**Tooltips**:
- "You can make 2 more moves today (resets at midnight)"
- "Click a piece to see legal moves"
- "This game is waiting for White's move"

### Notifications

**Optional Email/Push Notifications**:
- "Your favorite game (Game #42) is waiting for a move!"
- "Daily reminder: You have 3 moves remaining"
- "Game #42 ended in checkmate!"

**In-Game Notifications**:
- "New game started: Game #45"
- "Game #42: Checkmate! White wins!"
- "You contributed to a winning game!"

### Accessibility

- Keyboard navigation (arrow keys, enter to select)
- Screen reader support (board state announced)
- High contrast mode (for visually impaired)
- Algebraic notation for screen readers

## Configuration

**BBS Admin Settings** (in webdoors.json or game config):

```json
{
  "min_active_games": 5,
  "max_active_games": 10,
  "moves_per_day_divisor": 2,
  "min_moves_per_day": 1,
  "game_abandon_days": 7,
  "enable_multinode": false,
  "local_node_address": "1:1/1",
  "enable_move_sounds": true,
  "enable_notifications": false,
  "allow_pawn_promotion_choice": true,
  "show_opening_names": true
}
```

## Success Metrics

- **Engagement**: Daily active players, moves per day
- **Retention**: Players returning daily (streak tracking)
- **Completion**: Games completed per week
- **Community**: Unique players per game
- **Cross-Node**: Moves from remote nodes (Phase 3)

## Technical Considerations

### Performance

- **Database Indexes**: Ensure fast queries for active games, move history
- **Caching**: Cache active games list (refresh every 60 seconds)
- **FEN Storage**: Efficient board state storage
- **PGN Generation**: Generate on-demand, not on every move

### Security

- **Move Validation**: Server-side only (never trust client)
- **Rate Limiting**: Prevent spam move attempts
- **Input Sanitization**: Algebraic notation only accepts valid characters
- **Echomail Validation**: Verify message signatures (Phase 3)

### Scalability

- **Archive Old Games**: Move completed games to archive table after 30 days
- **Pagination**: Don't load all moves at once for long games
- **Lazy Loading**: Load board state on demand

### Chess Library Dependency

**Using ryanhs/chess.php**:
- **Pros**: Full chess implementation, well-tested, PGN/FEN support, handles all edge cases
- **Cons**: External dependency, no longer actively maintained upstream
- **Mitigation**: Library is stable and feature-complete; chess rules don't change. Can fork if critical issues arise.

**Composer Requirement**:
```bash
composer require ryanhs/chess.php
```

**Fallback**: If library unavailable or installation fails, show error message and disable game until dependency is resolved. The game cannot function without proper chess validation.

## Conclusion

ChessHive offers a unique social gaming experience that combines the strategic depth of chess with the collaborative spirit of BBS communities. By allowing any player to make the next move, it creates a dynamic, accessible chess environment where casual players and experts can participate together.

The phased implementation allows for rapid local deployment while leaving room for advanced multi-node features via echomail integration.

---

**Next Steps**:
1. Review and approve proposal
2. Add `ryanhs/chess.php` to composer.json
3. Create WebDoor directory: `public_html/webdoors/chesshive/`
4. Run database migration
5. Implement Phase 1 (local gameplay)
6. Test with multiple users
7. Polish UI (Phase 2)
8. Implement echomail sync (Phase 3)
9. Launch!

**Installation for Sysops**:
1. Copy `/webdoors/chesshive/` to BBS
2. Run `composer install` (for chess library)
3. Run database migration
4. Game appears in WebDoors menu
5. COMMCHESS_SYNC auto-creates on first use (Phase 3)
6. Configure settings in webdoor config
7. Players start making moves!

**Estimated Timeline**:
- Phase 1 (Local): 1-2 weeks
- Phase 2 (UI Polish): 1 week
- Phase 3 (Multi-Node): 1-2 weeks
- Phase 4 (Advanced): Ongoing

Total MVP: 3-5 weeks for fully functional local + multi-node community chess.
