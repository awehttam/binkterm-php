<?php

/**
 * WebDoor Routes
 *
 * Web pages and REST API endpoints for WebDoor (HTML5 BBS games)
 */

use BinktermPHP\ActivityTracker;
use BinktermPHP\Auth;
use BinktermPHP\BbsConfig;
use BinktermPHP\DoorManager;
use BinktermPHP\GameConfig;
use BinktermPHP\Template;
use BinktermPHP\WebDoorController;
use BinktermPHP\WebDoorManifest;
use Pecee\SimpleRouter\SimpleRouter;

/**
 * Helper function to get available WebDoor features
 */
function getAvailableWebDoorFeatures(): array {
    $features = [];

    // Storage and leaderboard are always available via WebDoor API
    $features[] = 'storage';
    $features[] = 'leaderboard';

    // Check if credits system is enabled
    $bbsConfig = BbsConfig::getConfig();
    $creditsConfig = $bbsConfig['credits'] ?? [];
    if (!empty($creditsConfig['enabled'])) {
        $features[] = 'credits';
    }

    return $features;
}

/**
 * Helper function to check if manifest requirements are met
 */
function checkManifestRequirements(array $manifest): bool {
    $requirements = $manifest['requirements'] ?? [];
    $requiredFeatures = $requirements['features'] ?? [];

    if (empty($requiredFeatures)) {
        return true; // No requirements, always met
    }

    $availableFeatures = getAvailableWebDoorFeatures();

    // Check if all required features are available
    foreach ($requiredFeatures as $required) {
        if (!in_array($required, $availableFeatures, true)) {
            return false;
        }
    }

    return true;
}

/**
 * Web Routes for WebDoor
 */

// GET /games - List available games
SimpleRouter::get('/games', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

     if(GameConfig::isGameSystemEnabled()==false){
         $template = new Template();
         $template->renderResponse('error.twig', [
             'error' => 'Sorry, the game system is not enabled.'
         ]);
         exit;
     }

    $games = [];

    // Get Web Doors
    foreach (WebDoorManifest::listManifests() as $entry) {
        $manifest = $entry['manifest'];
        if (!isset($manifest['game'])) {
            continue;
        }

        // Check if all required features are available
        if (!checkManifestRequirements($manifest)) {
            continue;
        }

        $game = $manifest['game'];
        $game['path'] = $entry['path'];
        $game['icon_url'] = "/webdoors/{$entry['path']}/" . ($game['icon'] ?? 'icon.png');
        $game['type'] = 'webdoor';

        if(GameConfig::isEnabled($entry['id'])){
            // Check for display_name and display_description overrides in configuration
            $gameConfig = GameConfig::getGameConfig($entry['id']);
            if ($gameConfig) {
                // Check top level first (config/webdoors.json uses flat structure)
                $displayName = $gameConfig['display_name'] ?? null;
                $displayDesc = $gameConfig['display_description'] ?? null;

                if (!empty($displayName)) {
                    $game['name'] = $displayName;
                }
                if (!empty($displayDesc)) {
                    $game['description'] = $displayDesc;
                }
            }
            $games[] = $game;
        }
    }

    // Get DOS Doors
    $doorManager = new DoorManager();
    $dosDoors = $doorManager->getEnabledDoors();
    foreach ($dosDoors as $doorId => $door) {
        // Skip admin-only doors for non-admin users
        if (!empty($door['admin_only']) && empty($user['is_admin'])) {
            continue;
        }
        // Check if door has a custom icon in manifest
        $iconUrl = '/images/dos-door-icon.png'; // Default icon
        if (!empty($door['icon'])) {
            // Use asset endpoint (manifest declares the actual filename)
            $iconUrl = "/door-assets/{$doorId}/icon";
        }

        $games[] = [
            'id' => $doorId,
            'name' => $door['name'],
            'description' => $door['description'] ?? '',
            'author' => $door['author'] ?? 'Unknown',
            'path' => $doorId,  // Will become /games/{doorid} (uses iframe wrapper)
            'icon_url' => $iconUrl,
            'type' => 'dosdoor',
            'genre' => $door['genre'] ?? [],
            'players' => $door['players'] ?? 'Unknown'
        ];
    }

    // Sort all games by name
    usort($games, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    // Build game lookup table for leaderboard (includes display_name overrides)
    $gameLookup = [];
    foreach ($games as $game) {
        $gameLookup[$game['id']] = [
            'name' => $game['name'],  // Already has display_name override applied if configured
            'path' => $game['path']
        ];
    }

    $leaderboard = [];
    try {
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $limit = 10;
        $stmt = $db->prepare('
            WITH best_scores AS (
                SELECT DISTINCT ON (l.user_id, l.game_id, l.board)
                    l.user_id, l.game_id, l.board, l.score, l.created_at
                FROM webdoor_leaderboards l
                WHERE l.created_at >= DATE_TRUNC(\'month\', CURRENT_DATE)
                ORDER BY l.user_id, l.game_id, l.board, l.score DESC, l.created_at DESC
            )
            SELECT b.game_id, b.board, u.real_name, u.username, b.score, b.created_at
            FROM best_scores b
            JOIN users u ON b.user_id = u.id
            ORDER BY b.score DESC, b.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $index => $row) {
            $displayName = $row['username'];
            $gameInfo = $gameLookup[$row['game_id']] ?? null;
            $leaderboard[] = [
                'rank' => $index + 1,
                'display_name' => $displayName,
                'score' => (int)$row['score'],
                'game_id' => $row['game_id'],
                'game_name' => $gameInfo['name'] ?? ucfirst($row['game_id']),
                'game_path' => $gameInfo['path'] ?? null,
                'board' => $row['board'],
                'date' => substr($row['created_at'], 0, 10)
            ];
        }
    } catch (\Throwable $e) {
        error_log('Failed to load WebDoor leaderboard: ' . $e->getMessage());
    }

    $template = new Template();
    $template->renderResponse('webdoors.twig', [
        'games' => $games,
        'leaderboard' => $leaderboard
    ]);
});

// GET /games/dosdoors/{doorid} - Play a DOS door game
SimpleRouter::get('/games/dosdoors/{doorid}', function($doorid) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    if(GameConfig::isGameSystemEnabled()==false){
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error' => 'Sorry, the game system is not enabled.'
        ]);
        exit;
    }

    // Verify door exists and is enabled
    $doorManager = new DoorManager();
    $door = $doorManager->getDoor($doorid);

    if (!$door || empty($door['config']['enabled'])) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/dosdoors/{$doorid}"
        ]);
        return;
    }

    // Block admin-only doors for non-admins
    if (!empty($door['admin_only']) && empty($user['is_admin'])) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title' => 'Access Denied',
            'error' => 'This door is restricted to administrators.'
        ]);
        return;
    }

    // Include the DOS door player
    $doorId = $doorid; // For the player script
    require __DIR__ . '/../public_html/webdoors/dosdoors/index.php';
});

