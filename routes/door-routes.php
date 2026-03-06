<?php
/**
 * Door Game Routes
 *
 * API endpoints for launching and managing DOSBox door game sessions
 */

use BinktermPHP\ActivityTracker;
use BinktermPHP\DoorSessionManager;
use BinktermPHP\DoorManager;
use BinktermPHP\NativeDoorManager;
use BinktermPHP\Database;
use BinktermPHP\RouteHelper;
use Pecee\SimpleRouter\SimpleRouter;

// Launch a door game session
SimpleRouter::post('/api/door/launch', function() {
    header('Content-Type: application/json');

    // Require authentication
    $user = RouteHelper::requireAuth();
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    $doorName = $_POST['door'] ?? null;

    error_log("DOSDOOR: [API] User ID: $userId, Username: " . ($user['username'] ?? 'unknown') . ", Door: $doorName");

    if (!$doorName) {
        http_response_code(400);
        echo json_encode(['error' => 'Door name required']);
        return;
    }

    try {
        // Get BBS configuration for system name and sysop
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $sysopName = $binkpConfig->getSystemSysop();

        // Split sysop name into first/last for DOOR.SYS format
        $sysopParts = explode(' ', $sysopName, 2);
        $sysopFirst = $sysopParts[0] ?? 'Sysop';
        $sysopLast = $sysopParts[1] ?? '';

        // Prepare user data for drop file (using actual schema columns)
        $userData = [
            'id' => $userId,
            'real_name' => $user['username'], // Use username for door games
            'location' => 'BinktermPHP BBS', // Default location
            'security_level' => $user['is_admin'] ? 255 : 30,
            'total_logins' => 1, // Default
            'last_login' => date('Y-m-d H:i:s'),
            'ansi_enabled' => true, // Default to ANSI
            'bbs_name' => $systemName,
            'sysop_name' => $sysopName,
            'sysop_first' => $sysopFirst,
            'sysop_last' => $sysopLast,
        ];

        // Create session manager in headless (production) mode and start session
        $sessionManager = new DoorSessionManager(null, true);

        // Check if user already has an active session for this door
        $existingSession = $sessionManager->getUserSession($userId, $doorName);
        if ($existingSession) {
            // User already has an active session for this door - return it
            // Bridge v3 owns the lifecycle, so if it's in DB, it's active
            error_log("DOSDOOR: [API] Resuming existing session: {$existingSession['session_id']}");

            // Build WebSocket URL
            $wsUrl = \BinktermPHP\Config::env('DOSDOOR_WS_URL');
            if (empty($wsUrl)) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
                $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
                $port = $existingSession['ws_port'];
                $wsUrl = "{$protocol}://{$host}:{$port}";
            }

            echo json_encode([
                'success' => true,
                'session' => [
                    'session_id' => $existingSession['session_id'],
                    'door_name' => $existingSession['door_name'],
                    'node' => $existingSession['node'],
                    'ws_port' => $existingSession['ws_port'],
                    'ws_token' => $existingSession['ws_token'],
                    'ws_url' => $wsUrl,
                ],
                'message' => 'Resuming existing session'
            ]);
            return;
        }

        // Determine door type: check native doors first, then DOS doors
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
        $doorManager = new \BinktermPHP\DoorManager();

        $nativeDoor = $nativeDoorManager->getDoor($doorName);
        $doorType = $nativeDoor ? 'native' : 'dos';
        $activeDoorManager = $nativeDoor ? $nativeDoorManager : $doorManager;

        error_log("DOSDOOR: [API] Door '$doorName' detected as type: $doorType");

        // Ensure door exists in database (fallback sync)
        $stmt = $db->prepare("SELECT id FROM dosbox_doors WHERE door_id = ?");
        $stmt->execute([$doorName]);
        if (!$stmt->fetch()) {
            // Door not in database - try to sync it
            error_log("DOSDOOR: [API] Door '$doorName' not in database, attempting sync...");
            $syncResult = $activeDoorManager->syncDoorsToDatabase();
            error_log("DOSDOOR: [API] Sync result: synced={$syncResult['synced']}, errors=" . json_encode($syncResult['errors']));

            // Check again after sync
            $stmt->execute([$doorName]);
            if (!$stmt->fetch()) {
                throw new \Exception("Door '$doorName' is not available or not enabled. Please contact the sysop.");
            }
        }

        // Block admin-only doors for non-admins
        $doorManifestCheck = $activeDoorManager->getDoor($doorName);
        if ($doorManifestCheck && !empty($doorManifestCheck['admin_only']) && empty($user['is_admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied', 'message' => 'This door is restricted to administrators.']);
            return;
        }

        // Check credits requirement
        $configFile = $doorType === 'native'
            ? __DIR__ . '/../config/nativedoors.json'
            : __DIR__ . '/../config/dosdoors.json';

        if (file_exists($configFile)) {
            $doorConfigs = json_decode(file_get_contents($configFile), true);
            $doorConfig = $doorConfigs[$doorName] ?? null;

            if ($doorConfig && isset($doorConfig['credit_cost']) && $doorConfig['credit_cost'] > 0) {
                $creditCost = (int)$doorConfig['credit_cost'];

                // Check if credits system is enabled
                $userCredit = new \BinktermPHP\UserCredit($userId);
                if ($userCredit->isEnabled()) {
                    $currentBalance = $userCredit->getBalance();

                    if ($currentBalance < $creditCost) {
                        error_log("DOSDOOR: [API] Insufficient credits for $doorName - Required: $creditCost, Balance: $currentBalance");
                        http_response_code(402); // Payment Required
                        echo json_encode([
                            'success' => false,
                            'error' => 'Insufficient credits',
                            'message' => "This door costs $creditCost credits. You have $currentBalance credits.",
                            'required' => $creditCost,
                            'balance' => $currentBalance
                        ]);
                        return;
                    }

                    // Deduct credits
                    if (!$userCredit->deductCredits($creditCost, 'dosdoor_launch', "Launched door: $doorName")) {
                        error_log("DOSDOOR: [API] Failed to deduct credits for $doorName");
                        throw new \Exception("Failed to process credit payment. Please try again.");
                    }

                    error_log("DOSDOOR: [API] Deducted $creditCost credits for $doorName - New balance: " . $userCredit->getBalance());
                }
            }
        }

        // Check door's max_nodes limit (per-door concurrency limit)
        $doorManifest = $activeDoorManager->getDoor($doorName);
        if ($doorManifest && isset($doorManifest['max_nodes'])) {
            $maxNodes = (int)$doorManifest['max_nodes'];

            // Count active sessions for this specific door
            $stmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM door_sessions
                WHERE door_id = ? AND ended_at IS NULL
            ");
            $stmt->execute([$doorName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $activeSessions = (int)$result['count'];

            if ($activeSessions >= $maxNodes) {
                error_log("DOSDOOR: [API] Door '$doorName' at max capacity - Active: $activeSessions, Max: $maxNodes");
                http_response_code(503); // Service Unavailable
                echo json_encode([
                    'success' => false,
                    'error' => 'Door at capacity',
                    'message' => "This door is currently in use. Only $maxNodes player(s) allowed at a time. Please try again later.",
                    'active_sessions' => $activeSessions,
                    'max_nodes' => $maxNodes
                ]);
                return;
            }

            error_log("DOSDOOR: [API] Door '$doorName' capacity check passed - Active: $activeSessions, Max: $maxNodes");
        }

        // Start new session
        $session = $sessionManager->startSession($userId, $doorName, $userData, $doorType);

        ActivityTracker::track($userId, ActivityTracker::TYPE_DOSDOOR_PLAY, null, $doorName);

        // Build WebSocket URL for browser
        $wsUrl = \BinktermPHP\Config::env('DOSDOOR_WS_URL');
        if (empty($wsUrl)) {
            // Auto-detect: use current request protocol and hostname (without port)
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
            $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $port = $session['ws_port'];
            $wsUrl = "{$protocol}://{$host}:{$port}";
        }

        echo json_encode([
            'success' => true,
            'session' => [
                'session_id' => $session['session_id'],
                'door_name' => $session['door_name'],
                'node' => $session['node'],
                'ws_port' => $session['ws_port'],
                'ws_token' => $session['ws_token'],
                'ws_url' => $wsUrl,
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to start door session',
            'message' => $e->getMessage()
        ]);
    }
});

// Launch a native door session as an anonymous guest (no authentication required)
SimpleRouter::post('/api/door/guest/launch', function() {
    header('Content-Type: application/json');

    $doorName = $_POST['door'] ?? null;

    if (!$doorName) {
        http_response_code(400);
        echo json_encode(['error' => 'Door name required']);
        return;
    }

    try {
        // Only native doors support anonymous access
        $nativeDoorManager = new NativeDoorManager();
        $nativeDoor = $nativeDoorManager->getDoor($doorName);

        if (!$nativeDoor) {
            http_response_code(404);
            echo json_encode(['error' => 'Door not found']);
            return;
        }

        if (empty($nativeDoor['config']['enabled'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Door is not available']);
            return;
        }

        if (!\BinktermPHP\NativeDoorConfig::isAnonymousAllowed($doorName)) {
            http_response_code(403);
            echo json_encode(['error' => 'This door does not allow anonymous access']);
            return;
        }

        // Anonymous sessions must always be free
        if ((int)($nativeDoor['config']['credit_cost'] ?? 0) > 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Doors with a credit cost cannot be accessed anonymously']);
            return;
        }

        $guestUserId = \BinktermPHP\GuestUser::getId();
        if (!$guestUserId) {
            http_response_code(500);
            echo json_encode(['error' => 'Guest user not configured. Run php scripts/setup.php.']);
            return;
        }

        $db = \BinktermPHP\Database::getInstance()->getPdo();

        // Check guest concurrency limit for this door
        $guestMax = \BinktermPHP\NativeDoorConfig::getGuestMaxSessions($doorName);
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM door_sessions
            WHERE door_id = ? AND user_id = ? AND ended_at IS NULL
        ");
        $stmt->execute([$doorName, $guestUserId]);
        if ((int)$stmt->fetch(\PDO::FETCH_ASSOC)['count'] >= $guestMax) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error' => 'Guest sessions at capacity',
                'message' => "This door currently has $guestMax guest session(s) active. Please try again later.",
            ]);
            return;
        }

        // Check overall door max_nodes
        $maxNodes = (int)($nativeDoor['max_nodes'] ?? 10);
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM door_sessions WHERE door_id = ? AND ended_at IS NULL
        ");
        $stmt->execute([$doorName]);
        if ((int)$stmt->fetch(\PDO::FETCH_ASSOC)['count'] >= $maxNodes) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error' => 'Door at capacity',
                'message' => "This door is currently full ($maxNodes player(s) maximum). Please try again later.",
            ]);
            return;
        }

        // Build guest user data for drop file
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $sysopName  = $binkpConfig->getSystemSysop();
        $sysopParts = explode(' ', $sysopName, 2);

        $userData = [
            'id'            => $guestUserId,
            'real_name'     => 'Guest',
            'location'      => 'Anonymous',
            'security_level' => 5,
            'total_logins'  => 0,
            'last_login'    => date('Y-m-d H:i:s'),
            'ansi_enabled'  => true,
            'bbs_name'      => $systemName,
            'sysop_name'    => $sysopName,
            'sysop_first'   => $sysopParts[0] ?? 'Sysop',
            'sysop_last'    => $sysopParts[1] ?? '',
        ];

        $sessionManager = new DoorSessionManager(null, true);
        $session = $sessionManager->startSession($guestUserId, $doorName, $userData, 'native');

        $wsUrl = \BinktermPHP\Config::env('DOSDOOR_WS_URL');
        if (empty($wsUrl)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
            $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $wsUrl = "{$protocol}://{$host}:{$session['ws_port']}";
        }

        echo json_encode([
            'success' => true,
            'session' => [
                'session_id' => $session['session_id'],
                'door_name'  => $session['door_name'],
                'node'       => $session['node'],
                'ws_port'    => $session['ws_port'],
                'ws_token'   => $session['ws_token'],
                'ws_url'     => $wsUrl,
            ],
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'Failed to start guest door session',
            'message' => $e->getMessage(),
        ]);
    }
});

