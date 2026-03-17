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
    return 'download';
}

/** @param {string} key @param {string} fallback @param {object} [params] */
function _fpT(key, fallback, params) {
    return window.t ? window.t(key, params || {}, fallback) : fallback;
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
    let previewUrl = `/api/files/${fileId}/preview`;
    if (shareParams && shareParams.share_area && shareParams.share_filename) {
        previewUrl += '?share_area=' + encodeURIComponent(shareParams.share_area)
                   + '&share_filename=' + encodeURIComponent(shareParams.share_filename);
    }

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
        fetch(`/api/files/${fileId}/prgs`, {credentials: 'same-origin'})
            .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
            .then(data => {
                if (!data.prgs || !data.prgs.length) throw new Error('empty');
                renderPrgGallery(body, data.prgs, fileId);
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
                const emuUrl  = `/c64emu/?file_id=${encodeURIComponent(fileId)}`;
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
        fetch(`/api/files/${fileId}/prgs`, {credentials: 'same-origin'})
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
                    renderPrgGallery($('#prgGalleryContainer'), data.prgs, fileId);
                } else {
                    renderPrgGallery(body, data.prgs, fileId);
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

    } else if (filename.toLowerCase().endsWith('.zip')) {
        const retroStyle = 'background:#0a0a0a;color:#c8c8c8;font-family:"Courier New",Courier,monospace;';
        body.css('background', '').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        Promise.allSettled([
            fetch(previewUrl, {credentials: 'same-origin'})
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    const ct = r.headers.get('Content-Type') || '';
                    if (!ct.includes('text/')) throw new Error('no-diz');
                    return r.text();
                }),
            fetch(`/api/files/${fileId}/prgs`, {credentials: 'same-origin'})
                .then(r => r.ok ? r.json() : Promise.reject())
        ]).then(([dizResult, prgsResult]) => {
            const diz     = dizResult.status  === 'fulfilled' ? dizResult.value        : null;
            const prgs    = prgsResult.status === 'fulfilled' ? prgsResult.value?.prgs : null;
            const hasPrgs = prgs && prgs.length > 0;

            if (!diz && !hasPrgs) {
                body.css('background', '').html(`
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-file-archive fa-3x mb-3 d-block"></i>
                        <p class="mb-2">${escapeHtml(filename)}</p>
                        <p class="small mb-4">${_fpT('ui.files.no_preview', 'No preview available for this file type')}</p>
                    </div>
                `);
                return;
            }
            if (hasPrgs && !diz) { renderPrgGallery(body, prgs, fileId); return; }
            if (diz && !hasPrgs) {
                body.html(`<pre class="m-0 p-3" style="max-height:65vh;overflow:auto;font-size:0.85em;white-space:pre-wrap;word-break:break-all;${retroStyle}">${escapeHtml(diz)}</pre>`);
                return;
            }
            // Both DIZ and PRGs
            body.html(`
                <pre class="m-0 p-3" style="font-size:0.85em;white-space:pre-wrap;word-break:break-all;border-bottom:1px solid #333;${retroStyle}">${escapeHtml(diz)}</pre>
                <div id="prgGalleryContainer"></div>
            `);
            renderPrgGallery($('#prgGalleryContainer'), prgs, fileId);
        });

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
 */
function renderPrgGallery(container, prgs, fileId) {
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
                const emuUrl = `/c64emu/?file_id=${encodeURIComponent(fileId)}&prg=${encodeURIComponent(prg.name)}`;
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

        const emuUrl = `/c64emu/?file_id=${encodeURIComponent(fileId)}&prg=${encodeURIComponent(prg.name)}`;
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
