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
use BinktermPHP\JsdosDoorConfig;
use BinktermPHP\JsdosDoorManifest;
use BinktermPHP\JsdosDoorSupport;
use BinktermPHP\Template;
use BinktermPHP\WebDoorController;
use BinktermPHP\WebDoorManifest;
use Pecee\SimpleRouter\SimpleRouter;

function webdoorApiError(string $errorCode, string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'error_code' => $errorCode,
        'error' => $message,
    ]);
}

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
 * Resolve a JS-DOS game + mode request with access checks and normalized manifest data.
 *
 * @param array<string, mixed>|null $user
 * @return array<string, mixed>|null
 */
function resolveJsdosDoorRequest(string $gameId, string $modeId = 'play', ?array $user = null): ?array
{
    $entry = JsdosDoorManifest::getManifest($gameId);
    if (!$entry || !JsdosDoorConfig::isEnabled($entry['id'])) {
        return null;
    }

    $manifest = JsdosDoorSupport::normalizeManifest($entry['manifest']);
    $mode = JsdosDoorSupport::getMode($manifest, $modeId);
    if (!$mode || !JsdosDoorSupport::canUserAccessMode($mode, $user)) {
        return null;
    }

    return [
        'entry' => $entry,
        'manifest' => $manifest,
        'mode' => $mode,
        'mode_id' => $modeId,
    ];
}

/**
 * Load a JS-DOS session for the current user.
 *
 * @return array<string, mixed>|null
 */
function loadJsdosSessionForUser(string $sessionId, int $userId): ?array
{
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    $stmt = $db->prepare("
        SELECT session_id, door_id, user_id, door_type, user_data, ended_at, expires_at
          FROM door_sessions
         WHERE session_id = ?
            AND user_id = ?
            AND door_type = 'jsdos'
            AND ended_at IS NULL
          LIMIT 1
    ");
    $stmt->execute([$sessionId, $userId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $userData = [];
    if (!empty($row['user_data'])) {
        if (is_array($row['user_data'])) {
            $userData = $row['user_data'];
        } elseif (is_string($row['user_data'])) {
            $decoded = json_decode($row['user_data'], true);
            if (is_array($decoded)) {
                $userData = $decoded;
            }
        }
    }

    $row['user_data'] = $userData;
    $row['mode'] = (string)($userData['mode'] ?? 'play');

    return $row;
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
             'error_code' => 'ui.webdoors.errors.system_disabled'
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
            'author' => $door['author'] ?? null,
            'version' => $door['game_version'] ?? null,
            'path' => $doorId,  // Will become /games/{doorid} (uses iframe wrapper)
            'icon_url' => $iconUrl,
            'type' => 'dosdoor',
            'genre' => $door['genre'] ?? [],
            'players' => $door['players'] ?? null
        ];
    }

    // Get Native Doors
    $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
    $nativeDoors = $nativeDoorManager->getEnabledDoors();
    foreach ($nativeDoors as $doorId => $door) {
        // Skip admin-only doors for non-admin users
        if (!empty($door['admin_only']) && empty($user['is_admin'])) {
            continue;
        }
        $iconUrl = '/images/dos-door-icon.png'; // Default icon
        if (!empty($door['icon'])) {
            $iconUrl = "/door-assets/{$doorId}/icon";
        }

        $games[] = [
            'id' => $doorId,
            'name' => $door['name'],
            'description' => $door['description'] ?? '',
            'author' => $door['author'] ?? null,
            'version' => $door['game_version'] ?? null,
            'path' => $doorId,  // Will become /games/{doorid} (uses iframe wrapper)
            'icon_url' => $iconUrl,
            'type' => 'nativedoor',
            'genre' => $door['genre'] ?? [],
            'players' => $door['players'] ?? null
        ];
    }

    // Get JS-DOS Doors
    if (JsdosDoorConfig::isConfigPresent()) {
        foreach (JsdosDoorManifest::listManifests() as $entry) {
            $manifest = $entry['manifest'];
            $gameId = $entry['id'];

            if (!JsdosDoorConfig::isEnabled($gameId)) {
                continue;
            }

            $gameConfig = JsdosDoorConfig::getGameConfig($gameId);
            $name = $gameConfig['display_name'] ?? $manifest['name'] ?? $gameId;
            $description = $gameConfig['display_description'] ?? $manifest['description'] ?? '';
            $iconUrl = "/jsdos-doors/{$entry['path']}/" . ($manifest['icon'] ?? 'icon.png');

            $games[] = [
                'id'          => $gameId,
                'name'        => $name,
                'description' => $description,
                'author'      => $manifest['author'] ?? null,
                'version'     => $manifest['version'] ?? null,
                'path'        => $gameId,
                'icon_url'    => $iconUrl,
                'type'        => 'jsdosdoor',
            ];
        }
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

    $monthOffset = 0;
    if (isset($_GET['month_offset'])) {
        $monthOffset = (int)$_GET['month_offset'];
        if ($monthOffset < 0) {
            $monthOffset = 0;
        }
        if ($monthOffset > 120) {
            $monthOffset = 120;
        }
    }

    $monthStart = new \DateTimeImmutable('first day of this month 00:00:00');
    if ($monthOffset > 0) {
        $monthStart = $monthStart->modify("-{$monthOffset} months");
    }
    $monthEnd = $monthStart->modify('+1 month');
    $leaderboardMonthLabel = $monthStart->format('F Y');

    $leaderboard = [];
    try {
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $limit = 10;
        $stmt = $db->prepare('
            WITH best_scores AS (
                SELECT DISTINCT ON (l.user_id, l.game_id, l.board)
                    l.user_id, l.game_id, l.board, l.score, l.created_at
                FROM webdoor_leaderboards l
                WHERE l.created_at >= ?
                  AND l.created_at < ?
                ORDER BY l.user_id, l.game_id, l.board, l.score DESC, l.created_at DESC
            )
            SELECT b.game_id, b.board, u.real_name, u.username, b.score, b.created_at
            FROM best_scores b
            JOIN users u ON b.user_id = u.id
            ORDER BY b.score DESC, b.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $monthStart->format('Y-m-d H:i:s'));
        $stmt->bindValue(2, $monthEnd->format('Y-m-d H:i:s'));
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
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
        getServerLogger()->error('Failed to load WebDoor leaderboard: ' . $e->getMessage());
    }

    $template = new Template();
    $template->renderResponse('webdoors.twig', [
        'games' => $games,
        'leaderboard' => $leaderboard,
        'leaderboard_month_label' => $leaderboardMonthLabel,
        'leaderboard_month_offset' => $monthOffset
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
            'error_code' => 'ui.webdoors.errors.system_disabled'
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
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.webdoors.errors.admin_only'
        ]);
        return;
    }

    // Include the DOS door player
    $doorId = $doorid; // For the player script
    require __DIR__ . '/../public_html/webdoors/dosdoors/index.php';
});

