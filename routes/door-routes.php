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
        // Prepare user data for drop file (using actual schema columns)
        $userData = [
            'id' => $userId,
            'real_name' => $user['username'], // Use username for door games
            'location' => 'BinktermPHP BBS', // Default location
            'security_level' => $user['is_admin'] ? 255 : 30,
            'total_logins' => 1, // Default
            'last_login' => date('Y-m-d H:i:s'),
            'ansi_enabled' => true, // Default to ANSI
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
