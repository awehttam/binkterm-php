/**
 * admin-terminal.js — Admin log terminal widget.
 *
 * Renders a fixed toggle button (bottom-right) visible only to admins.
 * Clicking it opens a floating xterm.js panel that:
 *   - Streams admin BinkStream events as log lines
 *   - Accepts typed commands ("help" → "no help available")
 */
(function () {
    'use strict';

    if (!window.currentUserIsAdmin) {
        return;
    }

    var term = null;
    var fitAddon = null;
    var inputBuffer = '';
    var panelOpen = false;
    var initialized = false;

    // ── Stream stats ─────────────────────────────────────────────────────────────
    var streamWatch = false;
    var streamEventCount = 0;
    var streamConnectedAt = null;
    var streamLastEventType = null;
    var streamLastEventAt = null;

    // ── Persistent state ─────────────────────────────────────────────────────────
    // Namespace by user ID so different accounts on the same browser don't share
    // terminal state (output log, command history, panel open/closed).
    var LS_KEY = 'binkterm_admin_terminal_' + (window.currentUserId || '0');
    var MAX_LOG = 500;
    var MAX_HISTORY = 100;
    var outputLog = [];

    function saveState() {
        try {
            localStorage.setItem(LS_KEY, JSON.stringify({
                v: 1,
                streamWatch: streamWatch,
                panelOpen: panelOpen,
                outputLog: outputLog,
                history: history
            }));
        } catch (_) {}
    }

    function loadState() {
        try {
            var raw = localStorage.getItem(LS_KEY);
            if (!raw) { return; }
            var s = JSON.parse(raw);
            if (!s || s.v !== 1) { return; }
            streamWatch = !!s.streamWatch;
            outputLog = Array.isArray(s.outputLog) ? s.outputLog : [];
            history = Array.isArray(s.history) ? s.history : [];
            // panelOpen is NOT restored here — it is managed solely by openPanel()/closePanel().
            // The auto-restore IIFE reads it directly from storage before calling openPanel().
        } catch (_) {}
    }

    /** Write a line to the terminal AND persist it to the output log. */
    function logLine(str) {
        term.writeln(str);
        outputLog.push(str);
        if (outputLog.length > MAX_LOG) { outputLog = outputLog.slice(-MAX_LOG); }
        saveState();
    }

    var PROMPT_STR = '\x1b[32m>\x1b[0m ';

    // ── History & search state ────────────────────────────────────────────────────
    var history = [];
    var historyIndex = -1;   // -1 = current (unsaved) line
    var historyTemp = '';    // saves typed input while navigating history

    var searchMode = false;
    var searchBuffer = '';
    var searchMatches = [];
    var searchMatchIndex = 0;

    // ── DOM ─────────────────────────────────────────────────────────────────────

    var toggle = document.createElement('button');
    toggle.id = 'admin-term-toggle';
    toggle.title = 'Admin Terminal';
    toggle.innerHTML = '<i class="fa fa-terminal"></i>';
    toggle.setAttribute('aria-label', 'Admin Terminal');
    document.body.appendChild(toggle);

    var panel = document.createElement('div');
    panel.id = 'admin-term-panel';
    panel.setAttribute('aria-hidden', 'true');
    panel.innerHTML =
        '<div id="admin-term-header">' +
            '<span><i class="fa fa-terminal" style="margin-right:6px"></i>Admin Terminal</span>' +
            '<button id="admin-term-close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div id="admin-term-body"></div>';
    document.body.appendChild(panel);

    // ── Styles ───────────────────────────────────────────────────────────────────

    var style = document.createElement('style');
    style.textContent = [
        '#admin-term-toggle {',
        '    position: fixed; bottom: 18px; right: 18px; z-index: 9000;',
        '    width: 40px; height: 40px; border-radius: 50%;',
        '    background: #1a1a2e; color: #00d4ff; border: 1px solid #00d4ff;',
        '    cursor: pointer; font-size: 15px; line-height: 1;',
        '    box-shadow: 0 2px 8px rgba(0,0,0,0.4);',
        '    transition: background 0.2s, color 0.2s;',
        '}',
        '#admin-term-toggle:hover { background: #00d4ff; color: #1a1a2e; }',
        '#admin-term-panel {',
        '    position: fixed; bottom: 70px; right: 18px; z-index: 8999;',
        '    width: 680px; height: 320px;',
        '    background: #1a1a2e; border: 1px solid #00d4ff;',
        '    border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.6);',
        '    display: none; flex-direction: column; overflow: hidden;',
        '}',
        '#admin-term-panel.open { display: flex; }',
        '#admin-term-header {',
        '    display: flex; justify-content: space-between; align-items: center;',
        '    padding: 4px 10px; background: #0f0f1e;',
        '    color: #00d4ff; font-size: 12px; font-family: monospace;',
        '    border-bottom: 1px solid #00d4ff22; flex-shrink: 0;',
        '}',
        '#admin-term-close {',
        '    background: none; border: none; color: #00d4ff; cursor: pointer;',
        '    font-size: 18px; line-height: 1; padding: 0 2px;',
        '}',
        '#admin-term-close:hover { color: #fff; }',
        '#admin-term-body { flex: 1; overflow: hidden; padding: 4px; }',
        '@media (max-width: 767px) {',
        '    #admin-term-toggle, #admin-term-panel { display: none !important; }',
        '}'
    ].join('\n');
    document.head.appendChild(style);

    // ── Terminal init ────────────────────────────────────────────────────────────

    function initTerminal() {
        if (initialized) { return; }
        initialized = true;

        term = new Terminal({
            theme: {
                background:  '#1a1a2e',
                foreground:  '#c8c8d0',
                cursor:      '#00d4ff',
                cursorAccent:'#1a1a2e',
                black:       '#1a1a2e',
                green:       '#00d4ff',
                yellow:      '#ffd700',
                red:         '#ff5555',
                cyan:        '#00cfcf',
                white:       '#c8c8d0',
                brightGreen: '#7ee8fa',
            },
            fontFamily: '"Cascadia Code", "Fira Code", "Courier New", monospace',
            fontSize: 12,
            rows: 16,
            scrollback: 500,
            cursorBlink: true,
            convertEol: true,
        });

        fitAddon = new FitAddon.FitAddon();
        term.loadAddon(fitAddon);
        term.open(document.getElementById('admin-term-body'));
        fitAddon.fit();

        loadState();

        if (outputLog.length > 0) {
            outputLog.forEach(function (line) { term.writeln(line); });
            term.writeln('\x1b[90m--- reconnected ' + new Date().toLocaleTimeString() + ' ---\x1b[0m');
        } else {
            term.writeln('\x1b[32mBinktermPHP Admin Terminal\x1b[0m');
            term.writeln('\x1b[90mType "help" for available commands.\x1b[0m');
        }

        if (streamWatch) {
            term.writeln('\x1b[90mStream watch is \x1b[32mon\x1b[90m.\x1b[0m');
        }

        writePrompt();

        term.onData(handleInput);
        wireEvents();
    }

    // ── Input handling ───────────────────────────────────────────────────────────

    function writePrompt() {
        term.write('\r\n' + PROMPT_STR);
    }

    /** Replace the current terminal line with the normal prompt + content. */
    function replaceCurrentLine(content) {
        term.write('\r\x1b[2K' + PROMPT_STR + content);
        inputBuffer = content;
    }

    /** Rebuild search match list from the current searchBuffer. */
    function updateSearchMatches() {
        searchMatches = [];
        if (searchBuffer === '') { return; }
        for (var i = history.length - 1; i >= 0; i--) {
            if (history[i].indexOf(searchBuffer) !== -1) {
                searchMatches.push(history[i]);
            }
        }
        searchMatchIndex = 0;
    }

    /** Redraw the reverse-search prompt line. */
    function renderSearchPrompt() {
        var match = searchMatches.length > 0 ? searchMatches[searchMatchIndex] : '';
        var color = (searchBuffer !== '' && searchMatches.length === 0) ? '\x1b[31m' : '\x1b[36m';
        term.write('\r\x1b[2K' + color + '(reverse-i-search)\x1b[0m`' + searchBuffer + '\': ' + match);
    }

    /** Accept the current search match: place it on the line and execute. */
    function acceptSearch() {
        var match = searchMatches.length > 0 ? searchMatches[searchMatchIndex] : '';
        searchMode = false;
        searchBuffer = '';
        inputBuffer = '';
        historyIndex = -1;
        if (match !== '') {
            term.write('\r\x1b[2K' + PROMPT_STR + match);
            term.writeln('');
            // Push echo to log without writing to terminal — already on screen from term.write above.
            outputLog.push(PROMPT_STR + match);
            if (outputLog.length > MAX_LOG) { outputLog = outputLog.slice(-MAX_LOG); }
            pushHistory(match);
            runCommand(match);
        } else {
            term.write('\r\x1b[2K' + PROMPT_STR);
        }
        writePrompt();
    }

    /** Cancel search, restore whatever was being typed before Ctrl-R. */
    function cancelSearch() {
        searchMode = false;
        searchBuffer = '';
        term.write('\r\x1b[2K' + PROMPT_STR + inputBuffer);
    }

    function pushHistory(cmd) {
        if (history.length === 0 || history[history.length - 1] !== cmd) {
            history.push(cmd);
            if (history.length > MAX_HISTORY) { history = history.slice(-MAX_HISTORY); }
            saveState();
        }
    }

    function handleInput(data) {
        if (searchMode) {
            handleSearchInput(data);
            return;
        }

        if (data === '\r') {
            var cmd = inputBuffer.trim();
            inputBuffer = '';
            historyIndex = -1;
            if (cmd !== '') {
                term.writeln('');
                // Push echo to log without writing to terminal — it's already on screen from typing.
                outputLog.push(PROMPT_STR + cmd);
                if (outputLog.length > MAX_LOG) { outputLog = outputLog.slice(-MAX_LOG); }
                pushHistory(cmd);
                runCommand(cmd);
            } else {
                term.writeln('');
            }
            writePrompt();
        } else if (data === '\x7f' || data === '\x08') {
            // Backspace
            if (inputBuffer.length > 0) {
                inputBuffer = inputBuffer.slice(0, -1);
                term.write('\b \b');
            }
        } else if (data === '\x03') {
            // Ctrl-C
            inputBuffer = '';
            historyIndex = -1;
            term.writeln('^C');
            writePrompt();
        } else if (data === '\x12') {
            // Ctrl-R — enter reverse search
            searchMode = true;
            searchBuffer = '';
            searchMatches = [];
            searchMatchIndex = 0;
            renderSearchPrompt();
        } else if (data === '\x1b[A') {
            // Up arrow
            if (history.length === 0) { return; }
            if (historyIndex === -1) {
                historyTemp = inputBuffer;
                historyIndex = history.length - 1;
            } else if (historyIndex > 0) {
                historyIndex--;
            }
            replaceCurrentLine(history[historyIndex]);
        } else if (data === '\x1b[B') {
            // Down arrow
            if (historyIndex === -1) { return; }
            if (historyIndex < history.length - 1) {
                historyIndex++;
                replaceCurrentLine(history[historyIndex]);
            } else {
                historyIndex = -1;
                replaceCurrentLine(historyTemp);
            }
        } else if (data >= ' ') {
            inputBuffer += data;
            term.write(data);
        }
    }

    function handleSearchInput(data) {
        if (data === '\r') {
            acceptSearch();
        } else if (data === '\x1b' || data === '\x07') {
            // Escape or Ctrl-G — cancel
            cancelSearch();
        } else if (data === '\x12') {
            // Ctrl-R — cycle to next match
            if (searchMatches.length > 1) {
                searchMatchIndex = (searchMatchIndex + 1) % searchMatches.length;
                renderSearchPrompt();
            }
        } else if (data === '\x7f' || data === '\x08') {
            // Backspace
            if (searchBuffer.length > 0) {
                searchBuffer = searchBuffer.slice(0, -1);
                updateSearchMatches();
                renderSearchPrompt();
            }
        } else if (data >= ' ') {
            searchBuffer += data;
            updateSearchMatches();
            renderSearchPrompt();
        } else if (data === '\x1b[A' || data === '\x1b[B') {
            // Arrow keys cancel search and return to normal mode
            cancelSearch();
        }
    }

    var COMMANDS = [
        {
            cmd: 'clear',
            desc: 'Clear terminal output and saved log',
            run: function () {
                outputLog = [];
                saveState();
                term.clear();
            }
        },
        {
            cmd: 'help',
            desc: 'Show this help',
            run: function () {
                logLine('\x1b[1mCommands\x1b[0m');
                COMMANDS.forEach(function (c) {
                    if (c.hidden) { return; }
                    logLine('  \x1b[96m' + c.cmd + '\x1b[0m  —  ' + c.desc);
                });
            }
        },
        {
            cmd: 'stream',
            desc: 'Show stream status (mode, events received, last event)',
            run: function () {
                var bs = window.BinkStream;
                var mode = bs ? bs.getMode() : 'unavailable';
                var modeColors = { ws: '\x1b[32m', sse: '\x1b[33m', poll: '\x1b[31m' };
                var modeColor = modeColors[mode] || '\x1b[90m';
                term.writeln('\x1b[1mStream Status\x1b[0m');
                term.writeln('  Mode          ' + modeColor + mode + '\x1b[0m');
                term.writeln('  Watch         ' + (streamWatch ? '\x1b[32mon\x1b[0m' : '\x1b[90moff\x1b[0m'));
                term.writeln('  Events recv   \x1b[37m' + streamEventCount + '\x1b[0m');
                term.writeln('  Connected at  \x1b[37m' + (streamConnectedAt ? streamConnectedAt.toLocaleTimeString() : '-') + '\x1b[0m');
                term.writeln('  Last event    \x1b[37m' + (streamLastEventType || '-') + '\x1b[0m'
                    + (streamLastEventAt ? '  \x1b[90m' + streamLastEventAt.toLocaleTimeString() + '\x1b[0m' : ''));
            }
        },
        {
            cmd: 'who',
            desc: 'Show who is currently online',
            run: function () {
                logLine('\x1b[90mFetching online users…\x1b[0m');
                fetch('/api/whosonline', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var users = data.users || [];
                        // Erase the "fetching" line and replace with results
                        term.write('\r\x1b[A\x1b[2K');
                        if (users.length === 0) {
                            term.write('\r\x1b[2K');
                            logLine('\x1b[90mNo users online.\x1b[0m');
                        } else {
                            logLine('\x1b[1mOnline (' + users.length + ')\x1b[0m');
                            users.forEach(function (u) {
                                var svc  = u.service ? '\x1b[90m[' + u.service + ']\x1b[0m ' : '';
                                var loc  = u.location ? ' \x1b[90m(' + u.location + ')\x1b[0m' : '';
                                var act  = u.activity ? ' \x1b[90m— ' + u.activity + '\x1b[0m' : '';
                                logLine('  ' + svc + '\x1b[96m' + u.username + '\x1b[0m' + loc + act);
                            });
                        }
                        term.write(PROMPT_STR + inputBuffer);
                    })
                    .catch(function () {
                        term.write('\r\x1b[A\x1b[2K');
                        logLine('\x1b[31mFailed to fetch online users.\x1b[0m');
                        term.write(PROMPT_STR + inputBuffer);
                    });
            }
        },
        {
            cmd: 'finger',
            desc: 'finger <username>  —  Show user info and online status',
            run: function (parts) {
                var username = parts[1];
                if (!username) {
                    logLine('\x1b[31mUsage: finger <username>\x1b[0m');
                    return;
                }
                logLine('\x1b[90mLooking up ' + escapeForTerminal(username) + '…\x1b[0m');
                fetch('/admin/api/finger/' + encodeURIComponent(username), { credentials: 'same-origin' })
                    .then(function (r) {
                        if (r.status === 404) { throw new Error('not_found'); }
                        if (!r.ok) { throw new Error('error'); }
                        return r.json();
                    })
                    .then(function (u) {
                        term.write('\r\x1b[A\x1b[2K');
                        var badges = '';
                        if (u.is_admin)  { badges += ' \x1b[33m[admin]\x1b[0m'; }
                        if (!u.is_active){ badges += ' \x1b[31m[inactive]\x1b[0m'; }
                        logLine('\x1b[1m\x1b[96m' + u.username + '\x1b[0m' + badges);
                        if (u.real_name)       { logLine('  Real name  ' + u.real_name); }
                        if (u.location)        { logLine('  Location   ' + u.location); }
                        if (u.fidonet_address) { logLine('  FTN addr   ' + u.fidonet_address); }
                        if (u.last_login) {
                            logLine('  Last login  ' + new Date(u.last_login).toLocaleString());
                        }
                        if (u.online && u.online.length > 0) {
                            logLine('  \x1b[32mCurrently online\x1b[0m (' + u.online.length + ' session' + (u.online.length > 1 ? 's' : '') + ')');
                            u.online.forEach(function (s) {
                                var line = '    \x1b[90m[' + (s.service || 'web') + ']\x1b[0m';
                                if (s.activity)      { line += ' ' + s.activity; }
                                if (s.last_activity) { line += '  \x1b[90m' + new Date(s.last_activity).toLocaleTimeString() + '\x1b[0m'; }
                                logLine(line);
                            });
                        } else {
                            logLine('  \x1b[90mNot currently online\x1b[0m');
                        }
                        term.write(PROMPT_STR + inputBuffer);
                    })
                    .catch(function (e) {
                        term.write('\r\x1b[A\x1b[2K');
                        logLine(e.message === 'not_found'
                            ? '\x1b[31mUser not found: ' + escapeForTerminal(username) + '\x1b[0m'
                            : '\x1b[31mFailed to look up user.\x1b[0m');
                        term.write(PROMPT_STR + inputBuffer);
                    });
            }
        },
        {
            cmd: 'stream watch',
            desc: 'Toggle live stream event output on/off',
            hidden: true,
            run: function () {
                streamWatch = !streamWatch;
                logLine('Stream watch ' + (streamWatch ? '\x1b[32mon\x1b[0m' : '\x1b[31moff\x1b[0m') + '.');
                saveState();
            }
        },
        {
            cmd: 'wall',
            desc: 'wall <message>  —  Broadcast a message to all online users',
            run: function (parts) {
                var message = parts.slice(1).join(' ');
                if (!message) {
                    logLine('\x1b[31mUsage: wall <message>\x1b[0m');
                    return;
                }
                fetch('/admin/api/wall', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: message })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            logLine('\x1b[32mWall message sent.\x1b[0m');
                        } else {
                            logLine('\x1b[31mFailed: ' + escapeForTerminal(data.error || 'unknown error') + '\x1b[0m');
                        }
                        term.write(PROMPT_STR + inputBuffer);
                    })
                    .catch(function () {
                        logLine('\x1b[31mFailed to send wall message.\x1b[0m');
                        term.write(PROMPT_STR + inputBuffer);
                    });
            }
        },
        {
            cmd: 'msg',
            desc: 'msg <username> <message>  —  Send a private message to a specific user',
            run: function (parts) {
                var username = parts[1];
                var message = parts.slice(2).join(' ');
                if (!username || !message) {
                    logLine('\x1b[31mUsage: msg <username> <message>\x1b[0m');
                    return;
                }
                fetch('/admin/api/msg', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username: username, message: message })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            logLine('\x1b[32mMessage sent to ' + escapeForTerminal(username) + '.\x1b[0m');
                        } else {
                            logLine('\x1b[31mFailed: ' + escapeForTerminal(data.error || 'unknown error') + '\x1b[0m');
                        }
                        term.write(PROMPT_STR + inputBuffer);
                    })
                    .catch(function () {
                        logLine('\x1b[31mFailed to send message.\x1b[0m');
                        term.write(PROMPT_STR + inputBuffer);
                    });
            }
        },
        {
            cmd: 'uplinks',
            desc: 'List configured binkp uplink addresses',
            run: function () {
                fetch('/admin/api/uplinks', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success && data.uplinks.length) {
                            data.uplinks.forEach(function (u) {
                                logLine('  \x1b[96m' + escapeForTerminal(u.address) + '\x1b[0m'
                                    + (u.domain ? '  \x1b[90m' + escapeForTerminal(u.domain) + '\x1b[0m' : ''));
                            });
                        } else {
                            logLine('\x1b[90mNo uplinks configured.\x1b[0m');
                        }
                        term.write(PROMPT_STR + inputBuffer);
                    })
                    .catch(function () {
                        logLine('\x1b[31mFailed to fetch uplinks.\x1b[0m');
                        term.write(PROMPT_STR + inputBuffer);
                    });
            }
        },
        {
            cmd: 'poll',
            desc: 'poll [<address>|all]  —  Trigger a binkp poll (default: all uplinks)',
            run: function (parts) {
                var upstream = parts[1] || 'all';
                logLine('\x1b[90mPolling ' + escapeForTerminal(upstream) + '...\x1b[0m');
                fetch('/admin/api/poll', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ upstream: upstream })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success && data.result) {
                            var out = (data.result.stdout || '').trim();
                            var err = (data.result.stderr || '').trim();
                            if (out) {
                                out.split('\n').forEach(function (line) {
                                    logLine('\x1b[37m' + escapeForTerminal(line) + '\x1b[0m');
                                });
                            }
                            if (err) {
                                err.split('\n').forEach(function (line) {
                                    logLine('\x1b[31m' + escapeForTerminal(line) + '\x1b[0m');
                                });
                            }
                            var code = data.result.exit_code;
                            logLine(code === 0
                                ? '\x1b[32mPoll completed.\x1b[0m'
                                : '\x1b[31mPoll exited with code ' + code + '.\x1b[0m');
                        } else {
                            logLine('\x1b[31mFailed: ' + escapeForTerminal((data && data.error) || 'unknown error') + '\x1b[0m');
                        }
                        term.write(PROMPT_STR + inputBuffer);
                    })
                    .catch(function () {
                        logLine('\x1b[31mFailed to trigger poll.\x1b[0m');
                        term.write(PROMPT_STR + inputBuffer);
                    });
            }
        },
    ];

    function runCommand(cmd) {
        var parts = cmd.toLowerCase().split(/\s+/);
        var originalParts = cmd.split(/\s+/);
        // Try longest match first (e.g. "stream watch" before "stream")
        var twoWord = parts.length >= 2 ? parts[0] + ' ' + parts[1] : null;
        var entry = (twoWord && COMMANDS.find(function (c) { return c.cmd === twoWord; }))
                 || COMMANDS.find(function (c) { return c.cmd === parts[0]; });
        if (entry) {
            // Pass originalParts so commands that use trailing arguments (like wall/msg)
            // receive the message text with its original casing intact.
            entry.run(originalParts);
        } else {
            logLine('\x1b[31mUnknown command: ' + escapeForTerminal(parts[0]) + '\x1b[0m');
        }
    }

    function escapeForTerminal(str) {
        return String(str).replace(/[\x00-\x1f\x7f]/g, '');
    }

    // ── BinkStream event wiring ──────────────────────────────────────────────────

    function writeEventLine(label, color, data) {
        // Clear the current input line, write + persist the event, then restore the prompt.
        term.write('\r\x1b[K');
        var ts = new Date().toLocaleTimeString();
        var payload = (data !== null && data !== undefined)
            ? ' ' + JSON.stringify(data)
            : '';
        var line = '\x1b[90m' + ts + '\x1b[0m ' + color + label + '\x1b[0m' + payload;
        logLine(line);
        if (searchMode) {
            renderSearchPrompt();
        } else {
            term.write(PROMPT_STR + inputBuffer);
        }
    }

    // Known SSE event types — subscribing tells the worker to fetch them from the server.
    var SSE_TYPES = [
        'binkp_session',
        'file_approvals_changed',
        'files_changed',
        'message_read',
        'sse_test',
        'dashboard_stats',
        'chat_message',
        'wall_message',
    ];

    function wireEvents() {
        if (typeof window.BinkStream === 'undefined') { return; }

        streamConnectedAt = new Date();

        // Subscribe to all known types so the worker fetches them from the SSE stream.
        SSE_TYPES.forEach(function (type) {
            window.BinkStream.on(type, function () {});
        });

        // Wildcard listener — always tracks stats, only displays when watch is on.
        window.BinkStream.on('*', function (type, data) {
            streamEventCount++;
            streamLastEventType = type;
            streamLastEventAt = new Date();

            if (!streamWatch) { return; }

            writeEventLine('[' + type + ']', '\x1b[36m', data);
        });
    }

    // ── Toggle panel ─────────────────────────────────────────────────────────────

    function openPanel() {
        panelOpen = true;
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        toggle.classList.add('active');
        initTerminal();
        saveState();
        // Slight delay so the flex layout has settled before fit
        setTimeout(function () { if (fitAddon) { fitAddon.fit(); } term.focus(); }, 50);
    }

    function closePanel() {
        panelOpen = false;
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        toggle.classList.remove('active');
        saveState();
    }

    // Ensure state is saved before navigating away.
    window.addEventListener('beforeunload', saveState);

    // Restore open state from previous session. Deferred so the browser has
    // completed layout before xterm tries to measure the container.
    setTimeout(function () {
        try {
            var raw = localStorage.getItem(LS_KEY);
            if (raw) {
                var s = JSON.parse(raw);
                if (s && s.v === 1 && s.panelOpen) { openPanel(); }
            }
        } catch (_) {}
    }, 0);

    toggle.addEventListener('click', function () {
        if (panelOpen) { closePanel(); } else { openPanel(); }
    });

    document.getElementById('admin-term-close').addEventListener('click', closePanel);

    // Close on Escape (but not when reverse-search is active — that cancels search instead)
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panelOpen && !searchMode) { closePanel(); }
    });

    // Resize
    window.addEventListener('resize', function () {
        if (panelOpen && fitAddon) { fitAddon.fit(); }
    });

}());