// End a door game session
SimpleRouter::post('/api/door/end', function() {
    header('Content-Type: application/json');

    // Require authentication
    $user = RouteHelper::requireAuth();

    $sessionId = $_POST['session_id'] ?? null;

    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['error' => 'Session ID required']);
        return;
    }

    try {
        $sessionManager = new DoorSessionManager(null, true);
        $session = $sessionManager->getSession($sessionId);

        // Verify session belongs to current user
        $userId = $user['user_id'] ?? $user['id'];
        if (!$session || $session['user_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        // End the session
        $success = $sessionManager->endSession($sessionId);

        echo json_encode([
            'success' => $success
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to end session',
            'message' => $e->getMessage()
        ]);
    }
});

// Get current user's active door session
SimpleRouter::get('/api/door/session', function() {
    header('Content-Type: application/json');

    // Require authentication
    $user = RouteHelper::requireAuth();
    $userId = $user['user_id'] ?? $user['id'];
    $doorId = $_GET['door'] ?? null;

    error_log("DOSDOOR: [GetSession] User ID: $userId, Username: " . ($user['username'] ?? 'unknown') . ", Door: " . ($doorId ?? 'any'));

    try {
        $sessionManager = new DoorSessionManager(null, true);
        $session = $sessionManager->getUserSession($userId, $doorId);

        if ($session) {
            error_log("DOSDOOR: [GetSession] Found session: {$session['session_id']} for user $userId");
            // Bridge v3 owns the entire lifecycle - no need to validate processes here
        } else {
            error_log("DOSDOOR: [GetSession] No session found for user $userId");
        }

        if ($session) {
            // Build WebSocket URL for browser
            $wsUrl = \BinktermPHP\Config::env('DOSDOOR_WS_URL');
            if (empty($wsUrl)) {
                // Auto-detect: use current request protocol and hostname (without port)
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
                $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
                $port = $session['ws_port'];
                $wsUrl = "{$protocol}://{$host}:{$port}";
            }

            echo json_encode([
                'success' => true,
                'session' => [
                    'session_id' => $session['session_id'],
                    'door_name' => $session['door_name'],
                    'node' => $session['node'],
                    'ws_port' => $session['ws_port'],
                    'ws_token' => $session['ws_token'],
                    'ws_url' => $wsUrl,
                    'started_at' => $session['started_at'],
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'session' => null
            ]);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get session',
            'message' => $e->getMessage()
        ]);
    }
});

