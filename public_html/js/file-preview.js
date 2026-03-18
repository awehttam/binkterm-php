/**
 * File preview renderer — shared between files.twig and shared_file.twig.
 *
 * Dependencies (must be loaded first):
 *   app.js     — escapeHtml()
 *   ansisys.js — renderAnsiBuffer(), renderPetsciiBuffer(), renderPrgToCanvas(),
 *                findScreenRamOffset(), byteStringFromBase64()
 */

const previewImageExts   = ['jpg','jpeg','png','gif','webp','svg','bmp','ico','tiff','tif','avif'];
const previewVideoExts   = ['mp4','webm','mov','ogv','m4v'];
const previewAudioExts   = ['mp3','wav','ogg','flac','aac','m4a','opus'];
const previewTextExts    = ['txt','log','nfo','diz','md','cfg','ini','conf','lsm','json','xml','bat','sh'];
const previewAnsiExts          = ['ans'];
const previewPetsciiExts       = ['prg'];
const previewPetsciiStreamExts = ['seq'];
const previewD64Exts           = ['d64'];
const previewRipExts           = ['rip'];

function getFileType(filename) {
    const ext = (filename.includes('.') ? filename.split('.').pop() : '').toLowerCase();
    if (previewImageExts.includes(ext))          return 'image';
    if (previewVideoExts.includes(ext))          return 'video';
    if (previewAudioExts.includes(ext))          return 'audio';
    if (previewTextExts.includes(ext))           return 'text';
    if (previewAnsiExts.includes(ext))           return 'ansi';
    if (previewPetsciiExts.includes(ext))        return 'petscii';
    if (previewPetsciiStreamExts.includes(ext))  return 'petscii_stream';
    if (previewD64Exts.includes(ext))            return 'd64';
    if (previewRipExts.includes(ext))            return 'rip';
    return 'download';
}

/** @param {string} key @param {string} fallback @param {object} [params] */
function _fpT(key, fallback, params) {
    return window.t ? window.t(key, params || {}, fallback) : fallback;
}

