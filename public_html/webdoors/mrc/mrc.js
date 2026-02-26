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
        this.pollInterval = 2000; // 2 seconds
        this.pollTimer = null;
        this.lastRoomsPollAt = 0;
        this.lastMessageId = 0;
        this.lastPrivateMessageId = 0;
        this.lastPrivateGlobalId = 0;
        this.privateUnread = {};
        this.unreadInitDone = false;
        this.seenMessageIds = new Set();
        this.seenPrivateMessageIds = new Set();
        this.motdPendingUntil = 0;
        this.motdLines = [];
        this.motdSeenIds = new Set();
        this.motdIsOpen = false;
        this.lastPresencePingAt = 0;
        this.autoScroll = true;
        this.username = window.mrcCurrentUser || null;
        this.localBbs = window.mrcCurrentBbs || null;
        this.missingPresenceCount = 0;

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
                $('#message-input').prop('disabled', false);
                $('#send-btn').prop('disabled', false);
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
            if (this.motdPendingUntil > 0 && msg.from_user === 'SERVER') {
                this.captureMotdLine(msg);
                return;
            }
            this.captureMotdLine(msg);
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

        let timeText = '';
        if (msg.received_at) {
            // Parse as UTC (DB stores CURRENT_TIMESTAMP without tz marker);
            // getHours/Minutes/Seconds then return the user's local time.
            const raw = msg.received_at.replace(' ', 'T');
            const d = new Date(raw.endsWith('Z') || raw.includes('+') ? raw : raw + 'Z');
            const time = [d.getHours(), d.getMinutes(), d.getSeconds()]
                .map(n => String(n).padStart(2, '0'))
                .join(':');
            timeText = time;
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
                $('#message-input').prop('disabled', true);
                $('#send-btn').prop('disabled', true);
                $('#join-room-active-btn').removeClass('d-none');
            }
        } else {
            this.missingPresenceCount = 0;
            $('#message-input').prop('disabled', false);
            $('#send-btn').prop('disabled', false);
        }
    }

    /**
     * Send a message
     */
    async sendMessage() {
        const input = $('#message-input');
        const message = input.val().trim();

        if (!message) return;
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

                // Force immediate message refresh
                setTimeout(() => this.poll(false), 500);
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
     * Start unified polling
     */
    startPolling() {
        this.stopPolling();

        this.pollTimer = setInterval(() => {
            this.poll(false);
        }, this.pollInterval);
    }

    /**
     * Stop unified polling
     */
    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
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
            case 'help':
            case 'list':
            case 'whoon':
            case 'users':
            case 'time':
            case 'version':
                this.showError(`Command '/${command}' is not implemented yet.`);
                break;
            default:
                this.showError(`Unknown command: /${command}`);
                break;
        }
    }

    /**
     * Send a supported command to the MRC server.
     */
    async sendCommand(command, args) {
        if (!this.joinedRoom && command !== 'rooms') {
            this.showError('Join a room before using commands.');
            return;
        }

        try {
            if (command === 'motd') {
                this.motdPendingUntil = Date.now() + 3000;
                this.motdLines = [];
                this.motdSeenIds.clear();
                this.motdIsOpen = false;
            }
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
                } else {
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
            }

            if (this.motdPendingUntil > 0 && Date.now() > this.motdPendingUntil) {
                if (this.motdLines.length > 0) {
                    this.showMotdModal(this.motdLines.join('\n'));
                } else {
                    this.showMotdModal('No MOTD received.');
                }
                this.motdPendingUntil = 0;
                this.motdLines = [];
                this.motdSeenIds.clear();
            }
        } catch (error) {
            console.error('Poll failed:', error);
        }
    }

    /**
     * Capture MOTD lines while a /motd request is pending.
     */
    captureMotdLine(msg) {
        if (this.motdPendingUntil === 0) return;
        if (Date.now() > this.motdPendingUntil) return;
        if (msg.from_user !== 'SERVER') return;
        if (this.joinedRoom && msg.to_room !== this.joinedRoom) return;
        if (this.motdSeenIds.has(msg.id)) return;

        const body = msg.message_body || '';
        const clean = body.replace(/\|[0-9A-Fa-f]{2}/g, '').trim();
        if (clean === '') return;
        if (/\(Joining\)|\(Parting\)|\(Timeout\)/i.test(clean)) return;
        if (/No route to a room/i.test(clean)) return;

        this.motdSeenIds.add(msg.id);
        this.motdLines.push(clean);
    }

    showMotdModal(content) {
        const modalEl = document.getElementById('motdModal');
        if (!modalEl) return;
        const bodyEl = document.getElementById('motdModalBody');
        if (bodyEl) {
            bodyEl.textContent = content;
        }
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
        this.motdIsOpen = true;
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
        $('#message-input').prop('disabled', needsJoin);
        $('#send-btn').prop('disabled', needsJoin);

        $('#room-list .list-group-item').removeClass('active');
        $(`#room-list .list-group-item[data-room="${roomName}"]`).addClass('active');

        $('#chat-messages').html(
            '<div class="text-center text-muted py-3">Waiting for new messages...</div>'
        );

        await this.poll(false);
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
