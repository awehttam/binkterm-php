(() => {
    const CHAT_NOTIFY_DB = 'bink_chat';
    const CHAT_NOTIFY_STORE = 'settings';
    const CHAT_NOTIFY_KEY = `state:${window.currentUserId || 'unknown'}`;
    const CHAT_RECONNECT_MS = 2000;

    let lastEventId = 0;
    let chatUnreadTotal = 0;
    let mailUnreadTotal = 0;
    let mailLastCounts = { netmail: 0, echomail: 0 };
    let mailUnread = { netmail: false, echomail: false };

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

    function updateMessagingIcon() {
        const messagingIcon = document.getElementById('messagingMenuIcon');
        if (!messagingIcon) return;
        if (chatUnreadTotal + mailUnreadTotal > 0) {
            messagingIcon.classList.add('unread');
        } else {
            messagingIcon.classList.remove('unread');
        }
    }

    function updateChatIcons(unreadCounts) {
        chatUnreadTotal = Object.values(unreadCounts).reduce((sum, val) => sum + val, 0);
        const menuIcons = document.querySelectorAll('.chat-menu-icon');
        if (chatUnreadTotal > 0) {
            menuIcons.forEach((icon) => icon.classList.add('unread'));
        } else {
            menuIcons.forEach((icon) => icon.classList.remove('unread'));
        }
        updateMessagingIcon();
    }

    function updateMailIcons(stats, clearTarget = null) {
        const netmailIcon = document.getElementById('netmailMenuIcon');
        const echomailIcon = document.getElementById('echomailMenuIcon');
        const netmailUnread = parseInt(stats?.unread_netmail || 0, 10) || 0;
        const echomailUnread = parseInt(stats?.new_echomail || 0, 10) || 0;
        if (netmailUnread > mailLastCounts.netmail) {
            mailUnread.netmail = true;
        }
        if (echomailUnread > mailLastCounts.echomail) {
            mailUnread.echomail = true;
        }

        if (clearTarget === 'netmail') {
            mailUnread.netmail = false;
        }
        if (clearTarget === 'echomail') {
            mailUnread.echomail = false;
        }

        mailLastCounts = {
            netmail: netmailUnread,
            echomail: echomailUnread
        };

        mailUnreadTotal = (mailUnread.netmail ? 1 : 0) + (mailUnread.echomail ? 1 : 0);

        if (netmailIcon) {
            if (mailUnread.netmail) {
                netmailIcon.classList.add('unread');
            } else {
                netmailIcon.classList.remove('unread');
            }
        }

        if (echomailIcon) {
            if (mailUnread.echomail) {
                echomailIcon.classList.add('unread');
            } else {
                echomailIcon.classList.remove('unread');
            }
        }

        updateMessagingIcon();
    }

    async function refreshUnreadState() {
        const stored = await idbGet(CHAT_NOTIFY_KEY);
        const unreadCounts = stored && stored.unreadCounts ? stored.unreadCounts : {};
        if (stored && stored.lastEventId) {
            lastEventId = stored.lastEventId;
        }
        if (stored && stored.mailLastCounts) {
            mailLastCounts = stored.mailLastCounts;
        }
        if (stored && stored.mailUnread) {
            mailUnread = stored.mailUnread;
        }
        updateChatIcons(unreadCounts);
        updateMailIcons({
            unread_netmail: mailLastCounts.netmail,
            new_echomail: mailLastCounts.echomail
        });
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
            lastEventId: lastEventId,
            mailLastCounts: mailLastCounts,
            mailUnread: mailUnread
        });

        updateChatIcons(unreadCounts);
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

    async function refreshMailState(clearTarget = null) {
        fetch('/api/dashboard/stats')
            .then(res => res.json())
            .then(async data => {
                updateMailIcons(data, clearTarget);
                const stored = await idbGet(CHAT_NOTIFY_KEY);
                await idbSet(CHAT_NOTIFY_KEY, {
                    active: stored && stored.active ? stored.active : null,
                    unreadCounts: stored && stored.unreadCounts ? stored.unreadCounts : {},
                    lastEventId: lastEventId,
                    mailLastCounts: mailLastCounts,
                    mailUnread: mailUnread
                });
            })
            .catch(() => {});
    }

    async function init() {
        await refreshUnreadState();
        if (window.location.pathname === '/chat') {
            const stored = await idbGet(CHAT_NOTIFY_KEY);
            const cleared = {
                active: stored && stored.active ? stored.active : null,
                unreadCounts: {},
                lastEventId: lastEventId,
                mailLastCounts: stored && stored.mailLastCounts ? stored.mailLastCounts : mailLastCounts,
                mailUnread: stored && stored.mailUnread ? stored.mailUnread : mailUnread
            };
            await idbSet(CHAT_NOTIFY_KEY, cleared);
            updateChatIcons({});
        }
        if (window.location.pathname === '/netmail') {
            await refreshMailState('netmail');
        } else if (window.location.pathname === '/echomail') {
            await refreshMailState('echomail');
        }
        if (window.EventSource !== undefined) {
            connectEventStream();
        }
        setInterval(refreshUnreadState, 10000);
        if (window.location.pathname !== '/netmail' && window.location.pathname !== '/echomail') {
            refreshMailState();
        }
        setInterval(refreshMailState, 30000);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
