(() => {
    let chatUnreadTotal = 0;
    let mailUnreadTotal = 0;
    let chatLastTotal = 0;
    let chatUnread = false;
    let mailLastCounts = { netmail: 0, echomail: 0 };
    let mailUnread = { netmail: false, echomail: false };

    function applyState(state) {
        const next = state || {};
        if (next.mailLastCounts) {
            mailLastCounts = {
                netmail: parseInt(next.mailLastCounts.netmail || 0, 10) || 0,
                echomail: parseInt(next.mailLastCounts.echomail || 0, 10) || 0
            };
        }
        if (next.mailUnread) {
            mailUnread = {
                netmail: !!next.mailUnread.netmail,
                echomail: !!next.mailUnread.echomail
            };
        }
        if (next.chatLastTotal !== undefined) {
            chatLastTotal = parseInt(next.chatLastTotal || 0, 10) || 0;
        }
        if (next.chatUnread !== undefined) {
            chatUnread = !!next.chatUnread;
        }
    }

    async function fetchNotifyState() {
        try {
            const res = await fetch('/api/notify/state');
            if (!res.ok) return null;
            const data = await res.json();
            return data.state || null;
        } catch (err) {
            return null;
        }
    }

    async function saveNotifyState() {
        try {
            await fetch('/api/notify/state', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    state: {
                        mailLastCounts: mailLastCounts,
                        mailUnread: mailUnread,
                        chatLastTotal: chatLastTotal,
                        chatUnread: chatUnread
                    }
                })
            });
        } catch (err) {
            // ignore
        }
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

    function updateChatIcons() {
        chatUnreadTotal = chatUnread ? 1 : 0;
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
        const stored = await fetchNotifyState();
        if (stored) {
            applyState(stored);
        }
        updateChatIcons();
        updateMailIcons({
            unread_netmail: mailLastCounts.netmail,
            new_echomail: mailLastCounts.echomail
        });
    }

    async function refreshMailState(clearTarget = null) {
        fetch('/api/dashboard/stats')
            .then(res => res.json())
            .then(async data => {
                updateMailIcons(data, clearTarget);
                const chatTotal = parseInt(data?.chat_total || 0, 10) || 0;
                if (chatTotal > chatLastTotal) {
                    chatUnread = true;
                }
                if (clearTarget === 'chat') {
                    chatUnread = false;
                }
                chatLastTotal = chatTotal;

                await saveNotifyState();
                updateChatIcons();
            })
            .catch(() => {});
    }

    async function init() {
        await refreshUnreadState();
        if (window.location.pathname === '/chat') {
            chatUnread = false;
            await saveNotifyState();
            updateChatIcons();
        }
        if (window.location.pathname === '/netmail') {
            await refreshMailState('netmail');
        } else if (window.location.pathname === '/echomail') {
            await refreshMailState('echomail');
        }
        if (window.location.pathname === '/chat') {
            await refreshMailState('chat');
        }
        setInterval(refreshUnreadState, 10000);
        if (window.location.pathname !== '/netmail' && window.location.pathname !== '/echomail') {
            refreshMailState();
        }
        setInterval(refreshMailState, 30000);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
