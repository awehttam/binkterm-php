// BinkTest JavaScript Application

// Message parsing and formatting functions
function parseNetmailMessage(messageText, storedKludgeLines = null) {
    // If we have stored kludge lines, use them instead of trying to parse from message text
    if (storedKludgeLines && storedKludgeLines.trim()) {
        const kludgeLines = storedKludgeLines.split('\n').filter(line => line.trim() !== '');
        return {
            kludgeLines: kludgeLines,
            messageBody: messageText.trim()
        };
    }
    
    // Fallback to old parsing method for backwards compatibility
    // First, split by both \n and \r\n, then by \r to handle different line endings
    let lines = messageText.split(/\r?\n/);
    
    // Some messages might have embedded \r without \n, split those too
    const allLines = [];
    lines.forEach(line => {
        if (line.includes('\r')) {
            allLines.push(...line.split('\r'));
        } else {
            allLines.push(line);
        }
    });
    
    const kludgeLines = [];
    const messageLines = [];
    
    for (let i = 0; i < allLines.length; i++) {
        const line = allLines[i];
        
        // True kludge lines ONLY: those starting with control characters or routing info
        if (line.startsWith('\x01') || line.startsWith('SEEN-BY:') || line.startsWith('PATH:')) {
            kludgeLines.push(line);
        } else {
            // All lines that aren't kludge lines are message content (including empty lines)
            messageLines.push(line);
        }
    }
    
    return {
        kludgeLines: kludgeLines,
        messageBody: messageLines.join('\n').trim()
    };
}

function parseEchomailMessage(messageText, storedKludgeLines = null) {
    // If we have stored kludge lines, use them instead of trying to parse from message text
    if (storedKludgeLines && storedKludgeLines.trim()) {
        const kludgeLines = storedKludgeLines.split('\n').filter(line => line.trim() !== '');
        return {
            kludgeLines: kludgeLines,
            messageBody: messageText.trim()
        };
    }
    
    // Fallback to old parsing method for backwards compatibility
    // First, split by both \n and \r\n, then by \r to handle different line endings
    let lines = messageText.split(/\r?\n/);
    
    // Some messages might have embedded \r without \n, split those too
    const allLines = [];
    lines.forEach(line => {
        if (line.includes('\r')) {
            allLines.push(...line.split('\r'));
        } else {
            allLines.push(line);
        }
    });
    
    const kludgeLines = [];
    const messageLines = [];
    
    for (let i = 0; i < allLines.length; i++) {
        const line = allLines[i];
        
        // True kludge lines for echomail ONLY: control characters and routing info
        if (line.startsWith('\x01') || line.startsWith('SEEN-BY:') || line.startsWith('PATH:') || 
            line.startsWith('AREA:')) {
            kludgeLines.push(line);
        } else {
            // All lines that aren't kludge lines are message content (including empty lines)
            messageLines.push(line);
        }
    }
    
    return {
        kludgeLines: kludgeLines,
        messageBody: messageLines.join('\n').trim()
    };
}