// GET /games/{game} - Play a specific game (DOS door or web door)
SimpleRouter::get('/games/{game}', function($game) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    if(GameConfig::isGameSystemEnabled()==false){
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error' => 'Sorry, the game system is not enabled.'
        ]);
        exit;
    }

    // Check if this is a DOS door first
    $doorManager = new DoorManager();
    $door = $doorManager->getDoor($game);

    if ($door && !empty($door['config']['enabled'])) {
        // Block admin-only doors for non-admins
        if (!empty($door['admin_only']) && empty($user['is_admin'])) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('error.twig', [
                'error_title' => 'Access Denied',
                'error' => 'This door is restricted to administrators.'
            ]);
            return;
        }

        // This is a DOS door - render with embedded player
        $template = new Template();
        $template->renderResponse('dosdoor_play.twig', [
            'door' => $door,
            'door_id' => $game
        ]);
        return;
    }

    // Check if this is a web door
    $gameDir = __DIR__ . '/../public_html/webdoors/' . basename($game);
    $manifestPath = $gameDir . '/webdoor.json';

    if (!file_exists($manifestPath)) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/{$game}"
        ]);
        return;
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);

    // Check if all required features are available
    if (!checkManifestRequirements($manifest)) {
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error' => 'This game requires features that are not currently enabled on this system.'
        ]);
        return;
    }

    $entryPoint = $manifest['game']['entry_point'] ?? 'index.html';
    $gameUrl = "/webdoors/{$game}/{$entryPoint}";

    // Apply display_name and display_description overrides if configured
    $gameData = $manifest['game'];
    $gameConfig = GameConfig::getGameConfig($game);
    if ($gameConfig) {
        // Check top level (config/webdoors.json uses flat structure)
        $displayName = $gameConfig['display_name'] ?? null;
        $displayDesc = $gameConfig['display_description'] ?? null;

        if (!empty($displayName)) {
            $gameData['name'] = $displayName;
        }
        if (!empty($displayDesc)) {
            $gameData['description'] = $displayDesc;
        }
    }

    $template = new Template();
    $template->renderResponse('webdoor_play.twig', [
        'game' => $gameData,
        'game_url' => $gameUrl,
        'game_id' => $game
    ]);
});

/**
 * WebDoor API Routes
 */

// GET /api/webdoor/session - Get or create session
SimpleRouter::get('/api/webdoor/session', function() {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        http_response_code(500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->getSession();

    // Track webdoor play session (only when the session endpoint returns successfully)
    if (!empty($result['session_id'])) {
        $auth = new Auth();
        $user = $auth->getCurrentUser();
        if ($user) {
            $userId = $user['user_id'] ?? $user['id'] ?? null;
            $gameId = $result['game']['id'] ?? null;
            ActivityTracker::track($userId, ActivityTracker::TYPE_WEBDOOR_PLAY, null, $gameId);
        }
    }

    echo json_encode($result);
});

// POST /api/webdoor/session/end - End session
SimpleRouter::post('/api/webdoor/session/end', function() {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        http_response_code(500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->endSession();

    echo json_encode($result);
});

// GET /api/webdoor/storage - List all saves
SimpleRouter::get('/api/webdoor/storage', function() {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        http_response_code(500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->listSaves();

    echo json_encode($result);
});

// GET /api/webdoor/storage/{slot} - Load specific save
SimpleRouter::get('/api/webdoor/storage/{slot}', function($slot) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        http_response_code(500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->loadSave((int)$slot);

    if ($result === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Save not found']);
        return;
    }

    echo json_encode($result);
});

// PUT /api/webdoor/storage/{slot} - Save game data
SimpleRouter::put('/api/webdoor/storage/{slot}', function($slot) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        http_response_code(500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->saveGame((int)$slot);

    echo json_encode($result);
});

// DELETE /api/webdoor/storage/{slot} - Delete save
SimpleRouter::delete('/api/webdoor/storage/{slot}', function($slot) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        http_response_code(500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->deleteSave((int)$slot);

    echo json_encode($result);
});

// GET /api/webdoor/leaderboard/{board} - Get leaderboard
SimpleRouter::get('/api/webdoor/leaderboard/{board}', function($board) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        http_response_code(500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->getLeaderboard($board);

    echo json_encode($result);
});

// POST /api/webdoor/leaderboard/{board} - Submit score
SimpleRouter::post('/api/webdoor/leaderboard/{board}', function($board) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        http_response_code(500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->submitScore($board);

    echo json_encode($result);
});
