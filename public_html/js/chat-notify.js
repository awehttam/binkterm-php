(() => {
    let chatUnreadTotal = 0;
    let mailUnreadTotal = 0;
    let chatUnread = false;
    let mailUnread = { netmail: false, echomail: false };
    let initialized = false;
    let pollInterval = null;

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

    function updateCreditBalance(stats) {
        const creditBalanceElement = document.getElementById('headerCreditBalance');
        if (creditBalanceElement && stats?.credit_balance !== undefined) {
            // Extract the credit symbol from the existing text (everything before the number)
            const currentText = creditBalanceElement.textContent || '';
            const symbolMatch = currentText.match(/^([^\d]*)/);
            const symbol = symbolMatch ? symbolMatch[1] : '';

            // Update with new balance
            creditBalanceElement.textContent = symbol + stats.credit_balance;
        }
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
        updateCreditBalance(stats);
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

    async function markSeen(target, currentCount) {
        try {
            await fetch('/api/notify/seen', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ target, current_count: currentCount || 0 })
            });
        } catch (err) {
            // ignore
        }
    }

    async function init() {
        // Prevent multiple initializations
        if (initialized) {
            return;
        }
        initialized = true;

        // Get current stats first, then mark as seen with the current count
        const stats = await fetch('/api/dashboard/stats').then(r => r.json()).catch(() => ({}));

        if (isPathMatch('/chat')) {
            chatUnread = false;
            updateChatIcons();
            await markSeen('chat', stats.total_chat);
        }
        if (isPathMatch('/netmail')) {
            await markSeen('netmail', stats.total_netmail);
            await refreshMailState('netmail');
        } else if (isPathMatch('/echomail')) {
            await markSeen('echomail', stats.total_echomail);
            await refreshMailState('echomail');
        }
        if (isPathMatch('/chat')) {
            await refreshMailState('chat');
        }
        if (!isPathMatch('/netmail') && !isPathMatch('/echomail')) {
            refreshMailState();
        }

        // Clear any existing interval and create new one
        if (pollInterval) {
            clearInterval(pollInterval);
        }
        pollInterval = setInterval(refreshMailState, 30000);
    }

    // Listen for postMessage events from WebDoors (credit updates, etc.)
    window.addEventListener('message', (event) => {
        // Handle credit balance updates from WebDoors
        if (event.data && event.data.type === 'binkterm:updateCredits') {
            const creditBalanceElement = document.getElementById('headerCreditBalance');
            if (creditBalanceElement && event.data.credits !== undefined) {
                // Extract the credit symbol from the existing text
                const currentText = creditBalanceElement.textContent || '';
                const symbolMatch = currentText.match(/^([^\d]*)/);
                const symbol = symbolMatch ? symbolMatch[1] : '';

                // Update with new balance from WebDoor
                creditBalanceElement.textContent = symbol + event.data.credits;
            }
        }
    });

    document.addEventListener('DOMContentLoaded', init);
})();
