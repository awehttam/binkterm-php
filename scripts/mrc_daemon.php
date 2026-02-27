#!/usr/bin/env php
<?php

/*
 * MRC Daemon - Multi Relay Chat Multiplexer
 *
 * Maintains persistent connection to MRC server, processes incoming/outgoing
 * messages, and manages room state in database.
 *
 * Usage: php mrc_daemon.php [--daemon] [--pid-file=path] [--log-level=LEVEL]
 */

chdir(__DIR__ . "/../");

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\Mrc\MrcConfig;
use BinktermPHP\Mrc\MrcClient;

// ========================================
// CLI Functions
// ========================================

function showUsage()
{
    echo "Usage: php mrc_daemon.php [options]\n";
    echo "Options:\n";
    echo "  --daemon          Run as daemon (detach from terminal)\n";
    echo "  --pid-file=FILE   Write PID file (default: data/run/mrc_daemon.pid)\n";
    echo "  --log-level=LEVEL Log level (default: INFO)\n";
    echo "  --debug           Enable protocol debug logging (raw send/recv)\n";
    echo "  --help            Show this help message\n";
    echo "\n";
}

function parseArgs($argv)
{
    $args = [];

    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }

    return $args;
}

function daemonize()
{
    $pid = pcntl_fork();

    if ($pid == -1) {
        die("Could not fork process\n");
    } elseif ($pid) {
        echo "MRC daemon started with PID: $pid\n";
        exit(0);
    }

    if (posix_setsid() == -1) {
        die("Could not detach from terminal\n");
    }

    chdir('/');
    umask(0);

    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
}

function setConsoleTitle(string $title): void
{
    echo "\033]0;{$title}\007";
}

// ========================================
// Database Functions
// ========================================

/**
 * Get database connection
 */
function getDb(): PDO
{
    return Database::getInstance()->getPdo();
}

/**
 * Update connection state in database
 */
