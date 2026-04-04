<?php
/**
 * MRC Chat WebDoor
 *
 * Loads the user's selected BBS theme so the MRC interface inherits
 * the same colour palette as the rest of the site.
 */

// Include WebDoor SDK (handles autoload, database, and session initialization)
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\AppearanceConfig;
use BinktermPHP\Auth;
use BinktermPHP\Config;
use BinktermPHP\MessageHandler;
use BinktermPHP\Mrc\MrcConfig;

function mrcRealtimeDaemonAvailable(): bool
{
    $pidFile = (string)(Config::env('BINKSTREAM_WS_PID_FILE', Config::env('REALTIME_WS_PID_FILE')) ?: (BINKTERMPHP_BASEDIR . '/data/run/realtime_server.pid'));
    if ($pidFile === '' || !is_file($pidFile)) {
        return false;
    }

    $pid = (int)trim((string)@file_get_contents($pidFile));
    if ($pid <= 0) {
        return false;
    }

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return true;
}

// Resolve the correct theme stylesheet for the current user
// (mirrors the logic in Template::addGlobalVariables)
$availableThemes = Config::getThemes();
$defaultTheme    = AppearanceConfig::getDefaultTheme();
$stylesheet      = ($defaultTheme !== '' && in_array($defaultTheme, $availableThemes, true))
    ? $defaultTheme
    : Config::getStylesheet();

$auth        = new Auth();
$currentUser = $auth->getCurrentUser();

