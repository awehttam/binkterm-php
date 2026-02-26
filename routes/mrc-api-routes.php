<?php

use BinktermPHP\Auth;
use BinktermPHP\Database;
use BinktermPHP\Mrc\MrcClient;
use BinktermPHP\Mrc\MrcConfig;

// MRC (Multi Relay Chat) API routes
SimpleRouter::group(['prefix' => '/api/webdoor/mrc'], function() {

    // Get MRC daemon status
    SimpleRouter::get('/status', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        try {
            $config = MrcConfig::getInstance();
            $db = Database::getInstance()->getPdo();

            // Get connection state from database
            $stmt = $db->prepare("SELECT value FROM mrc_state WHERE key = 'connected'");
            $stmt->execute();
            $connectedStr = $stmt->fetchColumn();
            $connected = ($connectedStr === 'true');

            echo json_encode([
                'success' => true,
                'enabled' => $config->isEnabled(),
                'connected' => $connected,
                'server' => $config->getServerHost() . ':' . $config->getServerPort(),
                'bbs_name' => $config->getBbsName()
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    // Get room list with metadata
    SimpleRouter::get('/rooms', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        try {
            $db = Database::getInstance()->getPdo();

            // Get rooms with user counts
            $stmt = $db->query("
                SELECT
                    r.room_name,
                    r.topic,
                    r.topic_set_by,
                    r.topic_set_at,
                    COUNT(u.id) as user_count,
                    r.last_activity
                FROM mrc_rooms r
                LEFT JOIN mrc_users u ON r.room_name = u.room_name
                GROUP BY r.room_name, r.topic, r.topic_set_by, r.topic_set_at, r.last_activity
                ORDER BY r.room_name
            ");

            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'rooms' => $rooms
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    // Get message history for a room
    SimpleRouter::get('/messages/{room}', function($room) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        try {
            $db = Database::getInstance()->getPdo();
            $config = MrcConfig::getInstance();

            $limit = isset($_GET['limit']) ? min(1000, (int)$_GET['limit']) : 100;
            $after = isset($_GET['after']) ? (int)$_GET['after'] : 0;

            // Get messages for room
            $stmt = $db->prepare("
                SELECT
                    id,
                    from_user,
                    from_site,
                    from_room,
                    to_user,
                    to_room,
                    message_body,
                    msg_ext,
                    is_private,
                    received_at
                FROM mrc_messages
                WHERE (to_room = :room OR from_room = :room)
                  AND id > :after
                  AND is_private = false
                ORDER BY received_at ASC, id ASC
                LIMIT :limit
            ");

            $stmt->bindValue(':room', $room, PDO::PARAM_STR);
            $stmt->bindValue(':after', $after, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'messages' => $messages
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    // Get users in a room
    SimpleRouter::get('/users/{room}', function($room) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        try {
            $db = Database::getInstance()->getPdo();

            // Get users in room
            $stmt = $db->prepare("
                SELECT
                    username,
                    bbs_name,
                    room_name,
                    ip_address,
                    connected_at,
                    last_seen,
                    is_afk,
                    afk_message
                FROM mrc_users
                WHERE room_name = :room
                ORDER BY username
            ");

            $stmt->execute(['room' => $room]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'users' => $users
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    // Send a message
    SimpleRouter::post('/send', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $room = $input['room'] ?? '';
            $message = $input['message'] ?? '';
            $toUser = $input['to_user'] ?? '';

            if (empty($room) || empty($message)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Room and message are required']);
                return;
            }

            $config = MrcConfig::getInstance();
            $db = Database::getInstance()->getPdo();

            // Sanitize username (replace spaces, remove tildes)
            $username = MrcClient::sanitizeName($user['username']);

            // Sanitize message (remove tildes, truncate)
            $message = str_replace('~', '', $message);
            $message = substr($message, 0, $config->getMaxMessageLength());

            $bbsName = $config->getBbsName();

            // Insert into outbound queue
            $stmt = $db->prepare("
                INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
                VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
            ");

            if (!empty($toUser)) {
                // Private message
                $stmt->execute([
                    'f1' => $username,
                    'f2' => $bbsName,
                    'f3' => '',
                    'f4' => $toUser,
                    'f5' => '',
                    'f6' => '',
                    'f7' => $message,
                    'priority' => 0
                ]);
            } else {
                // Room message
                $stmt->execute([
                    'f1' => $username,
                    'f2' => $bbsName,
                    'f3' => $room,
                    'f4' => '',
                    'f5' => '',
                    'f6' => $room,
                    'f7' => $message,
                    'priority' => 0
                ]);
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    // Join a room
    SimpleRouter::post('/join', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $room = $input['room'] ?? '';

            if (empty($room)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Room is required']);
                return;
            }

            $db = Database::getInstance()->getPdo();
            $mrcConfig = MrcConfig::getInstance();

            // Sanitize username and room names
            $username = MrcClient::sanitizeName($user['username']);
            $room     = MrcClient::sanitizeName($room);
            $fromRoom = MrcClient::sanitizeName($input['from_room'] ?? '');
            $bbsName  = MrcClient::sanitizeName($mrcConfig->getBbsName());

            // Queue NEWROOM command with high priority
            // Format: user~bbs~fromroom~SERVER~msgext~toroom~NEWROOM:oldroom:newroom~
            $stmt = $db->prepare("
                INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
                VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
            ");

            $stmt->execute([
                'f1' => $username,
                'f2' => $bbsName,
                'f3' => $fromRoom,
                'f4' => 'SERVER',
                'f5' => '',
                'f6' => $room,
                'f7' => "NEWROOM:{$fromRoom}:{$room}",
                'priority' => 10 // High priority
            ]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });
});
