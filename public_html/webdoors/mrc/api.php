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
        case 'status':   handleStatus($db);                  break;
        case 'rooms':    handleRooms($db);                   break;
        case 'messages': handleMessages($db, $user);          break;
        case 'private':  handlePrivateMessages($db, $user);   break;
        case 'private_unread': handlePrivateUnread($db, $user); break;
        case 'heartbeat': handleHeartbeat($db, $user);        break;
        case 'poll':     handlePoll($db, $user);              break;
        case 'command':  handleCommand($db, $user);           break;
        case 'users':    handleUsers($db);                  break;
        case 'send':     handleSend($db, $user);            break;
        case 'join':     handleJoin($db, $user);            break;
        case 'room_cursor': handleRoomCursor($db);           break;
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
    if (strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
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

    \WebDoorSDK\jsonResponse(['success' => true, 'messages' => $messages]);
}

function handlePrivateMessages(PDO $db, array $user): void
{
    $with  = $_GET['with']  ?? '';
    $limit = isset($_GET['limit']) ? min(1000, (int)$_GET['limit']) : 100;
    $after = isset($_GET['after'])  ? (int)$_GET['after']           : 0;

    if (empty($with)) {
        \WebDoorSDK\jsonError('Private chat user is required');
    }
    if (strpos($with, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in user');
    }

    $config   = MrcConfig::getInstance();
    $username = MrcClient::sanitizeName($user['username']);
    $withUser = MrcClient::sanitizeName($with);

    $stmt = $db->prepare("
        SELECT
            id, from_user, from_site, from_room, to_user, to_room,
            message_body, msg_ext, is_private, received_at
        FROM mrc_messages
        WHERE is_private = true
          AND id > :after
          AND (
            (from_user = :me AND to_user = :with_user)
            OR (from_user = :with_user AND to_user = :me)
          )
        ORDER BY received_at ASC, id ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':me', $username, PDO::PARAM_STR);
    $stmt->bindValue(':with_user', $withUser, PDO::PARAM_STR);
    $stmt->bindValue(':after', $after, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    \WebDoorSDK\jsonResponse(['success' => true, 'messages' => $messages]);
}

function handlePrivateUnread(PDO $db, array $user): void
{
    $after = isset($_GET['after']) ? (int)$_GET['after'] : 0;
    $username = MrcClient::sanitizeName($user['username']);

    $stmt = $db->prepare("
        SELECT id, from_user
        FROM mrc_messages
        WHERE is_private = true
          AND to_user = :me
          AND id > :after
        ORDER BY id ASC
    ");
    $stmt->bindValue(':me', $username, PDO::PARAM_STR);
    $stmt->bindValue(':after', $after, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = [];
    $latestId = $after;

    foreach ($rows as $row) {
        $from = $row['from_user'] ?? '';
        if ($from !== '') {
            $counts[$from] = ($counts[$from] ?? 0) + 1;
        }
        $latestId = max($latestId, (int)$row['id']);
    }

    \WebDoorSDK\jsonResponse([
        'success' => true,
        'latest_id' => $latestId,
        'counts' => $counts
    ]);
}

function handleHeartbeat(PDO $db, array $user): void
{
    $room = $_GET['room'] ?? '';
    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }
    if (strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    upsertLocalPresence($db, $user, $room);
    \WebDoorSDK\jsonResponse(['success' => true]);
}

function upsertLocalPresence(PDO $db, array $user, string $room): void
{
    $config        = MrcConfig::getInstance();
    $localUsername = MrcClient::sanitizeName($user['username']);
    $localBbsName  = MrcClient::sanitizeName($config->getBbsName());
    $room          = MrcClient::sanitizeName($room);

    $db->prepare("
        INSERT INTO mrc_rooms (room_name, last_activity)
        VALUES (:room, CURRENT_TIMESTAMP)
        ON CONFLICT (room_name) DO UPDATE SET last_activity = CURRENT_TIMESTAMP
    ")->execute(['room' => $room]);

    $db->prepare("
        INSERT INTO mrc_local_presence (username, bbs_name, room_name, last_seen)
        VALUES (:username, :bbs_name, :room, CURRENT_TIMESTAMP)
        ON CONFLICT (username, bbs_name, room_name) DO UPDATE
        SET last_seen = CURRENT_TIMESTAMP
    ")->execute(['username' => $localUsername, 'bbs_name' => $localBbsName, 'room' => $room]);
}

function handlePoll(PDO $db, array $user): void
{
    $viewMode = $_GET['view_mode'] ?? 'room';
    $viewRoom = $_GET['view_room'] ?? '';
    $joinRoom = $_GET['join_room'] ?? '';
    $withUser = $_GET['with_user'] ?? '';
    $after = isset($_GET['after']) ? (int)$_GET['after'] : 0;
    $afterPrivate = isset($_GET['after_private']) ? (int)$_GET['after_private'] : 0;
    $afterUnread = isset($_GET['after_unread']) ? (int)$_GET['after_unread'] : 0;
    $unreadInit = isset($_GET['unread_init']) && $_GET['unread_init'] === '1';
    $includeRooms = isset($_GET['include_rooms']) && $_GET['include_rooms'] === '1';

    if (!empty($viewRoom) && strpos($viewRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($joinRoom) && strpos($joinRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($withUser) && strpos($withUser, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in user');
    }

    $response = ['success' => true];

    // Messages for current view
    if ($viewMode === 'private' && $withUser !== '') {
        $username = MrcClient::sanitizeName($user['username']);
        $withUser = MrcClient::sanitizeName($withUser);

            $stmt = $db->prepare("
                SELECT
                    id, from_user, from_site, from_room, to_user, to_room,
                    message_body, msg_ext, is_private, received_at
                FROM mrc_messages
                WHERE is_private = true
                  AND id > :after
                  AND (
                    (from_user = :me AND to_user = :with_user)
                    OR (from_user = :with_user AND to_user = :me)
                  )
                ORDER BY received_at ASC, id ASC
                LIMIT 200
            ");
        $stmt->bindValue(':me', $username, PDO::PARAM_STR);
        $stmt->bindValue(':with_user', $withUser, PDO::PARAM_STR);
        $stmt->bindValue(':after', $afterPrivate, PDO::PARAM_INT);
        $stmt->execute();
        $response['messages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['message_mode'] = 'private';
    } elseif ($viewRoom !== '') {
        $viewRoom = MrcClient::sanitizeName($viewRoom);
        $stmt = $db->prepare("
            SELECT
                id, from_user, from_site, from_room, to_user, to_room,
                message_body, msg_ext, is_private, received_at
            FROM mrc_messages
            WHERE (to_room = :room OR from_room = :room)
              AND id > :after
              AND is_private = false
            ORDER BY received_at ASC, id ASC
            LIMIT 200
        ");
        $stmt->bindValue(':room', $viewRoom, PDO::PARAM_STR);
        $stmt->bindValue(':after', $after, PDO::PARAM_INT);
        $stmt->execute();
        $response['messages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['message_mode'] = 'room';
    } else {
        $response['messages'] = [];
        $response['message_mode'] = $viewMode === 'private' ? 'private' : 'room';
    }

    // Private unread counts
    if (!empty($user['username'])) {
        $username = MrcClient::sanitizeName($user['username']);
        if ($unreadInit && $afterUnread === 0) {
            $stmt = $db->prepare("
                SELECT COALESCE(MAX(id), 0) AS max_id
                FROM mrc_messages
                WHERE is_private = true
                  AND to_user = :me
            ");
            $stmt->bindValue(':me', $username, PDO::PARAM_STR);
            $stmt->execute();
            $latestId = (int)$stmt->fetchColumn();
            $response['private_unread'] = [
                'latest_id' => $latestId,
                'counts' => []
            ];
        } else {
            $stmt = $db->prepare("
                SELECT id, from_user
                FROM mrc_messages
                WHERE is_private = true
                  AND to_user = :me
                  AND id > :after
                ORDER BY id ASC
            ");
            $stmt->bindValue(':me', $username, PDO::PARAM_STR);
            $stmt->bindValue(':after', $afterUnread, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $counts = [];
            $latestId = $afterUnread;
            foreach ($rows as $row) {
                $from = $row['from_user'] ?? '';
                if ($from !== '') {
                    $counts[$from] = ($counts[$from] ?? 0) + 1;
                }
                $latestId = max($latestId, (int)$row['id']);
            }

            $response['private_unread'] = [
                'latest_id' => $latestId,
                'counts' => $counts
            ];
        }
    }

    // Users list for joined room
    if ($joinRoom !== '') {
        $joinRoom = MrcClient::sanitizeName($joinRoom);
        $stmt = $db->prepare("
            SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen, is_afk, afk_message
            FROM mrc_users
            WHERE room_name = :room
            ORDER BY username
        ");
        $stmt->execute(['room' => $joinRoom]);
        $response['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $response['users'] = [];
    }

    // Rooms list (optional)
    if ($includeRooms) {
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
        $response['rooms'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Heartbeat for joined room
    if ($joinRoom !== '') {
        upsertLocalPresence($db, $user, $joinRoom);
    }

    \WebDoorSDK\jsonResponse($response);
}

function handleUsers(PDO $db): void
{
    $room = $_GET['room'] ?? '';
    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }
    if (strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
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

function handleCommand(PDO $db, array $user): void
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $command = strtolower(trim($input['command'] ?? ''));
    $room = $input['room'] ?? '';

    if ($command === '') {
        \WebDoorSDK\jsonError('Command is required');
    }
    if (strpos($command, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in command');
    }
    if (!in_array($command, ['motd', 'rooms', 'topic', 'register', 'identify', 'update'], true)) {
        \WebDoorSDK\jsonError('Unsupported command');
    }
    // register/identify/update can be sent without a room (f3 will be empty)
    $roomOptional = in_array($command, ['rooms', 'motd', 'register', 'identify', 'update'], true);
    if (!$roomOptional) {
        if (empty($room)) {
            \WebDoorSDK\jsonError('Room is required');
        }
    }
    if (!empty($room) && strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    $config   = MrcConfig::getInstance();
    $username = MrcClient::sanitizeName($user['username']);
    $room     = $command === 'rooms' ? '' : MrcClient::sanitizeName($room);
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());

    $commandArgs = $input['args'] ?? [];
    $commandArgs = is_array($commandArgs) ? $commandArgs : [];
    $commandArgs = array_map('trim', $commandArgs);
    $commandArgs = array_values(array_filter($commandArgs, 'strlen'));

    // Build f7 and f6 per command.
    // REGISTER/IDENTIFY/UPDATE use f6='' (personal commands, not room-targeted).
    $f6 = $room;
    $f7 = '';

    switch ($command) {
        case 'motd':
            $f7 = 'MOTD';
            break;

        case 'rooms':
            $f7 = 'LIST';
            $f6 = '';
            break;

        case 'topic':
            if (empty($commandArgs)) {
                \WebDoorSDK\jsonError('Topic text is required');
            }
            $topicText = implode(' ', $commandArgs);
            $topicText = str_replace('~', '', $topicText);
            $topicText = preg_replace('/\\|[0-9A-Fa-f]{2}/', '', $topicText);
            $topicText = preg_replace('/\\|[A-Za-z]{2}/', '', $topicText);
            $topicText = trim(substr($topicText, 0, 55));
            if ($topicText === '') {
                \WebDoorSDK\jsonError('Topic text is required');
            }
            $f7 = "NEWTOPIC:{$room}:{$topicText}";
            break;

        case 'register':
            if (empty($commandArgs)) {
                $f7 = 'REGISTER';
            } else {
                $password = substr(str_replace(['~', ' '], '', $commandArgs[0]), 0, 20);
                $email = '';
                if (!empty($commandArgs[1])) {
                    $email = substr(str_replace(['~', ' '], '', $commandArgs[1]), 0, 128);
                }
                $f7 = 'REGISTER' . ($password !== '' ? ' ' . $password : '') . ($email !== '' ? ' ' . $email : '');
            }
            $f6 = '';
            break;

        case 'identify':
            if (empty($commandArgs)) {
                $f7 = 'IDENTIFY';
            } else {
                $password = substr(str_replace(['~', ' '], '', $commandArgs[0]), 0, 20);
                $f7 = 'IDENTIFY' . ($password !== '' ? ' ' . $password : '');
            }
            $f6 = '';
            break;

        case 'update':
            if (empty($commandArgs)) {
                $f7 = 'UPDATE';
            } else {
                $param = strtoupper(str_replace(['~', ' '], '', $commandArgs[0]));
                $value = isset($commandArgs[1]) ? substr(str_replace('~', '', $commandArgs[1]), 0, 128) : '';
                $f7 = 'UPDATE' . ($param !== '' ? ' ' . $param : '') . ($value !== '' ? ' ' . $value : '');
            }
            $f6 = '';
            break;
    }

    $db->prepare("
        INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
        VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
    ")->execute([
        'f1' => $command === 'rooms' ? $bbsName : $username,
        'f2' => $bbsName,
        'f3' => $room,
        'f4' => 'SERVER',
        'f5' => '',
        'f6' => $f6,
        'f7' => $f7,
        'priority' => 5
    ]);

    if ($command === 'rooms') {
        $db->prepare("
            INSERT INTO mrc_state (key, value, updated_at)
            VALUES ('list_refresh_started', :value, CURRENT_TIMESTAMP)
            ON CONFLICT (key) DO UPDATE
            SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
        ")->execute(['value' => (string)time()]);

        $db->prepare("
            INSERT INTO mrc_state (key, value, updated_at)
            VALUES ('list_refresh_pending', 'true', CURRENT_TIMESTAMP)
            ON CONFLICT (key) DO UPDATE
            SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
        ")->execute();
    }

    \WebDoorSDK\jsonResponse(['success' => true]);
}

function handleSend(PDO $db, array $user): void
{
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $room    = $input['room']    ?? '';
    $message = $input['message'] ?? '';
    $toUser  = $input['to_user'] ?? '';

    if (empty($message) || (empty($room) && empty($toUser))) {
        \WebDoorSDK\jsonError('Message and room or user are required');
    }
    if (strpos($message, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in message');
    }
    if (!empty($room) && strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($toUser) && strpos($toUser, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in user');
    }

    $config   = MrcConfig::getInstance();
    $username = MrcClient::sanitizeName($user['username']);
    $message  = str_replace('~', '', $message);
    $message  = substr($message, 0, $config->getMaxMessageLength());
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());
    $toUser   = $toUser !== '' ? MrcClient::sanitizeName($toUser) : '';

    $stmt = $db->prepare("
        INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
        VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
    ");

    // MRC spec (Field 7): Word 1 = handle of the user, Word 2+ = chat message.
    // Other clients (e.g. Mystic, ZOC) read W1 as the sender's name and
    // display it as "W1: rest".  Without this prefix, the first word of the
    // message text is treated as the sender name.
    $formattedMessage = '|03<|02' . $username . '|03> ' . $message;

    if (!empty($toUser)) {
        $stmt->execute([
            'f1' => $username, 'f2' => $bbsName, 'f3' => '',
            'f4' => $toUser,   'f5' => '',        'f6' => '',
            'f7' => '|03<|02' . $username . '|03> (Private) ' . $message,
            'priority' => 0
        ]);
    } else {
        $stmt->execute([
            'f1' => $username, 'f2' => $bbsName, 'f3' => $room,
            'f4' => '',        'f5' => '',        'f6' => $room,
            'f7' => $formattedMessage, 'priority' => 0
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
    if (strpos($room, '~') !== false || strpos($fromRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    $config   = MrcConfig::getInstance();
    $username = MrcClient::sanitizeName($user['username']);
    $room     = MrcClient::sanitizeName($room);
    $fromRoom = MrcClient::sanitizeName($fromRoom);
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($clientIp !== '') {
        $clientIp = preg_replace('/[^0-9a-fA-F:\\.]/', '', $clientIp);
    }

    // Queue NEWROOM command for the daemon to send
    $db->prepare("
        INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
        VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
    ")->execute([
        'f1' => $username, 'f2' => $bbsName, 'f3' => $fromRoom,
        'f4' => 'SERVER',  'f5' => '',        'f6' => $room,
        'f7' => "NEWROOM:{$fromRoom}:{$room}", 'priority' => 10
    ]);

    if ($clientIp !== '') {
        $db->prepare("
            INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
            VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
        ")->execute([
            'f1' => $username, 'f2' => $bbsName, 'f3' => '',
            'f4' => 'SERVER',  'f5' => '',        'f6' => '',
            'f7' => "USERIP:{$clientIp}", 'priority' => 9
        ]);
    }

    // Track user as local presence so IAMHERE keepalives are sent for them
    $db->prepare("
        INSERT INTO mrc_rooms (room_name, last_activity)
        VALUES (:room, CURRENT_TIMESTAMP)
        ON CONFLICT (room_name) DO UPDATE SET last_activity = CURRENT_TIMESTAMP
    ")->execute(['room' => $room]);

    $db->prepare("
        INSERT INTO mrc_local_presence (username, bbs_name, room_name, ip_address, last_seen)
        VALUES (:username, :bbs_name, :room, :ip_address, CURRENT_TIMESTAMP)
        ON CONFLICT (username, bbs_name, room_name) DO UPDATE
        SET last_seen = CURRENT_TIMESTAMP,
            ip_address = COALESCE(EXCLUDED.ip_address, mrc_local_presence.ip_address)
    ")->execute(['username' => $username, 'bbs_name' => $bbsName, 'room' => $room, 'ip_address' => $clientIp]);

    $stmt = $db->prepare("
        SELECT COALESCE(MAX(id), 0) AS max_id
        FROM mrc_messages
        WHERE is_private = false
          AND (to_room = :room OR from_room = :room)
    ");
    $stmt->execute(['room' => $room]);
    $maxId = (int)$stmt->fetchColumn();

    \WebDoorSDK\jsonResponse([
        'success' => true,
        'last_message_id' => $maxId
    ]);
}

/**
 * Get the latest non-private message id for a room.
 */
function handleRoomCursor(PDO $db): void
{
    $room = $_GET['room'] ?? '';
    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }
    if (strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    $room = MrcClient::sanitizeName($room);
    $stmt = $db->prepare("
        SELECT COALESCE(MAX(id), 0) AS max_id
        FROM mrc_messages
        WHERE is_private = false
          AND (to_room = :room OR from_room = :room)
    ");
    $stmt->execute(['room' => $room]);
    $maxId = (int)$stmt->fetchColumn();

    \WebDoorSDK\jsonResponse([
        'success' => true,
        'last_message_id' => $maxId
    ]);
}

