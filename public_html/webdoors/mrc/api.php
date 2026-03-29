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
use BinktermPHP\Realtime\BinkStream;

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
        case 'longpoll': handleLongPoll($db, $user);         break;
        case 'command':  handleCommand($db, $user);           break;
        case 'users':    handleUsers($db);                  break;
        case 'send':       handleSend($db, $user);           break;
        case 'join':       handleJoin($db, $user);           break;
        case 'room_cursor': handleRoomCursor($db);           break;
        case 'connect':    handleConnect($db, $user);        break;
        case 'disconnect': handleDisconnect($db, $user);     break;
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

    $stmt = $db->prepare("SELECT key, value, updated_at FROM mrc_state WHERE key IN ('connected', 'daemon_heartbeat')");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stateMap = [];
    foreach ($rows as $row) {
        $stateMap[$row['key']] = $row;
    }

    $connected = ($stateMap['connected']['value'] ?? 'false') === 'true';

    // Consider the daemon running only if it has written a heartbeat within
    // the last 90 seconds (daemon writes every 30 s; allow 3 missed beats).
    $daemonRunning = false;
    if (!empty($stateMap['daemon_heartbeat']['updated_at'])) {
        $lastBeat = strtotime($stateMap['daemon_heartbeat']['updated_at']);
        $daemonRunning = $lastBeat !== false && (time() - $lastBeat) < 90;
    }

    \WebDoorSDK\jsonResponse([
        'success'        => true,
        'enabled'        => $config->isEnabled(),
        'connected'      => $connected,
        'daemon_running' => $daemonRunning,
        'server'         => $config->getServerHost() . ':' . $config->getServerPort(),
        'bbs_name'       => $config->getBbsName()
    ]);
}

/**
 * Query the room list, falling back to the configured default room if the
 * server returned no rooms (e.g. fresh connection before LIST has arrived).
 *
 * @return array<int, array<string, mixed>>
 */
function fetchRoomList(PDO $db): array
{
    // Count distinct users from both server-reported presence (mrc_users) and
    // local webdoor sessions (mrc_local_presence) to avoid undercounting.
    $stmt = $db->query("
        SELECT
            r.room_name,
            r.topic,
            r.topic_set_by,
            r.topic_set_at,
            r.last_activity,
            (
                SELECT COUNT(DISTINCT username)
                FROM (
                    SELECT username FROM mrc_users WHERE room_name = r.room_name
                    UNION
                    SELECT username FROM mrc_local_presence WHERE room_name = r.room_name
                ) all_users
            ) AS user_count
        FROM mrc_rooms r
        ORDER BY r.room_name
    ");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rooms)) {
        $defaultRoom = MrcConfig::getInstance()->getDefaultRoom();
        $rooms = [[
            'room_name'    => $defaultRoom,
            'topic'        => null,
            'topic_set_by' => null,
            'topic_set_at' => null,
            'user_count'   => 0,
            'last_activity' => null,
        ]];
    }

    return $rooms;
}

function handleRooms(PDO $db): void
{
    \WebDoorSDK\jsonResponse([
        'success' => true,
        'rooms'   => fetchRoomList($db)
    ]);
}

function normalizeMrcHandle(string $handle, array $user): string
{
    $handle = trim($handle);
    if ($handle === '') {
        $handle = (string)($user['username'] ?? '');
    }

    $handle = substr(MrcClient::sanitizeName($handle), 0, 30);
    if ($handle === '') {
        \WebDoorSDK\jsonError('Username is required');
    }

    if (in_array(strtoupper($handle), ['SERVER', 'CLIENT', 'NOTME'], true)) {
        \WebDoorSDK\jsonError('Invalid username');
    }

    return $handle;
}

function resolveMrcUsername(array $user): string
{
    $sessionHandle = isset($_SESSION['mrc_username']) ? (string)$_SESSION['mrc_username'] : '';
    return normalizeMrcHandle($sessionHandle, $user);
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
    $username = resolveMrcUsername($user);
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
    $username = resolveMrcUsername($user);

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

    upsertLocalHandle($db, $user);
    upsertLocalPresence($db, $user, $room);
    \WebDoorSDK\jsonResponse(['success' => true]);
}

