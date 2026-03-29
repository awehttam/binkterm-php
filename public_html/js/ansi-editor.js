(function(window, document) {
    'use strict';

    const PRESET_GROUPS = [
        {
            labelKey: 'ui.ansi_editor.group.formatting',
            fallback: 'Formatting',
            options: [
                { value: '0m', key: 'ui.ansi_editor.sequence_reset', fallback: 'Reset (ESC[0m)' },
                { value: '1m', key: 'ui.ansi_editor.sequence_bold', fallback: 'Bold (ESC[1m)' },
                { value: '5m', key: 'ui.ansi_editor.sequence_blink', fallback: 'Blink (ESC[5m)' },
                { value: '7m', key: 'ui.ansi_editor.sequence_reverse', fallback: 'Reverse Video (ESC[7m)' }
            ]
        },
        {
            labelKey: 'ui.ansi_editor.group.foreground',
            fallback: 'Text Colors',
            options: [
                { value: '30m', key: 'ui.ansi_editor.sequence_fg_black', fallback: 'Black (ESC[30m)' },
                { value: '31m', key: 'ui.ansi_editor.sequence_fg_red', fallback: 'Red (ESC[31m)' },
                { value: '32m', key: 'ui.ansi_editor.sequence_fg_green', fallback: 'Green (ESC[32m)' },
                { value: '33m', key: 'ui.ansi_editor.sequence_fg_yellow', fallback: 'Yellow (ESC[33m)' },
                { value: '34m', key: 'ui.ansi_editor.sequence_fg_blue', fallback: 'Blue (ESC[34m)' },
                { value: '35m', key: 'ui.ansi_editor.sequence_fg_magenta', fallback: 'Magenta (ESC[35m)' },
                { value: '36m', key: 'ui.ansi_editor.sequence_fg_cyan', fallback: 'Cyan (ESC[36m)' },
                { value: '37m', key: 'ui.ansi_editor.sequence_fg_white', fallback: 'White (ESC[37m)' }
            ]
        },
        {
            labelKey: 'ui.ansi_editor.group.background',
            fallback: 'Background Colors',
            options: [
                { value: '40m', key: 'ui.ansi_editor.sequence_bg_black', fallback: 'Black Background (ESC[40m)' },
                { value: '41m', key: 'ui.ansi_editor.sequence_bg_red', fallback: 'Red Background (ESC[41m)' },
                { value: '42m', key: 'ui.ansi_editor.sequence_bg_green', fallback: 'Green Background (ESC[42m)' },
                { value: '43m', key: 'ui.ansi_editor.sequence_bg_yellow', fallback: 'Yellow Background (ESC[43m)' },
                { value: '44m', key: 'ui.ansi_editor.sequence_bg_blue', fallback: 'Blue Background (ESC[44m)' },
                { value: '45m', key: 'ui.ansi_editor.sequence_bg_magenta', fallback: 'Magenta Background (ESC[45m)' },
                { value: '46m', key: 'ui.ansi_editor.sequence_bg_cyan', fallback: 'Cyan Background (ESC[46m)' },
                { value: '47m', key: 'ui.ansi_editor.sequence_bg_white', fallback: 'White Background (ESC[47m)' }
            ]
        },
        {
            labelKey: 'ui.ansi_editor.group.cursor',
            fallback: 'Screen and Cursor',
            options: [
                { value: '2J', key: 'ui.ansi_editor.sequence_clear_screen', fallback: 'Clear Screen (ESC[2J)' },
                { value: 'K', key: 'ui.ansi_editor.sequence_clear_line', fallback: 'Clear Line (ESC[K)' },
                { value: 'H', key: 'ui.ansi_editor.sequence_cursor_home', fallback: 'Cursor Home (ESC[H)' },
                { value: 's', key: 'ui.ansi_editor.sequence_cursor_save', fallback: 'Save Cursor (ESC[s)' },
                { value: 'u', key: 'ui.ansi_editor.sequence_cursor_restore', fallback: 'Restore Cursor (ESC[u)' },
                { value: '1A', key: 'ui.ansi_editor.sequence_cursor_up', fallback: 'Cursor Up (ESC[1A)' },
                { value: '1B', key: 'ui.ansi_editor.sequence_cursor_down', fallback: 'Cursor Down (ESC[1B)' },
                { value: '1C', key: 'ui.ansi_editor.sequence_cursor_right', fallback: 'Cursor Right (ESC[1C)' },
                { value: '1D', key: 'ui.ansi_editor.sequence_cursor_left', fallback: 'Cursor Left (ESC[1D)' }
            ]
        }
    ];

    const CHEATSHEET_ROWS = [
        { sequence: '\x1b[0m', key: 'ui.ansi_editor.sequence_reset', fallback: 'Reset (ESC[0m)', sample: '\x1b[1;31mReset sample\x1b[0m normal' },
        { sequence: '\x1b[1m', key: 'ui.ansi_editor.sequence_bold', fallback: 'Bold (ESC[1m)', sample: '\x1b[1mBold text\x1b[0m' },
        { sequence: '\x1b[5m', key: 'ui.ansi_editor.sequence_blink', fallback: 'Blink (ESC[5m)', sample: '\x1b[5mBlink text\x1b[0m' },
        { sequence: '\x1b[7m', key: 'ui.ansi_editor.sequence_reverse', fallback: 'Reverse Video (ESC[7m)', sample: '\x1b[7mReverse text\x1b[0m' },
        { sequence: '\x1b[31m', key: 'ui.ansi_editor.sequence_fg_red', fallback: 'Red (ESC[31m)', sample: '\x1b[31mRed text\x1b[0m' },
        { sequence: '\x1b[32m', key: 'ui.ansi_editor.sequence_fg_green', fallback: 'Green (ESC[32m)', sample: '\x1b[32mGreen text\x1b[0m' },
        { sequence: '\x1b[33m', key: 'ui.ansi_editor.sequence_fg_yellow', fallback: 'Yellow (ESC[33m)', sample: '\x1b[33mYellow text\x1b[0m' },
        { sequence: '\x1b[34m', key: 'ui.ansi_editor.sequence_fg_blue', fallback: 'Blue (ESC[34m)', sample: '\x1b[34mBlue text\x1b[0m' },
        { sequence: '\x1b[35m', key: 'ui.ansi_editor.sequence_fg_magenta', fallback: 'Magenta (ESC[35m)', sample: '\x1b[35mMagenta text\x1b[0m' },
        { sequence: '\x1b[36m', key: 'ui.ansi_editor.sequence_fg_cyan', fallback: 'Cyan (ESC[36m)', sample: '\x1b[36mCyan text\x1b[0m' },
        { sequence: '\x1b[37m', key: 'ui.ansi_editor.sequence_fg_white', fallback: 'White (ESC[37m)', sample: '\x1b[37mWhite text\x1b[0m' },
        { sequence: '\x1b[41m', key: 'ui.ansi_editor.sequence_bg_red', fallback: 'Red Background (ESC[41m)', sample: '\x1b[41;37m Red bg \x1b[0m' },
        { sequence: '\x1b[42m', key: 'ui.ansi_editor.sequence_bg_green', fallback: 'Green Background (ESC[42m)', sample: '\x1b[42;30m Green bg \x1b[0m' },
        { sequence: '\x1b[44m', key: 'ui.ansi_editor.sequence_bg_blue', fallback: 'Blue Background (ESC[44m)', sample: '\x1b[44;37m Blue bg \x1b[0m' },
        { sequence: '\x1b[2J', key: 'ui.ansi_editor.sequence_clear_screen', fallback: 'Clear Screen (ESC[2J)', sample: 'Clears the screen before following output' },
        { sequence: '\x1b[K', key: 'ui.ansi_editor.sequence_clear_line', fallback: 'Clear Line (ESC[K)', sample: 'Clears from cursor to end of line' },
        { sequence: '\x1b[H', key: 'ui.ansi_editor.sequence_cursor_home', fallback: 'Cursor Home (ESC[H)', sample: 'Moves cursor to row 1, column 1' },
        { sequence: '\x1b[s', key: 'ui.ansi_editor.sequence_cursor_save', fallback: 'Save Cursor (ESC[s)', sample: 'Saves the current cursor position' },
        { sequence: '\x1b[u', key: 'ui.ansi_editor.sequence_cursor_restore', fallback: 'Restore Cursor (ESC[u)', sample: 'Restores the saved cursor position' },
        { sequence: '\x1b[10;20H', key: 'ui.ansi_editor.example_position', fallback: 'Position Cursor (ESC[10;20H)', sample: 'Moves cursor to row 10, column 20' }
    ];

    function translate(key, fallback, params) {
        if (typeof window.t === 'function') {
            return window.t(key, params || {}, fallback);
        }
        return fallback;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function buildPlainPreview(content) {
        return '<pre class="m-0 p-3 font-monospace" style="min-height:180px;max-height:420px;overflow:auto;white-space:pre-wrap;word-break:break-word;">'
            + escapeHtml(content)
            + '</pre>';
    }

    function buildAnsiPreview(content) {
        return '<div class="ansi-art-container" style="overflow:auto;min-height:180px;max-height:420px;background:#0a0a0a;padding:8px;">'
            + window.renderAnsiBuffer(content, 80, 500)
            + '</div>';
    }

    function renderPreview(container, content) {
        if (!container) {
            return;
        }

        const source = String(content || '');
        if (source.trim() === '') {
            container.innerHTML = '<div class="text-muted small p-3">'
                + escapeHtml(translate('ui.ansi_editor.preview_empty', 'Nothing to preview yet.'))
                + '</div>';
            return;
        }

        if (typeof window.renderAdContent === 'function') {
            window.renderAdContent(container, source);
            return;
        }

        if (typeof window.renderAnsiBuffer === 'function') {
            const hasAnsi = /\x1b\[[0-9;?]*[A-Za-z]/.test(source);
            const looksAnsi = typeof window.looksLikeAnsiArtText === 'function' && window.looksLikeAnsiArtText(source);
            container.innerHTML = hasAnsi || looksAnsi
                ? buildAnsiPreview(source)
                : buildPlainPreview(source);
            return;
        }

        container.innerHTML = buildPlainPreview(source);
    }

    let previewModalInstance = null;
    let cheatsheetModalInstance = null;

    function ensurePreviewModal() {
        let modal = document.getElementById('ansiEditorPreviewModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'ansiEditorPreviewModal';
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML = ''
                + '<div class="modal-dialog modal-xl modal-dialog-centered">'
                + '  <div class="modal-content">'
                + '    <div class="modal-header">'
                + '      <h5 class="modal-title" id="ansiEditorPreviewModalTitle"></h5>'
                + '      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>'
                + '    </div>'
                + '    <div class="modal-body">'
                + '      <div id="ansiEditorPreviewModalBody" class="w-100" style="overflow:hidden;"></div>'
                + '    </div>'
                + '    <div class="modal-footer">'
                + '      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
                + escapeHtml(translate('ui.common.close', 'Close'))
                + '      </button>'
                + '    </div>'
                + '  </div>'
                + '</div>';
            document.body.appendChild(modal);
        }

        if (!previewModalInstance && window.bootstrap && window.bootstrap.Modal) {
            previewModalInstance = new window.bootstrap.Modal(modal);
        }

        return {
            title: document.getElementById('ansiEditorPreviewModalTitle'),
            body: document.getElementById('ansiEditorPreviewModalBody')
        };
    }

    function openPreview(options) {
        const modal = ensurePreviewModal();
        if (!modal.body || !modal.title) {
            return;
        }

        modal.title.textContent = (options && options.title) || translate('ui.common.preview', 'Preview');
        renderPreview(modal.body, options && options.content ? options.content : '');
        if (previewModalInstance) {
            previewModalInstance.show();
        }
    }

    function ensureCheatsheetModal() {
        let modal = document.getElementById('ansiEditorCheatsheetModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'ansiEditorCheatsheetModal';
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML = ''
                + '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">'
                + '  <div class="modal-content">'
                + '    <div class="modal-header">'
                + '      <h5 class="modal-title">'
                + escapeHtml(translate('ui.ansi_editor.cheatsheet_title', 'ANSI Cheatsheet'))
                + '      </h5>'
                + '      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>'
                + '    </div>'
                + '    <div class="modal-body" id="ansiEditorCheatsheetModalBody"></div>'
                + '    <div class="modal-footer">'
                + '      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
                + escapeHtml(translate('ui.common.close', 'Close'))
                + '      </button>'
                + '    </div>'
                + '  </div>'
                + '</div>';
            document.body.appendChild(modal);
        }

        if (!cheatsheetModalInstance && window.bootstrap && window.bootstrap.Modal) {
            cheatsheetModalInstance = new window.bootstrap.Modal(modal);
        }

        return document.getElementById('ansiEditorCheatsheetModalBody');
    }

    function renderCheatsheetTable(container) {
        if (!container) {
            return;
        }

        const rowsHtml = CHEATSHEET_ROWS.map((row) => {
            const label = translate(row.key, row.fallback);
            const sampleHtml = typeof window.renderAnsiBuffer === 'function' && /\x1b\[/.test(row.sample)
                ? '<div class="ansi-art-container" style="overflow:auto;background:#0a0a0a;padding:6px;min-width:180px;">'
                    + window.renderAnsiBuffer(row.sample, 80, 25)
                    + '</div>'
                : '<div class="small">' + escapeHtml(row.sample) + '</div>';

            return ''
                + '<tr>'
                + '  <td><code>' + escapeHtml(row.sequence.replace(/\x1b/g, 'ESC')) + '</code></td>'
                + '  <td>' + escapeHtml(label) + '</td>'
                + '  <td>' + sampleHtml + '</td>'
                + '</tr>';
        }).join('');

        container.innerHTML = ''
            + '<p class="text-muted small mb-3">'
            + escapeHtml(translate('ui.ansi_editor.cheatsheet_help', 'Use these sequences in the editor. ESC means the escape character (ASCII 27).'))
            + '</p>'
            + '<div class="table-responsive">'
            + '  <table class="table table-sm align-middle">'
            + '    <thead>'
            + '      <tr>'
            + '        <th>' + escapeHtml(translate('ui.ansi_editor.cheatsheet_sequence', 'Sequence')) + '</th>'
            + '        <th>' + escapeHtml(translate('ui.ansi_editor.cheatsheet_description', 'Description')) + '</th>'
            + '        <th>' + escapeHtml(translate('ui.ansi_editor.cheatsheet_preview', 'Preview')) + '</th>'
            + '      </tr>'
            + '    </thead>'
            + '    <tbody>' + rowsHtml + '</tbody>'
            + '  </table>'
            + '</div>';
    }

    function openCheatsheet() {
        const body = ensureCheatsheetModal();
        renderCheatsheetTable(body);
        if (cheatsheetModalInstance) {
            cheatsheetModalInstance.show();
        }
    }

    function normalizeSequenceSuffix(value) {
        let suffix = String(value || '').trim();
        if (!suffix) {
            return '';
        }

        suffix = suffix.replace(/^ESC\[/i, '');
        suffix = suffix.replace(/^\u001b\[/, '');
        suffix = suffix.replace(/^\[/, '');
        return suffix;
    }

    function buildColumnRuler(maxColumns) {
        const width = Math.max(1, Number(maxColumns) || 132);
        const numberLine = new Array(width).fill(' ');
        const tickLine = new Array(width).fill('.');

        for (let column = 1; column <= width; column++) {
            if (column === 1 || column % 10 === 0) {
                const label = String(column);
                const start = column - 1;
                for (let i = 0; i < label.length && (start + i) < width; i++) {
                    numberLine[start + i] = label[i];
                }
            }

            if (column === 1) {
                tickLine[column - 1] = '|';
            } else if (column % 10 === 0) {
                tickLine[column - 1] = '|';
            } else if (column % 5 === 0) {
                tickLine[column - 1] = ':';
            }
        }

        return numberLine.join('') + '\n' + tickLine.join('');
    }

    class AnsiEditor {
        constructor(root, options) {
            this.root = root;
            this.options = options || {};
            this.textarea = root ? root.querySelector('textarea') : null;
            this.presetSelect = null;
            this.customInput = null;
            this.rulerTextarea = null;
            this.rulerWrap = null;

            if (!this.root || !this.textarea) {
                return;
            }

            this.buildUi();
            this.bindEvents();
        }

        buildUi() {
            const controls = document.createElement('div');
            controls.className = 'row g-2 mb-2';
            controls.innerHTML = ''
                + '<div class="col-md-3">'
                + '  <button type="button" class="btn btn-sm btn-outline-secondary w-100" data-ansi-editor-insert-esc>'
                + escapeHtml(translate('ui.ansi_editor.insert_escape_prefix', 'Insert ESC['))
                + '  </button>'
                + '</div>'
                + '<div class="col-md-4">'
                + '  <select class="form-select form-select-sm" data-ansi-editor-preset></select>'
                + '</div>'
                + '<div class="col-md-3">'
                + '  <input type="text" class="form-control form-control-sm font-monospace" data-ansi-editor-custom>'
                + '</div>'
                + '<div class="col-md-2 d-grid">'
                + '  <button type="button" class="btn btn-sm btn-outline-secondary" data-ansi-editor-insert-seq>'
                + escapeHtml(translate('ui.ansi_editor.insert_sequence', 'Insert Sequence'))
                + '  </button>'
                + '</div>'
                + '<div class="col-12">'
                + '  <div class="d-flex gap-2 flex-wrap">'
                + '    <button type="button" class="btn btn-sm btn-outline-secondary" data-ansi-editor-cheatsheet-btn>'
                + escapeHtml(translate('ui.ansi_editor.cheatsheet_title', 'ANSI Cheatsheet'))
                + '    </button>'
                + '    <button type="button" class="btn btn-sm btn-outline-secondary" data-ansi-editor-insert-file-btn>'
                + escapeHtml(translate('ui.ansi_editor.insert_file', 'Insert File'))
                + '    </button>'
                + '    <input type="file" data-ansi-editor-file-input style="display:none;">'
                + '    <button type="button" class="btn btn-sm btn-outline-primary" data-ansi-editor-preview-btn>'
                + escapeHtml(translate('ui.common.preview', 'Preview'))
                + '    </button>'
                + '  </div>'
                + '</div>';

            this.root.insertBefore(controls, this.textarea);

            const rulerWrap = document.createElement('div');
            rulerWrap.className = 'border rounded mb-2 bg-light';
            rulerWrap.style.overflowX = 'auto';
            rulerWrap.style.overflowY = 'hidden';
            rulerWrap.innerHTML = ''
                + '<textarea class="form-control font-monospace border-0 bg-light" data-ansi-editor-ruler '
                + 'rows="2" readonly spellcheck="false" aria-hidden="true" '
                + 'style="white-space:pre;overflow:hidden;resize:none;min-width:max-content;"></textarea>';
            this.root.insertBefore(rulerWrap, this.textarea);
            this.rulerWrap = rulerWrap;
            this.rulerTextarea = rulerWrap.querySelector('[data-ansi-editor-ruler]');
            if (this.rulerTextarea) {
                this.rulerTextarea.wrap = 'off';
                this.rulerTextarea.tabIndex = -1;
                this.rulerTextarea.value = buildColumnRuler(132);
            }

            this.textarea.wrap = 'off';
            this.syncRulerMetrics();

            this.presetSelect = controls.querySelector('[data-ansi-editor-preset]');
            this.customInput = controls.querySelector('[data-ansi-editor-custom]');
            this.customInput.placeholder = translate('ui.ansi_editor.custom_sequence_placeholder', 'e.g. 10;20H or 44m');
            this.customInput.setAttribute('aria-label', translate('ui.ansi_editor.custom_sequence', 'Custom ANSI sequence'));

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = translate('ui.ansi_editor.select_sequence', 'Select ANSI sequence');
            this.presetSelect.appendChild(placeholder);

            PRESET_GROUPS.forEach(group => {
                const optgroup = document.createElement('optgroup');
                optgroup.label = translate(group.labelKey, group.fallback);
                group.options.forEach(option => {
                    const opt = document.createElement('option');
                    opt.value = option.value;
                    opt.textContent = translate(option.key, option.fallback);
                    optgroup.appendChild(opt);
                });
                this.presetSelect.appendChild(optgroup);
            });

        }

        bindEvents() {
            this.root.querySelector('[data-ansi-editor-insert-esc]').addEventListener('click', () => {
                this.insertAtCaret('\x1b[');
            });

            this.root.querySelector('[data-ansi-editor-insert-seq]').addEventListener('click', () => {
                const suffix = normalizeSequenceSuffix(this.customInput.value || this.presetSelect.value);
                if (!suffix) {
                    return;
                }
                this.insertAtCaret('\x1b[' + suffix);
                this.customInput.value = '';
                this.presetSelect.value = '';
            });

            this.root.querySelector('[data-ansi-editor-preview-btn]').addEventListener('click', () => {
                this.openPreview();
            });

            this.root.querySelector('[data-ansi-editor-cheatsheet-btn]').addEventListener('click', () => {
                openCheatsheet();
            });

            const fileInput = this.root.querySelector('[data-ansi-editor-file-input]');
            this.root.querySelector('[data-ansi-editor-insert-file-btn]').addEventListener('click', () => {
                if (fileInput) {
                    fileInput.click();
                }
            });

            if (fileInput) {
                fileInput.addEventListener('change', () => {
                    const file = fileInput.files && fileInput.files[0];
                    if (!file) {
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = () => {
                        const content = typeof reader.result === 'string' ? reader.result : '';
                        this.insertAtCaret(content);
                        fileInput.value = '';
                    };
                    reader.onerror = () => {
                        fileInput.value = '';
                    };
                    reader.readAsText(file);
                });
            }

            this.textarea.addEventListener('scroll', () => {
                this.syncRulerScroll();
            });

            window.addEventListener('resize', () => {
                this.syncRulerMetrics();
            });
        }

        insertAtCaret(text) {
            const start = this.textarea.selectionStart ?? this.textarea.value.length;
            const end = this.textarea.selectionEnd ?? this.textarea.value.length;
            const before = this.textarea.value.slice(0, start);
            const after = this.textarea.value.slice(end);
            this.textarea.value = before + text + after;
            this.textarea.focus();
            const nextCaret = start + text.length;
            this.textarea.setSelectionRange(nextCaret, nextCaret);
        }

        getPreviewTitle() {
            if (typeof this.options.previewTitleResolver === 'function') {
                return this.options.previewTitleResolver();
            }
            return translate('ui.common.preview', 'Preview');
        }

        refreshPreview() {
            // Modal-only preview mode keeps this for compatibility with callers
            // that refresh after loading content into the textarea.
        }

        syncRulerMetrics() {
            if (!this.textarea || !this.rulerTextarea || !this.rulerWrap) {
                return;
            }

            const styles = window.getComputedStyle(this.textarea);
            this.rulerTextarea.style.fontFamily = styles.fontFamily;
            this.rulerTextarea.style.fontSize = styles.fontSize;
            this.rulerTextarea.style.fontWeight = styles.fontWeight;
            this.rulerTextarea.style.lineHeight = styles.lineHeight;
            this.rulerTextarea.style.letterSpacing = styles.letterSpacing;
            this.rulerTextarea.style.tabSize = styles.tabSize;
            this.rulerTextarea.style.paddingTop = styles.paddingTop;
            this.rulerTextarea.style.paddingRight = styles.paddingRight;
            this.rulerTextarea.style.paddingBottom = styles.paddingBottom;
            this.rulerTextarea.style.paddingLeft = styles.paddingLeft;
            this.rulerTextarea.style.boxSizing = styles.boxSizing;
            this.rulerTextarea.style.height = 'calc((' + styles.lineHeight + ' * 2) + ' + styles.paddingTop + ' + ' + styles.paddingBottom + ')';
            this.rulerWrap.scrollLeft = this.textarea.scrollLeft;
        }

        syncRulerScroll() {
            if (!this.textarea || !this.rulerWrap) {
                return;
            }
            this.rulerWrap.scrollLeft = this.textarea.scrollLeft;
        }

        openPreview() {
            openPreview({
                title: this.getPreviewTitle(),
                content: this.textarea.value || ''
            });
        }
    }

    function create(root, options) {
        if (!root) {
            return null;
        }
        return new AnsiEditor(root, options);
    }

    window.AnsiEditor = {
        create,
        renderPreview,
        openPreview,
        openCheatsheet
    };
})(window, document);