// Smart text processing for mobile-friendly rendering
function formatMessageText(messageText) {
    if (!messageText || messageText.trim() === '') {
        return '';
    }
    
    // Detect if message contains formatting that should be preserved
    const hasAsciiArt = /[\|\+\-_=~`]{3,}/.test(messageText) || /[^\w\s]{5,}/.test(messageText);
    const hasIndentation = /^\s{4,}/.test(messageText) || messageText.includes('\t');
    const hasQuotes = /^\s*[>|]/.test(messageText);
    const hasSignature = messageText.includes('---') || messageText.includes('___');
    
    // If message has special formatting, use responsive preformatted style
    if (hasAsciiArt || hasIndentation) {
        return `<pre class="message-preformatted">${escapeHtml(messageText)}</pre>`;
    }
    
    // Otherwise, format as readable text with preserved line breaks
    const lines = messageText.split(/\r?\n/);
    let formattedLines = [];
    let inQuoteBlock = false;
    let inSignature = false;
    
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const trimmedLine = line.trim();
        
        // Handle signature separator
        if (trimmedLine === '---' || trimmedLine === '___' || trimmedLine.match(/^-{2,}$/)) {
            inSignature = true;
            formattedLines.push(`<div class="message-signature-separator">${escapeHtml(trimmedLine)}</div>`);
            continue;
        }
        
        // Handle quoted text
        if (/^\s*[>|]/.test(line)) {
            if (!inQuoteBlock) {
                formattedLines.push('<div class="message-quote">');
                inQuoteBlock = true;
            }
            formattedLines.push(`<div class="quote-line">${escapeHtml(line)}</div>`);
        } else {
            if (inQuoteBlock) {
                formattedLines.push('</div>');
                inQuoteBlock = false;
            }
            
            // Empty lines become paragraph breaks
            if (trimmedLine === '') {
                formattedLines.push('<br>');
            } else {
                const cssClass = inSignature ? 'message-signature' : 'message-line';
                formattedLines.push(`<span class="${cssClass}">${escapeHtml(line)}</span>`);
                // Add line break after each line except the last one
                if (i < lines.length - 1) {
                    formattedLines.push('<br>');
                }
            }
        }
    }
    
    // Close any open quote block
    if (inQuoteBlock) {
        formattedLines.push('</div>');
    }
    
    return `<div class="message-formatted">${formattedLines.join('')}</div>`;
}

function formatKludgeLines(kludgeLines) {
    return kludgeLines.map(line => {
        // Clean up control characters completely
        let cleanLine = line.replace(/\x01/g, ''); // Remove SOH characters
        cleanLine = cleanLine.replace(/[\x00-\x1F\x7F-\x9F]/g, ''); // Remove other control characters
        const escapedLine = escapeHtml(cleanLine);
        
        // Color code different types of kludge lines
        if (line.startsWith('\x01MSGID:')) {
            return `<span style="color: #28a745;">${escapedLine}</span>`;
        } else if (line.startsWith('\x01REPLY:')) {
            return `<span style="color: #17a2b8;">${escapedLine}</span>`;
        } else if (line.startsWith('\x01INTL')) {
            return `<span style="color: #ffc107;">${escapedLine}</span>`;
        } else if (line.startsWith('\x01TOPT') || line.startsWith('\x01FMPT')) {
            return `<span style="color: #fd7e14;">${escapedLine}</span>`;
        } else if (line.startsWith('\x01PID:')) {
            return `<span style="color: #e83e8c;">${escapedLine}</span>`;
        } else if (line.startsWith('SEEN-BY:')) {
            return `<span style="color: #6f42c1;">${escapedLine}</span>`;
        } else if (line.startsWith('PATH:')) {
            return `<span style="color: #20c997;">${escapedLine}</span>`;
        } else if (line.startsWith('AREA:')) {
            return `<span style="color: #007bff;">${escapedLine}</span>`;
        } else if (line.startsWith('\x01')) {
            // Generic kludge line
            return `<span style="color: #dc3545;">${escapedLine}</span>`;
        } else {
            return `<span style="color: #6c757d;">${escapedLine}</span>`;
        }
    }).join('\n');
}

function toggleKludgeLines() {
    const container = $('#kludgeContainer');
    const icon = $('#toggleIcon');
    const text = $('#toggleText');
    
    if (container.is(':visible')) {
        container.slideUp();
        icon.removeClass('fas fa-eye').addClass('fas fa-eye-slash');
        text.text('Show Headers');
    } else {
        container.slideDown();
        icon.removeClass('fas fa-eye-slash').addClass('fas fa-eye');
        text.text('Hide Headers');
    }
}

// Global user settings object
window.userSettings = {};

$(document).ready(function() {
    // Load user settings on page load
    loadUserSettings();
    
    // Global AJAX setup
    $.ajaxSetup({
        beforeSend: function(xhr) {
            // Add loading indicator if needed
        },
        error: function(xhr, status, error) {
            if (xhr.status === 401) {
                // Redirect to login if unauthorized
                window.location.href = '/login';
            }
        }
    });
});

// Unified user settings management
function loadUserSettings() {
    return new Promise(function(resolve, reject) {
        $.get('/api/user/settings')
            .done(function(response) {
                if (response.success && response.settings) {
                    // Store all settings globally
                    window.userSettings = response.settings;
                    console.log('Loaded user settings:', window.userSettings);
                } else if (response.timezone || response.messages_per_page) {
                    // Handle old API response format
                    window.userSettings = response;
                    console.log('Loaded user settings (legacy format):', window.userSettings);
                }
                
                // Apply font settings after loading
                applyFontSettings();
                resolve(window.userSettings);
            })
            .fail(function() {
                console.log('Failed to load user settings, using defaults');
                // Set defaults
                window.userSettings = {
                    messages_per_page: 25,
                    threaded_view: false,
                    netmail_threaded_view: false,
                    default_sort: 'date_desc',
                    timezone: 'America/Los_Angeles',
                    font_family: 'Courier New, Monaco, Consolas, monospace',
                    font_size: 16
                };
                
                // Apply font settings after loading defaults
                applyFontSettings();
                resolve(window.userSettings);
            });
    });
}

function saveUserSetting(key, value) {
    // Update local cache
    window.userSettings[key] = value;
    
    // Apply font settings if font-related setting changed
    if (key === 'font_family' || key === 'font_size') {
        applyFontSettings();
    }
    
    // Save to server
    const settings = {};
    settings[key] = value;
    
    return $.ajax({
        url: '/api/user/settings',
        method: 'POST',
        data: JSON.stringify({ settings: settings }),
        contentType: 'application/json',
        success: function() {
            console.log(`Saved setting: ${key} = ${value}`);
        },
        error: function() {
            console.warn(`Failed to save setting: ${key}`);
        }
    });
}

function saveUserSettings(settings) {
    // Update local cache
    Object.assign(window.userSettings, settings);
    
    // Apply font settings if any font-related settings changed
    if (settings.hasOwnProperty('font_family') || settings.hasOwnProperty('font_size')) {
        applyFontSettings();
    }
    
    // Save to server
    return $.ajax({
        url: '/api/user/settings',
        method: 'POST',
        data: JSON.stringify({ settings: settings }),
        contentType: 'application/json',
        success: function() {
            console.log('Saved settings:', settings);
        },
        error: function() {
            console.warn('Failed to save settings');
        }
    });
}

// Apply font settings to message display areas
function applyFontSettings() {
    if (!window.userSettings) return;
    
    const fontFamily = window.userSettings.font_family || 'Courier New, Monaco, Consolas, monospace';
    const fontSize = window.userSettings.font_size || 16;
    
    // Remove existing font style if present
    $('#dynamicFontStyles').remove();
    
    // Apply font settings to message text and compose areas
    const css = `
        .message-text, .message-text pre, .message-formatted {
            font-family: ${fontFamily} !important;
            font-size: ${fontSize}px !important;
        }
        .message-content {
            font-family: ${fontFamily} !important;
            font-size: ${fontSize}px !important;
        }
        #messageText {
            font-family: ${fontFamily} !important;
            font-size: ${fontSize}px !important;
        }
        .message-text code,
        #dashboardMessageContent .message-text,
        #dashboardMessageContent .message-text pre,
        #dashboardMessageContent .message-text code,
        #dashboardKludgeContainer pre {
            font-family: ${fontFamily} !important;
            font-size: ${fontSize}px !important;
        }
    `;
    
    $('<style>').prop('type', 'text/css').prop('id', 'dynamicFontStyles').html(css).appendTo('head');
}

// Authentication functions
function logout() {
    $.ajax({
        url: '/api/auth/logout',
        method: 'POST',
        success: function() {
            window.location.href = '/login';
        },
        error: function() {
            // Force logout even if API call fails
            document.cookie = 'binktermphp_session=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
            window.location.href = '/login';
        }
    });
}

// Utility functions
function formatDate(dateString) {
    // Database stores dates in UTC, so parse as UTC and convert to local time
    //const date = new Date(dateString + 'Z'); // Add 'Z' to treat as UTC
    const date = new Date(dateString+'Z');
    const now = new Date();
    const diffMs = now - date;
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffDays = Math.floor(diffHours / 24);
    
    // Handle negative differences (future dates) gracefully
    if (diffMs < 0) {
        const absDays = Math.abs(diffDays);
        const absHours = Math.abs(diffHours);
        if (absDays === 0 && absHours === 0) {
            return 'Soon';
        } else if (absDays === 0) {
            return `In ${absHours} hour${absHours !== 1 ? 's' : ''}`;
        } else if (absDays === 1) {
            return 'Tomorrow';
        } else {
            return `In ${absDays} days`;
        }
    }
    
    if (diffDays === 0) {
        if (diffHours === 0) {
            const diffMins = Math.floor(diffMs / (1000 * 60));
            return diffMins <= 1 ? 'Just now' : `${diffMins} minutes ago`;
        }
        return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
    } else if (diffDays === 1) {
        return 'Yesterday';
    } else if (diffDays < 7) {
        return `${diffDays} days ago`;
    } else {
        return date.toLocaleDateString();
    }
}

function formatFidonetAddress(address) {
    return `<span class="fidonet-address">${address}</span>`;
}

function formatFullDate(dateString) {
    // Database stores dates in UTC, so parse as UTC
    const date = new Date(dateString + 'Z'); // Add 'Z' to treat as UTC
    const userTimezone = window.userSettings?.timezone || 'America/Los_Angeles';
    
    return date.toLocaleString("en-US", {
        timeZone: userTimezone,
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Message handling functions
function loadMessages(type, area = null, page = 1) {
    let url = `/api/messages/${type}`;
    if (area) {
        url += `/${area}`;
    }
    url += `?page=${page}`;
    
    $.get(url)
        .done(function(data) {
            displayMessages(data.messages, type);
            updatePagination(data.pagination);
        })
        .fail(function() {
            showError('Failed to load messages');
        });
}

function displayMessages(messages, type) {
    const container = $('#messagesContainer');
    let html = '';
    
    if (messages.length === 0) {
        html = '<div class="text-center text-muted py-4">No messages found</div>';
    } else {
        messages.forEach(function(msg) {
            html += `
                <div class="message-item" onclick="viewMessage(${msg.id}, '${type}')">
                    <div class="message-header">
                        <div>
                            <span class="message-from">${escapeHtml(msg.from_name)}</span>
                            ${formatFidonetAddress(msg.from_address)}
                            ${type === 'echomail' ? `<span class="echoarea-tag ms-2">${msg.echoarea}</span>` : '<span class="netmail-indicator ms-2">NETMAIL</span>'}
                        </div>
                        <small class="message-date">${type === 'echomail' ? formatFullDate(msg.date_written) : formatDate(msg.date_written)}</small>
                    </div>
                    <div class="message-subject">${escapeHtml(msg.subject || '(No Subject)')}</div>
                    ${msg.to_name ? `<small class="text-muted">To: ${escapeHtml(msg.to_name)}</small>` : ''}
                    ${!msg.is_read && type === 'netmail' ? '<span class="badge bg-primary ms-2">NEW</span>' : ''}
                </div>
            `;
        });
    }
    
    container.html(html);
}

function viewMessage(messageId, type) {
    window.location.href = `/messages/${type}/${messageId}`;
}

function composeMessage(type, replyToId = null) {
    window.location.href = `/compose/${type}${replyToId ? `?reply=${replyToId}` : ''}`;
}

// Form validation
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function validateFidonetAddress(address) {
    const re = /^\d+:\d+\/\d+(\.\d+)?(@\w+)?$/;
    return re.test(address);
}

// UI feedback functions
function showError(message) {
    const alertHtml = `
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            ${escapeHtml(message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    // Insert at top of main content
    $('main .container').prepend(alertHtml);
    
    // Auto-remove after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 5000);
}

function showSuccess(message) {
    const alertHtml = `
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ${escapeHtml(message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    $('main .container').prepend(alertHtml);
    
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 5000);
}

function showLoading(container) {
    $(container).html(`
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin me-2"></i>
            Loading...
        </div>
    `);
}

// Auto-refresh functionality
let autoRefreshInterval = null;

function startAutoRefresh(callback, interval = 30000) {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    autoRefreshInterval = setInterval(callback, interval);
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// Initialize tooltips and popovers
$(function () {
    $('[data-bs-toggle="tooltip"]').tooltip();
    $('[data-bs-toggle="popover"]').popover();
});

// Handle page unload
$(window).on('beforeunload', function() {
    stopAutoRefresh();
});