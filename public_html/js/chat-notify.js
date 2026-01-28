(() => {
    const CHAT_NOTIFY_DB = 'bink_chat';
    const CHAT_NOTIFY_STORE = 'settings';
    const CHAT_NOTIFY_KEY = `state:${window.currentUserId || 'unknown'}`;
    const CHAT_RECONNECT_MS = 2000;

    let lastEventId = 0;

    function openDb() {
        return new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB unavailable'));
                return;
            }
            const request = indexedDB.open(CHAT_NOTIFY_DB, 1);
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(CHAT_NOTIFY_STORE)) {
                    db.createObjectStore(CHAT_NOTIFY_STORE, { keyPath: 'key' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error || new Error('IndexedDB open failed'));
        });
    }

    async function idbGet(key) {
        try {
            const db = await openDb();
            return await new Promise((resolve, reject) => {
                const tx = db.transaction(CHAT_NOTIFY_STORE, 'readonly');
                const store = tx.objectStore(CHAT_NOTIFY_STORE);
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
                const tx = db.transaction(CHAT_NOTIFY_STORE, 'readwrite');
                const store = tx.objectStore(CHAT_NOTIFY_STORE);
                store.put({ key, value });
                tx.oncomplete = () => resolve();
                tx.onerror = () => reject(tx.error);
            });
        } catch (err) {
            // ignore
        }
    }

    function threadKey(thread) {
        return `${thread.type}:${thread.id}`;
    }

    function updateMenuIcon(unreadCounts) {
        const total = Object.values(unreadCounts).reduce((sum, val) => sum + val, 0);
        const menuIcons = document.querySelectorAll('.chat-menu-icon');
        const messagingIcon = document.getElementById('messagingMenuIcon');
        if (total > 0) {
            menuIcons.forEach((icon) => icon.classList.add('unread'));
            if (messagingIcon) {
                messagingIcon.classList.add('unread');
            }
        } else {
            menuIcons.forEach((icon) => icon.classList.remove('unread'));
            if (messagingIcon) {
                messagingIcon.classList.remove('unread');
            }
        }
    }

    async function refreshUnreadState() {
        const stored = await idbGet(CHAT_NOTIFY_KEY);
        const unreadCounts = stored && stored.unreadCounts ? stored.unreadCounts : {};
        if (stored && stored.lastEventId) {
            lastEventId = stored.lastEventId;
        }
        updateMenuIcon(unreadCounts);
    }

    async function handleEvent(payload) {
        if (!payload || payload.type !== 'chat') return;
        const message = payload.payload;
        if (!message) return;

        const stored = await idbGet(CHAT_NOTIFY_KEY);
        const unreadCounts = stored && stored.unreadCounts ? stored.unreadCounts : {};
        const active = stored && stored.active ? stored.active : null;

        const thread = message.type === 'room'
            ? { type: 'room', id: message.room_id }
            : { type: 'dm', id: message.from_user_id };
        const key = threadKey(thread);

        const isActive = active && active.type === thread.type && active.id === thread.id;
        if (!isActive) {
            unreadCounts[key] = (unreadCounts[key] || 0) + 1;
        }

        if (payload.id) {
            lastEventId = payload.id;
        }

        await idbSet(CHAT_NOTIFY_KEY, {
            active: active,
            unreadCounts: unreadCounts,
            lastEventId: lastEventId
        });

        updateMenuIcon(unreadCounts);
    }

    function connectEventStream() {
        const params = new URLSearchParams();
        if (lastEventId) {
            params.set('since_id', lastEventId);
        }
        const source = new EventSource(`/api/events/stream?${params.toString()}`);
        source.addEventListener('message', (event) => {
            const data = JSON.parse(event.data);
            handleEvent(data);
        });
        source.addEventListener('error', () => {
            source.close();
            setTimeout(connectEventStream, CHAT_RECONNECT_MS);
        });
    }

    async function init() {
        await refreshUnreadState();
        if (window.location.pathname === '/chat') {
            const stored = await idbGet(CHAT_NOTIFY_KEY);
            const cleared = {
                active: stored && stored.active ? stored.active : null,
                unreadCounts: {},
                lastEventId: lastEventId
            };
            await idbSet(CHAT_NOTIFY_KEY, cleared);
            updateMenuIcon({});
        }
        if (window.EventSource !== undefined) {
            connectEventStream();
        }
        setInterval(refreshUnreadState, 10000);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
