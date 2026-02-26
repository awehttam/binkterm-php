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
        this.currentRoom = null;
        this.pollInterval = 2000; // 2 seconds
        this.messagesPollTimer = null;
        this.usersPollTimer = null;
        this.roomsPollTimer = null;
        this.lastMessageId = 0;
        this.autoScroll = true;
        this.username = null;

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

        $('#join-room-btn').on('click', () => {
            this.joinRoomFromInput();
        });

        $('#join-room-input').on('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.joinRoomFromInput();
            }
        });

        // Check connection status
        this.checkStatus();

        // Load rooms list
        this.loadRooms();

        // Start periodic room list updates
        this.startRoomsPolling();
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
     * Load rooms list
     */
    async loadRooms() {
        try {
            const response = await $.ajax({
                url: 'api.php?action=rooms',
                method: 'GET',
                dataType: 'json'
            });

            if (response.success) {
                this.renderRooms(response.rooms);
            }
        } catch (error) {
            console.error('Failed to load rooms:', error);
            $('#room-list').html(
                '<div class="text-danger small p-2">Failed to load rooms</div>'
            );
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
            const isActive = this.currentRoom === room.room_name;
            const item = $('<div>')
                .addClass('list-group-item')
                .addClass(isActive ? 'active' : '')
                .attr('data-room', room.room_name)
                .html(`
                    <div class="room-name">#${this.escapeHtml(room.room_name)}</div>
                    ${room.topic ? `<div class="room-topic">${this.escapeHtml(room.topic)}</div>` : ''}
                    <div class="room-users">
                        <i class="bi bi-people"></i> ${room.user_count || 0} users
                    </div>
                `)
                .on('click', () => {
                    this.joinRoom(room.room_name);
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
        try {
            const response = await $.ajax({
                url: 'api.php?action=join',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ room: roomName, from_room: this.currentRoom || '' }),
                dataType: 'json'
            });

            if (response.success) {
                this.currentRoom = roomName;
                this.lastMessageId = 0;

                // Update UI
                $('#current-room-name span').text(roomName);
                $('#current-room-topic').text('');
                $('#message-input').prop('disabled', false);
                $('#send-btn').prop('disabled', false);

                // Update active room in list
                $('#room-list .list-group-item').removeClass('active');
                $(`#room-list .list-group-item[data-room="${roomName}"]`).addClass('active');

                // Clear messages
                $('#chat-messages').html(
                    '<div class="text-center text-muted py-3">Loading messages...</div>'
                );

                // Refresh room list so newly created rooms appear immediately
                this.loadRooms();

                // Start polling
                this.startMessagesPolling();
                this.startUsersPolling();

                // Load initial data
                await this.loadMessages();
                await this.loadUsers();

                // Focus message input
                $('#message-input').focus();
            }
        } catch (error) {
            console.error('Failed to join room:', error);
            this.showError('Failed to join room. Please try again.');
        }
    }

    /**
     * Load messages for current room
     */
    async loadMessages(append = false) {
        if (!this.currentRoom) return;

        try {
            const response = await $.ajax({
                url: 'api.php',
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'messages',
                    room: this.currentRoom,
                    limit: 100,
                    after: this.lastMessageId
                }
            });

            if (response.success) {
                this.renderMessages(response.messages, append);

                // Update last message ID
                if (response.messages.length > 0) {
                    this.lastMessageId = response.messages[response.messages.length - 1].id;
                }
            }
        } catch (error) {
            console.error('Failed to load messages:', error);
        }
    }

    /**
     * Render messages in chat area
     */
    renderMessages(messages, append = false) {
        const chatArea = $('#chat-messages');

        if (!append) {
            chatArea.empty();
        }

        if (messages.length === 0 && !append) {
            chatArea.html(
                '<div class="text-center text-muted py-5">No messages yet. Start the conversation!</div>'
            );
            return;
        }

        messages.forEach(msg => {
            const messageEl = this.createMessageElement(msg);
            chatArea.append(messageEl);
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

        const header = $('<div>').addClass('message-header');

        if (!isSystem) {
            header.append(
                $('<span>').addClass('message-user').text(msg.from_user),
                $('<span>').addClass('message-site').text(`@${msg.from_site}`)
            );
        }

        if (msg.received_at) {
            const time = new Date(msg.received_at).toLocaleTimeString();
            header.append($('<span>').addClass('message-time').text(time));
        }

        const rawBody = this.stripUsernamePrefix(msg.message_body, msg.from_user);
        const body = $('<div>')
            .addClass('message-body')
            .html(typeof parsePipeCodes === 'function'
                ? parsePipeCodes(rawBody)
                : $('<div>').text(rawBody).html());

        messageDiv.append(header, body);

        return messageDiv;
    }

    /**
     * Load users in current room
     */
    async loadUsers() {
        if (!this.currentRoom) return;

        try {
            const response = await $.ajax({
                url: 'api.php',
                method: 'GET',
                dataType: 'json',
                data: { action: 'users', room: this.currentRoom }
            });

            if (response.success) {
                this.renderUsers(response.users);
            }
        } catch (error) {
            console.error('Failed to load users:', error);
        }
    }

    /**
     * Render users list
     */
    renderUsers(users) {
        const userList = $('#user-list');
        const userCount = $('#user-count');

        userList.empty();
        userCount.text(users.length);

        if (users.length === 0) {
            userList.html('<div class="text-muted small p-2">No users online</div>');
            return;
        }

        users.forEach(user => {
            const item = $('<div>')
                .addClass('list-group-item')
                .html(`
                    <div class="user-name">
                        ${this.escapeHtml(user.username)}
                        ${user.is_afk ? '<span class="user-afk">(AFK)</span>' : ''}
                    </div>
                    <div class="user-bbs">${this.escapeHtml(user.bbs_name)}</div>
                `);

            userList.append(item);
        });
    }

    /**
     * Send a message
     */
    async sendMessage() {
        const input = $('#message-input');
        const message = input.val().trim();

        if (!message || !this.currentRoom) return;

        try {
            const response = await $.ajax({
                url: 'api.php?action=send',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    room: this.currentRoom,
                    message: message
                }),
                dataType: 'json'
            });

            if (response.success) {
                input.val('');
                this.updateCharCount();

                // Force immediate message refresh
                setTimeout(() => this.loadMessages(true), 500);
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
        this.lastMessageId = 0;
        await this.loadMessages(false);
    }

    /**
     * Start messages polling
     */
    startMessagesPolling() {
        this.stopMessagesPolling();

        this.messagesPollTimer = setInterval(() => {
            this.loadMessages(true);
        }, this.pollInterval);
    }

    /**
     * Stop messages polling
     */
    stopMessagesPolling() {
        if (this.messagesPollTimer) {
            clearInterval(this.messagesPollTimer);
            this.messagesPollTimer = null;
        }
    }

    /**
     * Start users polling
     */
    startUsersPolling() {
        this.stopUsersPolling();

        this.usersPollTimer = setInterval(() => {
            this.loadUsers();
        }, this.pollInterval * 2); // Poll users less frequently
    }

    /**
     * Stop users polling
     */
    stopUsersPolling() {
        if (this.usersPollTimer) {
            clearInterval(this.usersPollTimer);
            this.usersPollTimer = null;
        }
    }

    /**
     * Start rooms polling
     */
    startRoomsPolling() {
        this.stopRoomsPolling();

        this.roomsPollTimer = setInterval(() => {
            this.loadRooms();
        }, 30000); // Poll rooms every 30 seconds
    }

    /**
     * Stop rooms polling
     */
    stopRoomsPolling() {
        if (this.roomsPollTimer) {
            clearInterval(this.roomsPollTimer);
            this.roomsPollTimer = null;
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
