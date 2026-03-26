(() => {
    let chatUnreadTotal = 0;
    let mailUnreadTotal = 0;
    let filesUnread = false;
    let chatUnread = false;
    let mailUnread = { netmail: false, echomail: false };
    let initialized = false;
    let pollInterval = null;
    let audioUnlocked = false;
    let lastChatSoundAt = 0;
    const CHAT_SOUND_COOLDOWN_MS = 3000; // 3 s — standard notification debounce (Discord/Slack/IRC)
    const audioCache = new Map();
    let previousStats = {
        unread_netmail: 0,
        new_echomail: 0,
        chat_total: 0,
        new_files: 0
    };

    const defaultNotificationSounds = {
        chat: 'notify3',
        echomail: 'disabled',
        netmail: 'notify1',
        files: 'disabled'
    };

    function getAudio(soundName) {
        if (!audioCache.has(soundName)) {
            const audio = new Audio(`/sounds/${soundName}.mp3`);
            audio.preload = 'auto';
            audioCache.set(soundName, audio);
        }
        return audioCache.get(soundName);
    }

    function getNotificationSound(key) {
        const sound = window.userSettings?.[key];
        if (sound === 'disabled') {
            return 'disabled';
        }

        if (typeof sound === 'string' && /^notify\d+$/.test(sound)) {
            return sound;
        }

        if (key === 'chat_notification_sound') {
            return 'notify3';
        }
        if (key === 'echomail_notification_sound' || key === 'file_notification_sound') {
            return 'disabled';
        }
        return 'notify1';
    }

    function playNotificationSound(type) {
        const keyMap = {
            chat: 'chat_notification_sound',
            echomail: 'echomail_notification_sound',
            netmail: 'netmail_notification_sound',
            files: 'file_notification_sound'
        };
        const soundName = getNotificationSound(keyMap[type] || '') || defaultNotificationSounds[type] || 'notify1';
        if (soundName === 'disabled') {
            return;
        }
        const audio = getAudio(soundName);
        audio.muted = false;
        audio.pause();
        audio.currentTime = 0;
        audio.play().catch(() => {
            const retryAudio = new Audio(`/sounds/${soundName}.mp3`);
            retryAudio.play().catch(() => {});
        });
    }

    async function unlockAudio() {
        if (audioUnlocked) {
            return;
        }
        audioUnlocked = true;
        document.removeEventListener('pointerdown', unlockAudio);
        document.removeEventListener('keydown', unlockAudio);

        // Prime each shipped sound after a user gesture so later notifications
        // are not blocked by browser autoplay restrictions.
        for (let i = 1; i <= 5; i++) {
            const audio = getAudio(`notify${i}`);
            audio.muted = true;
            try {
                audio.currentTime = 0;
                await audio.play();
                audio.pause();
                audio.currentTime = 0;
            } catch (err) {
                // Ignore unlock failures; playback will retry on demand.
            } finally {
                audio.muted = false;
            }
        }
    }

    function maybeChatSound() {
        if (isPathMatch('/chat')) {
            return;
        }
        if (!audioUnlocked) {
            return;
        }
        const now = Date.now();
        if (now - lastChatSoundAt < CHAT_SOUND_COOLDOWN_MS) {
            return;
        }
        lastChatSoundAt = now;
        playNotificationSound('chat');
    }

    function maybePlayNotificationSounds(stats, suppressSounds = false) {
        const currentStats = {
            unread_netmail: parseInt(stats?.unread_netmail || 0, 10) || 0,
            new_echomail: parseInt(stats?.new_echomail || 0, 10) || 0,
            chat_total: parseInt(stats?.chat_total || 0, 10) || 0,
            new_files: parseInt(stats?.new_files || 0, 10) || 0
        };

        if (!suppressSounds && audioUnlocked) {
            if (currentStats.chat_total > previousStats.chat_total) {
                maybeChatSound();
            }
            if (currentStats.new_echomail > previousStats.new_echomail) {
                playNotificationSound('echomail');
            }
            if (currentStats.unread_netmail > previousStats.unread_netmail) {
                playNotificationSound('netmail');
            }
            if (currentStats.new_files > previousStats.new_files) {
                playNotificationSound('files');
            }
        }

        previousStats = currentStats;
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

    function updateFileIcon(stats, clearTarget = null) {
        const fileMenuIcons = document.querySelectorAll('.file-menu-icon');
        const fileMenuLinks = document.querySelectorAll('.file-menu-link');
        const newFiles = parseInt(stats?.new_files || 0, 10) || 0;
        filesUnread = newFiles > 0;

        if (clearTarget === 'files') {
            filesUnread = false;
        }

        fileMenuIcons.forEach((icon) => icon.classList.toggle('unread', filesUnread));
        fileMenuLinks.forEach((link) => link.classList.toggle('unread', filesUnread));
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
            // Validate credit_balance is a finite number
            const creditValue = Number(stats.credit_balance);
            if (!Number.isFinite(creditValue)) {
                return; // Leave existing text unchanged if invalid
            }

            // Extract the credit symbol from the existing text (everything before the number)
            const currentText = creditBalanceElement.textContent || '';
            const symbolMatch = currentText.match(/^([^\d]*)/);
            const symbol = symbolMatch ? symbolMatch[1] : '';

            // Update with new balance
            creditBalanceElement.textContent = symbol + Math.floor(creditValue);
        }
    }

    function updateMailIcons(stats, clearTarget = null) {
        const netmailIcon = document.getElementById('netmailMenuIcon');
        const echomailIcon = document.getElementById('echomailMenuIcon');
        const netmailUnread = parseInt(stats?.unread_netmail || 0, 10) || 0;
        const echomailUnread = parseInt(stats?.new_echomail || 0, 10) || 0;
        mailUnread.netmail = netmailUnread > 0;
        mailUnread.echomail = echomailUnread > 0;

        if (clearTarget === 'netmail' || isPathMatch('/netmail')) {
            mailUnread.netmail = false;
        }
        if (clearTarget === 'echomail' || isPathMatch('/echomail') || isPathMatch('/echolist')) {
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

    async function refreshMailState(clearTarget = null, suppressSounds = false) {
        fetch('/api/dashboard/stats')
            .then(res => res.json())
            .then(async data => {
                const chatTotal = parseInt(data?.chat_total || 0, 10) || 0;
                chatUnread = chatTotal > 0;

                maybePlayNotificationSounds(data, suppressSounds);
                updateMailIcons(data, clearTarget);
                updateFileIcon(data, clearTarget);

                if (clearTarget === 'chat' || isPathMatch('/chat')) {
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

        document.addEventListener('pointerdown', unlockAudio, { once: true });
        document.addEventListener('keydown', unlockAudio, { once: true });

        if (typeof window.loadUserSettings === 'function') {
            try {
                await window.loadUserSettings();
            } catch (err) {
                // ignore
            }
        }

        // Get current stats first, then mark as seen with the current count
        const stats = await fetch('/api/dashboard/stats').then(r => r.json()).catch(() => ({}));
        maybePlayNotificationSounds(stats, true);

        if (isPathMatch('/chat')) {
            chatUnread = false;
            updateChatIcons();
            await markSeen('chat', stats.chat_max_id ?? 0);
        }
        if (isPathMatch('/netmail')) {
            await markSeen('netmail', stats.total_netmail);
            await refreshMailState('netmail', true);
        } else if (isPathMatch('/echomail') || isPathMatch('/echolist')) {
            await markSeen('echomail', stats.total_echomail);
            await refreshMailState('echomail', true);
        } else if (isPathMatch('/files')) {
            await markSeen('files', stats.files_max_id ?? 0);
            await refreshMailState('files', true);
        }
        if (isPathMatch('/chat')) {
            await refreshMailState('chat', true);
        }
        if (!isPathMatch('/netmail') && !isPathMatch('/echomail') && !isPathMatch('/echolist') && !isPathMatch('/files') && !isPathMatch('/chat')) {
            refreshMailState(null, true);
        }

        // Polling is disabled — BinkStream delivers all events in real-time.
        // dashboard_stats fires on echomail/netmail/files INSERT; chat_message fires on chat INSERT.
        // Re-enable by uncommenting the lines below if BinkStream is unavailable.
        // if (pollInterval) { clearInterval(pollInterval); }
        // pollInterval = setInterval(refreshMailState, 30000);

        if (window.BinkStream) {
            // Real-time chat sound — fires immediately on message arrival.
            // Incrementing previousStats.chat_total prevents the dashboard_stats
            // event (which also fires on chat INSERT via the chat trigger) from
            // double-firing a sound for the same message.
            window.BinkStream.on('chat_message', function () {
                previousStats.chat_total++;
                if (!isPathMatch('/chat')) {
                    chatUnread = true;
                    updateChatIcons();
                }
                maybeChatSound();
            });

            // Refresh badges when echomail, netmail, or files arrive.
            // Debounced to absorb bursts from concurrent imports.
            let _dashboardStatsTimer = null;
            window.BinkStream.on('dashboard_stats', function () {
                clearTimeout(_dashboardStatsTimer);
                _dashboardStatsTimer = setTimeout(refreshMailState, 2000);
            });
        }
    }

    // Listen for postMessage events from WebDoors (credit updates, etc.)
    window.addEventListener('message', (event) => {
        // Validate origin - only accept messages from same origin
        if (event.origin !== window.location.origin) {
            return;
        }

        // Handle credit balance updates from WebDoors
        if (event.data && event.data.type === 'binkterm:updateCredits') {
            const creditBalanceElement = document.getElementById('headerCreditBalance');
            if (!creditBalanceElement) {
                return;
            }

            // Validate credits value is numeric and non-negative
            const credits = event.data.credits;
            if (typeof credits !== 'number' || !isFinite(credits) || credits < 0) {
                console.warn('Invalid credits value received:', credits);
                return;
            }

            // Extract the credit symbol from the existing text
            const currentText = creditBalanceElement.textContent || '';
            const symbolMatch = currentText.match(/^([^\d]*)/);
            const symbol = symbolMatch ? symbolMatch[1] : '';

            // Update with validated balance from WebDoor
            creditBalanceElement.textContent = symbol + Math.floor(credits);
        }
    });

    document.addEventListener('DOMContentLoaded', init);
})();
