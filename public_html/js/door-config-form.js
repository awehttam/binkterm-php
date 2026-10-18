/**
 * Form view for the admin door config pages (DOS, Native, JS-DOS, WebDoors).
 *
 * The JSON textarea on each page stays the single source of truth. This module
 * renders one editable card per door from that JSON and writes changes back
 * into it, so the existing Format / Save / toggle code keeps working untouched.
 *
 * Usage:
 *   const form = DoorConfigForm.create({
 *       container: 'doorFormContainer',   // element id for the cards
 *       editor: 'configEditor',           // textarea id holding the JSON
 *       indent: 4,
 *       manifestType: 'dos',              // dos|native|jsdos|web; adds an Edit link to the manifest editor
 *       fields: [{key: 'enabled', type: 'bool', labelKey: '...', fallback: '...'}],
 *       getDoors: () => [{id: 'LORD', name: 'Legend of the Red Dragon'}],
 *       onChange: () => { updateJsonStatus(); renderDoorsList(); }
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

        const colClass = field.type === 'textarea' ? 'col-12' : 'col-md-6';
        let input;
        if (field.type === 'textarea') {
            input = `<textarea class="form-control" rows="2" data-field="${esc(field.key)}">${esc(value ?? '')}</textarea>`;
        } else if (field.type === 'int') {
            const placeholder = field.placeholder !== undefined ? ` placeholder="${esc(field.placeholder)}"` : '';
            input = `<input type="number" min="0" class="form-control" data-field="${esc(field.key)}" value="${esc(value ?? '')}"${placeholder}>`;
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
        let busy = false;

        function containerEl() { return document.getElementById(opts.container); }
        function editorEl() { return document.getElementById(opts.editor); }

        function editLink(key) {
            if (!opts.manifestType) {
                return '';
            }
            const href = `/admin/doors/${encodeURIComponent(opts.manifestType)}/${encodeURIComponent(key)}/manifest`;
            const title = esc(t('ui.admin.door_manifest_editor.heading', 'Door Manifest Editor'));
            return `<a href="${href}" class="btn btn-sm btn-outline-info" title="${title}">
                <i class="fas fa-file-code"></i> ${esc(t('ui.common.edit', 'Edit'))}
            </a>`;
        }

        function render() {
            const container = containerEl();
            if (!container || busy) {
                return;
            }

            const config = parse(editorEl().value || '{}');
            if (config === null) {
                container.innerHTML = `<div class="text-muted">${esc(t('ui.admin.door_config_form.invalid_json', 'Invalid JSON.'))}</div>`;
                return;
            }

            const known = new Map();
            (opts.getDoors() || []).forEach(door => known.set(door.id, door.name || door.id));
            const keys = Array.from(new Set([...known.keys(), ...Object.keys(config)])).sort();

            if (keys.length === 0) {
                container.innerHTML = `<div class="text-muted">${esc(t('ui.admin.door_config_form.no_doors', 'No doors defined.'))}</div>`;
                return;
            }

            container.innerHTML = keys.map(key => {
                const entry = config[key] && typeof config[key] === 'object' ? config[key] : {};
                const name = known.get(key);
                const title = name && name !== key ? `${name} (${key})` : key;
                return `
                    <div class="card mb-3" data-door-key="${esc(encodeURIComponent(key))}">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">${esc(title)}</span>
                            ${editLink(key)}
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                ${opts.fields.map(f => renderField(f, entry[f.key])).join('')}
                            </div>
                        </div>
                    </div>`;
            }).join('');
        }

        function onInput(event) {
            const input = event.target.closest('[data-field]');
            const card = event.target.closest('[data-door-key]');
            if (!input || !card) {
                return;
            }

            const editor = editorEl();
            const config = parse(editor.value || '{}');
            if (config === null) {
                return;
            }

            const key = decodeURIComponent(card.dataset.doorKey);
            if (!config[key] || typeof config[key] !== 'object') {
                config[key] = {};
            }

            const field = opts.fields.find(f => f.key === input.dataset.field);
            if (!field) {
                return;
            }

            if (field.type === 'bool') {
                config[key][field.key] = input.checked;
            } else if (input.value.trim() === '') {
                delete config[key][field.key];
            } else if (field.type === 'int') {
                config[key][field.key] = Math.max(0, parseInt(input.value, 10) || 0);
            } else {
                config[key][field.key] = input.value;
            }

            editor.value = JSON.stringify(config, null, indent);

            // The page's list re-render calls render() again; skip it here so
            // the focused input is not rebuilt while typing.
            busy = true;
            try {
                if (opts.onChange) {
                    opts.onChange();
                }
            } finally {
                busy = false;
            }
        }

        const container = containerEl();
        if (container) {
            container.addEventListener('input', onInput);
            container.addEventListener('change', onInput);
        }

        const tab = document.getElementById(opts.tab || 'doorFormTab');
        if (tab) {
            tab.addEventListener('shown.bs.tab', render);
        }

        return { render: render };
    }

    return { create: create };
})();
