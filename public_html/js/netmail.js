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
            $('#messagesContainer').html('<div class="text-center text-danger py-4">Failed to load messages</div>');
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
                            <th style="width: 30%">To</th>
                            <th style="width: 45%">Subject</th>
                            <th colspan="2" style="width: 25%">Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        drafts.forEach(function(draft) {
            const displayTo = draft.to_name || 'Unknown';
            const displayAddress = draft.to_address || '';

            html += `
                <tr class="message-row" style="cursor: pointer;" onclick="continueDraft(${draft.id})">
                    <td>
                        <div><strong>${escapeHtml(displayTo)}</strong></div>
                        ${displayAddress ? `<div class="text-muted small">${escapeHtml(displayAddress)}</div>` : ''}
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
    // Store messages for navigation
    currentMessages = messages;

    const container = $('#messagesContainer');
    let html = '';

    if (messages.length === 0) {
        html = '<div class="text-center text-muted py-4">No messages found</div>';
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
                        <th width="27%">From/To</th>
                        <th width="40%">Subject</th>
                        <th width="15%">Address</th>
                        <th width="10%">Received</th>
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
            const threadIndent = threadLevel > 0 ? `style="text-indent: ${threadLevel * 0.5}rem;"` : '';
            const threadIcon = threadLevel > 0 ? '<i class="fas fa-reply me-1 text-muted" title="Reply"></i>' : '';
            const replyCountBadge = isThreadRoot && replyCount > 0 ? ` <span class="badge bg-secondary ms-1" title="${replyCount} replies">${replyCount}</span>` : '';

            // Add thread-specific CSS classes

            html += `
                <tr class="${rowClass} message-row" data-message-id="${msg.id}" onclick="viewMessage(${msg.id})" style="cursor: pointer;">
                    <td class="message-checkbox d-none" onclick="event.stopPropagation()">
                        <div class="form-check">
                            <input class="form-check-input message-select" type="checkbox" value="${msg.id}" onchange="updateSelection()">
                        </div>
                    </td>
                    <td ${threadIndent}>
                        ${isUnread ? '<i class="fas fa-envelope text-primary me-1" title="Unread"></i>' : '<i class="far fa-envelope-open text-muted me-1" title="Read"></i>'}${threadIcon}<strong>${escapeHtml(isSent ? 'To: ' + msg.to_name : msg.from_name)}</strong>
                        <br>
                    </td>
                    <td ${threadIndent}>
                        ${isUnread ? '<strong>' : ''}<span>${escapeHtml(msg.subject || '(No Subject)')}</span>${isUnread ? '</strong>' : ''}${replyCountBadge}
                        <br>
                        <small class="text-muted">
                            <span class="badge bg-secondary">NETMAIL</span>
                            ${isUnread ? '<span class="badge bg-primary ms-1">NEW</span>' : ''}
                            ${msg.received_insecure ? '<span class="badge bg-warning text-dark ms-1" title="Received via insecure session"><i class="fas fa-exclamation-triangle"></i></span>' : ''}
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
                        <button class="btn btn-outline-danger btn-sm" onclick="event.stopPropagation(); deleteMessage(${msg.id})" title="Delete message">
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
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${pagination.page - 1})">Previous</a></li>`;
        }

        // Page numbers
        for (let i = 1; i <= pagination.pages; i++) {
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
            Loading message...
        </div>
    `);

    applyModalFullscreenPreference();
    $('#messageModal').modal('show');

    $.get(`/api/messages/netmail/${messageId}`)
        .done(function(data) {
            displayMessageContent(data);
        })
        .fail(function() {
            $('#messageContent').html('<div class="text-danger">Failed to load message</div>');
        });
}

function displayMessageContent(message) {
    // Check if current user is the sender - use user_id comparison instead of address
    const currentUserId = window.currentUser ? window.currentUser.id : null;
    const isSent = (message.user_id && currentUserId && message.user_id == currentUserId);

    $('#messageSubject').text(message.subject || '(No Subject)');

    // Parse message to separate kludge lines from body (use stored kludge_lines if available)
    const parsedMessage = parseNetmailMessage(message.message_text || '', message.kludge_lines || null, message.bottom_kludges || null);

    // Check if sender is already in address book before rendering
    checkAndDisplayMessage(message, parsedMessage, isSent);
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
            <button class="btn btn-sm btn-outline-secondary ms-2" id="saveAddressBookBtn" disabled title="Already in address book">
                <i class="fas fa-check"></i> <i class="fas fa-address-book"></i>
            </button>
        `;
    } else {
        const replyToAddress = message.replyto_address || message.reply_address || message.original_author_address || message.from_address;
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
                <div class="col-md-6">
                    <strong>From:</strong> ${escapeHtml(message.from_name)}
                    <small class="text-muted ms-2">${formatFidonetAddress(message.from_address)}</small>
                    ${addressBookButton}
                </div>
                <div class="col-md-6">
                    <strong>To:</strong> ${escapeHtml(message.to_name)}
                    <small class="text-muted ms-2">${formatFidonetAddress(message.to_address)}</small>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-6">
                    <strong>Date:</strong> <span title="Sent: ${formatFullDate(message.date_written)}">${formatFullDate(message.date_received)}</span>
                </div>
                <div class="col-md-6">
                    <strong>Subject:</strong> ${escapeHtml(message.subject || '(No Subject)')}
                </div>
            </div>
            ${message.received_insecure ? `
            <div class="row mt-2">
                <div class="col-12">
                    <span class="badge bg-warning text-dark" title="This message was received via an insecure/unauthenticated binkp session">
                        <i class="fas fa-exclamation-triangle"></i> Received Insecurely
                    </span>
                    <small class="text-muted ms-2">This message was not authenticated</small>
                </div>
            </div>
            ` : ''}
        </div>

        ${parsedMessage.kludgeLines.length > 0 ? `
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
        ` : ''}

        <div class="message-text">
            ${formatMessageText(parsedMessage.messageBody)}
        </div>

        ${message.attachments && message.attachments.length > 0 ? `
        <div class="message-attachments mt-3">
            <h6 class="text-muted mb-2">
                <i class="fas fa-paperclip"></i>
                File Attachments (${message.attachments.length})
            </h6>
            <div class="list-group">
                ${message.attachments.map(file => `
                    <a href="/api/files/${file.id}/download" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" target="_blank">
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
}


function composeMessage(type, replyToId = null) {
    window.location.href = `/compose/netmail${replyToId ? '?reply=' + replyToId : ''}`;
}

function composeMessageToUser(toName, toAddress, subject) {
    // Build URL with parameters for composing to a specific user
    const params = new URLSearchParams();
    params.set('to_name', toName);
    params.set('to', toAddress);
    if (subject && subject.trim()) {
        // If subject doesn't start with "Re:", add it
        const replySubject = subject && subject.toLowerCase().startsWith('re:') ? subject : 'Re: ' + subject;
        params.set('subject', replySubject);
    }

    window.location.href = `/compose/netmail?${params.toString()}`;
}

function searchMessages() {
    const query = $('#searchInput').val().trim();
    if (query.length < 2) {
        showError('Please enter at least 2 characters to search');
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
            showError('Search failed');
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
            $('#totalCount').text('Error');
            $('#unreadCount').text('Error');
            $('#sentCount').text('Error');
        });
}

