const trackerExts = ['xm', 'it', 's3m', 'mod', 'stm', 'amf', '669', 'mptm'];
const sidExts = ['sid'];
const midiExts = ['mid', 'midi'];

const players = new Map();
let nextPlayerId = 1;

function esc(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function getExt(url, label) {
    const source = (label || url || '').split('?')[0].split('#')[0];
    const m = source.match(/\.([a-z0-9]+)$/i);
    return m ? m[1].toLowerCase() : '';
}

export function isRetroAudioUrl(url, label) {
    const ext = getExt(url, label);
    return trackerExts.includes(ext) || sidExts.includes(ext) || midiExts.includes(ext);
}

function getKind(url, label) {
    const ext = getExt(url, label);
    if (trackerExts.includes(ext)) return 'tracker';
    if (sidExts.includes(ext)) return 'sid';
    if (midiExts.includes(ext)) return 'midi';
    return '';
}

function assignId(container) {
    if (!container.dataset.retroPlayerId) {
        container.dataset.retroPlayerId = String(nextPlayerId++);
    }
    return container.dataset.retroPlayerId;
}

function setLoading(container, label, icon) {
    container.classList.add('bink-retro-audio');
    container.innerHTML = `
        <div class="p-4 text-center">
            <i class="fas ${icon} fa-3x text-muted mb-3 d-block"></i>
            <p class="text-muted mb-1 fw-semibold">${esc(label)}</p>
            <div class="text-center py-3"><i class="fas fa-spinner fa-spin text-muted"></i></div>
        </div>`;
}

function renderError(container, message) {
    container.innerHTML = `<div class="alert alert-danger m-3">${esc(message)}</div>`;
}

function renderControls(container, options) {
    const subtitle = options.subtitle ? `<p class="text-muted small mb-3">${esc(options.subtitle)}</p>` : '<div class="mb-3"></div>';
    const extra = options.extra || '';
    container.innerHTML = `
        <div class="p-4 text-center">
            <i class="fas ${options.icon} fa-3x text-muted mb-3 d-block"></i>
            <p class="text-muted mb-1 fw-semibold" data-retro-title>${esc(options.title)}</p>
            <div data-retro-subtitle>${subtitle}</div>
            ${extra}
            <div class="d-flex justify-content-center align-items-center gap-3 mb-4">
                <button type="button" class="btn btn-primary btn-lg" data-retro-play style="min-width:56px;">
                    <i class="fas fa-play"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary" data-retro-stop>
                    <i class="fas fa-stop"></i>
                </button>
            </div>
            <div class="d-flex align-items-center justify-content-center gap-2">
                <i class="fas fa-volume-low text-muted"></i>
                <input type="range" class="form-range" data-retro-volume style="width:160px;" min="0" max="100" value="${options.volume}">
                <i class="fas fa-volume-high text-muted"></i>
            </div>
        </div>`;
}

function setPlayButton(container, playing) {
    const btn = container.querySelector('[data-retro-play]');
    if (!btn) return;
    btn.innerHTML = playing ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>';
    btn.classList.toggle('btn-primary', !playing);
    btn.classList.toggle('btn-warning', playing);
}

function registerPlayer(container, controller) {
    const id = assignId(container);
    stopRetroAudioPlayer(container);
    players.set(id, controller);
}

export function stopRetroAudioPlayer(container) {
    const id = container && container.dataset ? container.dataset.retroPlayerId : '';
    const controller = id ? players.get(id) : null;
    if (controller && typeof controller.stop === 'function') {
        try { controller.stop(); } catch (e) {}
    }
    if (id) players.delete(id);
}

export function stopRetroAudioWithin(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('[data-retro-player-id]')) {
        stopRetroAudioPlayer(root);
    }
    root.querySelectorAll('[data-retro-player-id]').forEach(stopRetroAudioPlayer);
}