// GET /games/nativedoors/{doorid} - Play a native Linux door game
SimpleRouter::get('/games/nativedoors/{doorid}', function($doorid) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    if (GameConfig::isGameSystemEnabled() == false) {
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_code' => 'ui.webdoors.errors.system_disabled'
        ]);
        exit;
    }

    // Verify door exists and is enabled
    $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
    $door = $nativeDoorManager->getDoor($doorid);

    if (!$door || empty($door['config']['enabled'])) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/nativedoors/{$doorid}"
        ]);
        return;
    }

    // Block admin-only doors for non-admins
    if (!empty($door['admin_only']) && empty($user['is_admin'])) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.webdoors.errors.admin_only'
        ]);
        return;
    }

    // Reuse the DOS door terminal player (same WebSocket protocol)
    $doorId = $doorid;
    require __DIR__ . '/../public_html/webdoors/dosdoors/index.php';
});

// GET /games/jsdos/{gameId} - JS-DOS door player (or coming-soon for custom emulators)
SimpleRouter::get('/games/jsdos/{gameId}', function(string $gameId) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    if (GameConfig::isGameSystemEnabled() == false) {
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_code' => 'ui.webdoors.errors.system_disabled'
        ]);
        exit;
    }

    $requestedMode = trim((string)($_GET['mode'] ?? 'play'));
    if ($requestedMode === '') {
        $requestedMode = 'play';
    }

    $entry = JsdosDoorManifest::getManifest($gameId);
    if (!$entry || !JsdosDoorConfig::isEnabled($entry['id'])) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/jsdos/{$gameId}"
        ]);
        return;
    }

    $resolved = resolveJsdosDoorRequest($gameId, $requestedMode, $user);
    if (!$resolved) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.webdoors.errors.admin_only'
        ]);
        return;
    }

    $entry = $resolved['entry'];
    $manifest = $resolved['manifest'];
    $mode = $resolved['mode'];
    $gameConfig = JsdosDoorConfig::getGameConfig($entry['id']);
    $name = $gameConfig['display_name'] ?? $manifest['name'] ?? $entry['id'];
    $description = $gameConfig['display_description'] ?? $manifest['description'] ?? '';
    $gameData = array_merge($manifest, ['name' => $name, 'description' => $description]);

    $emulator = $mode['emulator'] ?? ($manifest['emulator'] ?? 'jsdos');
    $template = new Template();

    if ($emulator === 'jsdos') {
        $template->renderResponse('jsdosdoor_play.twig', [
            'game'      => $gameData,
            'game_id'   => $entry['id'],
            'game_path' => $entry['path'],
            'mode'      => $mode,
            'mode_id'   => $requestedMode,
        ]);
    } else {
        // Phase 5: custom emulator support — show placeholder for now
        $template->renderResponse('jsdosdoor_coming_soon.twig', [
            'game'      => $gameData,
            'game_path' => $entry['path'],
        ]);
    }
});

