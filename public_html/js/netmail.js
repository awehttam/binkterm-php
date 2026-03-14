let currentPage = 1;
let currentFilter = 'all';
let currentSort = 'date_desc';
let currentMessageId = null;
let currentMessageIndex = -1;
let currentMessages = [];
let modalClosedByBackButton = false;
let threadedView = false;
let userSettings = {};
let currentSearchTerms = [];
let selectMode = false;
let selectedMessages = new Set();
let keyboardHelpVisible = false;
let currentMessageData = null;
let currentParsedMessage = null;
let currentRenderMode = 'auto';

function apiError(payload, fallback) {
    if (window.getApiErrorMessage) {
        return window.getApiErrorMessage(payload, fallback);
    }
    if (payload && payload.error) {
        return String(payload.error);
    }
    return fallback;
}

function uiT(key, fallback, params = {}) {
    if (window.t) {
        return window.t(key, params, fallback);
    }
    return fallback;
}

$(document).ready(function() {
    loadNetmailSettings().then(function() {
        loadMessages();
    });
    loadStats();
    loadAddressBook();

    // Initialize history state
    if (!history.state) {
        history.replaceState({}, '', '');
    }

    // Handle browser back button for modal
    window.addEventListener('popstate', function(event) {
        if ($('#messageModal').hasClass('show')) {
            modalClosedByBackButton = true;
            $('#messageModal').modal('hide');
        }
    });

    // Add keyboard navigation for message modal
    $(document).on('keydown', function(e) {
        if ($('#messageModal').hasClass('show')) {
            switch(e.key) {
                case 'ArrowLeft':
                    e.preventDefault();
                    navigateMessage(-1);
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    navigateMessage(1);
                    break;
                case 'f':
                case 'F':
                    e.preventDefault();
                    toggleModalFullscreen();
                    break;
                case 'd':
                case 'D':
                    e.preventDefault();
                    downloadCurrentMessage();
                    break;
                case 'a':
                case 'A':
                    e.preventDefault();
                    cycleRenderMode();
                    break;
                case '?':
                case 'h':
                case 'H':
                    e.preventDefault();
                    toggleKeyboardHelp();
                    break;
            }
        }
    });

    // Handle modal close events
    $('#messageModal').on('hidden.bs.modal', function() {
        // If modal wasn't closed by back button and we're in a modal state, go back in history
        if (!modalClosedByBackButton && history.state && history.state.modal === 'message') {
            history.back();
        }
        modalClosedByBackButton = false;
        hideKeyboardHelp();
    });

    // Filter change handler
    $('input[name="filter"]').change(function() {
        currentFilter = $(this).val();
        currentPage = 1;
        loadMessages();
    });

    // Search on enter key
    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) {
            searchMessages();
        }
    });

    // Address book search
    $('#addressBookSearch').on('keyup', debounce(function() {
        loadAddressBook($(this).val());
    }, 300));

    // Auto refresh every 2 minutes
    startAutoRefresh(function() {
        loadMessages();
        loadStats();
    }, 120000);
});

function loadMessages() {
    showLoading('#messagesContainer');

    // Clear search terms when loading regular messages (not from search)
    currentSearchTerms = [];

    if (currentFilter === 'drafts') {
        // Load drafts instead of regular messages
        loadDrafts();
        return;
    }

    let url = `/api/messages/netmail?page=${currentPage}&sort=${currentSort}`;
    if (currentFilter !== 'all') {
        url += `&filter=${currentFilter}`;
    }
    if (threadedView) {
        url += '&threaded=true';
    }

    $.get(url)
        .done(function(data) {
            displayMessages(data.messages, data.threaded || false);
            updatePagination(data.pagination);
        })
        .fail(function() {
            $('#messagesContainer').html(`<div class="text-center text-danger py-4">${uiT('errors.failed_load_messages', 'Failed to load messages')}</div>`);
        });
}

function loadDrafts() {
    $.get('/api/messages/drafts?type=netmail')
        .done(function(data) {
            if (data.success) {
                displayDrafts(data.drafts);
                // Clear pagination for drafts
                $('#pagination').empty();
            } else {
                $('#messagesContainer').html(`<div class="text-center text-danger py-4">${uiT('errors.messages.drafts.list_failed', 'Failed to load drafts')}</div>`);
            }
        })
        .fail(function() {
            $('#messagesContainer').html(`<div class="text-center text-danger py-4">${uiT('errors.messages.drafts.list_failed', 'Failed to load drafts')}</div>`);
        });
}

function displayDrafts(drafts) {
    const container = $('#messagesContainer');
    let html = '';

    if (drafts.length === 0) {
        html = `<div class="text-center text-muted py-4">${uiT('ui.netmail.no_drafts_found', 'No drafts found')}</div>`;
    } else {
        // Create table structure
        html = `
            <div class="table-responsive">
                <table class="table table-hover message-table mb-0">
                    <thead>
                        <tr>
                            <th style="width: 30%">${uiT('ui.netmail.to', 'To')}</th>
                            <th style="width: 45%">${uiT('ui.common.subject_label_short', 'Subject')}</th>
                            <th colspan="2" style="width: 25%">${uiT('ui.netmail.last_updated', 'Last Updated')}</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        drafts.forEach(function(draft) {
            const displayTo = draft.to_name || uiT('ui.common.unknown', 'Unknown');
            const displayAddress = draft.to_address || '';

            html += `
                <tr class="message-row" style="cursor: pointer;" onclick="continueDraft(${draft.id})">
                    <td>
                        <div><strong>${escapeHtml(displayTo)}</strong></div>
                        ${displayAddress ? `<div class="text-muted small">${escapeHtml(displayAddress)}</div>` : ''}
                    </td>
                    <td>
                        <strong>${escapeHtml(draft.subject || uiT('messages.no_subject', '(No Subject)'))}</strong>
                        ${draft.message_text ? `<br><small class="text-muted">${escapeHtml(draft.message_text.substring(0, 100))}${draft.message_text.length > 100 ? '...' : ''}</small>` : ''}
                    </td>
                    <td>
                        <div>${formatFullDate(draft.updated_at)}</div>
                        <div class="text-muted small">
                            <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteDraftConfirm(${draft.id})" title="${uiT('ui.common.delete_draft', 'Delete draft')}">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });

        html += `
                    </tbody>
                </table>
            </div>
        `;
    }

    container.html(html);
}

