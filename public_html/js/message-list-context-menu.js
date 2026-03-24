window.MessageListContextMenu = (function() {
    function bindLongPress(options) {
        const container = document.getElementById(options.containerId);
        if (!container || container.dataset.longPressBound === '1') {
            return;
        }

        const state = {
            timer: null,
            touch: null,
            suppressNextClick: false
        };

        const holdMs = options.holdMs || 550;
        const moveThreshold = options.moveThreshold || 12;

        function clearTimer() {
            if (state.timer) {
                window.clearTimeout(state.timer);
                state.timer = null;
            }
            state.touch = null;
        }

        function shouldIgnoreTarget(target) {
            if (typeof options.isInteractiveTarget === 'function') {
                return !!options.isInteractiveTarget(target);
            }
            return false;
        }

        function suppressClickAfterLongPress(event) {
            if (!state.suppressNextClick) {
                return;
            }

            if (event.target.closest(options.rowSelector || '.message-row[data-message-id]')) {
                event.preventDefault();
                event.stopPropagation();
            }
            state.suppressNextClick = false;
        }

        container.dataset.longPressBound = '1';

        container.addEventListener('touchstart', function(event) {
            if (!event.touches || event.touches.length !== 1) {
                clearTimer();
                return;
            }

            const row = event.target.closest(options.rowSelector || '.message-row[data-message-id]');
            if (!row || shouldIgnoreTarget(event.target)) {
                clearTimer();
                return;
            }

            const touch = event.touches[0];
            const messageId = Number(row.dataset.messageId);
            if (!messageId) {
                clearTimer();
                return;
            }

            state.touch = {
                messageId: messageId,
                startX: touch.clientX,
                startY: touch.clientY,
                pageX: touch.pageX,
                pageY: touch.pageY,
                fired: false
            };

            state.timer = window.setTimeout(function() {
                if (!state.touch) {
                    return;
                }
                state.touch.fired = true;
                state.suppressNextClick = true;
                options.onLongPress({
                    messageId: state.touch.messageId,
                    pageX: state.touch.pageX,
                    pageY: state.touch.pageY
                });
            }, holdMs);
        }, { passive: true });

        container.addEventListener('touchmove', function(event) {
            if (!state.touch || !event.touches || event.touches.length !== 1) {
                clearTimer();
                return;
            }

            const touch = event.touches[0];
            const deltaX = Math.abs(touch.clientX - state.touch.startX);
            const deltaY = Math.abs(touch.clientY - state.touch.startY);
            if (deltaX > moveThreshold || deltaY > moveThreshold) {
                clearTimer();
            }
        }, { passive: true });

        container.addEventListener('touchend', function(event) {
            if (state.touch && state.touch.fired) {
                event.preventDefault();
            }
            clearTimer();
        }, { passive: false });

        container.addEventListener('touchcancel', clearTimer, { passive: true });
        document.addEventListener('click', suppressClickAfterLongPress, true);
    }

    function showActionNotice(success, message) {
        if (typeof showInlineNotification === 'function') {
            showInlineNotification(message, success ? 'success' : 'danger');
            return;
        }

        const existing = document.getElementById('messageActionNotice');
        if (existing) {
            existing.remove();
        }

        const notice = document.createElement('div');
        notice.id = 'messageActionNotice';
        notice.className = `alert alert-${success ? 'success' : 'danger'} shadow`;
        notice.textContent = message;
        notice.style.position = 'fixed';
        notice.style.top = '1rem';
        notice.style.right = '1rem';
        notice.style.zIndex = '2000';
        notice.style.maxWidth = '24rem';

        document.body.appendChild(notice);
        window.setTimeout(function() {
            notice.remove();
        }, 4000);
    }

    return {
        bindLongPress: bindLongPress,
        showActionNotice: showActionNotice
    };
})();
