<?php
/**
 * Door Game Routes
 *
 * API endpoints for launching and managing DOSBox door game sessions
 */

use BinktermPHP\DoorSessionManager;
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

        // Check if user already has an active session
        $existingSession = $sessionManager->getUserSession($userId);
        if ($existingSession) {
            // User already has an active session - return it
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

        // Ensure door exists in database (fallback sync)
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $doorManager = new \BinktermPHP\DoorManager();

        $stmt = $db->prepare("SELECT id FROM dosbox_doors WHERE door_id = ?");
        $stmt->execute([$doorName]);
        if (!$stmt->fetch()) {
            // Door not in database - try to sync it
            error_log("DOSDOOR: [API] Door '$doorName' not in database, attempting sync...");
            $syncResult = $doorManager->syncDoorsToDatabase();
            error_log("DOSDOOR: [API] Sync result: synced={$syncResult['synced']}, errors=" . json_encode($syncResult['errors']));

            // Check again after sync
            $stmt->execute([$doorName]);
            if (!$stmt->fetch()) {
                throw new \Exception("Door '$doorName' is not available or not enabled. Please contact the sysop.");
            }
        }

        // Block admin-only doors for non-admins
        $doorManifestCheck = $doorManager->getDoor($doorName);
        if ($doorManifestCheck && !empty($doorManifestCheck['admin_only']) && empty($user['is_admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied', 'message' => 'This door is restricted to administrators.']);
            return;
        }

        // Check credits requirement
        $doorConfigPath = __DIR__ . '/../config/dosdoors.json';
        if (file_exists($doorConfigPath)) {
            $doorConfigs = json_decode(file_get_contents($doorConfigPath), true);
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
        $doorManifest = $doorManager->getDoor($doorName);
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
        $session = $sessionManager->startSession($userId, $doorName, $userData);

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

    error_log("DOSDOOR: [GetSession] User ID: $userId, Username: " . ($user['username'] ?? 'unknown'));

    try {
        $sessionManager = new DoorSessionManager(null, true);
        $session = $sessionManager->getUserSession($userId);

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

    // Load door manifest to get the actual filename
    $doorManager = new \BinktermPHP\DoorManager();
    $door = $doorManager->getDoor($doorid);

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
    $doorPath = __DIR__ . "/../dosbox-bridge/dos/doors/{$doorid}/{$filename}";

    // Verify file exists
    if (!file_exists($doorPath) || !is_file($doorPath)) {
        http_response_code(404);
        echo "Asset file not found";
        return;
    }

    // Verify file is in the door directory (prevent traversal)
    $realPath = realpath($doorPath);
    $allowedBase = realpath(__DIR__ . "/../dosbox-bridge/dos/doors/{$doorid}");
    if (strpos($realPath, $allowedBase) !== 0) {
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
