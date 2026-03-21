/**
 * MRC Chat Client
 * Handles real-time chat interface with HTTP polling
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
        this.viewedRoom = null;
        this.viewMode = 'room'; // room | private
        this.privateUser = null;
        this.pollTimer = null;
        this.longPollActive = false;
        this.longPollXhr = null;
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
        this.pollMode = localStorage.getItem('mrc_poll_mode') || 'simple'; // 'longpoll' | 'simple'
        this.missingPresenceCount = 0;
        this.inputHistory = [];
        this.historyIndex = -1;
        this.historySavedInput = '';
        this.currentUsers = [];
        this.tabState = null; // { prefix, matches, index } while cycling

        this.init();
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

        $('#join-room-active-btn').on('click', () => {
            if (this.viewedRoom) {
                this.joinRoom(this.viewedRoom);
            }
        });

        $('#private-chat-exit').on('click', (e) => {
            e.preventDefault();
            this.exitPrivateChat(true);
        });

        $('#join-room-btn').on('click', () => {
            this.joinRoomFromInput();
        });

        $('#join-room-input').on('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.joinRoomFromInput();
            }
        });

        // Disable auto-scroll when user scrolls up
        $('#chat-messages').on('scroll', () => {
            this.updateAutoScroll();
        });

        // Keep presence alive on user activity (covers background tab throttling)
        const activityHandler = () => this.pingPresence();
        $(document).on('keydown mousedown mousemove touchstart', activityHandler);

        // Check connection status
        this.checkStatus();

        // Wire poll-mode toggle button
        this.initPollModeButton();

        // Initial poll (includes rooms + unread init)
        this.poll(true, true);

        // Start unified polling
        this.startPolling();
    }

    /**
     * Check MRC daemon status
     */
    async checkStatus() {
        try {
            const response = await $.ajax({
                url: 'api.php?action=status',
                method: 'GET',
                dataType: 'json'
            });

            if (response.success) {
                this.updateConnectionStatus(response.connected, response.enabled);
            }
        } catch (error) {
            console.error('Status check failed:', error);
            this.updateConnectionStatus(false, false);
        }
    }

    /**
     * Update connection status indicator
     */
    updateConnectionStatus(connected, enabled) {
        const statusEl = $('#connection-status');

        if (!enabled) {
            statusEl.html('<i class="bi bi-circle-fill text-danger"></i> MRC Disabled');
        } else if (connected) {
            statusEl.html('<i class="bi bi-circle-fill text-success"></i> Connected');
        } else {
            statusEl.html('<i class="bi bi-circle-fill text-warning"></i> Connecting...');
        }
    }

    /**
     * Wire the poll-mode toggle button.
     */
    initPollModeButton() {
        this.updatePollModeButton();
        $('#poll-mode-btn').on('click', () => this.togglePollMode());
    }

    /**
     * Render rooms list
     */
    renderRooms(rooms) {
        const roomList = $('#room-list');
        roomList.empty();

        if (!rooms || rooms.length === 0) {
            roomList.html('<div class="text-muted small p-2">No rooms available</div>');
            return;
        }

        rooms.forEach(room => {
            const isJoined = this.joinedRoom === room.room_name;
            const isViewed = this.viewedRoom === room.room_name && this.viewMode === 'room';
            const item = $('<div>')
                .addClass('list-group-item')
                .addClass(isViewed ? 'active' : '')
                .addClass(isJoined ? 'room-joined' : '')
                .addClass(isViewed ? 'room-viewed' : '')
                .attr('data-room', room.room_name)
                .html(`
                    <div class="room-name">#${this.escapeHtml(room.room_name)}</div>
                    ${room.topic ? `<div class="room-topic">${this.escapeHtml(room.topic)}</div>` : ''}
                    <div class="room-users">
                        <i class="bi bi-people"></i> ${room.user_count || 0} users
                    </div>
                `)
                .on('click', () => {
                    this.viewRoom(room.room_name);
                });

            roomList.append(item);
        });
    }

    /**
     * Join room from the text input
     */
    joinRoomFromInput() {
        const input = $('#join-room-input');
        const roomName = input.val().trim();
        if (!roomName) return;
        input.val('');
        this.joinRoom(roomName);
    }

    /**
     * Join a room
     */
    async joinRoom(roomName) {
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
                this.viewedRoom = roomName;
                this.lastMessageId = typeof response.last_message_id === 'number'
                    ? response.last_message_id
                    : 0;
                this.seenMessageIds.clear();
                this.missingPresenceCount = 0;
                this.exitPrivateChat();

                // Update UI
                $('#current-room-name span').text(roomName);
                $('#current-room-topic').text('');
                $('#message-input').attr('placeholder', 'Type a message... (max 140 chars)');
                $('#join-room-active-btn').addClass('d-none');

                // Update active room in list
                $('#room-list .list-group-item').removeClass('active');
                $(`#room-list .list-group-item[data-room="${roomName}"]`).addClass('active');

                // Clear messages
                $('#chat-messages').html(
                    '<div class="text-center text-muted py-3">Waiting for new messages...</div>'
                );

                // Load initial data (includes rooms + users + messages)
                await this.poll(true);

                // Restart long poll with the new room's cursor.
                this.restartLongPoll();

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
    renderMessages(messages, append = false) {
        const chatArea = $('#chat-messages');

        if (!append) {
            chatArea.empty();
            if (this.viewMode === 'private') {
                this.seenPrivateMessageIds.clear();
            } else {
                this.seenMessageIds.clear();
            }
        }

        if (messages.length === 0 && !append) {
            chatArea.html(
                '<div class="text-center text-muted py-5">No messages yet. Start the conversation!</div>'
            );
            return;
        }

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
            const isSelf = this.username && userName === this.username;
            const isActiveDm = this.privateUser && userName === this.privateUser;
            const unread = this.privateUnread[userName] || 0;
            const displayName = this.escapeHtml(userName);
            const item = $('<div>')
                .addClass('list-group-item')
                .addClass(isActiveDm ? 'user-dm-active' : '')
                .html(`
                    <div class="user-name">
                        ${displayName}
                        ${user.is_afk ? '<span class="user-afk">(AFK)</span>' : ''}
                        ${unread > 0 ? `<span class="badge bg-warning text-dark ms-2 user-dm-badge">${unread}</span>` : ''}
                    </div>
                `);

            if (!isSelf) {
                item.on('click', () => {
                    this.startPrivateChat(user.username);
                });
            }

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
                $('#message-input').attr('placeholder', 'Type a command (e.g. /identify) or join a room to chat...');
                $('#join-room-active-btn').removeClass('d-none');
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
                } else {
                    // Force immediate message refresh for room messages
                    setTimeout(() => this.poll(false), 500);
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
        if (this.viewMode === 'private') {
            this.lastPrivateMessageId = 0;
        } else {
            this.lastMessageId = 0;
        }
        await this.poll(false);
    }

    /**
     * Start unified polling. Uses long-poll or simple interval polling
     * depending on this.pollMode.
     */
    startPolling() {
        this.stopPolling();
        if (this.pollMode === 'simple') {
            // Simple interval polling — safer on platforms where long-running
            // HTTP connections are terminated (e.g. PHP built-in server).
            this.poll(false);
            this.pollTimer = setInterval(() => this.poll(false), 3000);
        } else {
            this.longPollActive = true;
            this.runLongPoll();
        }
    }

    /**
     * Toggle between long-poll and simple interval polling modes,
     * persist the choice to localStorage, and restart polling.
     */
    togglePollMode() {
        this.pollMode = this.pollMode === 'longpoll' ? 'simple' : 'longpoll';
        localStorage.setItem('mrc_poll_mode', this.pollMode);
        this.startPolling();
        this.updatePollModeButton();
    }

    /**
     * Update the poll-mode toggle button label to reflect the current mode.
     */
    updatePollModeButton() {
        const btn = $('#poll-mode-btn');
        if (this.pollMode === 'simple') {
            // Currently in simple mode — clicking switches to long poll
            btn.html('<i class="bi bi-lightning-charge"></i> Use long poll').attr('title', 'Switch to long polling (lower latency, keeps connection open)');
        } else {
            // Currently in long poll mode — clicking switches to simple
            btn.html('<i class="bi bi-arrow-repeat"></i> Use simple poll').attr('title', 'Switch to simple polling (safer for some server configs)');
        }
    }

    /**
     * Stop long-poll loop and cancel any in-flight request.
     */
    stopPolling() {
        this.longPollActive = false;
        if (this.longPollXhr) {
            this.longPollXhr.abort();
            this.longPollXhr = null;
        }
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    /**
     * Abort the in-flight long-poll request so the loop immediately restarts
     * with the current view state. Call this after any room/mode change.
     * In simple poll mode this is a no-op; the next interval tick picks up
     * the new state automatically.
     */
    restartLongPoll() {
        if (this.pollMode === 'simple') return;
        if (this.longPollXhr) {
            this.longPollXhr.abort(); // doLongPoll catches the abort and loops again
        }
    }

    /**
     * Self-restarting long-poll loop. Runs until stopPolling() is called.
     * Errors (network failures, etc.) trigger a 2-second back-off before retry.
     */
    async runLongPoll() {
        while (this.longPollActive) {
            try {
                await this.doLongPoll();
            } catch (e) {
                if (!this.longPollActive) break;
                // Back off briefly on unexpected errors before retrying.
                await new Promise(resolve => setTimeout(resolve, 2000));
            }
        }
    }

    /**
     * Perform a single long-poll request. Holds open for up to ~20 s server-side;
     * returns as soon as new messages or unread DMs arrive, or on timeout.
     * An intentional abort (from restartLongPoll) returns silently so the loop
     * can restart immediately with fresh state.
     */
    async doLongPoll() {
        const xhr = $.ajax({
            url: 'api.php',
            method: 'GET',
            dataType: 'json',
            timeout: 30000, // 30 s client-side safety net (server responds in ≤ 20 s)
            data: {
                action:        'longpoll',
                view_mode:     this.viewMode,
                view_room:     this.viewedRoom  || '',
                join_room:     this.joinedRoom  || '',
                with_user:     this.privateUser || '',
                after:         this.lastMessageId,
                after_private: this.lastPrivateMessageId,
                after_unread:  this.lastPrivateGlobalId,
            }
        });

        this.longPollXhr = xhr;

        try {
            const response = await xhr;
            this.longPollXhr = null;
            if (!this.longPollActive) return;
            this.processLongPollResponse(response);
        } catch (e) {
            this.longPollXhr = null;
            // Abort is intentional (restartLongPoll called) — return so the
            // while loop immediately starts the next request.
            if (!this.longPollActive || e.statusText === 'abort') return;
            throw e; // Propagate real errors so runLongPoll can back off.
        }
    }

    /**
     * Process a long-poll response the same way poll() handles its response,
     * but without the rooms/users-only slow-poll logic.
     */
    processLongPollResponse(response) {
        if (!response || !response.success) return;

        if (response.messages) {
            if (this.viewMode === 'private') {
                const append = this.lastPrivateMessageId !== 0;
                this.renderMessages(response.messages, append);
                if (response.messages.length > 0) {
                    const maxId = Math.max(...response.messages.map(m => m.id));
                    this.lastPrivateMessageId = Math.max(this.lastPrivateMessageId, maxId);
                    this.syncPrivateUnreadFromMessages(response.messages);
                }
            } else if (this.viewedRoom) {
                const append = this.lastMessageId !== 0;
                this.renderMessages(response.messages, append);
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
            this.lastRoomsPollAt = Date.now();
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
        return ['rooms', 'motd', 'register', 'identify', 'update', 'help'];
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

            setTimeout(() => this.poll(false), 500);
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
                const commands = ['help', 'identify', 'motd', 'msg', 'register', 'rooms', 'topic', 'update'];
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
     * Send a private message and open the DM view.
     */
    async sendPrivateMessage(username, message) {
        // Open the DM view and load history BEFORE sending so the echo
        // is appended after a stable cursor — not after a cursor reset.
        if (this.viewMode !== 'private' || this.privateUser !== username) {
            await this.startPrivateChat(username);
        }

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
        $('#chat-messages').html(
            '<div class="text-center text-muted py-3">Loading private messages...</div>'
        );

        await this.poll(false);
        // If no prior history existed, lastPrivateMessageId is still 0.
        // Set it to -1 so subsequent polls use append mode (id > -1 is
        // equivalent to id > 0) instead of doing a full replace that would
        // wipe the locally echoed sent message.
        if (this.lastPrivateMessageId === 0) {
            this.lastPrivateMessageId = -1;
        }
        this.restartLongPoll();
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
        this.restartLongPoll();
        if (this.viewedRoom) {
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
        const total = Object.values(this.privateUnread).reduce((sum, val) => sum + val, 0);
        const badge = $('#private-unread-count');
        if (total > 0) {
            badge.text(total).removeClass('d-none');
        } else {
            badge.text('0').addClass('d-none');
        }
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
                    view_room: this.viewedRoom || '',
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
                    const append = this.lastPrivateMessageId !== 0;
                    this.renderMessages(response.messages, append);
                    if (response.messages.length > 0) {
                        const maxId = Math.max(...response.messages.map(m => m.id));
                        this.lastPrivateMessageId = Math.max(this.lastPrivateMessageId, maxId);
                        this.syncPrivateUnreadFromMessages(response.messages);
                    }
                } else if (this.viewedRoom) {
                    const append = this.lastMessageId !== 0;
                    this.renderMessages(response.messages, append);
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
     * View a room without sending NEWROOM (no join spam).
     */
    async viewRoom(roomName) {
        if (!roomName) return;

        if (this.viewMode !== 'room') {
            this.exitPrivateChat(true);
        }

        this.viewedRoom = roomName;
        this.lastMessageId = await this.fetchRoomCursor(roomName);
        this.seenMessageIds.clear();

        $('#current-room-name span').text(roomName);
        $('#current-room-topic').text(
            this.joinedRoom && this.joinedRoom !== roomName
                ? 'Viewing history (not joined)'
                : ''
        );

        const needsJoin = !this.joinedRoom || this.joinedRoom !== roomName;
        $('#join-room-active-btn').toggleClass('d-none', !needsJoin);
        $('#message-input').attr('placeholder', needsJoin
            ? 'Type a command (e.g. /identify) or join a room to chat...'
            : 'Type a message... (max 140 chars)');

        $('#room-list .list-group-item').removeClass('active');
        $(`#room-list .list-group-item[data-room="${roomName}"]`).addClass('active');

        $('#chat-messages').html(
            '<div class="text-center text-muted py-3">Waiting for new messages...</div>'
        );

        await this.poll(false);
        this.restartLongPoll();
    }

    /**
     * Fetch latest message id for a room to start from "now".
     */
    async fetchRoomCursor(roomName) {
        try {
            const response = await $.ajax({
                url: 'api.php',
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'room_cursor',
                    room: roomName
                }
            });
            if (response && response.success && typeof response.last_message_id === 'number') {
                return response.last_message_id;
            }
        } catch (error) {
            console.error('Room cursor fetch failed:', error);
        }
        return 0;
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
