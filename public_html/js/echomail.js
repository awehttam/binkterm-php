let currentPage = 1;
let currentSort = 'date_desc';
// Per-area page memory, keyed by area tag. Loaded from and saved to DB via web-mail-state API.
let echoPageMemory = {};
let currentMessageId = null;
let currentFilter = 'all';
let modalClosedByBackButton = false;
let threadedView = false;
let userSettings = {};
let currentMessages = [];
let currentMessageIndex = -1;
let currentSearchTerms = [];
let currentMessageData = null;
let currentParsedMessage = null;
let currentRenderMode = 'auto';
let keyboardHelpVisible = false;
let allEchoareas = [];
let echoareaSearchQuery = '';
let currentEchoareaData = null;  // area object for the currently viewed echo
let allEchoareasCache = null;    // lazy full-list cache for unsubscribed area lookups
let searchResultCounts = null;
let searchFilterCounts = null;
let originalFilterCounts = null;
let isSearchActive = false;
let currentPagination = null;
let _messageRiptermLoaderPromise = null;
let requestedMessageId = null;

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

// Date display configuration: 'written' or 'received'
// Sourced from server-side ECHOMAIL_ORDER_DATE env configuration.
const USE_DATE_FIELD = (window.echomailDateField === 'written') ? 'written' : 'received';

$(document).ready(function() {
    loadEchomailSettings().then(function() {
        const urlParams = new URLSearchParams(window.location.search);
        const searchQuery = urlParams.get('search');
        const messageParam = urlParams.get('message');
        requestedMessageId = messageParam && /^\d+$/.test(messageParam) ? parseInt(messageParam, 10) : null;

        loadEchoareas();

        if (searchQuery) {
            // Populate search input and trigger search
            $('#searchInput').val(searchQuery);
            $('#mobileSearchInput').val(searchQuery);
            searchMessages();
        } else {
            // Restore last visited page for the current area
            const memKey = currentEchoarea || '__all__';
            if (echoPageMemory[memKey]) {
                currentPage = echoPageMemory[memKey];
            }
            loadMessages(function() {
                openRequestedMessage();
            });
        }
    });
    loadStats();

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

    // Handle modal close events
    $('#messageModal').on('hidden.bs.modal', function() {
        // If modal wasn't closed by back button and we're in a modal state, go back in history
        if (!modalClosedByBackButton && history.state && history.state.modal === 'message') {
            history.back();
        }
        modalClosedByBackButton = false;
        hideKeyboardHelp();
    });

    // Add keyboard navigation for message modal
    $(document).on('keydown', function(e) {
        // Only handle keyboard shortcuts when the message modal is open
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
                case 'd':
                case 'D':
                    e.preventDefault();
                    downloadCurrentMessage();
                    break;
                case 'Escape':
                    // Let the default modal behavior handle this
                    break;
                case 'f':
                case 'F':
                    e.preventDefault();
                    toggleModalFullscreen();
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

    // Add touch/swipe navigation for message modal
    setupModalSwipeNavigation();

    // Initialize mobile accordion text
    updateMobileAccordionText(currentEchoarea);

    // Search on enter key for both desktop and mobile
    $('#searchInput, #mobileSearchInput').on('keypress', function(e) {
        if (e.which === 13) {
            searchMessages();
        }
    });

    // Auto refresh every 5 minutes
    startAutoRefresh(function() {
        loadMessages();
        loadStats();
    }, 300000);
});

function loadEchoareas() {
    $.get('/api/echoareas?subscribed_only=true')
        .done(function(data) {
            allEchoareas = data.echoareas;
            applyEchoareaFilter();
            updateEchoInfoBar();
        })
        .fail(function() {
            $('#echoareasList').html(`<div class="text-center text-danger p-3">${uiT('ui.echoareas.load_failed', 'Failed to load echo areas')}</div>`);
            $('#mobileEchoareasList').html(`<div class="text-center text-danger p-3">${uiT('ui.echoareas.load_failed', 'Failed to load echo areas')}</div>`);
        });
}

/**
 * Update the echo info bar (description + subscribe button) for the current echo.
 * If no echo is selected the bar is hidden.
 */
function updateEchoInfoBar() {
    if (!currentEchoarea) {
        $('#echoInfoBar').addClass('d-none');
        currentEchoareaData = null;
        return;
    }

    // Look for the area in the already-loaded subscribed list
    currentEchoareaData = null;
    if (allEchoareas) {
        for (const area of allEchoareas) {
            const fullTag = area.domain ? `${area.tag}@${area.domain}` : area.tag;
            if (fullTag === currentEchoarea) {
                currentEchoareaData = area;
                break;
            }
        }
    }

    if (currentEchoareaData) {
        renderEchoInfoBar(currentEchoareaData, true);
    } else {
        // Area not in subscribed list — lazy-fetch all areas to get description + ID
        if (allEchoareasCache) {
            const found = allEchoareasCache[currentEchoarea] || null;
            currentEchoareaData = found;
            renderEchoInfoBar(found, false);
        } else {
            // Show bar immediately with spinner while fetching
            $('#echoDescription').text('');
            $('#echoSubscribeBtn').prop('disabled', true).text('...');
            $('#echoInfoBar').removeClass('d-none');

            $.get('/api/echoareas').done(function(data) {
                allEchoareasCache = {};
                (data.echoareas || []).forEach(function(area) {
                    const fullTag = area.domain ? `${area.tag}@${area.domain}` : area.tag;
                    allEchoareasCache[fullTag] = area;
                });
                const found = allEchoareasCache[currentEchoarea] || null;
                currentEchoareaData = found;
                renderEchoInfoBar(found, false);
            }).fail(function() {
                renderEchoInfoBar(null, false);
            });
        }
    }
}

/**
 * Render the info bar contents given an area object and subscription state.
 * @param {object|null} area  Area data (may be null for unknown areas)
 * @param {boolean}     subscribed
 */
function renderEchoInfoBar(area, subscribed) {
    const title       = area ? (area.domain ? `${area.tag}@${area.domain}` : (area.tag || '')) : '';
    const description = area ? (area.description || '') : '';
    const areaId      = area ? area.id : null;

    $('#echoTitle').text(title);
    $('#echoDescription').text(description);

    const btn = $('#echoSubscribeBtn');
    btn.prop('disabled', false).attr('data-area-id', areaId || '').attr('data-subscribed', subscribed ? '1' : '0');

    if (subscribed) {
        btn.removeClass('btn-outline-success').addClass('btn-outline-secondary')
           .html(`<i class="fas fa-star me-1"></i>${uiT('ui.echomail.unsubscribe', 'Unsubscribe')}`);
    } else {
        btn.removeClass('btn-outline-secondary').addClass('btn-outline-success')
           .html(`<i class="far fa-star me-1"></i>${uiT('ui.echomail.subscribe', 'Subscribe')}`);
    }

    $('#echoInfoBar').removeClass('d-none');
}

/**
 * Toggle subscription for the currently viewed echo area.
 */
function toggleSubscription() {
    const btn        = $('#echoSubscribeBtn');
    const areaId     = parseInt(btn.attr('data-area-id'));
    const subscribed = btn.attr('data-subscribed') === '1';

    if (!areaId) return;

    const action = subscribed ? 'unsubscribe' : 'subscribe';
    btn.prop('disabled', true);

    $.ajax({
        url: '/api/subscriptions/user',
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ action: action, echoarea_id: areaId }),
        success: function(data) {
            if (data.success) {
                // Update button immediately, then reload sidebar in background
                renderEchoInfoBar(currentEchoareaData, !subscribed);
                allEchoareasCache = null;
                loadEchoareas();
            } else {
                btn.prop('disabled', false);
            }
        },
        error: function() {
            btn.prop('disabled', false);
        }
    });
}

function searchEchoareas(query) {
    echoareaSearchQuery = query.toLowerCase();
    applyEchoareaFilter();
}

function applyEchoareaFilter() {
    let filtered = allEchoareas;

    if (echoareaSearchQuery.length > 0) {
        filtered = allEchoareas.filter(area =>
            area.tag.toLowerCase().includes(echoareaSearchQuery) ||
            (area.description && area.description.toLowerCase().includes(echoareaSearchQuery))
        );
    }

    displayEchoareas(filtered);
    displayMobileEchoareas(filtered);
}

function displayEchoareas(echoareas) {
    const container = $('#echoareasList');
    let html = '';

    if (echoareas && echoareas.length > 0) {
        // All messages option
        html += `
            <div class="node-item ${!currentEchoarea ? 'bg-primary text-white' : ''}" onclick="selectEchoarea(null)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="node-system">${uiT('ui.common.all_messages', 'All Messages')}</div>
                        <small class="text-muted">${uiT('ui.echomail.view_all_echo_areas', 'View all echo areas')}</small>
                    </div>
                    <span class="badge bg-secondary">All</span>
                </div>
            </div>
        `;

        echoareas.forEach(function(area) {
            const areaDomain = (area.domain || '').toString().trim();
            const fullTag = areaDomain ? `${area.tag}@${areaDomain}` : area.tag;
            const isActive = currentEchoarea === fullTag;
            const unreadCount = area.unread_count || 0;
            const totalCount = area.message_count || 0;

            // Use search count if search is active
            let countDisplay;
            if (isSearchActive && area.search_count !== undefined) {
                countDisplay = `<span class="badge bg-info">${uiT('ui.echomail.search_found_count', '{count} found', { count: area.search_count })}</span>`;
            } else {
                countDisplay = `<span class="badge ${isActive ? 'bg-light text-dark' : 'bg-secondary'}">${unreadCount}/${totalCount}</span>`;
            }

            html += `
                <div class="node-item ${isActive ? 'bg-primary text-white' : ''}" onclick="selectEchoarea('${fullTag}')">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="node-address">${area.tag} ${area.domain ? `<span class="badge ${isActive ? 'bg-light text-dark' : 'bg-secondary'}" style="font-size: 0.65em;">${area.domain}</span>` : ''}</div>
                            <div class="node-system">${escapeHtml(area.description)}</div>
                        </div>
                        ${countDisplay}
                    </div>
                </div>
            `;
        });
    } else {
        html = `<div class="text-center text-muted p-3">${uiT('ui.echomail.no_echoareas_available', 'No echo areas available')}</div>`;
    }

    container.html(html);
}

function displayMobileEchoareas(echoareas) {
    const container = $('#mobileEchoareasList');
    let html = '';

    if (echoareas && echoareas.length > 0) {
        // All messages option
        html += `
            <div class="list-group-item list-group-item-action ${!currentEchoarea ? 'active' : ''}" onclick="selectEchoarea(null)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-bold">${uiT('ui.common.all_messages', 'All Messages')}</div>
                        <small class="text-muted">${uiT('ui.echomail.view_all_echo_areas', 'View all echo areas')}</small>
                    </div>
                    <span class="badge bg-secondary">All</span>
                </div>
            </div>
        `;

        echoareas.forEach(function(area) {
            const areaDomain = (area.domain || '').toString().trim();
            const fullTag = areaDomain ? `${area.tag}@${areaDomain}` : area.tag;
            const isActive = currentEchoarea === fullTag;
            const unreadCount = area.unread_count || 0;
            const totalCount = area.message_count || 0;

            // Use search count if search is active
            let countDisplay;
            if (isSearchActive && area.search_count !== undefined) {
                countDisplay = `<span class="badge bg-info">${uiT('ui.echomail.search_found_count', '{count} found', { count: area.search_count })}</span>`;
            } else {
                countDisplay = `<span class="badge ${isActive ? 'bg-light text-dark' : 'bg-secondary'}">${unreadCount}/${totalCount}</span>`;
            }

            html += `
                <div class="list-group-item list-group-item-action ${isActive ? 'active' : ''}" onclick="selectEchoarea('${fullTag}')">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold">${area.tag} ${area.domain ? `<span class="badge ${isActive ? 'bg-light text-dark' : 'bg-secondary'}" style="font-size: 0.65em;">${area.domain}</span>` : ''}</div>
                            <div class="text-muted small">${escapeHtml(area.description)}</div>
                        </div>
                        ${countDisplay}
                    </div>
                </div>
            `;
        });

        // Wrap in list-group
        html = '<div class="list-group list-group-flush">' + html + '</div>';
    } else {
        html = `<div class="text-center text-muted p-3">${uiT('ui.echomail.no_echoareas_available', 'No echo areas available')}</div>`;
    }

    container.html(html);
}

