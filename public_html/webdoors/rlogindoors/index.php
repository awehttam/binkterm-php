<?php
/**
 * RLogin Door Player
 *
 * This file can be included by routes or accessed directly.
 * When included, $doorId should be set by the calling code.
 */

use BinktermPHP\RouteHelper;
use BinktermPHP\UserMeta;
use BinktermPHP\I18n\LocaleResolver;
use BinktermPHP\I18n\Translator;

$user = RouteHelper::requireAuth();
$csrfUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);
$csrfToken = '';
if ($csrfUserId > 0) {
    try {
        $meta = new UserMeta();
        $csrfToken = $meta->getValue($csrfUserId, 'csrf_token') ?? '';
    } catch (\Throwable $e) {}
}

$translator = new Translator();
$localeResolver = new LocaleResolver($translator);
$locale = $localeResolver->resolveLocale((string)($user['locale'] ?? ''), $user);
$localeResolver->persistLocale($locale);
$t = static function (string $key, string $fallback, array $params = [], string $namespace = 'common') use ($translator, $locale): string {
    $translated = $translator->translate($key, $params, $locale, [$namespace]);
    return $translated === $key ? $fallback : $translated;
};

// If accessed directly, try to extract door ID from URL
if (!isset($doorId)) {
    $requestUri = $_SERVER['REQUEST_URI'];
    $pattern = '#/webdoors/rlogindoors/([^/]+)#';
    preg_match($pattern, $requestUri, $matches);
    $doorId = $matches[1] ?? '';

    // Clean the door ID (remove query string if present)
    $doorId = preg_replace('/\?.*$/', '', $doorId);
}

if (empty($doorId)) {
    http_response_code(404);
    echo htmlspecialchars($t('ui.dosdoor_player.error_no_door_specified', 'Error: No door ID specified'), ENT_QUOTES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale, ENT_QUOTES) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <title><?= htmlspecialchars($t('ui.rlogindoor_player.page_title', 'RLogin Door Player'), ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="/webdoors/terminal/assets/xterm.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            overflow: hidden;
            background: #000;
        }

        .terminal-controls {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            padding: 5px 10px;
            background: #1a1a2e;
            height: 35px;
            border-bottom: 1px solid #333;
        }

        #terminal-container {
            position: absolute;
            top: 35px;
            left: 0;
            right: 0;
            bottom: 0;
            background: #000;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            overflow: hidden;
        }

        #terminal-container .xterm {
            margin-top: 6px;
        }

        /* Force terminal surface to pure black */
        #terminal-container .xterm,
        #terminal-container .xterm-viewport,
        #terminal-container .xterm-screen,
        #terminal-container .xterm-screen canvas {
            background-color: #000 !important;
        }

        .connection-status {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-family: monospace;
        }

        .status-disconnected {
            background-color: #dc3545;
            color: white;
        }

        .status-connecting {
            background-color: #ffc107;
            color: black;
        }

        .status-connected {
            background-color: #28a745;
            color: white;
        }

        .door-header {
            margin: 0;
            font-size: 0.9rem;
            color: #fff;
            font-family: monospace;
            justify-self: start;
        }

        #endSessionBtn {
            padding: 4px 12px;
            font-size: 12px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            justify-self: end;
            font-family: monospace;
        }

        #endSessionBtn:hover {
            background: #c82333;
        }

        #connectionStatus {
            justify-self: center;
        }

        .error-message {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #1a1a2e;
            color: #ff5555;
            padding: 20px;
            border-radius: 5px;
            font-family: monospace;
            max-width: 80%;
            text-align: center;
        }

        /* Xterm helpers: keep off-screen, but do not break measurement logic */
        .xterm-helpers {
            position: absolute !important;
            left: -9999em !important;
            top: 0 !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }

        .xterm-helpers .xterm-helper-textarea {
            position: absolute !important;
            left: -9999em !important;
            top: 0 !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }

        .xterm-char-measure-element {
            position: absolute !important;
            left: -9999em !important;
            top: 0 !important;
            visibility: hidden !important;
        }
    </style>
