(() => {
    const CHAT_DB_NAME = 'bink_chat';
    const CHAT_DB_STORE = 'settings';
    const CHAT_STORAGE_KEY = `state:${window.currentUserId || 'unknown'}`;
    const CHAT_MAX_MESSAGES = 500;
    // When BinkStream SSE is active we fall back to a slow safety-net poll;
    // when SSE is unavailable we use a faster interval.
    const CHAT_POLL_INTERVAL_SSE_MS  = 30000;
    const CHAT_POLL_INTERVAL_POLL_MS = 1000;

    const state = {
        rooms: [],
        users: [],
        active: { type: 'room', id: null },
        unreadCounts: {},
        oldestIds: {},
        hasMore: {},
        lastChatId: 0,
        loadingHistory: false,
        displayedMessageIds: new Set() // Track displayed messages to prevent duplicates
    };

    let dbPromise = null;
    function uiT(key, fallback, params = {}) {
        if (window.t) {
            return window.t(key, params, fallback);
        }
        return fallback;
    }

    function apiError(payload, fallback) {
        if (window.getApiErrorMessage) {
            return window.getApiErrorMessage(payload, fallback);
        }
        if (payload && payload.error) {
            return String(payload.error);
        }
        return fallback;
    }

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
            state.lastChatId = stored.lastChatId || stored.lastEventId || 0;
        }
    }

    function saveState() {
        const payload = {
            active: state.active,
            unreadCounts: state.unreadCounts,
            lastChatId: state.lastChatId
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
        // formatFullDate appends 'Z' and expects a naive UTC string like
        // "2026-03-25 18:01:13" (what PHP/PDO returns). SSE payloads from
        // PostgreSQL json_build_object include microseconds and a tz offset
        // ("2026-03-25T18:01:13.922794+00:00"), which breaks new Date(...+'Z').
        // Strip both so either source works correctly.
        const normalized = String(ts)
            .replace(/\.\d+/, '')               // remove fractional seconds
            .replace(/[+-]\d{2}:\d{2}$|Z$/, ''); // remove tz offset or trailing Z
        if (window.formatFullDate) {
            return window.formatFullDate(normalized);
        } else {
            const userDateFormat = window.userSettings?.date_format || 'en-US';
            return new Date(normalized + 'Z').toLocaleString(userDateFormat);
        }
    }

    function threadKey(thread) {
        return `${thread.type}:${thread.id}`;
    }

    function setActiveThread(thread) {
        state.active = thread;
        state.unreadCounts[threadKey(thread)] = 0;
        // Clear displayed message IDs when switching threads
        state.displayedMessageIds.clear();
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
            empty.textContent = uiT('ui.chat.no_one_online', 'No one online');
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
            titleEl.textContent = room ? room.name : uiT('ui.chat.room', 'Room');
            metaEl.textContent = room && room.description ? room.description : '';
        } else {
            const user = state.users.find(u => u.user_id === state.active.id);
            titleEl.textContent = user ? user.username : uiT('ui.chat.direct', 'Direct');
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
        messages.forEach(msg => {
            // Skip if message already displayed (deduplication)
            if (msg.id && state.displayedMessageIds.has(msg.id)) {
                return;
            }
            frag.appendChild(buildMessageElement(msg));
            // Track this message ID
            if (msg.id) {
                state.displayedMessageIds.add(msg.id);
            }
        });
        container.insertBefore(frag, insertBefore);
        trimMessages(container);
    }

    function appendMessage(msg, skipScroll) {
        // Skip if message already displayed (deduplication)
        if (msg.id && state.displayedMessageIds.has(msg.id)) {
            return;
        }

        const container = document.getElementById('chatThread');
        const wrapper = buildMessageElement(msg);
        container.appendChild(wrapper);

        // Track this message ID
        if (msg.id) {
            state.displayedMessageIds.add(msg.id);
        }

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
        const devBadge = window.isDevMode && msg._source
            ? ` <span class="chat-dev-source">(${msg._source})</span>` : '';
        header.innerHTML = `<span class="${authorClass}" data-user-id="${msg.from_user_id || ''}" title="${window.currentUserIsAdmin ? uiT('ui.chat.moderation_hint', 'Right-click or click to moderate') : ''}">${escapeHtml(msg.from_username || uiT('ui.chat.system', 'System'))}</span>${devBadge}
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
                alert(apiError(data, uiT('ui.chat.send_failed', 'Failed to send message')));
            }
        }).catch(() => {
            alert(uiT('ui.chat.send_failed', 'Failed to send message'));
        });
    }

    function pollMessages() {
        const params = new URLSearchParams();
        if (state.lastChatId) {
            params.set('since_id', state.lastChatId);
        }
        fetch(`/api/chat/poll?${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                const messages = data.messages || [];
                messages.forEach(payload => {
                    payload._source = 'poll';
                    handleIncoming(payload);
                    if (payload.id && payload.id > state.lastChatId) {
                        state.lastChatId = payload.id;
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

    function handleIncoming(payload) {
        if (!payload || !payload.id) return;
        // Guard against duplicate delivery (SSE catch-up + poll overlap).
        if (state.displayedMessageIds.has(payload.id)) return;

        const thread = payload.type === 'room'
            ? { type: 'room', id: payload.room_id }
            : {
                type: 'dm',
                id: (payload.from_user_id === window.currentUserId)
                    ? payload.to_user_id
                    : payload.from_user_id
            };
        const key = threadKey(thread);

        const isActive = state.active.type === thread.type && state.active.id === thread.id;
        if (isActive) {
            // appendMessage() renders and adds the id to displayedMessageIds.
            appendMessage(payload);
        } else if (payload.from_user_id !== window.currentUserId) {
            // Inactive thread — track as processed so re-delivery doesn't
            // double-count the unread badge.
            state.displayedMessageIds.add(payload.id);
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
            const target = event.target instanceof Element ? event.target : event.target.parentElement;
            if (!target) return;
            const author = target.closest('.chat-message-author');
            if (!author) return;

            event.preventDefault();
            event.stopPropagation();
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
            if (!confirm(action === 'ban'
                ? uiT('ui.chat.confirm_ban', 'Ban this user from the room?')
                : uiT('ui.chat.confirm_kick', 'Kick this user from the room?'))) {
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
                    alert(apiError(data, uiT('ui.chat.moderation_failed', 'Moderation failed')));
                }
            }).catch(() => {
                alert(uiT('ui.chat.moderation_failed', 'Moderation failed'));
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

        // Wire BinkStream SSE if available; fall back to fast polling otherwise.
        let sseActive = false;
        if (window.BinkStream) {
            window.BinkStream.on('chat_message', function (payload) {
                if (!payload || !payload.id) return;
                // Full message payload arrives via SSE — render directly with no
                // extra HTTP round-trip. Advance the poll fallback cursor so the
                // safety-net poll won't re-deliver the same message.
                // Note: the SSE Last-Event-ID cursor (sse_events.id) is managed
                // automatically by the browser/SharedWorker EventSource.
                payload._source = 'sse';
                handleIncoming(payload);
                if (payload.id > state.lastChatId) {
                    state.lastChatId = payload.id;
                    saveState();
                }
            });
            sseActive = true;
        }

        const pollInterval = sseActive ? CHAT_POLL_INTERVAL_SSE_MS : CHAT_POLL_INTERVAL_POLL_MS;
        pollMessages();
        setInterval(pollMessages, pollInterval);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
