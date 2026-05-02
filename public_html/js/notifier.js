(() => {
    let chatUnreadTotal = 0;
    let mailUnreadTotal = 0;
    let filesUnread = false;
    let fileApprovalsUnread = false;
    let chatUnread = false;
    let mrcUnread = false;
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
        try {
            const sharedLastSoundAt = parseInt(UserStorage.getItem('chatSoundAt') || '0', 10) || 0;
            if (now - sharedLastSoundAt < CHAT_SOUND_COOLDOWN_MS) {
                lastChatSoundAt = now;
                return;
            }
            UserStorage.setItem('chatSoundAt', String(now));
        } catch (_) {
            // Ignore storage errors and fall back to per-tab cooldown only.
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

    function updateAdminModerationIcon(stats, clearTarget = null) {
        const moderationLinks = document.querySelectorAll('.echomail-moderation-menu-link');
        const areaManagementLinks = document.querySelectorAll('.area-management-menu-link');
        const adminMenuLinks = document.querySelectorAll('.admin-menu-link');
        let pendingModeration = parseInt(stats?.pending_echomail_moderation || 0, 10) || 0;

        if (clearTarget === 'echomail-moderation' || isPathMatch('/admin/echomail-moderation')) {
            pendingModeration = 0;
        }

        const hasModeration = pendingModeration > 0;
        moderationLinks.forEach((link) => link.classList.toggle('unread', hasModeration));
        areaManagementLinks.forEach((link) => link.classList.toggle('unread', hasModeration));
        adminMenuLinks.forEach((link) => link.classList.toggle('unread', hasModeration));
    }

    function updateFileIcon(stats, clearTarget = null) {
        const fileMenuIcons = document.querySelectorAll('.file-menu-icon');
        const fileMenuParentLinks = document.querySelectorAll('.file-menu-parent-link');
        const fileMenuLinks = document.querySelectorAll('.file-menu-link');
        const fileApprovalLinks = document.querySelectorAll('.file-approvals-menu-link');
        const newFiles = parseInt(stats?.new_files || 0, 10) || 0;
        const pendingApprovals = parseInt(stats?.pending_file_approvals || 0, 10) || 0;

        filesUnread = newFiles > 0;
        fileApprovalsUnread = pendingApprovals > 0;

        if (clearTarget === 'files' || isPathMatch('/files')) {
            filesUnread = false;
        }
        if (clearTarget === 'file-approvals' || isPathMatch('/admin/file-approvals')) {
            fileApprovalsUnread = false;
        }

        const fileIconUnread = filesUnread || fileApprovalsUnread;
        fileMenuIcons.forEach((icon) => icon.classList.toggle('unread', fileIconUnread));
        fileMenuParentLinks.forEach((link) => link.classList.toggle('unread', fileIconUnread));
        fileMenuLinks.forEach((link) => link.classList.toggle('unread', filesUnread));
        fileApprovalLinks.forEach((link) => link.classList.toggle('unread', fileApprovalsUnread));
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

    function updateMrcIcons() {
        const menuIcons = document.querySelectorAll('.mrc-menu-icon');
        if (mrcUnread) {
            menuIcons.forEach((icon) => icon.classList.add('unread'));
        } else {
            menuIcons.forEach((icon) => icon.classList.remove('unread'));
        }
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

    async function refreshMailState(clearTarget = null, suppressSounds = false) {
        fetch('/api/dashboard/stats')
            .then(res => res.json())
            .then(async data => {
                const chatTotal = parseInt(data?.chat_total || 0, 10) || 0;
                chatUnread = chatTotal > 0;

                maybePlayNotificationSounds(data, suppressSounds);
                updateMailIcons(data, clearTarget);
                updateFileIcon(data, clearTarget);
                updateAdminModerationIcon(data, clearTarget);

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
        if (isPathMatch('/games/mrc')) {
            mrcUnread = false;
            updateMrcIcons();
        }
        if (isPathMatch('/netmail')) {
            await markSeen('netmail', stats.total_netmail);
            await refreshMailState('netmail', true);
        } else if (isPathMatch('/echomail') || isPathMatch('/echolist')) {
            await markSeen('echomail', stats.echomail_max_id ?? 0);
            await refreshMailState('echomail', true);
        } else if (isPathMatch('/files')) {
            await markSeen('files', stats.files_max_id ?? 0);
            await refreshMailState('files', true);
        } else if (isPathMatch('/admin/file-approvals')) {
            await markSeen('file-approvals', stats.pending_files_max_id ?? 0);
            await refreshMailState('file-approvals', true);
        } else if (isPathMatch('/admin/echomail-moderation')) {
            await refreshMailState('echomail-moderation', true);
        }
        if (isPathMatch('/chat')) {
            await refreshMailState('chat', true);
        }
        if (!isPathMatch('/netmail') && !isPathMatch('/echomail') && !isPathMatch('/echolist') && !isPathMatch('/files') && !isPathMatch('/admin/file-approvals') && !isPathMatch('/admin/echomail-moderation') && !isPathMatch('/chat')) {
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

            window.BinkStream.on('mrc_message', function (data) {
                if (isPathMatch('/games/mrc')) {
                    return;
                }
                // Suppress badge for messages already seen in the MRC window.
                // mrc.js writes the highest rendered mrc_messages.id to UserStorage
                // so replayed BinkStream events for already-seen messages are ignored.
                // Only notify for private (direct) messages, not room messages.
                if (!data || !data.is_private) {
                    return;
                }
                // Suppress badge for messages already seen in the MRC window.
                // mrc.js writes the highest rendered mrc_messages.id to UserStorage
                // so replayed BinkStream events for already-seen messages are ignored.
                const lastSeen = parseInt(UserStorage.getItem('mrc_last_seen_id') || '0', 10);
                if (data.id && data.id <= lastSeen) {
                    return;
                }
                mrcUnread = true;
                updateMrcIcons();
                maybeChatSound();
            });

            // Refresh badges when mail changes or when legacy insert-based
            // file events arrive. Separate file-specific events handle
            // approval transitions that do not INSERT new rows.
            let _dashboardStatsTimer = null;
            const scheduleRefresh = function (delayMs = 1000) {
                clearTimeout(_dashboardStatsTimer);
                _dashboardStatsTimer = setTimeout(refreshMailState, delayMs);
            };

            window.BinkStream.on('dashboard_stats', function () {
                scheduleRefresh(2000);
            });
            window.BinkStream.on('files_changed', function () {
                scheduleRefresh(500);
            });
            window.BinkStream.on('file_approvals_changed', function () {
                scheduleRefresh(500);
            });
        }
    }

    function showWallMessage(from, message) {
        var modalId = 'wallMessageModal';
        var existing = document.getElementById(modalId);
        if (existing) { existing.remove(); }

        var escaped = function (str) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(str));
            return d.innerHTML;
        };

        var html = [
            '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-labelledby="wallMessageModalLabel" aria-modal="true" role="dialog">',
            '  <div class="modal-dialog modal-dialog-centered">',
            '    <div class="modal-content">',
            '      <div class="modal-header">',
            '        <h5 class="modal-title" id="wallMessageModalLabel">',
            '          <i class="fas fa-bullhorn me-2"></i>',
            window.t ? window.t('ui.admin.wall.incoming', { from: escaped(from) }, 'Incoming message from ' + escaped(from)) : ('Incoming message from ' + escaped(from)),
            '        </h5>',
            '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>',
            '      </div>',
            '      <div class="modal-body">',
            '        <p class="mb-0">' + escaped(message) + '</p>',
            '      </div>',
            '      <div class="modal-footer">',
            '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">',
            window.t ? window.t('ui.common.close', {}, 'Close') : 'Close',
            '        </button>',
            '      </div>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n');

        document.body.insertAdjacentHTML('beforeend', html);
        var el = document.getElementById(modalId);
        el.addEventListener('hidden.bs.modal', function () { el.remove(); });
        var modal = new bootstrap.Modal(el);
        modal.show();
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

    // Register the wall_message listener immediately — do NOT wait for the async
    // init() to complete. Events can arrive as soon as BinkStream connects, and
    // any event that arrives before a listener is registered is silently dropped.
    // Registering here (synchronously, at script-parse time) ensures the handler
    // is in place before the first SSE event can be dispatched to this page.
    if (window.BinkStream) {
        window.BinkStream.on('wall_message', function (data) {
            if (!data || !data.from) { return; }
            showWallMessage(data.from, data.message || '');
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
