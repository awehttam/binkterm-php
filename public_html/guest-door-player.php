<?php
/**
 * Guest Door Player
 *
 * Public, unauthenticated player for native doors that have allow_anonymous enabled.
 * Included by the /play/{doorid} route — $doorId must be set by the caller.
 */

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
    <title>Public Terminal</title>
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

        #leaveBtn {
            padding: 4px 12px;
            font-size: 12px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            justify-self: end;
            font-family: monospace;
        }

        #leaveBtn:hover {
            background: #5a6268;
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
        <h5 class="door-header" id="doorTitle">Public Terminal</h5>
        <div id="connectionStatus" class="connection-status status-disconnected">
            Status: Disconnected
        </div>
        <button id="leaveBtn">Leave</button>
    </div>
    <div id="terminal-container"></div>

    <script src="/webdoors/terminal/assets/xterm.js"></script>
    <script>
        let term = null;
        const TERM_COLS = 80;
        const TERM_ROWS = 25;
        let socket = null;
        let sessionId = null;
        const doorId = <?php echo json_encode($doorId); ?>;

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

            term.onData((data) => {
                // Remap DEL (0x7f) to Backspace (0x08) for DOS compatibility
                if (data === '\x7f') data = '\x08';
                if (socket && socket.readyState === WebSocket.OPEN) {
                    socket.send(data);
                }
            });

            // Key handler: intercept extended keys before xterm generates ANSI sequences.
            // DOS apps running via Doorway expect Doorway Protocol (\x00 + IBM PC scan code)
            // for navigation/function keys, not ANSI escape sequences.
            term.attachCustomKeyEventHandler((e) => {
                if (e.type !== 'keydown') return true;

                const doorwayKeys = {
                    'ArrowUp':    0x48, 'ArrowDown':  0x50,
                    'ArrowLeft':  0x4B, 'ArrowRight': 0x4D,
                    'Home':       0x47, 'End':        0x4F,
                    'PageUp':     0x49, 'PageDown':   0x51,
                    'Insert':     0x52, 'Delete':     0x53,
                    'F1':  0x3B, 'F2':  0x3C, 'F3':  0x3D, 'F4':  0x3E,
                    'F5':  0x3F, 'F6':  0x40, 'F7':  0x41, 'F8':  0x42,
                    'F9':  0x43, 'F10': 0x44, 'F11': 0x85, 'F12': 0x86,
                };
                const doorwayCtrlKeys = {
                    'ArrowLeft':  0x73, 'ArrowRight': 0x74,
                    'Home':       0x77, 'End':        0x75,
                    'PageUp':     0x84, 'PageDown':   0x76,
                };

                if (!e.altKey) {
                    let scanCode = null;
                    if (e.ctrlKey && doorwayCtrlKeys[e.key] !== undefined) {
                        scanCode = doorwayCtrlKeys[e.key];
                    } else if (!e.ctrlKey && doorwayKeys[e.key] !== undefined) {
                        scanCode = doorwayKeys[e.key];
                    }
                    if (scanCode !== null) {
                        if (socket && socket.readyState === WebSocket.OPEN) {
                            socket.send('\x00' + String.fromCharCode(scanCode));
                        }
                        return false;
                    }
                }

                if (e.ctrlKey && !e.altKey && e.key.length === 1) {
                    e.preventDefault();
                    return true;
                }

                return true;
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

        function launchGuestSession() {
            updateStatus('Launching...', 'connecting');
            term.writeln('\x1b[1;33mConnecting as guest...\x1b[0m');

            const formData = new FormData();
            formData.append('door', doorId);

            return fetch('/api/door/guest/launch', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || data.error || 'Failed to launch door');
                }
                return data.session;
            });
        }

        function connectToSession() {
            launchGuestSession()
                .then(session => {
                    sessionId = session.session_id;
                    const wsPort = session.ws_port;
                    const wsToken = session.ws_token;

                    const doorTitle = document.getElementById('doorTitle');
                    if (doorTitle && session.door_name) {
                        doorTitle.textContent = session.door_name;
                        document.title = session.door_name + ' - Guest';
                    }

                    term.clear();
                    updateStatus('Connecting...', 'connecting');
                    term.writeln('\x1b[1;33mConnecting to ' + session.door_name + '...\x1b[0m');

                    const wsBaseUrl = session.ws_url || ('ws://' + window.location.hostname + ':' + wsPort);
                    const wsUrl = wsBaseUrl + (wsToken ? '?token=' + encodeURIComponent(wsToken) : '');
                    socket = new WebSocket(wsUrl);

                    socket.onopen = () => {
                        updateStatus('Connected', 'connected');
                        term.writeln('\x1b[1;32mConnected!\x1b[0m');
                        term.writeln('');
                        term.focus();
                    };

                    socket.onmessage = (event) => {
                        term.write(event.data);
                    };

                    socket.onclose = () => {
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
                    console.error('Failed to launch guest session:', error);
                    updateStatus('Error', 'disconnected');
                    if (term) {
                        term.writeln('\x1b[1;31mFailed to connect: ' + error.message + '\x1b[0m');
                    } else {
                        showError(error.message);
                    }
                });
        }

        function leave() {
            if (socket) {
                socket.close();
            }
            window.close();
        }

        function setFixedTerminalSize() {
            if (!term || !term.element) return;
            const core = term._core;
            if (!core || !core._renderService || !core._renderService.dimensions) return;
            const dims = core._renderService.dimensions.css;
            if (!dims || !dims.cell) return;
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

        window.addEventListener('DOMContentLoaded', () => {
            if (!doorId) {
                showError('Error: No door ID specified');
                updateStatus('Error', 'disconnected');
                return;
            }

            initTerminal();
            connectToSession();
        });

        window.addEventListener('resize', () => {
            scheduleFixedTerminalSize();
        });

        document.getElementById('leaveBtn').addEventListener('click', leave);

        window.addEventListener('beforeunload', () => {
            if (socket) {
                socket.close();
            }
        });

        // Alt+key handling via capture phase
        document.addEventListener('keydown', function(e) {
            if (!e.altKey || e.ctrlKey || !term || !socket || socket.readyState !== WebSocket.OPEN) return;

            const altLetterCodes = {
                'a': 0x1E, 'b': 0x30, 'c': 0x2E, 'd': 0x20,
                'e': 0x12, 'f': 0x21, 'g': 0x22, 'h': 0x23,
                'i': 0x17, 'j': 0x24, 'k': 0x25, 'l': 0x26,
                'm': 0x32, 'n': 0x31, 'o': 0x18, 'p': 0x19,
                'q': 0x10, 'r': 0x13, 's': 0x1F, 't': 0x14,
                'u': 0x16, 'v': 0x2F, 'w': 0x11, 'x': 0x2D,
                'y': 0x15, 'z': 0x2C
            };
            const altDigitCodes = {
                '1': 0x78, '2': 0x79, '3': 0x7A, '4': 0x7B, '5': 0x7C,
                '6': 0x7D, '7': 0x7E, '8': 0x7F, '9': 0x80, '0': 0x81
            };

            let scanCode = null;
            if (e.code.startsWith('Key')) {
                const ch = e.code.slice(3).toLowerCase();
                scanCode = altLetterCodes[ch] ?? null;
            } else if (e.code.startsWith('Digit')) {
                const digit = e.code.slice(5);
                scanCode = altDigitCodes[digit] ?? null;
            }

            if (scanCode !== null) {
                e.preventDefault();
                e.stopPropagation();
                socket.send('\x00' + String.fromCharCode(scanCode));
            }
        }, true);
    </script>
</body>
</html>
