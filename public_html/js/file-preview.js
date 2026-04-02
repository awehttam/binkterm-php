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
const previewHtmlExts    = ['htm','html'];
const previewModExts     = ['mod'];
const previewHeuristicTextExts = ['doc','msg'];
const previewTextExts    = ['txt','log','nfo','diz','asc','cfg','ini','conf','lsm','json','xml','bat','sh'];
const previewMarkdownExts = ['md'];
const previewAnsiExts          = ['ans'];
const previewSixelExts         = ['six', 'sixel'];
const previewPetsciiExts       = ['prg'];
const previewPetsciiStreamExts = ['seq'];
const previewD64Exts           = ['d64'];
const previewRipExts           = ['rip'];
const previewPCBoardExts       = ['bbs'];
const previewArchiveExts       = ['zip','rar','r00','7z','tar','gz','tgz','bz2','tbz2','xz','txz','lzh','lha','arc','arj','cab'];
const previewSidExts           = ['sid'];
const previewTorrentExts       = ['torrent'];

function getFileType(filename) {
    const ext = (filename.includes('.') ? filename.split('.').pop() : '').toLowerCase();
    if (previewImageExts.includes(ext))          return 'image';
    if (previewVideoExts.includes(ext))          return 'video';
    if (previewAudioExts.includes(ext))          return 'audio';
    if (previewHtmlExts.includes(ext))           return 'html';
    if (previewModExts.includes(ext))            return 'mod';
    if (previewHeuristicTextExts.includes(ext))  return 'text_probe';
    if (previewTextExts.includes(ext))           return 'text';
    if (previewMarkdownExts.includes(ext))       return 'markdown';
    if (previewAnsiExts.includes(ext))           return 'ansi';
    if (previewSixelExts.includes(ext))          return 'sixel';
    if (previewPetsciiExts.includes(ext))        return 'petscii';
    if (previewPetsciiStreamExts.includes(ext))  return 'petscii_stream';
    if (previewD64Exts.includes(ext))            return 'd64';
    if (previewRipExts.includes(ext))            return 'rip';
    if (previewPCBoardExts.includes(ext))        return 'pcboard';
    if (previewArchiveExts.includes(ext))        return 'archive';
    if (previewSidExts.includes(ext))            return 'sid';
    if (previewTorrentExts.includes(ext))        return 'torrent';
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

function renderHtmlPreview(container, url) {
    container.css('background', '#fff').html(`
        <iframe
            src="${url}"
            sandbox=""
            referrerpolicy="no-referrer"
            style="display:block;width:100%;height:75vh;border:0;background:#fff;"
            title="HTML preview">
        </iframe>
    `);
}


/**
 * Minimal bencoding decoder. Returns JS objects/arrays/numbers/strings.
 * Binary strings that are not valid UTF-8 are returned as Uint8Array.
 *
 * @param {ArrayBuffer} buffer
 * @returns {*}
 */
function bencodeDecode(buffer) {
    const bytes = new Uint8Array(buffer);
    let pos = 0;

    function readAsciiUntil(charCode) {
        let s = '';
        while (pos < bytes.length && bytes[pos] !== charCode) {
            s += String.fromCharCode(bytes[pos++]);
        }
        return s;
    }

    function decode() {
        const ch = bytes[pos];
        if (ch === 105) { // 'i'
            pos++;
            const n = readAsciiUntil(101); // 'e'
            pos++;
            return parseInt(n, 10);
        }
        if (ch === 108) { // 'l'
            pos++;
            const list = [];
            while (bytes[pos] !== 101) list.push(decode()); // 'e'
            pos++;
            return list;
        }
        if (ch === 100) { // 'd'
            pos++;
            const dict = {};
            while (bytes[pos] !== 101) { // 'e'
                const key = decode();
                dict[key] = decode();
            }
            pos++;
            return dict;
        }
        if (ch >= 48 && ch <= 57) { // '0'-'9'
            const lenStr = readAsciiUntil(58); // ':'
            pos++;
            const len = parseInt(lenStr, 10);
            const slice = bytes.slice(pos, pos + len);
            pos += len;
            try {
                return new TextDecoder('utf-8', { fatal: true }).decode(slice);
            } catch (e) {
                return slice; // binary field (e.g. pieces) — return raw bytes
            }
        }
        const ctx = Array.from(bytes.slice(Math.max(0, pos - 16), pos + 16)).join(',');
        throw new Error(`bencodeDecode: unexpected byte ${ch} at position ${pos}, context [-16..+16]: [${ctx}]`);
    }

    return decode();
}

/**
 * Parse a .torrent ArrayBuffer and return structured metadata.
 *
 * @param {ArrayBuffer} buffer
 * @returns {object|null}
 */
function parseTorrentMeta(buffer) {
    try {
        const torrent = bencodeDecode(buffer);
        if (!torrent || typeof torrent !== 'object' || !torrent.info) return null;

        const info = torrent.info;

        const name        = typeof info.name === 'string' ? info.name.trim() : '';
        const comment     = typeof torrent.comment === 'string' ? torrent.comment.trim() : '';
        const createdBy   = typeof torrent['created by'] === 'string' ? torrent['created by'].trim() : '';
        const createdDate = typeof torrent['creation date'] === 'number'
            ? new Date(torrent['creation date'] * 1000).toLocaleString()
            : '';
        const isPrivate   = info.private === 1 || info.private === '1';
        const pieceLength = typeof info['piece length'] === 'number' ? info['piece length'] : 0;

        // Trackers
        const trackers = [];
        if (typeof torrent.announce === 'string') trackers.push(torrent.announce);
        if (Array.isArray(torrent['announce-list'])) {
            torrent['announce-list'].forEach(tier => {
                if (Array.isArray(tier)) {
                    tier.forEach(url => {
                        if (typeof url === 'string' && !trackers.includes(url)) trackers.push(url);
                    });
                }
            });
        }

        // Files
        let files = [];
        let totalSize = 0;
        if (Array.isArray(info.files)) {
            files = info.files.map(f => {
                const path = Array.isArray(f.path) ? f.path.filter(p => typeof p === 'string').join('/') : '';
                const size = typeof f.length === 'number' ? f.length : 0;
                totalSize += size;
                return { path, size };
            }).filter(f => f.path);
        } else if (typeof info.length === 'number') {
            totalSize = info.length;
        }

        const pieceCount  = pieceLength > 0 && totalSize > 0 ? Math.ceil(totalSize / pieceLength) : 0;
        const commentIsUrl = /^https?:\/\//i.test(comment);
        const shortDesc   = name || (!commentIsUrl ? comment : '');
        const longDesc    = files.length ? files.map(f => f.path).join('\n') : '';

        return {
            name, comment, createdBy, createdDate, isPrivate,
            pieceLength, pieceCount, trackers, files, totalSize,
            shortDesc, longDesc,
            isMultiFile: files.length > 0,
        };
    } catch (e) {
        console.error('parseTorrentMeta failed:', e);
        return null;
    }
}

/**
 * Extract the raw bytes of the bencoded `info` dictionary from a .torrent
 * ArrayBuffer. Returns a Uint8Array slice, or null if not found.
 *
 * @param {ArrayBuffer} buffer
 * @returns {Uint8Array|null}
 */
function findInfoBytes(buffer) {
    const bytes = new Uint8Array(buffer);
    let pos = 0;

    function skipValue() {
        const ch = bytes[pos];
        if (ch === 105) { // integer i...e
            pos++;
            while (pos < bytes.length && bytes[pos] !== 101) pos++;
            pos++;
        } else if (ch === 108) { // list l...e
            pos++;
            while (pos < bytes.length && bytes[pos] !== 101) skipValue();
            pos++;
        } else if (ch === 100) { // dict d...e
            pos++;
            while (pos < bytes.length && bytes[pos] !== 101) { skipValue(); skipValue(); }
            pos++;
        } else { // byte string len:data
            let lenStr = '';
            while (pos < bytes.length && bytes[pos] !== 58) lenStr += String.fromCharCode(bytes[pos++]);
            pos++; // ':'
            pos += parseInt(lenStr, 10);
        }
    }

    if (bytes[pos] !== 100) return null; // top level must be a dict
    pos++; // skip 'd'

    while (pos < bytes.length && bytes[pos] !== 101) { // 'e'
        let lenStr = '';
        while (pos < bytes.length && bytes[pos] !== 58) lenStr += String.fromCharCode(bytes[pos++]);
        pos++; // ':'
        const keyLen = parseInt(lenStr, 10);
        const key    = new TextDecoder().decode(bytes.slice(pos, pos + keyLen));
        pos += keyLen;

        if (key === 'info') {
            const start = pos;
            skipValue();
            return bytes.slice(start, pos);
        }
        skipValue();
    }
    return null;
}

/**
 * Compute the magnet link for a .torrent file by SHA-1 hashing the raw
 * `info` dict bytes extracted from the buffer.
 *
 * @param {ArrayBuffer} buffer
 * @param {object}      meta    Return value of parseTorrentMeta()
 * @returns {Promise<string|null>}
 */
async function computeMagnetLink(buffer, meta) {
    const infoBytes = findInfoBytes(buffer);
    if (!infoBytes) return null;

    const hashBuf  = await crypto.subtle.digest('SHA-1', infoBytes);
    const infoHash = Array.from(new Uint8Array(hashBuf))
        .map(b => b.toString(16).padStart(2, '0')).join('');

    let magnet = `magnet:?xt=urn:btih:${infoHash}`;
    if (meta.name) magnet += `&dn=${encodeURIComponent(meta.name)}`;
    meta.trackers.forEach(t => { magnet += `&tr=${encodeURIComponent(t)}`; });

    return magnet;
}

/**
 * Inject a computed magnet link into the [data-torrent-magnet] placeholder
 * inside the given container element.
 *
 * @param {Element} containerEl
 * @param {string|null} magnet
 */
function injectMagnetLink(containerEl, magnet) {
    const el = containerEl.querySelector('[data-torrent-magnet]');
    if (!el) return;

    if (!magnet) {
        el.innerHTML = '<span class="text-muted small">unavailable</span>';
        return;
    }

    // Show the btih hash prominently; full link available via copy button
    const hashMatch = magnet.match(/btih:([0-9a-f]+)/i);
    const hashDisplay = hashMatch
        ? `<code class="small">${escapeHtml(hashMatch[1])}</code>`
        : '';

    el.innerHTML = `
        <button class="btn btn-sm btn-outline-secondary py-0 px-1 me-1"
                title="Copy magnet link"
                data-magnet="${escapeHtml(magnet)}"
                onclick="navigator.clipboard.writeText(this.dataset.magnet)">
            <i class="fas fa-copy fa-sm"></i>
        </button>${hashDisplay}`;
}

/**
 * Build the HTML for a torrent metadata card. Used by both the upload
 * form preview and the file preview modal.
 *
 * @param {object} meta  Return value of parseTorrentMeta()
 * @returns {string}
 */
function buildTorrentPreviewHtml(meta) {
    const rows = [];

    const addRow = (label, value) => {
        rows.push(`<tr><th class="text-nowrap pe-3" style="width:1%">${escapeHtml(label)}</th><td>${value}</td></tr>`);
    };

    if (meta.name)        addRow('Name',      escapeHtml(meta.name));
    if (meta.totalSize)   addRow('Size',       escapeHtml(_fpBytes(meta.totalSize)));
    if (meta.comment)     addRow('Comment',    escapeHtml(meta.comment));
    if (meta.createdBy)   addRow('Created by', escapeHtml(meta.createdBy));
    if (meta.createdDate) addRow('Created',    escapeHtml(meta.createdDate));
    if (meta.pieceLength) {
        const pieceSz = escapeHtml(_fpBytes(meta.pieceLength));
        const pieceInfo = meta.pieceCount ? `${pieceSz} &times; ${meta.pieceCount} pieces` : pieceSz;
        addRow('Piece size', pieceInfo);
    }
    if (meta.isPrivate) {
        addRow('Private', '<span class="badge bg-warning text-dark">Yes &mdash; DHT/PEX disabled</span>');
    }

    // Magnet link row — filled in asynchronously by computeMagnetLink after render
    rows.push(`<tr>
        <th class="text-nowrap pe-3" style="width:1%">Magnet</th>
        <td><span data-torrent-magnet><i class="fas fa-spinner fa-spin fa-sm text-muted"></i></span></td>
    </tr>`);

    let html = `<table class="table table-sm mb-0">${rows.join('')}</table>`;

    if (meta.files.length) {
        const fileRows = meta.files.map(f =>
            `<tr>
                <td class="font-monospace small">${escapeHtml(f.path)}</td>
                <td class="text-end text-nowrap small text-muted">${escapeHtml(_fpBytes(f.size))}</td>
            </tr>`
        ).join('');
        html += `<div class="border-top">
            <div class="px-3 py-2 fw-semibold small">
                ${_fpT('ui.files.torrent_files', 'Files')} (${meta.files.length})
            </div>
            <div style="max-height:220px;overflow-y:auto;">
                <table class="table table-sm mb-0"><tbody>${fileRows}</tbody></table>
            </div>
        </div>`;
    }

    if (meta.trackers.length) {
        const trackerRows = meta.trackers.map(t =>
            `<tr><td class="font-monospace small">${escapeHtml(t)}</td></tr>`
        ).join('');
        html += `<div class="border-top">
            <div class="px-3 py-2 fw-semibold small">
                ${_fpT('ui.files.torrent_trackers', meta.trackers.length > 1 ? 'Trackers' : 'Tracker')} (${meta.trackers.length})
            </div>
            <div style="max-height:120px;overflow-y:auto;">
                <table class="table table-sm mb-0"><tbody>${trackerRows}</tbody></table>
            </div>
        </div>`;
    }

    return html;
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
    if (window._modPlayer) {
        try { window._modPlayer.unload(); } catch (e) {}
        window._modPlayer = null;
    }
    if (window._sidPlayerReady) {
        try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
    }

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

    } else if (type === 'html') {
        renderHtmlPreview(body, previewUrl);

    } else if (type === 'mod') {
        renderModPlayer(body, previewUrl, filename);

    } else if (type === 'sid') {
        renderSidPlayer(body, previewUrl, filename);

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
                body.html(`<pre class="m-0 p-3" style="max-height:75vh;overflow:auto;font-size:0.85em;white-space:pre-wrap;word-break:break-all;text-align:left;${preStyle}">${escapeHtml(text)}</pre>`);
            })
            .catch(() => {
                body.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'markdown') {
        body.css('background', '').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        fetch(previewUrl, {credentials: 'same-origin'})
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(html => {
                body.html(`<div class="p-3 text-start" style="max-height:75vh;overflow:auto;">${html}</div>`);
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

    } else if (type === 'pcboard') {
        body.css('background', '#0a0a0a').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        fetch(previewUrl, {credentials: 'same-origin'})
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(text => {
                const artHtml = renderPCBoardBuffer(text);
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

    } else if (type === 'sixel') {
        body.css('background', '#000').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        fetch(previewUrl, {credentials: 'same-origin'})
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(text => { renderSixelFilePreview(body, text); })
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
        renderRipPreview(body, previewUrl);

    } else if (type === 'torrent') {
        body.css('background', '').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        fetch(previewUrl, { credentials: 'same-origin' })
            .then(r => {
                if (!r.ok) throw new Error('fetch failed');
                return r.arrayBuffer();
            })
            .then(buf => {
                const meta = parseTorrentMeta(buf);
                if (!meta) {
                    body.html(`
                        <div class="p-5 text-center text-muted">
                            <i class="fas fa-magnet fa-3x mb-3 d-block"></i>
                            <p class="mb-2">${escapeHtml(filename)}</p>
                            <p class="small">${_fpT('ui.files.torrent_parse_error', 'Could not parse torrent file')}</p>
                        </div>
                    `);
                    return;
                }
                body.html(`<div class="p-2">${buildTorrentPreviewHtml(meta)}</div>`);
                computeMagnetLink(buf, meta).then(magnet => {
                    injectMagnetLink(body[0], magnet);
                    if (magnet) {
                        const btn = document.getElementById('previewMagnetBtn');
                        if (btn) {
                            btn.dataset.magnet = magnet;
                            btn.classList.remove('d-none');
                        }
                    }
                });
            })
            .catch(() => {
                body.html(`
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-magnet fa-3x mb-3 d-block"></i>
                        <p class="mb-2">${escapeHtml(filename)}</p>
                        <p class="small">${_fpT('ui.files.no_preview', 'No preview available for this file type')}</p>
                    </div>
                `);
            });

    } else if (type === 'archive') {
        body.css('background', '').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        renderArchiveBrowser(body, fileId, shareQs);

    } else if (type === 'text_probe') {
        body.css('background', '').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        fetch(previewUrl, {credentials: 'same-origin'})
            .then(r => {
                const ct = r.headers.get('Content-Type') || '';
                if (!r.ok || !ct.startsWith('text/plain')) {
                    body.html(`
                        <div class="p-5 text-center text-muted">
                            <i class="fas fa-file fa-3x mb-3 d-block"></i>
                            <p class="mb-2">${escapeHtml(filename)}</p>
                            <p class="small mb-4">${_fpT('ui.files.no_preview', 'No preview available for this file type')}</p>
                        </div>
                    `);
                    return null;
                }
                return r.text();
            })
            .then(text => {
                if (text === null) return;
                body.html(`<pre class="m-0 p-3" style="max-height:75vh;overflow:auto;font-size:0.85em;white-space:pre-wrap;word-break:break-all;text-align:left;">${escapeHtml(text)}</pre>`);
            })
            .catch(() => {
                body.html(`
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-file fa-3x mb-3 d-block"></i>
                        <p class="mb-2">${escapeHtml(filename)}</p>
                        <p class="small mb-4">${_fpT('ui.files.no_preview', 'No preview available for this file type')}</p>
                    </div>
                `);
            });

    } else {
        // Unknown extension — first probe the archive-contents endpoint using
        // server-side magic-byte detection (handles FidoNet naming conventions
        // like .l79 for LZH, .a01 for ARJ, etc.).  If the server recognises it
        // as an archive, show the archive browser.  Otherwise fall through to
        // the heuristic text probe.
        body.css('background', '').html(
            `<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`
        );
        const arcContentsUrl = `/api/files/${fileId}/archive-contents` + shareQs;
        fetch(arcContentsUrl, {credentials: 'same-origin'})
            .then(r => {
                if (r.ok) return r.json().then(data => ({ isArchive: true, data }));
                // 415 = not a recognised archive format; anything else = real error
                return { isArchive: false };
            })
            .then(({ isArchive, data }) => {
                if (isArchive) {
                    renderArchiveBrowser(body, fileId, shareQs);
                    return;
                }
                // Not an archive — probe the preview endpoint for heuristic text detection
                return fetch(previewUrl, {credentials: 'same-origin'})
                    .then(r => {
                        const ct = r.headers.get('Content-Type') || '';
                        if (!r.ok || !ct.startsWith('text/plain')) {
                            body.html(`
                                <div class="p-5 text-center text-muted">
                                    <i class="fas fa-file fa-3x mb-3 d-block"></i>
                                    <p class="mb-2">${escapeHtml(filename)}</p>
                                    <p class="small mb-4">${_fpT('ui.files.no_preview', 'No preview available for this file type')}</p>
                                </div>
                            `);
                            return null;
                        }
                        return r.text();
                    })
                    .then(text => {
                        if (text === null) return;
                        body.html(`<pre class="m-0 p-3" style="max-height:75vh;overflow:auto;font-size:0.85em;white-space:pre-wrap;word-break:break-all;text-align:left;">${escapeHtml(text)}</pre>`);
                    });
            })
            .catch(() => {
                body.html(`
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-file fa-3x mb-3 d-block"></i>
                        <p class="mb-2">${escapeHtml(filename)}</p>
                        <p class="small mb-4">${_fpT('ui.files.no_preview', 'No preview available for this file type')}</p>
                    </div>
                `);
            });
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

let _riptermLoaderPromise = null;

function loadRiptermJs() {
    if (_riptermLoaderPromise) return _riptermLoaderPromise;
    if (window.RIPterm && window.BGI) {
        _riptermLoaderPromise = Promise.resolve();
        return _riptermLoaderPromise;
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-ripterm-src="${src}"]`);
            if (existing) {
                if (existing.dataset.loaded === 'true') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = false;
            script.dataset.riptermSrc = src;
            script.addEventListener('load', () => {
                script.dataset.loaded = 'true';
                resolve();
            }, { once: true });
            script.addEventListener('error', reject, { once: true });
            document.head.appendChild(script);
        });
    }

    _riptermLoaderPromise = loadScript('/vendor/riptermjs/BGI.js')
        .then(() => loadScript('/vendor/riptermjs/ripterm.js'));
    return _riptermLoaderPromise;
}

function renderRipPreview(container, ripUrl) {
    const canvasId = `fileRipCanvas_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

    container.css('background', '').html(`
        <div class="text-center py-4 text-muted" data-rip-loading>
            <i class="fas fa-spinner fa-spin fa-2x"></i>
        </div>
        <div class="d-none" data-rip-stage style="overflow:auto;max-height:75vh;padding:8px;text-align:center;background:#0a0a0a;border-radius:6px;">
            <canvas id="${canvasId}" width="640" height="350"
                style="width:100%;max-width:960px;height:auto;image-rendering:pixelated;background:#000;border:1px solid #193247;border-radius:6px;"></canvas>
        </div>
    `);

    fetch(ripUrl, { credentials: 'same-origin' })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(ripText => loadRiptermJs().then(async () => {
            const blobUrl = URL.createObjectURL(new Blob([ripText], { type: 'text/plain' }));
            const ripterm = new window.RIPterm({
                canvasId: canvasId,
                timeInterval: 0,
                refreshInterval: 25,
                fontsPath: '/vendor/riptermjs/fonts',
                iconsPath: '/vendor/riptermjs/icons',
                logQuiet: true
            });

            await ripterm.initFonts();
            ripterm.reset();
            try {
                await ripterm.openURL(blobUrl);
                await ripterm.play();
            } finally {
                URL.revokeObjectURL(blobUrl);
            }

            const loading = container[0]?.querySelector('[data-rip-loading]');
            const stage = container[0]?.querySelector('[data-rip-stage]');
            if (loading) loading.remove();
            if (stage) stage.classList.remove('d-none');
        }))
        .catch((err) => {
            console.error('RIP preview failed:', err);
            container.css('background', '').html(
                `<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`
            );
        });
}

// ---------------------------------------------------------------------------
// MOD tracker player
// ---------------------------------------------------------------------------

/**
 * Render a MOD tracker player UI into a container, loading from the given URL.
 * Unloads any previously active MOD player first.
 *
 * @param {jQuery} container
 * @param {string} url       - URL to fetch the MOD file from
 * @param {string} label     - Display name (filename or entry name)
 */
function renderModPlayer(container, url, label) {
    if (window._modPlayer) {
        try { window._modPlayer.unload(); } catch(e) {}
        window._modPlayer = null;
    }

    container.css('background', '').html(`
        <div class="p-4 text-center" id="modPlayerUI">
            <i class="fas fa-music fa-3x text-muted mb-3 d-block"></i>
            <p class="text-muted mb-1 fw-semibold">${escapeHtml(label)}</p>
            <p class="text-muted small mb-3" id="modSongName">&nbsp;</p>
            <div class="text-center py-3"><i class="fas fa-spinner fa-spin text-muted"></i></div>
        </div>
    `);

    import('/js/mod-player/player.js').then(({ ModPlayer }) => {
        const player = new ModPlayer();
        window._modPlayer = player;
        return player.load(url).then(() => {
            const songName = (player.mod && player.mod.name) ? player.mod.name : '';
            container.find('#modPlayerUI').html(`
                <i class="fas fa-music fa-3x text-muted mb-3 d-block"></i>
                <p class="text-muted mb-1 fw-semibold">${escapeHtml(label)}</p>
                <p class="text-muted small mb-3">${escapeHtml(songName)}</p>
                <div class="d-flex justify-content-center align-items-center gap-3 mb-4">
                    <button id="modPlayBtn" class="btn btn-primary btn-lg" style="min-width:56px;">
                        <i class="fas fa-play"></i>
                    </button>
                    <button id="modStopBtn" class="btn btn-outline-secondary">
                        <i class="fas fa-stop"></i>
                    </button>
                </div>
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <i class="fas fa-volume-low text-muted"></i>
                    <input type="range" id="modVolume" class="form-range" style="width:160px;" min="0" max="100" value="30">
                    <i class="fas fa-volume-high text-muted"></i>
                </div>
            `);

            player.setVolume(0.3);

            container.find('#modPlayBtn').on('click', function() {
                if (player.playing) {
                    player.stop();
                    $(this).html('<i class="fas fa-play"></i>').removeClass('btn-warning').addClass('btn-primary');
                } else {
                    player.play();
                    $(this).html('<i class="fas fa-pause"></i>').removeClass('btn-primary').addClass('btn-warning');
                }
            });

            container.find('#modStopBtn').on('click', function() {
                player.stop();
                container.find('#modPlayBtn').html('<i class="fas fa-play"></i>').removeClass('btn-warning').addClass('btn-primary');
            });

            container.find('#modVolume').on('input', function() {
                player.setVolume(parseInt(this.value, 10) / 100);
            });

            player.watchStop(() => {
                container.find('#modPlayBtn').html('<i class="fas fa-play"></i>').removeClass('btn-warning').addClass('btn-primary');
            });
        });
    }).catch((e) => {
        console.error('MOD player load failed:', e);
        if (e && e.modLoadInfo) {
            console.error('MOD player diagnostics:', e.modLoadInfo);
        }
        container.css('background', '').html(
            `<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load MOD file')}</div>`
        );
    });
}

// ---------------------------------------------------------------------------
// SID (Commodore 64) music player — powered by wothke/websid
// ---------------------------------------------------------------------------

/**
 * Load a classic (non-module) script tag once; resolves immediately if already loaded.
 * @param {string} src
 * @returns {Promise<void>}
 */
function _loadClassicScript(src) {
    return new Promise((resolve, reject) => {
        if (document.querySelector(`script[src="${CSS.escape(src)}"], script[src="${src}"]`)) {
            resolve();
            return;
        }
        const s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = () => reject(new Error('Failed to load script: ' + src));
        document.head.appendChild(s);
    });
}

/**
 * Parse a SID file header.
 * @param {Uint8Array} data
 * @returns {{ title: string, author: string, released: string, numSongs: number, startSong: number, valid: boolean }}
 */
function _parseSidHeader(data) {
    const dec = new TextDecoder('iso-8859-1');
    const magic = dec.decode(data.slice(0, 4));
    if (magic !== 'PSID' && magic !== 'RSID') {
        return { title: '', author: '', released: '', numSongs: 1, startSong: 1, valid: false };
    }
    return {
        valid:     true,
        numSongs:  (data[14] << 8) | data[15],
        startSong: ((data[16] << 8) | data[17]) || 1,
        title:     dec.decode(data.slice(22, 54)).replace(/\0.*/, '').trim(),
        author:    dec.decode(data.slice(54, 86)).replace(/\0.*/, '').trim(),
        released:  dec.decode(data.slice(86, 118)).replace(/\0.*/, '').trim(),
    };
}

/**
 * Load websid scripts and initialise ScriptNodePlayer with the SID backend.
 * Safe to call multiple times — only initialises once.
 * @returns {Promise<void>}
 */
function _ensureSidPlayerReady() {
    if (window._sidPlayerReady) return Promise.resolve();
    if (window._sidPlayerLoading) return window._sidPlayerLoading;

    window._sidPlayerLoading = _loadClassicScript('/vendor/websid/stdlib/scriptprocessor_player.min.js')
        .then(() => {
            window.WASM_SEARCH_PATH = '/vendor/websid/';
            return _loadClassicScript('/vendor/websid/backend_websid.js');
        })
        .then(() => new Promise((resolve, reject) => {
            // ROMs are optional — non-BASIC SID files (the vast majority) work without them.
            // The emulator will log a console warning for the rare BASIC songs that need them.
            const backend = new SIDBackendAdapter(null, null, null);
            ScriptNodePlayer.initialize(backend, function () {}, [], true, undefined)
                .then(() => {
                    window._sidPlayerReady = true;
                    window._sidPlayerLoading = null;
                    resolve();
                })
                .catch(reject);
        }));

    return window._sidPlayerLoading;
}

/**
 * Render a Commodore 64 SID music file player.
 * @param {jQuery} container
 * @param {string} url        - URL to fetch the SID file from
 * @param {string} label      - Display name (filename)
 */
/**
 * Draw one frame of the SID spectrum visualizer onto the given canvas.
 * Reads FFT data when window._sidVizPlaying is true; decays bars to zero otherwise.
 * @param {HTMLCanvasElement} canvas
 */
function _drawSidViz(canvas) {
    const numBars = 48;
    if (!window._sidVizLevels) window._sidVizLevels = new Float32Array(numBars);

    const p = ScriptNodePlayer.getInstance();
    const data = window._sidVizPlaying && p ? p.getFreqByteData() : null;
    const binCount = data ? Math.floor(data.length / 3) : 0;

    for (let i = 0; i < numBars; i++) {
        if (data) {
            const binStart = Math.floor(i * binCount / numBars);
            const binEnd   = Math.max(binStart + 1, Math.floor((i + 1) * binCount / numBars));
            let sum = 0;
            for (let b = binStart; b < binEnd; b++) sum += data[b];
            const target = (sum / (binEnd - binStart)) / 255;
            // Smooth toward the target level
            window._sidVizLevels[i] = window._sidVizLevels[i] * 0.7 + target * 0.3;
        } else {
            // Decay toward zero (falling bars effect)
            window._sidVizLevels[i] *= 0.92;
        }
    }

    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    const slotW = w / numBars;
    const barW  = slotW - 1;

    for (let i = 0; i < numBars; i++) {
        const barH = Math.round(window._sidVizLevels[i] * h);
        if (barH < 1) continue;
        // Gradient: cyan at bottom → green at top (C64-ish palette)
        const grad = ctx.createLinearGradient(0, h, 0, h - barH);
        grad.addColorStop(0, '#00cfcf');
        grad.addColorStop(1, '#00ff41');
        ctx.fillStyle = grad;
        ctx.fillRect(i * slotW, h - barH, barW, barH);
    }
}

/**
 * Start the visualizer animation loop. Runs continuously until _stopSidViz().
 * @param {HTMLCanvasElement} canvas
 */
function _startSidViz(canvas) {
    _stopSidViz();
    const loop = () => {
        _drawSidViz(canvas);
        window._sidVizFrameId = requestAnimationFrame(loop);
    };
    window._sidVizFrameId = requestAnimationFrame(loop);
}

/** Stop the visualizer animation loop. */
function _stopSidViz() {
    if (window._sidVizFrameId) {
        cancelAnimationFrame(window._sidVizFrameId);
        window._sidVizFrameId = null;
    }
    window._sidVizPlaying = false;
}

function renderSidPlayer(container, url, label) {
    _stopSidViz();
    window._sidVizLevels = null;
    if (window._sidPlayerReady) {
        try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
    }

    container.css('background', '').html(`
        <div class="p-4 text-center" id="sidPlayerUI">
            <i class="fas fa-microchip fa-3x text-muted mb-3 d-block"></i>
            <p class="text-muted mb-1 fw-semibold">${escapeHtml(label)}</p>
            <div class="text-center py-3"><i class="fas fa-spinner fa-spin text-muted"></i></div>
        </div>
    `);

    fetch(url)
        .then(r => r.arrayBuffer())
        .then(buf => {
            const meta = _parseSidHeader(new Uint8Array(buf));
            const displayTitle = meta.title || label;

            return _ensureSidPlayerReady().then(() => {
                let currentTrack = meta.startSong - 1; // ScriptNodePlayer uses 0-based track index

                const loadTrack = (track) => {
                    return ScriptNodePlayer.loadMusicFromURL(url, { track: track, timeout: -1 }, () => {}, () => {});
                };

                const setPlayingState = (playing) => {
                    window._sidVizPlaying = playing;
                    const btn = container.find('#sidPlayBtn');
                    if (playing) {
                        btn.html('<i class="fas fa-pause"></i>').removeClass('btn-primary').addClass('btn-warning');
                    } else {
                        btn.html('<i class="fas fa-play"></i>').removeClass('btn-warning').addClass('btn-primary');
                    }
                };

                let subtuneSel = '';
                if (meta.numSongs > 1) {
                    const opts = Array.from({ length: meta.numSongs }, (_, i) =>
                        `<option value="${i}" ${i === currentTrack ? 'selected' : ''}>${i + 1}</option>`
                    ).join('');
                    subtuneSel = `
                        <div class="d-flex align-items-center justify-content-center gap-2 mb-3">
                            <label class="text-muted small mb-0" for="sidSubtune">${_fpT('ui.files.sid_track', 'Track')}:</label>
                            <select id="sidSubtune" class="form-select form-select-sm" style="width:auto;">${opts}</select>
                        </div>`;
                }

                container.find('#sidPlayerUI').html(`
                    <i class="fas fa-microchip fa-3x text-muted mb-3 d-block"></i>
                    <p class="text-muted mb-1 fw-semibold">${escapeHtml(displayTitle)}</p>
                    ${meta.author   ? `<p class="text-muted small mb-0">${escapeHtml(meta.author)}</p>` : ''}
                    ${meta.released ? `<p class="text-muted small mb-3">${escapeHtml(meta.released)}</p>` : '<div class="mb-3"></div>'}
                    ${subtuneSel}
                    <canvas id="sidVizCanvas" height="60" style="width:100%;max-width:320px;display:block;margin:0 auto 16px;border-radius:4px;background:#111;"></canvas>
                    <div class="d-flex justify-content-center align-items-center gap-3 mb-4">
                        <button id="sidPlayBtn" class="btn btn-primary btn-lg" style="min-width:56px;">
                            <i class="fas fa-play"></i>
                        </button>
                        <button id="sidStopBtn" class="btn btn-outline-secondary">
                            <i class="fas fa-stop"></i>
                        </button>
                    </div>
                    <div class="d-flex align-items-center justify-content-center gap-2">
                        <i class="fas fa-volume-low text-muted"></i>
                        <input type="range" id="sidVolume" class="form-range" style="width:160px;" min="0" max="255" value="200">
                        <i class="fas fa-volume-high text-muted"></i>
                    </div>
                `);

                // Sync canvas pixel width to its rendered CSS width for crisp drawing
                const vizCanvas = container.find('#sidVizCanvas')[0];
                if (vizCanvas) vizCanvas.width = vizCanvas.offsetWidth || 320;

                // Load track, then immediately pause — user must press Play
                loadTrack(currentTrack).then(() => {
                    try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
                    setPlayingState(false);
                    const canvas = container.find('#sidVizCanvas')[0];
                    if (canvas) _startSidViz(canvas);
                });

                container.find('#sidPlayBtn').on('click', function () {
                    const p = ScriptNodePlayer.getInstance();
                    if (!p) return;
                    if ($(this).hasClass('btn-warning')) {
                        p.pause();
                        setPlayingState(false);
                    } else {
                        p.resume();
                        setPlayingState(true);
                    }
                });

                container.find('#sidStopBtn').on('click', function () {
                    try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
                    setPlayingState(false);
                });

                container.find('#sidVolume').on('input', function () {
                    try { ScriptNodePlayer.getInstance().setVolume(parseInt(this.value, 10) / 255); } catch (e) {}
                });

                if (meta.numSongs > 1) {
                    container.find('#sidSubtune').on('change', function () {
                        currentTrack = parseInt(this.value, 10);
                        loadTrack(currentTrack).then(() => {
                            try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
                            window._sidVizLevels = null;
                            setPlayingState(false);
                        });
                    });
                }
            });
        })
        .catch(e => {
            console.error('SID player load failed:', e);
            container.css('background', '').html(
                `<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load SID file')}</div>`
            );
        });
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
        case 'html':           return 'fa-code';
        case 'mod':            return 'fa-music';
        case 'sid':            return 'fa-microchip';
        case 'text_probe':     return 'fa-file-lines';
        case 'text':           return 'fa-file-lines';
        case 'ansi':           return 'fa-terminal';
        case 'sixel':          return 'fa-image';
        case 'rip':            return 'fa-paint-brush';
        case 'petscii':        return 'fa-gamepad';
        case 'petscii_stream': return 'fa-gamepad';
        case 'd64':            return 'fa-floppy-disk';
        default:               return 'fa-file';
    }
}

/**
 * Render a browsable listing of an archive file's contents into a container.
 * Detects archive type server-side by magic bytes.
 * Clicking an entry opens it via renderArchiveEntry().
 *
 * @param {jQuery}  container
 * @param {number}  fileId
 * @param {string}  shareQs  - e.g. '' or '?share_area=X&share_filename=Y'
 */
function renderArchiveBrowser(container, fileId, shareQs) {
    const contentsUrl = `/api/files/${fileId}/archive-contents` + shareQs;

    fetch(contentsUrl, {credentials: 'same-origin'})
        .then(r => {
            if (r.status === 413) {
                // Too large for 7z listing — read JSON for details but fall back gracefully
                return r.json().catch(() => ({ error: 'file_too_large' }));
            }
            return r.ok ? r.json() : Promise.reject('HTTP ' + r.status);
        })
        .then(data => {
            if (data.error === 'tool_unavailable') {
                renderArchiveToolUnavailableNotice(container, fileId, data.label || '', shareQs);
                return;
            }

            if (data.error === 'file_too_large') {
                const sizeStr = _fpBytes(data.max_size || 0);
                const label   = data.label || '';
                container.css('background', '').html(`
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-file-zipper fa-3x mb-3 d-block"></i>
                        <p class="mb-3">${_fpT('ui.files.archive_too_large', 'This archive is too large to list ({size}). Only ZIP archives can be browsed above this limit.', {size: sizeStr})}</p>
                        ${label ? `<p class="small mb-3"><span class="badge bg-secondary">${_fpEsc(label)}</span></p>` : ''}
                        <a href="/api/files/${fileId}/download" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-download me-1"></i>${_fpT('ui.files.download_archive', 'Download archive')}
                        </a>
                    </div>
                `);
                return;
            }

            const entries  = data.entries || [];
            const arcLabel = data.label   || '';

            if (entries.length === 0) {
                container.css('background', '').html(`
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-file-zipper fa-3x mb-3 d-block"></i>
                        <p>${_fpT('ui.files.archive_empty', 'Archive is empty')}</p>
                    </div>
                `);
                return;
            }

            // comp_method is only reported for ZIP; for other formats assume supported.
            const SUPPORTED_COMP = [0, 8, 12, 14, 20];

            let rows = '';
            entries.forEach(function(entry) {
                const entryType  = getFileType(entry.name);
                const icon       = zipEntryIcon(entryType);
                const legacyComp = entry.comp_method !== undefined
                    && !SUPPORTED_COMP.includes(entry.comp_method);
                const entryQs   = shareQs
                    ? shareQs + '&path=' + encodeURIComponent(entry.path)
                    : '?path='           + encodeURIComponent(entry.path);
                const entryUrl  = `/api/files/${fileId}/archive-entry` + entryQs;

                const dlBtn = `<a href="${entryUrl}" class="btn btn-sm btn-outline-secondary ms-2 flex-shrink-0" download="${escapeHtml(entry.name)}" onclick="event.stopPropagation()"><i class="fas fa-download"></i></a>`;

                const legacyBadge = legacyComp
                    ? `<span class="badge bg-secondary ms-2 flex-shrink-0" title="${_fpT('ui.files.zip_legacy_badge', 'Legacy compression')}" style="font-size:0.65em;">legacy</span>`
                    : '';

                rows += `
                    <div class="d-flex align-items-center px-3 py-2 border-bottom zip-entry-row"
                         style="cursor:pointer; min-width:0;"
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

            const labelBadge = arcLabel
                ? `<div class="px-3 py-2 border-bottom d-flex align-items-center" style="background:#111;">
                       <i class="fas fa-file-zipper text-muted me-2"></i>
                       <span class="badge bg-secondary">${escapeHtml(arcLabel)}</span>
                   </div>`
                : '';

            container.css('background', '').html(`
                <div>
                    ${labelBadge}
                    ${rows}
                    ${truncNote}
                </div>
            `);

            container.find('.zip-entry-row').on('click', function() {
                const path = $(this).data('path');
                const name = $(this).data('name');
                const idx  = entries.findIndex(e => e.path === path);
                renderArchiveEntry(container, fileId, path, name, shareQs,
                    () => renderArchiveBrowser(container, fileId, shareQs),
                    entries, idx);
            });
        })
        .catch(function() {
            container.css('background', '').html(
                `<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load archive contents')}</div>`
            );
        });
}

/**
 * Fetch an archive entry, returning a Promise that resolves to text or ArrayBuffer.
 * Rejects with {legacy:true} on 415 (unsupported compression method).
 * Rejects with {toolUnavailable:true} on 503 (7z not installed).
 *
 * @param {string} url
 * @param {'text'|'buffer'} responseType
 * @returns {Promise}
 */
function fetchArchiveEntry(url, responseType) {
    return fetch(url, {credentials: 'same-origin'})
        .then(r => {
            if (r.status === 415) {
                return r.json().then(err => Promise.reject({ legacy: true, compMethod: err.comp_method }));
            }
            if (r.status === 503) {
                return Promise.reject({ toolUnavailable: true });
            }
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return responseType === 'buffer' ? r.arrayBuffer() : r.text();
        });
}

/**
 * Render a "legacy compression" notice into a container with a full-archive download link.
 */
function renderLegacyCompressionNotice(container, fileId, entryName, shareQs) {
    const dlUrl = `/api/files/${fileId}/download` + (shareQs || '');
    container.css('background', '').html(`
        <div class="p-5 text-center text-muted">
            <i class="fas fa-archive fa-3x mb-3 d-block"></i>
            <p class="mb-1"><strong>${escapeHtml(entryName)}</strong></p>
            <p class="small mb-3">${_fpT('ui.files.zip_legacy_compression', 'This file uses a legacy compression format that cannot be previewed.')}</p>
            <a href="${dlUrl}" download class="btn btn-outline-secondary">
                <i class="fas fa-download me-1"></i>${_fpT('ui.files.download_archive', 'Download archive')}
            </a>
        </div>
    `);
}

/**
 * Render a "tool unavailable" notice when 7z is not installed on the server.
 */
function renderArchiveToolUnavailableNotice(container, fileId, arcLabel, shareQs) {
    const dlUrl = `/api/files/${fileId}/download` + (shareQs || '');
    container.css('background', '').html(`
        <div class="p-5 text-center text-muted">
            <i class="fas fa-tools fa-3x mb-3 d-block"></i>
            ${arcLabel ? `<p class="mb-1"><span class="badge bg-secondary">${escapeHtml(arcLabel)}</span></p>` : ''}
            <p class="small mb-3">${_fpT('ui.files.archive_tool_unavailable', 'Archive tool (7z) is not available on this server.')}</p>
            <a href="${dlUrl}" download class="btn btn-outline-secondary">
                <i class="fas fa-download me-1"></i>${_fpT('ui.files.download_archive', 'Download archive')}
            </a>
        </div>
    `);
}

/**
 * Preview a single entry from inside an archive file.
 *
 * @param {jQuery}   container
 * @param {number}   fileId
 * @param {string}   entryPath    - full path within the archive
 * @param {string}   entryName    - basename
 * @param {string}   shareQs
 * @param {function} onBack       - called when the back button is clicked
 * @param {Array}    [entries]    - entry list for prev/next nav
 * @param {number}   [entryIndex] - index of current entry within entries
 */
function renderArchiveEntry(container, fileId, entryPath, entryName, shareQs, onBack, entries, entryIndex) {
    const entryQs  = shareQs
        ? shareQs + '&path=' + encodeURIComponent(entryPath)
        : '?path='           + encodeURIComponent(entryPath);
    const entryUrl = `/api/files/${fileId}/archive-entry` + entryQs;
    const type     = getFileType(entryName);

    const hasList  = Array.isArray(entries) && entries.length > 1;
    const idx      = hasList ? (entryIndex ?? 0) : 0;
    const prevEntry = hasList && idx > 0                  ? entries[idx - 1] : null;
    const nextEntry = hasList && idx < entries.length - 1 ? entries[idx + 1] : null;

    const navTo = (e) => renderArchiveEntry(container, fileId, e.path, e.name, shareQs, onBack, entries,
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

    // Unload any active MOD/SID player before switching entries
    if (window._modPlayer) {
        try { window._modPlayer.unload(); } catch(e) {}
        window._modPlayer = null;
    }
    if (window._sidPlayerReady) {
        try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
    }

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

    } else if (type === 'html') {
        renderHtmlPreview(previewArea, entryUrl);

    } else if (type === 'mod') {
        renderModPlayer(previewArea, entryUrl, entryName);

    } else if (type === 'sid') {
        renderSidPlayer(previewArea, entryUrl, entryName);

    } else if (type === 'text') {
        const ext2     = entryName.includes('.') ? entryName.split('.').pop().toLowerCase() : '';
        const isRetro  = ['nfo', 'diz'].includes(ext2);
        const preStyle = isRetro
            ? 'background:#0a0a0a;color:#c8c8c8;font-family:"Courier New",Courier,monospace;'
            : '';
        previewArea.css('background', '');
        fetchArchiveEntry(entryUrl, 'text')
            .then(text => {
                previewArea.html(`<pre class="m-0 p-3" style="max-height:70vh;overflow:auto;font-size:0.85em;white-space:pre-wrap;word-break:break-all;text-align:left;${preStyle}">${escapeHtml(text)}</pre>`);
            })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else if (err && err.toolUnavailable) renderArchiveToolUnavailableNotice(previewArea, fileId, '', shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'markdown') {
        previewArea.css('background', '');
        fetchArchiveEntry(entryUrl, 'text')
            .then(html => {
                previewArea.html(`<div class="p-3 text-start" style="max-height:70vh;overflow:auto;">${html}</div>`);
            })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else if (err && err.toolUnavailable) renderArchiveToolUnavailableNotice(previewArea, fileId, '', shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'ansi') {
        previewArea.css('background', '#0a0a0a');
        fetchArchiveEntry(entryUrl, 'text')
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
                else if (err && err.toolUnavailable) renderArchiveToolUnavailableNotice(previewArea, fileId, '', shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'pcboard') {
        previewArea.css('background', '#0a0a0a');
        fetchArchiveEntry(entryUrl, 'text')
            .then(text => {
                const artHtml = renderPCBoardBuffer(text);
                previewArea.html(`
                    <div class="ansi-art-container" style="overflow:auto;max-height:70vh;background:#0a0a0a;padding:8px;">
                        <pre class="m-0">${artHtml}</pre>
                    </div>
                `);
            })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else if (err && err.toolUnavailable) renderArchiveToolUnavailableNotice(previewArea, fileId, '', shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'sixel') {
        previewArea.css('background', '#000');
        fetchArchiveEntry(entryUrl, 'text')
            .then(text => { renderSixelFilePreview(previewArea, text); })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else if (err && err.toolUnavailable) renderArchiveToolUnavailableNotice(previewArea, fileId, '', shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'rip') {
        renderRipPreview(previewArea, entryUrl, entryName);

    } else if (type === 'petscii') {
        previewArea.css('background', '#0000aa')
            .html(`<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x" style="color:#55ffff;"></i></div>`);
        fetchArchiveEntry(entryUrl, 'buffer')
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
                else if (err && err.toolUnavailable) renderArchiveToolUnavailableNotice(previewArea, fileId, '', shareQs);
                else previewArea.html(`<div class="alert alert-danger m-3">${_fpT('ui.files.preview_failed', 'Failed to load preview')}</div>`);
            });

    } else if (type === 'text_probe') {
        previewArea.css('background', '');
        const showDownload = () => previewArea.html(`
            <div class="p-5 text-center text-muted">
                <i class="fas fa-file fa-3x mb-3 d-block"></i>
                <p class="mb-3">${escapeHtml(entryName)}</p>
                <p class="small mb-3">${_fpT('ui.files.no_preview', 'No preview available for this file type')}</p>
                <a href="${entryUrl}" download="${escapeHtml(entryName)}" class="btn btn-outline-secondary">
                    <i class="fas fa-download me-1"></i>${_fpT('ui.files.download', 'Download')}
                </a>
            </div>
        `);
        fetch(entryUrl, {credentials: 'same-origin'})
            .then(r => {
                if (r.status === 415) {
                    return r.json().then(err => Promise.reject({ legacy: true, compMethod: err.comp_method }));
                }
                if (r.status === 503) return Promise.reject({ toolUnavailable: true });
                const ct = r.headers.get('Content-Type') || '';
                if (!r.ok || !ct.startsWith('text/plain')) {
                    showDownload();
                    return null;
                }
                return r.text();
            })
            .then(text => {
                if (text === null) return;
                previewArea.html(`<pre class="m-0 p-3" style="max-height:70vh;overflow:auto;font-size:0.85em;white-space:pre-wrap;word-break:break-all;text-align:left;">${escapeHtml(text)}</pre>`);
            })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else if (err && err.toolUnavailable) renderArchiveToolUnavailableNotice(previewArea, fileId, '', shareQs);
                else showDownload();
            });

    } else {
        // Unknown extension — probe the entry URL and check Content-Type.
        // If the server heuristically identifies it as text/plain, render it;
        // otherwise fall back to a download button.
        previewArea.css('background', '');
        const showDownload = () => previewArea.html(`
            <div class="p-5 text-center text-muted">
                <i class="fas fa-file fa-3x mb-3 d-block"></i>
                <p class="mb-3">${escapeHtml(entryName)}</p>
                <p class="small mb-3">${_fpT('ui.files.no_preview', 'No preview available for this file type')}</p>
                <a href="${entryUrl}" download="${escapeHtml(entryName)}" class="btn btn-outline-secondary">
                    <i class="fas fa-download me-1"></i>${_fpT('ui.files.download', 'Download')}
                </a>
            </div>
        `);
        fetch(entryUrl, {credentials: 'same-origin'})
            .then(r => {
                if (r.status === 415) {
                    return r.json().then(err => Promise.reject({ legacy: true, compMethod: err.comp_method }));
                }
                if (r.status === 503) return Promise.reject({ toolUnavailable: true });
                const ct = r.headers.get('Content-Type') || '';
                if (!r.ok || !ct.startsWith('text/plain')) {
                    showDownload();
                    return null;
                }
                return r.text();
            })
            .then(text => {
                if (text === null) return;
                previewArea.html(`<pre class="m-0 p-3" style="max-height:70vh;overflow:auto;font-size:0.85em;white-space:pre-wrap;word-break:break-all;text-align:left;">${escapeHtml(text)}</pre>`);
            })
            .catch(err => {
                if (err && err.legacy) renderLegacyCompressionNotice(previewArea, fileId, entryName, shareQs);
                else if (err && err.toolUnavailable) renderArchiveToolUnavailableNotice(previewArea, fileId, '', shareQs);
                else showDownload();
            });
    }
}

// ---------------------------------------------------------------------------
// Shared file info + preview modal
// ---------------------------------------------------------------------------

/**
 * Escape a string for safe HTML insertion.
 * @param {string} str
 * @returns {string}
 */
function _fpEsc(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Open a self-contained file info + preview modal for any file.
 * Creates the modal DOM lazily on first call — no template changes required.
 *
 * @param {number} fileId
 * @param {Object} [shareParams]  Optional {share_area, share_filename} for shared file access
 */
function openFileInfoModal(fileId, shareParams) {
    const MODAL_ID = 'fpSharedFileModal';

    // Create modal DOM once
    if (!document.getElementById(MODAL_ID)) {
        const el = document.createElement('div');
        el.innerHTML = `
<div class="modal fade" id="${MODAL_ID}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content" style="max-height:90vh;">
      <div class="modal-header py-2">
        <h5 class="modal-title text-truncate" id="fpSharedModalTitle" style="max-width:70%;"></h5>
        <div class="ms-auto d-flex align-items-center gap-2">
          <a href="#" class="btn btn-sm btn-outline-primary d-none" id="fpSharedDownloadBtn" target="_blank">
            <i class="fas fa-download me-1"></i>${_fpT('ui.files.download', 'Download')}
          </a>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body p-0 text-center" id="fpSharedPreviewBody"
           style="min-height:200px;max-height:60vh;flex:0 1 auto;background:#1a1a1a;overflow:auto;"></div>
      <div class="modal-footer d-block p-3" id="fpSharedMetaBody" style="max-height:28vh;overflow-y:auto;"></div>
    </div>
  </div>
</div>`;
        document.body.appendChild(el.firstElementChild);

        // Clean up preview content when modal closes
        document.getElementById(MODAL_ID).addEventListener('hidden.bs.modal', function() {
            const previewBody = document.getElementById('fpSharedPreviewBody');
            // Stop any playing media
            previewBody.querySelectorAll('video, audio').forEach(function(mediaEl) {
                mediaEl.pause();
                mediaEl.src = '';
            });
            previewBody.innerHTML = '';
            document.getElementById('fpSharedMetaBody').innerHTML = '';
            document.getElementById('fpSharedModalTitle').textContent = '';
            const dlBtn = document.getElementById('fpSharedDownloadBtn');
            dlBtn.href = '#';
            dlBtn.classList.add('d-none');
        });
    }

    const titleEl   = document.getElementById('fpSharedModalTitle');
    const previewEl = document.getElementById('fpSharedPreviewBody');
    const metaEl    = document.getElementById('fpSharedMetaBody');
    const dlBtn     = document.getElementById('fpSharedDownloadBtn');

    // Reset to loading state
    titleEl.textContent = _fpT('ui.common.loading', 'Loading\u2026');
    previewEl.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    metaEl.innerHTML    = '';
    dlBtn.classList.add('d-none');

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById(MODAL_ID));
    modal.show();

    // Fetch file metadata
    const metaUrl = shareParams
        ? '/api/files/' + fileId + '?share_area=' + encodeURIComponent(shareParams.share_area || '') +
          '&share_filename=' + encodeURIComponent(shareParams.share_filename || '')
        : '/api/files/' + fileId;

    fetch(metaUrl, {credentials: 'same-origin'})
        .then(function(r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function(data) {
            const f = data.file || data;
            const filename = f.filename || '';

            titleEl.textContent = filename;

            // Download button
            const dlUrl = shareParams
                ? '/api/files/' + fileId + '/download?share_area=' + encodeURIComponent(shareParams.share_area || '') +
                  '&share_filename=' + encodeURIComponent(shareParams.share_filename || '')
                : '/api/files/' + fileId + '/download';
            dlBtn.href = dlUrl;
            dlBtn.setAttribute('download', filename);
            dlBtn.classList.remove('d-none');

            // Metadata footer
            let meta = '<dl class="row mb-0 small">';
            if (f.file_area_tag || f.area_tag) {
                const tag = f.file_area_tag || f.area_tag || '';
                meta += '<dt class="col-sm-3">' + _fpT('ui.files.area', 'Area') + '</dt>' +
                        '<dd class="col-sm-9"><span class="badge bg-secondary">' + _fpEsc(tag) + '</span></dd>';
            }
            if (f.filesize) {
                meta += '<dt class="col-sm-3">' + _fpT('ui.files.size', 'Size') + '</dt>' +
                        '<dd class="col-sm-9">' + _fpBytes(f.filesize) + '</dd>';
            }
            if (f.created_at) {
                const d = typeof window.parseAppDate === 'function'
                    ? window.parseAppDate(f.created_at)
                    : new Date(String(f.created_at).replace(' ', 'T'));
                meta += '<dt class="col-sm-3">' + _fpT('ui.files.uploaded', 'Uploaded') + '</dt>' +
                        '<dd class="col-sm-9">' + _fpEsc(d ? d.toLocaleString() : String(f.created_at)) + '</dd>';
            }
            if (f.uploaded_from_address) {
                meta += '<dt class="col-sm-3">' + _fpT('ui.files.from', 'From') + '</dt>' +
                        '<dd class="col-sm-9">' + _fpEsc(f.uploaded_from_address) + '</dd>';
            }
            meta += '</dl>';
            if (f.short_description) {
                meta += '<p class="mb-1 mt-2 small">' + _fpEsc(f.short_description) + '</p>';
            }
            if (f.long_description) {
                meta += '<pre class="mb-0 mt-2" style="white-space:pre-wrap;font-size:0.8rem;">' + _fpEsc(f.long_description) + '</pre>';
            }
            metaEl.innerHTML = meta;

            // Render preview
            renderPreviewContent(fileId, filename, $(previewEl), shareParams);
        })
        .catch(function() {
            titleEl.textContent = _fpT('errors.files.not_found', 'File not found');
            previewEl.innerHTML = '<div class="alert alert-danger m-3">' +
                _fpT('errors.files.not_found', 'File not found or access denied.') + '</div>';
        });
}
