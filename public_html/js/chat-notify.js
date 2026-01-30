(() => {
    let chatUnreadTotal = 0;
    let mailUnreadTotal = 0;
    let chatUnread = false;
    let mailUnread = { netmail: false, echomail: false };

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
        mailUnread.netmail = netmailUnread > 0;
        mailUnread.echomail = echomailUnread > 0;

        if (clearTarget === 'netmail') {
            mailUnread.netmail = false;
        }
        if (clearTarget === 'echomail') {
            mailUnread.echomail = false;
        }

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

    async function refreshMailState(clearTarget = null) {
        fetch('/api/dashboard/stats')
            .then(res => res.json())
            .then(async data => {
                const chatTotal = parseInt(data?.chat_total || 0, 10) || 0;
                chatUnread = chatTotal > 0;

                updateMailIcons(data, clearTarget);

                if (clearTarget === 'chat') {
                    chatUnread = false;
                }

                updateChatIcons();
            })
            .catch(() => {});
    }

    function isPathMatch(path) {
        return window.location.pathname === path || window.location.pathname.startsWith(path + '/');
    }

    async function markSeen(target) {
        try {
            await fetch('/api/notify/seen', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ target })
            });
        } catch (err) {
            // ignore
        }
    }

    async function init() {
        if (isPathMatch('/chat')) {
            chatUnread = false;
            updateChatIcons();
            await markSeen('chat');
        }
        if (isPathMatch('/netmail')) {
            await markSeen('netmail');
            await refreshMailState('netmail');
        } else if (isPathMatch('/echomail')) {
            await markSeen('echomail');
            await refreshMailState('echomail');
        }
        if (isPathMatch('/chat')) {
            await refreshMailState('chat');
        }
        if (!isPathMatch('/netmail') && !isPathMatch('/echomail')) {
            refreshMailState();
        }
        setInterval(refreshMailState, 30000);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
