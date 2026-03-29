/**
 * MRC Chat Client
 * Handles real-time chat interface via BinkStream SSE events,
 * with a simple interval-poll fallback for environments without SharedWorker.
 */

// Required by ansisys.js parsePipeCodes (normally provided by app.js)
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

class MrcClient {
    constructor() {
        this.joinedRoom = null;
        this.viewMode = 'room'; // room | private
        this.privateUser = null;
        this.pollTimer = null;
        this.binkStreamActive = false;
        this.lastRoomsPollAt = 0;
        this.lastMessageId = 0;
        this.lastPrivateMessageId = 0;
        this.lastPrivateGlobalId = 0;
        this.lastSystemMessageId = 0;
        this.privateUnread = {};
        this.unreadInitDone = false;
        this.seenMessageIds = new Set();
        this.seenPrivateMessageIds = new Set();
        this.seenSystemMessageIds = new Set();
        this.lastPresencePingAt = 0;
        this.autoScroll = true;
        this.username = window.mrcCurrentUser || null;
        this.localBbs = window.mrcCurrentBbs || null;
        this.missingPresenceCount = 0;
        this.inputHistory = [];
        this.historyIndex = -1;
        this.historySavedInput = '';
        this.currentUsers = [];
        this.tabState = null; // { prefix, matches, index } while cycling

        this.initConnectScreen();
    }