function updateConnectionState(bool $connected): void
{
    $db = getDb();
    $stmt = $db->prepare("
        INSERT INTO mrc_state (key, value, updated_at)
        VALUES ('connected', :value, CURRENT_TIMESTAMP)
        ON CONFLICT (key) DO UPDATE
        SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
    ");
    $stmt->execute(['value' => $connected ? 'true' : 'false']);
}

/**
 * Update last ping timestamp in database
 */
function updateLastPing(int $timestamp): void
{
    $db = getDb();
    $stmt = $db->prepare("
        INSERT INTO mrc_state (key, value, updated_at)
        VALUES ('last_ping', :value, CURRENT_TIMESTAMP)
        ON CONFLICT (key) DO UPDATE
        SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
    ");
    $stmt->execute(['value' => (string)$timestamp]);
}

/**
 * Start a room list refresh window for pruning.
 */
function startRoomListRefresh(): void
{
    $db = getDb();
    $stmt = $db->prepare("
        INSERT INTO mrc_state (key, value, updated_at)
        VALUES ('list_refresh_started', :value, CURRENT_TIMESTAMP)
        ON CONFLICT (key) DO UPDATE
        SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
    ");
    $stmt->execute(['value' => (string)time()]);

    $stmt = $db->prepare("
        INSERT INTO mrc_state (key, value, updated_at)
        VALUES ('list_refresh_pending', 'true', CURRENT_TIMESTAMP)
        ON CONFLICT (key) DO UPDATE
        SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
    ");
    $stmt->execute();
}

/**
 * Prune rooms that were not seen during the last LIST refresh window.
 */
function pruneStaleRooms(): void
{
    $db = getDb();

    $started = $db->prepare("SELECT value FROM mrc_state WHERE key = 'list_refresh_started'");
    $started->execute();
    $startedAt = (int)($started->fetchColumn() ?: 0);
    if ($startedAt <= 0) {
        return;
    }

    $pending = $db->prepare("SELECT value FROM mrc_state WHERE key = 'list_refresh_pending'");
    $pending->execute();
    if ($pending->fetchColumn() !== 'true') {
        return;
    }

    // Wait a few seconds for LIST lines to arrive.
    if (time() - $startedAt < 3) {
        return;
    }

    $db->prepare("
        DELETE FROM mrc_rooms
        WHERE last_list_seen IS NULL OR last_list_seen < to_timestamp(:started_at)
    ")->execute(['started_at' => $startedAt]);

    $db->prepare("
        UPDATE mrc_state
        SET value = 'false', updated_at = CURRENT_TIMESTAMP
        WHERE key = 'list_refresh_pending'
    ")->execute();
}

/**
 * Process incoming MRC packet
 */
function processIncomingPacket(array $packet): void
{
    $db = getDb();

    $f1 = $packet['f1'];
    $f2 = $packet['f2'];
    $f3 = $packet['f3'];
    $f4 = $packet['f4'];
    $f5 = $packet['f5'];
    $f6 = $packet['f6'];
    $f7 = $packet['f7'];

    // Handle SERVER commands - command verb is in f7, not f2
    if ($f1 === 'SERVER') {
        $verb = explode(':', $f7, 2)[0];
        handleServerCommand($verb, $f7, $packet);
        return;
    }

    // Regular chat message - store in database
    $stmt = $db->prepare("
        INSERT INTO mrc_messages (from_user, from_site, from_room, to_user, msg_ext, to_room, message_body, is_private, received_at)
        VALUES (:from_user, :from_site, :from_room, :to_user, :msg_ext, :to_room, :message_body, :is_private, CURRENT_TIMESTAMP)
    ");

    $stmt->execute([
        'from_user'    => $f1,
        'from_site'    => $f2,
        'from_room'    => $f3,
        'to_user'      => $f4,
        'msg_ext'      => $f5,
        'to_room'      => $f6,
        'message_body' => $f7,
        'is_private'   => !empty($f4) ? 'true' : 'false'
    ]);

    // Prune old messages (keep last 1000 per room)
    pruneOldMessages($f6);
}

/**
 * Handle a join announcement — upsert the user as server-reported presence.
 */
function handleUserJoinAnnouncement(string $username, string $bbsName, string $room): void
{
    $db = getDb();

    $db->prepare("
        INSERT INTO mrc_rooms (room_name, last_activity)
        VALUES (:room, CURRENT_TIMESTAMP)
        ON CONFLICT (room_name) DO UPDATE SET last_activity = CURRENT_TIMESTAMP
    ")->execute(['room' => $room]);

    $db->prepare("
        INSERT INTO mrc_users (username, bbs_name, room_name, is_local, last_seen)
        VALUES (:username, :bbs_name, :room, false, CURRENT_TIMESTAMP)
        ON CONFLICT (username, bbs_name, room_name) DO UPDATE
        SET last_seen = CURRENT_TIMESTAMP
    ")->execute(['username' => $username, 'bbs_name' => $bbsName, 'room' => $room]);
}

/**
 * Handle a part announcement — remove the user from the room.
 */
function handleUserPartAnnouncement(string $username, string $bbsName, string $room): void
{
    $db = getDb();
    $db->prepare("
        DELETE FROM mrc_users
        WHERE username = :username AND bbs_name = :bbs_name AND room_name = :room AND is_local = false
    ")->execute(['username' => $username, 'bbs_name' => $bbsName, 'room' => $room]);
}

/**
 * Check if a join/part announcement belongs to a local webdoor user.
 * Uses BBS name comparison rather than username so same-named users on
 * other systems are not mistakenly treated as local.
 */
function isLocalAnnouncement(string $username, string $bbsName, string $room): bool
{
    $config = MrcConfig::getInstance();
    $ourBbs = MrcClient::sanitizeName($config->getBbsName());
    return strcasecmp(MrcClient::sanitizeName($bbsName), $ourBbs) === 0;
}

/**
 * Handle SERVER commands (PING, ROOMTOPIC, NOTIFY, etc.)
 *
 * All server commands arrive with the verb (and optional params) in f7.
 * Format: VERB:param1:param2 or just VERB
 */
function handleServerCommand(string $verb, string $f7, array $packet): void
{
    $db = getDb();

    // If f7 starts with a pipe/MCI code or non-alpha character it's a display
    // message (join/part/timeout announcement, notice, etc.), not a command verb.
    if ($f7 === '' || !ctype_alpha($f7[0])) {
        $room  = $packet['f6'] ?? '';
        $clean = preg_replace('/\|[0-9]{2}/', '', $f7);
        error_log("MRC: Room message" . ($room ? " [{$room}]" : '') . ": {$clean}");

        if ($room) {
            $suppressAnnouncement = false;
            // Parse join/part/timeout announcements to keep the user list accurate.

            // Join format:    * (Joining) user@bbs just joined room #room
            // Part format:    * (Parting) user@bbs has left room #room
            // Timeout format: * (Timeout) user@bbs client session has timed-out
            // Leave server:   * (Leaving) user@bbs just left the server
            if (preg_match('/\(Joining\)\s+(\S+?)@(\S+?)\s+just joined/i', $clean, $m)) {
                handleUserJoinAnnouncement($m[1], $m[2], $room);
                $suppressAnnouncement = isLocalAnnouncement($m[1], $m[2], $room);
            } elseif (preg_match('/\((Parting|Timeout)\)\s+(\S+?)@(\S+?)\s/i', $clean, $m)) {
                handleUserPartAnnouncement($m[2], $m[3], $room);
                $suppressAnnouncement = isLocalAnnouncement($m[2], $m[3], $room);
            } elseif (preg_match('/(?:\(Leaving\)\s+)?(\S+?)@(\S+?)\s+just left the server/i', $clean, $m)) {
                // Remove from all rooms; server did not provide a room context.
                $db->prepare("
                    DELETE FROM mrc_users
                    WHERE username = :username AND bbs_name = :bbs_name
                ")->execute(['username' => $m[1], 'bbs_name' => $m[2]]);
                $suppressAnnouncement = isLocalAnnouncement($m[1], $m[2], $room);
            }

            // Store announcement in mrc_messages so webdoor clients see it.
            // Suppress local join/part announcements to avoid spam when switching rooms.
            if (!$suppressAnnouncement) {
                $db->prepare("
                    INSERT INTO mrc_messages
                        (from_user, from_site, from_room, to_user, msg_ext, to_room, message_body, is_private, received_at)
                    VALUES ('SERVER', '', '', '', '', :room, :body, false, CURRENT_TIMESTAMP)
                ")->execute(['room' => $room, 'body' => $f7]);
            }
        } else {
            // No room context: may be a LIST response directed to this BBS.
            // Server sends one line per room: "*.:  #roomname  <count>  <topic>"
            // After pipe-code stripping, match "#roomname  <digits>" pattern.
            // Be tolerant of non-word chars (e.g. dashes) and missing counts.
            $targetUser = $packet['f4'] ?? '';
            if ($targetUser !== '') {
                $targetUser = MrcClient::sanitizeName($targetUser);
                $fromSite = $packet['f5'] ?? '';
                $db->prepare("
                    INSERT INTO mrc_messages
                        (from_user, from_site, from_room, to_user, msg_ext, to_room, message_body, is_private, received_at)
                    VALUES ('SERVER', :from_site, '', :to_user, '', '', :body, true, CURRENT_TIMESTAMP)
                ")->execute([
                    'from_site' => $fromSite,
                    'to_user' => $targetUser,
                    'body' => $f7
                ]);
                return;
            }
            if (
                preg_match('/#([A-Za-z0-9_-]{1,30})\s+\d+/', $clean, $m) ||
                preg_match('/#([A-Za-z0-9_-]{1,30})\b/', $clean, $m)
            ) {
                $db->prepare("
                    INSERT INTO mrc_rooms (room_name, last_activity, last_list_seen)
                    VALUES (:room, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ON CONFLICT (room_name) DO UPDATE
                    SET last_list_seen = CURRENT_TIMESTAMP
                ")->execute(['room' => $m[1]]);
                error_log("MRC: Added room from LIST: {$m[1]}");
            }
        }

        return;
    }

    // Extract params portion (everything after first ':')
    $params = strpos($f7, ':') !== false ? substr($f7, strpos($f7, ':') + 1) : '';

    $verb = strtoupper($verb);

    switch ($verb) {
        case 'PING':
            // Keepalive ping - response handled by main loop
            break;

        case 'ROOMTOPIC':
            // Format: ROOMTOPIC:room:topic
            $parts = explode(':', $params, 2);
            $room  = $parts[0] ?? '';
            $topic = $parts[1] ?? '';

            if ($room) {
                $stmt = $db->prepare("
                    INSERT INTO mrc_rooms (room_name, topic, last_activity)
                    VALUES (:room, :topic, CURRENT_TIMESTAMP)
                    ON CONFLICT (room_name) DO UPDATE
                    SET topic = EXCLUDED.topic, last_activity = CURRENT_TIMESTAMP
                ");
                $stmt->execute(['room' => $room, 'topic' => $topic]);
            }
            break;

        case 'USERROOM':
            // Server confirms which room the client is in
            // Format: USERROOM:room
            $room = $params;
            error_log("MRC: Server confirmed room: {$room}");
            break;

        case 'USERNICK':
            // Server assigned/confirmed nick (may have suffix to resolve conflicts)
            // Format: USERNICK:nick
            error_log("MRC: Server assigned nick: {$params}");
            break;

        case 'USERLIST':
            // Server sending current room user list (response to USERLIST or NEWROOM)
            // Format: USERLIST:user1,user2,...  f6=room
            $room = $packet['f6'];
            if ($room && $params !== '') {
                $users = array_values(array_filter(array_map('trim', explode(',', $params)), 'strlen'));
                if (count($users) === 0) {
                    error_log("MRC: USERLIST empty/invalid for room {$room} (raw={$params})");
                    break;
                }
                // Ensure room exists before inserting users (foreign key requirement)
                $db->prepare("
                    INSERT INTO mrc_rooms (room_name, last_activity)
                    VALUES (:room, CURRENT_TIMESTAMP)
                    ON CONFLICT (room_name) DO UPDATE SET last_activity = CURRENT_TIMESTAMP
                ")->execute(['room' => $room]);
                // Replace foreign user list for this room — preserve local (webdoor) users.
                // Wrap in a transaction to avoid empty-list flashes during polling.
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("DELETE FROM mrc_users WHERE room_name = :room AND is_local = false");
                    $stmt->execute(['room' => $room]);
                    $insertStmt = $db->prepare("
                        INSERT INTO mrc_users (username, bbs_name, room_name, is_local, last_seen)
                        VALUES (:username, :bbs_name, :room, false, CURRENT_TIMESTAMP)
                        ON CONFLICT (username, bbs_name, room_name) DO UPDATE
                        SET last_seen = CURRENT_TIMESTAMP
                    ");
                    foreach ($users as $u) {
                        $trimmed = $u;
                        $insertStmt->execute([
                            'username' => $trimmed,
                            'bbs_name' => 'unknown',
                            'room' => $room
                        ]);
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log("MRC: USERLIST update failed for room {$room}: " . $e->getMessage());
                }
                error_log("MRC: Room {$room} users (" . count($users) . "): {$params}");
            }
            break;

        case 'HELLO':
            // Server greeting after connect — no action needed.
            break;

        case 'NOTIFY':
            // System notification - Format: NOTIFY:message
            error_log("MRC: Server notification: {$params}");
            break;

        case 'TERMINATE':
            // Server requesting graceful shutdown - Format: TERMINATE:msg
            error_log("MRC: Server terminate request: {$params}");
            break;

        case 'OLDVERSION':
            // Server rejected our version - Format: OLDVERSION:minversion
            error_log("MRC: Version rejected, server requires >= {$params}");
            break;

        default:
            // If f4 is a username (not CLIENT/empty) this is a server-generated
            // display notice directed at a specific user, not a command verb.
            if (!empty($packet['f4']) && $packet['f4'] !== 'CLIENT') {
                $clean = preg_replace('/\|[0-9]{2}/', '', $f7);
                error_log("MRC: Server notice to {$packet['f4']}: {$clean}");
            } else {
                error_log("MRC: Unhandled server verb: {$verb} (f7={$f7})");
            }
            break;
    }
}

/**
 * Prune old messages from a room (keep last 1000)
 */
function pruneOldMessages(string $room): void
{
    $db = getDb();
    $config = MrcConfig::getInstance();
    $limit = $config->getHistoryLimit();

    $stmt = $db->prepare("
        DELETE FROM mrc_messages
        WHERE id IN (
            SELECT id FROM mrc_messages
            WHERE to_room = :room
            ORDER BY received_at DESC
            OFFSET :limit
        )
    ");
    $stmt->execute(['room' => $room, 'limit' => $limit]);
}

/**
 * Process outbound message queue
 */
function processOutboundQueue(MrcClient $client): void
{
    $db = getDb();

    // Get unsent messages ordered by priority
    $stmt = $db->prepare("
        SELECT * FROM mrc_outbound
        WHERE sent_at IS NULL
        ORDER BY priority DESC, created_at ASC
        LIMIT 10
    ");
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($messages as $msg) {
        // Send packet
        $success = $client->sendPacket(
            $msg['field1'],
            $msg['field2'],
            $msg['field3'] ?? '',
            $msg['field4'] ?? '',
            $msg['field5'] ?? '',
            $msg['field6'] ?? '',
            $msg['field7']
        );

        if ($success) {
            // Mark as sent
            $updateStmt = $db->prepare("
                UPDATE mrc_outbound
                SET sent_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $updateStmt->execute(['id' => $msg['id']]);
        }
    }

    // Prune sent messages older than 24 hours
    $db->exec("
        DELETE FROM mrc_outbound
        WHERE sent_at IS NOT NULL
        AND sent_at < CURRENT_TIMESTAMP - INTERVAL '24 hours'
    ");
}

/**
 * Maintain local (webdoor) user sessions:
 *   - Send IAMHERE for active users so the server keeps them in room routing.
 *   - Send LOGOFF + prune users whose browser heartbeat has expired (10 min).
 */
function maintainLocalUserSessions(MrcClient $client): void
{
    $db = getDb();

    // Collect stale and active users in one query
    $stmt = $db->query("
        SELECT username, room_name,
               (last_seen < CURRENT_TIMESTAMP - INTERVAL '10 minutes') AS is_stale
        FROM mrc_local_presence
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $staleCount = 0;
    foreach ($rows as $row) {
        if ($row['is_stale']) {
            $client->sendLogoff($row['username'], $row['room_name']);
            error_log("MRC: LOGOFF stale user {$row['username']} from {$row['room_name']}");
            $staleCount++;
        } else {
            $client->sendIamHere($row['username'], $row['room_name']);
        }
    }

    if ($staleCount > 0) {
        $db->exec("
            DELETE FROM mrc_local_presence
            WHERE last_seen < CURRENT_TIMESTAMP - INTERVAL '10 minutes'
        ");
        error_log("MRC: Pruned {$staleCount} stale local user(s)");
    }
}

/**
 * Clear foreign user presence records.
 * Called on (re)connect so stale remote-user entries are removed.
 * Local (webdoor) users are preserved so their rooms can be re-joined.
 */
function clearForeignUsers(): void
{
    getDb()->exec("DELETE FROM mrc_users WHERE is_local = false");
}

/**
 * Ensure the configured auto_join rooms exist in mrc_rooms so the
 * WebDoor room list is populated even before any user joins.
 * No NEWROOM packets are sent — this is a DB-only operation.
 */
function seedAutoJoinRooms(): void
{
    $db = getDb();
    $config = MrcConfig::getInstance();
    $rooms = $config->getAutoJoinRooms();

    $stmt = $db->prepare("
        INSERT INTO mrc_rooms (room_name, last_activity)
        VALUES (:room, CURRENT_TIMESTAMP)
        ON CONFLICT (room_name) DO NOTHING
    ");

    foreach ($rooms as $room) {
        $room = trim((string)$room);
        if ($room !== '') {
            $stmt->execute(['room' => $room]);
        }
    }
}

/**
 * Re-join each local (webdoor) user into their room after a reconnect.
 * Each user needs their own NEWROOM packet so the MRC server establishes
 * an individual session for them — sending NEWROOM only as the BBS is not enough.
 */
function rejoinLocalUserRooms(MrcClient $client): void
{
    $db = getDb();

    $stmt = $db->query("SELECT username, room_name FROM mrc_local_presence");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $client->joinRoom($row['room_name'], $row['username']);
        error_log("MRC: Re-joined {$row['username']} into room: {$row['room_name']}");
    }
}

// ========================================
// Main Program
// ========================================

$args = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

try {
    // Setup paths
    $defaultPidFile = __DIR__ . '/../data/run/mrc_daemon.pid';
    $pidFile = $args['pid-file'] ?? $defaultPidFile;
    $masterPid = null; // Track parent PID for cleanup

    // Check if MRC is enabled
    $config = MrcConfig::getInstance();
    if (!$config->isEnabled()) {
        echo "MRC is disabled in configuration\n";
        exit(0);
    }

    // Daemonize if requested
    if (isset($args['daemon']) && function_exists('pcntl_fork')) {
        $masterPid = getmypid(); // Store parent PID
        daemonize();
    } else {
        setConsoleTitle('BinktermPHP MRC Daemon');
    }

    // Write PID file (only the forked child writes this)
    $pidDir = dirname($pidFile);
    if (!is_dir($pidDir)) {
        @mkdir($pidDir, 0755, true);
    }
    @file_put_contents($pidFile, (string)getmypid());

    // Signal handling
    $shutdown = false;

    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
    }

    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, function() use (&$shutdown) {
            error_log("MRC: Received SIGTERM, shutting down...");
            $shutdown = true;
        });

        pcntl_signal(SIGINT, function() use (&$shutdown) {
            error_log("MRC: Received SIGINT, shutting down...");
            $shutdown = true;
        });
    }

    $debugMode = isset($args['debug']);

    error_log("MRC: Daemon started (PID: " . getmypid() . ")" . ($debugMode ? " [DEBUG MODE]" : ""));

    $client = new MrcClient($config);
    $client->setDebug($debugMode);
    $lastReconnectAttempt = 0;
    $lastKeepalive = 0;
    $lastIamHere = 0;
    $lastUserListRefresh = 0;
    $lastRoomListRefresh = 0;

    // Main loop
    while (!$shutdown) {
        // Check if still enabled
        $config->reloadConfig();
        if (!$config->isEnabled()) {
            error_log("MRC: Disabled in config, shutting down");
            break;
        }

        // Connect if not connected
        if (!$client->isConnected()) {
            $now = time();

            if ($now - $lastReconnectAttempt >= $config->getReconnectDelay()) {
                error_log("MRC: Attempting to connect...");

                if ($client->connect()) {
                    updateConnectionState(true);

                    // Send INFO* metadata to the hub on connect.
                    $web = trim((string)$config->getWebsite());
                    $telnet = trim((string)$config->getTelnet());
                    $sysop = trim((string)$config->getSysop());
                    $desc = trim((string)$config->getDescription());
                    if ($web !== '') {
                        $client->sendInfo('INFOWEB', $web);
                    }
                    if ($telnet !== '') {
                        $client->sendInfo('INFOTEL', $telnet);
                    }
                    if ($sysop !== '') {
                        $client->sendInfo('INFOSYS', $sysop);
                    }
                    if ($desc !== '') {
                        $client->sendInfo('INFODSC', $desc);
                    }

                    // Clear stale foreign users; local (webdoor) users are kept
                    // so their rooms can be re-established below.
                    clearForeignUsers();

                    // Seed configured rooms into mrc_rooms so the WebDoor
                    // room list is populated even before any user joins.
                    seedAutoJoinRooms();

                    // Request full room list from server to populate mrc_rooms.
                    startRoomListRefresh();
                    $client->requestRoomList();

                    // Re-join any rooms with active local (webdoor) users.
                    // Individual room joins happen via the outbound queue when
                    // users join from the web UI.  We do NOT send a BBS-level
                    // NEWROOM using the BBS name as a username — that creates a
                    // phantom user session the server will time out.
                    sleep(1); // Give server time to process handshake
                    rejoinLocalUserRooms($client);

                    $lastKeepalive = time();
                    $lastIamHere   = time(); // Delay first IAMHERE so NEWROOMs are processed first
                } else {
                    updateConnectionState(false);
                }

                $lastReconnectAttempt = $now;
            }

            // Sleep before retry
            usleep(100000); // 100ms
            continue;
        }

        // Read incoming packets
        $packets = $client->readPackets();
        foreach ($packets as $packet) {
            // Check for PING - command is in f7, not f2
            if ($packet['f1'] === 'SERVER' && strtoupper($packet['f7']) === 'PING') {
                $client->sendKeepalive();
                $client->updateLastPing();
                updateLastPing(time());
                $lastKeepalive = time();
            }

            processIncomingPacket($packet);
        }

        // Process outbound queue
        processOutboundQueue($client);

        // Send IAMHERE for active local users; LOGOFF + prune stale ones
        if (time() - $lastIamHere >= 50) {
            maintainLocalUserSessions($client);
            $lastIamHere = time();
        }

        // Refresh user list for each room with active local users every 60 seconds.
        // This catches remote users who joined before us or whose join announcement
        // was missed.
        if (time() - $lastUserListRefresh >= 60) {
            $db = getDb();
            $stmt = $db->query("SELECT DISTINCT room_name FROM mrc_local_presence");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $room) {
                $client->requestUserList($room);
            }
            $lastUserListRefresh = time();
        }

        // Prune rooms after LIST refresh window.
        pruneStaleRooms();

        // Check keepalive timeout
        if ($client->isKeepaliveExpired()) {
            error_log("MRC: Keepalive timeout, reconnecting...");
            $client->disconnect();
            updateConnectionState(false);
            continue;
        }

        // Sleep to avoid busy loop
        usleep(100000); // 100ms
    }

    // Cleanup
    error_log("MRC: Shutting down...");

    if ($client->isConnected()) {
        $client->disconnect();
    }

    updateConnectionState(false);

    // Delete PID file (only if we're the process that created it)
    if (file_exists($pidFile) && getmypid() == (int)file_get_contents($pidFile)) {
        @unlink($pidFile);
    }

    error_log("MRC: Daemon stopped");
    exit(0);

} catch (Exception $e) {
    error_log("MRC: Fatal error: " . $e->getMessage());
    exit(1);
}
