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
            // Validate that the processes are still running
            $bridgePid = $existingSession['bridge_pid'];
            $dosboxPid = $existingSession['dosbox_pid'];

            $bridgeRunning = $sessionManager->isProcessRunning($bridgePid);
            $dosboxRunning = $sessionManager->isProcessRunning($dosboxPid);

            error_log("DOSDOOR: [API] Existing session found - Bridge running: " . ($bridgeRunning ? 'yes' : 'no') . ", DOSBox running: " . ($dosboxRunning ? 'yes' : 'no'));

            if ($bridgeRunning && $dosboxRunning) {
                // Processes are alive, resume existing session
                echo json_encode([
                    'success' => true,
                    'session' => $existingSession,
                    'message' => 'Resuming existing session'
                ]);
                return;
            } else {
                // Processes are dead, clean up stale session
                error_log("DOSDOOR: [API] Cleaning up stale session: {$existingSession['session_id']}");
                $sessionManager->endSession($existingSession['session_id']);
                // Continue to create new session below
            }
        }

        // Start new session
        $session = $sessionManager->startSession($userId, $doorName, $userData);

        echo json_encode([
            'success' => true,
            'session' => [
                'session_id' => $session['session_id'],
                'door_name' => $session['door_name'],
                'node' => $session['node'],
                'ws_port' => $session['ws_port'],
                'tcp_port' => $session['tcp_port'],
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
            error_log("DOSDOOR: [GetSession] Found session: {$session['session_id']} for user $userId, session user_id: {$session['user_id']}");

            // Validate that processes are still running
            $bridgePid = $session['bridge_pid'];
            $dosboxPid = $session['dosbox_pid'];

            $bridgeRunning = $sessionManager->isProcessRunning($bridgePid);
            $dosboxRunning = $sessionManager->isProcessRunning($dosboxPid);

            error_log("DOSDOOR: [GetSession] Validation - Bridge: " . ($bridgeRunning ? 'alive' : 'dead') . ", DOSBox: " . ($dosboxRunning ? 'alive' : 'dead'));

            if (!$bridgeRunning || !$dosboxRunning) {
                // Processes are dead, clean up stale session
                error_log("DOSDOOR: [GetSession] Cleaning up stale session: {$session['session_id']}");
                $sessionManager->endSession($session['session_id']);
                $session = null; // Treat as no session
            }
        } else {
            error_log("DOSDOOR: [GetSession] No session found for user $userId");
        }

        if ($session) {
            echo json_encode([
                'success' => true,
                'session' => [
                    'session_id' => $session['session_id'],
                    'door_name' => $session['door_name'],
                    'node' => $session['node'],
                    'ws_port' => $session['ws_port'],
                    'tcp_port' => $session['tcp_port'],
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
