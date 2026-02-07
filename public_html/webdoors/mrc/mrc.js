/**
 * MRC Chat Client
 * Handles real-time chat interface with HTTP polling
 */

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
                url: '/api/webdoor/mrc/status',
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
                url: '/api/webdoor/mrc/rooms',
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
     * Join a room
     */
    async joinRoom(roomName) {
        try {
            const response = await $.ajax({
                url: '/api/webdoor/mrc/join',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ room: roomName }),
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
                url: `/api/webdoor/mrc/messages/${encodeURIComponent(this.currentRoom)}`,
                method: 'GET',
                dataType: 'json',
                data: {
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

        const body = $('<div>')
            .addClass('message-body')
            .text(msg.message_body);

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
                url: `/api/webdoor/mrc/users/${encodeURIComponent(this.currentRoom)}`,
                method: 'GET',
                dataType: 'json'
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
                url: '/api/webdoor/mrc/send',
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