function displayMessages(messages, isThreaded = false) {
    // Store messages for navigation
    currentMessages = messages;

    const container = $('#messagesContainer');
    let html = '';

    if (messages.length === 0) {
        html = `<div class="text-center text-muted py-4">${uiT('messages.none_found', 'No messages found')}</div>`;
    } else {
        html = `
            <table class="table table-hover message-table mb-0">
                <thead>
                    <tr>
                        <th style="width: 3%" id="selectAllColumn" class="d-none">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllMessages" onchange="toggleSelectAll()">
                            </div>
                        </th>
                        <th width="27%">${uiT('ui.netmail.from_to', 'From/To')}</th>
                        <th width="40%">${uiT('ui.common.subject_label_short', 'Subject')}</th>
                        <th width="15%">${uiT('ui.common.address', 'Address')}</th>
                        <th width="10%">${uiT('ui.netmail.received', 'Received')}</th>
                        <th width="5%"></th>
                    </tr>
                </thead>
                <tbody>
        `;

        messages.forEach(function(msg) {
            const isUnread = !msg.is_read;
            const currentUserId = window.currentUser ? window.currentUser.id : null;
            const isSent = (msg.user_id && currentUserId && msg.user_id == currentUserId);
            const rowClass = '';    //isUnread ? 'table-light' : '';

            // Threading support
            const threadLevel = msg.thread_level || 0;
            const replyCount = msg.reply_count || 0;
            const isThreadRoot = msg.is_thread_root || false;
            // Indent up to 2 levels (0.75rem each); deeper nesting shown via border-left color
            const indentRem = Math.min(threadLevel, 2) * 0.75;
            const threadIndent = threadLevel > 0 ? `style="padding-left: ${indentRem}rem;"` : '';
            const threadIcon = threadLevel > 0 ? `<i class="fas fa-reply me-1 text-muted" title="${uiT('ui.common.reply', 'Reply')}"></i>` : '';
            const replyCountBadge = isThreadRoot && replyCount > 0 ? ` <span class="badge bg-secondary ms-1" title="${uiT('ui.common.replies_with_count', '{count} replies', { count: replyCount })}">${replyCount}</span>` : '';
            const threadLevelClass = threadLevel > 0 ? ` thread-reply thread-level-${Math.min(threadLevel, 9)}` : '';

            html += `
                <tr class="${rowClass} message-row${threadLevelClass}" data-message-id="${msg.id}" onclick="viewMessage(${msg.id})" style="cursor: pointer;">
                    <td class="message-checkbox d-none" onclick="event.stopPropagation()">
                        <div class="form-check">
                            <input class="form-check-input message-select" type="checkbox" value="${msg.id}" onchange="updateSelection()">
                        </div>
                    </td>
                    <td ${threadIndent}>
                        ${isUnread ? `<i class="fas fa-envelope text-primary me-1" title="${uiT('ui.common.unread', 'Unread')}"></i>` : `<i class="far fa-envelope-open text-muted me-1" title="${uiT('ui.common.read', 'Read')}"></i>`}${msg.art_format === 'petscii' ? `<span class="badge me-1" style="background-color:#4040a0;color:#fff;font-size:0.6em;padding:1px 3px;vertical-align:middle;" title="PETSCII / C64 Art">C64</span>` : ''}${threadIcon}<strong>${escapeHtml(isSent ? `${uiT('ui.common.to_label', 'To:')} ` + msg.to_name : msg.from_name)}</strong>
                        <br>
                    </td>
                    <td>
                        ${isUnread ? '<strong>' : ''}<span>${escapeHtml(msg.subject || uiT('messages.no_subject', '(No Subject)'))}</span>${isUnread ? '</strong>' : ''}${replyCountBadge}
                        ${msg.has_attachment ? ` <i class="fas fa-paperclip text-muted" title="${uiT('ui.common.has_attachment', 'Has attachment')}"></i>` : ''}
                        <br>
                        <small class="text-muted">
                            <span class="badge bg-secondary">${uiT('ui.netmail.badge_netmail', 'NETMAIL')}</span>
                            ${isUnread ? `<span class="badge bg-primary ms-1">${uiT('ui.netmail.badge_new', 'NEW')}</span>` : ''}
                            ${msg.received_insecure ? `<span class="badge bg-warning text-dark ms-1" title="${uiT('ui.netmail.received_insecure_session_title', 'Received via insecure session')}"><i class="fas fa-exclamation-triangle"></i></span>` : ''}
                            ${msg.is_freq ? `<span class="badge bg-info ms-1" title="${uiT('ui.compose.freq.badge', 'File Request')}"><i class="fas fa-file-download"></i> ${uiT('ui.compose.freq.badge', 'FREQ')}</span>` : ''}
                        </small>
                    </td>
                    <td>
                        <small class="text-muted font-monospace">${formatFidonetAddress(isSent ? msg.to_address : msg.from_address)}</small>
                        ${(isSent ? msg.to_domain : msg.from_domain) ? `<br><span class="badge bg-secondary" style="font-size: 0.7em;">${isSent ? msg.to_domain : msg.from_domain}</span>` : ''}
                    </td>
                    <td title="${formatFullDate(msg.date_written)}">
                        <small>${formatDate(msg.date_received)}</small>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-outline-danger btn-sm" onclick="event.stopPropagation(); deleteMessage(${msg.id})" title="${uiT('ui.common.delete_message', 'Delete message')}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });

        html += `
                </tbody>
            </table>
        `;
    }

    container.html(html);
}

function updatePagination(pagination) {
    const container = $('#pagination');
    let html = '';

    if (pagination.pages > 1) {
        html = '<ul class="pagination pagination-sm mb-0">';

        // Previous button
        if (pagination.page > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${pagination.page - 1})">${uiT('ui.common.previous', 'Previous')}</a></li>`;
        }

        // Page numbers
        for (let i = 1; i <= pagination.pages; i++) {
            const active = i === pagination.page ? 'active' : '';
            html += `<li class="page-item ${active}"><a class="page-link" href="#" onclick="changePage(${i})">${i}</a></li>`;
        }

        // Next button
        if (pagination.page < pagination.pages) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${pagination.page + 1})">${uiT('ui.common.next', 'Next')}</a></li>`;
        }

        html += '</ul>';
    }

    container.html(html);
}

function changePage(page) {
    currentPage = page;
    loadMessages();
}

function toggleModalFullscreen() {
    const modal = document.getElementById('messageModal');
    const dialog = modal.querySelector('.modal-dialog');
    const icon = document.querySelector('#fullscreenToggle i');

    if (dialog.classList.contains('modal-fullscreen')) {
        dialog.classList.remove('modal-fullscreen');
        dialog.classList.add('modal-lg');
        icon.classList.remove('fa-compress');
        icon.classList.add('fa-expand');
        localStorage.setItem('modalFullscreen', 'false');
    } else {
        dialog.classList.remove('modal-lg');
        dialog.classList.add('modal-fullscreen');
        icon.classList.remove('fa-expand');
        icon.classList.add('fa-compress');
        localStorage.setItem('modalFullscreen', 'true');
    }
}

function applyModalFullscreenPreference() {
    const isFullscreen = localStorage.getItem('modalFullscreen') === 'true';
    const modal = document.getElementById('messageModal');
    const dialog = modal.querySelector('.modal-dialog');
    const icon = document.querySelector('#fullscreenToggle i');

    if (isFullscreen) {
        dialog.classList.remove('modal-lg');
        dialog.classList.add('modal-fullscreen');
        icon.classList.remove('fa-expand');
        icon.classList.add('fa-compress');
    }
}

