<?php

/**
 * WebDoor Routes
 *
 * Web pages and REST API endpoints for WebDoor (HTML5 BBS games)
 */

use BinktermPHP\Auth;
use BinktermPHP\BbsConfig;
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

        if(GameConfig::isEnabled($entry['id'])){
            $games[] = $game;
        }
    }

    $gameLookup = [];
    foreach ($games as $game) {
        $gameLookup[$game['id']] = [
            'name' => $game['name'],
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
            $displayName = $row['username'] ?: $row['real_name'];
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

// GET /games/{game} - Play a specific game
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

    // Validate game exists
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

    $template = new Template();
    $template->renderResponse('webdoor_play.twig', [
        'game' => $manifest['game'],
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