async function renderTracker(container, url, label) {
    setLoading(container, label, 'fa-music');
    const [{ ChiptuneJsPlayer }, response] = await Promise.all([
        import('/vendor/chiptune3/chiptune3.js'),
        fetch(url, { credentials: 'same-origin' }),
    ]);
    if (!response.ok) throw new Error('HTTP ' + response.status);
    const buffer = await response.arrayBuffer();
    const player = new ChiptuneJsPlayer({ repeatCount: -1 });

    await new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('libopenmpt initialization timed out')), 10000);
        player.onInitialized(() => {
            clearTimeout(timer);
            resolve();
        });
        player.onError((err) => {
            clearTimeout(timer);
            reject(new Error((err && err.type) || 'libopenmpt error'));
        });
    });

    let started = false;
    let playing = false;
    player.onMetadata((meta) => {
        const title = meta && typeof meta.title === 'string' && meta.title ? meta.title
            : (meta && typeof meta.name === 'string' && meta.name ? meta.name : label);
        const titleEl = container.querySelector('[data-retro-title]');
        const subtitleEl = container.querySelector('[data-retro-subtitle]');
        if (titleEl) titleEl.textContent = title;
        if (subtitleEl && meta && meta.type) subtitleEl.innerHTML = `<p class="text-muted small mb-3">${esc(meta.type)}</p>`;
    });
    player.onEnded(() => {
        playing = false;
        started = false;
        setPlayButton(container, false);
    });

    function bindTrackerControls(target) {
        const playBtn = target.querySelector('[data-retro-play]');
        const stopBtn = target.querySelector('[data-retro-stop]');
        const volume = target.querySelector('[data-retro-volume]');

        playBtn.addEventListener('click', () => {
            if (!started) {
                player.play(buffer);
                started = true;
                playing = true;
            } else if (playing) {
                player.pause();
                playing = false;
            } else {
                player.unpause();
                playing = true;
            }
            setPlayButton(target, playing);
        });
        stopBtn.addEventListener('click', () => {
            player.stop();
            started = false;
            playing = false;
            setPlayButton(target, false);
        });
        volume.addEventListener('input', () => player.setVol(parseInt(volume.value, 10) / 100));
    }

    renderControls(container, { icon: 'fa-music', title: label, subtitle: '', volume: 70 });
    bindTrackerControls(container);
    player.setVol(0.7);
    registerPlayer(container, { stop: () => player.stop() });
}