function selectEchoarea(tag) {
    currentEchoarea = tag;
    updateEchoInfoBar();
    const memKey = tag || '__all__';
    currentPage = echoPageMemory[memKey] ? echoPageMemory[memKey] : 1;

    // Update URL without page reload
    const url = tag ? `/echomail/${encodeURIComponent(tag)}` : '/echomail';
    history.pushState({echoarea: tag}, '', url);

    // Update mobile accordion text
    updateMobileAccordionText(tag);

    // Collapse mobile accordion after selection
    $('#echoAreasCollapse').collapse('hide');

    // Update active state in existing DOM instead of re-rendering entire list
    $('.node-item, .list-group-item-action').removeClass('bg-primary text-white active');
    $('.node-item .badge, .list-group-item-action .badge').removeClass('bg-light text-dark').addClass('bg-secondary');

    // Add active state to the selected item
    if (tag) {
        // Find the clicked item by checking the onclick attribute
        $(`.node-item[onclick*="selectEchoarea('${tag.replace(/'/g, "\\'")}')"]`).addClass('bg-primary text-white');
        $(`.node-item[onclick*="selectEchoarea('${tag.replace(/'/g, "\\'")}')"] .badge`).removeClass('bg-secondary').addClass('bg-light text-dark');

        $(`.list-group-item-action[onclick*="selectEchoarea('${tag.replace(/'/g, "\\'")}')"]`).addClass('active');
    } else {
        // "All Messages" is selected
        $(".node-item[onclick*=\"selectEchoarea(null)\"]").addClass('bg-primary text-white');
        $(".node-item[onclick*=\"selectEchoarea(null)\"] .badge").removeClass('bg-secondary').addClass('bg-light text-dark');

        $(".list-group-item-action[onclick*=\"selectEchoarea(null)\"]").addClass('active');
    }

    loadMessages();
}

function loadMessages(callback) {
    showLoading('#messagesContainer');

    // Clear search terms when loading regular messages (not from search)
    currentSearchTerms = [];

    if (currentFilter === 'drafts') {
        // Load drafts instead of regular messages
        loadDrafts();
        return;
    }

    let url = '/api/messages/echomail';
    if (currentEchoarea) {
        url += `/${encodeURIComponent(currentEchoarea)}`;
    }
    url += `?page=${currentPage}&sort=${currentSort}&filter=${currentFilter}`;
    if (threadedView) {
        url += '&threaded=true';
    }

    $.get(url)
        .done(function(data) {
            // If the saved page is beyond the last page, reset to page 1 and reload
            if (currentPage > 1 && data.messages && data.messages.length === 0 && data.pagination && data.pagination.pages < currentPage) {
                currentPage = 1;
                loadMessages(callback);
                return;
            }
            displayMessages(data.messages, data.threaded || false);
            updatePagination(data.pagination);
            updateUnreadCount(data.unreadCount || 0);
            // Remember the current page for this area (null = All Messages, stored as '__all__')
            echoPageMemory[currentEchoarea || '__all__'] = currentPage;
            saveEchoPositions();
            // Refresh stats to get updated filter counts
            loadStats();
            if (typeof callback === 'function') {
                callback(data);
            }
        })
        .fail(function() {
            $('#messagesContainer').html(`<div class="text-center text-danger py-4">${uiT('errors.failed_load_messages', 'Failed to load messages')}</div>`);
        });
}

