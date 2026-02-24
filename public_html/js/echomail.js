let currentPage = 1;
let currentSort = 'date_desc';
let currentMessageId = null;
let currentFilter = 'all';
let modalClosedByBackButton = false;
let threadedView = false;
let userSettings = {};
let currentMessages = [];
let currentMessageIndex = -1;
let currentSearchTerms = [];
let currentMessageData = null;
let allEchoareas = [];
let echoareaSearchQuery = '';
let searchResultCounts = null;
let searchFilterCounts = null;
let originalFilterCounts = null;
let isSearchActive = false;

// Date display configuration: 'written' or 'received'
// TODO: Add user toggle in settings
const USE_DATE_FIELD = 'received';   // related to ECHOMAIL_DATE_FIELD in backend

$(document).ready(function() {
    loadEchomailSettings().then(function() {
        loadEchoareas();

        // Check for search parameter in URL
        const urlParams = new URLSearchParams(window.location.search);
        const searchQuery = urlParams.get('search');

        if (searchQuery) {
            // Populate search input and trigger search
            $('#searchInput').val(searchQuery);
            $('#mobileSearchInput').val(searchQuery);
            searchMessages();
        } else {
            loadMessages();
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
        })
        .fail(function() {
            $('#echoareasList').html('<div class="text-center text-danger p-3">Failed to load echo areas</div>');
            $('#mobileEchoareasList').html('<div class="text-center text-danger p-3">Failed to load echo areas</div>');
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
                        <div class="node-system">All Messages</div>
                        <small class="text-muted">View all echo areas</small>
                    </div>
                    <span class="badge bg-secondary">All</span>
                </div>
            </div>
        `;

        echoareas.forEach(function(area) {
            const fullTag = `${area.tag}@${area.domain}`;
            const isActive = currentEchoarea === fullTag;
            const unreadCount = area.unread_count || 0;
            const totalCount = area.message_count || 0;

            // Use search count if search is active
            let countDisplay;
            if (isSearchActive && area.search_count !== undefined) {
                countDisplay = `<span class="badge bg-info">${area.search_count} found</span>`;
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
        html = '<div class="text-center text-muted p-3">No echo areas available</div>';
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
                        <div class="fw-bold">All Messages</div>
                        <small class="text-muted">View all echo areas</small>
                    </div>
                    <span class="badge bg-secondary">All</span>
                </div>
            </div>
        `;

        echoareas.forEach(function(area) {
            const fullTag = `${area.tag}@${area.domain}`;
            const isActive = currentEchoarea === fullTag;
            const unreadCount = area.unread_count || 0;
            const totalCount = area.message_count || 0;

            // Use search count if search is active
            let countDisplay;
            if (isSearchActive && area.search_count !== undefined) {
                countDisplay = `<span class="badge bg-info">${area.search_count} found</span>`;
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
        html = '<div class="text-center text-muted p-3">No echo areas available</div>';
    }

    container.html(html);
}

function selectEchoarea(tag) {
    currentEchoarea = tag;
    currentPage = 1;

    // Update URL without page reload
    const url = tag ? `/echomail/${encodeURIComponent(tag)}` : '/echomail';
    history.pushState({echoarea: tag}, '', url);

    // Update title - strip domain for display
    const displayTag = tag && tag.includes('@') ? tag.split('@')[0] : tag;
    const title = displayTag ? `Echomail - ${displayTag}` : 'Echomail';
    $('h2 small').remove();
    if (displayTag) {
        $('h2').append(`<small class="text-muted">/ ${displayTag}</small>`);
    }

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

function loadMessages() {
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
            displayMessages(data.messages, data.threaded || false);
            updatePagination(data.pagination);
            updateUnreadCount(data.unreadCount || 0);
            // Refresh stats to get updated filter counts
            loadStats();
        })
        .fail(function() {
            $('#messagesContainer').html('<div class="text-center text-danger py-4">Failed to load messages</div>');
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
                $('#messagesContainer').html('<div class="text-center text-danger py-4">Failed to load drafts</div>');
            }
        })
        .fail(function() {
            $('#messagesContainer').html('<div class="text-center text-danger py-4">Failed to load drafts</div>');
        });
}

function displayDrafts(drafts) {
    const container = $('#messagesContainer');
    let html = '';

    if (drafts.length === 0) {
        html = '<div class="text-center text-muted py-4">No drafts found</div>';
    } else {
        // Create table structure
        html = `
            <div class="table-responsive">
                <table class="table table-hover message-table mb-0">
                    <thead>
                        <tr>
                            <th style="width: 25%">To / Echo Area</th>
                            <th style="width: 50%">Subject</th>
                            <th colspan="2" style="width: 25%">Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        drafts.forEach(function(draft) {
            const displayTarget = draft.to_name || 'All';
            const displayArea = draft.echoarea || 'No area';

            html += `
                <tr class="message-row" style="cursor: pointer;" onclick="continueDraft(${draft.id})">
                    <td>
                        <div><strong>To:</strong> ${escapeHtml(displayTarget)}</div>
                        <div class="text-muted small"><strong>Area:</strong> ${escapeHtml(displayArea)}</div>
                    </td>
                    <td>
                        <strong>${escapeHtml(draft.subject || '(No Subject)')}</strong>
                        ${draft.message_text ? `<br><small class="text-muted">${escapeHtml(draft.message_text.substring(0, 100))}${draft.message_text.length > 100 ? '...' : ''}</small>` : ''}
                    </td>
                    <td>
                        <div>${formatFullDate(draft.updated_at)}</div>
                        <div class="text-muted small">
                            <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteDraftConfirm(${draft.id})" title="Delete draft">
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
        html = '<div class="text-center text-muted py-4">No messages found</div>';
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
                            <th style="width: 25%">From</th>
                            <th style="width: 60%">Subject</th>
                            <th colspan="2" style="width: 15%">Received</th>

                        </tr>
                    </thead>
                    <tbody>
        `;

        messages.forEach(function(msg) {
            // Check if message is addressed to current user
            const isToCurrentUser = msg.to_name && window.currentUserRealName && msg.to_name === window.currentUserRealName;
            const toInfo = msg.to_name && msg.to_name !== 'All' ?
                ` (to: <span class="${isToCurrentUser ? 'text-green fw-bold' : ''}">${escapeHtml(msg.to_name)}</span>)` : '';
            const isRead = msg.is_read == 1;
            const isShared = msg.is_shared == 1;
            const isSaved = msg.is_saved == 1;
            const readClass = isRead ? 'read' : 'unread';
            const readIcon = isRead ? '<i class="fas fa-envelope-open text-muted me-1" title="Read"></i>' : '<i class="fas fa-envelope text-primary me-1" title="Unread"></i>';
            const shareIcon = isShared ? '<i class="fas fa-share-alt text-success me-1" title="Shared"></i>' : '';
            const saveIcon = `<i class="fas fa-bookmark ${isSaved ? 'text-warning' : 'text-muted'} me-1 save-btn"
                                 data-message-id="${msg.id}"
                                 data-message-type="echomail"
                                 data-saved="${isSaved}"
                                 title="${isSaved ? 'Remove from saved' : 'Save for later'}"
                                 style="cursor: pointer;"
                                 onclick="toggleSaveMessage(${msg.id}, 'echomail', ${isSaved})"></i>`;

            // Threading support
            const threadLevel = msg.thread_level || 0;
            const replyCount = msg.reply_count || 0;
            const isThreadRoot = msg.is_thread_root || false;
            const threadIcon = threadLevel > 0 ? '<i class="fas fa-reply me-1 text-muted" title="Reply"></i>' : '';
            const replyCountBadge = isThreadRoot && replyCount > 0 ? ` <span class="badge bg-secondary ms-1" title="${replyCount} replies">${replyCount}</span>` : '';

            // Add thread-specific CSS classes
            const threadClasses = isThreaded ? `thread-level-${threadLevel} ${isThreadRoot ? 'thread-root' : 'thread-reply'}` : '';

            html += `
                <tr class="message-row ${readClass} ${threadClasses}" data-message-id="${msg.id}">
                    <td class="message-checkbox d-none">
                        <div class="form-check">
                            <input class="form-check-input message-select" type="checkbox" value="${msg.id}" onchange="updateSelection()">
                        </div>
                    </td>
                    <td class="message-from clickable-cell" onclick="viewMessage(${msg.id})" style="cursor: pointer;">
                        ${threadIcon}${readIcon}${shareIcon}${saveIcon}<a href="/compose/netmail?to=${encodeURIComponent((msg.replyto_address && msg.replyto_address !== '') ? msg.replyto_address : msg.from_address)}&to_name=${encodeURIComponent((msg.replyto_name && msg.replyto_name !== '') ? msg.replyto_name : msg.from_name)}&subject=${encodeURIComponent('Re: ' + (msg.subject || ''))}" class="text-decoration-none" onclick="event.stopPropagation()" title="Send netmail to ${escapeHtml(msg.from_name)}">${escapeHtml(msg.from_name)}</a>
                    </td>
                    <td class="message-subject clickable-cell" onclick="viewMessage(${msg.id})" style="cursor: pointer;">
                        ${!currentEchoarea ? `<div class="mb-1">
                            <span class="badge" style="background-color: ${msg.echoarea_color || '#28a745'}; color: white;">${msg.echoarea}</span>
                            ${msg.echoarea_domain ? `<span class="badge bg-secondary ms-1" style="font-size: 0.7em;">${msg.echoarea_domain}</span>` : ''}
                        </div>` : ''}
                        ${isRead ? '' : '<strong>'}${escapeHtml(msg.subject || '(No Subject)')}${isRead ? '' : '</strong>'}${replyCountBadge}
                        ${toInfo ? `<br><small class="text-muted">${toInfo}</small>` : ''}
                    </td>
                    <td class="message-date clickable-cell" onclick="viewMessage(${msg.id})" style="cursor: pointer;" title="${USE_DATE_FIELD === 'written' ? 'Received: ' + formatFullDate(msg.date_received) : 'Written: ' + formatFullDate(msg.date_written)}">${formatDate(USE_DATE_FIELD === 'written' ? msg.date_written : msg.date_received)}</td>
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
    const container = $('#pagination');
    let html = '';

    if (pagination.pages > 1) {
        html = '<ul class="pagination pagination-sm mb-0">';

        // Previous button
        if (pagination.page > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${pagination.page - 1})">Previous</a></li>`;
        }

        // Page numbers (show max 5 pages)
        let startPage = Math.max(1, pagination.page - 2);
        let endPage = Math.min(pagination.pages, startPage + 4);

        if (endPage - startPage < 4) {
            startPage = Math.max(1, endPage - 4);
        }

        for (let i = startPage; i <= endPage; i++) {
            const active = i === pagination.page ? 'active' : '';
            html += `<li class="page-item ${active}"><a class="page-link" href="#" onclick="changePage(${i})">${i}</a></li>`;
        }

        // Next button
        if (pagination.page < pagination.pages) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${pagination.page + 1})">Next</a></li>`;
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

    // Save sort preference
    window.saveUserSetting('default_sort', sortBy);

    loadMessages();
}

function refreshMessages() {
    loadMessages();
    showSuccess('Messages refreshed');
}

function toggleThreading() {
    threadedView = !threadedView;
    currentPage = 1; // Reset to first page when toggling

    // Update toggle text
    const toggleText = $('#threadingToggleText');
    if (threadedView) {
        toggleText.text('Show Flat');
    } else {
        toggleText.text('Show Threaded');
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
            Loading message...
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
            $('#messageContent').html('<div class="text-danger">Failed to load message</div>');
        });
}

function displayMessageContent(message) {
    updateModalTitle(message.subject);

    // Parse message to separate kludge lines from body
    const parsedMessage = parseEchomailMessage(message.message_text || '', message.kludge_lines || '', message.bottom_kludges || null);
    currentMessageData = message;

    // Check if sender is already in address book before rendering
    checkAndDisplayEchomailMessage(message, parsedMessage);
}

function downloadCurrentMessage() {
    if (!currentMessageId || !currentMessageData) {
        return;
    }

    window.location.href = `/api/messages/echomail/${encodeURIComponent(currentMessageId)}/download`;
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

function renderEchomailMessageContent(message, parsedMessage, isInAddressBook) {
    let addressBookButton;
    if (isInAddressBook) {
        addressBookButton = `
            <button class="btn btn-sm btn-outline-secondary ms-2" id="saveAddressBookBtn" disabled title="Already in address book">
                <i class="fas fa-check"></i> <i class="fas fa-address-book"></i>
            </button>
        `;
    } else {
        // Use REPLYTO priority for address book save
        const replyToAddress = message.replyto_address || message.from_address;
        const replyToName = message.replyto_name || message.from_name;

        addressBookButton = `
            <button class="btn btn-sm btn-outline-success ms-2" id="saveAddressBookBtn" onclick="saveToAddressBook('${escapeHtml(replyToName)}', '${escapeHtml(replyToAddress)}', '${escapeHtml(message.from_name)}', '${escapeHtml(message.from_address)}')" title="Save to address book">
                <i class="fas fa-address-book"></i>
            </button>
        `;
    }

    const html = `
        <div class="message-header-full mb-3">
            <div class="row">
                <div class="col-md-4">
                    <strong>From:</strong> <a href="/compose/netmail?to=${encodeURIComponent((message.replyto_address && message.replyto_address !== '') ? message.replyto_address : message.from_address)}&to_name=${encodeURIComponent((message.replyto_name && message.replyto_name !== '') ? message.replyto_name : message.from_name)}&subject=${encodeURIComponent('Re: ' + (message.subject || ''))}" class="text-decoration-none" title="Send netmail to ${escapeHtml(message.from_name)}">${escapeHtml(message.from_name)}</a>
                    <small class="text-muted ms-2">${formatFidonetAddress(message.from_address)}</small>
                    ${addressBookButton}
                </div>
                <div class="col-md-4">
                    <strong>To:</strong> ${escapeHtml(message.to_name || 'All')}
                </div>
                <div class="col-md-4">
                    <strong>Area:</strong> ${escapeHtml(message.echoarea)}${message.domain ? '@' + escapeHtml(message.domain) : ''}
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-4">
                    <strong>Date:</strong> ${formatFullDate(message.date_written)}
                </div>
                <div class="col-md-8">
                    <strong>Subject:</strong> ${escapeHtml(message.subject || '(No Subject)')}
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12 text-end">
                    <i class="fas fa-bookmark modal-header-save-icon ${message.is_saved == 1 ? 'text-warning' : 'text-muted'}"
                       id="modalHeaderSaveIcon"
                       style="cursor: pointer;"
                       title="${message.is_saved == 1 ? 'Remove from saved' : 'Save for later'}"></i>
                    ${message.is_shared == 1 ? '<i class="fas fa-share-alt text-success ms-2" title="Shared"></i>' : ''}
                </div>
            </div>
        </div>

        <div class="message-headers mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0 text-muted">Kludge Lines</h6>
                <button class="btn btn-sm btn-outline-secondary" id="toggleHeaders" onclick="toggleKludgeLines()">
                    <i class="fas fa-eye-slash" id="toggleIcon"></i>
                    <span id="toggleText">Show Kludge Lines</span>
                </button>
            </div>
            <div id="kludgeContainer" class="kludge-lines" style="display: none;">
                <pre class="bg-dark text-light p-3 rounded small">${formatKludgeLinesWithSeparator(parsedMessage.topKludges || parsedMessage.kludgeLines, parsedMessage.bottomKludges || [])}</pre>
            </div>
        </div>

        <div class="message-text">
            ${formatMessageText(parsedMessage.messageBody)}
        </div>
        ${message.origin_line ? `<div class="message-origin mt-2"><small class="text-muted">${escapeHtml(message.origin_line)}</small></div>` : ''}
    `;

    $('#messageContent').html(html);

    // Update save button state AFTER HTML is inserted
    updateModalSaveButton(message);

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
        showError('Please enter at least 2 characters to search');
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
            showError('Search failed');
        });
}

function searchMessagesFromMobile() {
    // Copy mobile search input to desktop input for consistency
    const query = $('#mobileSearchInput').val().trim();
    $('#searchInput').val(query);
    searchMessages();
}

function updateEchoareaCountsWithSearchResults() {
    if (!searchResultCounts) return;

    // Create a map of echoarea counts by tag@domain
    const countMap = {};
    searchResultCounts.forEach(area => {
        const fullTag = `${area.tag}@${area.domain}`;
        countMap[fullTag] = area.message_count;
    });

    // Update the allEchoareas array with search counts
    allEchoareas.forEach(area => {
        const fullTag = `${area.tag}@${area.domain}`;
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
                <i class="fas fa-times me-1"></i> Clear Search
            </button>
        `;
        $('#searchInput').after(clearBtn);
    }

    // Also add to mobile
    if ($('#mobileClearSearchBtn').length === 0) {
        const mobileClearBtn = `
            <button id="mobileClearSearchBtn" class="btn btn-sm btn-secondary w-100 mt-2" onclick="clearSearch()">
                <i class="fas fa-times me-1"></i> Clear Search
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
                    icon.attr('title', 'Save for later');
                    icon.attr('data-saved', 'false');
                    icon.attr('onclick', `toggleSaveMessage(${messageId}, '${messageType}', false)`);
                    showSuccess('Message removed from saved items');
                } else {
                    // Message was saved
                    icon.removeClass('text-muted').addClass('text-warning');
                    icon.attr('title', 'Remove from saved');
                    icon.attr('data-saved', 'true');
                    icon.attr('onclick', `toggleSaveMessage(${messageId}, '${messageType}', true)`);
                    showSuccess('Message saved for later');
                }

                // If we're viewing saved messages, remove the message from view
                if (isSaved && currentFilter === 'saved') {
                    loadMessages();
                }
            } else {
                showError(response.message || 'Failed to update save status');
            }
        },
        error: function(xhr) {
            const response = JSON.parse(xhr.responseText || '{}');
            showError(response.error || 'Failed to update save status');
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
        saveText.text('Saved');
        saveBtn.attr('title', 'Remove from saved');

        // Update header icon
        headerIcon.removeClass('text-muted').addClass('text-warning');
        headerIcon.attr('title', 'Remove from saved');

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
        saveText.text('Save');
        saveBtn.attr('title', 'Save for later');

        // Update header icon
        headerIcon.removeClass('text-warning').addClass('text-muted');
        headerIcon.attr('title', 'Save for later');

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
                    saveText.text('Save');
                    saveBtn.attr('title', 'Save for later');

                    // Update header icon
                    headerIcon.removeClass('text-warning').addClass('text-muted');
                    headerIcon.attr('title', 'Save for later');

                    showSuccess('Message removed from saved items');

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
                    saveText.text('Saved');
                    saveBtn.attr('title', 'Remove from saved');

                    // Update header icon
                    headerIcon.removeClass('text-muted').addClass('text-warning');
                    headerIcon.attr('title', 'Remove from saved');

                    showSuccess('Message saved for later');

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
                        icon.attr('title', 'Save for later');
                        icon.attr('data-saved', 'false');
                        icon.attr('onclick', `toggleSaveMessage(${messageId}, '${messageType}', false)`);
                    } else {
                        icon.removeClass('text-muted').addClass('text-warning');
                        icon.attr('title', 'Remove from saved');
                        icon.attr('data-saved', 'true');
                        icon.attr('onclick', `toggleSaveMessage(${messageId}, '${messageType}', true)`);
                    }
                }

                // If we're viewing saved messages, remove the message from view
                if (isSaved && currentFilter === 'saved') {
                    loadMessages();
                }
            } else {
                showError(response.message || 'Failed to update save status');
            }
        },
        error: function(xhr) {
            const response = JSON.parse(xhr.responseText || '{}');
            showError(response.error || 'Failed to update save status');
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
            $('#totalMessages').text('Error');
            $('#unreadMessages').text('Error');
            $('#recentMessages').text('Error');
            $('#totalAreas').text('Error');
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
        btn.html('<i class="fas fa-times"></i> Cancel');
        btn.removeClass('btn-outline-secondary').addClass('btn-outline-warning');
        checkboxColumn.removeClass('d-none');
        checkboxCells.removeClass('d-none');
        bulkActions.removeClass('d-none');
    } else {
        // Disable select mode
        btn.html('<i class="fas fa-check-square"></i> Select');
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
    if (!window.isAdmin) {
        showError('Admin privileges required to delete echomail messages');
        return;
    }

    if (selectedMessages.size === 0) {
        showError('No messages selected');
        return;
    }

    const count = selectedMessages.size;
    const confirmMessage = `Are you sure you want to delete ${count} selected message${count > 1 ? 's' : ''} for everyone?`;

    if (!confirm(confirmMessage)) {
        return;
    }

    // Convert Set to Array for API call
    const messageIds = Array.from(selectedMessages);

    // Show loading state
    const deleteBtn = $('#bulkActions .btn-outline-danger');
    const originalText = deleteBtn.html();
    deleteBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

    $.ajax({
        url: '/api/messages/echomail/delete',
        type: 'POST',
        data: JSON.stringify({ messageIds: messageIds }),
        contentType: 'application/json',
        success: function(response) {
            if (response.success) {
                showSuccess(response.message);
                clearSelection();
                loadMessages(); // Reload messages
                loadStats(); // Update statistics
            } else {
                showError(response.error || 'Failed to delete messages');
            }
        },
        error: function(xhr) {
            let errorMessage = 'Failed to delete messages';
            try {
                const response = JSON.parse(xhr.responseText);
                errorMessage = response.error || errorMessage;
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
            textSpan.text('Viewing: All Messages');
        }
    }
}

function updateNavigationButtons() {
    const prevBtn = $('#prevMessageBtn');
    const nextBtn = $('#nextMessageBtn');

    // Disable/enable buttons based on current position
    if (currentMessageIndex <= 0) {
        prevBtn.prop('disabled', true);
    } else {
        prevBtn.prop('disabled', false);
    }

    if (currentMessageIndex >= currentMessages.length - 1) {
        nextBtn.prop('disabled', true);
    } else {
        nextBtn.prop('disabled', false);
    }
}

function updateModalTitle(subject) {
    const position = currentMessages.length > 0 ? `${currentMessageIndex + 1} of ${currentMessages.length}` : '';
    const titleText = subject || '(No Subject)';

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

    const modal = document.getElementById('messageModal');

    // Helper function to check if an element or its parents have horizontal scroll
    function hasHorizontalScroll(element) {
        let current = element;
        while (current && current !== modal) {
            const hasOverflow = current.scrollWidth > current.clientWidth;
            const overflowX = window.getComputedStyle(current).overflowX;

            if (hasOverflow && (overflowX === 'auto' || overflowX === 'scroll')) {
                return true;
            }
            current = current.parentElement;
        }
        return false;
    }

    // Helper function to check if element is at scroll boundary
    function isAtScrollBoundary(element, direction) {
        // direction: -1 for left boundary, 1 for right boundary
        if (direction < 0) {
            // Swiping right, check if at left edge
            return element.scrollLeft <= 0;
        } else {
            // Swiping left, check if at right edge
            return element.scrollLeft + element.clientWidth >= element.scrollWidth - 1;
        }
    }

    // Touch start
    modal.addEventListener('touchstart', function(e) {
        // Only handle if modal is visible
        if (!$('#messageModal').hasClass('show')) return;

        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        startElement = e.target;
        isDragging = false;
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

        // Check if touch started on horizontally scrollable content
        const hasHScroll = hasHorizontalScroll(startElement);

        // Must be significantly more horizontal than vertical movement
        // And must exceed minimum distance
        if (absDeltaX > (absDeltaY * horizontalRatio) && absDeltaX > minSwipeDistance) {
            // If the element has horizontal scroll, only trigger swipe at boundaries
            if (hasHScroll) {
                let scrollableElement = startElement;
                while (scrollableElement && scrollableElement !== modal) {
                    if (scrollableElement.scrollWidth > scrollableElement.clientWidth) {
                        const swipeDirection = deltaX > 0 ? -1 : 1;
                        if (!isAtScrollBoundary(scrollableElement, swipeDirection)) {
                            // User is scrolling content, don't trigger swipe
                            resetValues();
                            return;
                        }
                        break;
                    }
                    scrollableElement = scrollableElement.parentElement;
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
    }
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
    markEchomailAsRead(newMessage.id);

    // Show loading
    $('#messageContent').html(`
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin me-2"></i>
            Loading message...
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
            $('#messageContent').html('<div class="text-danger">Failed to load message</div>');
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
            showError('Failed to check existing shares');
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
                    showSuccess('Using existing share link');
                } else {
                    showSuccess('Share link created successfully!');
                }
            } else {
                $('#shareErrorMessage').text(data.error || 'Failed to create share link');
                $('#shareError').removeClass('d-none');
            }
        },
        error: function(xhr) {
            let errorMessage = 'Failed to create share link';
            try {
                const response = JSON.parse(xhr.responseText);
                errorMessage = response.error || errorMessage;
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
                showSuccess('Friendly URL generated!');
            } else {
                showError(data.error || 'Failed to generate friendly URL');
                btn.prop('disabled', false).html(originalHtml);
            }
        })
        .fail(function() {
            showError('Failed to generate friendly URL');
            btn.prop('disabled', false).html(originalHtml);
        });
}

function revokeShare() {
    if (!confirm('Are you sure you want to revoke this share link? It will no longer be accessible to others.')) {
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
                showSuccess('Share link revoked');
            } else {
                showError(data.error || 'Failed to revoke share link');
            }
        },
        error: function() {
            showError('Failed to revoke share link');
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
            showSuccess('Share URL copied to clipboard!');

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
            showSuccess('Share URL copied to clipboard!');
        } else {
            showError('Copy to clipboard failed. Please copy manually.');
        }
    } catch (err) {
        showError('Copy to clipboard not supported. Please copy manually.');
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
                          .attr('title', 'Already in address book')
                          .prop('disabled', true);
                    showError('This contact is already in your address book');
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
                                  .html('<i class="fas fa-check"></i> Saved')
                                  .attr('title', 'Saved to address book')
                                  .prop('disabled', true);

                            showSuccess(`${fromName} added to address book`);

                            // Refresh address book in sidebar if it exists (for netmail page)
                            if (typeof loadAddressBook === 'function') {
                                loadAddressBook();
                            }
                        } else {
                            // Reset button on error
                            button.removeClass('btn-outline-success').addClass('btn-outline-danger')
                                  .html(originalHtml)
                                  .attr('title', 'Error - click to retry')
                                  .prop('disabled', false);
                            showError(response.error || 'Failed to save to address book');
                        }
                    },
                    error: function(xhr) {
                        // Reset button on error
                        button.removeClass('btn-outline-success').addClass('btn-outline-danger')
                              .html(originalHtml)
                              .attr('title', 'Error - click to retry')
                              .prop('disabled', false);
                        const response = xhr.responseJSON;
                        showError(response && response.error ? response.error : 'Failed to save to address book');
                    }
                });
            } else {
                // Reset button on error
                button.html(originalHtml).attr('title', originalTitle).prop('disabled', false);
                showError('Failed to check existing contacts');
            }
        })
        .fail(function() {
            // Reset button on error
            button.html(originalHtml).attr('title', originalTitle).prop('disabled', false);
            showError('Failed to check existing contacts');
        });
}

// User settings functions - apply echomail-specific settings after loading
function loadEchomailSettings() {
    if (typeof window.loadUserSettings === 'function') {
        return window.loadUserSettings().then(function() {
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
        });
    } else {
        // Fallback if global function not available
        return Promise.resolve();
    }
}

// Use global settings functions directly - no local wrappers needed
// All calls to saveUserSetting and saveUserSettings will use window.* functions

// Draft management functions
function continueDraft(draftId) {
    // Navigate to compose page with draft data
    window.location.href = `/compose/echomail?draft=${draftId}`;
}

function deleteDraftConfirm(draftId) {
    if (confirm('Are you sure you want to delete this draft? This cannot be undone.')) {
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
                showSuccess('Draft deleted successfully');
            } else {
                showError('Failed to delete draft');
            }
        },
        error: function() {
            showError('Failed to delete draft');
        }
    });
}