function loadClassicScript(src) {
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

function parseSidHeader(data) {
    const dec = new TextDecoder('iso-8859-1');
    const magic = dec.decode(data.slice(0, 4));
    if (magic !== 'PSID' && magic !== 'RSID') {
        return { title: '', author: '', released: '', numSongs: 1, startSong: 1 };
    }
    return {
        numSongs: (data[14] << 8) | data[15],
        startSong: ((data[16] << 8) | data[17]) || 1,
        title: dec.decode(data.slice(22, 54)).replace(/\0.*/, '').trim(),
        author: dec.decode(data.slice(54, 86)).replace(/\0.*/, '').trim(),
        released: dec.decode(data.slice(86, 118)).replace(/\0.*/, '').trim(),
    };
}

function ensureSidPlayerReady() {
    if (window._sidPlayerReady) return Promise.resolve();
    if (window._sidPlayerLoading) return window._sidPlayerLoading;

    window._sidPlayerLoading = loadClassicScript('/vendor/websid/stdlib/scriptprocessor_player.min.js')
        .then(() => {
            window.WASM_SEARCH_PATH = '/vendor/websid/';
            return loadClassicScript('/vendor/websid/backend_websid.js');
        })
        .then(() => {
            return new Promise((resolve, reject) => {
                const backend = new SIDBackendAdapter(null, null, null);
                ScriptNodePlayer.initialize(backend, function () {}, [], true, undefined)
                    .then(() => {
                        window._sidPlayerReady = true;
                        window._sidPlayerLoading = null;
                        resolve();
                    })
                    .catch(reject);
            });
        });

    return window._sidPlayerLoading;
}

async function renderSid(container, url, label) {
    setLoading(container, label, 'fa-microchip');
    if (window._sidPlayerReady) {
        try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
    }

    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error('HTTP ' + response.status);
    const meta = parseSidHeader(new Uint8Array(await response.arrayBuffer()));
    await ensureSidPlayerReady();

    let currentTrack = meta.startSong - 1;
    let playing = false;
    const title = meta.title || label;
    const subtitle = [meta.author, meta.released].filter(Boolean).join(' - ');
    const trackOptions = meta.numSongs > 1
        ? `<div class="d-flex align-items-center justify-content-center gap-2 mb-3">
                <label class="text-muted small mb-0">Track:</label>
                <select class="form-select form-select-sm" data-retro-track style="width:auto;">
                    ${Array.from({ length: meta.numSongs }, (_, i) => `<option value="${i}" ${i === currentTrack ? 'selected' : ''}>${i + 1}</option>`).join('')}
                </select>
           </div>`
        : '';

    renderControls(container, { icon: 'fa-microchip', title: title, subtitle: subtitle, extra: trackOptions, volume: 78 });

    const loadTrack = (track) => ScriptNodePlayer.loadMusicFromURL(url, { track: track, timeout: -1 }, () => {}, () => {});
    const setPlaying = (value) => {
        playing = value;
        setPlayButton(container, playing);
    };

    await loadTrack(currentTrack);
    try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
    setPlaying(false);

    container.querySelector('[data-retro-play]').addEventListener('click', () => {
        const p = ScriptNodePlayer.getInstance();
        if (!p) return;
        if (playing) {
            p.pause();
            setPlaying(false);
        } else {
            p.resume();
            setPlaying(true);
        }
    });
    container.querySelector('[data-retro-stop]').addEventListener('click', () => {
        try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
        setPlaying(false);
    });
    container.querySelector('[data-retro-volume]').addEventListener('input', (e) => {
        try { ScriptNodePlayer.getInstance().setVolume(parseInt(e.target.value, 10) / 100); } catch (err) {}
    });
    const trackSelect = container.querySelector('[data-retro-track]');
    if (trackSelect) {
        trackSelect.addEventListener('change', async () => {
            currentTrack = parseInt(trackSelect.value, 10);
            await loadTrack(currentTrack);
            try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
            setPlaying(false);
        });
    }

    registerPlayer(container, {
        stop: () => {
            try { ScriptNodePlayer.getInstance().pause(); } catch (e) {}
        },
    });
}

function readString(bytes, offset, length) {
    return String.fromCharCode(...bytes.slice(offset, offset + length));
}

function readU16(bytes, offset) {
    return (bytes[offset] << 8) | bytes[offset + 1];
}

function readU32(bytes, offset) {
    return (bytes[offset] << 24) | (bytes[offset + 1] << 16) | (bytes[offset + 2] << 8) | bytes[offset + 3];
}

function readVarLen(bytes, state) {
    let value = 0;
    let b;
    do {
        b = bytes[state.pos++];
        value = (value << 7) | (b & 0x7f);
    } while (b & 0x80);
    return value;
}

function parseMidi(buffer) {
    const bytes = new Uint8Array(buffer);
    if (readString(bytes, 0, 4) !== 'MThd') throw new Error('Invalid MIDI header');
    const headerLen = readU32(bytes, 4);
    const trackCount = readU16(bytes, 10);
    const division = readU16(bytes, 12);
    if (division & 0x8000) throw new Error('SMPTE MIDI timing is not supported');

    let pos = 8 + headerLen;
    const events = [];
    for (let t = 0; t < trackCount; t++) {
        if (readString(bytes, pos, 4) !== 'MTrk') break;
        const len = readU32(bytes, pos + 4);
        const end = pos + 8 + len;
        const state = { pos: pos + 8 };
        let tick = 0;
        let running = 0;

        while (state.pos < end) {
            tick += readVarLen(bytes, state);
            let status = bytes[state.pos++];
            if (status < 0x80) {
                state.pos--;
                status = running;
            } else if (status < 0xf0) {
                running = status;
            }

            if (status === 0xff) {
                const type = bytes[state.pos++];
                const length = readVarLen(bytes, state);
                if (type === 0x51 && length === 3) {
                    events.push({ tick, type: 'tempo', tempo: (bytes[state.pos] << 16) | (bytes[state.pos + 1] << 8) | bytes[state.pos + 2] });
                }
                state.pos += length;
            } else if (status === 0xf0 || status === 0xf7) {
                state.pos += readVarLen(bytes, state);
            } else {
                const command = status & 0xf0;
                const channel = status & 0x0f;
                const a = bytes[state.pos++];
                const needsTwo = command !== 0xc0 && command !== 0xd0;
                const b = needsTwo ? bytes[state.pos++] : 0;
                if (command === 0x90 && b > 0) events.push({ tick, type: 'on', channel, note: a, velocity: b });
                if (command === 0x80 || (command === 0x90 && b === 0)) events.push({ tick, type: 'off', channel, note: a });
            }
        }
        pos = end;
    }

    events.sort((a, b) => a.tick - b.tick || (a.type === 'tempo' ? -1 : 1));

    let tempo = 500000;
    let lastTick = 0;
    let seconds = 0;
    const active = new Map();
    const notes = [];
    for (const event of events) {
        seconds += ((event.tick - lastTick) * tempo) / (division * 1000000);
        lastTick = event.tick;
        if (event.type === 'tempo') {
            tempo = event.tempo;
        } else if (event.type === 'on') {
            const key = event.channel + ':' + event.note;
            if (!active.has(key)) active.set(key, []);
            active.get(key).push({ start: seconds, velocity: event.velocity, note: event.note, channel: event.channel });
        } else if (event.type === 'off') {
            const key = event.channel + ':' + event.note;
            const stack = active.get(key);
            const note = stack && stack.shift();
            if (note) {
                note.duration = Math.max(0.05, seconds - note.start);
                notes.push(note);
            }
        }
    }

    return { notes, duration: seconds };
}

function noteFrequency(note) {
    return 440 * Math.pow(2, (note - 69) / 12);
}

async function renderMidi(container, url, label) {
    setLoading(container, label, 'fa-music');
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error('HTTP ' + response.status);
    const parsed = parseMidi(await response.arrayBuffer());

    let context = null;
    let gain = null;
    let playing = false;

    function stopContext() {
        if (context) {
            context.close();
            context = null;
            gain = null;
        }
        playing = false;
        setPlayButton(container, false);
    }

    function start() {
        stopContext();
        context = new AudioContext();
        gain = context.createGain();
        gain.gain.value = parseInt(container.querySelector('[data-retro-volume]').value, 10) / 100;
        gain.connect(context.destination);

        const startAt = context.currentTime + 0.05;
        parsed.notes.slice(0, 4000).forEach((note) => {
            if (note.channel === 9) return;
            const osc = context.createOscillator();
            const env = context.createGain();
            const t0 = startAt + note.start;
            const t1 = t0 + Math.min(note.duration, 12);
            osc.type = 'sine';
            osc.frequency.value = noteFrequency(note.note);
            env.gain.setValueAtTime(0, t0);
            env.gain.linearRampToValueAtTime((note.velocity / 127) * 0.16, t0 + 0.01);
            env.gain.setValueAtTime((note.velocity / 127) * 0.14, Math.max(t0 + 0.02, t1 - 0.04));
            env.gain.linearRampToValueAtTime(0, t1);
            osc.connect(env).connect(gain);
            osc.start(t0);
            osc.stop(t1 + 0.02);
        });
        playing = true;
        setPlayButton(container, true);
        setTimeout(() => {
            if (context && context.state !== 'closed') stopContext();
        }, Math.max(1000, (parsed.duration + 0.5) * 1000));
    }

    renderControls(container, {
        icon: 'fa-music',
        title: label,
        subtitle: parsed.notes.length ? `${parsed.notes.length} MIDI notes` : 'MIDI sequence',
        volume: 50,
    });

    container.querySelector('[data-retro-play]').addEventListener('click', () => {
        if (!context) {
            start();
        } else if (playing) {
            context.suspend();
            playing = false;
            setPlayButton(container, false);
        } else {
            context.resume();
            playing = true;
            setPlayButton(container, true);
        }
    });
    container.querySelector('[data-retro-stop]').addEventListener('click', stopContext);
    container.querySelector('[data-retro-volume]').addEventListener('input', (e) => {
        if (gain) gain.gain.value = parseInt(e.target.value, 10) / 100;
    });
    registerPlayer(container, { stop: stopContext });
}

export async function renderRetroAudioPlayer(container, options) {
    const url = (options && options.url) || container.dataset.retroAudioUrl || '';
    const label = (options && options.label) || container.dataset.retroAudioLabel || decodeURIComponent(url.split('/').pop() || 'Audio file');
    const kind = (options && options.kind) || getKind(url, label);

    try {
        if (kind === 'tracker') {
            await renderTracker(container, url, label);
        } else if (kind === 'sid') {
            await renderSid(container, url, label);
        } else if (kind === 'midi') {
            await renderMidi(container, url, label);
        } else {
            renderError(container, 'Unsupported audio format');
        }
    } catch (e) {
        console.error('Retro audio player failed:', e);
        renderError(container, 'Failed to load audio file');
    }
}

window.BinkRetroAudio = {
    isRetroAudioUrl,
    render: renderRetroAudioPlayer,
    stop: stopRetroAudioPlayer,
    stopWithin: stopRetroAudioWithin,
};
