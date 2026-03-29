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
                ? window.t('ui.interests.no_interests', {}, 'No interests are subscribed to.')
                : 'No interests are subscribed to.';
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
            const echoCountHtml = (interest.echoarea_count ?? 0) > 0
                ? `<a href="#" class="text-muted interest-areas-link" data-interest-id="${interest.id}" data-interest-name="${InterestPicker._escAttr(interest.name)}" onclick="InterestPicker._handleAreasClick(this); return false;"><i class="fas fa-comments me-1"></i>${InterestPicker._escHtml(echoCount)}</a>`
                : `<span><i class="fas fa-comments me-1"></i>${InterestPicker._escHtml(echoCount)}</span>`;

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
                                ${echoCountHtml}
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
                            <div class="small text-muted me-2">
                                <span class="me-2">${echoCountHtml}</span>
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
        instance._showAreasModal(parseInt(btn.dataset.id));
    }

    static _handleSelectChange(checkbox) {
        const container = checkbox.closest('[data-ipicker-instance]');
        if (!container) return;
        const instance = InterestPicker._instances[container.dataset.ipickerInstance];
        if (!instance) return;
        instance._handleSelect(checkbox);
    }

    static _handleAreasClick(link) {
        const container = link.closest('[data-ipicker-instance]');
        if (!container) return;
        const instance = InterestPicker._instances[container.dataset.ipickerInstance];
        if (!instance) return;
        instance._showAreasModal(parseInt(link.dataset.interestId), link.dataset.interestName);
    }

    async _showAreasModal(interestId) {
        const interest     = this._interests.find(i => i.id === interestId);
        const name         = interest ? interest.name : '';
        const isSubscribed = interest ? this._selectedIds.has(interestId) : false;
        const isSubMode    = this._mode === 'subscribe';
        const operation    = isSubscribed ? 'unsubscribe' : 'subscribe';

        // Create modal shell once, reuse it.
        let modalEl = document.getElementById('interestAreasModal');
        if (!modalEl) {
            modalEl = document.createElement('div');
            modalEl.id = 'interestAreasModal';
            modalEl.className = 'modal fade';
            modalEl.tabIndex = -1;
            modalEl.innerHTML = `
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="interestAreasModalTitle"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="interestAreasModalBody"></div>
                        <div class="modal-footer d-none" id="interestAreasModalFooter"></div>
                    </div>
                </div>`;
            document.body.appendChild(modalEl);
        }

        const titleEl  = document.getElementById('interestAreasModalTitle');
        const bodyEl   = document.getElementById('interestAreasModalBody');
        const footerEl = document.getElementById('interestAreasModalFooter');

        // Set title based on mode and operation.
        if (isSubMode) {
            const key      = operation === 'subscribe' ? 'ui.interests.areas_modal_subscribe_title' : 'ui.interests.areas_modal_unsubscribe_title';
            const fallback = operation === 'subscribe' ? `Subscribe to ${name}` : `Unsubscribe from ${name}`;
            titleEl.textContent = window.t ? window.t(key, { name }, fallback) : fallback;
        } else {
            const fallback = `Echo Areas — ${name}`;
            titleEl.textContent = window.t ? window.t('ui.interests.areas_modal_title', { name }, fallback) : fallback;
        }

        bodyEl.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        footerEl.innerHTML = '';
        footerEl.classList.add('d-none');

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        try {
            const resp = await fetch(`/api/interests/${interestId}/echoareas`);
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data  = await resp.json();
            const areas = data.echoareas || [];

            if (!areas.length) {
                bodyEl.innerHTML = '<p class="text-muted mb-0">' +
                    InterestPicker._escHtml(
                        window.t ? window.t('ui.interests.areas_modal_empty', {}, 'No echo areas assigned.') : 'No echo areas assigned.'
                    ) + '</p>';
                return;
            }

            const thTag  = window.t ? window.t('ui.interests.areas_modal_col_tag',  {}, 'Echo Area')    : 'Echo Area';
            const thNet  = window.t ? window.t('ui.interests.areas_modal_col_net',  {}, 'Network')       : 'Network';
            const thDesc = window.t ? window.t('ui.interests.areas_modal_col_desc', {}, 'Description')   : 'Description';

            const subBadge = `<span class="badge bg-success ms-1" title="${InterestPicker._escAttr(window.t ? window.t('ui.interests.already_subscribed', {}, 'Already subscribed') : 'Already subscribed')}"><i class="fas fa-check"></i></span>`;

            if (!isSubMode) {
                // Read-only table for select mode.
                bodyEl.innerHTML =
                    `<table class="table table-sm table-hover mb-0">
                        <thead><tr>
                            <th>${InterestPicker._escHtml(thTag)}</th>
                            <th>${InterestPicker._escHtml(thNet)}</th>
                            <th>${InterestPicker._escHtml(thDesc)}</th>
                        </tr></thead>
                        <tbody>${areas.map(a => `<tr>
                            <td class="fw-semibold text-warning">${InterestPicker._escHtml(a.tag)}${a.subscribed ? subBadge : ''}</td>
                            <td><span class="badge bg-secondary fw-normal">${InterestPicker._escHtml(a.domain || '')}</span></td>
                            <td>${InterestPicker._escHtml(a.description || '')}</td>
                        </tr>`).join('')}</tbody>
                    </table>`;
                return;
            }

            // Subscribe/unsubscribe mode — render checkboxes.
            const selectAllLabel   = window.t ? window.t('ui.interests.areas_modal_select_all',   {}, 'Select all')   : 'Select all';
            const deselectAllLabel = window.t ? window.t('ui.interests.areas_modal_deselect_all', {}, 'Deselect all') : 'Deselect all';

            bodyEl.innerHTML =
                `<div class="d-flex gap-3 mb-2 small">
                    <a href="#" id="ipickSelectAll">${InterestPicker._escHtml(selectAllLabel)}</a>
                    <a href="#" id="ipickDeselectAll">${InterestPicker._escHtml(deselectAllLabel)}</a>
                </div>
                <table class="table table-sm table-hover mb-0">
                    <thead><tr>
                        <th style="width:2.5rem"></th>
                        <th>${InterestPicker._escHtml(thTag)}</th>
                        <th>${InterestPicker._escHtml(thNet)}</th>
                        <th>${InterestPicker._escHtml(thDesc)}</th>
                    </tr></thead>
                    <tbody>${areas.map(a => {
                        const checked = operation === 'unsubscribe'
                            ? (a.subscribed ? 'checked' : '')
                            : 'checked';
                        return `<tr>
                        <td><input class="form-check-input interest-area-check" type="checkbox"
                                   value="${InterestPicker._escAttr(String(a.echoarea_id))}" ${checked}></td>
                        <td class="fw-semibold text-warning">${InterestPicker._escHtml(a.tag)}${a.subscribed ? subBadge : ''}</td>
                        <td><span class="badge bg-secondary fw-normal">${InterestPicker._escHtml(a.domain || '')}</span></td>
                        <td>${InterestPicker._escHtml(a.description || '')}</td>
                    </tr>`;
                    }).join('')}</tbody>
                </table>`;

            const cancelLabel = window.t ? window.t('ui.cancel', {}, 'Cancel') : 'Cancel';
            footerEl.innerHTML =
                `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${InterestPicker._escHtml(cancelLabel)}</button>
                 <button type="button" class="btn ${operation === 'subscribe' ? 'btn-primary' : 'btn-danger'}" id="interestAreasConfirmBtn" disabled></button>`;
            footerEl.classList.remove('d-none');

            const confirmBtn = document.getElementById('interestAreasConfirmBtn');

            const updateBtn = () => {
                const count    = bodyEl.querySelectorAll('.interest-area-check:checked').length;
                const key      = operation === 'subscribe' ? 'ui.interests.areas_modal_confirm_subscribe' : 'ui.interests.areas_modal_confirm_unsubscribe';
                const fallback = operation === 'subscribe' ? `Subscribe to ${count} area(s)` : `Unsubscribe from ${count} area(s)`;
                confirmBtn.textContent = window.t ? window.t(key, { count }, fallback) : fallback;
                confirmBtn.disabled    = count === 0;
            };

            bodyEl.querySelectorAll('.interest-area-check').forEach(cb => cb.addEventListener('change', updateBtn));
            document.getElementById('ipickSelectAll').addEventListener('click', e => {
                e.preventDefault();
                bodyEl.querySelectorAll('.interest-area-check').forEach(cb => cb.checked = true);
                updateBtn();
            });
            document.getElementById('ipickDeselectAll').addEventListener('click', e => {
                e.preventDefault();
                bodyEl.querySelectorAll('.interest-area-check').forEach(cb => cb.checked = false);
                updateBtn();
            });
            updateBtn();

            confirmBtn.addEventListener('click', async () => {
                const ids = [...bodyEl.querySelectorAll('.interest-area-check:checked')].map(cb => parseInt(cb.value));
                if (!ids.length) return;

                confirmBtn.disabled   = true;
                confirmBtn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i>';

                try {
                    const apiPath = operation === 'subscribe' ? 'subscribe' : 'unsubscribe';
                    const resp    = await fetch(`/api/interests/${interestId}/${apiPath}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ echoarea_ids: ids }),
                    });
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    const result = await resp.json();

                    if (result.subscribed) {
                        this._selectedIds.add(interestId);
                    } else {
                        this._selectedIds.delete(interestId);
                    }
                    this._refreshCard(interestId);
                    modal.hide();
                } catch (err) {
                    console.error('[InterestPicker] confirm failed:', err);
                    confirmBtn.disabled = false;
                    updateBtn();
                }
            });

        } catch (err) {
            bodyEl.innerHTML = '<div class="alert alert-danger mb-0">' + InterestPicker._escHtml(err.message) + '</div>';
        }
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