function loadDrafts() {
    $.get('/api/messages/drafts?type=echomail')
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
        html = `<div class="text-center text-muted py-4">${uiT('ui.echomail.no_drafts_found', 'No drafts found')}</div>`;
    } else {
        // Create table structure
        html = `
            <div class="table-responsive">
                <table class="table table-hover message-table mb-0">
                    <thead>
                        <tr>
                            <th style="width: 25%">${uiT('ui.echomail.to_echo_area', 'To / Echo Area')}</th>
                            <th style="width: 50%">${uiT('ui.common.subject_label_short', 'Subject')}</th>
                            <th colspan="2" style="width: 25%">${uiT('ui.echomail.last_updated', 'Last Updated')}</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        drafts.forEach(function(draft) {
            const displayTarget = draft.to_name || uiT('ui.common.all', 'All');
            const displayArea = draft.echoarea || uiT('ui.echomail.no_area', 'No area');

            html += `
                <tr class="message-row" style="cursor: pointer;" onclick="continueDraft(${draft.id})">
                    <td>
                        <div><strong>${uiT('ui.common.to_label', 'To:')}</strong> ${escapeHtml(displayTarget)}</div>
                        <div class="text-muted small"><strong>${uiT('ui.common.area_label', 'Area:')}</strong> ${escapeHtml(displayArea)}</div>
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
    const container = $('#messagesContainer');
    let html = '';

    // Store current messages for navigation
    currentMessages = messages;

    if (messages.length === 0) {
        html = `<div class="text-center text-muted py-4">${uiT('messages.none_found', 'No messages found')}</div>`;
    } else {
        // Create table structure
        html = `
            <div class="table-responsive">
                <table class="table table-hover message-table mb-0">
                    <thead>
                        <tr>
                            <th style="width: 5%" id="selectAllColumn" class="d-none">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="selectAllMessages" onchange="toggleSelectAll()">
                                </div>
                            </th>
                            <th style="width: 25%">${uiT('ui.common.from', 'From')}</th>
                            <th style="width: 60%">${uiT('ui.common.subject_label_short', 'Subject')}</th>
                            <th colspan="2" style="width: 15%">${uiT('ui.netmail.received', 'Received')}</th>

                        </tr>
                    </thead>
                    <tbody>
        `;

        messages.forEach(function(msg) {
            // Check if message is addressed to current user
            const isToCurrentUser = msg.to_name && window.currentUserRealName && msg.to_name === window.currentUserRealName;
            const toInfo = msg.to_name && msg.to_name !== uiT('ui.common.all', 'All') ?
                ` (${uiT('ui.echomail.to_prefix', 'to:')} <span class="${isToCurrentUser ? 'text-green fw-bold' : ''}">${escapeHtml(msg.to_name)}</span>)` : '';
            const isRead = msg.is_read == 1;
            const isShared = msg.is_shared == 1;
            const isSaved = msg.is_saved == 1;
            const readClass = isRead ? 'read' : 'unread';
            const readIcon = isRead ? `<i class="fas fa-envelope-open text-muted me-1" title="${uiT('ui.common.read', 'Read')}"></i>` : `<i class="fas fa-envelope text-primary me-1" title="${uiT('ui.common.unread', 'Unread')}"></i>`;
            const petsciiIcon = msg.art_format === 'petscii' ? `<span class="badge me-1" style="background-color:#4040a0;color:#fff;font-size:0.6em;padding:1px 3px;vertical-align:middle;" title="PETSCII / C64 Art">C64</span>` : '';
            const shareIcon = isShared ? `<i class="fas fa-share-alt text-success me-1" title="${uiT('ui.common.shared', 'Shared')}"></i>` : '';
            const saveIcon = `<i class="fas fa-bookmark ${isSaved ? 'text-warning' : 'text-muted'} me-1 save-btn"
                                 data-message-id="${msg.id}"
                                 data-message-type="echomail"
                                 data-saved="${isSaved}"
                                 title="${isSaved ? uiT('ui.common.remove_from_saved', 'Remove from saved') : uiT('ui.common.save_for_later', 'Save for later')}"
                                 style="cursor: pointer;"
                                 onclick="toggleSaveMessage(${msg.id}, 'echomail', ${isSaved})"></i>`;

            // Threading support
            const threadLevel = msg.thread_level || 0;
            const replyCount = msg.reply_count || 0;
            const isThreadRoot = msg.is_thread_root || false;
            const threadIcon = threadLevel > 0 ? `<i class="fas fa-reply me-1 text-muted" title="${uiT('ui.common.reply', 'Reply')}"></i>` : '';
            const replyCountBadge = isThreadRoot && replyCount > 0 ? ` <span class="badge bg-secondary ms-1" title="${uiT('ui.common.replies_with_count', '{count} replies', { count: replyCount })}">${replyCount}</span>` : '';

            // Add thread-specific CSS classes; indent up to 2 levels (0.5rem each)
            const threadClasses = isThreaded ? `thread-level-${Math.min(threadLevel, 9)} ${isThreadRoot ? 'thread-root' : 'thread-reply'}` : '';
            const indentRem = isThreaded && threadLevel > 0 ? Math.min(threadLevel, 2) * 0.75 : 0;
            const threadIndent = indentRem > 0 ? `padding-left: ${indentRem}rem;` : '';

            html += `
                <tr class="message-row ${readClass} ${threadClasses}" data-message-id="${msg.id}">
                    <td class="message-checkbox d-none">
                        <div class="form-check">
                            <input class="form-check-input message-select" type="checkbox" value="${msg.id}" onchange="updateSelection()">
                        </div>
                    </td>
                    <td class="message-from clickable-cell" onclick="viewMessage(${msg.id})" style="cursor: pointer;${threadIndent}">
                        ${threadIcon}${readIcon}${petsciiIcon}${shareIcon}${saveIcon}<a href="/compose/netmail?to=${encodeURIComponent((msg.replyto_address && msg.replyto_address !== '') ? msg.replyto_address : msg.from_address)}&to_name=${encodeURIComponent((msg.replyto_name && msg.replyto_name !== '') ? msg.replyto_name : msg.from_name)}&subject=${encodeURIComponent('Re: ' + (msg.subject || ''))}" class="text-decoration-none" onclick="event.stopPropagation()" title="${uiT('ui.common.send_netmail_to', 'Send netmail to {name}', { name: msg.from_name })}">${escapeHtml(msg.from_name)}</a>
                    </td>
                    <td class="message-subject clickable-cell" onclick="viewMessage(${msg.id})" style="cursor: pointer;">
                        ${!currentEchoarea ? `<div class="mb-1">
                            <span class="badge" style="background-color: ${msg.echoarea_color || '#28a745'}; color: white;">${msg.echoarea}</span>
                            ${msg.echoarea_domain ? `<span class="badge bg-secondary ms-1" style="font-size: 0.7em;">${msg.echoarea_domain}</span>` : ''}
                        </div>` : ''}
                        ${isRead ? '' : '<strong>'}${escapeHtml(msg.subject || uiT('messages.no_subject', '(No Subject)'))}${isRead ? '' : '</strong>'}${replyCountBadge}
                        ${toInfo ? `<br><small class="text-muted">${toInfo}</small>` : ''}
                    </td>
                    <td class="message-date clickable-cell" onclick="viewMessage(${msg.id})" style="cursor: pointer;" title="${USE_DATE_FIELD === 'written' ? uiT('ui.common.received_prefix', 'Received:') + ' ' + formatFullDate(msg.date_received) : uiT('ui.common.written_prefix', 'Written:') + ' ' + formatFullDate(msg.date_written)}">${formatDate(USE_DATE_FIELD === 'written' ? msg.date_written : msg.date_received)}</td>
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

function updatePagination(pagination) {
    currentPagination = pagination;
    const container = $('#pagination');
    let html = '';

    if (pagination.pages > 1) {
        html = '<ul class="pagination pagination-sm mb-0">';

        // Previous button
        if (pagination.page > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${pagination.page - 1})">${uiT('ui.common.previous', 'Previous')}</a></li>`;
        }

        // Page numbers: first, ellipsis, window around current, ellipsis, last
        const cur = pagination.page, total = pagination.pages;
        const pageBtn = (n) => {
            const active = n === cur ? 'active' : '';
            return `<li class="page-item ${active}"><a class="page-link" href="#" onclick="changePage(${n})">${n}</a></li>`;
        };
        const ellipsis = `<li class="page-item disabled"><span class="page-link">&hellip;</span></li>`;

        const pages = new Set([1, total]);
        for (let i = Math.max(1, cur - 2); i <= Math.min(total, cur + 2); i++) pages.add(i);

        let prev = 0;
        [...pages].sort((a, b) => a - b).forEach(p => {
            if (prev && p - prev > 1) html += ellipsis;
            html += pageBtn(p);
            prev = p;
        });

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

function sortMessages(sortBy) {
    currentSort = sortBy;
    currentPage = 1;
    updateSortIndicator();

    // Save sort preference
    window.saveUserSetting('default_sort', sortBy);

    loadMessages();
}

function updateSortIndicator() {
    $('.sort-option').each(function() {
        const $el = $(this);
        $el.find('.sort-active-indicator').remove();
        if ($el.data('sort') === currentSort) {
            $el.prepend('<i class="fas fa-arrow-right me-2 sort-active-indicator text-primary" style="font-size:0.75em;"></i>');
        }
    });
}

function refreshMessages() {
    loadMessages();
    showSuccess(uiT('ui.echomail.messages_refreshed', 'Messages refreshed'));
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

    // Save preference
    window.saveUserSetting('threaded_view', threadedView);

    loadMessages();
}

function setFilter(filter) {
    currentFilter = filter;
    currentPage = 1;
    updateFilterTabs();
    loadMessages();
}

function updateFilterTabs() {
    // Remove active class from all tabs
    $('.nav-tabs .nav-link').removeClass('active');

    // Add active class to current tab
    if (currentFilter === 'all') {
        $('#allTab').addClass('active');
    } else if (currentFilter === 'unread') {
        $('#unreadTab').addClass('active');
    } else if (currentFilter === 'read') {
        $('#readTab').addClass('active');
    } else if (currentFilter === 'tome') {
        $('#toMeTab').addClass('active');
    } else if (currentFilter === 'saved') {
        $('#savedTab').addClass('active');
    } else if (currentFilter === 'drafts') {
        $('#draftsTab').addClass('active');
    }
}

function updateUnreadCount(count) {
    $('#unreadCount').text(count);
}

function updateFilterCounts(counts) {
    $('#allCount').text(counts.all || 0);
    $('#unreadCount').text(counts.unread || 0);
    $('#readCount').text(counts.read || 0);
    $('#toMeCount').text(counts.tome || 0);
    $('#savedCount').text(counts.saved || 0);
    $('#draftsCount').text(counts.drafts || 0);
}

function markEchomailAsRead(messageId) {
    $.post(`/api/messages/echomail/${messageId}/read`)
        .done(function() {
            // Update the UI to show message as read
            const messageRow = $(`.message-row[data-message-id="${messageId}"]`);
            if (messageRow.length) {
                messageRow.removeClass('unread').addClass('read');
                // Change envelope icon from closed to open
                messageRow.find('.fa-envelope').removeClass('fas fa-envelope text-primary').addClass('fas fa-envelope-open text-muted');
                // Remove bold formatting from subject
                messageRow.find('strong').contents().unwrap();
                // Update title attribute
                messageRow.find('.fa-envelope-open').attr('title', 'Read');
            }
        })
        .fail(function() {
            console.log('Failed to mark echomail as read');
        });
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

    // Find the current message index in the messages array
    currentMessageIndex = currentMessages.findIndex(msg => msg.id == messageId);

    // Update navigation button states
    updateNavigationButtons();

    // Add history entry for mobile back button support
    if (!history.state || history.state.modal !== 'message') {
        history.pushState({modal: 'message', messageId: messageId}, '', '');
    }

    // Mark as read immediately
    markEchomailAsRead(messageId);

    $('#messageContent').html(`
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin me-2"></i>
            ${uiT('ui.common.loading_message', 'Loading message...')}
        </div>
    `);

    applyModalFullscreenPreference();
    $('#messageModal').modal('show');

    // Choose the appropriate API endpoint based on whether we have a specific echoarea
    let apiUrl;
    if (currentEchoarea) {
        apiUrl = `/api/messages/echomail/${encodeURIComponent(currentEchoarea)}/${messageId}`;
    } else {
        apiUrl = `/api/messages/echomail/message/${messageId}`;
    }

    $.get(apiUrl)
        .done(function(data) {
            displayMessageContent(data);
            // Auto-scroll to top of modal content
            $('#messageModal .modal-body').scrollTop(0);
        })
        .fail(function() {
            $('#messageContent').html(`<div class="text-danger">${uiT('errors.messages.echomail.get_failed', 'Failed to load message')}</div>`);
        });
}

function displayMessageContent(message) {
    // Re-enable toolbar buttons that may have been disabled by the end-of-echo prompt
    $('#editMessageButton, #toggleHeaders, #printMessageButton').prop('disabled', false);

    updateModalTitle(message.subject);

    // Parse message to separate kludge lines from body
    const parsedMessage = parseEchomailMessage(message.message_text || '', message.kludge_lines || '', message.bottom_kludges || null);
    currentMessageData = message;

    // Check if sender is already in address book before rendering
    checkAndDisplayEchomailMessage(message, parsedMessage);
}

function toggleKeyboardHelp() {
    keyboardHelpVisible = !keyboardHelpVisible;
    $('#keyboardHelpOverlay').toggle(keyboardHelpVisible);
}

function hideKeyboardHelp() {
    keyboardHelpVisible = false;
    $('#keyboardHelpOverlay').hide();
}

function getNextRenderMode(mode) {
    if (window.getNextViewerRenderMode) {
        return window.getNextViewerRenderMode(mode);
    }
    const modes = ['auto', 'rip', 'ansi', 'amiga_ansi', 'petscii', 'plain'];
    const currentIndex = modes.indexOf(mode);
    return modes[(currentIndex + 1 + modes.length) % modes.length];
}

function loadRiptermJsForMessages() {
    if (_messageRiptermLoaderPromise) return _messageRiptermLoaderPromise;
    if (window.RIPterm && window.BGI) {
        _messageRiptermLoaderPromise = Promise.resolve();
        return _messageRiptermLoaderPromise;
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-ripterm-src="${src}"]`);
            if (existing) {
                if (existing.dataset.loaded === 'true') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = false;
            script.dataset.riptermSrc = src;
            script.addEventListener('load', () => {
                script.dataset.loaded = 'true';
                resolve();
            }, { once: true });
            script.addEventListener('error', reject, { once: true });
            document.head.appendChild(script);
        });
    }

    _messageRiptermLoaderPromise = loadScript('/vendor/riptermjs/BGI.js')
        .then(() => loadScript('/vendor/riptermjs/ripterm.js'));
    return _messageRiptermLoaderPromise;
}

function renderRipMessageBody(container, ripText) {
    const canvasId = `messageRipCanvas_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

    container.innerHTML = `
        <div class="text-center py-4 text-muted" data-rip-loading>
            <i class="fas fa-spinner fa-spin fa-2x"></i>
        </div>
        <div class="d-none" data-rip-stage style="overflow:auto;max-height:70vh;padding:8px;text-align:center;background:#0a0a0a;border-radius:6px;">
            <canvas id="${canvasId}" width="640" height="350"
                style="width:100%;max-width:960px;height:auto;image-rendering:pixelated;background:#000;border:1px solid #193247;border-radius:6px;"></canvas>
        </div>
    `;

    loadRiptermJsForMessages()
        .then(async () => {
            const blobUrl = URL.createObjectURL(new Blob([ripText], { type: 'text/plain' }));
            const ripterm = new window.RIPterm({
                canvasId: canvasId,
                timeInterval: 0,
                refreshInterval: 25,
                fontsPath: '/vendor/riptermjs/fonts',
                iconsPath: '/vendor/riptermjs/icons',
                logQuiet: true
            });

            await ripterm.initFonts();
            ripterm.reset();
            try {
                await ripterm.openURL(blobUrl);
                await ripterm.play();
            } finally {
                URL.revokeObjectURL(blobUrl);
            }

            const loading = container.querySelector('[data-rip-loading]');
            const stage = container.querySelector('[data-rip-stage]');
            if (loading) loading.remove();
            if (stage) stage.classList.remove('d-none');
        })
        .catch((err) => {
            console.error('RIP message render failed:', err);
            container.innerHTML = `<div class="alert alert-danger m-3">${uiT('ui.echomail.rip_render_failed', 'Failed to render RIPscrip message')}</div>`;
        });
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
    badgeText.textContent = `${prefix} ${modeLabel} — ${suffix}`;
    badge.style.display = '';
}

function renderCurrentMessageBody() {
    if (!currentMessageData || !currentParsedMessage) return;

    const body = currentParsedMessage.messageBody;
    const detectedRipScript = currentMessageData.rip_script
        || ((typeof looksLikeRipScript === 'function' && looksLikeRipScript(body)) ? body : null);
    const isRipMode = !!detectedRipScript && (currentRenderMode === 'auto' || currentRenderMode === 'rip');
    const container = document.getElementById('messageTextContainer');
    if (!container) return;

    if (isRipMode) {
        renderRipMessageBody(container, detectedRipScript);
        updateRenderModeBadge();
        return;
    }

    if (currentRenderMode !== 'plain'
            && typeof looksLikeSixel === 'function'
            && looksLikeSixel(body)) {
        renderSixelChunks(container, body, function (textChunk) {
            return formatMessageBodyForDisplay(currentMessageData, textChunk, currentSearchTerms, {
                formatOverride: currentRenderMode === 'plain' ? null : currentRenderMode
            });
        });
        updateRenderModeBadge();
        updateSaveToAdLibraryButton();
        return;
    }

    let bodyHtml;
    if (currentRenderMode === 'auto' && currentMessageData.markup_html) {
        bodyHtml = currentMessageData.markup_html;
    } else {
        bodyHtml = formatMessageBodyForDisplay(currentMessageData, body, currentSearchTerms, {
            forcePlain: currentRenderMode === 'plain',
            formatOverride: currentRenderMode === 'plain' ? null : currentRenderMode
        });
    }

    container.innerHTML = '';
    const tmp = document.createElement('div');
    tmp.innerHTML = bodyHtml;
    while (tmp.firstChild) container.appendChild(tmp.firstChild);
    updateRenderModeBadge();
    updateSaveToAdLibraryButton();
}

function isAnsiAdCandidate(message, bodyText) {
    const text = bodyText || message?.message_text || '';
    if (!text || text.trim() === '') {
        return false;
    }

    const format = window.normalizeArtFormat ? window.normalizeArtFormat(message?.art_format || 'auto') : String(message?.art_format || 'auto').toLowerCase();
    if (format === 'ansi' || format === 'amiga_ansi') {
        return true;
    }
    if (format === 'petscii' || format === 'rip' || format === 'plain') {
        return false;
    }

    if (typeof looksLikeRipScript === 'function' && looksLikeRipScript(text)) {
        return false;
    }

    const hasAnsi = /\x1b\[[0-9;]*m/.test(text);
    const hasCursorAnsi = /\x1b\[[0-9;]*[ABCDEFGHJKfsu]/.test(text);
    const hasPipes = /\|[0-9A-Fa-f]{2}/.test(text);
    const lines = text.split(/\r?\n/);
    const nonEmptyLines = lines.filter(line => line.trim() !== '').length;
    const maxLineLength = lines.reduce((max, line) => Math.max(max, line.length), 0);
    const linesWithLeadingSpaces = lines.filter(line => /^\s{5,}\S/.test(line)).length;
    const hasLeadingSpaceArt = linesWithLeadingSpaces >= 3 && linesWithLeadingSpaces >= (nonEmptyLines * 0.5);

    return hasCursorAnsi
        || ((hasAnsi || hasPipes) && nonEmptyLines >= 4 && maxLineLength >= 30)
        || (hasLeadingSpaceArt && nonEmptyLines >= 4 && maxLineLength >= 30);
}

function updateSaveToAdLibraryButton() {
    const button = document.getElementById('saveToAdLibraryButton');
    if (!button) {
        return;
    }

    const body = currentParsedMessage?.messageBody || currentMessageData?.message_text || '';
    const shouldShow = isAnsiAdCandidate(currentMessageData, body);
    button.classList.toggle('d-none', !shouldShow);
    button.disabled = !shouldShow;
}

function cycleRenderMode() {
    currentRenderMode = getNextRenderMode(currentRenderMode);
    renderCurrentMessageBody();
}

function printMessage() {
    const content = document.getElementById('messageContent');
    if (!content) return;
    const win = window.open('', '_blank', 'width=800,height=600');
    win.document.write(
        '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Print</title>'
        + '<style>'
        + 'body{font-family:sans-serif;font-size:11pt;padding:1.5cm;color:#000;background:#fff}'
        + '.message-header-full{border-bottom:1px solid #ccc;margin-bottom:1em;padding-bottom:.5em}'
        + '.message-header-full strong{color:#333}'
        + 'pre{white-space:pre-wrap;word-break:break-word;font-size:10pt;background:#f8f9fa;border:1px solid #dee2e6;padding:.75em;border-radius:4px}'
        + '.message-origin{border-top:1px solid #ccc;margin-top:1em;padding-top:.5em;font-size:9pt;color:#666}'
        + 'a{color:#000;text-decoration:none}'
        + 'button,i.fas,i.far,.badge,.btn,#ansiRenderBadge,.modal-header-save-icon{display:none!important}'
        + '</style>'
        + '</head><body>'
        + content.innerHTML
        + '</body></html>'
    );
    win.document.close();
    win.focus();
    win.onafterprint = function() { win.close(); };
    win.print();
}

function downloadCurrentMessage() {
    if (!currentMessageId || !currentMessageData) {
        return;
    }

    window.location.href = `/api/messages/echomail/${encodeURIComponent(currentMessageId)}/download`;
}

function saveCurrentMessageToAdLibrary() {
    if (!currentMessageId || !currentMessageData || !currentParsedMessage) {
        return;
    }

    if (!isAnsiAdCandidate(currentMessageData, currentParsedMessage.messageBody || currentMessageData.message_text || '')) {
        showError(uiT('ui.echomail.save_to_ad_library_not_ansi', 'This message is not eligible to save as an ANSI ad.'));
        return;
    }

    const button = document.getElementById('saveToAdLibraryButton');
    if (button) {
        button.disabled = true;
    }

    fetch(`/api/messages/echomail/${encodeURIComponent(currentMessageId)}/save-ad`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
        .then(readJsonResponse)
        .then(({ response, data }) => {
            if (!response.ok || !data.success) {
                throw new Error(apiError(data, uiT('ui.echomail.save_to_ad_library_failed', 'Failed to save message to ad library')));
            }

            const successMessage = window.getApiMessage
                ? window.getApiMessage(data, uiT('ui.echomail.save_to_ad_library_saved', 'Message saved to ad library.'))
                : uiT('ui.echomail.save_to_ad_library_saved', 'Message saved to ad library.');
            showSuccess(successMessage);
        })
        .catch(error => {
            showError(error.message || uiT('ui.echomail.save_to_ad_library_failed', 'Failed to save message to ad library'));
        })
        .finally(() => {
            if (button) {
                button.disabled = false;
            }
        });
}

function checkAndDisplayEchomailMessage(message, parsedMessage) {
    // Use REPLYTO priority for checking existing contacts
    const replyToAddress = message.replyto_address || message.from_address;
    const replyToName = message.replyto_name || message.from_name;

    // Check if this contact already exists in address book
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

            renderEchomailMessageContent(message, parsedMessage, isInAddressBook);
        })
        .fail(function() {
            // On error, assume not in address book
            renderEchomailMessageContent(message, parsedMessage, false);
        });
}

/**
 * Parse a ^AFILEREF kludge from a raw kludge_lines string.
 * Format: \x01FILEREF: <area_tag[@domain]> <filename> [<sha256>]
 * Returns { areaTag, filename, hash } or null if not present.
 */
function parseFileRefKludge(kludgeLines) {
    if (!kludgeLines) return null;
    const match = kludgeLines.match(/\x01FILEREF:\s+(\S+)\s+(\S+)(?:\s+([0-9a-fA-F]{64}))?/);
    if (!match) return null;
    return {
        areaTag:  match[1],
        filename: match[2],
        hash:     match[3] || null,
    };
}

/**
 * Build an informational banner when the current message is a file comment.
 */
function buildFileRefBanner(kludgeLines) {
    const ref = parseFileRefKludge(kludgeLines);
    if (!ref) return '';

    const label   = uiT('ui.echomail.fileref_label', 'File comment:');
    const area    = escapeHtml(ref.areaTag);
    const file    = escapeHtml(ref.filename);
    // Strip @domain from area tag for the files page area filter (tag-only lookup)
    const bareTag = ref.areaTag.split('@')[0];
    // Link to the files page with the area pre-selected via query string
    const href    = `/files?area=${encodeURIComponent(bareTag)}&search=${encodeURIComponent(ref.filename)}`;

    return `
        <div class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size:.875rem;">
            <i class="fas fa-paperclip"></i>
            <span>${label}</span>
            <a href="${href}" class="fw-bold text-decoration-none">${file}</a>
            <span class="opacity-75">in</span>
            <span class="badge bg-info text-dark font-monospace">${area}</span>
        </div>
    `;
}

function renderEchomailMessageContent(message, parsedMessage, isInAddressBook) {
    currentParsedMessage = parsedMessage;
    currentRenderMode = 'auto';
    hideKeyboardHelp();
    let addressBookButton;
    const saveAdEligible = isAnsiAdCandidate(message, parsedMessage.messageBody || message.message_text || '');
    let saveAdButton = '';
    if (isInAddressBook) {
        addressBookButton = `
            <button class="btn btn-sm btn-outline-secondary ms-2" id="saveAddressBookBtn" disabled title="${uiT('ui.common.already_in_address_book', 'Already in address book')}">
                <i class="fas fa-check"></i> <i class="fas fa-address-book"></i>
            </button>
        `;
    } else {
        // Use REPLYTO priority for address book save
        const replyToAddress = message.replyto_address || message.from_address;
        const replyToName = message.replyto_name || message.from_name;

        addressBookButton = `
            <button class="btn btn-sm btn-outline-success ms-2" id="saveAddressBookBtn" onclick="saveToAddressBook('${escapeHtml(replyToName)}', '${escapeHtml(replyToAddress)}', '${escapeHtml(message.from_name)}', '${escapeHtml(message.from_address)}')" title="${uiT('ui.common.save_to_address_book', 'Save to address book')}">
                <i class="fas fa-address-book"></i>
            </button>
        `;
    }

    if (document.getElementById('editMessageButton')) {
        saveAdButton = `
            <button class="btn btn-sm btn-outline-secondary ${saveAdEligible ? '' : 'd-none'}" id="saveToAdLibraryButton" onclick="saveCurrentMessageToAdLibrary()" title="${uiT('ui.echomail.save_to_ad_library_title', 'Save to ad library')}">
                <i class="fas fa-bullhorn me-1"></i>${uiT('ui.echomail.save_to_ad_library', 'Save Ad')}
            </button>
        `;
    }

    const html = `
        <div class="message-header-full mb-3">
            <div class="row">
                <div class="col-md-4">
                    <strong>${uiT('ui.common.from_label', 'From:')}</strong> <span id="senderNamePopoverTrigger" style="cursor:pointer; border-bottom: 1px dashed var(--text-color);">${escapeHtml(message.from_name)}</span>
                    ${addressBookButton}
                </div>
                <div class="col-md-4">
                    <strong>${uiT('ui.common.to_label', 'To:')}</strong> ${escapeHtml(message.to_name || uiT('ui.common.all', 'All'))}
                </div>
                <div class="col-md-4">
                    <strong>${uiT('ui.common.area_label', 'Area:')}</strong> ${escapeHtml(message.echoarea)}${message.domain ? '@' + escapeHtml(message.domain) : ''}
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-4">
                    <strong>${uiT('ui.common.date_label', 'Date:')}</strong> ${formatFullDate(message.date_written)}
                </div>
                <div class="col-md-8">
                    <strong>${uiT('ui.common.subject_label', 'Subject:')}</strong> ${escapeHtml(message.subject || uiT('messages.no_subject', '(No Subject)'))}
                </div>
            </div>
            <div class="row mt-2 align-items-center">
                <div class="col-md-6 d-flex align-items-center gap-2">
                    ${saveAdButton}
                </div>
                <div class="col-md-6 text-end">
                    <i class="fas fa-bookmark modal-header-save-icon ${message.is_saved == 1 ? 'text-warning' : 'text-muted'}"
                       id="modalHeaderSaveIcon"
                       style="cursor: pointer;"
                       title="${message.is_saved == 1 ? uiT('ui.common.remove_from_saved', 'Remove from saved') : uiT('ui.common.save_for_later', 'Save for later')}"></i>
                    ${message.is_shared == 1 ? `<i class="fas fa-share-alt text-success ms-2" title="${uiT('ui.common.shared', 'Shared')}"></i>` : ''}
                </div>
            </div>
        </div>

        <div id="kludgeContainer" class="kludge-lines mb-3" style="display: none;">
            <pre class="bg-dark text-light p-3 rounded small">${formatKludgeLinesWithSeparator(parsedMessage.topKludges || parsedMessage.kludgeLines, parsedMessage.bottomKludges || [])}</pre>
        </div>

        ${buildFileRefBanner(message.kludge_lines || '')}

        <div class="message-text">
            <div id="ansiRenderBadge" style="display:none;" class="mb-2">
                <span class="badge bg-secondary" id="ansiRenderBadgeText"></span>
            </div>
            <div id="messageTextContainer"></div>
        </div>
        ${message.origin_line ? `<div class="message-origin mt-2"><small class="text-muted">${escapeHtml(message.origin_line)}</small></div>` : ''}
    `;

    $('#messageContent').html(html);

    const echoNetmailAddr = (message.replyto_address && message.replyto_address !== '') ? message.replyto_address : message.from_address;
    const echoNetmailName = (message.replyto_name && message.replyto_name !== '') ? message.replyto_name : message.from_name;
    initSenderPopover(message, echoNetmailAddr, echoNetmailName);

    // Update save button state AFTER HTML is inserted
    updateModalSaveButton(message);
    renderCurrentMessageBody();

    // Set up reply button
    $('#replyButton').show().off('click').on('click', function() {
        // Store the message ID for the reply
        const messageId = currentMessageId;

        // Hide modal and wait for it to be fully closed before navigating
        $('#messageModal').one('hidden.bs.modal', function() {
            // Small delay to ensure other modal handlers complete first
            setTimeout(function() {
                composeMessage('echomail', messageId);
            }, 10);
        });
        $('#messageModal').modal('hide');
    });

    // Set up share button
    $('#shareButton').show().off('click').on('click', function() {
        showShareDialog(currentMessageId);
    });
}

function composeMessage(type, replyToId = null) {
    let url = `/compose/echomail`;
    const params = new URLSearchParams();

    if (replyToId) {
        params.append('reply', replyToId);
    }
    if (currentEchoarea) {
        params.append('echoarea', currentEchoarea);
    }
    if(domain){
        params.append('domain', domain);
    }

    if (params.toString()) {
        url += '?' + params.toString();
    }

    window.location.href = url;
}

function searchMessages() {
    // Get query from both desktop and mobile search inputs
    let query = $('#searchInput').val().trim();
    if (!query) {
        query = $('#mobileSearchInput').val().trim();
    }

    if (query.length < 2) {
        showError(uiT('errors.messages.search.query_too_short', 'Please enter at least 2 characters to search'));
        return;
    }

    showLoading('#messagesContainer');

    // Store search terms for highlighting
    currentSearchTerms = query.toLowerCase().split(/\s+/).filter(term => term.length > 1);

    let url = `/api/messages/search?q=${encodeURIComponent(query)}&type=echomail`;
    if (currentEchoarea) {
        url += `&echoarea=${encodeURIComponent(currentEchoarea)}`;
    }

    $.get(url)
        .done(function(data) {
            displayMessages(data.messages);
            $('#pagination').empty();

            // Store original filter counts if not already stored
            if (!originalFilterCounts) {
                originalFilterCounts = {
                    all: parseInt($('#allCount').text()) || 0,
                    unread: parseInt($('#unreadCount').text()) || 0,
                    read: parseInt($('#readCount').text()) || 0,
                    tome: parseInt($('#toMeCount').text()) || 0,
                    saved: parseInt($('#savedCount').text()) || 0,
                    drafts: parseInt($('#draftsCount').text()) || 0
                };
            }

            // Update echo area counts with search results
            if (data.echoarea_counts) {
                searchResultCounts = data.echoarea_counts;
                isSearchActive = true;
                updateEchoareaCountsWithSearchResults();
                showClearSearchButton();
            }

            // Update filter counts with search results
            if (data.filter_counts) {
                searchFilterCounts = data.filter_counts;
                updateFilterCounts(data.filter_counts);
            }

            // Collapse mobile search after searching
            $('#mobileSearchCollapse').collapse('hide');
        })
        .fail(function() {
            $('#messagesContainer').html('<div class="p-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + uiT('ui.echomail.search.failed', 'Search failed') + '</div>');
            $('#pagination').empty();
        });
}

function searchMessagesFromMobile() {
    // Copy mobile search input to desktop input for consistency
    const query = $('#mobileSearchInput').val().trim();
    $('#searchInput').val(query);
    searchMessages();
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

    const params = new URLSearchParams({ type: 'echomail' });
    if (fromName) params.set('from_name', fromName);
    if (subject) params.set('subject', subject);
    if (body) params.set('body', body);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    if (currentEchoarea) params.set('echoarea', currentEchoarea);

    $.get('/api/messages/search?' + params.toString())
        .done(function(data) {
            displayMessages(data.messages);
            $('#pagination').empty();

            if (!originalFilterCounts) {
                originalFilterCounts = {
                    all: parseInt($('#allCount').text()) || 0,
                    unread: parseInt($('#unreadCount').text()) || 0,
                    read: parseInt($('#readCount').text()) || 0,
                    tome: parseInt($('#toMeCount').text()) || 0,
                    saved: parseInt($('#savedCount').text()) || 0,
                    drafts: parseInt($('#draftsCount').text()) || 0
                };
            }

            if (data.echoarea_counts) {
                searchResultCounts = data.echoarea_counts;
                isSearchActive = true;
                updateEchoareaCountsWithSearchResults();
                showClearSearchButton();
            }

            if (data.filter_counts) {
                searchFilterCounts = data.filter_counts;
                updateFilterCounts(data.filter_counts);
            }

            $('#mobileSearchCollapse').collapse('hide');
        })
        .fail(function() {
            $('#messagesContainer').html('<div class="p-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + uiT('ui.echomail.search.failed', 'Search failed') + '</div>');
            $('#pagination').empty();
        });
}

function updateEchoareaCountsWithSearchResults() {
    if (!searchResultCounts) return;

    // Create a map of echoarea counts by tag@domain
    const countMap = {};
    searchResultCounts.forEach(area => {
        const areaDomain = (area.domain || '').toString().trim();
        const fullTag = areaDomain ? `${area.tag}@${areaDomain}` : area.tag;
        countMap[fullTag] = area.message_count;
    });

    // Update the allEchoareas array with search counts
    allEchoareas.forEach(area => {
        const areaDomain = (area.domain || '').toString().trim();
        const fullTag = areaDomain ? `${area.tag}@${areaDomain}` : area.tag;
        area.search_count = countMap[fullTag] || 0;
    });

    // Re-display with search counts
    applyEchoareaFilter();
}

function showClearSearchButton() {
    // Add clear search button if not already present
    if ($('#clearSearchBtn').length === 0) {
        const clearBtn = `
            <button id="clearSearchBtn" class="btn btn-sm btn-secondary w-100 mt-2" onclick="clearSearch()">
                <i class="fas fa-times me-1"></i> ${uiT('ui.common.clear_search', 'Clear Search')}
            </button>
        `;
        $('#searchInput').after(clearBtn);
    }

    // Also add to mobile
    if ($('#mobileClearSearchBtn').length === 0) {
        const mobileClearBtn = `
            <button id="mobileClearSearchBtn" class="btn btn-sm btn-secondary w-100 mt-2" onclick="clearSearch()">
                <i class="fas fa-times me-1"></i> ${uiT('ui.common.clear_search', 'Clear Search')}
            </button>
        `;
        $('#mobileSearchInput').after(mobileClearBtn);
    }
}

function clearSearch() {
    // Clear search inputs
    $('#searchInput').val('');
    $('#mobileSearchInput').val('');

    // Clear search state
    currentSearchTerms = [];
    searchResultCounts = null;
    searchFilterCounts = null;
    isSearchActive = false;

    // Remove clear buttons
    $('#clearSearchBtn').remove();
    $('#mobileClearSearchBtn').remove();

    // Remove search counts from echoareas
    allEchoareas.forEach(area => {
        delete area.search_count;
    });

    // Restore original filter counts
    if (originalFilterCounts) {
        updateFilterCounts(originalFilterCounts);
        originalFilterCounts = null;
    }

    // Reload messages and redisplay echoareas
    loadMessages();
    applyEchoareaFilter();
}

// Toggle save status of a message
function toggleSaveMessage(messageId, messageType, isSaved) {
    event.stopPropagation(); // Prevent triggering the row click

    const method = isSaved ? 'DELETE' : 'POST';
    const url = `/api/messages/${messageType}/${messageId}/save`;

    $.ajax({
        url: url,
        type: method,
        success: function(response) {
            if (response.success) {
                // Update the icon in the UI
                const icon = $(`.save-btn[data-message-id="${messageId}"]`);
                if (isSaved) {
                    // Message was unsaved
                    icon.removeClass('text-warning').addClass('text-muted');
                    icon.attr('title', uiT('ui.common.save_for_later', 'Save for later'));
                    icon.attr('data-saved', 'false');
                    icon.attr('onclick', `toggleSaveMessage(${messageId}, '${messageType}', false)`);
                    showSuccess(uiT('ui.echomail.saved_items.removed', 'Message removed from saved items'));
                } else {
                    // Message was saved
                    icon.removeClass('text-muted').addClass('text-warning');
                    icon.attr('title', uiT('ui.common.remove_from_saved', 'Remove from saved'));
                    icon.attr('data-saved', 'true');
                    icon.attr('onclick', `toggleSaveMessage(${messageId}, '${messageType}', true)`);
                    showSuccess(uiT('ui.echomail.saved_items.saved', 'Message saved for later'));
                }

                // If we're viewing saved messages, remove the message from view
                if (isSaved && currentFilter === 'saved') {
                    loadMessages();
                }
            } else {
                showError(window.getApiErrorMessage
                    ? window.getApiErrorMessage(response, uiT('ui.echomail.save_status.update_failed', 'Failed to update save status'))
                    : (response.message || uiT('ui.echomail.save_status.update_failed', 'Failed to update save status')));
            }
        },
        error: function(xhr) {
            let response = {};
            try {
                response = JSON.parse(xhr.responseText || '{}');
            } catch (e) {
                response = xhr.responseJSON || {};
            }
            showError(apiError(response, uiT('ui.echomail.save_status.update_failed', 'Failed to update save status')));
        }
    });
}

// Update modal save button based on message save status
function updateModalSaveButton(message) {
    const saveBtn = $('#modalSaveButton');
    const saveText = $('#modalSaveText');
    const saveIcon = saveBtn.find('i');

    // Also update header save icon
    const headerIcon = $('#modalHeaderSaveIcon');

    if (message.is_saved == 1) {
        // Message is saved - show "Saved" button that will unsave when clicked
        saveBtn.removeClass('btn-outline-warning').addClass('btn-warning');
        saveIcon.removeClass('fa-bookmark').addClass('fa-bookmark');
        saveText.text(uiT('ui.common.saved_short', 'Saved'));
        saveBtn.attr('title', uiT('ui.common.remove_from_saved', 'Remove from saved'));

        // Update header icon
        headerIcon.removeClass('text-muted').addClass('text-warning');
        headerIcon.attr('title', uiT('ui.common.remove_from_saved', 'Remove from saved'));

        // Click handlers to UNSAVE (isSaved = true)
        saveBtn.off('click').on('click', function() {
            toggleSaveMessageModal(message.id, 'echomail', true);
        });
        headerIcon.off('click').on('click', function() {
            toggleSaveMessageModal(message.id, 'echomail', true);
        });
    } else {
        // Message is not saved - show "Save" button that will save when clicked
        saveBtn.removeClass('btn-warning').addClass('btn-outline-warning');
        saveIcon.removeClass('fa-bookmark').addClass('fa-bookmark');
        saveText.text(uiT('ui.common.save', 'Save'));
        saveBtn.attr('title', uiT('ui.common.save_for_later', 'Save for later'));

        // Update header icon
        headerIcon.removeClass('text-warning').addClass('text-muted');
        headerIcon.attr('title', uiT('ui.common.save_for_later', 'Save for later'));

        // Click handlers to SAVE (isSaved = false)
        saveBtn.off('click').on('click', function() {
            toggleSaveMessageModal(message.id, 'echomail', false);
        });
        headerIcon.off('click').on('click', function() {
            toggleSaveMessageModal(message.id, 'echomail', false);
        });
    }
}

// Toggle save status from modal
function toggleSaveMessageModal(messageId, messageType, isSaved) {
    const method = isSaved ? 'DELETE' : 'POST';
    const url = `/api/messages/${messageType}/${messageId}/save`;

    $.ajax({
        url: url,
        type: method,
        success: function(response) {
            if (response.success) {
                // Update modal button and header icon
                const saveBtn = $('#modalSaveButton');
                const saveText = $('#modalSaveText');
                const saveIcon = saveBtn.find('i');
                const headerIcon = $('#modalHeaderSaveIcon');

                if (isSaved) {
                    // Message was unsaved
                    saveBtn.removeClass('btn-warning').addClass('btn-outline-warning');
                    saveText.text(uiT('ui.common.save', 'Save'));
                    saveBtn.attr('title', uiT('ui.common.save_for_later', 'Save for later'));

                    // Update header icon
                    headerIcon.removeClass('text-warning').addClass('text-muted');
                    headerIcon.attr('title', uiT('ui.common.save_for_later', 'Save for later'));

                    showSuccess(uiT('ui.echomail.saved_items.removed', 'Message removed from saved items'));

                    // Update click handlers for next time
                    saveBtn.off('click').on('click', function() {
                        toggleSaveMessageModal(messageId, messageType, false);
                    });
                    headerIcon.off('click').on('click', function() {
                        toggleSaveMessageModal(messageId, messageType, false);
                    });
                } else {
                    // Message was saved
                    saveBtn.removeClass('btn-outline-warning').addClass('btn-warning');
                    saveText.text(uiT('ui.common.saved_short', 'Saved'));
                    saveBtn.attr('title', uiT('ui.common.remove_from_saved', 'Remove from saved'));

                    // Update header icon
                    headerIcon.removeClass('text-muted').addClass('text-warning');
                    headerIcon.attr('title', uiT('ui.common.remove_from_saved', 'Remove from saved'));

                    showSuccess(uiT('ui.echomail.saved_items.saved', 'Message saved for later'));

                    // Update click handlers for next time
                    saveBtn.off('click').on('click', function() {
                        toggleSaveMessageModal(messageId, messageType, true);
                    });
                    headerIcon.off('click').on('click', function() {
                        toggleSaveMessageModal(messageId, messageType, true);
                    });
                }

                // Update the corresponding row icon in the message list
                const icon = $(`.save-btn[data-message-id="${messageId}"]`);
                if (icon.length) {
                    if (isSaved) {
                        icon.removeClass('text-warning').addClass('text-muted');
                        icon.attr('title', uiT('ui.common.save_for_later', 'Save for later'));
                        icon.attr('data-saved', 'false');
                        icon.attr('onclick', `toggleSaveMessage(${messageId}, '${messageType}', false)`);
                    } else {
                        icon.removeClass('text-muted').addClass('text-warning');
                        icon.attr('title', uiT('ui.common.remove_from_saved', 'Remove from saved'));
                        icon.attr('data-saved', 'true');
                        icon.attr('onclick', `toggleSaveMessage(${messageId}, '${messageType}', true)`);
                    }
                }

                // If we're viewing saved messages, remove the message from view
                if (isSaved && currentFilter === 'saved') {
                    loadMessages();
                }
            } else {
                showError(window.getApiErrorMessage
                    ? window.getApiErrorMessage(response, uiT('ui.echomail.save_status.update_failed', 'Failed to update save status'))
                    : (response.message || uiT('ui.echomail.save_status.update_failed', 'Failed to update save status')));
            }
        },
        error: function(xhr) {
            let response = {};
            try {
                response = JSON.parse(xhr.responseText || '{}');
            } catch (e) {
                response = xhr.responseJSON || {};
            }
            showError(apiError(response, uiT('ui.echomail.save_status.update_failed', 'Failed to update save status')));
        }
    });
}

// Handle browser back/forward
window.addEventListener('popstate', function(event) {
    if (event.state && event.state.echoarea !== undefined) {
        currentEchoarea = event.state.echoarea;
        loadEchoareas();
        loadMessages();
    }
});

function loadStats() {
    console.log('Loading echomail statistics...');
    let url = '/api/messages/echomail/stats';
    if (currentEchoarea) {
        url += '/' + encodeURIComponent(currentEchoarea);
    }

    $.get(url)
        .done(function(data) {
            console.log('Echomail stats response:', data);
            $('#totalMessages').text(data.total || 0);
            $('#unreadMessages').text(data.unread || 0);
            $('#recentMessages').text(data.recent || 0);

            if (data.areas !== undefined) {
                $('#totalAreas').text(data.areas || 0);
            } else {
                $('#totalAreas').text('-');
            }

            // Update filter counts if available
            if (data.filter_counts) {
                updateFilterCounts(data.filter_counts);
            }
        })
        .fail(function(xhr, status, error) {
            console.error('Echomail stats loading failed:', xhr.status, status, error);
            console.error('Response text:', xhr.responseText);
            $('#totalMessages').text(uiT('ui.common.error', 'Error'));
            $('#unreadMessages').text(uiT('ui.common.error', 'Error'));
            $('#recentMessages').text(uiT('ui.common.error', 'Error'));
            $('#totalAreas').text(uiT('ui.common.error', 'Error'));
        });
}

// Selection and bulk operations functionality
let selectMode = false;
let selectedMessages = new Set();

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

    const bulkButtons = $('#bulkActions button');
    if (count === 0) {
        bulkButtons.prop('disabled', true);
    } else {
        bulkButtons.prop('disabled', false);
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

function markSelectedAsRead() {
    if (selectedMessages.size === 0) {
        showError(uiT('ui.messages.none_selected', 'No messages selected'));
        return;
    }

    const messageIds = Array.from(selectedMessages);
    const markBtn = $('#bulkActions .btn-outline-primary');
    const originalText = markBtn.html();
    markBtn.prop('disabled', true).html(`<i class="fas fa-spinner fa-spin"></i> ${uiT('ui.echomail.bulk_marking', 'Marking...')}`);

    $.ajax({
        url: '/api/messages/echomail/read',
        type: 'POST',
        data: JSON.stringify({ messageIds: messageIds }),
        contentType: 'application/json',
        success: function(response) {
            if (response.success) {
                const markedCount = response.marked || messageIds.length;
                showSuccess(window.getApiMessage
                    ? window.getApiMessage(response, uiT('ui.echomail.bulk_mark_read_success', 'Marked {count} message(s) as read', { count: markedCount }))
                    : (response.message || uiT('ui.echomail.bulk_mark_read_success', 'Marked {count} message(s) as read', { count: markedCount })));
                clearSelection();
                loadMessages();
                loadStats();
            } else {
                showError(apiError(response, uiT('errors.messages.echomail.bulk_read.failed', 'Failed to mark messages as read')));
            }
        },
        error: function(xhr) {
            let errorMessage = uiT('ui.echomail.bulk_mark_read_failed', 'Failed to mark messages as read');
            try {
                const response = JSON.parse(xhr.responseText);
                errorMessage = apiError(response, errorMessage);
            } catch (e) {
                // Use default error message
            }
            showError(errorMessage);
        },
        complete: function() {
            markBtn.prop('disabled', false).html(originalText);
        }
    });
}

function deleteSelectedMessages() {
    if (!window.isAdmin) {
        showError(uiT('errors.messages.echomail.bulk_delete.admin_required', 'Admin privileges required to delete echomail messages'));
        return;
    }

    if (selectedMessages.size === 0) {
        showError(uiT('ui.messages.none_selected', 'No messages selected'));
        return;
    }

    const count = selectedMessages.size;
    const confirmMessage = uiT(
        'ui.echomail.bulk_delete.confirm',
        'Are you sure you want to delete {count} selected message(s) for everyone?',
        { count }
    );

    if (!confirm(confirmMessage)) {
        return;
    }

    // Convert Set to Array for API call
    const messageIds = Array.from(selectedMessages);

    // Show loading state
    const deleteBtn = $('#bulkActions .btn-outline-danger');
    const originalText = deleteBtn.html();
    deleteBtn.prop('disabled', true).html(`<i class="fas fa-spinner fa-spin"></i> ${uiT('ui.echomail.bulk_deleting', 'Deleting...')}`);

    $.ajax({
        url: '/api/messages/echomail/delete',
        type: 'POST',
        data: JSON.stringify({ messageIds: messageIds }),
        contentType: 'application/json',
        success: function(response) {
            if (response.success) {
                const deletedCount = response.deleted || messageIds.length;
                showSuccess(window.getApiMessage
                    ? window.getApiMessage(response, uiT('ui.echomail.bulk_delete.success', 'Deleted {count} message(s)', { count: deletedCount }))
                    : (response.message || uiT('ui.echomail.bulk_delete.success', 'Deleted {count} message(s)', { count: deletedCount })));
                clearSelection();
                loadMessages(); // Reload messages
                loadStats(); // Update statistics
            } else {
                showError(apiError(response, uiT('ui.echomail.bulk_delete.failed', 'Failed to delete messages')));
            }
        },
        error: function(xhr) {
            let errorMessage = uiT('ui.echomail.bulk_delete.failed', 'Failed to delete messages');
            try {
                const response = JSON.parse(xhr.responseText);
                errorMessage = apiError(response, errorMessage);
            } catch (e) {
                // Use default error message
            }
            showError(errorMessage);
        },
        complete: function() {
            deleteBtn.prop('disabled', false).html(originalText);
        }
    });
}

// Mark message as read when viewed
function markMessageAsRead(messageId) {
    $.post(`/api/messages/echomail/${messageId}/read`)
        .done(function() {
            // Update the UI to show message as read
            const messageRow = $(`.message-row[data-message-id="${messageId}"]`);
            messageRow.removeClass('unread').addClass('read');
            messageRow.find('.fa-envelope').removeClass('fas fa-envelope text-primary').addClass('fas fa-envelope-open text-muted');
            messageRow.find('.message-subject strong').contents().unwrap();
            messageRow.css('opacity', '0.85');
        })
        .fail(function() {
            console.log('Failed to mark message as read');
        });
}

function updateMobileAccordionText(selectedArea) {
    const textSpan = $('#mobileAccordionText');
    if (textSpan.length) {
        if (selectedArea) {
            // Strip domain from display (show just the tag)
            const displayTag = selectedArea.includes('@') ? selectedArea.split('@')[0] : selectedArea;
            textSpan.text(`Viewing: ${displayTag}`);
        } else {
            textSpan.text(uiT('ui.echomail.viewing_all_messages', 'Viewing: All Messages'));
        }
    }
}

function updateNavigationButtons() {
    const prevBtn = $('#prevMessageBtn');
    const nextBtn = $('#nextMessageBtn');

    if (currentMessageIndex < 0) {
        prevBtn.prop('disabled', true);
        nextBtn.prop('disabled', true);
        prevBtn.attr('title', uiT('ui.common.previous_message', 'Previous message'));
        nextBtn.attr('title', uiT('ui.common.next_message', 'Next message'));
        return;
    }

    prevBtn.prop('disabled', currentMessageIndex <= 0);
    prevBtn.attr('title', uiT('ui.common.previous_message', 'Previous message'));

    const atEnd = currentMessageIndex >= currentMessages.length - 1;
    if (atEnd) {
        const hasNextPage = currentPagination && currentPagination.page < currentPagination.pages;
        if (hasNextPage) {
            // More pages available — keep enabled, navigateMessage will load the next page
            nextBtn.prop('disabled', false);
            nextBtn.attr('title', uiT('ui.echomail.next_page_title', 'Load next page'));
        } else {
            // At the true end — keep enabled so user can trigger the end-of-echo prompt
            nextBtn.prop('disabled', false);
            nextBtn.attr('title', uiT('ui.echomail.end_of_echo_next_btn_title', 'End of echo'));
        }
    } else {
        nextBtn.prop('disabled', false);
        nextBtn.attr('title', uiT('ui.common.next_message', 'Next message'));
    }
}

function openRequestedMessage() {
    if (!requestedMessageId) {
        return;
    }

    const messageId = requestedMessageId;
    requestedMessageId = null;
    viewMessage(messageId);
}

/**
 * Show an end-of-echo prompt inside the message modal, asking the user
 * whether to continue to the next unread echoarea (or just close).
 *
 * @param {object|null} nextEcho  The next echoarea object, or null if none.
 */
function showEndOfEchoPrompt(nextEcho) {
    const currentDisplayTag = currentEchoarea
        ? (currentEchoarea.includes('@') ? currentEchoarea.split('@')[0] : currentEchoarea)
        : uiT('ui.echomail.echo_list', 'Echo List');

    let bodyHtml = `
        <div class="text-center py-4">
            <div class="mb-3">
                <i class="fas fa-check-circle fa-3x text-success"></i>
            </div>
            <h5>${uiT('ui.echomail.end_of_echo_title', 'End of {echo}').replace('{echo}', escapeHtml(currentDisplayTag))}</h5>`;

    if (nextEcho) {
        const nextDisplayTag = nextEcho.tag.includes('@') ? nextEcho.tag.split('@')[0] : nextEcho.tag;
        // Build full tag including domain so selectEchoarea hits the correct API path
        const nextFullTag = nextEcho.domain ? `${nextEcho.tag}@${nextEcho.domain}` : nextEcho.tag;
        bodyHtml += `
            <p class="text-muted">${uiT('ui.echomail.end_of_echo_next_prompt', 'Continue to {echo}?').replace('{echo}', `<strong>${escapeHtml(nextDisplayTag)}</strong>`)}</p>
            <div class="d-flex justify-content-center gap-2 mt-3">
                <button class="btn btn-primary" onclick="proceedToNextEcho(${escapeHtml(JSON.stringify(nextFullTag))})">
                    <i class="fas fa-arrow-right me-1"></i>${uiT('ui.echomail.end_of_echo_go', 'Go to {echo}').replace('{echo}', escapeHtml(nextDisplayTag))}
                </button>
                <button class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>${uiT('ui.common.close', 'Close')}
                </button>
            </div>`;
    } else {
        bodyHtml += `
            <p class="text-muted">${uiT('ui.echomail.end_of_echo_no_next', 'You have no more unread messages.')}</p>
            <div class="d-flex justify-content-center mt-3">
                <button class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>${uiT('ui.common.close', 'Close')}
                </button>
            </div>`;
    }

    bodyHtml += `</div>`;

    // Replace modal body content with the prompt
    $('#messageContent').html(bodyHtml);

    // Update modal title to reflect end-of-echo state
    $('#messageSubject').text(uiT('ui.echomail.end_of_echo_title', 'End of {echo}').replace('{echo}', currentDisplayTag));

    // Disable nav and toolbar buttons — no message is displayed
    $('#prevMessageBtn').prop('disabled', true);
    $('#nextMessageBtn').prop('disabled', true);
    $('#editMessageButton, #toggleHeaders, #printMessageButton').prop('disabled', true);
}

/**
 * Navigate to the next echoarea after the user confirms the end-of-echo prompt.
 */
function proceedToNextEcho(tag) {
    modalClosedByBackButton = true;
    $('#messageModal').one('hidden.bs.modal', function() {
        selectEchoarea(tag);
    });
    $('#messageModal').modal('hide');
}

/**
 * Find the next echoarea with unread messages after the currently selected one.
 * Returns the echoarea object from allEchoareas, or null if none found.
 */
function findNextUnreadEcho() {
    if (!allEchoareas || allEchoareas.length === 0) return null;

    // currentEchoarea may be "TAG@domain"; area.tag from the API is bare "TAG".
    // Normalise both to bare tag for comparison.
    const bareCurrentTag = currentEchoarea ? currentEchoarea.split('@')[0] : null;
    let foundCurrent = (bareCurrentTag === null); // if "All Messages", start from beginning

    for (let i = 0; i < allEchoareas.length; i++) {
        const area = allEchoareas[i];
        const bareAreaTag = (area.tag || '').split('@')[0];
        if (!foundCurrent) {
            if (bareAreaTag === bareCurrentTag) foundCurrent = true;
            continue;
        }
        if ((area.unread_count || 0) > 0 && (area.message_count || 0) > 0) {
            return area;
        }
    }
    return null;
}

function updateModalTitle(subject) {
    const position = currentMessages.length > 0 ? `${currentMessageIndex + 1} of ${currentMessages.length}` : '';
    const titleText = subject || uiT('messages.no_subject', '(No Subject)');

    if (position) {
        $('#messageSubject').html(`${escapeHtml(titleText)} <small class="text-muted">(${position})</small>`);
    } else {
        $('#messageSubject').text(titleText);
    }
}

function setupModalSwipeNavigation() {
    let touchStartX = 0;
    let touchStartY = 0;
    let touchEndX = 0;
    let touchEndY = 0;
    let isDragging = false;
    let startElement = null;
    let startScrollableElement = null;
    let startScrollLeft = 0;

    const modal = document.getElementById('messageModal');

    // Touch start
    modal.addEventListener('touchstart', function(e) {
        // Only handle if modal is visible
        if (!$('#messageModal').hasClass('show')) return;

        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        startElement = e.target;
        isDragging = false;

        // Snapshot the scrollLeft of the nearest scrollable parent at touch start.
        // By touchend the browser will have already scrolled the element, so we
        // need this value to correctly determine whether the gesture was a scroll
        // within content or a navigation swipe.
        startScrollableElement = null;
        startScrollLeft = 0;
        let el = startElement;
        while (el && el !== modal) {
            if (el.scrollWidth > el.clientWidth) {
                const ox = window.getComputedStyle(el).overflowX;
                if (ox === 'auto' || ox === 'scroll') {
                    startScrollableElement = el;
                    startScrollLeft = el.scrollLeft;
                    break;
                }
            }
            el = el.parentElement;
        }
    }, { passive: true });

    // Touch move - track dragging to avoid accidental swipes during scrolling
    modal.addEventListener('touchmove', function(e) {
        if (!$('#messageModal').hasClass('show')) return;

        const currentX = e.touches[0].clientX;
        const currentY = e.touches[0].clientY;

        const deltaX = Math.abs(currentX - touchStartX);
        const deltaY = Math.abs(currentY - touchStartY);

        // If we're moving more horizontally than vertically, we might be swiping
        if (deltaX > deltaY && deltaX > 10) {
            isDragging = true;
        }
    }, { passive: true });

    // Touch end - determine if it was a swipe
    modal.addEventListener('touchend', function(e) {
        if (!$('#messageModal').hasClass('show')) return;

        touchEndX = e.changedTouches[0].clientX;
        touchEndY = e.changedTouches[0].clientY;

        handleSwipe();
    }, { passive: true });

    function handleSwipe() {
        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;
        const absDeltaX = Math.abs(deltaX);
        const absDeltaY = Math.abs(deltaY);

        // Minimum swipe distance (in pixels) - increased for better distinction
        const minSwipeDistance = 80;

        // Require significantly more horizontal than vertical movement
        const horizontalRatio = 2.5;

        // Must be significantly more horizontal than vertical movement
        // And must exceed minimum distance
        if (absDeltaX > (absDeltaY * horizontalRatio) && absDeltaX > minSwipeDistance) {
            // If the touch started within a horizontally scrollable element, use the
            // scroll position captured at touchstart (not the current position, which
            // has already been updated by native scrolling during touchmove).
            if (startScrollableElement) {
                const swipeDirection = deltaX > 0 ? -1 : 1;
                const atBoundary = swipeDirection < 0
                    ? startScrollLeft <= 0
                    : startScrollLeft + startScrollableElement.clientWidth >= startScrollableElement.scrollWidth - 1;
                if (!atBoundary) {
                    // User was scrolling content, not navigating
                    resetValues();
                    return;
                }
            }

            // Trigger navigation
            if (deltaX > 0) {
                // Swipe right - go to previous message
                navigateMessage(-1);
            } else {
                // Swipe left - go to next message
                navigateMessage(1);
            }
        }

        resetValues();
    }

    function resetValues() {
        touchStartX = 0;
        touchStartY = 0;
        touchEndX = 0;
        touchEndY = 0;
        isDragging = false;
        startElement = null;
        startScrollableElement = null;
        startScrollLeft = 0;
    }
}

function navigateMessage(direction) {
    if (currentMessages.length === 0) return;

    const newIndex = currentMessageIndex + direction;

    // Check bounds
    if (newIndex < 0) return;

    if (newIndex >= currentMessages.length) {
        if (direction > 0) {
            // More pages available — load next page and open its first message
            if (currentPagination && currentPagination.page < currentPagination.pages) {
                currentPage = currentPagination.page + 1;
                loadMessages(function() {
                    if (currentMessages.length > 0) {
                        viewMessage(currentMessages[0].id);
                    }
                });
                return;
            }

            // No more pages — show end-of-echo prompt
            const nextEcho = findNextUnreadEcho();
            showEndOfEchoPrompt(nextEcho);
        }
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
    markEchomailAsRead(newMessage.id);

    // Show loading
    $('#messageContent').html(`
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin me-2"></i>
            ${uiT('ui.common.loading_message', 'Loading message...')}
        </div>
    `);

    // Load the new message
    let apiUrl;
    if (currentEchoarea) {
        apiUrl = `/api/messages/echomail/${encodeURIComponent(currentEchoarea)}/${newMessage.id}`;
    } else {
        apiUrl = `/api/messages/echomail/message/${newMessage.id}`;
    }

    $.get(apiUrl)
        .done(function(data) {
            displayMessageContent(data);
            // Auto-scroll to top of modal content
            $('#messageModal .modal-body').scrollTop(0);
        })
        .fail(function() {
            $('#messageContent').html(`<div class="text-danger">${uiT('errors.messages.echomail.get_failed', 'Failed to load message')}</div>`);
        });
}


// Edit message (admin)
function openEditMessage() {
    if (!window.isAdmin || !currentMessageData) return;
    const msg = currentMessageData;

    const dbId = currentMessageId || msg.id;
    $('#editMessageModalTitle').html(`<i class="fas fa-pencil-alt me-2"></i>${uiT('ui.echomail.edit_message', 'Edit Message')} #${dbId}`);
    $('#editMsgDbId').text(dbId);
    $('#editMsgId').text(msg.message_id || '');
    $('#editMsgDate').text(formatFullDate(msg.date_written));
    $('#editMsgFrom').text((msg.from_name || '') + (msg.from_address ? ' <' + msg.from_address + '>' : ''));
    $('#editMsgSubject').text(msg.subject || '');
    $('#editArtFormat').val(msg.art_format || '');
    const editCharsetVal = msg.message_charset || 'UTF-8';
    const $editCharsetSel = $('#editCharset');
    $editCharsetSel.find('option.unknown-charset').remove();
    if ($editCharsetSel.find('option[value="' + editCharsetVal + '"]').length === 0) {
        $editCharsetSel.prepend('<option value="' + editCharsetVal + '" class="unknown-charset">' + editCharsetVal + ' (unknown)</option>');
    }
    $editCharsetSel.val(editCharsetVal);
    $('#editMessageError').addClass('d-none');
    $('#editMessageSuccess').addClass('d-none');
    $('#saveEditMessageBtn').prop('disabled', false);

    $('#editMessageModal').modal('show');
}

function saveEditMessage() {
    if (!window.isAdmin || !currentMessageData) return;

    const artFormat = $('#editArtFormat').val();
    const charset   = $('#editCharset').val().trim();

    $('#editMessageError').addClass('d-none');
    $('#editMessageSuccess').addClass('d-none');
    $('#saveEditMessageBtn').prop('disabled', true);

    $.ajax({
        url: `/api/messages/echomail/${currentMessageId}/edit`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ art_format: artFormat, message_charset: charset }),
    }).done(function() {
        // Update local cached data so the list badge reflects the change immediately
        currentMessageData.art_format     = artFormat || null;
        currentMessageData.message_charset = charset || null;
        const listMsg = currentMessages.find(m => m.id == currentMessageId);
        if (listMsg) {
            listMsg.art_format = artFormat || null;
        }
        // Refresh the list row
        displayMessages(currentMessages, currentMessages.some(m => m.thread_level > 0));
        $('#editMessageSuccess').removeClass('d-none');
        $('#saveEditMessageBtn').prop('disabled', false);
    }).fail(function(xhr) {
        const payload = xhr.responseJSON || {};
        $('#editMessageError').text(window.getApiErrorMessage ? window.getApiErrorMessage(payload, uiT('errors.messages.echomail.edit.save_failed', 'Failed to save changes')) : (payload.error || uiT('errors.messages.echomail.edit.save_failed', 'Failed to save changes'))).removeClass('d-none');
        $('#saveEditMessageBtn').prop('disabled', false);
    });
}

// Sharing functionality
function showShareDialog(messageId) {
    currentMessageId = messageId;

    // Reset modal state
    $('#shareResult').addClass('d-none');
    $('#shareError').addClass('d-none');
    $('#shareExpiryInfo').hide();
    $('#shareAccessInfo').hide();
    $('#shareExpirySection').show(); // Show expiry dropdown for new shares
    $('#createShareBtn').removeClass('d-none');
    $('#friendlyUrlBtn').addClass('d-none');
    $('#revokeShareBtn').addClass('d-none');
    $('#publicShare').prop('checked', true);
    $('#shareExpiry').val('');

    // Check if message is already shared
    $.get(`/api/messages/echomail/${messageId}/shares`)
        .done(function(data) {
            if (data.shares && data.shares.length > 0) {
                // Show existing share
                const share = data.shares[0];
                $('#shareUrl').val(share.share_url);
                $('#shareResult').removeClass('d-none');
                $('#shareExpirySection').hide(); // Hide expiry dropdown for existing shares
                $('#createShareBtn').addClass('d-none');
                if (!share.has_friendly_url) {
                    $('#friendlyUrlBtn').removeClass('d-none');
                }
                $('#revokeShareBtn').removeClass('d-none');
                $('#publicShare').prop('checked', share.is_public);

                // Show expiry information
                if (share.expires_at) {
                    const expiresDate = new Date(share.expires_at);
                    const now = new Date();
                    const createdDate = new Date(share.created_at);

                    // Calculate time remaining
                    if (expiresDate > now) {
                        const diffMs = expiresDate - now;
                        const diffHours = Math.ceil(diffMs / (1000 * 60 * 60));
                        const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

                        let expiryText;
                        if (diffHours < 24) {
                            expiryText = `Expires in ${diffHours} hour${diffHours !== 1 ? 's' : ''}`;
                        } else {
                            const userDateFormat = window.userSettings?.date_format || 'en-US';
                            expiryText = `Expires in ${diffDays} day${diffDays !== 1 ? 's' : ''} (${expiresDate.toLocaleString(userDateFormat)})`;
                        }
                        $('#shareExpiryText').text(expiryText);
                        $('#shareExpiryInfo').show().removeClass('alert-warning').addClass('alert-info');
                    } else {
                        $('#shareExpiryText').text('This share link has expired');
                        $('#shareExpiryInfo').show().removeClass('alert-info').addClass('alert-warning');
                    }

                    // Set expiry dropdown to match current settings for editing
                    const diffHours = Math.round((expiresDate - createdDate) / (1000 * 60 * 60));
                    $('#shareExpiry').val(diffHours.toString());
                } else {
                    $('#shareExpiryText').text('This link never expires');
                    $('#shareExpiryInfo').show().removeClass('alert-warning').addClass('alert-info');
                    $('#shareExpiry').val('');
                }

                // Show access information
                const accessCount = share.access_count || 0;
                const userDateFormat = window.userSettings?.date_format || 'en-US';
                const lastAccessed = share.last_accessed_at ? new Date(share.last_accessed_at).toLocaleString(userDateFormat) : 'Never';
                $('#shareAccessText').text(`Accessed ${accessCount} time${accessCount !== 1 ? 's' : ''}. Last accessed: ${lastAccessed}`);
                $('#shareAccessInfo').show();
            }
            $('#shareModal').modal('show');
        })
        .fail(function() {
            showError(uiT('ui.echomail.shares.check_failed', 'Failed to check existing shares'));
        });
}

function createShare() {
    const publicShare = $('#publicShare').is(':checked');
    const expiryHours = $('#shareExpiry').val();

    // Disable button and show loading
    const btn = $('#createShareBtn');
    const originalText = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Creating...');

    // Hide previous errors
    $('#shareError').addClass('d-none');

    $.ajax({
        url: `/api/messages/echomail/${currentMessageId}/share`,
        method: 'POST',
        data: JSON.stringify({
            public: publicShare,
            expires_hours: expiryHours || null
        }),
        contentType: 'application/json',
        success: function(data) {
            if (data.success) {
                $('#shareUrl').val(data.share_url);
                $('#shareResult').removeClass('d-none');
                $('#createShareBtn').addClass('d-none');
                $('#revokeShareBtn').removeClass('d-none');

                if (data.existing) {
                    showSuccess(uiT('ui.echomail.shares.using_existing', 'Using existing share link'));
                } else {
                    showSuccess(uiT('ui.echomail.shares.created_success', 'Share link created successfully!'));
                }
            } else {
                $('#shareErrorMessage').text(apiError(data, uiT('errors.messages.share_create_failed', 'Failed to create share link')));
                $('#shareError').removeClass('d-none');
            }
        },
        error: function(xhr) {
            let errorMessage = uiT('errors.messages.share_create_failed', 'Failed to create share link');
            try {
                const response = JSON.parse(xhr.responseText);
                errorMessage = apiError(response, errorMessage);
            } catch (e) {
                // Use default error message
            }
            $('#shareErrorMessage').text(errorMessage);
            $('#shareError').removeClass('d-none');
        },
        complete: function() {
            btn.prop('disabled', false).html(originalText);
        }
    });
}

function generateFriendlyUrl() {
    const btn = $('#friendlyUrlBtn');
    const originalHtml = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Generating...');

    $.post(`/api/messages/echomail/${currentMessageId}/share/friendly-url`)
        .done(function(data) {
            if (data.success) {
                $('#shareUrl').val(data.share_url);
                $('#friendlyUrlBtn').addClass('d-none');
                showSuccess(uiT('ui.echomail.shares.friendly_url_generated', 'Friendly URL generated!'));
            } else {
                showError(apiError(data, uiT('ui.echomail.shares.friendly_url_failed', 'Failed to generate friendly URL')));
                btn.prop('disabled', false).html(originalHtml);
            }
        })
        .fail(function() {
            showError(uiT('ui.echomail.shares.friendly_url_failed', 'Failed to generate friendly URL'));
            btn.prop('disabled', false).html(originalHtml);
        });
}

function revokeShare() {
    if (!confirm(uiT('ui.echomail.shares.revoke_confirm', 'Are you sure you want to revoke this share link? It will no longer be accessible to others.'))) {
        return;
    }

    const btn = $('#revokeShareBtn');
    const originalText = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Revoking...');

    $.ajax({
        url: `/api/messages/echomail/${currentMessageId}/share`,
        method: 'DELETE',
        success: function(data) {
            if (data.success) {
                $('#shareResult').addClass('d-none');
                $('#createShareBtn').removeClass('d-none');
                $('#revokeShareBtn').addClass('d-none');
                showSuccess(uiT('ui.echomail.shares.revoked', 'Share link revoked'));
            } else {
                showError(apiError(data, uiT('errors.messages.share_revoke_failed', 'Failed to revoke share link')));
            }
        },
        error: function() {
            showError(uiT('errors.messages.share_revoke_failed', 'Failed to revoke share link'));
        },
        complete: function() {
            btn.prop('disabled', false).html(originalText);
        }
    });
}

function copyShareUrl() {
    const shareUrl = $('#shareUrl').val();

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(shareUrl).then(function() {
            showSuccess(uiT('ui.echomail.shares.url_copied', 'Share URL copied to clipboard!'));

            // Briefly highlight the input field
            $('#shareUrl').select();
        }).catch(function() {
            // Fallback for older browsers
            fallbackCopyTextToClipboard(shareUrl);
        });
    } else {
        // Fallback for older browsers
        fallbackCopyTextToClipboard(shareUrl);
    }
}

function fallbackCopyTextToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;

    // Avoid scrolling to bottom
    textArea.style.top = '0';
    textArea.style.left = '0';
    textArea.style.position = 'fixed';

    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
        const successful = document.execCommand('copy');
        if (successful) {
            showSuccess(uiT('ui.echomail.shares.url_copied', 'Share URL copied to clipboard!'));
        } else {
            showError(uiT('ui.common.copy_failed_manual', 'Copy to clipboard failed. Please copy manually.'));
        }
    } catch (err) {
        showError(uiT('ui.common.copy_not_supported_manual', 'Copy to clipboard not supported. Please copy manually.'));
    }

    document.body.removeChild(textArea);
}

// Event handlers for share modal
$(document).ready(function() {
    $('#createShareBtn').on('click', createShare);
    $('#friendlyUrlBtn').on('click', generateFriendlyUrl);
    $('#revokeShareBtn').on('click', revokeShare);

    // Reset modal when closed
    $('#shareModal').on('hidden.bs.modal', function() {
        $('#shareResult').addClass('d-none');
        $('#shareError').addClass('d-none');
        $('#createShareBtn').removeClass('d-none');
        $('#friendlyUrlBtn').addClass('d-none').prop('disabled', false).html('<i class="fas fa-link"></i> Get Friendly URL');
        $('#revokeShareBtn').addClass('d-none');
    });
});

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
                let description = 'Added from echomail message';
                if (originalFromName && originalFromAddress) {
                    if (fromName !== originalFromName || fromAddress !== originalFromAddress) {
                        // Using REPLYTO data
                        description = `Added from echomail message. REPLYTO: ${fromName} (${fromAddress}), Original sender: ${originalFromName} (${originalFromAddress})`;
                    } else {
                        // Using original sender data
                        description = `Added from echomail message. Sender: ${originalFromName} (${originalFromAddress})`;
                    }
                }

                const data = {
                    name: originalFromName, // Use original from_name for descriptive name (e.g., "Aug")
                    messaging_user_id: fromName, // Use REPLYTO name for messaging
                    node_address: fromAddress, // Use REPLYTO address for messaging
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

                            // Refresh address book in sidebar if it exists (for netmail page)
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

// User settings functions - apply echomail-specific settings after loading
function loadEchomailSettings() {
    const settingsPromise = typeof window.loadUserSettings === 'function'
        ? window.loadUserSettings().then(function() {
            // Apply echomail-specific settings
            userSettings = window.userSettings;

            if (userSettings.threaded_view !== undefined) {
                threadedView = userSettings.threaded_view;
                const toggleText = $('#threadingToggleText');
                if (threadedView) {
                    toggleText.text('Show Flat');
                } else {
                    toggleText.text('Show Threaded');
                }
            }

            if (userSettings.default_sort) {
                currentSort = userSettings.default_sort;
            }
            updateSortIndicator();
        })
        : Promise.resolve();

    // Load per-area page positions from DB (only if setting is enabled)
    const positionsPromise = $.get('/api/user/web-mail-state')
        .then(function(data) {
            if (!window.userSettings || !window.userSettings.remember_page_position) return;
            if (data && data.settings && data.settings.web_echomail_positions) {
                try {
                    const parsed = typeof data.settings.web_echomail_positions === 'string'
                        ? JSON.parse(data.settings.web_echomail_positions)
                        : data.settings.web_echomail_positions;
                    if (parsed && typeof parsed === 'object') {
                        echoPageMemory = parsed;
                    }
                } catch (e) {}
            }
        })
        .catch(function() {});

    return Promise.all([settingsPromise, positionsPromise]);
}

/**
 * Persist the current echoPageMemory to the DB (fire-and-forget).
 */
function saveEchoPositions() {
    if (!window.userSettings || !window.userSettings.remember_page_position) return;
    $.ajax({
        url: '/api/user/web-mail-state',
        method: 'POST',
        data: JSON.stringify({ web_echomail_positions: echoPageMemory }),
        contentType: 'application/json'
    });
}

// Use global settings functions directly - no local wrappers needed
// All calls to saveUserSetting and saveUserSettings will use window.* functions

// Draft management functions
function continueDraft(draftId) {
    // Navigate to compose page with draft data
    window.location.href = `/compose/echomail?draft=${draftId}`;
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