</head>
<body>
    <div class="terminal-controls">
        <h5 class="door-header" id="doorTitle"><?= htmlspecialchars($t('ui.rlogindoor_player.page_title', 'RLogin Door Player'), ENT_QUOTES) ?></h5>
        <div id="connectionStatus" class="connection-status status-disconnected">
            <?= htmlspecialchars($t('ui.dosdoor_player.status_prefix', 'Status:'), ENT_QUOTES) ?> <?= htmlspecialchars($t('ui.dosdoor_player.status_disconnected', 'Disconnected'), ENT_QUOTES) ?>
        </div>
        <button id="endSessionBtn"><?= htmlspecialchars($t('ui.dosdoor_player.end_session', 'End Session'), ENT_QUOTES) ?></button>
    </div>
    <div id="terminal-container"></div>

    <script src="/webdoors/terminal/assets/xterm.js"></script>
    <script>
        let term = null;
        const TERM_COLS = 80;
        const TERM_ROWS = 25;
        let socket = null;
        let sessionId = null;
        let wsPort = null;
        let wsToken = null;
        const doorId = <?php echo json_encode($doorId); ?>;
        const I18N = <?php echo json_encode([
            'statusPrefix' => $t('ui.dosdoor_player.status_prefix', 'Status:'),
            'statusDisconnected' => $t('ui.dosdoor_player.status_disconnected', 'Disconnected'),
            'statusLaunching' => $t('ui.dosdoor_player.status_launching', 'Launching...'),
            'statusLaunchFailed' => $t('ui.dosdoor_player.status_launch_failed', 'Launch failed'),
            'statusConnecting' => $t('ui.dosdoor_player.status_connecting', 'Connecting...'),
            'statusConnected' => $t('ui.dosdoor_player.status_connected', 'Connected'),
            'statusConnectionError' => $t('ui.dosdoor_player.status_connection_error', 'Connection error'),
            'statusError' => $t('ui.dosdoor_player.status_error', 'Error'),
            'launchingDoorLine' => $t('ui.dosdoor_player.launching_door_line', 'Launching door game...'),
            'failedLaunchLine' => $t('ui.dosdoor_player.failed_launch_line', 'Failed to launch door session.'),
            'connectingToPrefix' => $t('ui.dosdoor_player.connecting_to_prefix', 'Connecting to'),
            'connectedLine' => $t('ui.dosdoor_player.connected_line', 'Connected!'),
            'connectionClosedLine' => $t('ui.dosdoor_player.connection_closed_line', '[Connection closed]'),
            'connectionErrorLine' => $t('ui.dosdoor_player.connection_error_line', '[Connection error]'),
            'failedToConnectPrefix' => $t('ui.dosdoor_player.failed_to_connect_prefix', 'Failed to connect:'),
            'confirmEndSession' => $t('ui.dosdoor_player.confirm_end_session', 'Are you sure you want to end this door session?'),
            'failedEndSession' => $t('ui.dosdoor_player.failed_end_session', 'Failed to end session'),
            'errorEndingSession' => $t('ui.dosdoor_player.error_ending_session', 'Error ending session'),
            'errorNoDoorSpecified' => $t('ui.dosdoor_player.error_no_door_specified', 'Error: No door ID specified'),
            'failedLaunchDoor' => $t('ui.dosdoor_player.failed_launch_door', 'Failed to launch door'),
            'documentTitleSuffix' => $t('ui.rlogindoor_player.document_title_suffix', 'RLogin Door'),
            'apiErrors' => [
                'errors.door.door_name_required' => $t('errors.door.door_name_required', 'Door name required', [], 'errors'),
                'errors.door.admin_only' => $t('errors.door.admin_only', 'This door is restricted to administrators', [], 'errors'),
                'errors.door.insufficient_credits' => $t('errors.door.insufficient_credits', 'Insufficient credits', [], 'errors'),
                'errors.door.insufficient_credits_detail' => $t('errors.door.insufficient_credits_detail', 'This door costs {required} credits. You have {balance} credits.', [], 'errors'),
                'errors.door.capacity_reached' => $t('errors.door.capacity_reached', 'Door is at capacity', [], 'errors'),
                'errors.door.capacity_reached_detail' => $t('errors.door.capacity_reached_detail', 'This door is currently in use. Only {max_nodes} player(s) allowed at a time. Please try again later.', [], 'errors'),
                'errors.door.launch_failed' => $t('errors.door.launch_failed', 'Failed to start door session', [], 'errors'),
                'errors.door.prelogin_failed' => $t('errors.door.prelogin_failed', 'The remote system rejected the login request. Please contact the sysop.', [], 'errors'),
                'errors.door.session_id_required' => $t('errors.door.session_id_required', 'Session ID required', [], 'errors'),
                'errors.door.session_unauthorized' => $t('errors.door.session_unauthorized', 'Unauthorized', [], 'errors'),
                'errors.door.session_end_failed' => $t('errors.door.session_end_failed', 'Failed to end session', [], 'errors'),
                'errors.door.session_get_failed' => $t('errors.door.session_get_failed', 'Failed to get session', [], 'errors'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function interpolateTemplate(template, params) {
            if (!template || !params || typeof params !== 'object') {
                return template;
            }
            return String(template).replace(/\{(\w+)\}/g, (_, key) => {
                return Object.prototype.hasOwnProperty.call(params, key)
                    ? String(params[key])
                    : `{${key}}`;
            });
        }

        function resolveApiError(payload, fallback) {
            if (payload && payload.error_code && I18N.apiErrors[payload.error_code]) {
                return interpolateTemplate(I18N.apiErrors[payload.error_code], payload);
            }
            if (payload && payload.error) {
                return String(payload.error);
            }
            return fallback;
        }

        // Initialize terminal
        function initTerminal() {
            const container = document.getElementById('terminal-container');

            term = new Terminal({
                cursorBlink: true,
                cols: TERM_COLS,
                rows: TERM_ROWS,
                fontSize: 16,
                fontFamily: 'Courier New, monospace',
                scrollback: 0,
                theme: {
                    background: '#000000',
                    foreground: '#AAAAAA',
                    cursor: '#00FF00',
                    black: '#000000',
                    red: '#AA0000',
                    green: '#00AA00',
                    yellow: '#AA5500',
                    blue: '#0000AA',
                    magenta: '#AA00AA',
                    cyan: '#00AAAA',
                    white: '#AAAAAA',
                    brightBlack: '#555555',
                    brightRed: '#FF5555',
                    brightGreen: '#55FF55',
                    brightYellow: '#FFFF55',
                    brightBlue: '#5555FF',
                    brightMagenta: '#FF55FF',
                    brightCyan: '#55FFFF',
                    brightWhite: '#FFFFFF'
                },
                convertEol: false
            });

            term.open(container);
            term.resize(TERM_COLS, TERM_ROWS);
            scheduleFixedTerminalSize();

            // Plain passthrough - rlogin-connected hosts expect standard ANSI
            // terminal input, not the DOS Doorway Protocol scan-code framing
            // the local DOSBox/native door player uses.
            term.onData((data) => {
                if (socket && socket.readyState === WebSocket.OPEN) {
                    socket.send(data);
                }
            });

            term.onRender(() => {
                scheduleFixedTerminalSize();
            });
        }

        function updateStatus(message, state) {
            const statusDiv = document.getElementById('connectionStatus');
            statusDiv.textContent = I18N.statusPrefix + ' ' + message;
            statusDiv.className = 'connection-status status-' + state;
        }

        function showError(message) {
            const container = document.getElementById('terminal-container');
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            errorDiv.textContent = message;
            container.appendChild(errorDiv);
        }

        function launchDoorSession() {
            updateStatus(I18N.statusLaunching, 'connecting');
            term.writeln('\x1b[1;33m' + I18N.launchingDoorLine + '\x1b[0m');

            const formData = new FormData();
            formData.append('door', doorId);

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            return fetch('/api/door/launch', {
                method: 'POST',
                headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {},
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(resolveApiError(data, I18N.failedLaunchDoor));
                }
                return data.session;
            });
        }

        function connectToSession() {
            // Get current session for this specific door
            fetch('/api/door/session?door=' + encodeURIComponent(doorId))
                .then(response => response.json())
                .then(data => {
                    if (!data.success || !data.session) {
                        // No session exists, launch one
                        return launchDoorSession().then(session => {
                            data.session = session;
                            return data;
                        });
                    }
                    return data;
                })
                .then(data => {
                    if (!data.session) {
                        updateStatus(I18N.statusLaunchFailed, 'disconnected');
                        term.clear();
                        term.writeln('\x1b[1;31m' + I18N.failedLaunchLine + '\x1b[0m');
                        return;
                    }

                    sessionId = data.session.session_id;
                    wsPort = data.session.ws_port;
                    wsToken = data.session.ws_token;

                    const doorTitle = document.getElementById('doorTitle');
                    if (doorTitle && data.session.door_name) {
                        doorTitle.textContent = data.session.door_name;
                        document.title = data.session.door_name + ' - ' + I18N.documentTitleSuffix;
                    }

                    term.clear();

                    // Connect to WebSocket with authentication token
                    updateStatus(I18N.statusConnecting, 'connecting');
                    term.writeln('\x1b[1;33m' + I18N.connectingToPrefix + ' ' + data.session.door_name + '...\x1b[0m');

                    // Use WebSocket URL from server (configured or auto-detected)
                    const wsBaseUrl = data.session.ws_url || ('ws://' + window.location.hostname + ':' + wsPort);
                    const wsUrl = wsBaseUrl + (wsToken ? '?token=' + encodeURIComponent(wsToken) : '');
                    socket = new WebSocket(wsUrl);

                    socket.onopen = () => {
                        updateStatus(I18N.statusConnected, 'connected');
                        term.writeln('\x1b[1;32m' + I18N.connectedLine + '\x1b[0m');
                        term.writeln('');
                        term.focus();
                    };

                    socket.onmessage = (event) => {
                        term.write(event.data);
                    };

                    socket.onclose = (event) => {
                        updateStatus(I18N.statusDisconnected, 'disconnected');
                        term.writeln('');
                        term.writeln('\x1b[1;31m' + I18N.connectionClosedLine + '\x1b[0m');
                    };

                    socket.onerror = (error) => {
                        updateStatus(I18N.statusConnectionError, 'disconnected');
                        term.writeln('\x1b[1;31m' + I18N.connectionErrorLine + '\x1b[0m');
                        console.error('WebSocket error:', error);
                    };
                })
                .catch(error => {
                    console.error('Failed to get session:', error);
                    updateStatus(I18N.statusError, 'disconnected');
                    term.writeln('\x1b[1;31m' + I18N.failedToConnectPrefix + ' ' + error.message + '\x1b[0m');
                });
        }

        function endSession() {
            if (!sessionId) {
                return;
            }

            if (!confirm(I18N.confirmEndSession)) {
                return;
            }

            const csrfTokenEnd = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch('/api/door/end', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    ...(csrfTokenEnd ? { 'X-CSRF-Token': csrfTokenEnd } : {})
                },
                body: 'session_id=' + encodeURIComponent(sessionId)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (socket) {
                        socket.close();
                    }
                    window.top.location.href = '/games';
                } else {
                    alert(resolveApiError(data, I18N.failedEndSession));
                }
            })
            .catch(error => {
                console.error('Failed to end session:', error);
                alert(I18N.errorEndingSession);
            });
        }

        function setFixedTerminalSize() {
            if (!term || !term.element) {
                return;
            }
            const core = term._core;
            if (!core || !core._renderService || !core._renderService.dimensions) {
                return;
            }
            const dims = core._renderService.dimensions.css;
            if (!dims || !dims.cell) {
                return;
            }
            const width = Math.ceil(dims.cell.width * TERM_COLS);
            const height = Math.ceil(dims.cell.height * TERM_ROWS);
            term.element.style.width = width + 'px';
            term.element.style.height = height + 'px';
        }

        function scheduleFixedTerminalSize() {
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(setFixedTerminalSize);
            } else {
                setFixedTerminalSize();
            }
        }

        // Initialize on page load
        window.addEventListener('DOMContentLoaded', () => {
            if (!doorId) {
                showError(I18N.errorNoDoorSpecified);
                updateStatus(I18N.statusError, 'disconnected');
                return;
            }

            initTerminal();
            connectToSession();
        });

        window.addEventListener('resize', () => {
            scheduleFixedTerminalSize();
        });

        // End session button
        document.getElementById('endSessionBtn').addEventListener('click', endSession);

        // Clean up on page unload
        window.addEventListener('beforeunload', () => {
            if (socket) {
                socket.close();
            }
        });
    </script>
</body>
</html>