/** Format bytes for display in the ZIP file browser. */
function _fpBytes(bytes) {
    if (!bytes) return '0 B';
    const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

/**
 * Render a file preview into the given jQuery container.
 *
 * @param {number} fileId
 * @param {string} filename
 * @param {jQuery} container
 * @param {{share_area?: string, share_filename?: string}} [shareParams] - Optional share context for unauthenticated access
 */
function renderPreviewContent(fileId, filename, container, shareParams) {
    const body = container;
    const type = getFileType(filename);
    const shareQs = (shareParams && shareParams.share_area && shareParams.share_filename)
        ? '?share_area=' + encodeURIComponent(shareParams.share_area)
          + '&share_filename=' + encodeURIComponent(shareParams.share_filename)
        : '';
    const previewUrl = `/api/files/${fileId}/preview` + shareQs;
    const prgsUrl    = `/api/files/${fileId}/prgs`    + shareQs;

    body.find('video,audio').each(function() { this.pause(); this.src = ''; });

    if (type === 'image') {
        body.css('background', '#1a1a1a').html(`
            <a href="${previewUrl}" target="_blank" title="${_fpT('ui.files.view_full_size', 'View full size')}">
                <img src="${previewUrl}"
                     class="img-fluid d-block mx-auto"
                     style="max-height:78vh;object-fit:contain;cursor:zoom-in;"
                     alt="${escapeHtml(filename)}">
            </a>
        `);

    } else if (type === 'video') {
        body.css('background', '#000').html(`
            <video controls class="d-block mx-auto" style="max-width:100%;max-height:75vh;">
                <source src="${previewUrl}">
                ${_fpT('ui.files.video_not_supported', 'Video format not supported by your browser')}
            </video>
        `);

    } else if (type === 'audio') {
        body.css('background', '').html(`
            <div class="p-5 text-center">
                <i class="fas fa-music fa-3x text-muted mb-3 d-block"></i>
                <p class="text-muted mb-3">${escapeHtml(filename)}</p>
                <audio controls class="w-100" style="max-width:520px;">
                    <source src="${previewUrl}">
                </audio>
            </div>
        `);

    } else if (type === 'text') {
        const ext = filename.includes('.') ? filename.split('.').pop().toLowerCase() : '';
        const isRetro = ['nfo','diz','ans'].includes(ext);
        const preStyle = isRetro
            ? 'background:#0a0a0a;color:#c8c8c8;font-family:"Courier New",Courier,monospace;'
            : '';
        body.css('background', '').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        fetch(previewUrl, {credentials: 'same-origin'})
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(text => {
                body.html(`<pre class="m-0 p-3" style="max-height:75vh;overflow:auto;font-size:0.85em;white-space:pre-wrap;word-break:break-all;${preStyle}">${escapeHtml(text)}</pre>`);
            })
            .catch(() => {
                body.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'ansi') {
        body.css('background', '#0a0a0a').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        fetch(previewUrl, {credentials: 'same-origin'})
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(text => {
                const artHtml = renderAnsiBuffer(text, 80, 500);
                body.css('background', '#0a0a0a').html(`
                    <div class="ansi-art-container" style="overflow:auto;max-height:78vh;background:#0a0a0a;padding:8px;">
                        <pre class="m-0">${artHtml}</pre>
                    </div>
                `);
            })
            .catch(() => {
                body.css('background', '').html(
                    `<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`
                );
            });

    } else if (type === 'petscii') {
        body.css('background', '#0000aa').html(
            `<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x" style="color:#55ffff;"></i></div>`
        );
        fetch(prgsUrl, {credentials: 'same-origin'})
            .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
            .then(data => {
                if (!data.prgs || !data.prgs.length) throw new Error('empty');
                renderPrgGallery(body, data.prgs, fileId, shareQs);
            })
            .catch(() => {
                body.css('background', '').html(
                    `<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`
                );
            });

    } else if (type === 'petscii_stream') {
        body.css('background', '#000').html(
            `<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x" style="color:#55ffff;"></i></div>`
        );
        fetch(previewUrl, {credentials: 'same-origin'})
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
            .then(buf => {
                const uBytes  = new Uint8Array(buf);
                const byteStr = Array.from(uBytes, b => String.fromCharCode(b)).join('');
                const emuUrl  = `/c64emu/?file_id=${encodeURIComponent(fileId)}` + shareQs.replace('?', '&');
                const draw = () => {
                    const html = renderPetsciiBuffer(byteStr, 40, 500);
                    body.css('background', '#000').html(`
                        <div class="ansi-art-container art-format-petscii" style="overflow:auto;max-height:78vh;background:#000;padding:8px;text-align:center;">
                            <pre class="m-0 d-inline-block text-start" style="letter-spacing:0;">${html}</pre>
                        </div>
                    `);
                    const runBtn = $('<button>').addClass('btn btn-sm btn-outline-warning')
                        .html('<i class="fas fa-play me-1"></i>' + _fpT('ui.files.prg_run_c64', 'Run on C64'))
                        .on('click', function () {
                            body.css({ background: '#000', padding: '0', textAlign: 'center' }).empty().append(
                                $('<iframe>').attr('src', emuUrl).attr('title', 'C64')
                                    .css({ border: 'none', width: '403px', height: '284px', maxWidth: '100%', display: 'block', margin: '0 auto' })
                            );
                        });
                    body.append(
                        $('<div>').addClass('d-flex justify-content-end px-3 py-2 border-top')
                            .css({ background: '#111' })
                            .append(runBtn)
                    );
                };
                if (document.fonts && document.fonts.load) {
                    document.fonts.load('8px "Pet Me 64"').then(draw, draw);
                } else {
                    draw();
                }
            })
            .catch(() => {
                body.css('background', '').html(
                    `<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`
                );
            });

    } else if (type === 'd64') {
        body.css('background', '').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        fetch(prgsUrl, {credentials: 'same-origin'})
            .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
            .then(data => {
                if (!data.prgs || !data.prgs.length) throw new Error('empty');
                const diskName = data.disk_name || '';
                if (diskName) {
                    const retroStyle = 'background:#0a0a0a;color:#c8c8c8;font-family:"Courier New",Courier,monospace;';
                    body.html(`
                        <div class="px-3 py-2" style="${retroStyle}border-bottom:1px solid #333;font-size:0.85em;">
                            <i class="fas fa-floppy-disk me-2"></i>${escapeHtml(diskName)}
                        </div>
                        <div id="prgGalleryContainer"></div>
                    `);
                    renderPrgGallery($('#prgGalleryContainer'), data.prgs, fileId, shareQs);
                } else {
                    renderPrgGallery(body, data.prgs, fileId, shareQs);
                }
            })
            .catch(() => {
                body.css('background', '').html(`
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-floppy-disk fa-3x mb-3 d-block"></i>
                        <p class="mb-2">${escapeHtml(filename)}</p>
                        <p class="small mb-4">${_fpT('ui.files.no_prgs_in_d64', 'No PRG files found in disk image')}</p>
                    </div>
                `);
            });

    } else if (type === 'rip') {
        body.css('background', '#0a0a0a').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        fetch(previewUrl, {credentials: 'same-origin'})
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(html => {
                body.css('background', '#0a0a0a').html(`
                    <div style="overflow:auto;max-height:78vh;padding:8px;text-align:center;">${html}</div>
                `);
            })
            .catch(() => {
                body.css('background', '').html(
                    `<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`
                );
            });

    } else if (filename.toLowerCase().endsWith('.zip')) {
        body.css('background', '').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        renderZipBrowser(body, fileId, shareQs);

    } else {
        body.css('background', '').html(`
            <div class="p-5 text-center text-muted">
                <i class="fas fa-file fa-3x mb-3 d-block"></i>
                <p class="mb-2">${escapeHtml(filename)}</p>
                <p class="small mb-4">${_fpT('ui.files.no_preview', 'No preview available for this file type')}</p>
            </div>
        `);
    }
}

/**
 * Render a C64 PRG art gallery into a jQuery container.
 * Each entry in prgs: {name, load_address, data_b64}
 * @param {string} [shareQs] - Optional share query string (e.g. '?share_area=X&share_filename=Y')
 */
function renderPrgGallery(container, prgs, fileId, shareQs) {
    shareQs = shareQs || '';
    let idx = 0;

    function show(i) {
        const prg     = prgs[i];
        const byteStr = byteStringFromBase64(prg.data_b64);
        const uBytes  = new Uint8Array(byteStr.length);
        for (let _i = 0; _i < byteStr.length; _i++) uBytes[_i] = byteStr.charCodeAt(_i) & 0xff;

        let canvasData = null;
        if (prg.load_address === 0x0400) {
            canvasData = uBytes;
        } else {
            const offset = findScreenRamOffset(uBytes);
            if (offset >= 0) canvasData = uBytes.slice(offset);
        }

        const isFirst = i === 0;
        const isLast  = i === prgs.length - 1;

        container.css('background', '#000').empty();

        const artWrap = $('<div>').css({
            overflow: 'auto', maxHeight: '65vh',
            background: '#000', textAlign: 'center', padding: '8px',
        });

        const drawArt = () => {
            if (canvasData) {
                const cvs = renderPrgToCanvas(canvasData, 40, 25);
                cvs.style.imageRendering = 'pixelated';
                cvs.style.width   = '640px';
                cvs.style.height  = '400px';
                cvs.style.maxWidth = '100%';
                artWrap.empty().append(cvs);
            } else {
                // Machine code PRG — show notice; clicking Run on C64 replaces it with the emulator inline
                const emuUrl = `/c64emu/?file_id=${encodeURIComponent(fileId)}&prg=${encodeURIComponent(prg.name)}` + shareQs.replace('?', '&');
                const notice = $('<div>').addClass('p-4 text-center text-muted').append(
                    $('<i>').addClass('fas fa-microchip fa-2x d-block mb-2'),
                    $('<div>').addClass('mb-3').append(
                        $('<small>').text(_fpT('ui.files.prg_no_preview', 'Preview unavailable — machine code program'))
                    ),
                    $('<button>').addClass('btn btn-sm btn-outline-warning').html(
                        '<i class="fas fa-play me-1"></i>' + _fpT('ui.files.prg_run_c64', 'Run on C64')
                    ).on('click', function () {
                        artWrap.css({ background: '#000', padding: '0', textAlign: 'center' }).empty().append(
                            $('<iframe>').attr('src', emuUrl).attr('title', prg.name)
                                .css({ border: 'none', width: '403px', height: '284px', maxWidth: '100%', display: 'block', margin: '0 auto' })
                        );
                    })
                );
                artWrap.css('background', '').empty().append(notice);
            }
        };

        if (document.fonts && document.fonts.load) {
            document.fonts.load('8px "Pet Me 64"').then(drawArt, drawArt);
        } else {
            drawArt();
        }

        container.append(artWrap);

        const emuUrl = `/c64emu/?file_id=${encodeURIComponent(fileId)}&prg=${encodeURIComponent(prg.name)}` + shareQs.replace('?', '&');
        const runBtn = $('<button>').addClass('btn btn-sm btn-outline-warning ms-2')
            .attr('title', _fpT('ui.files.prg_run_c64', 'Run on C64'))
            .html('<i class="fas fa-play"></i>')
            .on('click', function () {
                artWrap.css({ background: '#000', padding: '0', textAlign: 'center' }).empty().append(
                    $('<iframe>').attr('src', emuUrl).attr('title', prg.name)
                        .css({ border: 'none', width: '403px', height: '284px', maxWidth: '100%', display: 'block', margin: '0 auto' })
                );
                navBar.remove();
            });

        let navBar;
        if (prgs.length > 1) {
            navBar = $('<div>').addClass('d-flex align-items-center justify-content-between px-3 py-2 border-top')
                .css({ background: '#111', minHeight: '42px' })
                .append(
                    $('<button>').addClass('btn btn-sm btn-outline-secondary').attr('id', 'prgPrev').prop('disabled', isFirst)
                        .html('<i class="fas fa-chevron-left"></i>'),
                    $('<small>').addClass('text-muted text-truncate mx-2')
                        .html(escapeHtml(prg.name) + ` (${i + 1}\u202f/\u202f${prgs.length})`),
                    $('<div>').addClass('d-flex align-items-center').append(
                        $('<button>').addClass('btn btn-sm btn-outline-secondary').attr('id', 'prgNext').prop('disabled', isLast)
                            .html('<i class="fas fa-chevron-right"></i>'),
                        runBtn
                    )
                );
        } else {
            navBar = $('<div>').addClass('d-flex align-items-center justify-content-between px-3 py-2 border-top')
                .css({ background: '#111', minHeight: '42px' })
                .append(
                    $('<small>').addClass('text-muted text-truncate').text(prg.name),
                    runBtn
                );
        }
        container.append(navBar);

        if (prgs.length > 1) {
            container.find('#prgPrev').on('click', function() { idx--; show(idx); });
            container.find('#prgNext').on('click', function() { idx++; show(idx); });
        }
    }

    show(idx);
}

/**
 * Render a RIPscrip art gallery into a jQuery container.
 * Each entry in rips: {name, html} where html is server-rendered SVG.
 */
function renderRipGallery(container, rips) {
    let idx = 0;

    function show(i) {
        const rip    = rips[i];
        const isFirst = i === 0;
        const isLast  = i === rips.length - 1;

        container.css('background', '#0a0a0a').empty();

        const artWrap = $('<div>').css({
            overflow: 'auto', maxHeight: '65vh',
            background: '#0a0a0a', textAlign: 'center', padding: '8px',
        }).html(rip.html);

        container.append(artWrap);

        if (rips.length > 1) {
            const navBar = $('<div>').addClass('d-flex align-items-center justify-content-between px-3 py-2 border-top')
                .css({ background: '#111', minHeight: '42px' })
                .append(
                    $('<button>').addClass('btn btn-sm btn-outline-secondary').prop('disabled', isFirst)
                        .html('<i class="fas fa-chevron-left"></i>')
                        .on('click', function() { idx--; show(idx); }),
                    $('<small>').addClass('text-muted text-truncate mx-2')
                        .html(escapeHtml(rip.name) + ` (${i + 1}\u202f/\u202f${rips.length})`),
                    $('<button>').addClass('btn btn-sm btn-outline-secondary').prop('disabled', isLast)
                        .html('<i class="fas fa-chevron-right"></i>')
                        .on('click', function() { idx++; show(idx); })
                );
            container.append(navBar);
        }
    }

    show(idx);
}

// ---------------------------------------------------------------------------
// ZIP file browser
// ---------------------------------------------------------------------------

/** Map a file type string to a FontAwesome icon class. */
function zipEntryIcon(type) {
    switch (type) {
        case 'image':          return 'fa-image';
        case 'video':          return 'fa-film';
        case 'audio':          return 'fa-music';
        case 'text':           return 'fa-file-lines';
        case 'ansi':           return 'fa-terminal';
        case 'rip':            return 'fa-paint-brush';
        case 'petscii':        return 'fa-gamepad';
        case 'petscii_stream': return 'fa-gamepad';
        case 'd64':            return 'fa-floppy-disk';
        default:               return 'fa-file';
    }
}

/**
 * Render a browsable listing of a ZIP file's contents into a container.
 * Clicking a previewable entry opens it via renderZipEntry().
 *
 * @param {jQuery}  container
 * @param {number}  fileId
 * @param {string}  shareQs  - e.g. '' or '?share_area=X&share_filename=Y'
 */
function renderZipBrowser(container, fileId, shareQs) {
    const contentsUrl = `/api/files/${fileId}/zip-contents` + shareQs;

    fetch(contentsUrl, {credentials: 'same-origin'})
        .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
        .then(data => {
            const entries = data.entries || [];

            if (entries.length === 0) {
                container.css('background', '').html(`
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-file-zipper fa-3x mb-3 d-block"></i>
                        <p>${_fpT('ui.files.zip_empty', 'ZIP archive is empty')}</p>
                    </div>
                `);
                return;
            }

            // Compression methods supported by ZipArchive/libzip without a shell fallback
            const SUPPORTED_COMP = [0, 8, 12, 14, 20];

            let rows = '';
            entries.forEach(function(entry) {
                const type       = getFileType(entry.name);
                const icon       = zipEntryIcon(type);
                const canPreview = type !== 'download';
                // Entries with non-standard compression may require shell fallback;
                // flag them visually but still allow click (server will attempt unzip)
                const legacyComp = !SUPPORTED_COMP.includes(entry.comp_method ?? 0);
                const entryQs   = shareQs
                    ? shareQs + '&path=' + encodeURIComponent(entry.path)
                    : '?path='           + encodeURIComponent(entry.path);
                const entryUrl  = `/api/files/${fileId}/zip-entry` + entryQs;

                const dlBtn = `<a href="${entryUrl}" class="btn btn-sm btn-outline-secondary ms-2 flex-shrink-0" download="${escapeHtml(entry.name)}" onclick="event.stopPropagation()"><i class="fas fa-download"></i></a>`;

                const legacyBadge = legacyComp
                    ? `<span class="badge bg-secondary ms-2 flex-shrink-0" title="${_fpT('ui.files.zip_legacy_badge', 'Legacy compression')}" style="font-size:0.65em;">legacy</span>`
                    : '';

                rows += `
                    <div class="d-flex align-items-center px-3 py-2 border-bottom zip-entry-row"
                         style="cursor:${canPreview ? 'pointer' : 'default'}; min-width:0;"
                         data-path="${escapeHtml(entry.path)}" data-name="${escapeHtml(entry.name)}">
                        <i class="fas ${icon} text-muted me-2 flex-shrink-0" style="width:16px;"></i>
                        <span class="flex-grow-1 text-truncate small" style="min-width:0;">${escapeHtml(entry.path)}</span>
                        ${legacyBadge}
                        <span class="text-muted small ms-3 flex-shrink-0">${_fpBytes(entry.size)}</span>
                        ${dlBtn}
                    </div>`;
            });

            const truncNote = data.total > entries.length
                ? `<div class="px-3 py-2 text-muted small border-top">${_fpT('ui.files.zip_truncated', 'Showing first {count} entries', {count: entries.length})}</div>`
                : '';

            container.css('background', '').html(`
                <div>
                    ${rows}
                    ${truncNote}
                </div>
            `);

            // Only previewable entries participate in arrow navigation
            const previewable = entries.filter(e => getFileType(e.name) !== 'download');

            container.find('.zip-entry-row').on('click', function() {
                const path = $(this).data('path');
                const name = $(this).data('name');
                if (getFileType(name) === 'download') return;
                const idx = previewable.findIndex(e => e.path === path);
                renderZipEntry(container, fileId, path, name, shareQs,
                    () => renderZipBrowser(container, fileId, shareQs),
                    previewable, idx);
            });
        })
        .catch(function() {
            container.css('background', '').html(
                `<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load ZIP contents')}</div>`
            );
        });
}

/**
 * Fetch a ZIP entry, returning a Promise that resolves to {text} or {buffer}.
 * Rejects with {legacy:true} on 415 (unsupported compression method).
 *
 * @param {string} url
 * @param {'text'|'buffer'} responseType
 * @returns {Promise}
 */
function fetchZipEntry(url, responseType) {
    return fetch(url, {credentials: 'same-origin'})
        .then(r => {
            if (r.status === 415) {
                return r.json().then(err => Promise.reject({ legacy: true, compMethod: err.comp_method }));
            }
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return responseType === 'buffer' ? r.arrayBuffer() : r.text();
        });
}

/**
 * Render a "legacy compression" notice into a container with a full-ZIP download link.
 */
function renderLegacyCompressionNotice(container, fileId, entryName, shareQs) {
    const dlUrl = `/api/files/${fileId}/download` + (shareQs || '');
    container.css('background', '').html(`
        <div class="p-5 text-center text-muted">
            <i class="fas fa-archive fa-3x mb-3 d-block"></i>
            <p class="mb-1"><strong>${escapeHtml(entryName)}</strong></p>
            <p class="small mb-3">${_fpT('ui.files.zip_legacy_compression', 'This file uses a legacy compression format that cannot be previewed.')}</p>
            <a href="${dlUrl}" download class="btn btn-outline-secondary">
                <i class="fas fa-download me-1"></i>${_fpT('ui.files.download_zip', 'Download full ZIP')}
            </a>
        </div>
    `);
}

/**
 * Preview a single entry from inside a ZIP file.
 *
 * @param {jQuery}   container
 * @param {number}   fileId
 * @param {string}   entryPath    - full path within the ZIP
 * @param {string}   entryName    - basename
 * @param {string}   shareQs
 * @param {function} onBack       - called when the back button is clicked
 * @param {Array}    [entries]    - previewable entries list for prev/next nav
 * @param {number}   [entryIndex] - index of current entry within entries
 */
function renderZipEntry(container, fileId, entryPath, entryName, shareQs, onBack, entries, entryIndex) {
    const entryQs  = shareQs
        ? shareQs + '&path=' + encodeURIComponent(entryPath)
        : '?path='           + encodeURIComponent(entryPath);
    const entryUrl = `/api/files/${fileId}/zip-entry` + entryQs;
    const type     = getFileType(entryName);

    const hasList  = Array.isArray(entries) && entries.length > 1;
    const idx      = hasList ? (entryIndex ?? 0) : 0;
    const prevEntry = hasList && idx > 0                  ? entries[idx - 1] : null;
    const nextEntry = hasList && idx < entries.length - 1 ? entries[idx + 1] : null;

    const navTo = (e) => renderZipEntry(container, fileId, e.path, e.name, shareQs, onBack, entries,
        entries.indexOf(e));

    const backBar = $('<div>').addClass('d-flex align-items-center px-3 py-2 border-bottom flex-shrink-0')
        .css({ background: '#111' })
        .append(
            $('<button>').addClass('btn btn-sm btn-outline-secondary me-2 flex-shrink-0')
                .html('<i class="fas fa-chevron-left me-1"></i>' + _fpT('ui.common.back', 'Back'))
                .on('click', onBack),
            $('<small>').addClass('text-muted text-truncate flex-grow-1').text(entryPath),
            hasList ? $('<span>').addClass('text-muted small ms-3 flex-shrink-0').text((idx + 1) + ' / ' + entries.length) : null,
            hasList ? $('<button>').addClass('btn btn-sm btn-outline-secondary ms-2 flex-shrink-0')
                .html('<i class="fas fa-chevron-left"></i>')
                .prop('disabled', !prevEntry)
                .attr('title', prevEntry ? prevEntry.name : '')
                .on('click', () => prevEntry && navTo(prevEntry)) : null,
            hasList ? $('<button>').addClass('btn btn-sm btn-outline-secondary ms-1 flex-shrink-0')
                .html('<i class="fas fa-chevron-right"></i>')
                .prop('disabled', !nextEntry)
                .attr('title', nextEntry ? nextEntry.name : '')
                .on('click', () => nextEntry && navTo(nextEntry)) : null,
        );

    const previewArea = $('<div>').css({ minHeight: '200px' })
        .html(`<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`);

    container.empty().append(backBar, previewArea);

    if (type === 'image') {
        previewArea.css('background', '#1a1a1a').html(`
            <a href="${entryUrl}" target="_blank" title="${_fpT('ui.files.view_full_size', 'View full size')}">
                <img src="${entryUrl}" class="img-fluid d-block mx-auto"
                     style="max-height:70vh;object-fit:contain;cursor:zoom-in;"
                     alt="${escapeHtml(entryName)}">
            </a>
        `);

    } else if (type === 'video') {
        previewArea.css('background', '#000').html(`
            <video controls class="d-block mx-auto" style="max-width:100%;max-height:70vh;">
                <source src="${entryUrl}">
            </video>
        `);

    } else if (type === 'audio') {
        previewArea.css('background', '').html(`
            <div class="p-5 text-center">
                <i class="fas fa-music fa-3x text-muted mb-3 d-block"></i>
                <p class="text-muted mb-3">${escapeHtml(entryName)}</p>
                <audio controls class="w-100" style="max-width:520px;"><source src="${entryUrl}"></audio>
            </div>
        `);

    } else if (type === 'text') {
        const ext2     = entryName.includes('.') ? entryName.split('.').pop().toLowerCase() : '';
        const isRetro  = ['nfo', 'diz'].includes(ext2);
        const preStyle = isRetro
            ? 'background:#0a0a0a;color:#c8c8c8;font-family:"Courier New",Courier,monospace;'
            : '';
        previewArea.css('background', '');
        fetchZipEntry(entryUrl, 'text')
            .then(text => {
                previewArea.html(`<pre class="m-0 p-3" style="max-height:70vh;overflow:auto;font-size:0.85em;white-space:pre-wrap;word-break:break-all;${preStyle}">${escapeHtml(text)}</pre>`);
            })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'ansi') {
        previewArea.css('background', '#0a0a0a');
        fetchZipEntry(entryUrl, 'text')
            .then(text => {
                const artHtml = renderAnsiBuffer(text, 80, 500);
                previewArea.html(`
                    <div class="ansi-art-container" style="overflow:auto;max-height:70vh;background:#0a0a0a;padding:8px;">
                        <pre class="m-0">${artHtml}</pre>
                    </div>
                `);
            })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'rip') {
        previewArea.css('background', '#0a0a0a');
        fetchZipEntry(entryUrl, 'text')
            .then(html => {
                previewArea.html(`<div style="overflow:auto;max-height:70vh;padding:8px;text-align:center;">${html}</div>`);
            })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'petscii') {
        previewArea.css('background', '#0000aa')
            .html(`<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x" style="color:#55ffff;"></i></div>`);
        fetchZipEntry(entryUrl, 'buffer')
            .then(buf => {
                const uBytes   = new Uint8Array(buf);
                if (uBytes.length < 3) throw new Error('too short');
                const loadAddr = uBytes[0] | (uBytes[1] << 8);
                const data     = uBytes.slice(2);
                let canvasData = null;
                if (loadAddr === 0x0400) {
                    canvasData = data;
                } else {
                    const offset = findScreenRamOffset(data);
                    if (offset >= 0) canvasData = data.slice(offset);
                }
                const draw = () => {
                    if (canvasData) {
                        const cvs = renderPrgToCanvas(canvasData, 40, 25);
                        cvs.style.imageRendering = 'pixelated';
                        cvs.style.width = '640px'; cvs.style.height = '400px'; cvs.style.maxWidth = '100%';
                        previewArea.css('background', '#000').empty().append(
                            $('<div>').css({overflow:'auto', maxHeight:'70vh', background:'#000', textAlign:'center', padding:'8px'}).append(cvs)
                        );
                    } else {
                        previewArea.css('background', '').html(`
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-microchip fa-2x d-block mb-2"></i>
                                <small class="d-block mb-3">${_fpT('ui.files.prg_no_preview', 'Preview unavailable — machine code program')}</small>
                                <a href="${entryUrl}" download="${escapeHtml(entryName)}" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-download me-1"></i>${_fpT('ui.files.download', 'Download')}
                                </a>
                            </div>
                        `);
                    }
                };
                if (document.fonts && document.fonts.load) {
                    document.fonts.load('8px "Pet Me 64"').then(draw, draw);
                } else {
                    draw();
                }
            })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else {
        // Unsupported type — download only
        previewArea.css('background', '').html(`
            <div class="p-5 text-center text-muted">
                <i class="fas fa-file fa-3x mb-3 d-block"></i>
                <p class="mb-3">${escapeHtml(entryName)}</p>
                <a href="${entryUrl}" download="${escapeHtml(entryName)}" class="btn btn-outline-secondary">
                    <i class="fas fa-download me-1"></i>${_fpT('ui.files.download', 'Download')}
                </a>
            </div>
        `);
    }
}
