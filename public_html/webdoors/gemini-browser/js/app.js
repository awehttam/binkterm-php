/**
 * app.js — Gemini Browser WebDoor
 *
 * Handles navigation, page rendering, input prompts, and bookmarks.
 * Depends on gemtext.js (renderGemtext, resolveGeminiUrl, escapeHtml).
 */

'use strict';

const GeminiBrowser = (() => {

    // ── State ─────────────────────────────────────────────────────────────────
    let navHistory      = [];      // visited URLs
    let histPos         = -1;      // current position in navHistory
    let currentUrl      = '';
    let bookmarks       = [];
    let config          = { home_url: 'gemini://kennedy.gemi.dev/' };
    let pendingInputUrl = '';      // URL awaiting user input (status 1x)

    // ── DOM refs ──────────────────────────────────────────────────────────────
    let el = {};

    // ── Initialise ────────────────────────────────────────────────────────────
    async function init() {
        el = {
            urlBar:        document.getElementById('url-bar'),
            btnBack:       document.getElementById('btn-back'),
            btnForward:    document.getElementById('btn-forward'),
            btnHome:       document.getElementById('btn-home'),
            btnGo:         document.getElementById('btn-go'),
            btnBmToggle:   document.getElementById('btn-bm-toggle'),
            btnPanel:      document.getElementById('btn-panel'),
            contentWrap:   document.getElementById('content-wrap'),
            content:       document.getElementById('content'),
            loading:       document.getElementById('loading'),
            panel:         document.getElementById('bookmarks-panel'),
            bmList:        document.getElementById('bookmarks-list'),
            btnClosePanel: document.getElementById('btn-close-panel'),
            statusText:    document.getElementById('status-text'),
            inputModal:    document.getElementById('input-modal'),
            inputLabel:    document.getElementById('input-prompt-label'),
            inputField:    document.getElementById('input-field'),
            btnInputOk:    document.getElementById('btn-input-submit'),
            btnInputCancel:document.getElementById('btn-input-cancel'),
        };

        bindEvents();

        // Load config then bookmarks, then navigate home
        await loadConfig();
        await loadBookmarks();
        navigate(config.home_url);
    }

    // ── Event bindings ────────────────────────────────────────────────────────
    function bindEvents() {
        el.btnBack.addEventListener('click',    goBack);
        el.btnForward.addEventListener('click', goForward);
        el.btnHome.addEventListener('click',    () => navigate(config.home_url));
        el.btnGo.addEventListener('click',      navigateToBar);
        el.urlBar.addEventListener('keydown',   e => { if (e.key === 'Enter') navigateToBar(); });

        el.btnBmToggle.addEventListener('click', toggleBookmark);
        el.btnPanel.addEventListener('click',    togglePanel);
        el.btnClosePanel.addEventListener('click', closePanel);

        // Intercept clicks on gemini:// links inside the content pane
        el.content.addEventListener('click', e => {
            const link = e.target.closest('a[data-url]');
            if (!link) return;
            const url = link.dataset.url;
            if (url && url.startsWith('gemini://')) {
                e.preventDefault();
                navigate(url);
            }
            // Non-gemini links already have target="_blank", browser handles them
        });

        el.btnInputOk.addEventListener('click',     submitInput);
        el.btnInputCancel.addEventListener('click',  dismissInput);
        el.inputField.addEventListener('keydown', e => {
            if (e.key === 'Enter')  submitInput();
            if (e.key === 'Escape') dismissInput();
        });
    }

    // ── Navigation ────────────────────────────────────────────────────────────

    function navigateToBar() {
        let url = el.urlBar.value.trim();
        if (!url) return;
        // Be forgiving: add scheme if missing
        if (!url.includes('://')) url = 'gemini://' + url;
        navigate(url);
    }

    /**
     * Navigate to a URL.
     * @param {string}  url
     * @param {boolean} push - Whether to push onto history (false when going back/forward)
     */
    async function navigate(url, push = true) {
        if (!url) return;

        currentUrl = url;
        el.urlBar.value = url;
        setStatus('Loading \u2026');
        setLoading(true);
        updateBmButton();

        if (push) {
            navHistory = navHistory.slice(0, histPos + 1);
            navHistory.push(url);
            histPos = navHistory.length - 1;
        }
        updateNavButtons();

        try {
            const res = await fetchPage(url);
            handleResponse(res, url);
        } catch (err) {
            showError('Request failed: ' + err.message);
        } finally {
            setLoading(false);
        }
    }

    function goBack() {
        if (histPos > 0) {
            histPos--;
            navigate(navHistory[histPos], false);
        }
    }

    function goForward() {
        if (histPos < navHistory.length - 1) {
            histPos++;
            navigate(navHistory[histPos], false);
        }
    }

    function updateNavButtons() {
        el.btnBack.disabled    = histPos <= 0;
        el.btnForward.disabled = histPos >= navHistory.length - 1;
    }

    // ── Response handling ─────────────────────────────────────────────────────

    function handleResponse(res, url) {
        if (!res.success && res.error) {
            showError(res.error);
            return;
        }

        const status = res.status || 0;
        const meta   = res.meta   || '';

        // 1x — Input required
        if (status >= 10 && status < 20) {
            showInputPrompt(url, meta, status === 11);
            return;
        }

        // 2x — Success
        if (status >= 20 && status < 30) {
            const rawMime = res.mime || 'text/gemini';
            const mime    = rawMime.split(';')[0].trim().toLowerCase();

            if (mime === 'text/gemini' || mime === '') {
                renderGemtextPage(res.body || '', url);
            } else if (mime.startsWith('text/')) {
                renderPlainText(res.body || '');
            } else {
                showInfo(`This page returned content of type <strong>${escapeHtml(rawMime)}</strong>, which cannot be displayed inline.`);
            }
            setStatus(url);
            return;
        }

        // 3x — Redirect (should have been followed server-side, but handle gracefully)
        if (status >= 30 && status < 40) {
            if (meta) {
                const resolved = resolveGeminiUrl(url, meta);
                showInfo(`Redirect to <a href="${escapeHtml(resolved)}" class="gmi-a gmi-internal" data-url="${escapeHtml(resolved)}">${escapeHtml(resolved)}</a>`);
            } else {
                showWarn(`Redirect (${status}) with no destination.`);
            }
            return;
        }

        // 4x — Temporary failure
        if (status >= 40 && status < 50) {
            showError(`Temporary failure (${status}): ${escapeHtml(meta || 'No details provided.')}`);
            return;
        }

        // 5x — Permanent failure
        if (status >= 50 && status < 60) {
            showError(`Permanent failure (${status}): ${escapeHtml(meta || 'No details provided.')}`);
            return;
        }

        // 6x — Client certificate required
        if (status >= 60 && status < 70) {
            showWarn(`This page requires a client certificate (${status}: ${escapeHtml(meta || '')}). Client certificates are not currently supported by this browser.`);
            return;
        }

        showError(`Unexpected status code ${status} from server.`);
    }

    function renderGemtextPage(body, url) {
        el.content.innerHTML =
            `<p class="gmi-meta">${escapeHtml(url)}</p>` +
            renderGemtext(body, url);
        el.contentWrap.scrollTop = 0;
        updateBmButton();
    }

    function renderPlainText(body) {
        el.content.innerHTML = `<pre class="gmi-pre">${escapeHtml(body)}</pre>`;
        el.contentWrap.scrollTop = 0;
    }

    function showError(html) {
        el.content.innerHTML = `<div class="gmi-error">\u2717 ${html}</div>`;
        setStatus('Error');
    }

    function showWarn(html) {
        el.content.innerHTML = `<div class="gmi-warn">\u26a0 ${html}</div>`;
        setStatus('Warning');
    }

    function showInfo(html) {
        el.content.innerHTML = `<div class="gmi-info">${html}</div>`;
    }

    function setLoading(show) {
        el.loading.hidden = !show;
        if (show) el.content.innerHTML = '';
    }

    function setStatus(msg) {
        el.statusText.textContent = msg;
    }

    // ── Input prompt (Gemini 1x) ──────────────────────────────────────────────

    function showInputPrompt(url, prompt, sensitive) {
        pendingInputUrl          = url;
        el.inputLabel.textContent = prompt || 'Enter input:';
        el.inputField.type        = sensitive ? 'password' : 'text';
        el.inputField.value       = '';
        el.inputModal.classList.remove('hidden');
        el.inputField.focus();
    }

    function submitInput() {
        const value = el.inputField.value;
        el.inputModal.classList.add('hidden');
        if (!pendingInputUrl) return;
        // Per Gemini spec: query = percent-encoded user input
        const base     = pendingInputUrl.split('?')[0];
        const queryUrl = base + '?' + encodeURIComponent(value);
        pendingInputUrl = '';
        navigate(queryUrl);
    }

    function dismissInput() {
        el.inputModal.classList.add('hidden');
        pendingInputUrl = '';
        setStatus('Ready');
    }

    // ── Bookmarks ─────────────────────────────────────────────────────────────

    async function loadBookmarks() {
        try {
            const res = await apiGet('api.php?action=bookmark_list');
            bookmarks = res.bookmarks || [];
            renderBmList();
        } catch (_) { /* non-critical — continue without bookmarks */ }
    }

    function renderBmList() {
        if (bookmarks.length === 0) {
            el.bmList.innerHTML = '<div class="bookmarks-empty">No bookmarks yet.</div>';
            return;
        }

        el.bmList.innerHTML = bookmarks.map((bm, i) =>
            `<div class="bookmark-item" data-url="${escapeHtml(bm.url)}" data-index="${i}">
                <span class="bookmark-title" title="${escapeHtml(bm.url)}">${escapeHtml(bm.title)}</span>
                <button class="bookmark-remove" data-index="${i}" title="Remove">\u00d7</button>
            </div>`
        ).join('');

        el.bmList.querySelectorAll('.bookmark-item').forEach(item => {
            item.addEventListener('click', e => {
                if (e.target.classList.contains('bookmark-remove')) return;
                navigate(item.dataset.url);
            });
        });

        el.bmList.querySelectorAll('.bookmark-remove').forEach(btn => {
            btn.addEventListener('click', async e => {
                e.stopPropagation();
                const url = btn.closest('.bookmark-item').dataset.url;
                await removeBookmark(url);
            });
        });
    }

    async function toggleBookmark() {
        if (!currentUrl || !currentUrl.startsWith('gemini://')) return;
        const exists = bookmarks.some(b => b.url === currentUrl);
        if (exists) {
            await removeBookmark(currentUrl);
        } else {
            await addBookmark(currentUrl);
        }
    }

    async function addBookmark(url) {
        // Use the page's first h1 as the title, falling back to the URL
        let title = url;
        const h1 = el.content.querySelector('.gmi-h1');
        if (h1 && h1.textContent.trim()) title = h1.textContent.trim();

        try {
            const res = await apiPost('api.php?action=bookmark_add', { url, title });
            bookmarks = res.bookmarks || bookmarks;
            renderBmList();
            updateBmButton();
        } catch (_) { /* non-critical */ }
    }

    async function removeBookmark(url) {
        try {
            const res = await apiPost('api.php?action=bookmark_remove', { url });
            bookmarks = res.bookmarks || bookmarks;
            renderBmList();
            updateBmButton();
        } catch (_) { /* non-critical */ }
    }

    function updateBmButton() {
        const active = bookmarks.some(b => b.url === currentUrl);
        el.btnBmToggle.classList.toggle('active', active);
        el.btnBmToggle.title = active ? 'Remove bookmark' : 'Bookmark this page';
    }

    function togglePanel() {
        el.panel.classList.toggle('hidden');
    }

    function closePanel() {
        el.panel.classList.add('hidden');
    }

    // ── API helpers ───────────────────────────────────────────────────────────

    async function loadConfig() {
        try {
            const res = await apiGet('api.php?action=config');
            config = Object.assign(config, res);
        } catch (_) { /* use defaults */ }
    }

    async function fetchPage(url) {
        const params = new URLSearchParams({ action: 'fetch', url });
        const res = await fetch(`api.php?${params}`);
        if (!res.ok) {
            const body = await res.json().catch(() => ({}));
            throw new Error(body.error || `HTTP ${res.status}`);
        }
        return res.json();
    }

    async function apiGet(endpoint) {
        const res = await fetch(endpoint);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    async function apiPost(endpoint, data) {
        const res = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    // ── Public API ────────────────────────────────────────────────────────────
    return { init };

})();

document.addEventListener('DOMContentLoaded', () => GeminiBrowser.init());
