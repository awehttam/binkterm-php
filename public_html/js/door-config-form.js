/**
 * Door list + settings modal for the admin door config pages (DOS, Native,
 * JS-DOS, WebDoors), styled after the RLogin door list.
 *
 * The JSON textarea on each page stays the single source of truth. This module
 * renders one row per door from that JSON and the door metadata, and edits a
 * door's settings in a modal that writes back into the textarea (then calls
 * the page's own save), so the existing Format / Save code keeps working.
 *
 * Usage:
 *   const form = DoorConfigForm.create({
 *       container: 'doorFormContainer',   // element id for the list
 *       editor: 'configEditor',           // textarea id holding the JSON
 *       indent: 4,
 *       manifestType: 'dos',              // dos|native|jsdos|web; adds a manifest editor link
 *       fields: [{key: 'enabled', type: 'bool', labelKey: '...', fallback: '...'}],
 *       getDoors: () => [{id, name, description, genre, icon_url}],
 *       onChange: () => { updateJsonStatus(); },
 *       onSave: () => saveConfig()
 *   });
 *   form.render();
 *
 * Field types: bool, int, text, textarea. Keys the form does not list are
 * preserved. Clearing an int/text field removes its key.
 */
window.DoorConfigForm = (function () {
    'use strict';

    function t(key, fallback) {
        return window.t ? window.t(key, {}, fallback) : fallback;
    }

    function esc(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function parse(text) {
        try {
            const data = JSON.parse(text);
            return data && typeof data === 'object' && !Array.isArray(data) ? data : null;
        } catch (e) {
            return null;
        }
    }

    function renderField(field, value) {
        const label = esc(t(field.labelKey, field.fallback));
        const help = field.helpKey
            ? `<div class="form-text">${esc(t(field.helpKey, field.helpFallback || ''))}</div>`
            : '';

        if (field.type === 'bool') {
            return `
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" data-field="${esc(field.key)}" ${value ? 'checked' : ''}>
                        <label class="form-check-label">${label}</label>
                    </div>
                    ${help}
                </div>`;
        }

        const colClass = (field.type === 'textarea' || field.type === 'tags') ? 'col-12' : 'col-md-6';
        let input;
        if (field.type === 'textarea') {
            input = `<textarea class="form-control" rows="2" data-field="${esc(field.key)}">${esc(value ?? '')}</textarea>`;
        } else if (field.type === 'int') {
            input = `<input type="number" min="0" class="form-control" data-field="${esc(field.key)}" value="${esc(value ?? '')}">`;
        } else if (field.type === 'tags') {
            const tagVal = Array.isArray(value) ? value.join(', ') : (typeof value === 'string' ? value : '');
            let optionsHtml = '';
            const datalist = document.getElementById('manifestCategoriesList');
            if (datalist && datalist.options.length > 0) {
                Array.from(datalist.options).forEach(opt => {
                    optionsHtml += `<option value="${esc(opt.value)}">${esc(opt.value)}</option>`;
                });
            } else {
                const defaults = [
                    'JSDOS', 'Adventure', 'RPG', 'Strategy', 'Classic', 'Multiplayer',
                    'Arcade', 'Action', 'Sci-Fi', 'Fantasy', 'Simulation', 'Comedy',
                    'Space', 'Casino', 'Trivia', 'Board Games', 'Door Servers'
                ];
                defaults.forEach(cat => {
                    optionsHtml += `<option value="${esc(cat)}">${esc(cat)}</option>`;
                });
            }
            input = `
                <div class="input-group">
                    <input type="text" class="form-control" data-field="${esc(field.key)}" data-field-type="tags" value="${esc(tagVal)}" placeholder="e.g. JSDOS, Adventure, Sci-Fi" list="manifestCategoriesList">
                    <select class="form-select door-tag-select" style="max-width: 170px;">
                        <option value="">${esc(t('ui.admin.door_manifest_editor.add_category_placeholder', 'Add category...'))}</option>
                        ${optionsHtml}
                    </select>
                </div>`;
        } else {
            input = `<input type="text" class="form-control" data-field="${esc(field.key)}" value="${esc(value ?? '')}">`;
        }

        return `
            <div class="${colClass}">
                <label class="form-label">${label}</label>
                ${input}
                ${help}
            </div>`;
    }

    function create(opts) {
        const indent = opts.indent || 2;
        const hasField = key => opts.fields.some(f => f.key === key);
        let modalEl = null;
        let modalKey = null;

        function containerEl() { return document.getElementById(opts.container); }
        function editorEl() { return document.getElementById(opts.editor); }

        function doorMap() {
            const map = new Map();
            (opts.getDoors() || []).forEach(door => map.set(door.id, door));
            return map;
        }

        function manifestLink(key) {
            if (!opts.manifestType) {
                return '';
            }
            const href = `/admin/doors/${encodeURIComponent(opts.manifestType)}/${encodeURIComponent(key)}/manifest`;
            const title = esc(t('ui.admin.door_manifest_editor.heading', 'Door Manifest Editor'));
            return `<a href="${href}" class="btn btn-outline-info" title="${title}"><i class="fas fa-file-code"></i></a>`;
        }

        function renderRow(key, info, entry) {
            const enabled = !!entry.enabled;
            const name = entry.display_name || info.name || key;
            const description = entry.display_description || info.description
                || t('ui.admin.door_config_form.no_description', 'No description');
            const icon = info.icon_url
                ? `<img src="${esc(info.icon_url)}" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:4px;" onerror="this.style.visibility='hidden'">`
                : '<i class="fas fa-gamepad text-muted" style="width:32px;font-size:1.5rem;"></i>';

            const badges = [`<span class="badge bg-secondary">${esc(key)}</span>`];
            const genres = (entry.genre && entry.genre.length > 0) ? entry.genre : (info.genre || []);
            genres.forEach(g => badges.push(`<span class="badge bg-primary">${esc(g)}</span>`));
            if (hasField('credit_cost')) {
                const cost = parseInt(entry.credit_cost, 10) || 0;
                badges.push(cost > 0
                    ? `<span class="badge bg-info">${cost} ${esc(t('ui.admin.door_config_form.credits', 'credits'))}</span>`
                    : `<span class="badge bg-success">${esc(t('ui.admin.door_config_form.free', 'Free'))}</span>`);
            }
            if (entry.hide_from_web) {
                badges.push(`<span class="badge bg-warning text-dark">${esc(t('ui.admin.door_config_form.telnet_only', 'Telnet/SSH only'))}</span>`);
            }

            return `
                <div class="list-group-item" data-door-key="${esc(encodeURIComponent(key))}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="d-flex gap-2 flex-grow-1">
                            ${icon}
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="fas ${enabled ? 'fa-check-circle text-success' : 'fa-circle text-secondary'}"></i>
                                    <strong>${esc(name)}</strong>
                                </div>
                                <div class="small text-muted">${esc(description)}</div>
                                <div class="small mt-1">${badges.join(' ')}</div>
                            </div>
                        </div>
                        <div class="btn-group-vertical btn-group-sm">
                            <button type="button" class="btn btn-outline-primary edit-door-settings-btn" title="${esc(t('ui.common.edit', 'Edit'))}">
                                <i class="fas fa-pen"></i>
                            </button>
                            ${manifestLink(key)}
                        </div>
                    </div>
                </div>`;
        }

        function render() {
            const container = containerEl();
            if (!container) {
                return;
            }

            const config = parse(editorEl().value || '{}');
            if (config === null) {
                container.innerHTML = `<div class="text-muted">${esc(t('ui.admin.door_config_form.invalid_json', 'Invalid JSON.'))}</div>`;
                return;
            }

            const known = doorMap();
            const keys = Array.from(new Set([...known.keys(), ...Object.keys(config)])).sort();

            if (keys.length === 0) {
                container.innerHTML = `<div class="text-muted">${esc(t('ui.admin.door_config_form.no_doors', 'No doors defined.'))}</div>`;
                return;
            }

            container.innerHTML = '<div class="list-group list-group-flush">' + keys.map(key => {
                const entry = config[key] && typeof config[key] === 'object' ? config[key] : {};
                return renderRow(key, known.get(key) || {}, entry);
            }).join('') + '</div>';
        }

        function ensureModal() {
            if (modalEl) {
                return modalEl;
            }
            modalEl = document.createElement('div');
            modalEl.className = 'modal fade';
            modalEl.tabIndex = -1;
            modalEl.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body"><div class="row g-3" data-role="fields"></div></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${esc(t('ui.common.cancel', 'Cancel'))}</button>
                            <button type="button" class="btn btn-primary" data-role="save">${esc(t('ui.common.save', 'Save'))}</button>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(modalEl);
            modalEl.querySelector('[data-role="save"]').addEventListener('click', saveModal);
            modalEl.addEventListener('change', event => {
                const select = event.target.closest('.door-tag-select');
                if (!select || !select.value) {
                    return;
                }
                const input = select.closest('.input-group')?.querySelector('input[data-field-type="tags"]');
                if (input) {
                    const current = input.value.split(',').map(s => s.trim()).filter(Boolean);
                    if (!current.some(c => c.toLowerCase() === select.value.toLowerCase())) {
                        current.push(select.value);
                        input.value = current.join(', ');
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
                select.value = '';
            });
            return modalEl;
        }

        function openModal(key) {
            const config = parse(editorEl().value || '{}');
            if (config === null) {
                return;
            }
            const entry = config[key] && typeof config[key] === 'object' ? config[key] : {};
            const info = doorMap().get(key) || {};

            modalKey = key;
            const el = ensureModal();
            el.querySelector('.modal-title').textContent = entry.display_name || info.name || key;
            el.querySelector('[data-role="fields"]').innerHTML = opts.fields.map(f => {
                let val = entry[f.key];
                if ((val === undefined || val === null || (Array.isArray(val) && val.length === 0)) && f.key === 'genre' && info.genre) {
                    val = info.genre;
                }
                return renderField(f, val);
            }).join('');
            bootstrap.Modal.getOrCreateInstance(el).show();
        }

        function saveModal() {
            const editor = editorEl();
            const config = parse(editor.value || '{}');
            if (config === null || modalKey === null) {
                return;
            }

            if (!config[modalKey] || typeof config[modalKey] !== 'object') {
                config[modalKey] = {};
            }

            modalEl.querySelectorAll('[data-field]').forEach(input => {
                const field = opts.fields.find(f => f.key === input.dataset.field);
                if (!field) {
                    return;
                }
                if (field.type === 'bool') {
                    config[modalKey][field.key] = input.checked;
                } else if (input.value.trim() === '') {
                    delete config[modalKey][field.key];
                } else if (field.type === 'int') {
                    config[modalKey][field.key] = Math.max(0, parseInt(input.value, 10) || 0);
                } else if (field.type === 'tags') {
                    const items = input.value.split(',').map(s => s.trim()).filter(s => s !== '');
                    if (items.length === 0) {
                        delete config[modalKey][field.key];
                    } else {
                        config[modalKey][field.key] = items;
                    }
                } else {
                    config[modalKey][field.key] = input.value;
                }
            });

            editor.value = JSON.stringify(config, null, indent);
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            render();
            if (opts.onChange) {
                opts.onChange();
            }
            if (opts.onSave) {
                opts.onSave();
            }
        }

        const container = containerEl();
        if (container) {
            container.addEventListener('click', event => {
                const btn = event.target.closest('.edit-door-settings-btn');
                const row = event.target.closest('[data-door-key]');
                if (btn && row) {
                    openModal(decodeURIComponent(row.dataset.doorKey));
                }
            });
        }

        const tab = document.getElementById(opts.tab || 'doorFormTab');
        if (tab) {
            tab.addEventListener('shown.bs.tab', render);
        }

        return { render: render };
    }

    return { create: create };
})();