// Public guest doors listing page
// GET /guest-doors — lists all anonymous-accessible native doors
SimpleRouter::get('/guest-doors', function() {
    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('guest_doors_page')) {
        http_response_code(404);
        $template = new \BinktermPHP\Template();
        $template->renderResponse('404.twig');
        return;
    }

    $nativeDoorManager = new NativeDoorManager();
    $allDoors = $nativeDoorManager->getAllDoors();

    $guestDoors = [];
    foreach ($allDoors as $doorId => $door) {
        if (!empty($door['config']['enabled']) && !empty($door['config']['allow_anonymous'])) {
            $guestDoors[] = $door;
        }
    }

    $template = new \BinktermPHP\Template();
    $template->renderResponse('guest_doors.twig', ['doors' => $guestDoors]);
});

// Public guest door player page
// GET /play/{doorid} — serves the xterm.js player for anonymous-accessible native doors
SimpleRouter::get('/play/{doorid}', function($doorid) {
    // Sanitize door ID
    $doorId = preg_replace('/[^a-zA-Z0-9_-]/', '', $doorid);

    if (empty($doorId)) {
        http_response_code(404);
        echo "Door not found";
        return;
    }

    // Verify door exists and allows anonymous access
    $nativeDoorManager = new NativeDoorManager();
    $door = $nativeDoorManager->getDoor($doorId);

    if (!$door) {
        http_response_code(404);
        echo "Door not found";
        return;
    }

    if (!$nativeDoorManager->isDoorAvailable($doorId)) {
        http_response_code(403);
        echo "This door is currently disabled";
        return;
    }

    if (!\BinktermPHP\NativeDoorConfig::isAnonymousAllowed($doorId)) {
        http_response_code(403);
        echo "This door does not allow guest access";
        return;
    }

    require __DIR__ . '/../public_html/guest-door-player.php';
});

