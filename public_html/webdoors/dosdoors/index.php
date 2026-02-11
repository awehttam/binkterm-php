<?php
/**
 * DOS Door Player
 *
 * This file can be included by routes or accessed directly.
 * When included, $doorId should be set by the calling code.
 */

use BinktermPHP\RouteHelper;

$user = RouteHelper::requireAuth();

// If accessed directly, try to extract door ID from URL
if (!isset($doorId)) {
    $requestUri = $_SERVER['REQUEST_URI'];
    $pattern = '#/webdoors/dosdoors/([^/]+)#';
    preg_match($pattern, $requestUri, $matches);
    $doorId = $matches[1] ?? '';

    // Clean the door ID (remove query string if present)
    $doorId = preg_replace('/\?.*$/', '', $doorId);
}

if (empty($doorId)) {
    http_response_code(404);
    echo "Error: No door specified";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DOS Door Player</title>
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
        <h5 class="door-header" id="doorTitle">DOS Door Player</h5>
        <div id="connectionStatus" class="connection-status status-disconnected">
            Status: Disconnected
        </div>
        <button id="endSessionBtn">End Session</button>
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

        // Initialize terminal
        function initTerminal() {
            console.log('[INIT] Starting terminal initialization');
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

            console.log('[INIT] Terminal created, opening in container');
            term.open(container);
            term.resize(TERM_COLS, TERM_ROWS);
            scheduleFixedTerminalSize();

            // Handle terminal input
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
            statusDiv.textContent = 'Status: ' + message;
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
            console.log('[LAUNCH] Launching door session for:', doorId);
            updateStatus('Launching...', 'connecting');
            term.writeln('\x1b[1;33mLaunching door game...\x1b[0m');

            const formData = new FormData();
            formData.append('door', doorId);

            return fetch('/api/door/launch', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('[LAUNCH] Launch response:', data);
                if (!data.success) {
                    throw new Error(data.error || data.message || 'Failed to launch door');
                }
                return data.session;
            });
        }

        function connectToSession() {
            console.log('[CONNECT] connectToSession called, term exists:', !!term);

            // Get current session
            fetch('/api/door/session')
                .then(response => response.json())
                .then(data => {
                    console.log('[CONNECT] Session data received:', data);

                    if (!data.success || !data.session) {
                        // No session exists, launch one
                        console.log('[CONNECT] No session found, launching...');
                        return launchDoorSession().then(session => {
                            data.session = session;
                            return data;
                        });
                    }
                    return data;
                })
                .then(data => {
                    if (!data.session) {
                        updateStatus('Launch failed', 'disconnected');
                        term.clear();
                        term.writeln('\x1b[1;31mFailed to launch door session.\x1b[0m');
                        return;
                    }

                    sessionId = data.session.session_id;
                    wsPort = data.session.ws_port;
                    wsToken = data.session.ws_token;
                    console.log('[TOKEN] Received token:', wsToken ? wsToken.substring(0, 16) + '...' : 'MISSING!');

                    const doorTitle = document.getElementById('doorTitle');
                    if (doorTitle && data.session.door_name) {
                        doorTitle.textContent = data.session.door_name;
                        document.title = data.session.door_name + ' - DOS Door';
                    }

                    // Clear terminal initialization artifacts
                    console.log('[CONNECT] Clearing terminal');
                    term.clear();

                    // Connect to WebSocket with authentication token
                    updateStatus('Connecting...', 'connecting');
                    term.writeln('\x1b[1;33mConnecting to ' + data.session.door_name + '...\x1b[0m');

                    // Use WebSocket URL from server (configured or auto-detected)
                    const wsBaseUrl = data.session.ws_url || ('ws://' + window.location.hostname + ':' + wsPort);
                    const wsUrl = wsBaseUrl + (wsToken ? '?token=' + encodeURIComponent(wsToken) : '');
                    console.log('[CONNECT] Connecting to WebSocket:', wsBaseUrl + ' (token present:', !!wsToken, ')');
                    socket = new WebSocket(wsUrl);

                    socket.onopen = () => {
                        updateStatus('Connected', 'connected');
                        term.writeln('\x1b[1;32mConnected!\x1b[0m');
                        term.writeln('');
                        term.focus();
                    };

                    let messageCount = 0;
                    socket.onmessage = (event) => {
                        messageCount++;

                        // Debug: log received data
                        if (event.data.length < 100) {
                            console.log('[WS recv #' + messageCount + ']', JSON.stringify(event.data));
                        } else {
                            console.log('[WS recv #' + messageCount + ']', event.data.length, 'bytes');
                        }

                        term.write(event.data);
                    };

                    socket.onclose = (event) => {
                        updateStatus('Disconnected', 'disconnected');
                        term.writeln('');
                        term.writeln('\x1b[1;31m[Connection closed]\x1b[0m');
                    };

                    socket.onerror = (error) => {
                        updateStatus('Connection error', 'disconnected');
                        term.writeln('\x1b[1;31m[Connection error]\x1b[0m');
                        console.error('WebSocket error:', error);
                    };
                })
                .catch(error => {
                    console.error('Failed to get session:', error);
                    updateStatus('Error', 'disconnected');
                    term.writeln('\x1b[1;31mFailed to connect: ' + error.message + '\x1b[0m');
                });
        }

        function endSession() {
            if (!sessionId) {
                return;
            }

            if (!confirm('Are you sure you want to end this door session?')) {
                return;
            }

            fetch('/api/door/end', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'session_id=' + encodeURIComponent(sessionId)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (socket) {
                        socket.close();
                    }
                    window.location.href = '/games';
                } else {
                    alert('Failed to end session');
                }
            })
            .catch(error => {
                console.error('Failed to end session:', error);
                alert('Error ending session');
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
                showError('Error: No door ID specified');
                updateStatus('Error', 'disconnected');
                return;
            }

            console.log('[INIT] Door ID:', doorId);
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