function viewMessage(messageId) {
    currentMessageId = messageId;

    // Find and store current message index for navigation
    currentMessageIndex = currentMessages.findIndex(msg => msg.id === messageId);

    // Update navigation buttons
    updateNavigationButtons();

    // Mark as read immediately
    markMessageAsRead(messageId);

    // Add history entry for mobile back button support
    if (!history.state || history.state.modal !== 'message') {
        history.pushState({modal: 'message', messageId: messageId}, '', '');
    }

    $('#messageContent').html(`
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin me-2"></i>
            ${uiT('ui.common.loading_message', 'Loading message...')}
        </div>
    `);

    applyModalFullscreenPreference();
    $('#messageModal').modal('show');

    $.get(`/api/messages/netmail/${messageId}`)
        .done(function(data) {
            displayMessageContent(data);
        })
        .fail(function() {
            $('#messageContent').html(`<div class="text-danger">${uiT('errors.messages.netmail.get_failed', 'Failed to load message')}</div>`);
        });
}

function displayMessageContent(message) {
    // Check if current user is the sender - use user_id comparison instead of address
    const currentUserId = window.currentUser ? window.currentUser.id : null;
    const isSent = (message.user_id && currentUserId && message.user_id == currentUserId);

    $('#messageSubject').text(message.subject || uiT('messages.no_subject', '(No Subject)'));

    // Parse message to separate kludge lines from body (use stored kludge_lines if available)
    const parsedMessage = parseNetmailMessage(message.message_text || '', message.kludge_lines || null, message.bottom_kludges || null);
    currentMessageData = message;
    currentParsedMessage = parsedMessage;
    currentRenderMode = 'auto';

    // Check if sender is already in address book before rendering
    checkAndDisplayMessage(message, parsedMessage, isSent);
}

function getNextRenderMode(mode) {
    const modes = ['auto', 'ansi', 'amiga_ansi', 'petscii', 'plain'];
    const currentIndex = modes.indexOf(mode);
    return modes[(currentIndex + 1 + modes.length) % modes.length];
}

function showRenderModeToast() {
    const modalBody = document.querySelector('#messageModal .modal-body');
    if (!modalBody) {
        return;
    }

    const existing = document.getElementById('renderModeToast');
    if (existing) {
        existing.remove();
    }

    const modeLabel = window.getViewerModeToastLabel
        ? window.getViewerModeToastLabel(currentRenderMode, currentMessageData)
        : currentRenderMode;

    const toast = document.createElement('div');
    toast.id = 'renderModeToast';
    toast.className = 'badge bg-secondary';
    toast.style.position = 'absolute';
    toast.style.top = '0.75rem';
    toast.style.right = '0.75rem';
    toast.style.zIndex = '25';
    toast.textContent = `${uiT('ui.echomail.viewer_mode_prefix', 'Viewer mode:')} ${modeLabel}`;
    modalBody.appendChild(toast);

    window.setTimeout(() => {
        const currentToast = document.getElementById('renderModeToast');
        if (currentToast) {
            currentToast.remove();
        }
    }, 1200);
}

function updateRenderModeBadge() {
    const badge = document.getElementById('ansiRenderBadge');
    const badgeText = document.getElementById('ansiRenderBadgeText');
    if (!badge || !badgeText) {
        return;
    }

    if (currentRenderMode === 'auto') {
        badge.style.display = 'none';
        return;
    }

    const modeLabel = window.getViewerModeToastLabel
        ? window.getViewerModeToastLabel(currentRenderMode, currentMessageData)
        : currentRenderMode;
    const prefix = uiT('ui.echomail.viewer_mode_prefix', 'Viewer mode:');
    const suffix = uiT('ui.echomail.press_a_to_cycle', 'press A to cycle');
    badgeText.textContent = `${prefix} ${modeLabel} - ${suffix}`;
    badge.style.display = '';
}

function renderCurrentMessageBody() {
    if (!currentMessageData || !currentParsedMessage) {
        return;
    }

    const bodyHtml = currentMessageData.markup_html && currentRenderMode === 'auto'
        ? currentMessageData.markup_html
        : formatMessageBodyForDisplay(currentMessageData, currentParsedMessage.messageBody, currentSearchTerms, {
            forcePlain: currentRenderMode === 'plain',
            formatOverride: currentRenderMode === 'plain' ? null : currentRenderMode
        });

    const container = document.getElementById('messageBodyContainer');
    if (container) {
        container.innerHTML = bodyHtml;
    }
    updateRenderModeBadge();
}

function cycleRenderMode() {
    if (!$('#messageModal').hasClass('show')) {
        return;
    }

    currentRenderMode = getNextRenderMode(currentRenderMode);
    renderCurrentMessageBody();
}

function checkAndDisplayMessage(message, parsedMessage, isSent) {
    // Check if this contact already exists in address book
    const replyToAddress = message.replyto_address || message.reply_address || message.original_author_address || message.from_address;
    const replyToName = message.replyto_name || message.from_name;

    $.get('/api/address-book', { search: replyToName })
        .done(function(response) {
            let isInAddressBook = false;
            if (response.success && response.entries) {
                const existingEntry = response.entries.find(entry =>
                    entry.messaging_user_id && replyToName &&
                    entry.messaging_user_id.toLowerCase() === replyToName.toLowerCase() &&
                    entry.node_address === replyToAddress
                );
                isInAddressBook = !!existingEntry;
            }

            renderMessageContent(message, parsedMessage, isSent, isInAddressBook);
        })
        .fail(function() {
            // On error, assume not in address book
            renderMessageContent(message, parsedMessage, isSent, false);
        });
}

