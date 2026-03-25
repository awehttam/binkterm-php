// BinkTest JavaScript Application
// Message parsing and formatting functions
function parseNetmailMessage(messageText, storedKludgeLines = null, storedBottomKludges = null) {
    // If we have stored kludge lines, use them instead of trying to parse from message text
    if (storedKludgeLines && storedKludgeLines.trim()) {
        const topKludges = storedKludgeLines.split('\n').filter(line => line.trim() !== '');
        const bottomKludges = storedBottomKludges ? storedBottomKludges.split('\n').filter(line => line.trim() !== '') : [];
        return {
            topKludges: topKludges,
            bottomKludges: bottomKludges,
            kludgeLines: [...topKludges, ...bottomKludges], // Combined for backward compatibility
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

function parseEchomailMessage(messageText, storedKludgeLines = null, storedBottomKludges = null) {
    // If we have stored kludge lines, use them instead of trying to parse from message text
    if (storedKludgeLines && storedKludgeLines.trim()) {
        const topKludges = storedKludgeLines.split('\n').filter(line => line.trim() !== '');
        const bottomKludges = storedBottomKludges ? storedBottomKludges.split('\n').filter(line => line.trim() !== '') : [];
        return {
            topKludges: topKludges,
            bottomKludges: bottomKludges,
            kludgeLines: [...topKludges, ...bottomKludges], // Combined for backward compatibility
            messageBody: messageText.replace(/\s+$/g, '')
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
        messageBody: messageLines.join('\n').replace(/\s+$/g, '')
    };
}

// Smart text processing for mobile-friendly rendering
function formatMessageText(messageText, searchTerms = [], forcePlain = false) {
    if (!messageText || messageText.trim() === '') {
        return '';
    }

    // Get search terms from global variable if not passed
    if (!searchTerms || searchTerms.length === 0) {
        searchTerms = (typeof currentSearchTerms !== 'undefined') ? currentSearchTerms : [];
    }

    const hasAnsi = /\x1b\[[0-9;]*m/.test(messageText);
    const hasCursorAnsi = /\x1b\[[0-9;]*[ABCDEFGHJKfsu]/.test(messageText);
    const hasPipes = /\|[0-9A-Fa-f]{2}/.test(messageText);
    const hasColorCodes = hasAnsi || hasPipes;

    const lines = messageText.split(/\r?\n/);

    const shouldRenderAnsiArt = !forcePlain && (hasCursorAnsi || hasColorCodes);
    const ansiLineStyle = hasColorCodes ? ' style="white-space: pre;"' : ' style="white-space: pre-wrap;"';

    // Check if this is ANSI art (cursor positioning or dense ANSI text)
    // If so, use the full terminal renderer instead of line-by-line processing
    if (shouldRenderAnsiArt) {
        console.debug('[ANSI auto] detected via', hasCursorAnsi ? 'cursor ANSI' : hasColorCodes ? 'color codes' : 'unknown', '— rendering as ANSI art —', messageText.substring(0, 40).replace(/\n/g, '↵'));
        let rendered = renderAnsiTerminal(messageText);
        rendered = linkifyUrls(rendered);
        if (searchTerms && searchTerms.length > 0) {
            rendered = highlightSearchTerms(rendered, searchTerms);
        }
        return `<div class="ansi-art-container"><pre class="ansi-art">${rendered}</pre></div>`;
    }

    // Format as readable text with preserved line breaks and quote coloring

    // Pre-scan to find the LAST signature separator in the bottom third of the
    // message. A bare run of dashes (-- or ---) only counts as a sig separator
    // when it appears late in the message — earlier occurrences are typically
    // mid-message dividers, not signature delimiters.
    const isSigSeparator = line => /^(-{2,3}|_{2,3})$/.test(line.trim());
    const bottomThirdStart = Math.floor(lines.length * 2 / 3);
    let lastSigIndex = -1;
    for (let i = bottomThirdStart; i < lines.length; i++) {
        if (isSigSeparator(lines[i])) lastSigIndex = i;
    }

    let formattedLines = [];
    let inQuoteBlock = false;
    let inSignature = false;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const trimmedLine = line.trim();

        // Handle signature separator — only trigger on the last one
        if (isSigSeparator(line) && i === lastSigIndex) {
            inSignature = true;
            let highlightedLine = parseAnsi(trimmedLine);
            highlightedLine = linkifyUrls(highlightedLine);
            if (searchTerms && searchTerms.length > 0) {
                highlightedLine = highlightSearchTerms(highlightedLine, searchTerms);
            }
            formattedLines.push(`<div class="message-signature-separator"${ansiLineStyle}>${highlightedLine}</div>`);
            continue;
        }

        // Handle quoted text - supports multi-level and initials-style quotes
        // Matches lines starting with: >, >>, MA>, MA> >, CM>>, CN>>>, etc.
        const trimmedForQuote = line.trim();
        const isQuoteLine = /^[A-Za-z]{0,3}>/.test(trimmedForQuote);

        if (isQuoteLine) {
            if (!inQuoteBlock) {
                formattedLines.push('<div class="message-quote">');
                inQuoteBlock = true;
            }
            let highlightedLine = parseAnsi(line);
            highlightedLine = linkifyUrls(highlightedLine);
            if (searchTerms && searchTerms.length > 0) {
                highlightedLine = highlightSearchTerms(highlightedLine, searchTerms);
            }

            // Calculate quote depth by counting > characters at start of line
            const quoteColoring = window.userSettings?.quote_coloring !== false;
            if (quoteColoring) {
                // Count all > characters in the quote prefix portion
                // For "CN>>> text" or "MA> > text" or ">> text", count all the >'s
                const allGts = trimmedForQuote.match(/>/g) || ['>'];
                // Only count >'s that appear before the main text content
                // Extract prefix: optional initials, then all > and spaces until non-> non-space
                const prefixMatch = trimmedForQuote.match(/^([A-Za-z]{0,3}[>\s]+)/);
                const prefix = prefixMatch ? prefixMatch[1] : '>';
                const depth = (prefix.match(/>/g) || []).length;
                const depthClass = Math.min(depth, 4); // Cap at 4 levels
                formattedLines.push(`<div class="quote-line quote-level-${depthClass}"${ansiLineStyle}>${highlightedLine}</div>`);
            } else {
                formattedLines.push(`<div class="quote-line"${ansiLineStyle}>${highlightedLine}</div>`);
            }
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
                let highlightedLine = parseAnsi(line);
                highlightedLine = linkifyUrls(highlightedLine);
                if (searchTerms && searchTerms.length > 0) {
                    highlightedLine = highlightSearchTerms(highlightedLine, searchTerms);
                }
                formattedLines.push(`<span class="${cssClass}"${ansiLineStyle}>${highlightedLine}</span>`);
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

function formatPlainMessageText(messageText, searchTerms = []) {
    if (!messageText || messageText.trim() === '') {
        return '';
    }

    if (!searchTerms || searchTerms.length === 0) {
        searchTerms = (typeof currentSearchTerms !== 'undefined') ? currentSearchTerms : [];
    }

    let plainText = messageText;
    if (window.hasPipeCodes && window.hasPipeCodes(plainText) && window.convertPipeCodesToAnsi) {
        plainText = window.convertPipeCodesToAnsi(plainText);
    }
    if (window.stripAllAnsi) {
        plainText = window.stripAllAnsi(plainText);
    }

    let escaped = escapeHtml(plainText).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    escaped = linkifyUrls(escaped);
    if (searchTerms && searchTerms.length > 0) {
        escaped = highlightSearchTerms(escaped, searchTerms);
    }

    return `<div class="message-formatted"><pre class="mb-0" style="white-space: pre-wrap;">${escaped}</pre></div>`;
}

/**
 * Render message body as raw source — no ANSI stripping, no pipe code conversion,
 * no URL linkification. Useful for inspecting the wire content of a message.
 */
function formatRawMessageText(messageText) {
    if (!messageText || messageText.trim() === '') {
        return '';
    }
    const escaped = escapeHtml(messageText).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    return `<div class="message-formatted"><pre class="mb-0" style="white-space: pre-wrap;">${escaped}</pre></div>`;
}

function normalizeViewerRenderMode(mode) {
    const normalized = String(mode || 'auto').toLowerCase();
    if (normalized === 'plain') return 'plain';
    if (normalized === 'raw') return 'raw';
    if (normalized === 'rip' || normalized === 'ripscript') return 'rip';
    // Handle art format names explicitly — normalizeArtFormat does not recognise
    // 'amiga_ansi' (only 'amiga'/'amigaansi') and falls back to 'auto' for unknowns,
    // which would corrupt the viewer mode cycle.
    if (normalized === 'ansi') return 'ansi';
    if (normalized === 'amiga_ansi' || normalized === 'amiga' || normalized === 'amigaansi') return 'amiga_ansi';
    if (normalized === 'petscii') return 'petscii';
    if (window.normalizeArtFormat) {
        return window.normalizeArtFormat(normalized);
    }
    return normalized || 'auto';
}

function getNextViewerRenderMode(mode) {
    const modes = ['auto', 'rip', 'ansi', 'amiga_ansi', 'plain', 'raw'];
    const normalized = normalizeViewerRenderMode(mode);
    const currentIndex = modes.indexOf(normalized);
    return modes[(currentIndex + 1 + modes.length) % modes.length];
}

function getViewerRenderModeLabel(mode) {
    const normalized = normalizeViewerRenderMode(mode);
    switch (normalized) {
        case 'rip':
            return window.t ? window.t('ui.echomail.viewer_mode_rip', {}, 'RIPscrip') : 'RIPscrip';
        case 'ansi':
            return window.t ? window.t('ui.echomail.viewer_mode_ansi', {}, 'ANSI') : 'ANSI';
        case 'amiga_ansi':
            return window.t ? window.t('ui.echomail.viewer_mode_amiga_ansi', {}, 'Amiga ANSI') : 'Amiga ANSI';
        case 'plain':
            return window.t ? window.t('ui.echomail.viewer_mode_plain', {}, 'Plain Text') : 'Plain Text';
        case 'raw':
            return window.t ? window.t('ui.echomail.viewer_mode_raw', {}, 'Raw Source') : 'Raw Source';
        default:
            return window.t ? window.t('ui.echomail.viewer_mode_auto', {}, 'Auto') : 'Auto';
    }
}

function getMarkupFormatLabel(message) {
    const markupFormat = String(message?.markup_format || '').toLowerCase();
    switch (markupFormat) {
        case 'markdown':
            return window.t ? window.t('ui.echomail.markup_markdown', {}, 'Markdown') : 'Markdown';
        case 'stylecodes':
            return window.t ? window.t('ui.echomail.markup_stylecodes', {}, 'StyleCodes') : 'StyleCodes';
        default:
            return '';
    }
}

function getViewerModeToastLabel(mode, message = null) {
    const normalized = normalizeViewerRenderMode(mode);
    const modeLabel = getViewerRenderModeLabel(normalized);
    const markupLabel = getMarkupFormatLabel(message);

    if (!markupLabel || !message?.markup_html) {
        return modeLabel;
    }

    if (normalized === 'auto') {
        return window.t
            ? window.t('ui.echomail.viewer_mode_rendered_markup', { format: markupLabel }, 'Rendered {format}')
            : `Rendered ${markupLabel}`;
    }

    return window.t
        ? window.t('ui.echomail.viewer_mode_markup_source', { format: markupLabel, mode: modeLabel }, '{format} Source ({mode})')
        : `${markupLabel} Source (${modeLabel})`;
}

function looksLikeRipScript(text) {
    if (!text || text.trim() === '') {
        return false;
    }

    const normalized = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    const lines = normalized.split('\n');
    let ripLineCount = 0;
    let supportedCommandCount = 0;

    for (const line of lines) {
        const trimmed = line.replace(/^\s+/, '');
        if (!trimmed.startsWith('!|')) {
            continue;
        }

        ripLineCount++;

        if (/\|c\d{2}\b/i.test(trimmed)
            || /\|L[0-9A-Z]{8,}/i.test(trimmed)
            || /\|@[0-9A-Z]{4}/i.test(trimmed)
        ) {
            supportedCommandCount++;
        }
    }

    return ripLineCount > 0 && supportedCommandCount > 0;
}

// ---------------------------------------------------------------------------
// Shared RIPscrip renderer — used by the ad box and any other non-echomail
// page that needs to render RIP content from raw text (not a URL).
// ---------------------------------------------------------------------------

let _sharedRiptermLoaderPromise = null;

/**
 * Lazily load the RIPterm JavaScript libraries (BGI.js + ripterm.js).
 * Uses script-tag deduplication so the files are only ever fetched once even
 * when called from multiple callers on the same page.
 *
 * @returns {Promise<void>}
 */
function loadRiptermForContent() {
    if (_sharedRiptermLoaderPromise) return _sharedRiptermLoaderPromise;
    if (window.RIPterm && window.BGI) {
        _sharedRiptermLoaderPromise = Promise.resolve();
        return _sharedRiptermLoaderPromise;
    }

    function _loadScript(src) {
        return new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-ripterm-src="${src}"]`);
            if (existing) {
                if (existing.dataset.loaded === 'true') { resolve(); return; }
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.async = false;
            script.dataset.riptermSrc = src;
            script.addEventListener('load', () => { script.dataset.loaded = 'true'; resolve(); }, { once: true });
            script.addEventListener('error', reject, { once: true });
            document.head.appendChild(script);
        });
    }

    _sharedRiptermLoaderPromise = _loadScript('/vendor/riptermjs/BGI.js')
        .then(() => _loadScript('/vendor/riptermjs/ripterm.js'));
    return _sharedRiptermLoaderPromise;
}

/**
 * Render a RIPscrip text payload into a DOM container element.
 * Shows a spinner while loading, then replaces it with the rendered canvas.
 *
 * @param {HTMLElement} container
 * @param {string}      ripText   Raw RIPscrip content
 */
function renderRipContent(container, ripText) {
    const canvasId = `ripCanvas_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
    container.innerHTML = `
        <div class="text-center py-4 text-muted" data-rip-loading>
            <i class="fas fa-spinner fa-spin fa-2x"></i>
        </div>
        <div class="d-none" data-rip-stage style="overflow:auto;max-height:70vh;padding:8px;text-align:center;background:#0a0a0a;border-radius:6px;">
            <canvas id="${canvasId}" width="640" height="350"
                style="width:100%;max-width:960px;height:auto;image-rendering:pixelated;background:#000;border:1px solid #193247;border-radius:6px;"></canvas>
        </div>
    `;
    loadRiptermForContent()
        .then(async () => {
            const blobUrl = URL.createObjectURL(new Blob([ripText], { type: 'text/plain' }));
            const ripterm = new window.RIPterm({
                canvasId,
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
            const stage   = container.querySelector('[data-rip-stage]');
            if (loading) loading.remove();
            if (stage)   stage.classList.remove('d-none');
        })
        .catch((err) => {
            console.error('RIP render failed:', err);
            container.innerHTML = '<div class="alert alert-danger m-3">Failed to render RIPscrip content.</div>';
        });
}

/**
 * Render ad content using the same multimodal pipeline as the echomail viewer:
 * RIPscrip → Sixel → ANSI/PCBoard/plain.
 *
 * Requires sixel.js and pcboard.js to be loaded on the page before calling.
 *
 * @param {HTMLElement} container Target element (will be replaced with rendered output)
 * @param {string}      content   Raw ad content string
 */
function renderAdContent(container, content) {
    if (!content || content.trim() === '') {
        container.innerHTML = '';
        return;
    }

    const looksLikeAnsiArt = typeof looksLikeAnsiArtText === 'function' && looksLikeAnsiArtText(content);
    const hasAnsi = /\x1b\[[0-9;]*m/.test(content);
    const hasCursorAnsi = /\x1b\[[0-9;]*[ABCDEFGHJKfsu]/.test(content);
    const hasPipes = /\|[0-9A-Fa-f]{2}/.test(content);
    const hasColorCodes = hasAnsi || hasPipes;

    // RIPscrip
    if (typeof looksLikeRipScript === 'function' && looksLikeRipScript(content)) {
        renderRipContent(container, content);
        return;
    }

    // Sixel
    if (typeof looksLikeSixel === 'function' && looksLikeSixel(content)) {
        if (typeof renderSixelChunks === 'function') {
            renderSixelChunks(container, content, function (chunk) {
                return formatMessageBodyForDisplay({}, chunk, []);
            });
            return;
        }
    }

    // ANSI / PCBoard / plain
    const adMessage = {};
    if (looksLikeAnsiArt) {
        adMessage.art_format = 'ansi';
    }

    const html = formatMessageBodyForDisplay(adMessage, content, []);
    container.innerHTML = '';
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    while (tmp.firstChild) container.appendChild(tmp.firstChild);
}

function formatMessageBodyForDisplay(message, bodyText, searchTerms = [], forcePlain = false) {
    const text = bodyText || '';
    let forcePlainText = !!forcePlain;
    let formatOverride = null;
    if (typeof forcePlain === 'object' && forcePlain !== null) {
        forcePlainText = !!forcePlain.forcePlain;
        formatOverride = forcePlain.formatOverride || null;
    }

    const messageArtFormat = window.normalizeArtFormat ? window.normalizeArtFormat(message?.art_format || 'auto') : (message?.art_format || 'auto');
    const requestedFormat = normalizeViewerRenderMode(formatOverride || messageArtFormat || 'auto');
    const rawBytesB64 = message?.message_bytes_b64 || null;
    const ripDetected = looksLikeRipScript(text);

    if (!text || text.trim() === '') {
        return '';
    }

    if (!searchTerms || searchTerms.length === 0) {
        searchTerms = (typeof currentSearchTerms !== 'undefined') ? currentSearchTerms : [];
    }

    if (requestedFormat === 'raw') {
        return formatRawMessageText(text);
    }

    if (forcePlainText || requestedFormat === 'plain') {
        return formatPlainMessageText(text, searchTerms);
    }

    const shouldRenderRip = !forcePlainText && (
        requestedFormat === 'rip'
        || (requestedFormat === 'auto' && ripDetected)
    );

    if (shouldRenderRip && message?.rip_html) {
        return message.rip_html;
    }

    const hasPCBoard = !forcePlainText && (window.hasPCBoardCodes ? window.hasPCBoardCodes(text) : false);
    if (hasPCBoard && window.renderPCBoardBuffer) {
        let rendered = window.renderPCBoardBuffer(text);
        rendered = linkifyUrls(rendered);
        if (searchTerms && searchTerms.length > 0) {
            rendered = highlightSearchTerms(rendered, searchTerms);
        }
        return `<div class="ansi-art-container art-format-ansi"><pre class="ansi-art art-format-ansi">${rendered}</pre></div>`;
    }

    const hasAnsi = /\x1b\[[0-9;]*m/.test(text);
    const hasCursorAnsi = /\x1b\[[0-9;]*[ABCDEFGHJKfsu]/.test(text);
    const hasPipes = /\|[0-9A-Fa-f]{2}/.test(text);
    const hasColorCodes = hasAnsi || hasPipes;
    const explicitBinaryArtMode = ['ansi', 'amiga_ansi'].includes(requestedFormat);
    const shouldRenderAnsiArt = !forcePlainText && (
        explicitBinaryArtMode ||
        hasCursorAnsi ||
        hasColorCodes
    );

    if (shouldRenderAnsiArt) {
        const renderFormat = explicitBinaryArtMode ? requestedFormat : 'ansi';
        if (!explicitBinaryArtMode) {
            console.debug('[ANSI auto] detected via', hasAnsi ? 'ESC sequences' : hasPipes ? 'pipe codes' : 'cursor ANSI',
                '— msg id:', message?.id, '— subject:', message?.subject);
        }
        let rendered = renderArtMessage(text, {
            format: renderFormat,
            bytesBase64: rawBytesB64,
            cols: 80,
            rows: 500
        });
        rendered = linkifyUrls(rendered);
        if (searchTerms && searchTerms.length > 0) {
            rendered = highlightSearchTerms(rendered, searchTerms);
        }
        const profileClass = window.getArtProfileClass ? window.getArtProfileClass(renderFormat) : 'art-format-ansi';
        return `<div class="ansi-art-container ${profileClass}"><pre class="ansi-art ${profileClass}">${rendered}</pre></div>`;
    }

    return formatMessageText(text, searchTerms, false);
}

// Helper function to highlight search terms in escaped HTML text
// Only highlights text content, not text inside HTML tags or attributes
function highlightSearchTerms(htmlText, searchTerms) {
    if (!searchTerms || searchTerms.length === 0 || !htmlText) {
        return htmlText;
    }

    // Sort search terms by length (longest first) to avoid partial matches inside longer terms
    const sortedTerms = searchTerms.slice().sort((a, b) => b.length - a.length);

    // Split HTML into text and tag parts to avoid highlighting inside tags/attributes
    // This regex matches HTML tags (including their attributes)
    const htmlTagPattern = /<[^>]+>/g;
    const parts = [];
    let lastIndex = 0;
    let match;

    // Split the HTML into alternating text and tag segments
    while ((match = htmlTagPattern.exec(htmlText)) !== null) {
        // Add text before this tag
        if (match.index > lastIndex) {
            parts.push({
                type: 'text',
                content: htmlText.substring(lastIndex, match.index)
            });
        }
        // Add the tag itself
        parts.push({
            type: 'tag',
            content: match[0]
        });
        lastIndex = htmlTagPattern.lastIndex;
    }
    // Add remaining text after last tag
    if (lastIndex < htmlText.length) {
        parts.push({
            type: 'text',
            content: htmlText.substring(lastIndex)
        });
    }

    // Now highlight search terms only in text parts
    for (const term of sortedTerms) {
        if (term.length < 2) continue; // Skip single character terms

        const escapedTerm = escapeRegex(term);
        const regex = new RegExp(escapedTerm, 'gi');

        for (let i = 0; i < parts.length; i++) {
            if (parts[i].type === 'text') {
                parts[i].content = parts[i].content.replace(regex, function(match) {
                    return `<span class="search-highlight">${match}</span>`;
                });
            }
        }
    }

    // Reconstruct the HTML
    let result = parts.map(p => p.content).join('');

    // Clean up nested highlighting that might occur
    result = result.replace(/<span class="search-highlight">([^<]*)<span class="search-highlight">([^<]*)<\/span>([^<]*)<\/span>/gi,
        '<span class="search-highlight">$1$2$3</span>');

    return result;
}

// Helper function to escape special regex characters
function escapeRegex(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// Convert URLs in text to clickable links (XSS-safe)
// Must be called AFTER escapeHtml since we're inserting HTML anchor tags
function linkifyUrls(text) {
    if (!text) return text;

    // Only match http://, https://, ftp:// URLs - never javascript: or data:
    const urlPattern = /\b((?:https?|ftp):\/\/[^\s<>&"']+)/gi;

    return text.replace(urlPattern, function(match, url) {
        // Double-check: only allow safe protocols
        if (!/^(https?|ftp):\/\//i.test(url)) {
            return match;
        }

        // Clean up trailing punctuation that's likely not part of the URL
        let cleanUrl = url;
        let trailing = '';
        const trailingMatch = cleanUrl.match(/([.,;:!?]+)$/);
        if (trailingMatch) {
            trailing = trailingMatch[1];
            cleanUrl = cleanUrl.slice(0, -trailing.length);
        }

        // Encode the URL for safe use in href attribute
        // escapeHtml was already called, so &amp; needs to be restored for valid URLs
        const safeUrl = cleanUrl
            .replace(/&amp;/g, '&')   // Restore & for valid URL params
            .replace(/"/g, '%22')     // Encode double quotes
            .replace(/'/g, '%27');    // Encode single quotes

        // Display URL keeps the escaped version for safe display
        return `<a href="${safeUrl}" target="_blank" rel="noopener noreferrer" class="message-link">${cleanUrl}</a>${trailing}`;
    });
}

function formatSingleKludgeLine(line) {
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
    } else if (line.startsWith('\x01Via')) {
        return `<span style="color: #ff69b4;">${escapedLine}</span>`;
    } else if (line.startsWith('\x01')) {
        // Generic kludge line
        return `<span style="color: #dc3545;">${escapedLine}</span>`;
    } else {
        return `<span style="color: #6c757d;">${escapedLine}</span>`;
    }
}

function formatKludgeLines(kludgeLines) {
    return kludgeLines.map(line => formatSingleKludgeLine(line)).join('\n');
}

function formatKludgeLinesWithSeparator(topKludges, bottomKludges) {
    let output = '';

    // Format top kludges
    if (topKludges && topKludges.length > 0) {
        output += topKludges.map(line => formatSingleKludgeLine(line)).join('\n');
    }

    // Add bottom kludges without separator
    if (bottomKludges && bottomKludges.length > 0) {
        if (output) {
            output += '\n';
        }
        output += bottomKludges.map(line => formatSingleKludgeLine(line)).join('\n');
    }

    if (output) {
        return output;
    }
    if (window.t) {
        return window.t('ui.common.no_kludge_lines_found', {}, 'No kludge lines found');
    }
    return 'No kludge lines found';
}

function toggleKludgeLines() {
    const container = $('#kludgeContainer');
    const btn = $('#toggleHeaders');

    if (container.is(':visible')) {
        container.slideUp();
        btn.removeClass('active');
    } else {
        container.slideDown();
        btn.addClass('active');
    }
}

// Print message — opens a clean new window containing only the message content.
// Also defined in echomail.js and netmail.js to avoid cache timing issues.
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

// Global user settings object
window.userSettings = {};

// Lightweight i18n client state with lazy namespace loading.
window.i18n = window.i18n || {
    locale: window.appLocale || 'en',
    defaultLocale: window.appDefaultLocale || 'en',
    catalogs: {},
    loadedNamespaces: {}
};

function i18nInterpolate(template, params = {}) {
    let output = String(template || '');
    Object.keys(params || {}).forEach(function(key) {
        const token = new RegExp(`\\{${key}\\}`, 'g');
        output = output.replace(token, String(params[key]));
    });
    return output;
}

function i18nLookup(key) {
    const catalogs = window.i18n?.catalogs || {};
    for (const ns of Object.keys(catalogs)) {
        if (Object.prototype.hasOwnProperty.call(catalogs[ns], key)) {
            return catalogs[ns][key];
        }
    }
    return null;
}

function t(key, params = {}, fallback = '') {
    const value = i18nLookup(key);
    if (typeof value === 'string') {
        return i18nInterpolate(value, params);
    }
    if (fallback) {
        return i18nInterpolate(fallback, params);
    }
    return key;
}

window.t = t;

function getApiErrorMessage(payload, fallback = 'An unexpected error occurred.') {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }
    if (payload.error_code) {
        const params = payload.error_params && typeof payload.error_params === 'object'
            ? payload.error_params
            : {};
        return t(payload.error_code, params, payload.error || fallback);
    }
    if (payload.error) {
        return String(payload.error);
    }
    return fallback;
}

window.getApiErrorMessage = getApiErrorMessage;

function getApiMessage(payload, fallback = '') {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }
    if (payload.message_code) {
        const params = payload.message_params && typeof payload.message_params === 'object'
            ? payload.message_params
            : {};
        return t(payload.message_code, params, payload.message || fallback);
    }
    if (payload.message) {
        return String(payload.message);
    }
    return fallback;
}

window.getApiMessage = getApiMessage;

function mergeCatalogs(catalogs) {
    if (!catalogs || typeof catalogs !== 'object') {
        return;
    }
    Object.keys(catalogs).forEach(function(ns) {
        if (!window.i18n.catalogs[ns]) {
            window.i18n.catalogs[ns] = {};
        }
        Object.assign(window.i18n.catalogs[ns], catalogs[ns] || {});
        window.i18n.loadedNamespaces[ns] = true;
    });
}

// In-flight namespace fetch promises keyed by sorted ns+locale string
const _i18nInflight = {};

function loadI18nNamespaces(namespaces = []) {
    const normalized = (namespaces || [])
        .map(ns => String(ns || '').trim())
        .filter(ns => ns.length > 0);
    if (normalized.length === 0) {
        return Promise.resolve();
    }

    const missing = normalized.filter(ns => !window.i18n.loadedNamespaces[ns]);
    if (missing.length === 0) {
        return Promise.resolve();
    }

    const localeParam = encodeURIComponent(window.i18n.locale || window.appLocale || 'en');
    const inflightKey = missing.slice().sort().join(',') + '@' + localeParam;

    // If a fetch for this exact set is already in-flight, reuse it
    if (_i18nInflight[inflightKey]) {
        return _i18nInflight[inflightKey];
    }

    // Mark namespaces as loading now to prevent duplicate fetches from
    // concurrent callers that haven't awaited the result yet
    missing.forEach(ns => { window.i18n.loadedNamespaces[ns] = true; });

    const nsParam = encodeURIComponent(missing.join(','));

    const promise = fetch(`/api/i18n/catalog?ns=${nsParam}&locale=${localeParam}`)
        .then(function(response) {
            if (!response.ok) {
                throw new Error('i18n catalog load failed');
            }
            return response.json();
        })
        .then(function(payload) {
            if (!payload || !payload.success) {
                throw new Error('invalid i18n payload');
            }
            if (payload.locale) {
                window.i18n.locale = payload.locale;
            }
            if (payload.default_locale) {
                window.i18n.defaultLocale = payload.default_locale;
            }
            mergeCatalogs(payload.catalogs || {});
        })
        .catch(function() {
            // Non-fatal: app keeps English literals as fallback.
            // Un-mark so a future call can retry
            missing.forEach(ns => { delete window.i18n.loadedNamespaces[ns]; });
        })
        .finally(function() {
            delete _i18nInflight[inflightKey];
        });

    _i18nInflight[inflightKey] = promise;
    return promise;
}

$(document).ready(function() {
    // Load user settings on page load
    loadUserSettings();
    
    // Global AJAX setup — attach CSRF token to every jQuery AJAX request
    $.ajaxSetup({
        beforeSend: function(xhr) {
            const token = document.querySelector('meta[name="csrf-token"]');
            if (token && token.content) {
                xhr.setRequestHeader('X-CSRF-Token', token.content);
            }
        },
        error: function(xhr, status, error) {
            if (xhr.status === 401) {
                // Redirect to login if unauthorized
                window.location.href = '/login';
            }
        }
    });
});

// Intercept native fetch() calls for same-origin state-changing requests
// so that templates using fetch() directly also send the CSRF token.
(function() {
    const _fetch = window.fetch;
    window.fetch = function(url, options) {
        options = options || {};
        const method = (options.method || 'GET').toUpperCase();
        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            const isSameOrigin = typeof url === 'string' &&
                (url.startsWith('/') || url.startsWith(window.location.origin));
            if (isSameOrigin) {
                const token = document.querySelector('meta[name="csrf-token"]');
                if (token && token.content) {
                    options.headers = options.headers || {};
                    if (options.headers instanceof Headers) {
                        options.headers.set('X-CSRF-Token', token.content);
                    } else {
                        options.headers['X-CSRF-Token'] = token.content;
                    }
                }
            }
        }
        return _fetch.call(this, url, options);
    };
}());

// Unified user settings management
function loadUserSettings() {
    if (window.__userSettingsPromise) {
        return window.__userSettingsPromise;
    }

    window.__userSettingsPromise = new Promise(function(resolve, reject) {
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

                window.i18n.locale = window.userSettings.locale || window.appLocale || window.i18n.locale || 'en';

                loadI18nNamespaces(window.appI18nNamespaces || ['common']).finally(function() {
                    // Apply font settings after loading
                    applyFontSettings();
                    resolve(window.userSettings);
                });
            })
            .fail(function() {
                console.log('Failed to load user settings, using defaults');
                // Set defaults
                window.userSettings = {
                    messages_per_page: 25,
                    threaded_view: false,
                    netmail_threaded_view: false,
                    quote_coloring: true,
                    chat_notification_sound: 'notify3',
                    echomail_notification_sound: 'disabled',
                    netmail_notification_sound: 'notify1',
                    file_notification_sound: 'disabled',
                    default_sort: 'date_desc',
                    timezone: 'America/Los_Angeles',
                    locale: window.appLocale || 'en',
                    font_family: 'Courier New, Monaco, Consolas, monospace',
                    font_size: 16,
                    signature_text: ''
                };

                window.i18n.locale = window.userSettings.locale || window.appLocale || 'en';
                loadI18nNamespaces(window.appI18nNamespaces || ['common']).finally(function() {
                    // Apply font settings after loading defaults
                    applyFontSettings();
                    resolve(window.userSettings);
                });
            });
    });

    return window.__userSettingsPromise;
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

function updateSessionActivity() {
    if (!window.currentUserId) {
        return;
    }
    const activity = document.title || '';
    fetch('/api/user/activity', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ activity })
    }).catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    updateSessionActivity();
});

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
            return t('time.soon', {}, 'Soon');
        } else if (absDays === 0) {
            return t('time.in_hours', {
                count: absHours,
                suffix: absHours !== 1 ? t('time.suffix_plural', {}, 's') : t('time.suffix_singular', {}, '')
            }, `In ${absHours} hour${absHours !== 1 ? 's' : ''}`);
        } else if (absDays === 1) {
            return t('time.tomorrow', {}, 'Tomorrow');
        } else {
            return t('time.in_days', { count: absDays }, `In ${absDays} days`);
        }
    }
    
    if (diffDays === 0) {
        if (diffHours === 0) {
            const diffMins = Math.floor(diffMs / (1000 * 60));
            return diffMins <= 1
                ? t('time.just_now', {}, 'Just now')
                : t('time.minutes_ago', { count: diffMins }, `${diffMins} minutes ago`);
        }
        return t('time.hours_ago', {
            count: diffHours,
            suffix: diffHours !== 1 ? t('time.suffix_plural', {}, 's') : t('time.suffix_singular', {}, '')
        }, `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`);
    } else if (diffDays === 1) {
        return t('time.yesterday', {}, 'Yesterday');
    } else if (diffDays < 7) {
        return t('time.days_ago', { count: diffDays }, `${diffDays} days ago`);
    } else {
        // Use user's preferred date format for older dates
        const userDateFormat = window.userSettings?.date_format || 'en-US';
        return date.toLocaleDateString(userDateFormat);
    }
}

function formatFidonetAddress(address, systemName) {
    if (!address) return '';
    const url = '/nodelist/view?address=' + encodeURIComponent(address);
    const titleText = systemName
        ? `${escapeHtml(systemName)} (${escapeHtml(address)})`
        : `View node ${escapeHtml(address)}`;
    return `<a href="${url}" class="fidonet-address text-decoration-none" title="${titleText}">${escapeHtml(address)}</a>`;
}

function formatFullDate(dateString) {
    // Database stores dates in UTC, so parse as UTC
    const date = new Date(dateString + 'Z'); // Add 'Z' to treat as UTC
    const userTimezone = window.userSettings?.timezone || 'America/Los_Angeles';
    const userDateFormat = window.userSettings?.date_format || 'en-US';

    return date.toLocaleString(userDateFormat, {
        timeZone: userTimezone,
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

/**
 * Toggle a loading-blur state on settings cards or any container.
 * While loading=true the target is blurred and non-interactive, and a
 * centred spinner overlay is shown above the blurred content.
 *
 * @param {string|Element|jQuery} target  CSS selector, DOM element, or jQuery object
 * @param {boolean}               loading true to apply blur, false to remove
 */
function setSettingsLoading(target, loading) {
    $(target).toggleClass('settings-loading', loading);

    const spinnerId = 'settings-loading-spinner';
    if (loading) {
        if (!document.getElementById(spinnerId)) {
            $('body').append(
                '<div id="' + spinnerId + '" class="settings-loading-spinner" aria-hidden="true">' +
                '<div class="spinner-border" role="status"></div>' +
                '</div>'
            );
        }
    } else {
        // Only remove spinner when no blurred elements remain
        if ($(target).hasClass('settings-loading') === false && $('.settings-loading').length === 0) {
            $('#' + spinnerId).remove();
        }
    }
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

// ── Shared FTN sender popover ─────────────────────────────────────────────────

const _ftnSenderPopoverCache = {};

/**
 * Look up an FTN node for a sender popover.  Point addresses (zone:net/node.point)
 * are resolved to the boss node.  Results are cached.
 *
 * @param  {string} address  FTN address string
 * @returns {Promise<{node: object|null, isPoint: boolean}>}
 */
function _lookupFtnNodeForPopover(address) {
    if (!address) return Promise.resolve({ node: null, isPoint: false });

    const pointMatch = address.match(/^(\d+:\d+\/\d+)\.(\d+)$/);
    const isPoint = !!(pointMatch && pointMatch[2] !== '0');
    const lookupAddr = isPoint ? pointMatch[1] : address;

    if (_ftnSenderPopoverCache[lookupAddr] !== undefined) {
        return Promise.resolve({ node: _ftnSenderPopoverCache[lookupAddr], isPoint });
    }

    return fetch(`/api/nodelist/node?address=${encodeURIComponent(lookupAddr)}`)
        .then(r => r.json())
        .then(data => {
            _ftnSenderPopoverCache[lookupAddr] = data.node || null;
            return { node: _ftnSenderPopoverCache[lookupAddr], isPoint };
        })
        .catch(() => {
            _ftnSenderPopoverCache[lookupAddr] = null;
            return { node: null, isPoint };
        });
}

/**
 * Build the inner HTML for a sender popover, optionally including BBS name from nodelist.
 *
 * @param {string}      fromName
 * @param {string}      fromAddress
 * @param {string}      toAddress    - Address for the Send Netmail button
 * @param {string}      toName
 * @param {string}      subject      - Optional subject to pre-fill
 * @param {object|null} node         - Nodelist entry (may be null)
 * @param {boolean}     isPoint      - Whether fromAddress is a point address
 * @returns {string}  HTML string
 */
function _buildFtnSenderPopoverHtml(fromName, fromAddress, toAddress, toName, subject, node, isPoint) {
    const parts = [`<div class="fw-semibold">${escapeHtml(fromName)}</div>`];

    if (node && node.system_name) {
        let bbs = escapeHtml(node.system_name);
        if (isPoint) bbs += ` <span class="text-muted">(${uiT('ui.nodelist.point', 'Point')})</span>`;
        parts.push(`<div class="text-muted small">${bbs}</div>`);
        if (node.location) {
            parts.push(`<div class="text-muted small">${escapeHtml(node.location)}</div>`);
        }
    }

    if (fromAddress) {
        parts.push(`<div class="text-muted small font-monospace">${escapeHtml(fromAddress)}</div>`);
    }

    if (toAddress || (node && node.address)) {
        parts.push('<div class="mt-2 d-grid gap-1">');
        if (toAddress) {
            let url = `/compose/netmail?to=${encodeURIComponent(toAddress)}&to_name=${encodeURIComponent(toName)}`;
            if (subject) url += `&subject=${encodeURIComponent(subject)}`;
            parts.push(`<a href="${url}" class="btn btn-sm btn-primary">${uiT('ui.nodelist.send_netmail', 'Send Netmail')}</a>`);
        }
        if (node && node.address) {
            parts.push(`<a href="/nodelist/view?address=${encodeURIComponent(node.address)}" class="btn btn-sm btn-outline-secondary">${uiT('ui.nodelist.view_full_details', 'View full node details')}</a>`);
        }
        parts.push('</div>');
    }

    return parts.join('');
}

/**
 * Return cached nodelist data for an FTN address synchronously, or null if not
 * yet fetched.  Applies the same point→boss resolution as _lookupFtnNodeForPopover.
 *
 * @param  {string} address
 * @returns {{ node: object|null, isPoint: boolean, hit: boolean }}
 */
function _ftnNodeFromCache(address) {
    if (!address) return { node: null, isPoint: false, hit: true };
    const pointMatch = address.match(/^(\d+:\d+\/\d+)\.(\d+)$/);
    const isPoint = !!(pointMatch && pointMatch[2] !== '0');
    const lookupAddr = isPoint ? pointMatch[1] : address;
    const hit = Object.prototype.hasOwnProperty.call(_ftnSenderPopoverCache, lookupAddr);
    return { node: hit ? _ftnSenderPopoverCache[lookupAddr] : null, isPoint, hit };
}

/**
 * Show a sender-name popover on `el`, performing an async nodelist lookup.
 * The popover is always recreated on open so the content option is always
 * current — avoids stale-spinner issues from Bootstrap re-rendering the
 * original content option on subsequent show() calls.
 *
 * @param {Element} el
 * @param {object}  opts
 * @param {string}  opts.fromName
 * @param {string}  opts.fromAddress
 * @param {string}  opts.toAddress
 * @param {string}  opts.toName
 * @param {string}  [opts.subject]
 * @param {string}  [opts.placement]       - Bootstrap placement (default 'bottom')
 * @param {string}  [opts.siblingSelector] - CSS selector for sibling popovers to close
 */
function showFtnSenderPopover(el, opts) {
    if (opts.siblingSelector) {
        document.querySelectorAll(opts.siblingSelector).forEach(function(other) {
            if (other !== el) {
                const p = bootstrap.Popover.getInstance(other);
                if (p) p.hide();
            }
        });
    }

    const existing = bootstrap.Popover.getInstance(el);
    if (existing) {
        // If currently visible, close it (toggle behaviour)
        if (el.getAttribute('aria-describedby')) {
            existing.hide();
            return;
        }
        // Hidden but instance still attached — dispose so we recreate below
        existing.dispose();
    }

    // Use cached node data immediately if available so there is no spinner
    // on repeat opens of the same address.
    const address = opts.fromAddress || '';
    const cached = _ftnNodeFromCache(address);
    const initialContent = cached.hit
        ? _buildFtnSenderPopoverHtml(opts.fromName || '', address, opts.toAddress || '', opts.toName || '', opts.subject || '', cached.node, cached.isPoint)
        : '<i class="fas fa-spinner fa-spin"></i>';

    const pop = new bootstrap.Popover(el, {
        html: true,
        content: initialContent,
        trigger: 'manual',
        placement: opts.placement || 'bottom',
        sanitize: false,
    });
    pop.show();

    if (!cached.hit) {
        _lookupFtnNodeForPopover(address).then(function(result) {
            if (!bootstrap.Popover.getInstance(el)) return; // dismissed before lookup finished
            const html = _buildFtnSenderPopoverHtml(
                opts.fromName || '', address,
                opts.toAddress || '', opts.toName || '',
                opts.subject || '', result.node, result.isPoint
            );
            const popId = el.getAttribute('aria-describedby');
            const popEl = popId ? document.getElementById(popId) : null;
            if (popEl) popEl.querySelector('.popover-body').innerHTML = html;
        });
    }
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Initialise a click handler on the sender name element in message views.
 *
 * @param {object} message      - Message object with from_name, from_address
 * @param {string} netmailAddr  - FTN address to pre-fill in the netmail compose link
 * @param {string} netmailName  - Display name to pre-fill in the netmail compose link
 */
function initSenderPopover(message, netmailAddr, netmailName) {
    const el = document.getElementById('senderNamePopoverTrigger');
    if (!el) return;

    const existing = bootstrap.Popover.getInstance(el);
    if (existing) existing.dispose();

    const toAddr = netmailAddr || message.from_address || '';
    const toName = netmailName || message.from_name || '';

    el.onclick = function(e) {
        e.stopPropagation();
        showFtnSenderPopover(el, {
            fromName: message.from_name || '',
            fromAddress: message.from_address || '',
            toAddress: toAddr,
            toName: toName,
            placement: 'bottom',
        });
    };

    $(document).off('click.senderPopoverDismiss').on('click.senderPopoverDismiss', function(e) {
        if (!$(e.target).closest('#senderNamePopoverTrigger, .popover').length) {
            const pop = bootstrap.Popover.getInstance(el);
            if (pop) pop.hide();
        }
    });
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
            showError(t('errors.failed_load_messages', {}, 'Failed to load messages'));
        });
}

function displayMessages(messages, type) {
    const container = $('#messagesContainer');
    let html = '';
    
    if (messages.length === 0) {
        html = `<div class="text-center text-muted py-4">${t('messages.none_found', {}, 'No messages found')}</div>`;
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
                    <div class="message-subject">${escapeHtml(msg.subject || t('messages.no_subject', {}, '(No Subject)'))}</div>
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
    if (typeof email !== 'string') {
        return false;
    }
    const trimmed = email.trim();
    if (trimmed.length === 0 || trimmed.length > 320) {
        return false;
    }
    const atIndex = trimmed.indexOf('@');
    if (atIndex <= 0 || atIndex !== trimmed.lastIndexOf('@')) {
        return false;
    }
    const local = trimmed.slice(0, atIndex);
    const domain = trimmed.slice(atIndex + 1);
    if (local.length === 0 || domain.length === 0) {
        return false;
    }
    if (/\s/.test(local) || /\s/.test(domain)) {
        return false;
    }
    if (domain.indexOf('.') === -1) {
        return false;
    }
    return true;
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
            ${window.t ? window.t('ui.common.loading', {}, 'Loading...') : 'Loading...'}
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

$(document).ready(function(){

    // Initialize tooltips and popovers
    $(function () {
        $('[data-bs-toggle="tooltip"]').tooltip();
        $('[data-bs-toggle="popover"]').popover();
    });

    // Handle page unload
    $(window).on('beforeunload', function() {
        stopAutoRefresh();
    });
})
