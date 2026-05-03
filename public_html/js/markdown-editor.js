(function(window) {
    'use strict';

    function sanitizeTuiMarkdown(md) {
        md = String(md || '');
        const protectedSegments = [];
        const placeholderPrefix = '%%TUIESC';

        md = md.replace(/```[\s\S]*?```|`[^`\n]+`|!?\[[^\]]*\]\([^)]+\)/g, function(segment) {
            const token = placeholderPrefix + protectedSegments.length + '%%';
            protectedSegments.push(segment);
            return token;
        });

        md = md
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<[^>]+>/g, '')
            .replace(/^(>+[ ]*)((?:\\#)+)( )/gm, function(match, prefix, hashes, space) {
                return prefix + hashes.replace(/\\#/g, '#') + space;
            })
            .replace(/\\([.~|])/g, '$1');

        return md.replace(/%%TUIESC(\d+)%%/g, function(match, index) {
            return protectedSegments[parseInt(index, 10)] || match;
        });
    }

    function resolveElement(value) {
        return typeof value === 'string' ? document.querySelector(value) : value;
    }

    function MarkdownEditor(options) {
        this.options = options || {};
        this.editor = null;
        this.active = false;
        this.boundKeydown = null;
        this.boundPaste = null;
    }

    MarkdownEditor.prototype.init = function() {
        if (this.active) {
            return this.editor;
        }
        if (!window.toastui || !window.toastui.Editor) {
            throw new Error('Toast UI Editor is not loaded');
        }

        const textarea = resolveElement(this.options.textarea);
        const editorEl = resolveElement(this.options.editorEl);
        const plainContainer = resolveElement(this.options.plainContainer);
        const editorContainer = resolveElement(this.options.editorContainer);
        const height = this.options.height || ((textarea && textarea.offsetHeight) ? textarea.offsetHeight + 'px' : '320px');

        if (plainContainer) {
            plainContainer.style.display = 'none';
        } else if (textarea) {
            textarea.style.display = 'none';
        }
        if (editorContainer) {
            editorContainer.style.display = '';
        }

        this.editor = new window.toastui.Editor({
            el: editorEl,
            height: height,
            initialEditType: this.options.initialEditType || 'wysiwyg',
            previewStyle: this.options.previewStyle || 'tab',
            initialValue: textarea ? textarea.value : '',
            toolbarItems: this.options.toolbarItems || [],
            usageStatistics: false,
            events: {
                change: () => {
                    const raw = this.editor ? this.editor.getMarkdown() : '';
                    const cleaned = sanitizeTuiMarkdown(raw);
                    if (textarea) {
                        textarea.value = cleaned;
                    }
                    if (this.editor && this.editor.isMarkdownMode && this.editor.isMarkdownMode() && cleaned !== raw) {
                        this.editor.setMarkdown(cleaned, false);
                    }
                    if (typeof this.options.onChange === 'function') {
                        this.options.onChange(cleaned, raw, this.editor);
                    }
                }
            }
        });

        const editorUI = editorEl ? editorEl.querySelector('.toastui-editor-defaultUI') : null;
        if (editorUI) {
            editorUI.classList.add('toastui-editor-dark');
        }

        if (typeof this.options.onCtrlK === 'function' && editorEl) {
            this.boundKeydown = (event) => {
                if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
                    event.preventDefault();
                    event.stopPropagation();
                    this.options.onCtrlK(event, this.editor);
                }
            };
            editorEl.addEventListener('keydown', this.boundKeydown, true);
        }

        if (typeof this.options.onBareUrlPaste === 'function' && editorEl) {
            this.boundPaste = (event) => {
                const data = event.clipboardData || window.clipboardData;
                const text = data && data.getData ? data.getData('text/plain').trim() : '';
                if (/^https?:\/\/\S+$/.test(text)) {
                    this.options.onBareUrlPaste(text, event, this.editor);
                }
            };
            editorEl.addEventListener('paste', this.boundPaste, true);
        }

        this.active = true;
        if (this.options.focus !== false && this.editor) {
            this.editor.focus();
        }
        return this.editor;
    };

    MarkdownEditor.prototype.destroy = function(options) {
        options = options || {};
        const sync = options.sync !== false;
        const textarea = resolveElement(this.options.textarea);
        const editorEl = resolveElement(this.options.editorEl);
        const plainContainer = resolveElement(this.options.plainContainer);
        const editorContainer = resolveElement(this.options.editorContainer);

        if (!this.active && !this.editor) {
            return;
        }

        if (sync) {
            this.sync();
        }

        if (editorEl && this.boundKeydown) {
            editorEl.removeEventListener('keydown', this.boundKeydown, true);
            this.boundKeydown = null;
        }
        if (editorEl && this.boundPaste) {
            editorEl.removeEventListener('paste', this.boundPaste, true);
            this.boundPaste = null;
        }

        if (this.editor) {
            this.editor.destroy();
            this.editor = null;
        }

        if (editorContainer) {
            editorContainer.style.display = 'none';
        }
        if (plainContainer) {
            plainContainer.style.display = '';
        } else if (textarea) {
            textarea.style.display = '';
        }

        this.active = false;
    };

    MarkdownEditor.prototype.sync = function() {
        const textarea = resolveElement(this.options.textarea);
        if (this.editor && textarea) {
            textarea.value = sanitizeTuiMarkdown(this.editor.getMarkdown());
        }
        return textarea ? textarea.value : '';
    };

    MarkdownEditor.prototype.setMarkdown = function(markdown, cursorToEnd) {
        const textarea = resolveElement(this.options.textarea);
        if (textarea) {
            textarea.value = String(markdown || '');
        }
        if (this.editor) {
            this.editor.setMarkdown(String(markdown || ''), cursorToEnd === true);
        }
    };

    MarkdownEditor.prototype.getMarkdown = function() {
        return this.sync();
    };

    MarkdownEditor.prototype.exec = function(action, payload) {
        if (!this.editor) {
            return false;
        }
        const commandMap = {
            bold: 'bold',
            italic: 'italic',
            code: 'code',
            codeblock: 'codeBlock',
            ul: 'bulletList',
            ol: 'orderedList',
            quote: 'blockQuote',
            hr: 'hr'
        };
        if (action === 'h1' || action === 'h2' || action === 'h3') {
            this.editor.exec('heading', { level: parseInt(action.substring(1), 10) });
        } else if (commandMap[action]) {
            this.editor.exec(commandMap[action], payload);
        } else {
            this.editor.exec(action, payload);
        }
        this.editor.focus();
        return true;
    };

    MarkdownEditor.prototype.focus = function() {
        if (this.editor) {
            this.editor.focus();
        }
    };

    MarkdownEditor.prototype.getToastEditor = function() {
        return this.editor;
    };

    window.BinkMarkdownEditor = {
        sanitize: sanitizeTuiMarkdown,
        create: function(options) {
            return new MarkdownEditor(options);
        },
        mount: function(options) {
            const controller = new MarkdownEditor(options);
            controller.init();
            return controller;
        }
    };
})(window);