function renderMessageContent(message, parsedMessage, isSent, isInAddressBook) {
    let addressBookButton;
    if (isInAddressBook) {
        addressBookButton = `
            <button class="btn btn-sm btn-outline-secondary ms-2" id="saveAddressBookBtn" disabled title="${uiT('ui.common.already_in_address_book', 'Already in address book')}">
                <i class="fas fa-check"></i> <i class="fas fa-address-book"></i>
            </button>
        `;
    } else {
        const replyToAddress = message.replyto_address || message.reply_address || message.original_author_address || message.from_address;
        const replyToName = message.replyto_name || message.from_name;
        addressBookButton = `
            <button class="btn btn-sm btn-outline-success ms-2" id="saveAddressBookBtn" onclick="saveToAddressBook('${escapeHtml(replyToName)}', '${escapeHtml(replyToAddress)}', '${escapeHtml(message.from_name)}', '${escapeHtml(message.from_address)}')" title="${uiT('ui.common.save_to_address_book', 'Save to address book')}">
                <i class="fas fa-address-book"></i>
            </button>
        `;
    }

    const bodyHtml = message.markup_html
        ? message.markup_html
        : formatMessageBodyForDisplay(message, parsedMessage.messageBody);

    const html = `
        <div class="message-header-full mb-3">
            <div class="row">
                <div class="col-md-6">
                    <strong>${uiT('ui.common.from_label', 'From:')}</strong> ${escapeHtml(message.from_name)}
                    <small class="text-muted ms-2">${formatFidonetAddress(message.from_address)}</small>
                    ${addressBookButton}
                </div>
                <div class="col-md-6">
                    <strong>${uiT('ui.common.to_label', 'To:')}</strong> ${escapeHtml(message.to_name)}
                    <small class="text-muted ms-2">${formatFidonetAddress(message.to_address)}</small>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-6">
                    <strong>${uiT('ui.common.date_label', 'Date:')}</strong> <span title="${uiT('ui.common.sent_prefix', 'Sent:')} ${formatFullDate(message.date_written)}">${formatFullDate(message.date_received)}</span>
                </div>
                <div class="col-md-6">
                    <strong>${uiT('ui.common.subject_label', 'Subject:')}</strong> ${escapeHtml(message.subject || uiT('messages.no_subject', '(No Subject)'))}
                </div>
            </div>
            ${message.received_insecure ? `
            <div class="row mt-2">
                <div class="col-12">
                    <span class="badge bg-warning text-dark" title="${uiT('ui.netmail.received_insecure_badge_title', 'This message was received via an insecure/unauthenticated binkp session')}">
                        <i class="fas fa-exclamation-triangle"></i> ${uiT('ui.netmail.received_insecurely', 'Received Insecurely')}
                    </span>
                    <small class="text-muted ms-2">${uiT('ui.netmail.not_authenticated', 'This message was not authenticated')}</small>
                </div>
            </div>
            ` : ''}
            ${message.is_freq ? `
            <div class="row mt-2">
                <div class="col-12">
                    <span class="badge bg-info">
                        <i class="fas fa-file-download"></i> ${uiT('ui.compose.freq.badge', 'File Request (FREQ)')}
                    </span>
                    <small class="text-muted ms-2">${uiT('ui.compose.freq.help', 'This message is a file request')}</small>
                </div>
            </div>
            ` : ''}
        </div>

        ${parsedMessage.kludgeLines.length > 0 ? `
        <div class="message-headers mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0 text-muted">${uiT('ui.common.kludge_lines', 'Kludge Lines')}</h6>
                <button class="btn btn-sm btn-outline-secondary" id="toggleHeaders" onclick="toggleKludgeLines()">
                    <i class="fas fa-eye-slash" id="toggleIcon"></i>
                    <span id="toggleText">${uiT('ui.common.show_kludge_lines', 'Show Kludge Lines')}</span>
                </button>
            </div>
            <div id="kludgeContainer" class="kludge-lines" style="display: none;">
                <pre class="bg-dark text-light p-3 rounded small">${formatKludgeLinesWithSeparator(parsedMessage.topKludges || parsedMessage.kludgeLines, parsedMessage.bottomKludges || [])}</pre>
            </div>
        </div>
        ` : ''}

        <div class="message-text">
            <div id="ansiRenderBadge" style="display:none;" class="mb-2">
                <span class="badge bg-secondary" id="ansiRenderBadgeText"></span>
            </div>
            <div id="messageBodyContainer">
                ${bodyHtml}
            </div>
        </div>

        ${message.attachments && message.attachments.length > 0 ? `
        <div class="message-attachments mt-3">
            <h6 class="text-muted mb-2">
                <i class="fas fa-paperclip"></i>
                ${uiT('ui.common.file_attachments_with_count', 'File Attachments ({count})', { count: message.attachments.length })}
            </h6>
            <div class="list-group">
                ${message.attachments.map(file => `
                    <a href="/api/files/${file.id}/download" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" download="${escapeHtml(file.filename)}">
                        <div>
                            <i class="fas fa-file me-2"></i>
                            <strong>${escapeHtml(file.filename)}</strong>
                            <br>
                            <small class="text-muted">
                                ${formatFileSize(file.filesize)}
                                ${file.short_description ? ' - ' + escapeHtml(file.short_description) : ''}
                            </small>
                        </div>
                        <i class="fas fa-download"></i>
                    </a>
                `).join('')}
            </div>
        </div>
        ` : ''}
    `;

    $('#messageContent').html(html);

    // Set up reply button
    if (!isSent) {
        $('#replyButton').show().off('click').on('click', function() {
            // Store the message ID for the reply
            const messageId = currentMessageId;

            // Hide modal and wait for it to be fully closed before navigating
            $('#messageModal').one('hidden.bs.modal', function() {
                // Small delay to ensure other modal handlers complete first
                setTimeout(function() {
                    composeMessage('netmail', messageId);
                }, 10);
            });
            $('#messageModal').modal('hide');
        });
    } else {
        $('#replyButton').hide();
    }

    // Set up delete button
    $('#deleteButton').show().off('click').on('click', function() {
        deleteMessage(currentMessageId);
    });

    // Edit button is always shown — getMessage already enforces sender/receiver access
}

function openEditMessage() {
    if (!currentMessageData) return;
    const msg = currentMessageData;

    $('#editMessageModalTitle').html(`<i class="fas fa-pencil-alt me-2"></i>${uiT('ui.echomail.edit_message', 'Edit Message')} #${currentMessageId}`);
    $('#editMsgDbId').text(currentMessageId);
    $('#editMsgId').text(msg.message_id || '');
    $('#editMsgDate').text(formatFullDate(msg.date_written));
    $('#editMsgFrom').text((msg.from_name || '') + (msg.from_address ? ' <' + msg.from_address + '>' : ''));
    $('#editMsgSubject').text(msg.subject || '');
    $('#editArtFormat').val(msg.art_format || '');
    $('#editCharset').val(msg.message_charset || '');
    $('#editMessageError').addClass('d-none');
    $('#editMessageSuccess').addClass('d-none');
    $('#saveEditMessageBtn').prop('disabled', false);

    $('#editMessageModal').modal('show');
}

function saveEditMessage() {
    if (!currentMessageData) return;

    const artFormat = $('#editArtFormat').val();
    const charset   = $('#editCharset').val().trim();

    $('#editMessageError').addClass('d-none');
    $('#editMessageSuccess').addClass('d-none');
    $('#saveEditMessageBtn').prop('disabled', true);

    $.ajax({
        url: `/api/messages/netmail/${currentMessageId}/edit`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ art_format: artFormat, message_charset: charset }),
    }).done(function() {
        currentMessageData.art_format      = artFormat || null;
        currentMessageData.message_charset = charset || null;
        const listMsg = currentMessages.find(m => m.id == currentMessageId);
        if (listMsg) {
            listMsg.art_format = artFormat || null;
        }
        $('#editMessageSuccess').removeClass('d-none');
        $('#saveEditMessageBtn').prop('disabled', false);
    }).fail(function(xhr) {
        const payload = xhr.responseJSON || {};
        $('#editMessageError').text(window.getApiErrorMessage ? window.getApiErrorMessage(payload, uiT('errors.messages.echomail.edit.save_failed', 'Failed to save changes')) : (payload.error || uiT('errors.messages.echomail.edit.save_failed', 'Failed to save changes'))).removeClass('d-none');
        $('#saveEditMessageBtn').prop('disabled', false);
    });
}