// POST /api/jsdoor/session - Create a JS-DOS game session
SimpleRouter::post('/api/jsdoor/session', function() {
    header('Content-Type: application/json');

    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        webdoorApiError('errors.auth.authentication_required', 'Authentication required', 401);
        return;
    }
    if (!GameConfig::isGameSystemEnabled()) {
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $gameId = trim((string)($body['game_id'] ?? ''));
    $requestedMode = trim((string)($body['mode'] ?? 'play'));

    if ($gameId === '') {
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.game_not_found', 'Game ID required', 400);
        return;
    }

    if ($requestedMode === '') {
        $requestedMode = 'play';
    }

    $entry = JsdosDoorManifest::getManifest($gameId);
    if (!$entry || !JsdosDoorConfig::isEnabled($entry['id'])) {
        http_response_code(404);
        webdoorApiError('errors.jsdosdoor.game_not_found', 'Game not found', 404);
        return;
    }

    $resolved = resolveJsdosDoorRequest($gameId, $requestedMode, $user);
    if (!$resolved) {
        http_response_code(403);
        webdoorApiError('errors.door.admin_only', 'This door is restricted to administrators', 403);
        return;
    }

    $entry = $resolved['entry'];
    $manifest = $resolved['manifest'];
    $sessionCost = (int)($manifest['credits']['session_cost'] ?? 0);
    if ($sessionCost > 0) {
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.session_create_failed', 'Credit-gated sessions are not yet supported', 400);
        return;
    }

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $sessionId = bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', time() + 14400); // 4 hours

    try {
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        // Close any existing active jsdos session for this user + game
        $stmt = $db->prepare("
            UPDATE door_sessions
               SET ended_at = NOW()
             WHERE user_id = ?
               AND door_id = ?
               AND door_type = 'jsdos'
               AND ended_at IS NULL
        ");
        $stmt->execute([$userId, $entry['id']]);

        // Create new session record
        $stmt = $db->prepare("
            INSERT INTO door_sessions (session_id, user_id, door_id, expires_at, door_type, user_data)
            VALUES (?, ?, ?, ?, 'jsdos', ?::jsonb)
        ");
        $userDataJson = json_encode(['mode' => $requestedMode]);
        $stmt->execute([$sessionId, $userId, $entry['id'], $expiresAt, $userDataJson]);

        echo json_encode([
            'success'    => true,
            'session_id' => $sessionId,
            'expires_at' => $expiresAt,
            'mode'       => $requestedMode,
        ]);
    } catch (\Throwable $e) {
        getServerLogger()->error('Failed to create jsdos session: ' . $e->getMessage());
        http_response_code(500);
        webdoorApiError('errors.jsdosdoor.session_create_failed', 'Failed to create session', 500);
    }
});

// POST /api/jsdoor/session/{sessionId}/end - End a JS-DOS game session
SimpleRouter::post('/api/jsdoor/session/{sessionId}/end', function(string $sessionId) {
    header('Content-Type: application/json');

    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        webdoorApiError('errors.auth.authentication_required', 'Authentication required', 401);
        return;
    }

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

    try {
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            UPDATE door_sessions
               SET ended_at = NOW()
             WHERE session_id = ?
               AND user_id = ?
               AND door_type = 'jsdos'
               AND ended_at IS NULL
        ");
        $stmt->execute([$sessionId, $userId]);

        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        getServerLogger()->error('Failed to end jsdos session: ' . $e->getMessage());
        http_response_code(500);
        webdoorApiError('errors.jsdosdoor.session_end_failed', 'Failed to end session', 500);
    }
});

// GET /api/jsdoor/files/{gameId} - Load JS-DOS synced files for the active session
SimpleRouter::get('/api/jsdoor/files/{gameId}', function(string $gameId) {
    header('Content-Type: application/json');

    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        webdoorApiError('errors.auth.authentication_required', 'Authentication required', 401);
        return;
    }

    $sessionId = trim((string)($_GET['session_id'] ?? ''));
    if ($sessionId === '') {
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.session_create_failed', 'Session ID required', 400);
        return;
    }

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $session = loadJsdosSessionForUser($sessionId, $userId);
    if (!$session || (string)$session['door_id'] !== $gameId) {
        http_response_code(404);
        webdoorApiError('errors.jsdosdoor.game_not_found', 'Active session not found', 404);
        return;
    }

    $resolved = resolveJsdosDoorRequest($gameId, (string)$session['mode'], $user);
    if (!$resolved) {
        http_response_code(403);
        webdoorApiError('errors.door.admin_only', 'This door is restricted to administrators', 403);
        return;
    }

    $manifest = $resolved['manifest'];
    $mode = $resolved['mode'];
    $modeSaveConfig = JsdosDoorSupport::getSaveConfig($mode);
    $files = [];

    foreach (JsdosDoorSupport::getSharedSaveModes($manifest) as $sharedMode) {
        $sharedFiles = JsdosDoorSupport::loadStoredFiles(
            JsdosDoorSupport::getSharedStorageDirectory($gameId),
            $sharedMode['saves']
        );
        foreach ($sharedFiles as $dosPath => $contents) {
            $files[$dosPath] = $contents;
        }
    }

    if (!empty($modeSaveConfig['enabled'])) {
        $baseDir = $modeSaveConfig['scope'] === 'shared'
            ? JsdosDoorSupport::getSharedStorageDirectory($gameId)
            : JsdosDoorSupport::getUserStorageDirectory($userId, $gameId);
        $modeFiles = JsdosDoorSupport::loadStoredFiles($baseDir, $modeSaveConfig);
        foreach ($modeFiles as $dosPath => $contents) {
            $files[$dosPath] = $contents;
        }
    }

    echo json_encode([
        'success' => true,
        'files' => array_map(static function(string $dosPath, string $contents): array {
            return [
                'dos_path' => $dosPath,
                'content_b64' => base64_encode($contents),
            ];
        }, array_keys($files), array_values($files)),
    ]);
});