    /**
     * Wire the connect screen and decide whether to show it or auto-connect.
     */
    initConnectScreen() {
        const savedHandle = UserStorage.getItem('mrc_handle');
        if (savedHandle) {
            $('#connect-username').val(savedHandle);
        }

        $('#connect-btn').on('click', () => {
            const username = $('#connect-username').val();
            const password = $('#connect-password').val();
            this.connect(username, password);
        });

        $('#connect-username, #connect-password').on('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.connect($('#connect-username').val(), $('#connect-password').val());
            }
        });

        // Auto-connect if a prior session was stored
        if (UserStorage.getItem('mrc_session') === '1') {
            this.connect($('#connect-username').val(), '', true);
        }
    }

    /**
     * Connect to MRC. If autoConnect is true the connect screen is never shown.
     */
    async connect(username = '', password = '', autoConnect = false) {
        $('#connect-btn').prop('disabled', true).text('Connecting…');
        $('#connect-error').addClass('d-none');

        try {
            const response = await $.ajax({
                url: 'api.php?action=connect',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ username: username, password: password }),
                dataType: 'json'
            });

            this.username = response.username || String(username || '').trim() || this.username;
            UserStorage.setItem('mrc_session', '1');
            UserStorage.setItem('mrc_handle', this.username || '');
            $('#connect-username').val(this.username || '');
            $('#mrc-connect-screen').addClass('d-none');
            $('#mrc-app').removeClass('d-none');
            this.init();
            await this.sendCommand('motd', []);
        } catch (error) {
            $('#connect-btn').prop('disabled', false).text('Connect');
            $('#connect-error')
                .text('Connection failed. Please try again.')
                .removeClass('d-none');
        }
    }

    /**
     * Disconnect from MRC, send LOGOFF, and return to the connect screen.
     */
    async disconnect() {
        // Stop polling
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }

        try {
            await $.ajax({
                url: 'api.php?action=disconnect',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({}),
                dataType: 'json'
            });
        } catch (_) {}

        UserStorage.removeItem('mrc_session');
        this.joinedRoom = null;

        $('#mrc-app').addClass('d-none');
        $('#mrc-connect-screen').removeClass('d-none');
        $('#connect-btn').prop('disabled', false).text('Connect');
        $('#connect-username').val(UserStorage.getItem('mrc_handle') || this.username || '');
        $('#connect-password').val('');
        $('#connect-error').addClass('d-none');
    }

    init() {
        // Setup event handlers
        $('#message-form').on('submit', (e) => {
            e.preventDefault();
            this.sendMessage();
        });

        $('#message-input').on('input', () => {
            this.updateCharCount();
        });

        // Tab completion — prevent default and complete.
        // Also notify the parent page so it can refocus us if the browser
        // moves focus out of the iframe despite preventDefault.
        document.getElementById('message-input').addEventListener('keydown', (e) => {
            if (e.key === 'Tab') {
                e.preventDefault();
                window.parent.postMessage({ type: 'mrc:keepFocus' }, '*');
                this.handleTabComplete();
            }
        });

        $('#message-input').on('keydown', (e) => {
            // Any key other than Tab/Shift resets tab-completion cycling
            if (e.key !== 'Tab' && e.key !== 'Shift') {
                this.tabState = null;
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (this.inputHistory.length === 0) return;
                if (this.historyIndex === -1) {
                    this.historySavedInput = $('#message-input').val();
                }
                this.historyIndex = Math.min(this.historyIndex + 1, this.inputHistory.length - 1);
                $('#message-input').val(this.inputHistory[this.historyIndex]);
                this.updateCharCount();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (this.historyIndex === -1) return;
                this.historyIndex--;
                const value = this.historyIndex === -1
                    ? this.historySavedInput
                    : this.inputHistory[this.historyIndex];
                $('#message-input').val(value);
                this.updateCharCount();
            }
        });


        $('#refresh-btn').on('click', () => {
            this.refreshMessages();
        });

        $('#disconnect-btn').on('click', () => {
            this.disconnect();
        });

        $('#room-select').on('change', () => {
            const room = $('#room-select').val();
            if (room) {
                this.joinRoom(room);
            }
        });

        $('#private-chat-exit').on('click', (e) => {
            e.preventDefault();
            this.exitPrivateChat(true);
        });


        // Disable auto-scroll when user scrolls up
        $('#chat-messages').on('scroll', () => {
            this.updateAutoScroll();
        });

        // Keep presence alive on user activity (covers background tab throttling)
        const activityHandler = () => this.pingPresence();
        $(document).on('keydown mousedown mousemove touchstart', activityHandler);


        // Subscribe before the first poll so realtime handlers are registered
        // before any join-related events arrive.
        this.subscribeBinkStream();

        // Check connection status, then recheck every 30 s
        this.checkStatus();
        setInterval(() => this.checkStatus(), 30000);

        // Initial poll (includes rooms + unread init)
        this.poll(true, true);
    }

    /**
     * Check MRC daemon status and update the UI overlay accordingly.
     */
    async checkStatus() {
        try {
            const response = await $.ajax({
                url: 'api.php?action=status',
                method: 'GET',
                dataType: 'json'
            });

            if (response.success) {
                this.updateConnectionStatus(response.connected, response.enabled, response.daemon_running);
            }
        } catch (error) {
            console.error('Status check failed:', error);
            this.updateConnectionStatus(false, false, false);
        }
    }

    /**
     * Update connection status indicator and daemon-offline overlay.
     *
     * @param {boolean} connected  - daemon is connected to the MRC server
     * @param {boolean} enabled    - MRC is enabled in config
     * @param {boolean} daemonRunning - daemon process is alive (heartbeat recent)
     */
    updateConnectionStatus(connected, enabled, daemonRunning) {
        const statusEl = $('#connection-status');

        if (!enabled) {
            statusEl.html('<i class="bi bi-circle-fill text-danger" title="MRC Disabled"></i>');
        } else if (connected) {
            statusEl.html('<i class="bi bi-circle-fill text-success" title="Connected"></i>');
        } else {
            statusEl.html('<i class="bi bi-circle-fill text-warning" title="Connecting..."></i>');
        }

        const overlay = $('#mrc-daemon-overlay');
        const isDown = enabled && !daemonRunning;

        if (isDown) {
            overlay.removeClass('d-none');
            $('#message-input').prop('disabled', true);
            $('#send-btn').prop('disabled', true);
            this.startDaemonCountdown();
        } else {
            overlay.addClass('d-none');
            $('#message-input').prop('disabled', false);
            $('#send-btn').prop('disabled', false);
            this.stopDaemonCountdown();
        }
    }

    /**
     * Start (or restart) the countdown timer shown in the daemon-offline overlay.
     */
    startDaemonCountdown() {
        this.stopDaemonCountdown();
        let secs = 30;
        $('#mrc-daemon-countdown').text(secs);
        this._daemonCountdownTimer = setInterval(() => {
            secs--;
            if (secs <= 0) {
                secs = 30;
            }
            $('#mrc-daemon-countdown').text(secs);
        }, 1000);
    }

    /** Stop the countdown timer. */
    stopDaemonCountdown() {
        if (this._daemonCountdownTimer) {
            clearInterval(this._daemonCountdownTimer);
            this._daemonCountdownTimer = null;
        }
    }

    /**
     * Render rooms dropdown
     */
    renderRooms(rooms) {
        const select = $('#room-select');
        const current = select.val();

        select.empty().append($('<option>').val('').text('— join a room —'));

        if (rooms && rooms.length > 0) {
            rooms.forEach(room => {
                const label = `#${room.room_name}`
                    + (room.user_count ? ` (${room.user_count})` : '');
                select.append($('<option>').val(room.room_name).text(label));
            });
        }

        // Restore selection: prefer joinedRoom, then previous value
        const restore = this.joinedRoom || current;
        if (restore) {
            select.val(restore);
        }
    }

    /**
     * Join a room
     */
    async joinRoom(roomName) {
        roomName = String(roomName || '').trim().replace(/^#/, '');
        if (!/^[A-Za-z0-9]{1,20}$/.test(roomName)) {
            this.showError('Invalid room name.');
            return;
        }

        if (this.joinedRoom && this.joinedRoom === roomName) {
            if (this.viewMode !== 'room') {
                this.exitPrivateChat(true);
                await this.poll(false);
            } else {
                await this.refreshMessages();
            }
            $('#message-input').focus();
            return;
        }

        try {
            const response = await $.ajax({
                url: 'api.php?action=join',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ room: roomName, from_room: this.joinedRoom || '' }),
                dataType: 'json'
            });

            if (response.success) {
                this.joinedRoom = roomName;
                this.lastMessageId = typeof response.last_message_id === 'number'
                    ? response.last_message_id
                    : 0;
                this.seenMessageIds.clear();
                this.missingPresenceCount = 0;
                this.exitPrivateChat();

                // Update UI
                $('#room-select').val(roomName);
                $('#current-room-topic').text('');
                $('#message-input').attr('placeholder', 'Type a message... (max 140 chars)');

                // Load initial data (includes rooms + users + messages)
                await this.poll(true);

                // Focus message input
                $('#message-input').focus();
            }
        } catch (error) {
            console.error('Failed to join room:', error);
            this.showError('Failed to join room. Please try again.');
        }
    }

    /**
     * Render messages in chat area
     */
    renderMessages(messages) {
        const chatArea = $('#chat-messages');

        messages.forEach(msg => {
            const msgId = msg.id;
            if (this.viewMode === 'private') {
                if (this.seenPrivateMessageIds.has(msgId)) {
                    return;
                }
                this.seenPrivateMessageIds.add(msgId);
            } else {
                if (this.seenMessageIds.has(msgId)) {
                    return;
                }
                this.seenMessageIds.add(msgId);
            }
            const messageEl = this.createMessageElement(msg);
            chatArea.append(messageEl);

            if (msg.from_user === 'SERVER') {
                this.handleServerNotice(msg.message_body || '');
            }
        });

        // Auto-scroll to bottom
        if (this.autoScroll) {
            this.scrollToBottom();
        }
    }

    /**
     * Create message element
     */
    createMessageElement(msg) {
        const isPrivate = msg.is_private;
        const isSystem = msg.from_user === 'SERVER';

        const messageDiv = $('<div>')
            .addClass('message')
            .addClass(isPrivate ? 'message-private' : '')
            .addClass(isSystem ? 'message-system' : '');

        let timeText = msg._localTime || '';
        if (msg.received_at) {
            // Parse as UTC (DB stores CURRENT_TIMESTAMP without tz marker);
            // getHours/Minutes/Seconds then return the user's local time.
            const raw = msg.received_at.replace(' ', 'T');
            const d = new Date(raw.endsWith('Z') || raw.includes('+') ? raw : raw + 'Z');
            timeText = [d.getHours(), d.getMinutes(), d.getSeconds()]
                .map(n => String(n).padStart(2, '0'))
                .join(':');
        }

        const body = $('<div>').addClass('message-body');

        if (isSystem) {
            const line = timeText ? `${timeText} ${msg.message_body}` : msg.message_body;
            body.html(typeof parsePipeCodes === 'function'
                ? parsePipeCodes(line)
                : $('<div>').text(line).html());
        } else {
            const parsed = this.extractUserAndMessage(msg.message_body, msg.from_user);
            if (timeText) {
                body.append($('<span>').text(`${timeText} `));
            }

            const userSpan = $('<span>')
                .addClass('message-user')
                .attr('title', msg.from_site ? `@${msg.from_site}` : '');

            if (typeof parsePipeCodes === 'function') {
                userSpan.html(parsePipeCodes(parsed.user));
            } else {
                userSpan.text(parsed.user);
            }

            body.append(userSpan);
            body.append($('<span>').text(' : '));

            if (typeof parsePipeCodes === 'function') {
                body.append($('<span>').html(parsePipeCodes(parsed.message)));
            } else {
                body.append($('<span>').text(parsed.message));
            }
        }

        messageDiv.append(body);

        return messageDiv;
    }

    /**
     * React to server notices that indicate routing/join issues.
     */
    handleServerNotice(body) {
        const lower = body.toLowerCase();
        if (lower.includes('no route to a room from your user')) {
            this.showError('Server says you are not in a room. Please join a room.');
        }
    }

    /**
     * Render users list
     */
    renderUsers(users) {
        const userList = $('#user-list');
        const userCount = $('#user-count');

        const normalizedUsers = this.dedupeUsers(users);
        this.currentUsers = normalizedUsers;
        userList.empty();
        userCount.text(normalizedUsers.length);

        if (normalizedUsers.length === 0) {
            userList.html('<div class="text-muted small p-2">No users online</div>');
            return;
        }

        const isLocalPresent = this.isLocalUserPresent(normalizedUsers);
        this.updateInputStateByPresence(isLocalPresent);

        normalizedUsers.forEach(user => {
            const userName = user.username || '';
            const isActiveDm = this.privateUser && userName === this.privateUser;
            const displayName = this.escapeHtml(userName);
            const item = $('<div>')
                .addClass('list-group-item')
                .addClass(isActiveDm ? 'user-dm-active' : '')
                .html(`
                    <div class="user-name">
                        ${displayName}
                        ${user.is_afk ? '<span class="user-afk">(AFK)</span>' : ''}
                    </div>
                `);

            // Intentionally disabled:
            // item.on('click', () => {
            //     this.startPrivateChat(user.username);
            // });

            userList.append(item);
        });
    }

    /**
     * Dedupe users by username (case-sensitive).
     * Prefer entries that include a real BBS name.
     */
    dedupeUsers(users) {
        const byName = new Map();
        users.forEach(user => {
            const name = (user.username || '').trim();
            if (!name) return;
            const key = name;
            const existing = byName.get(key);
            if (!existing) {
                byName.set(key, user);
                return;
            }
            const existingBbs = (existing.bbs_name || '').trim().toLowerCase();
            const newBbs = (user.bbs_name || '').trim().toLowerCase();
            const existingHasBbs = existingBbs !== '' && existingBbs !== 'unknown';
            const newHasBbs = newBbs !== '' && newBbs !== 'unknown';
            if (newHasBbs && !existingHasBbs) {
                byName.set(key, user);
            }
        });
        return Array.from(byName.values());
    }

    /**
     * Check if current local user appears in the server-provided user list.
     */
    isLocalUserPresent(users) {
        if (!this.username) return false;
        const me = this.username;
        return users.some(u => {
            const name = (u.username || '');
            return name === me;
        });
    }

    /**
     * Disable chat when the server indicates we are no longer in the room.
     */
    updateInputStateByPresence(isPresent) {
        if (this.viewMode !== 'room' || !this.joinedRoom) return;
        if (!isPresent) {
            this.missingPresenceCount += 1;
            if (this.missingPresenceCount >= 2) {
                this.joinedRoom = null;
                $('#room-select').val('');
                $('#message-input').attr('placeholder', 'Type a command (e.g. /identify) or join a room to chat...');
            }
        } else {
            this.missingPresenceCount = 0;
            $('#message-input').attr('placeholder', 'Type a message... (max 140 chars)');
        }
    }

    /**
     * Send a message
     */
    async sendMessage() {
        const input = $('#message-input');
        const message = input.val().trim();

        if (!message) return;
        this.inputHistory.unshift(message);
        if (this.inputHistory.length > 50) this.inputHistory.pop();
        this.historyIndex = -1;
        this.historySavedInput = '';
        if (message.startsWith('/')) {
            this.handleCommand(message);
            input.val('');
            this.updateCharCount();
            return;
        }
        if (this.viewMode === 'room' && !this.joinedRoom) return;
        if (this.viewMode === 'private' && !this.privateUser) return;

        try {
            const response = await $.ajax({
                url: 'api.php?action=send',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    room: this.joinedRoom,
                    message: message,
                    to_user: this.viewMode === 'private' ? this.privateUser : ''
                }),
                dataType: 'json'
            });

            if (response.success) {
                input.val('');
                this.updateCharCount();

                if (this.viewMode === 'private') {
                    this.echoSentMessage(message);
                }
            }
        } catch (error) {
            console.error('Failed to send message:', error);
            this.showError('Failed to send message. Please try again.');
        }
    }

    /**
     * Refresh messages manually
     */
    async refreshMessages() {
        await this.poll(false);
    }

    /**
     * Handle slash commands (do not send as chat messages)
     */
    handleCommand(raw) {
        const trimmed = raw.trim();
        const parts = trimmed.slice(1).split(/\s+/);
        const command = (parts.shift() || '').toLowerCase();
        const args = parts;

        switch (command) {
            case 'join':
                if (args.length === 0) {
                    this.showError('Usage: /join &lt;room&gt;');
                    break;
                }
                this.joinRoom(args[0]);
                break;
            case 'motd':
                this.sendCommand(command, args);
                break;
            case 'rooms':
                this.sendCommand(command, args);
                break;
            case 'msg':
                if (args.length < 2) {
                    this.showError('Usage: /msg &lt;username&gt; &lt;message&gt;');
                    break;
                }
                if (!this.joinedRoom) {
                    this.showError('Join a room before sending private messages.');
                    break;
                }
                this.sendPrivateMessage(args[0], args.slice(1).join(' '));
                break;
            case 'topic':
                if (!this.joinedRoom) {
                    this.showError('Join a room before setting the topic.');
                    break;
                }
                if (args.length === 0) {
                    this.showError('Usage: /topic &lt;new topic&gt;');
                    break;
                }
                this.sendCommand(command, args);
                break;
            case 'register':
            case 'identify':
            case 'update':
                this.sendCommand(command, args);
                break;
            case 'help':
                this.sendCommand(command, args);
                break;
            default:
                this.sendCommand(command, args);
                break;
        }
    }

    /**
     * Send a supported command to the MRC server.
     */
    /**
     * Commands that can be sent without being in a room.
     */
    static get NO_ROOM_COMMANDS() {
        return ['join', 'rooms', 'motd', 'register', 'identify', 'update', 'help'];
    }

    async sendCommand(command, args) {
        if (!this.joinedRoom && !MrcClient.NO_ROOM_COMMANDS.includes(command)) {
            this.showError('Join a room before using commands.');
            return;
        }

        try {
            const response = await $.ajax({
                url: 'api.php?action=command',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    command,
                    room: this.joinedRoom || '',
                    args
                }),
                dataType: 'json'
            });

            if (!response.success) {
                this.showError(`Command '/${command}' failed.`);
                return;
            }

            if (command === 'rooms') {
                this.showError('Refreshing room list...');
                setTimeout(() => this.poll(true), 1000);
            }
        } catch (error) {
            console.error('Command failed:', error);
            this.showError(`Command '/${command}' failed.`);
        }
    }

    /**
     * Update character count
     */
    updateCharCount() {
        const count = $('#message-input').val().length;
        $('#char-count').text(count);
    }

    /**
     * Tab-complete the partial nick at the cursor.
     * Cycles through matches on successive Tab presses.
     * Appends ": " after the nick when completing at the start of the line,
     * or a space when completing mid-message.
     */
    handleTabComplete() {
        const input = $('#message-input');
        const val = input.val();
        const pos = input[0].selectionStart;

        if (this.tabState) {
            // Continuing a cycle — verify the value still contains the previously
            // inserted completion starting at wordStart (cursor-position-independent).
            const prev = this.tabState.matches[this.tabState.index];
            const suffix = ' ';
            const prevInserted = prev + suffix;
            const fromWordStart = val.slice(this.tabState.wordStart);

            if (fromWordStart.startsWith(prevInserted)) {
                // Swap out the previous match for the next one
                this.tabState.index = (this.tabState.index + 1) % this.tabState.matches.length;
                const next = this.tabState.matches[this.tabState.index];
                const nextInserted = next + suffix;
                const after = fromWordStart.slice(prevInserted.length);
                const completed = val.slice(0, this.tabState.wordStart) + nextInserted + after;
                const newPos = this.tabState.wordStart + nextInserted.length;
                input.val(completed);
                input[0].setSelectionRange(newPos, newPos);
                this.updateCharCount();
                return;
            }

            // Value was modified — treat as a fresh completion
            this.tabState = null;
        }

        // Fresh completion
        const before = val.slice(0, pos);

        // Slash command completion
        if (val.startsWith('/')) {
            const spaceIdx = val.indexOf(' ');

            // Cursor is on the command word itself (e.g. "/mo|" or "/msg|")
            if (spaceIdx === -1 || pos <= spaceIdx) {
                const partial = before.slice(1); // strip leading /
                const prefix = partial.toLowerCase();
                const commands = ['help', 'identify', 'join', 'motd', 'msg', 'register', 'rooms', 'topic', 'update'];
                const matches = commands.filter(c => c.startsWith(prefix));
                if (matches.length === 0) return;

                // wordStart is 1 (the character after /)
                this.tabState = { prefix, matches, index: 0, wordStart: 1 };
                const match = matches[0];
                const suffix = ' ';
                const completed = '/' + match + suffix + val.slice(pos);
                const newPos = 1 + match.length + suffix.length;
                input.val(completed);
                input[0].setSelectionRange(newPos, newPos);
                this.updateCharCount();
                return;
            }

            // Cursor is past the command — complete username for /msg
            const command = val.slice(1, spaceIdx).toLowerCase();
            if (command === 'msg') {
                const afterCmd = val.slice(spaceIdx + 1, pos);
                // Only complete on the username token (before any second space)
                if (afterCmd.indexOf(' ') === -1) {
                    const wordStart = spaceIdx + 1;
                    const prefix = afterCmd.toLowerCase();
                    const matches = this.currentUsers
                        .map(u => u.username)
                        .filter(u => u && u.toLowerCase().startsWith(prefix));
                    if (matches.length === 0) return;

                    this.tabState = { prefix, matches, index: 0, wordStart };
                    const match = matches[0];
                    const suffix = ' ';
                    const completed = val.slice(0, wordStart) + match + suffix + val.slice(pos);
                    const newPos = wordStart + match.length + suffix.length;
                    input.val(completed);
                    input[0].setSelectionRange(newPos, newPos);
                    this.updateCharCount();
                }
            }
            return;
        }

        // Username completion for regular messages
        const wordStart = before.lastIndexOf(' ') + 1;
        const partial = before.slice(wordStart);

        if (!partial) return;

        const prefix = partial.toLowerCase();
        const matches = this.currentUsers
            .map(u => u.username)
            .filter(u => u && u.toLowerCase().startsWith(prefix));

        if (matches.length === 0) return;

        this.tabState = { prefix, matches, index: 0, wordStart };

        const match = matches[0];
        const suffix = ' ';
        const completed = val.slice(0, wordStart) + match + suffix + val.slice(pos);
        const newPos = wordStart + match.length + suffix.length;

        input.val(completed);
        input[0].setSelectionRange(newPos, newPos);
        this.updateCharCount();
    }

    /**
     * Scroll chat to bottom
     */
    scrollToBottom() {
        const chatArea = $('#chat-messages')[0];
        chatArea.scrollTop = chatArea.scrollHeight;
    }

    /**
     * Enable auto-scroll only when user is near the bottom.
     */
    updateAutoScroll() {
        const chatArea = $('#chat-messages')[0];
        const threshold = 40;
        const distanceFromBottom = chatArea.scrollHeight - chatArea.scrollTop - chatArea.clientHeight;
        this.autoScroll = distanceFromBottom <= threshold;
    }

    /**
     * Show error message
     */
    showError(message) {
        const chatArea = $('#chat-messages');
        const errorEl = $('<div>')
            .addClass('alert alert-danger alert-dismissible fade show m-2')
            .html(`
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `);

        chatArea.prepend(errorEl);

        // Auto-dismiss after 5 seconds
        setTimeout(() => errorEl.remove(), 5000);
    }

    /**
     * Locally echo a message we just sent (private chat only).
     * The MRC server does not echo private messages back to the sender.
     */
    echoSentMessage(message) {
        const now = new Date();
        const time = [now.getHours(), now.getMinutes(), now.getSeconds()]
            .map(n => String(n).padStart(2, '0')).join(':');

        const msg = {
            is_private: true,
            from_user: this.username || 'Me',
            from_site: this.localBbs || '',
            message_body: `${this.username || 'Me'} ${message}`,
            received_at: null,
            _localTime: time,
        };

        const el = this.createMessageElement(msg);
        el.addClass('message-sent');
        $('#chat-messages').append(el);
        if (this.autoScroll) this.scrollToBottom();
    }

    /**
     * Send a private message and render it inline in the main message area.
     */
    async sendPrivateMessage(username, message) {
        // Render the private message inline in the main stream.
        // is appended after a stable cursor — not after a cursor reset.
        try {
            const response = await $.ajax({
                url: 'api.php?action=send',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    room: this.joinedRoom,
                    message: message,
                    to_user: username
                }),
                dataType: 'json'
            });

            if (response.success) {
                $('#message-input').val('');
                this.updateCharCount();
                this.echoSentMessage(message);
            }
        } catch (error) {
            console.error('Failed to send private message:', error);
            this.showError('Failed to send private message. Please try again.');
        }
    }

    /**
     * Start a private chat with another user
     */
    async startPrivateChat(username) {
        if (!username) return;
        this.privateUser = username;
        this.viewMode = 'private';
        this.lastPrivateMessageId = 0;
        this.seenPrivateMessageIds.clear();

        $('#private-chat-user').text(username);
        $('#private-chat-indicator').removeClass('d-none');
        $('#message-input').attr('placeholder', `Private message to ${username}...`);

        $('#current-room-topic').text('Private chat');

        await this.poll(false);
        $('#message-input').focus();
        this.clearPrivateUnread(username);
    }

    /**
     * Exit private chat and return to room view
     */
    exitPrivateChat(suppressPoll = false) {
        if (this.viewMode !== 'private') return;
        this.viewMode = 'room';
        this.privateUser = null;
        this.lastPrivateMessageId = 0;
        $('#private-chat-indicator').addClass('d-none');
        $('#private-chat-user').text('');
        $('#message-input').attr('placeholder', 'Type a message... (max 140 chars)');
        if (!suppressPoll) {
            this.poll(false);
        }
        if (this.joinedRoom) {
            $('#current-room-topic').text('');
        }
    }

    /**
     * Clear unread count for a specific user.
     */
    clearPrivateUnread(username) {
        if (!username) return;
        const key = username;
        if (this.privateUnread[key]) {
            delete this.privateUnread[key];
            this.updatePrivateUnreadBadge();
        }
    }

    /**
     * Update the global private unread badge in the sidebar header.
     */
    updatePrivateUnreadBadge() {
        const badge = $('#private-unread-count');
        // Intentionally disabled: DMs are rendered inline in the main stream.
        badge.text('0').addClass('d-none');
    }

    /**
     * Fetch private SERVER notices and display them inline.
     */
    async fetchSystemNotices() {
        if (!this.username) return;

        try {
            const response = await $.ajax({
                url: 'api.php',
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'private',
                    with: 'SERVER',
                    after: this.lastSystemMessageId
                }
            });

            if (!response || !response.success || !response.messages) {
                return;
            }

            const messages = response.messages;
            if (messages.length === 0) {
                return;
            }

            const chatArea = $('#chat-messages');
            messages.forEach(msg => {
                const msgId = msg.id;
                if (this.seenSystemMessageIds.has(msgId)) {
                    return;
                }
                this.seenSystemMessageIds.add(msgId);
                chatArea.append(this.createMessageElement(msg));
                this.lastSystemMessageId = Math.max(this.lastSystemMessageId, msgId);
            });

            if (this.autoScroll) {
                this.scrollToBottom();
            }

            if (this.privateUnread.SERVER) {
                delete this.privateUnread.SERVER;
                this.updatePrivateUnreadBadge();
            }
        } catch (error) {
            console.error('Failed to fetch system notices:', error);
        }
    }

    /**
     * Lightweight presence ping to avoid false stale pruning.
     */
    async pingPresence() {
        if (!this.joinedRoom) return;
        const now = Date.now();
        if (now - this.lastPresencePingAt < 30000) return;
        this.lastPresencePingAt = now;

        try {
            await fetch(`api.php?action=heartbeat&room=${encodeURIComponent(this.joinedRoom)}`, {
                method: 'GET',
                keepalive: true
            });
        } catch (error) {
            console.error('Presence ping failed:', error);
        }
    }

    /**
     * Subscribe to BinkStream real-time events.
     * Falls back to a simple 3-second interval poll when SharedWorker is not
     * available (old browsers, HTTP contexts, etc.).
     */
    subscribeBinkStream() {
        if (typeof SharedWorker === 'undefined' || !window.BinkStream) {
            this.poll(false);
            this.pollTimer = setInterval(() => this.poll(false), 3000);
            return;
        }

        this.binkStreamActive = true;

        window.BinkStream.on('mrc_message', (data) => this.handleMrcMessageEvent(data));
        window.BinkStream.on('mrc_presence', (data) => this.handleMrcPresenceEvent(data));

        // Slow periodic safety-net poll: refreshes rooms list and catches
        // anything that may have been missed while this tab was not active.
        this.pollTimer = setInterval(() => {
            if (this.joinedRoom) {
                this.poll(true);
            }
        }, 30000);
    }

    /**
     * Handle a BinkStream mrc_message event.
     * Routes the message to the appropriate view: room, private chat, or system notices.
     */
    handleMrcMessageEvent(data) {
        if (!data || typeof data.id === 'undefined') return;

        const isPrivate = !!data.is_private;
        const fromUser = data.from_user || '';
        const toRoom = (data.to_room || '').toLowerCase();
        const toUser = (data.to_user || '').toLowerCase();
        const myUsername = (this.username || '').toLowerCase();

        if (isPrivate) {
            // Server notices: display inline in the current view
            if (fromUser === 'SERVER') {
                if (!this.seenSystemMessageIds.has(data.id)) {
                    this.seenSystemMessageIds.add(data.id);
                    this.lastSystemMessageId = Math.max(this.lastSystemMessageId, data.id);
                    const chatArea = $('#chat-messages');
                    chatArea.append(this.createMessageElement(data));
                    if (this.autoScroll) this.scrollToBottom();
                }
                return;
            }

            const isFromMe = fromUser.toLowerCase() === myUsername;
            const isForMe = toUser === myUsername;
            if (!isForMe && !isFromMe) return;

            if (!this.seenPrivateMessageIds.has(data.id)) {
                this.seenPrivateMessageIds.add(data.id);
                this.lastPrivateMessageId = Math.max(this.lastPrivateMessageId, data.id);
                this.lastPrivateGlobalId = Math.max(this.lastPrivateGlobalId, data.id);
                const chatArea = $('#chat-messages');
                chatArea.append(this.createMessageElement(data));
                if (this.autoScroll) this.scrollToBottom();
            }
            return;
        }

        // Room message — display only if we are joined to this room
        if (toRoom && this.joinedRoom && toRoom === this.joinedRoom.toLowerCase() &&
            this.viewMode !== 'private') {
            if (!this.seenMessageIds.has(data.id)) {
                this.lastMessageId = Math.max(this.lastMessageId, data.id);
                this.renderMessages([data]);

                if (fromUser === 'SERVER') {
                    this.handleServerNotice(data.message_body || '');
                }
            }
        }
    }

    /**
     * Handle a BinkStream mrc_presence event.
     * Updates the user list for the given room when it matches the current view.
     */
    handleMrcPresenceEvent(data) {
        if (!data || !data.room) return;
        if (!this.joinedRoom) return;
        if (data.room.toLowerCase() !== this.joinedRoom.toLowerCase()) return;
        if (data.users) {
            this.renderUsers(data.users);
        }
    }

    /**
     * Unified poll for messages, users, rooms, and private unread.
     */
    async poll(includeRooms = false, unreadInit = false) {
        const now = Date.now();
        const shouldIncludeRooms = includeRooms || (now - this.lastRoomsPollAt >= 30000);

        try {
            const response = await $.ajax({
                url: 'api.php',
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'poll',
                    view_mode: this.viewMode,
                    view_room: this.joinedRoom || '',
                    join_room: this.joinedRoom || '',
                    with_user: this.privateUser || '',
                    after: this.lastMessageId,
                    after_private: this.lastPrivateMessageId,
                    after_unread: this.lastPrivateGlobalId,
                    include_rooms: shouldIncludeRooms ? '1' : '0',
                    unread_init: (!this.unreadInitDone || unreadInit) ? '1' : '0'
                }
            });

            if (!response.success) {
                return;
            }

            if (response.messages) {
                if (this.viewMode === 'private') {
                    this.renderMessages(response.messages);
                    if (response.messages.length > 0) {
                        const maxId = Math.max(...response.messages.map(m => m.id));
                        this.lastPrivateMessageId = Math.max(this.lastPrivateMessageId, maxId);
                        this.syncPrivateUnreadFromMessages(response.messages);
                    }
                } else if (this.joinedRoom) {
                    this.renderMessages(response.messages);
                    if (response.messages.length > 0) {
                        const maxId = Math.max(...response.messages.map(m => m.id));
                        this.lastMessageId = Math.max(this.lastMessageId, maxId);
                    }
                }
            }

            if (response.users) {
                this.renderUsers(response.users);
            }

            if (response.rooms) {
                this.renderRooms(response.rooms);
                this.lastRoomsPollAt = now;
            }

            if (response.private_unread) {
                const counts = response.private_unread.counts || {};
                Object.keys(counts).forEach(sender => {
                    if (this.privateUser && sender === this.privateUser && this.viewMode === 'private') {
                        return;
                    }
                    this.privateUnread[sender] = (this.privateUnread[sender] || 0) + counts[sender];
                });
                if (typeof response.private_unread.latest_id === 'number') {
                    this.lastPrivateGlobalId = Math.max(this.lastPrivateGlobalId, response.private_unread.latest_id);
                }
                this.updatePrivateUnreadBadge();
                this.unreadInitDone = true;

                if (counts.SERVER) {
                    this.fetchSystemNotices();
                }
            }

        } catch (error) {
            console.error('Poll failed:', error);
        }
    }

    /**
     * Sync unread state from messages loaded in a private chat.
     */
    syncPrivateUnreadFromMessages(messages) {
        if (!this.privateUser || this.viewMode !== 'private') return;
        const key = this.privateUser;
        if (this.privateUnread[key]) {
            delete this.privateUnread[key];
            this.updatePrivateUnreadBadge();
        }

        let latestIncomingId = this.lastPrivateGlobalId;
        messages.forEach(msg => {
            if (msg.to_user && this.username && msg.to_user === this.username) {
                latestIncomingId = Math.max(latestIncomingId, msg.id);
            }
        });
        this.lastPrivateGlobalId = latestIncomingId;
    }

    /**
     * Strip embedded username prefix from message body.
     *
     * MRC convention embeds the sender name in F7 so other clients can display
     * it (e.g. "admin: hello" or "|03<|02admin|03>: hello").  Since we already
     * show from_user in the message header we strip the prefix here to avoid
     * showing the name twice.
     *
     * Handles two formats:
     *   - Plain:       "username: message"
     *   - Pipe-coded:  "|XX<|XXusername|XX>:? message"
     */
    /**
     * Strip the W1 (username) prefix from a message body per MRC spec.
     *
     * MRC Field 7 format: "W1 W2+" where W1 = sender handle, W2+ = chat text.
     * Other clients (Mystic, ZOC, etc.) display this as "W1: W2+".
     *
     * Since we already show from_user in the message header we strip W1 so
     * the body shows only the chat text.
     *
     * Two formats are handled:
     *   - Plain:       "username message"  (what we send)
     *   - Pipe-coded:  "|XX<|XXusername|XX>:? message" (other BBSes)
     */
    stripUsernamePrefix(body, fromUser) {
        if (!body || !fromUser) return body;

        const escaped = fromUser.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

        // Pipe-coded bracket format from other BBSes: "|XX<|XXusername|XX>:? "
        const bracketRe = new RegExp(
            '^(\\|[0-9A-Fa-f]{2})*<(\\|[0-9A-Fa-f]{2})*' + escaped + '(\\|[0-9A-Fa-f]{2})*>:?\\s+',
            'i'
        );
        const bm = body.match(bracketRe);
        if (bm) return body.substring(bm[0].length);

        // Plain format: "username " (space-delimited, what we send per spec)
        const plainRe = new RegExp('^' + escaped + '\\s+', 'i');
        if (plainRe.test(body)) return body.replace(plainRe, '');

        return body;
    }

    /**
     * Extract a pipe-coded username (if present) and the message body.
     */
    extractUserAndMessage(body, fromUser) {
        if (!body) {
            return { user: fromUser || '', message: '' };
        }

        const escaped = (fromUser || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

        // Pipe-coded bracket format: "|XX<|XXusername|XX> message"
        const bracketRe = new RegExp(
            '^(\\|[0-9A-Fa-f]{2})*<(\\|[0-9A-Fa-f]{2})*' + escaped + '(\\|[0-9A-Fa-f]{2})*>\\s+',
            'i'
        );
        const bm = body.match(bracketRe);
        if (bm) {
            const user = bm[0].trim().replace(/\s+$/, '');
            const message = body.substring(bm[0].length);
            return { user, message };
        }

        // Plain W1 format: "username message"
        const plainRe = new RegExp('^' + escaped + '\\s+', 'i');
        if (plainRe.test(body)) {
            const message = body.replace(plainRe, '');
            return { user: fromUser || '', message };
        }

        // Fallback: use fromUser and full body
        return { user: fromUser || '', message: body };
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize on page load
$(document).ready(() => {
    window.mrcClient = new MrcClient();
});