function composeMessage(type, replyToId = null) {
    window.location.href = `/compose/netmail${replyToId ? '?reply=' + replyToId : ''}`;
}

function composeMessageToUser(toName, toAddress, subject, alwaysCrashmail) {
    // Build URL with parameters for composing to a specific user
    const params = new URLSearchParams();
    params.set('to_name', toName);
    params.set('to', toAddress);
    if (subject && subject.trim()) {
        // If subject doesn't start with "Re:", add it
        const replySubject = subject && subject.toLowerCase().startsWith('re:') ? subject : 'Re: ' + subject;
        params.set('subject', replySubject);
    }
    if (alwaysCrashmail) {
        params.set('crashmail', '1');
    }

    window.location.href = `/compose/netmail?${params.toString()}`;
}

function searchMessages() {
    const query = $('#searchInput').val().trim();
    if (query.length < 2) {
        showError(uiT('errors.messages.search.query_too_short', 'Please enter at least 2 characters to search'));
        return;
    }

    showLoading('#messagesContainer');

    // Store search terms for highlighting
    currentSearchTerms = query.toLowerCase().split(/\s+/).filter(term => term.length > 1);

    $.get(`/api/messages/search?q=${encodeURIComponent(query)}&type=netmail`)
        .done(function(data) {
            displayMessages(data.messages);
            $('#pagination').empty();
        })
        .fail(function() {
            $('#messagesContainer').html('<div class="p-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + uiT('ui.netmail.search.failed', 'Search failed') + '</div>');
            $('#pagination').empty();
        });
}

function openAdvancedSearch() {
    $('#advSearchFromName').val('');
    $('#advSearchSubject').val('');
    $('#advSearchBody').val('');
    $('#advSearchDateFrom').val('');
    $('#advSearchDateTo').val('');
    $('#advSearchError').addClass('d-none').text('');
    $('#advancedSearchModal').modal('show');
}

function runAdvancedSearch() {
    const fromName = $('#advSearchFromName').val().trim();
    const subject = $('#advSearchSubject').val().trim();
    const body = $('#advSearchBody').val().trim();
    const dateFrom = $('#advSearchDateFrom').val();
    const dateTo = $('#advSearchDateTo').val();

    const textFields = [fromName, subject, body].filter(v => v.length > 0);
    const hasDate = dateFrom || dateTo;

    // Validate: at least one field filled, and text fields must be 2+ chars each
    if (textFields.length === 0 && !hasDate) {
        $('#advSearchError')
            .removeClass('d-none')
            .text(window.t('ui.common.advanced_search.fill_one_field', {}, 'Please fill in at least one field (minimum 2 characters for text fields).'));
        return;
    }
    if (textFields.some(v => v.length < 2)) {
        $('#advSearchError')
            .removeClass('d-none')
            .text(window.t('ui.common.advanced_search.fill_one_field', {}, 'Please fill in at least one field (minimum 2 characters for text fields).'));
        return;
    }

    $('#advSearchError').addClass('d-none');
    $('#advancedSearchModal').modal('hide');
    showLoading('#messagesContainer');

    // Collect text search terms for highlighting
    currentSearchTerms = [fromName, subject, body]
        .filter(v => v.length > 0)
        .join(' ')
        .toLowerCase()
        .split(/\s+/)
        .filter(term => term.length > 1);

    const params = new URLSearchParams({ type: 'netmail' });
    if (fromName) params.set('from_name', fromName);
    if (subject) params.set('subject', subject);
    if (body) params.set('body', body);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);

    $.get('/api/messages/search?' + params.toString())
        .done(function(data) {
            displayMessages(data.messages);
            $('#pagination').empty();
        })
        .fail(function() {
            $('#messagesContainer').html('<div class="p-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + uiT('ui.netmail.search.failed', 'Search failed') + '</div>');
            $('#pagination').empty();
        });
}

function loadStats() {
    console.log('Loading netmail statistics...');
    $.get('/api/messages/netmail/stats')
        .done(function(data) {
            console.log('Netmail stats response:', data);
            $('#totalCount').text(data.total || 0);
            $('#unreadCount').text(data.unread || 0);
            $('#sentCount').text(data.sent || 0);
        })
        .fail(function(xhr, status, error) {
            console.error('Netmail stats loading failed:', xhr.status, status, error);
            console.error('Response text:', xhr.responseText);
            $('#totalCount').text(uiT('ui.common.error', 'Error'));
            $('#unreadCount').text(uiT('ui.common.error', 'Error'));
            $('#sentCount').text(uiT('ui.common.error', 'Error'));
        });
}

function deleteMessage(messageId) {
    if (!confirm(uiT('ui.netmail.delete_message_confirm', 'Are you sure you want to delete this message? This action cannot be undone.'))) {
        return;
    }

    $.ajax({
        url: `/api/messages/netmail/${messageId}`,
        method: 'DELETE',
        success: function(data) {
            $('#messageModal').modal('hide');
            const successMessage = window.getApiMessage
                ? window.getApiMessage(data, uiT('ui.netmail.message_deleted_success', 'Message deleted successfully'))
                : uiT('ui.netmail.message_deleted_success', 'Message deleted successfully');
            showSuccess(successMessage);
            loadMessages();
            loadStats();
        },
        error: function(xhr) {
            const errorMsg = apiError(xhr.responseJSON, uiT('errors.messages.netmail.delete_failed', 'Failed to delete message'));
            showError(errorMsg);
        }
    });
}

// Mark message as read when viewed
function markMessageAsRead(messageId) {
    $.post(`/api/messages/netmail/${messageId}/read`)
        .done(function() {
            // Update the UI to show message as read
            const messageRow = $(`.message-row[data-message-id="${messageId}"]`);
            if (messageRow.length) {
                messageRow.removeClass('table-light');
                // Change envelope icon from closed to open
                messageRow.find('.fa-envelope').removeClass('fas fa-envelope text-primary').addClass('far fa-envelope-open text-muted');
                // Remove bold formatting from subject
                messageRow.find('strong').contents().unwrap();
                // Update title attribute
                messageRow.find('.fa-envelope-open').attr('title', 'Read');
                // Reduce opacity slightly to show as read
                messageRow.css('opacity', '0.85');
            }
        })
        .fail(function() {
            console.log('Failed to mark netmail as read');
        });
}

function sortMessages(sortBy) {
    currentSort = sortBy;
    currentPage = 1;

    // Save sort preference
    window.saveUserSetting('default_sort', sortBy);

    loadMessages();
}

function toggleThreading() {
    threadedView = !threadedView;
    currentPage = 1; // Reset to first page when toggling

    // Update toggle text
    const toggleText = $('#threadingToggleText');
    if (threadedView) {
        toggleText.text(uiT('ui.common.threading.show_flat', 'Show Flat'));
    } else {
        toggleText.text(uiT('ui.common.threading.show_threaded', 'Show Threaded'));
    }

    // Save preference (netmail uses separate setting from echomail)
    window.saveUserSetting('netmail_threaded_view', threadedView);

    loadMessages();
}

// User settings functions - apply netmail-specific settings after loading
function loadNetmailSettings() {
    if (typeof window.loadUserSettings === 'function') {
        return window.loadUserSettings().then(function() {
            // Apply netmail-specific settings
            userSettings = window.userSettings;

            if (userSettings.netmail_threaded_view !== undefined) {
                threadedView = userSettings.netmail_threaded_view;
                const toggleText = $('#threadingToggleText');
                if (threadedView) {
                    toggleText.text(uiT('ui.common.threading.show_flat', 'Show Flat'));
                } else {
                    toggleText.text(uiT('ui.common.threading.show_threaded', 'Show Threaded'));
                }
            }

            if (userSettings.default_sort) {
                currentSort = userSettings.default_sort;
            }
        });
    } else {
        // Fallback if global function not available
        return Promise.resolve();
    }
}

// Use global settings functions directly - no local wrappers needed
// All calls to saveUserSetting and saveUserSettings will use window.* functions

// Address Book Functions
function loadAddressBook(search = '') {
    $.get('/api/address-book', { search: search })
        .done(function(response) {
            if (response.success) {
                renderAddressBook(response.entries);
                $('#addressBookStats').text(`${response.entries.length} ${uiT('ui.address_book.entries', 'entries')}`);
            } else {
                $('#addressBookList').html(`<div class="text-danger py-2">${uiT('ui.address_book.load_failed', 'Failed to load address book')}</div>`);
            }
        })
        .fail(function() {
            $('#addressBookList').html(`<div class="text-danger py-2">${uiT('ui.address_book.load_failed', 'Failed to load address book')}</div>`);
        });
}

function renderAddressBook(entries) {
    const container = $('#addressBookList');
    let html = '';

    if (entries.length === 0) {
        html = `<div class="text-center text-muted py-2">${uiT('ui.address_book.no_entries_found', 'No entries found')}</div>`;
    } else {
        entries.forEach(function(entry) {
            html += `
                <div class="d-flex justify-content-between align-items-start mb-2 p-2 border rounded address-book-entry"
                     style="cursor: pointer;" onclick="composeToAddressBookEntry('${escapeHtml(entry.messaging_user_id || '')}', '${escapeHtml(entry.node_address || '')}', ${entry.always_crashmail ? 'true' : 'false'})">
                    <div class="flex-grow-1">
                        <div class="fw-bold small">${escapeHtml(entry.name || uiT('ui.address_book.unnamed', 'Unnamed'))}</div>
                        <div class="text-primary small">@${escapeHtml(entry.messaging_user_id || uiT('ui.common.unknown', 'Unknown'))}</div>
                        <div class="text-muted small font-monospace">${escapeHtml(entry.node_address || uiT('ui.address_book.no_address', 'No address'))}</div>
                        ${entry.description ? `<div class="text-muted smaller">${escapeHtml(entry.description.substring(0, 30) + (entry.description.length > 30 ? '...' : ''))}</div>` : ''}
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" onclick="event.stopPropagation();">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" onclick="event.stopPropagation(); editAddressBookEntry(${entry.id});">
                                <i class="fas fa-edit"></i> ${uiT('ui.common.edit', 'Edit')}
                            </a></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="event.stopPropagation(); deleteAddressBookEntry(${entry.id}, '${escapeHtml(entry.name || uiT('ui.address_book.unnamed', 'Unnamed'))}');">
                                <i class="fas fa-trash"></i> ${uiT('ui.common.delete', 'Delete')}
                            </a></li>
                        </ul>
                    </div>
                </div>
            `;
        });
    }

    container.html(html);
}

function showAddAddressModal() {
    $('#addressBookModalTitle').text(uiT('ui.address_book.add_entry', 'Add Address Book Entry'));
    $('#addressBookEntryId').val('');
    $('#addressBookForm')[0].reset();
    clearAddressBookModalError();
    $('#addressBookModal').modal('show');
}

function showAddressBookModalError(message) {
    const fallback = uiT('errors.address_book.create_failed', 'Failed to save entry');
    const text = (typeof message === 'string' && message.trim() !== '') ? message : fallback;
    const errorEl = $('#addressBookModalError');
    if (errorEl.length > 0) {
        errorEl.text(text).removeClass('d-none');
    }
    // Keep global alert as secondary visibility outside modal context.
    showError(text);
}

function clearAddressBookModalError() {
    const errorEl = $('#addressBookModalError');
    if (errorEl.length > 0) {
        errorEl.addClass('d-none').text('');
    }
}

function editAddressBookEntry(entryId) {
    clearAddressBookModalError();
    $.get(`/api/address-book/${entryId}`)
        .done(function(response) {
            if (response.success) {
                const entry = response.entry;
                $('#addressBookModalTitle').text(uiT('ui.address_book.edit_entry', 'Edit Address Book Entry'));
                $('#addressBookEntryId').val(entry.id);
                $('#addressBookName').val(entry.name);
                $('#addressBookUserId').val(entry.messaging_user_id);
                $('#addressBookNodeAddress').val(entry.node_address);
                $('#addressBookEmail').val(entry.email || '');
                $('#addressBookDescription').val(entry.description || '');
                $('#addressBookAlwaysCrashmail').prop('checked', !!entry.always_crashmail);
                $('#addressBookModal').modal('show');
            } else {
                showError(uiT('ui.netmail.address_book.load_entry_failed_prefix', 'Failed to load entry: ') + apiError(response, uiT('ui.common.unknown_error', 'Unknown error')));
            }
        })
        .fail(function() {
            showError(uiT('errors.address_book.get_failed', 'Failed to load entry'));
        });
}

function saveAddressBookEntry() {
    clearAddressBookModalError();
    const entryId = $('#addressBookEntryId').val();
    const data = {
        name: $('#addressBookName').val().trim(),
        messaging_user_id: $('#addressBookUserId').val().trim(),
        node_address: $('#addressBookNodeAddress').val().trim(),
        email: $('#addressBookEmail').val().trim(),
        description: $('#addressBookDescription').val().trim(),
        always_crashmail: $('#addressBookAlwaysCrashmail').is(':checked'),
    };

    // Basic validation
    if (!data.name || !data.messaging_user_id || !data.node_address) {
        showAddressBookModalError(uiT('errors.address_book.required_fields', 'Name, user ID, and node address are required'));
        return;
    }

    const url = entryId ? `/api/address-book/${entryId}` : '/api/address-book/';
    const method = entryId ? 'PUT' : 'POST';

    $.ajax({
        url: url,
        method: method,
        contentType: 'application/json',
        data: JSON.stringify(data),
        success: function(response) {
            if (response.success) {
                $('#addressBookModal').modal('hide');
                loadAddressBook();
                const defaultMessage = entryId
                    ? uiT('ui.address_book.entry_updated', 'Entry updated successfully')
                    : uiT('ui.compose.address_book.entry_added', 'Entry added successfully');
                const successMessage = window.getApiMessage
                    ? window.getApiMessage(response, defaultMessage)
                    : defaultMessage;
                showSuccess(successMessage);
            } else {
                showAddressBookModalError(apiError(response, uiT('errors.address_book.create_failed', 'Failed to save entry')));
            }
        },
        error: function(xhr) {
            const payload = (xhr && xhr.responseJSON) ? xhr.responseJSON : null;
            showAddressBookModalError(apiError(payload, uiT('errors.address_book.create_failed', 'Failed to save entry')));
        }
    });
}

function deleteAddressBookEntry(entryId, entryName) {
    if (!confirm(uiT('ui.address_book.delete_confirm', `Are you sure you want to delete "${entryName}" from your address book?`, { name: entryName }))) {
        return;
    }

    $.ajax({
        url: `/api/address-book/${entryId}`,
        method: 'DELETE',
        success: function(response) {
            if (response.success) {
                loadAddressBook();
                const successMessage = window.getApiMessage
                    ? window.getApiMessage(response, uiT('ui.address_book.entry_deleted', 'Entry deleted successfully'))
                    : uiT('ui.address_book.entry_deleted', 'Entry deleted successfully');
                showSuccess(successMessage);
            } else {
                showError(apiError(response, uiT('errors.address_book.delete_failed', 'Failed to delete entry')));
            }
        },
        error: function() {
            showError(uiT('errors.address_book.delete_failed', 'Failed to delete entry'));
        }
    });
}

function composeToAddressBookEntry(messagingUserId, nodeAddress, alwaysCrashmail) {
    composeMessageToUser(messagingUserId, nodeAddress, '', alwaysCrashmail);
}

// Save sender to address book from message modal
function saveToAddressBook(fromName, fromAddress, originalFromName, originalFromAddress) {
    const button = $('#saveAddressBookBtn');
    const originalHtml = button.html();
    const originalTitle = button.attr('title');

    // Show loading state
    button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>').attr('title', 'Saving...');

    // First check if this contact already exists
    $.get('/api/address-book', { search: fromName })
        .done(function(response) {
            if (response.success) {
                // Check if contact already exists
                const existingEntry = response.entries.find(entry =>
                    entry.messaging_user_id && fromName &&
                    entry.messaging_user_id.toLowerCase() === fromName.toLowerCase() &&
                    entry.node_address === fromAddress
                );

                if (existingEntry) {
                    // Already exists - show "already saved" state
                    button.removeClass('btn-outline-success').addClass('btn-outline-secondary')
                          .html('<i class="fas fa-check"></i> <i class="fas fa-address-book"></i>')
                          .attr('title', uiT('ui.common.already_in_address_book', 'Already in address book'))
                          .prop('disabled', true);
                    showError(uiT('ui.address_book.already_exists', 'This contact is already in your address book'));
                    return;
                }

                // Contact doesn't exist, create new entry
                // Build description with reference information
                let description = uiT('ui.address_book.added_from_netmail', 'Added from netmail message');
                if (originalFromName && originalFromAddress) {
                    if (fromName !== originalFromName || fromAddress !== originalFromAddress) {
                        // REPLYTO was used - show both original and reply-to info
                        description = uiT(
                            'ui.address_book.added_from_netmail_replyto_detail',
                            'Added from netmail message. Original sender: {original_name} ({original_address}), Reply-to: {replyto_name} ({replyto_address})',
                            {
                                original_name: originalFromName,
                                original_address: originalFromAddress,
                                replyto_name: fromName,
                                replyto_address: fromAddress
                            }
                        );
                    } else {
                        // No REPLYTO - just show sender info
                        description = uiT(
                            'ui.address_book.added_from_netmail_sender_detail',
                            'Added from netmail message. Sender: {sender_name} ({sender_address})',
                            {
                                sender_name: originalFromName,
                                sender_address: originalFromAddress
                            }
                        );
                    }
                }

                const data = {
                    name: originalFromName || fromName, // Use original name for description
                    messaging_user_id: fromName, // Use the reply-to name for messaging
                    node_address: fromAddress,
                    description: description
                };

                $.ajax({
                    url: '/api/address-book/',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(data),
                    success: function(response) {
                        if (response.success) {
                            // Show success state
                            button.removeClass('btn-outline-success').addClass('btn-success')
                                  .html(`<i class="fas fa-check"></i> ${uiT('ui.common.saved_short', 'Saved')}`)
                                  .attr('title', uiT('ui.address_book.saved_to_address_book', 'Saved to address book'))
                                  .prop('disabled', true);

                            showSuccess(uiT('ui.address_book.sender_added', `${fromName} added to address book`, { name: fromName }));

                            // Refresh address book in sidebar if it exists
                            if (typeof loadAddressBook === 'function') {
                                loadAddressBook();
                            }
                        } else {
                            // Reset button on error
                            button.removeClass('btn-outline-success').addClass('btn-outline-danger')
                                  .html(originalHtml)
                                  .attr('title', uiT('ui.common.error_click_retry', 'Error - click to retry'))
                                  .prop('disabled', false);
                            showError(apiError(response, uiT('errors.address_book.create_failed', 'Failed to save to address book')));
                        }
                    },
                    error: function(xhr) {
                        // Reset button on error
                        button.removeClass('btn-outline-success').addClass('btn-outline-danger')
                              .html(originalHtml)
                              .attr('title', uiT('ui.common.error_click_retry', 'Error - click to retry'))
                              .prop('disabled', false);
                        showError(apiError(xhr.responseJSON, uiT('errors.address_book.create_failed', 'Failed to save to address book')));
                    }
                });
            } else {
                // Reset button on error
                button.html(originalHtml).attr('title', originalTitle).prop('disabled', false);
                showError(uiT('ui.address_book.check_existing_failed', 'Failed to check existing contacts'));
            }
        })
        .fail(function() {
            // Reset button on error
            button.html(originalHtml).attr('title', originalTitle).prop('disabled', false);
            showError(uiT('ui.address_book.check_existing_failed', 'Failed to check existing contacts'));
        });
}

// A typical debounce implementation
function debounce(func, delay) {
    let timeout;
    return function(...args) {
        const context = this; // Capture the correct 'this'
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            func.apply(context, args); // Apply the captured context
        }, delay);
    };
}

