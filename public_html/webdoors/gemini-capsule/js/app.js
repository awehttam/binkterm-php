/**
 * Gemini Capsule WebDoor — Editor
 *
 * Split-pane gemtext editor with live preview.
 * Depends on: gemtext.js (from gemini-browser, loaded before this file)
 */

const GeminiCapsule = (() => {
    'use strict';

    // ── State ─────────────────────────────────────────────────────────────────

    let currentFile     = null;   // filename string or null
    let isPublished     = false;
    let isDirty         = false;
    let capsuleUrl      = '';
    let previewDebounce = null;

    // ── DOM refs ──────────────────────────────────────────────────────────────

    const el = {};

    function bindRefs() {
        el.fileList        = document.getElementById('file-list');
        el.btnNewFile      = document.getElementById('btn-new-file');
        el.newFileForm     = document.getElementById('new-file-form');
        el.newFilenameInput= document.getElementById('new-filename-input');
        el.btnCreateConfirm= document.getElementById('btn-create-confirm');
        el.btnCreateCancel = document.getElementById('btn-create-cancel');
        el.editorArea      = document.getElementById('editor-area');
        el.editorEmpty     = document.getElementById('editor-empty');
        el.editingFilename = document.getElementById('editing-filename');
        el.textarea        = document.getElementById('editor-textarea');
        el.previewPane     = document.getElementById('preview-pane');
        el.btnSave         = document.getElementById('btn-save');
        el.btnPublish      = document.getElementById('btn-publish');
        el.btnDelete       = document.getElementById('btn-delete');
        el.capsuleUrlLink  = document.getElementById('capsule-url-link');
        el.toastContainer  = document.getElementById('toast-container');
        el.btnCheatsheet   = document.getElementById('btn-cheatsheet');
        el.cheatsheet      = document.getElementById('cheatsheet');
    }

    // ── API helpers ───────────────────────────────────────────────────────────

    async function apiGet(action, params = {}) {
        const qs = new URLSearchParams({ action, ...params });
        const res = await fetch(`api.php?${qs}`);
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    async function apiPost(action, body = {}) {
        const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    // ── Toast notifications ───────────────────────────────────────────────────

    function toast(message, type = 'info') {
        const t = document.createElement('div');
        t.className = `toast toast-${type}`;
        t.textContent = message;
        el.toastContainer.appendChild(t);
        setTimeout(() => t.classList.add('toast-show'), 10);
        setTimeout(() => {
            t.classList.remove('toast-show');
            setTimeout(() => t.remove(), 300);
        }, 3000);
    }

    // ── File list ─────────────────────────────────────────────────────────────

    async function loadFileList(selectFilename = null) {
        try {
            const data = await apiGet('file_list');
            renderFileList(data.files || [], selectFilename);
        } catch (err) {
            toast('Failed to load files: ' + err.message, 'error');
        }
    }

    function renderFileList(files, selectFilename) {
        el.fileList.innerHTML = '';

        if (files.length === 0) {
            const li = document.createElement('li');
            li.className = 'file-list-empty';
            li.textContent = 'No files yet';
            el.fileList.appendChild(li);
            return;
        }

        files.forEach(file => {
            const li = document.createElement('li');
            li.className = 'file-item' + (file.filename === currentFile ? ' active' : '');
            li.dataset.filename = file.filename;

            const dot = document.createElement('span');
            dot.className = 'publish-dot ' + (file.is_published ? 'published' : 'draft');
            dot.title = file.is_published ? 'Published' : 'Draft';

            const name = document.createElement('span');
            name.className = 'file-name';
            name.textContent = file.filename;

            li.appendChild(dot);
            li.appendChild(name);
            li.addEventListener('click', () => openFile(file.filename));
            el.fileList.appendChild(li);
        });

        if (selectFilename) {
            openFile(selectFilename);
        }
    }

    // ── Open / close file ─────────────────────────────────────────────────────

    async function openFile(filename) {
        if (isDirty && currentFile) {
            if (!confirm(`You have unsaved changes in "${currentFile}". Discard them?`)) {
                return;
            }
        }

        try {
            const data = await apiGet('file_load', { filename });
            currentFile = data.filename;
            isPublished = data.is_published;
            isDirty     = false;

            el.editorEmpty.classList.add('hidden');
            el.editorArea.classList.remove('hidden');
            el.editingFilename.textContent = currentFile;
            el.textarea.value = data.content;
            updatePublishButton();
            updatePreview();
            highlightActive(filename);
        } catch (err) {
            toast('Failed to load file: ' + err.message, 'error');
        }
    }

    function highlightActive(filename) {
        document.querySelectorAll('#file-list .file-item').forEach(li => {
            li.classList.toggle('active', li.dataset.filename === filename);
        });
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    async function saveFile() {
        if (!currentFile) return;

        el.btnSave.disabled = true;
        try {
            await apiPost('file_save', {
                filename: currentFile,
                content:  el.textarea.value,
            });
            isDirty = false;
            toast(`"${currentFile}" saved`, 'success');
            await loadFileList(null);
            highlightActive(currentFile);
        } catch (err) {
            toast('Save failed: ' + err.message, 'error');
        } finally {
            el.btnSave.disabled = false;
        }
    }

    // ── Publish toggle ────────────────────────────────────────────────────────

    async function togglePublish() {
        if (!currentFile) return;

        const newState = !isPublished;
        el.btnPublish.disabled = true;

        try {
            await apiPost('file_publish', {
                filename:     currentFile,
                is_published: newState,
            });
            isPublished = newState;
            updatePublishButton();
            const label = newState ? 'published' : 'unpublished';
            toast(`"${currentFile}" ${label}`, 'success');
            await loadFileList(null);
            highlightActive(currentFile);
        } catch (err) {
            toast('Failed to update publish status: ' + err.message, 'error');
        } finally {
            el.btnPublish.disabled = false;
        }
    }

    function updatePublishButton() {
        el.btnPublish.textContent = isPublished ? 'Unpublish' : 'Publish';
        el.btnPublish.classList.toggle('btn-published', isPublished);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    async function deleteFile() {
        if (!currentFile) return;

        if (!confirm(`Delete "${currentFile}"? This cannot be undone.`)) {
            return;
        }

        el.btnDelete.disabled = true;
        try {
            await apiPost('file_delete', { filename: currentFile });
            toast(`"${currentFile}" deleted`, 'info');
            currentFile = null;
            isPublished = false;
            isDirty     = false;
            el.editorArea.classList.add('hidden');
            el.editorEmpty.classList.remove('hidden');
            await loadFileList();
        } catch (err) {
            toast('Delete failed: ' + err.message, 'error');
        } finally {
            el.btnDelete.disabled = false;
        }
    }

    // ── New file ──────────────────────────────────────────────────────────────

    function showNewFileForm() {
        el.newFileForm.classList.remove('hidden');
        el.newFilenameInput.value = '';
        el.newFilenameInput.focus();
    }

    function hideNewFileForm() {
        el.newFileForm.classList.add('hidden');
        el.newFilenameInput.value = '';
    }

    async function createNewFile() {
        let filename = el.newFilenameInput.value.trim();

        if (!filename) return;

        // Auto-append .gmi if no extension given
        if (!filename.includes('.')) {
            filename += '.gmi';
        }

        if (!/^[a-zA-Z0-9_\-]+\.(gmi|gemini)$/.test(filename)) {
            toast('Invalid filename — use letters, numbers, dashes, underscores with .gmi or .gemini extension', 'error');
            return;
        }

        try {
            await apiPost('file_save', { filename, content: '' });
            hideNewFileForm();
            await loadFileList(filename);
        } catch (err) {
            toast('Failed to create file: ' + err.message, 'error');
        }
    }

    // ── Preview ───────────────────────────────────────────────────────────────

    function updatePreview() {
        const content  = el.textarea.value;
        const baseUrl  = capsuleUrl || 'gemini://localhost/';
        el.previewPane.innerHTML = renderGemtext(content, baseUrl);
    }

    function schedulePreview() {
        clearTimeout(previewDebounce);
        previewDebounce = setTimeout(updatePreview, 300);
    }

    // ── Capsule URL ───────────────────────────────────────────────────────────

    async function loadCapsuleUrl() {
        try {
            const data = await apiGet('capsule_url');
            capsuleUrl = data.url || '';
            el.capsuleUrlLink.textContent = capsuleUrl;
            el.capsuleUrlLink.href        = capsuleUrl;
        } catch (err) {
            el.capsuleUrlLink.textContent = 'unavailable';
        }
    }

    // ── Keyboard shortcuts ────────────────────────────────────────────────────

    function handleKeydown(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveFile();
        }
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    async function init() {
        bindRefs();

        // Buttons
        el.btnNewFile.addEventListener('click', showNewFileForm);
        el.btnCreateConfirm.addEventListener('click', createNewFile);
        el.btnCreateCancel.addEventListener('click', hideNewFileForm);
        el.btnSave.addEventListener('click', saveFile);
        el.btnPublish.addEventListener('click', togglePublish);
        el.btnDelete.addEventListener('click', deleteFile);
        el.btnCheatsheet.addEventListener('click', () => {
            const visible = !el.cheatsheet.classList.contains('hidden');
            el.cheatsheet.classList.toggle('hidden', visible);
            el.btnCheatsheet.classList.toggle('active', !visible);
        });

        // New file: Enter to confirm, Escape to cancel
        el.newFilenameInput.addEventListener('keydown', e => {
            if (e.key === 'Enter')  createNewFile();
            if (e.key === 'Escape') hideNewFileForm();
        });

        // Editor
        el.textarea.addEventListener('input', () => {
            isDirty = true;
            schedulePreview();
        });

        document.addEventListener('keydown', handleKeydown);

        // Load initial data
        await Promise.all([loadCapsuleUrl(), loadFileList()]);
    }

    return { init };
})();

document.addEventListener('DOMContentLoaded', () => GeminiCapsule.init());
