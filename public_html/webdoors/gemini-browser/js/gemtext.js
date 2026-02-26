/**
 * gemtext.js — Gemtext (.gmi) renderer
 *
 * Converts Gemtext content to HTML according to the Gemini specification.
 * Exposes two globals: renderGemtext() and resolveGeminiUrl()
 *
 * Line types handled:
 *   =>  URL [label]   — link
 *   #   text          — h1
 *   ##  text          — h2
 *   ### text          — h3
 *   *   text          — list item
 *   >   text          — blockquote
 *   ``` [alt]         — preformatted toggle
 *   (anything else)   — paragraph / blank line
 */

'use strict';

/**
 * Escape HTML special characters.
 * @param {string} str
 * @returns {string}
 */
function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Resolve a (possibly relative) URL against a Gemini base URL.
 * Handles absolute URLs, absolute paths, and relative paths with dot segments.
 *
 * @param {string} base     - Current page URL (gemini://…)
 * @param {string} relative - URL to resolve
 * @returns {string}
 */
function resolveGeminiUrl(base, relative) {
    // Already absolute?
    if (/^[a-z][a-z0-9+\-.]*:\/\//i.test(relative)) {
        return relative;
    }

    // Parse base: gemini://host[:port]/path[?query]
    const baseMatch = base.match(/^(gemini:\/\/[^/?#]*)(\/[^?#]*)?(\?[^#]*)?/i);
    if (!baseMatch) return relative;

    const origin   = baseMatch[1];               // gemini://host[:port]
    const basePath = baseMatch[2] || '/';        // /path/to/page

    if (relative.startsWith('/')) {
        return origin + relative;
    }

    // Relative path — resolve against the directory of basePath
    const dir = basePath.substring(0, basePath.lastIndexOf('/') + 1);
    const raw = dir + relative;

    // Collapse dot segments (RFC 3986 §5.2.4)
    const parts    = raw.split('/');
    const resolved = [];
    for (const part of parts) {
        if (part === '..') {
            if (resolved.length > 1) resolved.pop(); // keep leading ''
        } else if (part !== '.') {
            resolved.push(part);
        }
    }

    return origin + resolved.join('/');
}

/**
 * Render Gemtext content to an HTML string.
 *
 * @param {string} text    - Raw gemtext content
 * @param {string} baseUrl - URL of the page (used to resolve relative links)
 * @returns {string}       - HTML string
 */
function renderGemtext(text, baseUrl) {
    const lines = text.split(/\r?\n/);
    let html    = '';
    let inPre   = false;
    let preBuf  = '';
    let preAlt  = '';

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        // ── Preformatted toggle ──────────────────────────────────────────────
        if (line.startsWith('```')) {
            if (inPre) {
                const altAttr = preAlt ? ` aria-label="${escapeHtml(preAlt)}"` : '';
                html  += `<pre class="gmi-pre"${altAttr}>${escapeHtml(preBuf)}</pre>\n`;
                preBuf = '';
                preAlt = '';
                inPre  = false;
            } else {
                preAlt = line.slice(3).trim();
                inPre  = true;
            }
            continue;
        }

        if (inPre) {
            preBuf += line + '\n';
            continue;
        }

        // ── Link line ────────────────────────────────────────────────────────
        if (line.startsWith('=>')) {
            const m = line.match(/^=>\s*(\S+)(?:\s+(.+))?$/);
            if (m) {
                const href  = m[1];
                const label = m[2] ? m[2].trim() : href;
                const abs   = resolveGeminiUrl(baseUrl, href);
                const isGem = abs.startsWith('gemini://');
                const cls   = isGem ? 'gmi-a gmi-internal' : 'gmi-a gmi-external';
                const tgt   = isGem ? '' : ' target="_blank" rel="noopener noreferrer"';
                html += `<p class="gmi-link"><a href="${escapeHtml(abs)}" class="${cls}" data-url="${escapeHtml(abs)}"${tgt}>${escapeHtml(label)}</a></p>\n`;
            }
            continue;
        }

        // ── Headings ─────────────────────────────────────────────────────────
        if (line.startsWith('### ')) {
            html += `<h3 class="gmi-h3">${escapeHtml(line.slice(4))}</h3>\n`;
            continue;
        }
        if (line.startsWith('## ')) {
            html += `<h2 class="gmi-h2">${escapeHtml(line.slice(3))}</h2>\n`;
            continue;
        }
        if (line.startsWith('# ')) {
            html += `<h1 class="gmi-h1">${escapeHtml(line.slice(2))}</h1>\n`;
            continue;
        }

        // ── List item ────────────────────────────────────────────────────────
        if (line.startsWith('* ')) {
            html += `<p class="gmi-li">${escapeHtml(line.slice(2))}</p>\n`;
            continue;
        }

        // ── Blockquote ───────────────────────────────────────────────────────
        if (line.startsWith('>')) {
            html += `<blockquote class="gmi-quote">${escapeHtml(line.slice(1).trim())}</blockquote>\n`;
            continue;
        }

        // ── Paragraph / blank ────────────────────────────────────────────────
        if (line.trim() === '') {
            html += '<div class="gmi-blank"></div>\n';
        } else {
            html += `<p class="gmi-p">${escapeHtml(line)}</p>\n`;
        }
    }

    // Close any unclosed preformatted block
    if (inPre && preBuf) {
        html += `<pre class="gmi-pre">${escapeHtml(preBuf)}</pre>\n`;
    }

    return html;
}
