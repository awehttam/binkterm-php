/**
 * InterestPicker — reusable interest selection/subscription widget.
 *
 * Modes:
 *   'subscribe' (default) — renders a grid of interest cards each with a
 *                           Subscribe / Unsubscribe toggle that persists to
 *                           the server via POST /api/interests/{id}/subscribe
 *                           and /unsubscribe.
 *
 *   'select'              — renders the same grid but with checkboxes so the
 *                           caller can collect a list of selected interest IDs
 *                           (e.g. for "Find echo areas by interest").
 *                           Call getSelectedIds() to read the current selection.
 *                           Pass onSelectionChange(ids) to react immediately.
 *
 * Usage (subscribe mode):
 *   const picker = new InterestPicker('my-container');
 *   picker.load();
 *
 * Usage (select mode):
 *   const picker = new InterestPicker('my-container', {
 *       mode: 'select',
 *       selectedIds: [3, 7],
 *       onSelectionChange: (ids) => console.log(ids),
 *   });
 *   picker.load();
 *   // later: picker.getSelectedIds()
 */
class InterestPicker {
    /**
     * @param {string|HTMLElement} container  Element ID string or DOM node.
     * @param {object}             options
     * @param {'subscribe'|'select'} [options.mode='subscribe']
     * @param {number[]}           [options.selectedIds=[]]  Pre-selected IDs (select mode).
     * @param {function}           [options.onSelectionChange]  Called with array of selected IDs.
     */
    constructor(container, options = {}) {
        this._el = typeof container === 'string'
            ? document.getElementById(container)
            : container;

        this._mode              = options.mode || 'subscribe';
        this._selectedIds       = new Set(options.selectedIds || []);
        this._onSelectionChange = options.onSelectionChange || null;
        this._interests         = [];
        this._busy              = new Set(); // IDs currently being toggled
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /** Fetch interests from the server and render. */
    async load() {
        this._renderLoading();
        try {
            const resp = await fetch('/api/interests');
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            this._interests = data.interests || [];

            // In subscribe mode, seed selection from server's subscribed flags.
            if (this._mode === 'subscribe') {
                this._selectedIds = new Set(
                    this._interests.filter(i => i.subscribed).map(i => i.id)
                );
            }

            this._render();
        } catch (err) {
            this._renderError(err.message);
        }
    }

    /** Returns the currently selected/subscribed interest IDs (select mode). */
    getSelectedIds() {
        return [...this._selectedIds];
    }

    /** Programmatically set selected IDs (select mode). Re-renders. */
    setSelectedIds(ids) {
        this._selectedIds = new Set(ids);
        this._render();
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    _renderLoading() {
        this._el.innerHTML =
            '<div class="text-center text-muted py-5">' +
            '<i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>' +
            (window.t ? window.t('ui.loading', {}, 'Loading…') : 'Loading…') +
            '</div>';
    }

    _renderError(msg) {
        this._el.innerHTML =
            '<div class="alert alert-danger">' +
            '<i class="fas fa-exclamation-triangle me-2"></i>' +
            InterestPicker._escHtml(msg) +
            '</div>';
    }

    _render() {
        if (!this._interests.length) {
            const msg = window.t
                ? window.t('ui.interests.no_interests', {}, 'No interests have been defined yet.')
                : 'No interests have been defined yet.';
            this._el.innerHTML = '<div class="alert alert-info">' + InterestPicker._escHtml(msg) + '</div>';
            return;
        }

        const isSelect = this._mode === 'select';

        const cards = this._interests.map(interest => {
            const subscribed  = this._selectedIds.has(interest.id);
            const colorStyle  = `border-top: 3px solid ${InterestPicker._escAttr(interest.color || '#6c757d')}`;
            const iconStyle   = `color: ${InterestPicker._escAttr(interest.color || '#6c757d')}`;
            const echoCount   = window.t
                ? window.t('ui.interests.area_count', { count: interest.echoarea_count ?? 0 }, '{count} echo area(s)')
                : `${interest.echoarea_count ?? 0} echo area(s)`;
            const subCount    = window.t
                ? window.t('ui.interests.subscriber_count', { count: interest.subscriber_count ?? 0 }, '{count} subscriber(s)')
                : `${interest.subscriber_count ?? 0} subscriber(s)`;

            if (isSelect) {
                const checked = subscribed ? 'checked' : '';
                return `<div class="col">
                    <div class="card h-100 interest-card ${subscribed ? 'interest-selected' : ''}"
                         style="${colorStyle}" data-id="${interest.id}">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="fas ${InterestPicker._escHtml(interest.icon || 'fa-layer-group')} fa-lg flex-shrink-0"
                                   style="${iconStyle}"></i>
                                <h6 class="card-title mb-0 fw-semibold">${InterestPicker._escHtml(interest.name)}</h6>
                                <div class="ms-auto form-check mb-0">
                                    <input class="form-check-input interest-select-check" type="checkbox"
                                           id="ipick_${interest.id}" value="${interest.id}" ${checked}
                                           onchange="InterestPicker._handleSelectChange(this)">
                                </div>
                            </div>
                            ${interest.description
                                ? `<p class="card-text small text-muted flex-grow-1">${InterestPicker._escHtml(interest.description)}</p>`
                                : '<div class="flex-grow-1"></div>'}
                            <div class="d-flex gap-2 mt-2 small text-muted">
                                <span><i class="fas fa-comments me-1"></i>${InterestPicker._escHtml(echoCount)}</span>
                                <span><i class="fas fa-users me-1"></i>${InterestPicker._escHtml(subCount)}</span>
                            </div>
                        </div>
                    </div>
                </div>`;
            }

            // Subscribe mode
            const busy    = this._busy.has(interest.id);
            const btnIcon = busy ? 'fa-spinner fa-spin' : (subscribed ? 'fa-check' : 'fa-plus');
            const btnClass = subscribed ? 'btn-success' : 'btn-outline-secondary';
            const btnLabel = subscribed
                ? (window.t ? window.t('ui.interests.subscribed', {}, 'Subscribed') : 'Subscribed')
                : (window.t ? window.t('ui.interests.subscribe', {}, 'Subscribe') : 'Subscribe');

            return `<div class="col">
                <div class="card h-100 interest-card" style="${colorStyle}" data-id="${interest.id}">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="fas ${InterestPicker._escHtml(interest.icon || 'fa-layer-group')} fa-lg flex-shrink-0"
                               style="${iconStyle}"></i>
                            <h6 class="card-title mb-0 fw-semibold">${InterestPicker._escHtml(interest.name)}</h6>
                        </div>
                        ${interest.description
                            ? `<p class="card-text small text-muted flex-grow-1">${InterestPicker._escHtml(interest.description)}</p>`
                            : '<div class="flex-grow-1"></div>'}
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <div class="small text-muted">
                                <span class="me-2"><i class="fas fa-comments me-1"></i>${InterestPicker._escHtml(echoCount)}</span>
                                <span><i class="fas fa-users me-1"></i>${InterestPicker._escHtml(subCount)}</span>
                            </div>
                            <button class="btn btn-sm ${btnClass} interest-sub-btn"
                                    data-id="${interest.id}"
                                    data-subscribed="${subscribed ? '1' : '0'}"
                                    ${busy ? 'disabled' : ''}
                                    onclick="InterestPicker._handleSubClick(this)">
                                <i class="fas ${btnIcon} me-1"></i>${InterestPicker._escHtml(btnLabel)}
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
        }).join('');

        this._el.innerHTML =
            `<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3">${cards}</div>`;

        // Store picker reference on element so static callbacks can find it.
        this._el.dataset.ipickerInstance = InterestPicker._register(this);
    }

    // -------------------------------------------------------------------------
    // Subscribe-mode toggle
    // -------------------------------------------------------------------------

    async _toggleSubscription(id, currentlySubscribed) {
        if (this._busy.has(id)) return;
        this._busy.add(id);
        this._refreshCard(id);

        const action = currentlySubscribed ? 'unsubscribe' : 'subscribe';
        try {
            const resp = await fetch(`/api/interests/${id}/${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);

            if (currentlySubscribed) {
                this._selectedIds.delete(id);
            } else {
                this._selectedIds.add(id);
            }
        } catch (err) {
            // Revert on error — interest stays in its previous state.
            console.error('[InterestPicker] toggle failed:', err);
        } finally {
            this._busy.delete(id);
            this._refreshCard(id);
        }
    }

    /** Re-render just the button / card for one interest ID. */
    _refreshCard(id) {
        const interest = this._interests.find(i => i.id === id);
        if (!interest) return;

        const btn = this._el.querySelector(`.interest-sub-btn[data-id="${id}"]`);
        if (!btn) return;

        const subscribed = this._selectedIds.has(id);
        const busy       = this._busy.has(id);

        btn.className = `btn btn-sm ${subscribed ? 'btn-success' : 'btn-outline-secondary'} interest-sub-btn`;
        btn.disabled  = busy;
        btn.dataset.subscribed = subscribed ? '1' : '0';

        const label = subscribed
            ? (window.t ? window.t('ui.interests.subscribed', {}, 'Subscribed') : 'Subscribed')
            : (window.t ? window.t('ui.interests.subscribe', {}, 'Subscribe') : 'Subscribe');
        btn.innerHTML = `<i class="fas ${busy ? 'fa-spinner fa-spin' : (subscribed ? 'fa-check' : 'fa-plus')} me-1"></i>${InterestPicker._escHtml(label)}`;
    }

    // -------------------------------------------------------------------------
    // Select-mode toggle
    // -------------------------------------------------------------------------

    _handleSelect(checkbox) {
        const id = parseInt(checkbox.value);
        if (checkbox.checked) {
            this._selectedIds.add(id);
        } else {
            this._selectedIds.delete(id);
        }

        // Highlight the card.
        const card = this._el.querySelector(`.interest-card[data-id="${id}"]`);
        if (card) card.classList.toggle('interest-selected', checkbox.checked);

        if (this._onSelectionChange) {
            this._onSelectionChange(this.getSelectedIds());
        }
    }

    // -------------------------------------------------------------------------
    // Static helpers (used by inline onclick handlers)
    // -------------------------------------------------------------------------

    static _instances = {};
    static _nextId    = 0;

    static _register(instance) {
        const key = 'ip_' + (InterestPicker._nextId++);
        InterestPicker._instances[key] = instance;
        return key;
    }

    static _handleSubClick(btn) {
        const container = btn.closest('[data-ipicker-instance]');
        if (!container) return;
        const instance = InterestPicker._instances[container.dataset.ipickerInstance];
        if (!instance) return;
        instance._toggleSubscription(parseInt(btn.dataset.id), btn.dataset.subscribed === '1');
    }

    static _handleSelectChange(checkbox) {
        const container = checkbox.closest('[data-ipicker-instance]');
        if (!container) return;
        const instance = InterestPicker._instances[container.dataset.ipickerInstance];
        if (!instance) return;
        instance._handleSelect(checkbox);
    }

    static _escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    static _escAttr(str) {
        return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
}