// POST /api/jsdoor/files/{gameId} - Save modified JS-DOS files for the active session
SimpleRouter::post('/api/jsdoor/files/{gameId}', function(string $gameId) {
    header('Content-Type: application/json');

    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        webdoorApiError('errors.auth.authentication_required', 'Authentication required', 401);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $sessionId = trim((string)($input['session_id'] ?? ''));
    $payloadFiles = is_array($input['files'] ?? null) ? $input['files'] : [];
    $endSession = !empty($input['end_session']);

    if ($sessionId === '') {
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.session_create_failed', 'Session ID required', 400);
        return;
    }

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $session = loadJsdosSessionForUser($sessionId, $userId);
    if (!$session || (string)$session['door_id'] !== $gameId) {
        http_response_code(404);
        webdoorApiError('errors.jsdosdoor.game_not_found', 'Active session not found', 404);
        return;
    }

    $resolved = resolveJsdosDoorRequest($gameId, (string)$session['mode'], $user);
    if (!$resolved) {
        http_response_code(403);
        webdoorApiError('errors.door.admin_only', 'This door is restricted to administrators', 403);
        return;
    }

    $modeSaveConfig = JsdosDoorSupport::getSaveConfig($resolved['mode']);
    if (empty($modeSaveConfig['enabled'])) {
        if ($endSession) {
            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                UPDATE door_sessions
                   SET ended_at = NOW()
                 WHERE session_id = ?
                   AND user_id = ?
                   AND door_type = 'jsdos'
                   AND ended_at IS NULL
            ");
            $stmt->execute([$sessionId, $userId]);
        }
        echo json_encode(['success' => true, 'saved' => 0]);
        return;
    }

    $baseDir = $modeSaveConfig['scope'] === 'shared'
        ? JsdosDoorSupport::getSharedStorageDirectory($gameId)
        : JsdosDoorSupport::getUserStorageDirectory($userId, $gameId);

    try {
        $saved = 0;
        foreach ($payloadFiles as $payloadFile) {
            if (!is_array($payloadFile)) {
                continue;
            }

            $dosPath = trim((string)($payloadFile['dos_path'] ?? ''));
            if ($dosPath === '') {
                continue;
            }

            if (!empty($payloadFile['deleted'])) {
                JsdosDoorSupport::deleteStoredFile($baseDir, $modeSaveConfig, $dosPath);
                $saved++;
                continue;
            }

            $contentB64 = (string)($payloadFile['content_b64'] ?? '');
            $contents = base64_decode($contentB64, true);
            if ($contents === false) {
                throw new \RuntimeException('Invalid base64 file payload');
            }

            JsdosDoorSupport::writeStoredFile($baseDir, $modeSaveConfig, $dosPath, $contents);
            $saved++;
        }

        if ($endSession) {
            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                UPDATE door_sessions
                   SET ended_at = NOW()
                 WHERE session_id = ?
                   AND user_id = ?
                   AND door_type = 'jsdos'
                   AND ended_at IS NULL
            ");
            $stmt->execute([$sessionId, $userId]);
        }

        echo json_encode(['success' => true, 'saved' => $saved]);
    } catch (\Throwable $e) {
        getServerLogger()->error('Failed to save jsdos files: ' . $e->getMessage());
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.session_end_failed', 'Failed to save files', 400);
    }
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
            'error_code' => 'ui.webdoors.errors.system_disabled'
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
                'error_title_code' => 'ui.error.access_error',
                'error_code' => 'ui.webdoors.errors.admin_only'
            ]);
            return;
        }

        // This is a DOS door - render with embedded player
        $template = new Template();
        $template->renderResponse('dosdoor_play.twig', [
            'door' => $door,
            'door_id' => $game,
            'player_url' => "/games/dosdoors/{$game}"
        ]);
        return;
    }

    // Check if this is a native door
    $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
    $nativeDoor = $nativeDoorManager->getDoor($game);

    if ($nativeDoor && !empty($nativeDoor['config']['enabled'])) {
        // Block admin-only doors for non-admins
        if (!empty($nativeDoor['admin_only']) && empty($user['is_admin'])) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('error.twig', [
                'error_title_code' => 'ui.error.access_error',
                'error_code' => 'ui.webdoors.errors.admin_only'
            ]);
            return;
        }

        $template = new Template();
        $template->renderResponse('dosdoor_play.twig', [
            'door' => $nativeDoor,
            'door_id' => $game,
            'player_url' => "/games/nativedoors/{$game}"
        ]);
        return;
    }

    // Check if this is a JS-DOS door
    $jsdosEntry = JsdosDoorManifest::getManifest($game);
    if ($jsdosEntry && JsdosDoorConfig::isEnabled($jsdosEntry['id'])) {
        $requestedMode = trim((string)($_GET['mode'] ?? 'play'));
        if ($requestedMode === '') {
            $requestedMode = 'play';
        }

        $resolved = resolveJsdosDoorRequest($game, $requestedMode, $user);
        if (!$resolved) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('error.twig', [
                'error_title_code' => 'ui.error.access_error',
                'error_code' => 'ui.webdoors.errors.admin_only'
            ]);
            return;
        }

        $manifest = $resolved['manifest'];
        $mode = $resolved['mode'];
        $gameConfig = JsdosDoorConfig::getGameConfig($jsdosEntry['id']);
        $name = $gameConfig['display_name'] ?? $manifest['name'] ?? $jsdosEntry['id'];
        $description = $gameConfig['display_description'] ?? $manifest['description'] ?? '';
        $modeLabel = (string)($mode['label'] ?? '');
        $playerUrl = "/games/jsdos/{$jsdosEntry['id']}" . ($requestedMode !== 'play' ? ('?mode=' . urlencode($requestedMode)) : '');
        $secondaryUrl = null;
        $secondaryLabelKey = null;

        if (!empty($user['is_admin']) && JsdosDoorSupport::hasMode($manifest, 'config')) {
            if ($requestedMode === 'config') {
                $secondaryUrl = "/games/{$game}";
                $secondaryLabelKey = 'ui.webdoor_play.play_mode';
            } else {
                $secondaryUrl = "/games/{$game}?mode=config";
                $secondaryLabelKey = 'ui.webdoor_play.admin_config';
            }
        }

        $template = new Template();
        $template->renderResponse('dosdoor_play.twig', [
            'door' => [
                'name' => $requestedMode === 'play' || $modeLabel === '' ? $name : ($name . ' - ' . $modeLabel),
                'description' => $description,
            ],
            'door_id' => $game,
            'player_url' => $playerUrl,
            'secondary_url' => $secondaryUrl,
            'secondary_label_key' => $secondaryLabelKey,
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
            'error_code' => 'ui.webdoors.errors.requirements_not_met'
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
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
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
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
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
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
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
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->loadSave((int)$slot);

    if ($result === null) {
        webdoorApiError('errors.webdoor.save_not_found', 'Save not found', 404);
        return;
    }

    echo json_encode($result);
});

// PUT /api/webdoor/storage/{slot} - Save game data
SimpleRouter::put('/api/webdoor/storage/{slot}', function($slot) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
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
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
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
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
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
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->submitScore($board);

    echo json_encode($result);
});
