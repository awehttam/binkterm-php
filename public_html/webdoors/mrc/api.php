<?php

/**
 * MRC Chat WebDoor - API Endpoint
 *
 * Self-contained API for the MRC Chat WebDoor.
 * Routed via ?action=<action>.
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Database;
use BinktermPHP\Mrc\MrcClient;
use BinktermPHP\Mrc\MrcConfig;

header('Content-Type: application/json');

$user = \WebDoorSDK\requireAuth();
$db   = \WebDoorSDK\getDatabase();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'status':   handleStatus($db);           break;
        case 'rooms':    handleRooms($db);             break;
        case 'messages': handleMessages($db, $user);   break;
        case 'users':    handleUsers($db);             break;
        case 'send':     handleSend($db, $user);       break;
        case 'join':     handleJoin($db, $user);       break;
        default:
            \WebDoorSDK\jsonError('Unknown action', 400);
    }
} catch (Exception $e) {
    \WebDoorSDK\jsonError($e->getMessage(), 500);
}

// ============================================================

function handleStatus(PDO $db): void
{
    $config = MrcConfig::getInstance();

    $stmt = $db->prepare("SELECT value FROM mrc_state WHERE key = 'connected'");
    $stmt->execute();
    $connected = ($stmt->fetchColumn() === 'true');

    \WebDoorSDK\jsonResponse([
        'success'  => true,
        'enabled'  => $config->isEnabled(),
        'connected' => $connected,
        'server'   => $config->getServerHost() . ':' . $config->getServerPort(),
        'bbs_name' => $config->getBbsName()
    ]);
}

function handleRooms(PDO $db): void
{
    $stmt = $db->query("
        SELECT
            r.room_name,
            r.topic,
            r.topic_set_by,
            r.topic_set_at,
            COUNT(u.id) AS user_count,
            r.last_activity
        FROM mrc_rooms r
        LEFT JOIN mrc_users u ON r.room_name = u.room_name
        GROUP BY r.room_name, r.topic, r.topic_set_by, r.topic_set_at, r.last_activity
        ORDER BY r.room_name
    ");

    \WebDoorSDK\jsonResponse([
        'success' => true,
        'rooms'   => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
}

function handleMessages(PDO $db, array $user): void
{
    $room  = $_GET['room']  ?? '';
    $limit = isset($_GET['limit']) ? min(1000, (int)$_GET['limit']) : 100;
    $after = isset($_GET['after'])  ? (int)$_GET['after']           : 0;

    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }

    $stmt = $db->prepare("
        SELECT
            id, from_user, from_site, from_room, to_user, to_room,
            message_body, msg_ext, is_private, received_at
        FROM mrc_messages
        WHERE (to_room = :room OR from_room = :room)
          AND id > :after
          AND is_private = false
        ORDER BY received_at ASC, id ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':room',  $room,  PDO::PARAM_STR);
    $stmt->bindValue(':after', $after, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Heartbeat: upsert local user presence so IAMHERE keepalives keep flowing.
    // Using upsert (not just UPDATE) so presence survives daemon restarts.
    $config        = MrcConfig::getInstance();
    $localUsername = MrcClient::sanitizeName($user['username']);
    $localBbsName  = MrcClient::sanitizeName($config->getBbsName());

    $db->prepare("
        INSERT INTO mrc_rooms (room_name, last_activity)
        VALUES (:room, CURRENT_TIMESTAMP)
        ON CONFLICT (room_name) DO UPDATE SET last_activity = CURRENT_TIMESTAMP
    ")->execute(['room' => $room]);

    $db->prepare("
        INSERT INTO mrc_users (username, bbs_name, room_name, is_local, last_seen)
        VALUES (:username, :bbs_name, :room, true, CURRENT_TIMESTAMP)
        ON CONFLICT (username, bbs_name, room_name) DO UPDATE
        SET is_local = true, last_seen = CURRENT_TIMESTAMP
    ")->execute(['username' => $localUsername, 'bbs_name' => $localBbsName, 'room' => $room]);

    \WebDoorSDK\jsonResponse(['success' => true, 'messages' => $messages]);
}

function handleUsers(PDO $db): void
{
    $room = $_GET['room'] ?? '';
    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }

    $stmt = $db->prepare("
        SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen, is_afk, afk_message
        FROM mrc_users
        WHERE room_name = :room
        ORDER BY username
    ");
    $stmt->execute(['room' => $room]);

    \WebDoorSDK\jsonResponse(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleSend(PDO $db, array $user): void
{
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $room    = $input['room']    ?? '';
    $message = $input['message'] ?? '';
    $toUser  = $input['to_user'] ?? '';

    if (empty($room) || empty($message)) {
        \WebDoorSDK\jsonError('Room and message are required');
    }

    $config   = MrcConfig::getInstance();
    $username = MrcClient::sanitizeName($user['username']);
    $message  = str_replace('~', '', $message);
    $message  = substr($message, 0, $config->getMaxMessageLength());
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());

    $stmt = $db->prepare("
        INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
        VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
    ");

    // MRC spec (Field 7): Word 1 = handle of the user, Word 2+ = chat message.
    // Other clients (e.g. Mystic, ZOC) read W1 as the sender's name and
    // display it as "W1: rest".  Without this prefix, the first word of the
    // message text is treated as the sender name.
    $roomMessage    = '|03<|02' . $username . '|03> ' . $message;

    if (!empty($toUser)) {
        $stmt->execute([
            'f1' => $username, 'f2' => $bbsName, 'f3' => '',
            'f4' => $toUser,   'f5' => '',        'f6' => '',
            'f7' => $message,  'priority' => 0
        ]);
    } else {
        $stmt->execute([
            'f1' => $username, 'f2' => $bbsName, 'f3' => $room,
            'f4' => '',        'f5' => '',        'f6' => $room,
            'f7' => $roomMessage, 'priority' => 0
        ]);
    }

    \WebDoorSDK\jsonResponse(['success' => true]);
}

function handleJoin(PDO $db, array $user): void
{
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $room     = $input['room']      ?? '';
    $fromRoom = $input['from_room'] ?? '';

    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }

    $config   = MrcConfig::getInstance();
    $username = MrcClient::sanitizeName($user['username']);
    $room     = MrcClient::sanitizeName($room);
    $fromRoom = MrcClient::sanitizeName($fromRoom);
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());

    // Queue NEWROOM command for the daemon to send
    $db->prepare("
        INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
        VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
    ")->execute([
        'f1' => $username, 'f2' => $bbsName, 'f3' => $fromRoom,
        'f4' => 'SERVER',  'f5' => '',        'f6' => $room,
        'f7' => "NEWROOM:{$fromRoom}:{$room}", 'priority' => 10
    ]);

    // Track user as local presence so IAMHERE keepalives are sent for them
    $db->prepare("
        INSERT INTO mrc_rooms (room_name, last_activity)
        VALUES (:room, CURRENT_TIMESTAMP)
        ON CONFLICT (room_name) DO UPDATE SET last_activity = CURRENT_TIMESTAMP
    ")->execute(['room' => $room]);

    $db->prepare("
        INSERT INTO mrc_users (username, bbs_name, room_name, is_local, last_seen)
        VALUES (:username, :bbs_name, :room, true, CURRENT_TIMESTAMP)
        ON CONFLICT (username, bbs_name, room_name) DO UPDATE
        SET is_local = true, last_seen = CURRENT_TIMESTAMP
    ")->execute(['username' => $username, 'bbs_name' => $bbsName, 'room' => $room]);

    \WebDoorSDK\jsonResponse(['success' => true]);
}