// Draft management functions
function continueDraft(draftId) {
    // Navigate to compose page with draft data
    window.location.href = `/compose/netmail?draft=${draftId}`;
}

function deleteDraftConfirm(draftId) {
    if (confirm(uiT('ui.drafts.delete_confirm', 'Are you sure you want to delete this draft? This cannot be undone.'))) {
        deleteDraft(draftId);
    }
}

function deleteDraft(draftId) {
    $.ajax({
        url: `/api/messages/drafts/${draftId}`,
        method: 'DELETE',
        success: function(response) {
            if (response.success) {
                // Reload drafts to show updated list
                loadDrafts();
                const successMessage = window.getApiMessage
                    ? window.getApiMessage(response, uiT('ui.drafts.deleted_success', 'Draft deleted successfully'))
                    : uiT('ui.drafts.deleted_success', 'Draft deleted successfully');
                showSuccess(successMessage);
            } else {
                showError(uiT('errors.messages.drafts.delete_failed', 'Failed to delete draft'));
            }
        },
        error: function() {
            showError(uiT('errors.messages.drafts.delete_failed', 'Failed to delete draft'));
        }
    });
}

/**
 * Format file size in human-readable format
 * @param {number} bytes File size in bytes
 * @returns {string} Formatted file size (e.g., "1.5 MB")
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    if (!bytes) return uiT('ui.common.unknown_size', 'Unknown size');

    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

function navigateMessage(direction) {
    if (currentMessages.length === 0) return;

    const newIndex = currentMessageIndex + direction;

    // Check bounds
    if (newIndex < 0 || newIndex >= currentMessages.length) {
        return;
    }

    // Get the new message
    const newMessage = currentMessages[newIndex];
    if (!newMessage) return;

    // Update current message info
    currentMessageId = newMessage.id;
    currentMessageIndex = newIndex;

    // Update navigation buttons
    updateNavigationButtons();

    // Mark as read immediately
    markMessageAsRead(newMessage.id);

    // Show loading
    $('#messageContent').html(`
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin me-2"></i>
            ${uiT('ui.common.loading_message', 'Loading message...')}
        </div>
    `);

    // Load the new message
    $.get(`/api/messages/netmail/${newMessage.id}`)
        .done(function(data) {
            displayMessageContent(data);
            // Auto-scroll to top of modal content
            $('#messageModal .modal-body').scrollTop(0);
        })
        .fail(function() {
            $('#messageContent').html(`<div class="text-danger">${uiT('errors.messages.netmail.get_failed', 'Failed to load message')}</div>`);
        });
}

function updateNavigationButtons() {
    const prevBtn = $('#prevMessageBtn');
    const nextBtn = $('#nextMessageBtn');

    // Disable/enable previous button
    if (currentMessageIndex <= 0) {
        prevBtn.prop('disabled', true);
    } else {
        prevBtn.prop('disabled', false);
    }

    // Disable/enable next button
    if (currentMessageIndex >= currentMessages.length - 1) {
        nextBtn.prop('disabled', true);
    } else {
        nextBtn.prop('disabled', false);
    }
}

function toggleSelectMode() {
    selectMode = !selectMode;
    const btn = $('#selectModeBtn');
    const checkboxColumn = $('#selectAllColumn');
    const checkboxCells = $('.message-checkbox');
    const bulkActions = $('#bulkActions');

    if (selectMode) {
        // Enable select mode
        btn.html(`<i class="fas fa-times"></i> ${uiT('ui.common.cancel', 'Cancel')}`);
        btn.removeClass('btn-outline-secondary').addClass('btn-outline-warning');
        checkboxColumn.removeClass('d-none');
        checkboxCells.removeClass('d-none');
        bulkActions.removeClass('d-none');
    } else {
        // Disable select mode
        btn.html(`<i class="fas fa-check-square"></i> ${uiT('ui.common.select', 'Select')}`);
        btn.removeClass('btn-outline-warning').addClass('btn-outline-secondary');
        checkboxColumn.addClass('d-none');
        checkboxCells.addClass('d-none');
        bulkActions.addClass('d-none');
        clearSelection();
    }
}

function toggleSelectAll() {
    const selectAllCheckbox = $('#selectAllMessages');
    const messageCheckboxes = $('.message-select');

    if (selectAllCheckbox.prop('checked')) {
        messageCheckboxes.prop('checked', true);
        messageCheckboxes.each(function() {
            selectedMessages.add(parseInt($(this).val()));
        });
    } else {
        messageCheckboxes.prop('checked', false);
        selectedMessages.clear();
    }

    updateSelectionDisplay();
}

function updateSelection() {
    const messageCheckboxes = $('.message-select');
    const checkedBoxes = $('.message-select:checked');

    // Update selected messages set
    selectedMessages.clear();
    checkedBoxes.each(function() {
        selectedMessages.add(parseInt($(this).val()));
    });

    // Update select all checkbox
    const selectAllCheckbox = $('#selectAllMessages');
    if (checkedBoxes.length === 0) {
        selectAllCheckbox.prop('indeterminate', false);
        selectAllCheckbox.prop('checked', false);
    } else if (checkedBoxes.length === messageCheckboxes.length) {
        selectAllCheckbox.prop('indeterminate', false);
        selectAllCheckbox.prop('checked', true);
    } else {
        selectAllCheckbox.prop('indeterminate', true);
        selectAllCheckbox.prop('checked', false);
    }

    updateSelectionDisplay();
}

function updateSelectionDisplay() {
    const count = selectedMessages.size;
    $('#selectedCount').text(count);

    if (count === 0) {
        $('#bulkActions .btn-outline-danger').prop('disabled', true);
    } else {
        $('#bulkActions .btn-outline-danger').prop('disabled', false);
    }
}

function clearSelection() {
    selectedMessages.clear();
    $('.message-select').prop('checked', false);
    $('#selectAllMessages').prop('checked', false).prop('indeterminate', false);
    updateSelectionDisplay();

    if (selectMode) {
        toggleSelectMode();
    }
}

function deleteSelectedMessages() {
    if (selectedMessages.size === 0) {
        showError(uiT('ui.messages.none_selected', 'No messages selected'));
        return;
    }

    if (!confirm(uiT('ui.netmail.bulk_delete.confirm', `Are you sure you want to delete ${selectedMessages.size} message(s)?`, { count: selectedMessages.size }))) {
        return;
    }

    const messageIds = Array.from(selectedMessages);

    $.ajax({
        url: '/api/messages/netmail/bulk-delete',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ message_ids: messageIds }),
        success: function(data) {
            const deletedCount = data.deleted || messageIds.length;
            const successMessage = window.getApiMessage
                ? window.getApiMessage(data, uiT('ui.netmail.bulk_delete.success', `Deleted ${deletedCount} message(s)`, { count: deletedCount }))
                : uiT('ui.netmail.bulk_delete.success', `Deleted ${deletedCount} message(s)`, { count: deletedCount });
            showSuccess(successMessage);
            clearSelection();
            loadMessages();
        },
        error: function(xhr) {
            const error = apiError(xhr.responseJSON, uiT('ui.netmail.bulk_delete.failed', 'Failed to delete messages'));
            showError(error);
        }
    });
}

function downloadCurrentMessage() {
    if (!currentMessageId) {
        return;
    }
    window.location.href = `/api/messages/netmail/${encodeURIComponent(currentMessageId)}/download`;
}

function toggleKeyboardHelp() {
    keyboardHelpVisible = !keyboardHelpVisible;
    $('#keyboardHelpOverlay').toggle(keyboardHelpVisible);
}

function hideKeyboardHelp() {
    keyboardHelpVisible = false;
    $('#keyboardHelpOverlay').hide();
}