// Serve door assets (icons, screenshots, etc.)
// Only serves assets explicitly declared in the door's manifest for security
SimpleRouter::get('/door-assets/{doorid}/{asset}', function($doorid, $asset) {
    // Sanitize door ID
    $doorid = preg_replace('/[^a-zA-Z0-9_-]/', '', $doorid);

    // Only allow specific asset types
    $allowedAssets = ['icon', 'screenshot'];
    if (!in_array($asset, $allowedAssets)) {
        http_response_code(404);
        echo "Invalid asset type";
        return;
    }

    // Load door manifest — check native doors first, then DOS doors
    $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
    $door = $nativeDoorManager->getDoor($doorid);

    if ($door) {
        $doorBasePath = __DIR__ . "/../native-doors/doors/{$doorid}";
    } else {
        $door = (new \BinktermPHP\DoorManager())->getDoor($doorid);
        $doorBasePath = __DIR__ . "/../dosbox-bridge/dos/DOORS/" . strtoupper($doorid);
    }

    if (!$door) {
        http_response_code(404);
        echo "Door not found";
        return;
    }

    // Get filename from manifest
    $filename = $door[$asset] ?? null;

    if (!$filename) {
        http_response_code(404);
        echo "Asset not defined in manifest";
        return;
    }

    // Build path to asset file (only using manifest-declared filename)
    $filename = basename($filename); // Extra safety
    $doorPath = $doorBasePath . "/{$filename}";

    // Verify file exists
    if (!file_exists($doorPath) || !is_file($doorPath)) {
        http_response_code(404);
        echo "Asset file not found";
        return;
    }

    // Verify file is in the door directory (prevent traversal)
    $realPath = realpath($doorPath);
    $allowedBase = realpath($doorBasePath);
    if ($allowedBase === false || strpos($realPath, $allowedBase) !== 0) {
        http_response_code(403);
        echo "Access denied";
        return;
    }

    // Determine MIME type
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeTypes = [
        'gif' => 'image/gif',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp',
        'ico' => 'image/x-icon'
    ];

    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

    // Serve the file
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($doorPath));
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
    readfile($doorPath);
});