function deleteMessage(messageId) {
    if (!confirm('Are you sure you want to delete this message? This action cannot be undone.')) {
        return;
    }

    $.ajax({
        url: `/api/messages/netmail/${messageId}`,
        method: 'DELETE',
        success: function(data) {
            $('#messageModal').modal('hide');
            showSuccess('Message deleted successfully');
            loadMessages();
            loadStats();
        },
        error: function(xhr) {
            let errorMsg = 'Failed to delete message';
            if (xhr.responseJSON && xhr.responseJSON.error) {
                errorMsg = xhr.responseJSON.error;
            }
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
        toggleText.text('Show Flat');
    } else {
        toggleText.text('Show Threaded');
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

// Address Book Functions
function loadAddressBook(search = '') {
    $.get('/api/address-book', { search: search })
        .done(function(response) {
            if (response.success) {
                renderAddressBook(response.entries);
                $('#addressBookStats').text(response.entries.length + ' entries');
            } else {
                $('#addressBookList').html('<div class="text-danger py-2">Failed to load address book</div>');
            }
        })
        .fail(function() {
            $('#addressBookList').html('<div class="text-danger py-2">Failed to load address book</div>');
        });
}

function renderAddressBook(entries) {
    const container = $('#addressBookList');
    let html = '';

    if (entries.length === 0) {
        html = '<div class="text-center text-muted py-2">No entries found</div>';
    } else {
        entries.forEach(function(entry) {
            html += `
                <div class="d-flex justify-content-between align-items-start mb-2 p-2 border rounded address-book-entry"
                     style="cursor: pointer;" onclick="composeToAddressBookEntry('${escapeHtml(entry.messaging_user_id || '')}', '${escapeHtml(entry.node_address || '')}')">
                    <div class="flex-grow-1">
                        <div class="fw-bold small">${escapeHtml(entry.name || 'Unnamed')}</div>
                        <div class="text-primary small">@${escapeHtml(entry.messaging_user_id || 'unknown')}</div>
                        <div class="text-muted small font-monospace">${escapeHtml(entry.node_address || 'No address')}</div>
                        ${entry.description ? `<div class="text-muted smaller">${escapeHtml(entry.description.substring(0, 30) + (entry.description.length > 30 ? '...' : ''))}</div>` : ''}
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" onclick="event.stopPropagation();">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" onclick="event.stopPropagation(); editAddressBookEntry(${entry.id});">
                                <i class="fas fa-edit"></i> Edit
                            </a></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="event.stopPropagation(); deleteAddressBookEntry(${entry.id}, '${escapeHtml(entry.name || 'Unnamed')}');">
                                <i class="fas fa-trash"></i> Delete
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
    $('#addressBookModalTitle').text('Add Address Book Entry');
    $('#addressBookEntryId').val('');
    $('#addressBookForm')[0].reset();
    $('#addressBookModal').modal('show');
}

function editAddressBookEntry(entryId) {
    $.get(`/api/address-book/${entryId}`)
        .done(function(response) {
            if (response.success) {
                const entry = response.entry;
                $('#addressBookModalTitle').text('Edit Address Book Entry');
                $('#addressBookEntryId').val(entry.id);
                $('#addressBookName').val(entry.name);
                $('#addressBookUserId').val(entry.messaging_user_id);
                $('#addressBookNodeAddress').val(entry.node_address);
                $('#addressBookEmail').val(entry.email || '');
                $('#addressBookDescription').val(entry.description || '');
                $('#addressBookModal').modal('show');
            } else {
                showError('Failed to load entry: ' + response.error);
            }
        })
        .fail(function() {
            showError('Failed to load entry');
        });
}

function saveAddressBookEntry() {
    const entryId = $('#addressBookEntryId').val();
    const data = {
        name: $('#addressBookName').val().trim(),
        messaging_user_id: $('#addressBookUserId').val().trim(),
        node_address: $('#addressBookNodeAddress').val().trim(),
        email: $('#addressBookEmail').val().trim(),
        description: $('#addressBookDescription').val().trim()
    };

    // Basic validation
    if (!data.name || !data.messaging_user_id || !data.node_address) {
        showError('Name, user ID, and node address are required');
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
                showSuccess(entryId ? 'Entry updated successfully' : 'Entry added successfully');
            } else {
                showError(response.error || 'Failed to save entry');
            }
        },
        error: function(xhr) {
            const response = xhr.responseJSON;
            showError(response && response.error ? response.error : 'Failed to save entry');
        }
    });
}

function deleteAddressBookEntry(entryId, entryName) {
    if (!confirm(`Are you sure you want to delete "${entryName}" from your address book?`)) {
        return;
    }

    $.ajax({
        url: `/api/address-book/${entryId}`,
        method: 'DELETE',
        success: function(response) {
            if (response.success) {
                loadAddressBook();
                showSuccess('Entry deleted successfully');
            } else {
                showError(response.error || 'Failed to delete entry');
            }
        },
        error: function() {
            showError('Failed to delete entry');
        }
    });
}

function composeToAddressBookEntry(messagingUserId, nodeAddress) {
    composeMessageToUser(messagingUserId, nodeAddress, '');
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
                          .attr('title', 'Already in address book')
                          .prop('disabled', true);
                    showError('This contact is already in your address book');
                    return;
                }

                // Contact doesn't exist, create new entry
                // Build description with reference information
                let description = 'Added from netmail message';
                if (originalFromName && originalFromAddress) {
                    if (fromName !== originalFromName || fromAddress !== originalFromAddress) {
                        // REPLYTO was used - show both original and reply-to info
                        description = `Added from netmail message. Original sender: ${originalFromName} (${originalFromAddress}), Reply-to: ${fromName} (${fromAddress})`;
                    } else {
                        // No REPLYTO - just show sender info
                        description = `Added from netmail message. Sender: ${originalFromName} (${originalFromAddress})`;
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
                                  .html('<i class="fas fa-check"></i> Saved')
                                  .attr('title', 'Saved to address book')
                                  .prop('disabled', true);

                            showSuccess(`${fromName} added to address book`);

                            // Refresh address book in sidebar if it exists
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

/**
 * Format file size in human-readable format
 * @param {number} bytes File size in bytes
 * @returns {string} Formatted file size (e.g., "1.5 MB")
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    if (!bytes) return 'Unknown size';

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
            Loading message...
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
            $('#messageContent').html('<div class="text-danger">Failed to load message</div>');
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
    if (selectedMessages.size === 0) {
        showError('No messages selected');
        return;
    }

    if (!confirm(`Are you sure you want to delete ${selectedMessages.size} message(s)?`)) {
        return;
    }

    const messageIds = Array.from(selectedMessages);

    $.ajax({
        url: '/api/messages/netmail/bulk-delete',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ message_ids: messageIds }),
        success: function(data) {
            showSuccess(`Deleted ${messageIds.length} message(s)`);
            clearSelection();
            loadMessages();
        },
        error: function(xhr) {
            const error = xhr.responseJSON ? xhr.responseJSON.error : 'Failed to delete messages';
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
