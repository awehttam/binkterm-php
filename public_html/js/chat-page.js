(() => {
    const CHAT_DB_NAME = 'bink_chat';
    const CHAT_DB_STORE = 'settings';
    const CHAT_STORAGE_KEY = `state:${window.currentUserId || 'unknown'}`;
    const CHAT_MAX_MESSAGES = 500;
    const CHAT_POLL_INTERVAL_MS = 3000;
    const CHAT_EVENT_RECONNECT_MS = 2000;

    const state = {
        rooms: [],
        users: [],
        active: { type: 'room', id: null },
        unreadCounts: {},
        oldestIds: {},
        hasMore: {},
        lastEventId: 0,
        loadingHistory: false
    };

    let dbPromise = null;

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB unavailable'));
                return;
            }
            const request = indexedDB.open(CHAT_DB_NAME, 1);
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(CHAT_DB_STORE)) {
                    db.createObjectStore(CHAT_DB_STORE, { keyPath: 'key' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error || new Error('IndexedDB open failed'));
        });
        return dbPromise;
    }

    async function idbGet(key) {
        try {
            const db = await openDb();
            return await new Promise((resolve, reject) => {
                const tx = db.transaction(CHAT_DB_STORE, 'readonly');
                const store = tx.objectStore(CHAT_DB_STORE);
                const request = store.get(key);
                request.onsuccess = () => resolve(request.result ? request.result.value : null);
                request.onerror = () => reject(request.error);
            });
        } catch (err) {
            return null;
        }
    }

    async function idbSet(key, value) {
        try {
            const db = await openDb();
            await new Promise((resolve, reject) => {
                const tx = db.transaction(CHAT_DB_STORE, 'readwrite');
                const store = tx.objectStore(CHAT_DB_STORE);
                store.put({ key, value });
                tx.oncomplete = () => resolve();
                tx.onerror = () => reject(tx.error);
            });
        } catch (err) {
            // Ignore persistence errors
        }
    }

    async function loadState() {
        const stored = await idbGet(CHAT_STORAGE_KEY);
        if (stored && typeof stored === 'object') {
            state.active = stored.active || state.active;
            state.unreadCounts = stored.unreadCounts || {};
            state.lastEventId = stored.lastEventId || 0;
        }
    }

    function saveState() {
        const payload = {
            active: state.active,
            unreadCounts: state.unreadCounts,
            lastEventId: state.lastEventId
        };
        idbSet(CHAT_STORAGE_KEY, payload);
    }

    function escapeHtml(text) {
        if (window.escapeHtml) {
            return window.escapeHtml(text);
        }
        return String(text).replace(/[&<>"']/g, function(m) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[m];
        });
    }

    function formatTimestamp(ts) {
        if (!ts) return '';
        return window.formatFullDate ? window.formatFullDate(ts) : new Date(ts).toLocaleString();
    }

    function threadKey(thread) {
        return `${thread.type}:${thread.id}`;
    }

    function setActiveThread(thread) {
        state.active = thread;
        state.unreadCounts[threadKey(thread)] = 0;
        saveState();
        renderUnreadBadge();
        renderRooms();
        renderUsers();
        loadMessages();
        renderThreadHeader();
        updateLoadOlderVisibility();
    }

    function renderRooms() {
        const list = document.getElementById('chatRoomsList');
        list.innerHTML = '';
        state.rooms.forEach(room => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'chat-list-item text-muted';
            item.textContent = room.name;
            if (state.active.type === 'room' && state.active.id === room.id) {
                item.classList.add('active');
            }
            const unread = state.unreadCounts[threadKey({ type: 'room', id: room.id })] || 0;
            if (unread > 0) {
                const badge = document.createElement('span');
                badge.className = 'badge bg-primary';
                badge.textContent = unread;
                item.appendChild(badge);
            }
            item.addEventListener('click', () => {
                setActiveThread({ type: 'room', id: room.id });
            });
            list.appendChild(item);
        });
    }

    function renderUsers() {
        const list = document.getElementById('chatUsersList');
        list.innerHTML = '';
        if (state.users.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'chat-empty text-muted';
            empty.textContent = 'No one online';
            list.appendChild(empty);
            return;
        }
        state.users.forEach(user => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'chat-list-item text-muted';
            item.textContent = user.username;
            if (state.active.type === 'dm' && state.active.id === user.user_id) {
                item.classList.add('active');
            }
            const unread = state.unreadCounts[threadKey({ type: 'dm', id: user.user_id })] || 0;
            if (unread > 0) {
                const badge = document.createElement('span');
                badge.className = 'badge bg-primary';
                badge.textContent = unread;
                item.appendChild(badge);
            }
            item.addEventListener('click', () => {
                setActiveThread({ type: 'dm', id: user.user_id });
            });
            list.appendChild(item);
        });
    }

    function renderThreadHeader() {
        const titleEl = document.getElementById('chatThreadTitle');
        const metaEl = document.getElementById('chatThreadMeta');
        if (state.active.type === 'room') {
            const room = state.rooms.find(r => r.id === state.active.id);
            titleEl.textContent = room ? room.name : 'Room';
            metaEl.textContent = room && room.description ? room.description : '';
        } else {
            const user = state.users.find(u => u.user_id === state.active.id);
            titleEl.textContent = user ? user.username : 'Direct';
            metaEl.textContent = user && user.location ? user.location : '';
        }
    }

    function renderThread(messages) {
        const container = document.getElementById('chatThread');
        const loadOlderWrap = document.getElementById('chatLoadOlderWrap');
        container.innerHTML = '';
        if (loadOlderWrap) {
            container.appendChild(loadOlderWrap);
        }
        messages.forEach(msg => appendMessage(msg, true));
        scrollThreadToBottom();
    }

    function prependMessages(messages) {
        const container = document.getElementById('chatThread');
        const loadOlderWrap = document.getElementById('chatLoadOlderWrap');
        if (!container || messages.length === 0) return;
        const insertBefore = loadOlderWrap ? loadOlderWrap.nextSibling : container.firstChild;
        const frag = document.createDocumentFragment();
        messages.forEach(msg => frag.appendChild(buildMessageElement(msg)));
        container.insertBefore(frag, insertBefore);
        trimMessages(container);
    }

    function appendMessage(msg, skipScroll) {
        const container = document.getElementById('chatThread');
        const wrapper = buildMessageElement(msg);
        container.appendChild(wrapper);

        trimMessages(container);

        if (!skipScroll) {
            scrollThreadToBottom();
        }
    }

    function buildMessageElement(msg) {
        const wrapper = document.createElement('div');
        wrapper.className = 'chat-message';
        wrapper.dataset.userId = msg.from_user_id || '';
        wrapper.dataset.roomId = msg.room_id || '';
        wrapper.dataset.messageType = msg.type || '';

        const header = document.createElement('div');
        header.className = 'chat-message-header';
        const authorClass = window.currentUserIsAdmin ? 'chat-message-author admin-action' : 'chat-message-author';
        header.innerHTML = `<span class="${authorClass}" data-user-id="${msg.from_user_id || ''}" title="${window.currentUserIsAdmin ? 'Right-click or click to moderate' : ''}">${escapeHtml(msg.from_username || 'System')}</span>
            <span class="chat-message-time">${formatTimestamp(msg.created_at)}</span>`;

        const body = document.createElement('div');
        body.className = 'chat-message-body';
        body.innerHTML = escapeHtml(msg.body || '');

        wrapper.appendChild(header);
        wrapper.appendChild(body);
        return wrapper;
    }

    function trimMessages(container) {
        const messages = container.querySelectorAll('.chat-message');
        if (messages.length > CHAT_MAX_MESSAGES) {
            for (let i = 0; i < messages.length - CHAT_MAX_MESSAGES; i++) {
                messages[i].remove();
            }
        }
    }

    function scrollThreadToBottom() {
        const container = document.getElementById('chatThread');
        container.scrollTop = container.scrollHeight;
    }

    function loadMessages() {
        if (state.loadingHistory) return;
        state.loadingHistory = true;
        const params = new URLSearchParams();
        if (state.active.type === 'room') {
            params.set('room_id', state.active.id);
        } else {
            params.set('dm_user_id', state.active.id);
        }
        const limit = 200;
        params.set('limit', limit);
        fetch(`/api/chat/messages?${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                const messages = data.messages || [];
                renderThread(messages);
                const key = threadKey(state.active);
                if (messages.length > 0) {
                    state.oldestIds[key] = messages[0].id;
                }
                state.hasMore[key] = (data.has_more !== undefined) ? data.has_more : messages.length === limit;
                updateLoadOlderVisibility();
            })
            .catch(() => {
                // Ignore for now
            })
            .finally(() => {
                state.loadingHistory = false;
            });
    }

    function loadOlderMessages() {
        if (state.loadingHistory) return;
        const key = threadKey(state.active);
        const beforeId = state.oldestIds[key];
        if (!beforeId) return;
        state.loadingHistory = true;
        const params = new URLSearchParams();
        if (state.active.type === 'room') {
            params.set('room_id', state.active.id);
        } else {
            params.set('dm_user_id', state.active.id);
        }
        params.set('before_id', beforeId);
        const limit = 200;
        params.set('limit', limit);
        fetch(`/api/chat/messages?${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                const messages = data.messages || [];
                if (messages.length > 0) {
                    prependMessages(messages);
                    state.oldestIds[key] = messages[0].id;
                }
                state.hasMore[key] = (data.has_more !== undefined) ? data.has_more : messages.length === limit;
                updateLoadOlderVisibility();
            })
            .catch(() => {
                // Ignore for now
            })
            .finally(() => {
                state.loadingHistory = false;
            });
    }

    function updateLoadOlderVisibility() {
        const wrap = document.getElementById('chatLoadOlderWrap');
        if (!wrap) return;
        const key = threadKey(state.active);
        if (state.hasMore[key]) {
            wrap.classList.remove('d-none');
        } else {
            wrap.classList.add('d-none');
        }
    }

    function sendMessage(body) {
        const payload = state.active.type === 'room'
            ? { room_id: state.active.id, body }
            : { to_user_id: state.active.id, body };

        fetch('/api/chat/send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(res => res.json()).then(data => {
            if (data.local_message) {
                appendMessage(data.local_message);
            }
            if (!data.success) {
                alert(data.error || 'Failed to send message');
            }
        }).catch(() => {
            alert('Failed to send message');
        });
    }

    function pollMessages() {
        const params = new URLSearchParams();
        if (state.lastEventId) {
            params.set('since_id', state.lastEventId);
        }
        fetch(`/api/chat/poll?${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                const messages = data.messages || [];
                messages.forEach(payload => {
                    handleIncoming(payload);
                    if (payload.id) {
                        state.lastEventId = payload.id;
                    }
                });
                if (messages.length > 0) {
                    saveState();
                }
            })
            .catch(() => {
                // Ignore polling errors
            });
    }

    function connectEventStream() {
        const params = new URLSearchParams();
        if (state.lastEventId) {
            params.set('since_id', state.lastEventId);
        }
        const source = new EventSource(`/api/events/stream?${params.toString()}`);
        source.addEventListener('message', (event) => {
            const data = JSON.parse(event.data);
            if (data.type === 'chat') {
                handleIncoming(data.payload);
                if (data.id) {
                    state.lastEventId = data.id;
                    saveState();
                }
            }
        });
        source.addEventListener('error', () => {
            source.close();
            setTimeout(connectEventStream, CHAT_EVENT_RECONNECT_MS);
        });
    }

    function handleIncoming(payload) {
        if (!payload || !payload.id) return;
        const thread = payload.type === 'room'
            ? { type: 'room', id: payload.room_id }
            : { type: 'dm', id: payload.from_user_id };
        const key = threadKey(thread);

        const isActive = state.active.type === thread.type && state.active.id === thread.id;
        if (isActive) {
            appendMessage(payload);
        } else {
            state.unreadCounts[key] = (state.unreadCounts[key] || 0) + 1;
            saveState();
            renderUnreadBadge();
            renderRooms();
            renderUsers();
        }
    }

    function renderUnreadBadge() {
        const total = Object.values(state.unreadCounts).reduce((sum, val) => sum + val, 0);
        const badge = document.getElementById('chatUnreadBadge');
        const menuIcon = document.getElementById('chatMenuIcon');
        if (!badge) return;
        if (total > 0) {
            badge.textContent = total;
            badge.style.display = 'inline-block';
            if (menuIcon) {
                menuIcon.classList.add('unread');
            }
        } else {
            badge.style.display = 'none';
            if (menuIcon) {
                menuIcon.classList.remove('unread');
            }
        }
    }

    function refreshRooms() {
        fetch('/api/chat/rooms')
            .then(res => res.json())
            .then(data => {
                state.rooms = data.rooms || [];
                if (!state.active.id) {
                    const lobby = state.rooms.find(room => room.name === 'Lobby') || state.rooms[0];
                    if (lobby) {
                        state.active = { type: 'room', id: lobby.id };
                    }
                }
                renderRooms();
                renderThreadHeader();
            });
    }

    function refreshUsers() {
        fetch('/api/chat/online')
            .then(res => res.json())
            .then(data => {
                state.users = data.users || [];
                renderUsers();
                renderThreadHeader();
            });
    }

    function initInput() {
        const input = document.getElementById('chatInput');
        const sendBtn = document.getElementById('chatSendBtn');
        if (!input || !sendBtn) return;

        function sendCurrentMessage() {
            let bodyText = input.value.trim();
            if (!bodyText) return;
            if (bodyText === '/source') {
                bodyText = 'https://github.com/awehttam/binkterm-php';
            }
            sendMessage(bodyText);
            input.value = '';
        }

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                sendCurrentMessage();
            }
        });

        sendBtn.addEventListener('click', sendCurrentMessage);
    }

    function initModerationMenu() {
        if (!window.currentUserIsAdmin) return;
        const menu = document.getElementById('chatContextMenu');
        const thread = document.getElementById('chatThread');
        if (!menu || !thread) return;

        let currentTarget = null;

        function hideMenu() {
            menu.classList.add('d-none');
            currentTarget = null;
        }

        function openMenu(event) {
            const author = event.target.closest('.chat-message-author');
            if (!author) return;

            event.preventDefault();
            const userId = author.dataset.userId;
            const roomId = state.active.type === 'room' ? state.active.id : null;

            if (!userId || !roomId) {
                hideMenu();
                return;
            }

            currentTarget = { userId: parseInt(userId, 10), roomId };
            menu.style.left = `${event.pageX}px`;
            menu.style.top = `${event.pageY}px`;
            menu.classList.remove('d-none');
        }

        thread.addEventListener('contextmenu', openMenu);
        thread.addEventListener('click', (event) => {
            if (!event.target.closest('.chat-message-author')) return;
            openMenu(event);
        });

        menu.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-action]');
            if (!button || !currentTarget) return;

            const action = button.dataset.action;
            if (!confirm(`${action === 'ban' ? 'Ban' : 'Kick'} this user from the room?`)) {
                hideMenu();
                return;
            }

            fetch('/api/chat/moderate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action,
                    room_id: currentTarget.roomId,
                    user_id: currentTarget.userId
                })
            }).then(res => res.json()).then(data => {
                if (!data.success) {
                    alert(data.error || 'Moderation failed');
                }
            }).catch(() => {
                alert('Moderation failed');
            }).finally(() => {
                hideMenu();
            });
        });

        document.addEventListener('click', hideMenu);
        document.addEventListener('scroll', hideMenu, true);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideMenu();
            }
        });
    }

    async function init() {
        await loadState();
        initInput();
        initModerationMenu();
        const loadOlderBtn = document.getElementById('chatLoadOlderBtn');
        if (loadOlderBtn) {
            loadOlderBtn.addEventListener('click', loadOlderMessages);
        }
        refreshRooms();
        refreshUsers();
        renderUnreadBadge();
        if (state.active.id) {
            loadMessages();
        }
        setInterval(refreshUsers, 15000);
        connectEventStream();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
