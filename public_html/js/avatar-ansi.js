/**
 * BinktermPHP ANSI avatar Web editor.
 * The uploaded BIN is inert [CP437 glyph, attribute] data, never HTML or SGR.
 * Copyright (c) 2026 Cythu contributors. BSD-3-Clause.
 */
(() => {
  'use strict';
  const GLYPHS = [" ","☺","☻","♥","♦","♣","♠","•","◘","○","◙","♂","♀","♪","♫","☼","►","◄","↕","‼","¶","§","▬","↨","↑","↓","→","←","∟","↔","▲","▼"," ","!","\"","#","$","%","&","'","(",")","*","+",",","-",".","/","0","1","2","3","4","5","6","7","8","9",":",";","<","=",">","?","@","A","B","C","D","E","F","G","H","I","J","K","L","M","N","O","P","Q","R","S","T","U","V","W","X","Y","Z","[","\\","]","^","_","`","a","b","c","d","e","f","g","h","i","j","k","l","m","n","o","p","q","r","s","t","u","v","w","x","y","z","{","|","}","~","⌂","Ç","ü","é","â","ä","à","å","ç","ê","ë","è","ï","î","ì","Ä","Å","É","æ","Æ","ô","ö","ò","û","ù","ÿ","Ö","Ü","¢","£","¥","₧","ƒ","á","í","ó","ú","ñ","Ñ","ª","º","¿","⌐","¬","½","¼","¡","«","»","░","▒","▓","│","┤","╡","╢","╖","╕","╣","║","╗","╝","╜","╛","┐","└","┴","┬","├","─","┼","╞","╟","╚","╔","╩","╦","╠","═","╬","╧","╨","╤","╥","╙","╘","╒","╓","╫","╪","┘","┌","█","▄","▌","▐","▀","α","ß","Γ","π","Σ","σ","µ","τ","Φ","Θ","Ω","δ","∞","φ","ε","∩","≡","±","≥","≤","⌠","⌡","÷","≈","°","∙","·","√","ⁿ","²","■"," "];
  const SHAPES = new Map([[0xdb,'full'],[0xdc,'lower'],[0xdf,'upper'],[0xdd,'left'],
    [0xde,'right'],[0xb0,'shade1'],[0xb1,'shade2'],[0xb2,'shade3']]);
  const api = '/api/user/ansi-avatar';

  function ui(key, params, fallback) {
    if (typeof window.t === 'function') {
      return window.t(key, params || {}, fallback);
    }
    return fallback;
  }

  function emptyBytes() {
    const bytes = new Uint8Array(120);
    for (let i = 0; i < 60; i++) {
      bytes[2 * i] = 32;
      bytes[2 * i + 1] = 7;
    }
    return bytes;
  }

  function from64(encoded) {
    if (!/^[A-Za-z0-9+/]{160}$/.test(encoded)) {
      throw new Error(ui('ui.avatar.status.invalid', {}, 'Invalid ANSI avatar data'));
    }
    const decoded = atob(encoded);
    if (decoded.length !== 120) {
      throw new Error(ui('ui.avatar.status.dimensions', {}, 'Invalid ANSI avatar dimensions'));
    }
    return Uint8Array.from(decoded, (x) => x.charCodeAt(0));
  }

  function to64(bytes) {
    if (bytes.length !== 120) {
      throw new Error(ui('ui.avatar.status.dimensions', {}, 'Invalid ANSI avatar dimensions'));
    }
    let raw = '';
    for (let i = 0; i < bytes.length; i++) {
      raw += String.fromCharCode(bytes[i]);
    }
    return btoa(raw);
  }

  function blank(bytes) {
    for (let i = 0; i < 120; i += 2) {
      if (![0, 32, 255].includes(bytes[i]) || ((bytes[i + 1] >> 4) & 7) !== 0) {
        return false;
      }
    }
    return true;
  }

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  }

  async function request(url, method = 'GET', data) {
    const options = { method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (method !== 'GET') {
      const csrf = csrfToken();
      if (!csrf) {
        throw new Error(ui('ui.avatar.status.missing_csrf', {}, 'Missing CSRF token. Reload and sign in again.'));
      }
      options.headers['X-CSRF-Token'] = csrf;
    }
    if (data !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(data);
    }
    const response = await fetch(url, options);
    let payload = null;
    try {
      payload = await response.json();
    } catch {
      throw new Error(ui('ui.avatar.status.unexpected', {}, 'Unexpected server response'));
    }
    if (!response.ok || payload?.success !== true) {
      const fallback = ui('ui.avatar.status.unable', {}, 'Unable to load or update ANSI avatar');
      if (typeof window.getApiErrorMessage === 'function') {
        throw new Error(window.getApiErrorMessage(payload, fallback));
      }
      throw new Error(fallback);
    }
    return payload;
  }

  function styleCell(element, bytes, index, name) {
    const row = Math.floor(index / 10);
    const col = index % 10;
    const char = bytes[index * 2];
    const attr = bytes[index * 2 + 1];
    const fg = attr & 15;
    const bg = (attr >> 4) & 7;
    element.className = `bt-ansi-cell bt-ansi-fg-${fg} bt-ansi-bg-${bg}`;
    const shape = SHAPES.get(char);
    element.replaceChildren();
    if (shape) {
      element.classList.add('bt-ansi-shape', 'bt-ansi-shape-' + shape);
    } else {
      element.textContent = GLYPHS[char] || ' ';
    }
    const title = ui('ui.avatar.aria.cell', {
      row: row + 1,
      column: col + 1,
      byte: char,
      foreground: fg,
      background: bg
    }, `Row ${row + 1}, column ${col + 1}, CP437 ${char}, foreground ${fg}, background ${bg}`);
    element.title = title;
    element.setAttribute('aria-label', title);
    element.dataset.cellIndex = String(index);
    element.dataset.ownerName = name;
  }

  function plate(host, bytes, name, editable, onPaint) {
    host.replaceChildren();
    const frame = document.createElement('span');
    frame.className = 'bt-ansi-avatar';
    frame.setAttribute('role', editable ? 'group' : 'img');
    frame.setAttribute('aria-label', editable
      ? ui('ui.avatar.aria.editor', { name }, `Editable ANSI avatar for ${name}`)
      : ui('ui.avatar.aria.plate', { name }, `ANSI avatar of ${name}`));
    for (let row = 0; row < 6; row++) {
      const line = document.createElement('span');
      line.className = 'bt-ansi-row';
      for (let col = 0; col < 10; col++) {
        const idx = row * 10 + col;
        const element = document.createElement(editable ? 'button' : 'span');
        if (editable) {
          element.type = 'button';
        }
        styleCell(element, bytes, idx, name);
        if (editable) {
          element.addEventListener('pointerdown', (evt) => {
            evt.preventDefault();
            onPaint(idx, null, true);
          });
          element.addEventListener('pointerenter', (evt) => {
            if (evt.buttons === 1) {
              onPaint(idx, null, true);
            }
          });
          element.addEventListener('keydown', (evt) => {
            if (evt.key.startsWith('Arrow')) {
              evt.preventDefault();
              const move = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -10, ArrowDown: 10 }[evt.key];
              const next = Math.max(0, Math.min(59, idx + move));
              frame.querySelector(`[data-cell-index="${next}"]`)?.focus();
              return;
            }
            if (evt.key.length === 1 && evt.key.charCodeAt(0) >= 32 && evt.key.charCodeAt(0) <= 126) {
              evt.preventDefault();
              onPaint(idx, evt.key.charCodeAt(0), false);
            }
          });
        }
        line.append(element);
      }
      frame.append(line);
    }
    host.append(frame);
  }

  function setupEditor(root) {
    let bytes = emptyBytes();
    const plateHost = root.querySelector('[data-ansi-plate]');
    const status = root.querySelector('[data-ansi-status]');
    const glyph = root.querySelector('[data-ansi-glyph]');
    const fg = root.querySelector('[data-ansi-fg]');
    const bg = root.querySelector('[data-ansi-bg]');
    const file = root.querySelector('[data-ansi-file]');
    const name = root.dataset.ownerName || 'Caller';
    if (fg && fg.value === '') {
      fg.value = '15';
    }
    if (bg && bg.value === '') {
      bg.value = '0';
    }

    function report(message, error = false) {
      status.textContent = message;
      status.setAttribute('role', error ? 'alert' : 'status');
    }

    function repaint(focusIndex = null) {
      plate(plateHost, bytes, name, true, paint);
      if (focusIndex !== null) {
        plateHost.querySelector(`[data-cell-index="${focusIndex}"]`)?.focus();
      }
    }

    function paint(index, charOverride = null, fromPointer = false) {
      const chr = charOverride === null ? Number(glyph.value) : charOverride;
      if (!Number.isInteger(chr) || chr < 0 || chr > 255) {
        report(ui('ui.avatar.status.glyph_range', {}, 'CP437 byte must be 0-255'), true);
        return;
      }
      bytes[2 * index] = chr;
      bytes[2 * index + 1] = (Number(bg.value) << 4) | Number(fg.value);
      const existing = plateHost.querySelector(`[data-cell-index="${index}"]`);
      if (existing && fromPointer) {
        styleCell(existing, bytes, index, name);
        return;
      }
      repaint(index);
    }

    root.querySelectorAll('[data-ansi-quick]').forEach((btn) => {
      btn.addEventListener('click', () => {
        glyph.value = btn.dataset.ansiQuick;
      });
    });
    root.querySelector('[data-ansi-import]').addEventListener('click', () => file.click());
    file.addEventListener('change', async () => {
      const chosen = file.files?.[0];
      if (!chosen) {
        return;
      }
      try {
        if (chosen.size !== 120) {
          throw new Error(ui('ui.avatar.status.bin_size', {}, 'A .bin avatar must be exactly 120 bytes'));
        }
        const imported = new Uint8Array(await chosen.arrayBuffer());
        if (blank(imported)) {
          throw new Error(ui('ui.avatar.status.blank', {}, 'An all-blank avatar cannot be saved'));
        }
        bytes = imported;
        repaint();
        report(ui('ui.avatar.status.imported', {}, 'Imported 120-byte BIN. Save to keep it.'));
      } catch (e) {
        report(e.message, true);
      } finally {
        file.value = '';
      }
    });
    root.querySelector('[data-ansi-save]').addEventListener('click', async () => {
      try {
        if (blank(bytes)) {
          throw new Error(ui('ui.avatar.status.paint_first', {}, 'Paint an avatar before saving'));
        }
        const res = await request(api, 'POST', { ansi: to64(bytes) });
        bytes = from64(res.avatar.ansi);
        repaint();
        report(ui('ui.avatar.status.saved', {}, 'ANSI avatar saved.'));
      } catch (e) {
        report(e.message, true);
      }
    });
    root.querySelector('[data-ansi-remove]').addEventListener('click', async () => {
      try {
        await request(api, 'DELETE');
        bytes = emptyBytes();
        repaint();
        report(ui('ui.avatar.status.removed', {}, 'ANSI avatar removed. Your default avatar is restored.'));
      } catch (e) {
        report(e.message, true);
      }
    });
    repaint();
    request(api).then((payload) => {
      if (payload.avatar?.ansi) {
        bytes = from64(payload.avatar.ansi);
        repaint();
        report(ui('ui.avatar.status.loaded', {}, 'Saved ANSI avatar loaded.'));
      } else {
        report(ui('ui.avatar.status.create', {}, 'Create an avatar by painting the grid, or import a 120-byte .bin file.'));
      }
    }).catch((e) => report(e.message, true));
  }

  function init() {
    document.querySelectorAll('[data-ansi-editor]').forEach(setupEditor);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