if ($currentUser && !empty($currentUser['user_id']) && !AppearanceConfig::isThemeLocked()) {
    try {
        $handler  = new MessageHandler();
        $settings = $handler->getUserSettings($currentUser['user_id']);
        if (!empty($settings['theme']) && in_array($settings['theme'], $availableThemes, true)) {
            $stylesheet = $settings['theme'];
        }
    } catch (\Exception $e) {
        // Fall back to sysop default
    }
}
$bbsName = MrcConfig::getInstance()->getBbsName();
$configuredRealtimeTransportMode = strtolower(trim((string)Config::env('BINKSTREAM_TRANSPORT_MODE', Config::env('REALTIME_TRANSPORT_MODE', Config::env('SSE_TRANSPORT_MODE', 'auto')))));
if (!in_array($configuredRealtimeTransportMode, ['auto', 'sse', 'ws'], true)) {
    $configuredRealtimeTransportMode = 'auto';
}
$realtimeWsUrl = trim((string)Config::env('BINKSTREAM_WS_PUBLIC_URL', Config::env('REALTIME_WS_PUBLIC_URL', '/ws')));
if ($realtimeWsUrl === '') {
    $realtimeWsUrl = '/ws';
}
$effectiveRealtimeTransportMode = $configuredRealtimeTransportMode;
if ($configuredRealtimeTransportMode === 'auto') {
    // Browser-side transport preference should follow the publicly
    // reachable WS endpoint, not local PID visibility from the web process.
    $effectiveRealtimeTransportMode = ($realtimeWsUrl !== '' && $realtimeWsUrl !== '/ws') ? 'ws' : 'sse';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MRC Chat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <!-- User's selected BBS theme (provides CSS variable overrides) -->
    <link href="<?php echo htmlspecialchars($stylesheet); ?>" rel="stylesheet">
    <link href="/css/ansisys.css" rel="stylesheet">
    <link rel="stylesheet" href="mrc.css?v=<?php echo filemtime(__DIR__ . '/mrc.css'); ?>">
</head>
<body>

    <!-- Connect Screen — shown until the user connects; hidden after -->
    <div id="mrc-connect-screen">
        <div id="mrc-connect-box">
            <div class="mrc-connect-title">MRC Chat</div>
            <div class="mrc-connect-bbs"><?php echo htmlspecialchars($bbsName); ?></div>
            <div class="mrc-connect-form">
                <div class="mb-3">
                    <label class="form-label mrc-text-muted small">Username</label>
                    <input type="text" class="form-control" id="connect-username" value="<?php echo htmlspecialchars($currentUser['username'] ?? ''); ?>" maxlength="30">
                </div>
                <div class="mb-3">
                    <label class="form-label mrc-text-muted small">
                        Password
                        <span class="mrc-text-muted">(optional &mdash; leave blank if not registered or trust is valid)</span>
                    </label>
                    <input type="password" class="form-control" id="connect-password"
                           placeholder="MRC password" autocomplete="current-password" maxlength="20">
                </div>
                <button class="btn btn-primary w-100" id="connect-btn">Connect</button>
                <div id="connect-error" class="text-danger small mt-2 d-none"></div>
            </div>
        </div>
    </div>

    <!-- Chat App — hidden until connected -->
    <div id="mrc-app" class="d-none">
            <!-- Center: Chat Messages -->
            <div id="mrc-main" class="d-flex flex-column">
                <!-- Chat Header -->
                <div class="border-bottom p-2" id="mrc-chat-header">
                    <div class="d-flex align-items-center gap-2">
                        <select id="room-select" class="form-select form-select-sm" style="max-width:200px">
                            <option value="">— join a room —</option>
                        </select>
                        <small class="mrc-text-muted flex-grow-1" id="current-room-topic"></small>
                        <div id="connection-status" class="mrc-text-muted small">
                            <i class="bi bi-circle-fill text-secondary"></i>
                        </div>
                        <div class="badge bg-warning text-dark d-none" id="private-chat-indicator">
                            <span>Direct:</span>
                            <span class="ms-1" id="private-chat-user"></span>
                            <button type="button" class="btn btn-sm btn-link p-0 ms-2" id="private-chat-exit" title="Return to room chat">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" id="refresh-btn" title="Refresh messages">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" id="disconnect-btn" title="Disconnect from MRC">
                            <i class="bi bi-box-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Daemon offline overlay — covers the chat area when daemon is down -->
                <div id="mrc-daemon-overlay" class="d-none">
                    <div id="mrc-daemon-overlay-inner">
                        <div class="mrc-daemon-icon">&#9888;</div>
                        <div class="mrc-daemon-title">MRC Daemon Offline</div>
                        <div class="mrc-daemon-msg">The MRC daemon is not running.<br>Messages cannot be sent or received.</div>
                        <div class="mrc-daemon-retry">Checking again in <span id="mrc-daemon-countdown">30</span>s&hellip;</div>
                    </div>
                </div>

                <!-- Chat Messages Area -->
                <div class="flex-grow-1 overflow-auto p-3" id="chat-messages"></div>

                <!-- Message Input -->
                <div class="border-top p-3" id="mrc-input-bar">
                    <form id="message-form">
                        <div class="input-group">
                            <input type="text"
                                   class="form-control"
                                   id="message-input"
                                   placeholder="Type a command (e.g. /identify) or join a room to chat..."
                                   maxlength="140"
                                   autocomplete="off">
                            <button class="btn btn-primary" type="submit" id="send-btn" tabindex="-1">
                                <i class="bi bi-send"></i> Send
                            </button>
                        </div>
                        <div class="form-text mt-1 mrc-text-muted">
                            <span id="char-count">0</span>/140 characters
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Sidebar: User List -->
            <div class="border-start" id="mrc-sidebar-right">
                <div class="p-3">
                    <h5 class="mb-3">
                        <i class="bi bi-people"></i> Users
                        <span class="badge bg-secondary" id="user-count">0</span>
                        <span class="badge bg-warning text-dark ms-2 d-none" id="private-unread-count">0</span>
                    </h5>
                    <div id="user-list" class="list-group">
                        <div class="text-center mrc-text-muted py-3 small">
                            Join a room to see users
                        </div>
                    </div>
                </div>
            </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Pipe/ANSI color code parser -->
    <script src="/js/ansisys.js"></script>
    <script>
        window.currentUserId = <?php echo (int)($currentUser['user_id'] ?? 0) ?: 'null'; ?>;
        window.mrcCurrentUser = <?php echo json_encode($currentUser['username'] ?? ''); ?>;
        window.mrcCurrentBbs = <?php echo json_encode($bbsName); ?>;
        window.siteConfig = {
            sseTransportMode: <?php echo json_encode($effectiveRealtimeTransportMode); ?>,
            configuredRealtimeTransportMode: <?php echo json_encode($configuredRealtimeTransportMode); ?>,
            realtimeTransportMode: <?php echo json_encode($effectiveRealtimeTransportMode); ?>,
            realtimeWsUrl: <?php echo json_encode($realtimeWsUrl); ?>
        };
    </script>
    <script src="/js/user-storage.js"></script>
    <!-- BinkStream real-time client -->
    <script src="/js/binkstream-client.js?v=<?php echo @filemtime(BINKTERMPHP_BASEDIR . '/public_html/js/binkstream-client.js'); ?>"></script>
    <!-- MRC Client JS -->
    <script src="mrc.js?v=<?php echo filemtime(__DIR__ . '/mrc.js'); ?>"></script>

</body>
</html>
