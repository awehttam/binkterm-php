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

    // Handle SERVER commands
    if ($f1 === 'SERVER') {
        handleServerCommand($f2, $packet);
        return;
    }

    // Regular chat message - store in database
    $stmt = $db->prepare("
        INSERT INTO mrc_messages (from_user, from_site, from_room, to_user, msg_ext, to_room, message_body, is_private, received_at)
        VALUES (:from_user, :from_site, :from_room, :to_user, :msg_ext, :to_room, :message_body, :is_private, CURRENT_TIMESTAMP)
    ");

    $stmt->execute([
        'from_user' => $f1,
        'from_site' => $f2,
        'from_room' => $f3,
        'to_user' => $f4,
        'msg_ext' => $f5,
        'to_room' => $f6,
        'message_body' => $f7,
        'is_private' => !empty($f4) ? 'true' : 'false'
    ]);

    // Prune old messages (keep last 1000 per room)
    pruneOldMessages($f6);
}

/**
 * Handle SERVER commands (PING, ROOMTOPIC, NOTIFY, etc.)
 */
function handleServerCommand(string $command, array $packet): void
{
    $db = getDb();

    switch ($command) {
        case 'PING':
            // Keepalive ping - response handled by main loop
            break;

        case 'ROOMTOPIC':
            // Update room topic
            // Format: SERVER~ROOMTOPIC~~~~room~topic~
            $room = $packet['f6'];
            $topic = $packet['f7'];

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
            // User joined/left room
            // Format: SERVER~USERROOM~room~user~action~room~~
            $room = $packet['f3'];
            $user = $packet['f4'];
            $action = $packet['f5']; // JOIN or PART

            if ($action === 'JOIN') {
                // Add user to room
                $stmt = $db->prepare("
                    INSERT INTO mrc_users (username, bbs_name, room_name, last_seen)
                    VALUES (:username, :bbs_name, :room, CURRENT_TIMESTAMP)
                    ON CONFLICT (username, bbs_name, room_name) DO UPDATE
                    SET last_seen = CURRENT_TIMESTAMP
                ");
                $stmt->execute(['username' => $user, 'bbs_name' => '', 'room' => $room]);
            } elseif ($action === 'PART') {
                // Remove user from room
                $stmt = $db->prepare("
                    DELETE FROM mrc_users WHERE username = :username AND room_name = :room
                ");
                $stmt->execute(['username' => $user, 'room' => $room]);
            }
            break;

        case 'NOTIFY':
            // System notification
            error_log("MRC: Server notification: " . $packet['f7']);
            break;

        case 'TERMINATE':
            // Server terminating connection
            error_log("MRC: Server terminating connection: " . $packet['f7']);
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
 * Join default rooms on connect
 */
function joinDefaultRooms(MrcClient $client, MrcConfig $config): void
{
    $rooms = $config->getAutoJoinRooms();
    $bbsName = $config->getBbsName();

    foreach ($rooms as $room) {
        $client->joinRoom($room, 'SYSTEM');
        error_log("MRC: Joined room: {$room}");
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

    error_log("MRC: Daemon started (PID: " . getmypid() . ")");

    $client = new MrcClient($config);
    $lastReconnectAttempt = 0;
    $lastKeepalive = 0;

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

                    // Join default rooms
                    sleep(1); // Give server time to process handshake
                    joinDefaultRooms($client, $config);

                    $lastKeepalive = time();
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
            // Check for PING
            if ($packet['f1'] === 'SERVER' && $packet['f2'] === 'PING') {
                $client->sendKeepalive();
                $client->updateLastPing();
                updateLastPing(time());
                $lastKeepalive = time();
            }

            processIncomingPacket($packet);
        }

        // Process outbound queue
        processOutboundQueue($client);

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
