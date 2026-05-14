(() => {
    const CHAT_DB_NAME = 'bink_chat';
    const CHAT_DB_STORE = 'settings';
    const CHAT_STORAGE_KEY = `state:${window.currentUserId || 'unknown'}`;
    const CHAT_MAX_MESSAGES = 500;
    // When BinkStream realtime is active we fall back to a slow safety-net poll;
    // when realtime is unavailable we use a faster interval.
    const CHAT_POLL_INTERVAL_SSE_MS  = 30000;
    const CHAT_POLL_INTERVAL_POLL_MS = 1000;

    const state = {
        rooms: [],
        users: [],
        active: { type: 'room', id: null },
        unreadCounts: {},
        lastSeenIds: {},   // { 'room:2': 383 } — highest msg id seen per thread
        oldestIds: {},
        hasMore: {},
        lastChatId: 0,
        loadingHistory: false,
        displayedMessageIds: new Set(), // Track displayed messages to prevent duplicates
        incomingBuffer: []  // BinkStream messages that arrived during a loadMessages fetch
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
            if (state.active.id !== null && state.active.id !== undefined) {
                state.active.id = parseInt(state.active.id, 10) || state.active.id;
            }
            state.unreadCounts = stored.unreadCounts || {};
            state.lastSeenIds = stored.lastSeenIds || {};
            state.lastChatId = stored.lastChatId || stored.lastEventId || 0;
        }
    }

    function saveState() {
        const payload = {
            active: state.active,
            unreadCounts: state.unreadCounts,
            lastSeenIds: state.lastSeenIds,
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
        if (window.formatFullDate) {
            return window.formatFullDate(ts);
        } else {
            const userDateFormat = window.userSettings?.date_format || 'en-US';
            const parsed = typeof window.parseAppDate === 'function'
                ? window.parseAppDate(ts)
                : new Date(String(ts).replace(' ', 'T'));
            return parsed ? parsed.toLocaleString(userDateFormat) : '';
        }
    }

    function threadKey(thread) {
        return `${thread.type}:${thread.id}`;
    }

    function setActiveThread(thread) {
        // Normalize id to integer so === comparisons against SSE payload ids
        // (always integers from json_build_object) work regardless of whether
        // the API returned a numeric string or an actual number.
        state.active = { type: thread.type, id: thread.id != null ? (parseInt(thread.id, 10) || thread.id) : thread.id };
        state.unreadCounts[threadKey(state.active)] = 0;
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
            if (user.is_bot) {
                const inner = document.createElement('span');
                inner.style.display = 'flex';
                inner.style.alignItems = 'center';
                inner.style.gap = '6px';
                const icon = document.createElement('i');
                icon.className = 'fas fa-robot small';
                icon.style.flexShrink = '0';
                const label = document.createElement('span');
                label.textContent = user.username;
                inner.appendChild(icon);
                inner.appendChild(label);
                item.appendChild(inner);
            } else {
                item.textContent = user.username;
            }
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
            if (user && user.is_bot && user.description) {
                metaEl.textContent = user.description;
            } else {
                metaEl.textContent = user && user.location ? user.location : '';
            }
        }
    }

    function renderThread(messages) {
        const container = document.getElementById('chatThread');
        const loadOlderWrap = document.getElementById('chatLoadOlderWrap');
        // Full re-render — reset tracking so BinkStream catch-up IDs don't
        // suppress messages that are about to be rendered from scratch.
        state.displayedMessageIds.clear();
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
            const msgId = msg.id ? parseInt(msg.id, 10) : null;
            if (msgId && state.displayedMessageIds.has(msgId)) {
                return;
            }
            frag.appendChild(buildMessageElement(msg));
            if (msgId) {
                state.displayedMessageIds.add(msgId);
            }
        });
        container.insertBefore(frag, insertBefore);
        trimMessages(container);
    }

    function appendMessage(msg, skipScroll) {
        // Normalize to integer so API-loaded string IDs and SSE integer IDs compare equal.
        const msgId = msg.id ? parseInt(msg.id, 10) : null;
        if (msgId && state.displayedMessageIds.has(msgId)) {
            return;
        }

        const container = document.getElementById('chatThread');
        const wrapper = buildMessageElement(msg);
        container.appendChild(wrapper);

        if (msgId) {
            state.displayedMessageIds.add(msgId);
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
        if (msg.markup_html) {
            body.innerHTML = msg.markup_html;
        } else {
            body.innerHTML = escapeHtml(msg.body || '');
        }

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
        state.incomingBuffer = [];
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
                    // Record the highest ID from history so that inactive-thread dedup
                    // can distinguish genuinely new messages from BinkStream catch-up
                    // re-deliveries after the user switches away from this thread.
                    const maxId = parseInt(messages[messages.length - 1].id, 10);
                    if (maxId > (state.lastSeenIds[key] || 0)) {
                        state.lastSeenIds[key] = maxId;
                    }
                }
                state.hasMore[key] = (data.has_more !== undefined) ? data.has_more : messages.length === limit;
                updateLoadOlderVisibility();
                // Flush messages that arrived via BinkStream while the fetch was in flight.
                // Handles the race where a bridge message is delivered between the API
                // request being sent and renderThread() clearing the DOM.
                const buffered = state.incomingBuffer.splice(0);
                buffered.forEach(msg => handleIncoming(msg));
                saveState();
            })
            .catch(() => {
                // Drain buffer on error so buffered messages aren't silently dropped.
                const buffered = state.incomingBuffer.splice(0);
                buffered.forEach(msg => handleIncoming(msg));
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
        const msgId = parseInt(payload.id, 10);
        // Guard against duplicate delivery (BinkStream catch-up + poll overlap).
        if (state.displayedMessageIds.has(msgId)) return;

        const fromId = parseInt(payload.from_user_id, 10);
        const meId   = parseInt(window.currentUserId, 10);
        const thread = payload.type === 'room'
            ? { type: 'room', id: parseInt(payload.room_id, 10) }
            : {
                type: 'dm',
                id: fromId === meId ? parseInt(payload.to_user_id, 10) : fromId
            };
        const key = threadKey(thread);

        const isActive = state.active.type === thread.type && state.active.id === thread.id;
        console.debug('[chat] handleIncoming decision', {
            msgId,
            thread_type: thread.type, thread_id: thread.id,
            active_type: state.active.type, active_id: state.active.id,
            isActive,
            already_displayed: state.displayedMessageIds.has(msgId),
            lastSeenId: state.lastSeenIds[key] || 0
        });
        if (isActive) {
            // appendMessage() renders and adds the id to displayedMessageIds.
            appendMessage(payload);
            // Track highest seen ID so inactive-thread dedup works after switching away.
            if (msgId > (state.lastSeenIds[key] || 0)) {
                state.lastSeenIds[key] = msgId;
                saveState();
            }
        } else if (fromId !== meId) {
            // Only count as unread if this message is newer than the last time we viewed
            // this thread. BinkStream catch-up can re-deliver already-seen messages on
            // reconnect; lastSeenIds prevents old messages from incrementing the badge.
            if (msgId > (state.lastSeenIds[key] || 0)) {
                state.displayedMessageIds.add(msgId);
                state.unreadCounts[key] = (state.unreadCounts[key] || 0) + 1;
                saveState();
                renderUnreadBadge();
                renderRooms();
                renderUsers();
            } else {
                // Already seen — mark processed so future catch-ups don't re-count it.
                state.displayedMessageIds.add(msgId);
            }
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
                        // Use setActiveThread() so the default room's unread count is cleared
                        // and messages are loaded automatically, same as a manual room click.
                        setActiveThread({ type: 'room', id: lobby.id });
                        return;
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
        // Clear the active thread's unread count immediately — its messages load on init,
        // so any persisted unread count from a previous session is stale.
        if (state.active.id) {
            state.unreadCounts[threadKey(state.active)] = 0;
        }
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

        // Wire BinkStream if available; fall back to fast polling otherwise.
        let sseActive = false;
        if (window.BinkStream) {
            window.BinkStream.on('chat_message', function (payload) {
                if (!payload || !payload.id) return;
                payload._source = (typeof window.BinkStream.getMode === 'function')
                    ? window.BinkStream.getMode()
                    : 'binkstream';
                // Diagnostic: remove when delivery issues are resolved
                console.debug('[chat] BinkStream chat_message arrived', {
                    id: payload.id, type: payload.type,
                    room_id: payload.room_id, from_user_id: payload.from_user_id,
                    loadingHistory: state.loadingHistory,
                    active_type: state.active.type, active_id: state.active.id
                });
                // Buffer messages that arrive while a loadMessages fetch is in flight.
                // Without this, a message delivered between the API request being sent
                // and renderThread() clearing the DOM would be lost permanently.
                if (state.loadingHistory) {
                    state.incomingBuffer.push(payload);
                } else {
                    handleIncoming(payload);
                }
                if (payload.id > state.lastChatId) {
                    state.lastChatId = payload.id;
                    saveState();
                }
            });
            sseActive = true;
        }

        // Polling is disabled — BinkStream delivers messages in real-time.
        // Re-enable by uncommenting the lines below if BinkStream is unavailable.
        // const pollInterval = sseActive ? CHAT_POLL_INTERVAL_SSE_MS : CHAT_POLL_INTERVAL_POLL_MS;
        // pollMessages();
        // setInterval(pollMessages, pollInterval);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