/**
 * Normalize a user-entered MRC room name.
 * Accepts an optional leading '#' from the UI, then enforces the protocol's
 * room-name rules before anything is queued to the daemon.
 */
function normalizeMrcRoomName(string $room): string
{
    $room = trim($room);
    if (strncmp($room, '#', 1) === 0) {
        $room = substr($room, 1);
    }

    $room = MrcClient::sanitizeName($room);

    if ($room === '' || !preg_match('/^[A-Za-z0-9]{1,20}$/', $room)) {
        \WebDoorSDK\jsonError('Invalid room name');
    }

    return $room;
}

/**
 * Emit a fresh room presence payload to all remaining local users in a room.
 */
function emitPresenceForRoom(PDO $db, string $room): void
{
    $room = MrcClient::sanitizeName($room);
    if ($room === '') {
        return;
    }

    $userIdStmt = $db->prepare("
        SELECT DISTINCT user_id AS id
        FROM mrc_local_presence
        WHERE room_name = :room
          AND user_id IS NOT NULL
          AND last_seen > CURRENT_TIMESTAMP - INTERVAL '10 minutes'
    ");
    $userIdStmt->execute(['room' => $room]);
    $targetUserIds = array_map('intval', array_column($userIdStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    if (empty($targetUserIds)) {
        return;
    }

    $localBbs = MrcClient::sanitizeName(MrcConfig::getInstance()->getBbsName());
    $usersStmt = $db->prepare("
        SELECT username, COALESCE(bbs_name, 'unknown') AS bbs_name, false AS is_afk
        FROM mrc_users
        WHERE room_name = :room
        UNION
        SELECT username, :local_bbs AS bbs_name, false AS is_afk
        FROM mrc_local_presence
        WHERE room_name = :room2
          AND last_seen > CURRENT_TIMESTAMP - INTERVAL '10 minutes'
    ");
    $usersStmt->execute([
        'room' => $room,
        'local_bbs' => $localBbs,
        'room2' => $room,
    ]);
    $payload = [
        'room' => $room,
        'users' => $usersStmt->fetchAll(PDO::FETCH_ASSOC),
    ];

    foreach ($targetUserIds as $targetUserId) {
        BinkStream::emit($db, 'mrc_presence', $payload, $targetUserId);
    }
}

function upsertLocalHandle(PDO $db, array $user): void
{
    $config        = MrcConfig::getInstance();
    $localUsername = resolveMrcUsername($user);
    $localUserId   = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $localBbsName  = MrcClient::sanitizeName($config->getBbsName());

    if ($localUserId <= 0 || $localUsername === '') {
        return;
    }

    $db->prepare("
        INSERT INTO mrc_local_handles (user_id, username, bbs_name, connected_at, last_seen)
        VALUES (:user_id, :username, :bbs_name, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT (user_id) DO UPDATE
        SET username = EXCLUDED.username,
            bbs_name = EXCLUDED.bbs_name,
            last_seen = CURRENT_TIMESTAMP
    ")->execute([
        'user_id' => $localUserId,
        'username' => $localUsername,
        'bbs_name' => $localBbsName,
    ]);
}

function upsertLocalPresence(PDO $db, array $user, string $room): void
{
    $config        = MrcConfig::getInstance();
    $localUsername = resolveMrcUsername($user);
    $localUserId   = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $localBbsName  = MrcClient::sanitizeName($config->getBbsName());
    $room          = MrcClient::sanitizeName($room);

    $db->prepare("
        INSERT INTO mrc_rooms (room_name, last_activity)
        VALUES (:room, CURRENT_TIMESTAMP)
        ON CONFLICT (room_name) DO UPDATE SET last_activity = CURRENT_TIMESTAMP
    ")->execute(['room' => $room]);

    $db->prepare("
        INSERT INTO mrc_local_presence (user_id, username, bbs_name, room_name, last_seen)
        VALUES (:user_id, :username, :bbs_name, :room, CURRENT_TIMESTAMP)
        ON CONFLICT (user_id, room_name) DO UPDATE
        SET username = EXCLUDED.username,
            bbs_name = EXCLUDED.bbs_name,
            last_seen = CURRENT_TIMESTAMP
    ")->execute([
        'user_id' => $localUserId > 0 ? $localUserId : null,
        'username' => $localUsername,
        'bbs_name' => $localBbsName,
        'room' => $room
    ]);
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
    upsertLocalHandle($db, $user);

    // Messages for current view
    if ($viewMode === 'private' && $withUser !== '') {
        $username = resolveMrcUsername($user);
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
    $username = resolveMrcUsername($user);
    if ($username !== '') {
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

    // Users list for joined room — include both server-reported (mrc_users) and
    // locally-connected (mrc_local_presence) users so that the local user
    // appears immediately after joining, before the server's USERLIST arrives.
    if ($joinRoom !== '') {
        $joinRoom = MrcClient::sanitizeName($joinRoom);
        $stmt = $db->prepare("
            SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen,
                   COALESCE(is_afk, false) AS is_afk, afk_message
            FROM mrc_users
            WHERE room_name = :room
            UNION ALL
            SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen,
                   false AS is_afk, NULL AS afk_message
            FROM mrc_local_presence
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
        $response['rooms'] = fetchRoomList($db);
    }

    // Heartbeat for joined room
    if ($joinRoom !== '') {
        upsertLocalPresence($db, $user, $joinRoom);
    }

    \WebDoorSDK\jsonResponse($response);
}

/**
 * Long-poll endpoint: holds the connection for up to 20 seconds and returns
 * as soon as new messages or unread DMs arrive, or when the timeout expires.
 *
 * The session lock is released immediately so concurrent requests from the
 * same user (e.g. sending a message) are never blocked by this handler.
 * Users and rooms are fetched once up-front and included in every response
 * so the client does not need a separate slow-poll interval.
 */
function handleLongPoll(PDO $db, array $user): void
{
    // Release PHP session lock so send/join requests are not blocked.
    session_write_close();

    // Allow this script to run longer than the default PHP max_execution_time.
    set_time_limit(35);

    $viewMode     = $_GET['view_mode']     ?? 'room';
    $viewRoom     = $_GET['view_room']     ?? '';
    $joinRoom     = $_GET['join_room']     ?? '';
    $withUser     = $_GET['with_user']     ?? '';
    $after        = isset($_GET['after'])         ? (int)$_GET['after']         : 0;
    $afterPrivate = isset($_GET['after_private'])  ? (int)$_GET['after_private'] : 0;
    $afterUnread  = isset($_GET['after_unread'])   ? (int)$_GET['after_unread']  : 0;

    if (!empty($viewRoom) && strpos($viewRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($joinRoom) && strpos($joinRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($withUser) && strpos($withUser, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in user');
    }

    $username = resolveMrcUsername($user);
    upsertLocalHandle($db, $user);
    $viewRoom = $viewRoom !== '' ? MrcClient::sanitizeName($viewRoom) : '';
    $joinRoom = $joinRoom !== '' ? MrcClient::sanitizeName($joinRoom) : '';
    $withUser = $withUser !== '' ? MrcClient::sanitizeName($withUser) : '';

    // Update presence heartbeat at the start of each long-poll cycle.
    if ($joinRoom !== '' && $username !== '') {
        upsertLocalPresence($db, $user, $joinRoom);
    }

    // Fetch slow-changing data once up-front; included in every response.
    // Include both server-reported (mrc_users) and locally-connected
    // (mrc_local_presence) users so the local user is visible immediately.
    $users = [];
    if ($joinRoom !== '') {
        $stmt = $db->prepare("
            SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen,
                   COALESCE(is_afk, false) AS is_afk, afk_message
            FROM mrc_users
            WHERE room_name = :room
            UNION ALL
            SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen,
                   false AS is_afk, NULL AS afk_message
            FROM mrc_local_presence
            WHERE room_name = :room
            ORDER BY username
        ");
        $stmt->execute(['room' => $joinRoom]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $rooms = fetchRoomList($db);

    // Prepare message query parameters based on view mode.
    $messageMode = 'room';
    $msgStmt     = null;

    if ($viewMode === 'private' && $withUser !== '' && $username !== '') {
        $messageMode = 'private';
        $msgStmt = $db->prepare("
            SELECT id, from_user, from_site, from_room, to_user, to_room,
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
        $msgStmt->bindValue(':me', $username, PDO::PARAM_STR);
        $msgStmt->bindValue(':with_user', $withUser, PDO::PARAM_STR);
    } elseif ($viewRoom !== '') {
        $msgStmt = $db->prepare("
            SELECT id, from_user, from_site, from_room, to_user, to_room,
                   message_body, msg_ext, is_private, received_at
            FROM mrc_messages
            WHERE (to_room = :room OR from_room = :room)
              AND id > :after
              AND is_private = false
            ORDER BY received_at ASC, id ASC
            LIMIT 200
        ");
        $msgStmt->bindValue(':room', $viewRoom, PDO::PARAM_STR);
    }

    // Prepare unread query.
    $unreadStmt = null;
    if ($username !== '') {
        $unreadStmt = $db->prepare("
            SELECT id, from_user
            FROM mrc_messages
            WHERE is_private = true
              AND to_user = :me
              AND id > :after
            ORDER BY id ASC
        ");
        $unreadStmt->bindValue(':me', $username, PDO::PARAM_STR);
    }

    $timeout  = 20.0;   // seconds
    $sleepUs  = 500000; // 500 ms
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $messages = [];
        if ($msgStmt !== null) {
            $afterVal = ($messageMode === 'private') ? $afterPrivate : $after;
            $msgStmt->bindValue(':after', $afterVal, PDO::PARAM_INT);
            $msgStmt->execute();
            $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $unreadCounts = [];
        $latestUnreadId = $afterUnread;
        if ($unreadStmt !== null) {
            $unreadStmt->bindValue(':after', $afterUnread, PDO::PARAM_INT);
            $unreadStmt->execute();
            foreach ($unreadStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $from = $row['from_user'] ?? '';
                if ($from !== '') {
                    $unreadCounts[$from] = ($unreadCounts[$from] ?? 0) + 1;
                }
                $latestUnreadId = max($latestUnreadId, (int)$row['id']);
            }
        }

        if (!empty($messages) || !empty($unreadCounts)) {
            \WebDoorSDK\jsonResponse([
                'success'         => true,
                'messages'        => $messages,
                'message_mode'    => $messageMode,
                'private_unread'  => ['latest_id' => $latestUnreadId, 'counts' => $unreadCounts],
                'users'           => $users,
                'rooms'           => $rooms,
            ]);
            return;
        }

        usleep($sleepUs);
    }

    // Timeout — return empty so the client reconnects immediately.
    \WebDoorSDK\jsonResponse([
        'success'        => true,
        'messages'       => [],
        'message_mode'   => $messageMode,
        'private_unread' => ['latest_id' => $afterUnread, 'counts' => []],
        'users'          => $users,
        'rooms'          => $rooms,
        'timed_out'      => true,
    ]);
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
        SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen,
               COALESCE(is_afk, false) AS is_afk, afk_message
        FROM mrc_users
        WHERE room_name = :room
        UNION ALL
        SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen,
               false AS is_afk, NULL AS afk_message
        FROM mrc_local_presence
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
    if (!preg_match('/^[a-z]{1,20}$/', $command)) {
        \WebDoorSDK\jsonError('Invalid command');
    }
    // these commands can be sent without a room
    $roomOptional = in_array($command, ['rooms', 'motd', 'register', 'identify', 'update', 'help'], true);
    if (!$roomOptional) {
        if (empty($room)) {
            \WebDoorSDK\jsonError('Room is required');
        }
    }
    if (!empty($room) && strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    $config   = MrcConfig::getInstance();
    $username = resolveMrcUsername($user);
    upsertLocalHandle($db, $user);
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
        case 'help':
            $topic = !empty($commandArgs[0]) ? ' ' . substr(str_replace(['~', ' '], '', $commandArgs[0]), 0, 20) : '';
            $f7 = 'HELP' . $topic;
            break;

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

        default:
            // Generic passthrough: uppercase the command word and append any args
            $safeArgs = array_map(function($a) {
                return substr(str_replace('~', '', $a), 0, 140);
            }, $commandArgs);
            $f7 = strtoupper($command) . (!empty($safeArgs) ? ' ' . implode(' ', $safeArgs) : '');
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
    $username = resolveMrcUsername($user);
    upsertLocalHandle($db, $user);
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
    $username = resolveMrcUsername($user);
    upsertLocalHandle($db, $user);
    $room     = normalizeMrcRoomName((string)$room);
    $fromRoom = trim((string)$fromRoom);
    if ($fromRoom !== '') {
        $fromRoom = normalizeMrcRoomName($fromRoom);
    }
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

    $localUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $db->prepare("
        INSERT INTO mrc_local_presence (user_id, username, bbs_name, room_name, ip_address, last_seen)
        VALUES (:user_id, :username, :bbs_name, :room, :ip_address, CURRENT_TIMESTAMP)
        ON CONFLICT (user_id, room_name) DO UPDATE
        SET username = EXCLUDED.username,
            bbs_name = EXCLUDED.bbs_name,
            last_seen = CURRENT_TIMESTAMP,
            ip_address = COALESCE(EXCLUDED.ip_address, mrc_local_presence.ip_address)
    ")->execute([
        'user_id' => $localUserId > 0 ? $localUserId : null,
        'username' => $username,
        'bbs_name' => $bbsName,
        'room' => $room,
        'ip_address' => $clientIp
    ]);

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


/**
 * Establish an MRC session for the current user.
 * Queues USERIP to register presence with the server, and optionally
 * queues IDENTIFY if a password is provided.
 */
function handleConnect(PDO $db, array $user): void
{
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $password = isset($input['password']) ? trim((string)$input['password']) : '';
    $username = normalizeMrcHandle((string)($input['username'] ?? ''), $user);
    $_SESSION['mrc_username'] = $username;
    upsertLocalHandle($db, $user);

    $config   = MrcConfig::getInstance();
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($clientIp !== '') {
        $clientIp = preg_replace('/[^0-9a-fA-F:\.]/', '', $clientIp);
    }

    $outStmt = $db->prepare("
        INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
        VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
    ");

    if ($clientIp !== '') {
        $outStmt->execute([
            'f1' => $username, 'f2' => $bbsName, 'f3' => '',
            'f4' => 'SERVER',  'f5' => '',        'f6' => '',
            'f7' => "USERIP:{$clientIp}", 'priority' => 9
        ]);
    }

    if ($password !== '') {
        $password = substr(str_replace(['~', ' '], '', $password), 0, 20);
        if ($password !== '') {
            $outStmt->execute([
                'f1' => $username, 'f2' => $bbsName, 'f3' => '',
                'f4' => 'SERVER',  'f5' => '',        'f6' => '',
                'f7' => "IDENTIFY {$password}", 'priority' => 8
            ]);
        }
    }

    \WebDoorSDK\jsonResponse([
        'success' => true,
        'username' => $username,
    ]);
}

/**
 * Disconnect the current user from MRC.
 * Queues LOGOFF for every room the user is currently in and removes
 * their local presence so IAMHERE keepalives stop.
 */
function handleDisconnect(PDO $db, array $user): void
{
    $config   = MrcConfig::getInstance();
    $username = resolveMrcUsername($user);
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());

    $localUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $stmt = $db->prepare("
        SELECT DISTINCT room_name FROM mrc_local_presence
        WHERE user_id = :user_id
          AND last_seen > CURRENT_TIMESTAMP - INTERVAL '10 minutes'
    ");
    $stmt->execute(['user_id' => $localUserId]);
    $rooms = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'room_name');

    $outStmt = $db->prepare("
        INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
        VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
    ");
    foreach ($rooms as $room) {
        $room = MrcClient::sanitizeName($room);
        $outStmt->execute([
            'f1' => $username, 'f2' => $bbsName, 'f3' => $room,
            'f4' => 'SERVER',  'f5' => '',        'f6' => $room,
            'f7' => 'LOGOFF', 'priority' => 10
        ]);
    }

    $db->prepare("
        DELETE FROM mrc_local_presence WHERE user_id = :user_id
    ")->execute(['user_id' => $localUserId]);
    $db->prepare("
        DELETE FROM mrc_local_handles WHERE user_id = :user_id
    ")->execute(['user_id' => $localUserId]);

    foreach ($rooms as $room) {
        emitPresenceForRoom($db, $room);
    }

    unset($_SESSION['mrc_username']);

    // Notify all other tabs/windows for this user so they return to the
    // connect screen instead of running against a terminated session.
    if ($localUserId > 0) {
        BinkStream::emit($db, 'mrc_session_ended', [], $localUserId);
    }

    \WebDoorSDK\jsonResponse(['success' => true]);
}
