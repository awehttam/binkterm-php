#!/usr/bin/env php
<?php

/*
 * AI Bot Daemon
 *
 * Listens for chat events via PostgreSQL NOTIFY and dispatches AI responses
 * for configured bots. Reactive to live chat; not a polling loop.
 *
 * Usage: php ai_bot_daemon.php [--daemon] [--pid-file=path] [--log-level=LEVEL]
 */

chdir(__DIR__ . "/../");

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\AiBot\AiBotRepository;
use BinktermPHP\AiBot\ActivityEvent;
use BinktermPHP\AiBot\LocalChatActivityHandler;
use BinktermPHP\AI\AiService;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\Realtime\PostgresEventListener;

// ========================================
// CLI Helpers
// ========================================

function showUsage(): void
{
    echo "Usage: php ai_bot_daemon.php [options]\n";
    echo "Options:\n";
    echo "  --daemon          Run as daemon (detach from terminal)\n";
    echo "  --pid-file=FILE   Write PID file (default: data/run/ai_bot_daemon.pid)\n";
    echo "  --log-level=LEVEL Log level (default: INFO)\n";
    echo "  --help            Show this help message\n\n";
}

function parseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    return $args;
}

function daemonize(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        die("Could not fork process\n");
    } elseif ($pid > 0) {
        echo "AI Bot daemon started with PID: $pid\n";
        exit(0);
    }
    if (posix_setsid() === -1) {
        die("Could not detach from terminal\n");
    }
    chdir('/');
    umask(0);
    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
}

function writePidFile(string $pidFile): void
{
    $dir = dirname($pidFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($pidFile, (string)getmypid());
}

function removePidFile(string $pidFile): void
{
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
}

// ========================================
// Main
// ========================================

$args     = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

$logLevel = strtoupper((string)($args['log-level'] ?? 'INFO'));
$pidFile  = (string)($args['pid-file'] ?? Config::env('AI_BOT_DAEMON_PID_FILE') ?: __DIR__ . '/../data/run/ai_bot_daemon.pid');

$logger = new Logger(
    Config::getLogPath('ai_bot_daemon.log'),
    $logLevel,
    !isset($args['daemon'])  // echo to console when not daemonizing
);

if (isset($args['daemon'])) {
    if (!function_exists('pcntl_fork')) {
        $logger->error('Cannot daemonize: pcntl extension not available');
        exit(1);
    }
    daemonize();
}

writePidFile($pidFile);

$logger->info('AI bot daemon starting');

// Signal handling
$shutdown = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shutdown) { $shutdown = true; });
    pcntl_signal(SIGINT,  function () use (&$shutdown) { $shutdown = true; });
}

// Database (PDO for application queries)
$db = Database::getInstance()->getPdo();

// AI service
$ai = AiService::create();

// Activity handler
$chatHandler = new LocalChatActivityHandler($db, $ai, $logger);

// Repository
$repo = new AiBotRepository($db);

// Open realtime listener for LISTEN/NOTIFY wake-ups
$eventListener = PostgresEventListener::fromConfiguredDatabase($logger);
if (!$eventListener->listen('binkstream')) {
    $logger->error('AI bot daemon could not establish PostgreSQL LISTEN connection; exiting');
    removePidFile($pidFile);
    exit(1);
}
$logger->info('AI bot daemon: listening on binkstream channel');

// Capture starting cursor so we do not replay old events on startup
$stmt = $db->query("SELECT COALESCE(MAX(id), 0) FROM sse_events");
$lastSseId = (int)$stmt->fetchColumn();
$logger->info("AI bot daemon: starting from sse_events id={$lastSseId}");

// Pre-load bots (refresh periodically)
$bots             = $repo->getActiveChatBotsByUserId();
$botUserIds       = array_keys($bots);
$lastBotRefresh   = time();
$botRefreshEvery  = 60; // seconds

$logger->info('AI bot daemon ready', ['active_bots' => count($bots)]);

// ========================================
// Event loop
// ========================================

while (!$shutdown) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    if ($shutdown) {
        break;
    }

    // Refresh bot list periodically
    if (time() - $lastBotRefresh >= $botRefreshEvery) {
        $bots           = $repo->getActiveChatBotsByUserId();
        $botUserIds     = array_keys($bots);
        $lastBotRefresh = time();
        $logger->debug('AI bot daemon: refreshed bot list', ['active_bots' => count($bots)]);
    }

    if (!$eventListener->isHealthy()) {
        $logger->warning('AI bot daemon: pg connection lost, reconnecting...');
        sleep(2);
        if (!$eventListener->reconnect()) {
            $logger->error('AI bot daemon: reconnect failed; exiting');
            break;
        }
    }

    $notifications = $eventListener->wait(500);
    if ($notifications === []) {
        continue;
    }

    // Consume all pending notifications
    foreach ($notifications as $notificationPayload) {
        $evtId = (int)$notificationPayload;
        if ($evtId <= $lastSseId) {
            continue;
        }
        $lastSseId = $evtId;

        // Fetch sse_events row
        $evtStmt = $db->prepare("
            SELECT event_type, payload::text AS event_data, user_id
            FROM   sse_events
            WHERE  id = ?
        ");
        $evtStmt->execute([$evtId]);
        $evt = $evtStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$evt || $evt['event_type'] !== 'chat_message') {
            continue;
        }

        $payload = json_decode($evt['event_data'], true);
        if (!is_array($payload)) {
            continue;
        }

        $msgFromUserId = (int)($payload['from_user_id'] ?? 0);
        $msgToUserId   = isset($payload['to_user_id']) && $payload['to_user_id'] !== null
            ? (int)$payload['to_user_id']
            : null;
        $msgBody       = (string)($payload['body'] ?? '');
        $msgType       = (string)($payload['type'] ?? '');

        // Skip messages sent by a bot itself (prevent loops)
        if (in_array($msgFromUserId, $botUserIds, true)) {
            continue;
        }

        if ($msgType === 'dm' && $msgToUserId !== null && isset($bots[$msgToUserId])) {
            // Direct message to a bot
            $bot   = $bots[$msgToUserId];
            $event = new ActivityEvent('chat_direct', $payload);
            try {
                $chatHandler->handle($bot, $event);
            } catch (\Throwable $e) {
                $logger->error('AI bot handler error (dm)', [
                    'bot_id' => $bot->id,
                    'error'  => $e->getMessage(),
                ]);
            }
        } elseif ($msgType === 'room') {
            // Room message — check for @mentions
            foreach ($bots as $botUserId => $bot) {
                if (!preg_match('/\B@' . preg_quote($bot->name, '/') . '\b/i', $msgBody)) {
                    continue;
                }
                $event = new ActivityEvent('chat_mention', $payload);
                try {
                    $chatHandler->handle($bot, $event);
                } catch (\Throwable $e) {
                    $logger->error('AI bot handler error (mention)', [
                        'bot_id' => $bot->id,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}

// ========================================
// Shutdown
// ========================================

$logger->info('AI bot daemon shutting down');
$eventListener->close();
removePidFile($pidFile);
