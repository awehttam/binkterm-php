/**
 * sixel.js — DEC Sixel image decoder and renderer
 *
 * Decodes DEC sixel graphics (ESC P ... q ... ESC \) embedded in text
 * or stored as standalone .six/.sixel files and renders them to <canvas>.
 *
 * Public API:
 *   looksLikeSixel(text)               → boolean
 *   renderSixelToCanvas(sixelData)     → HTMLCanvasElement|null
 *   renderSixelChunks(container, rawText, renderTextFn)
 *   renderSixelFilePreview($container, text)
 */

/**
 * Default VT340-style palette for the first 16 color registers (RGB 0-255).
 * Remaining registers 16-255 default to opaque black until defined by the stream.
 */
const SIXEL_DEFAULT_PALETTE = (function () {
    const vt340 = [
        [  0,   0,   0], //  0 black
        [ 85,  23, 161], //  1 blue
        [161,  23,  23], //  2 red
        [ 23, 161,  23], //  3 green
        [161,  23, 161], //  4 magenta
        [ 23, 161, 161], //  5 cyan
        [161, 161,  23], //  6 yellow
        [161, 161, 161], //  7 gray 50%
        [ 85,  85,  85], //  8 gray 33%
        [ 85,  85, 255], //  9 light blue
        [255,  85,  85], // 10 light red
        [ 85, 255,  85], // 11 light green
        [255,  85, 255], // 12 light magenta
        [ 85, 255, 255], // 13 light cyan
        [255, 255,  85], // 14 light yellow
        [255, 255, 255], // 15 white
    ];
    const pal = [];
    for (let i = 0; i < 256; i++) {
        pal.push(i < vt340.length ? [...vt340[i], 255] : [0, 0, 0, 255]);
    }
    return pal;
})();

/**
 * Convert DEC HLS (H=0-360, L=0-100, S=0-100) to RGB (0-255 each).
 * @param {number} h
 * @param {number} l
 * @param {number} s
 * @returns {number[]} [r, g, b]
 */
function sixelHlsToRgb(h, l, s) {
    l /= 100;
    s /= 100;
    if (s === 0) {
        const v = Math.round(l * 255);
        return [v, v, v];
    }
    const c  = (1 - Math.abs(2 * l - 1)) * s;
    const hp = h / 60;
    const x  = c * (1 - Math.abs(hp % 2 - 1));
    const m  = l - c / 2;
    let r1, g1, b1;
    if      (hp < 1) { r1 = c; g1 = x; b1 = 0; }
    else if (hp < 2) { r1 = x; g1 = c; b1 = 0; }
    else if (hp < 3) { r1 = 0; g1 = c; b1 = x; }
    else if (hp < 4) { r1 = 0; g1 = x; b1 = c; }
    else if (hp < 5) { r1 = x; g1 = 0; b1 = c; }
    else             { r1 = c; g1 = 0; b1 = x; }
    return [
        Math.round((r1 + m) * 255),
        Math.round((g1 + m) * 255),
        Math.round((b1 + m) * 255),
    ];
}

/**
 * Decode a sixel data string into a pixel buffer.
 *
 * @param {string} data  Raw sixel data (the portion after 'q' and before the String Terminator).
 * @returns {{width:number, height:number, imgData:Uint8ClampedArray}|null}
 */
function decodeSixelData(data) {
    const MAX_DIM = 4096;

    // Clone default palette so each decode is independent.
    const palette = SIXEL_DEFAULT_PALETTE.map(c => c.slice());

    let currentReg = 0;
    let x = 0, y = 0;
    let imgWidth = 0, imgHeight = 0;
    let pixels = null; // Uint8ClampedArray allocated on first write or raster hint

    /**
     * Grow the pixel buffer to at least (needW × needH), copying existing data.
     * @param {number} needW
     * @param {number} needH
     */
    function ensureSize(needW, needH) {
        const newW = Math.min(Math.max(imgWidth, needW, 1), MAX_DIM);
        const newH = Math.min(Math.max(imgHeight, needH, 1), MAX_DIM);
        if (newW === imgWidth && newH === imgHeight && pixels) return;
        const newPix = new Uint8ClampedArray(newW * newH * 4); // zeros = transparent
        if (pixels) {
            const copyRows = Math.min(imgHeight, newH);
            for (let row = 0; row < copyRows; row++) {
                newPix.set(
                    pixels.subarray(row * imgWidth * 4, (row + 1) * imgWidth * 4),
                    row * newW * 4
                );
            }
        }
        pixels    = newPix;
        imgWidth  = newW;
        imgHeight = newH;
    }

    /**
     * Paint a single pixel at (px, py) using color register reg.
     * @param {number} px
     * @param {number} py
     * @param {number} reg
     */
    function plotPixel(px, py, reg) {
        if (px < 0 || py < 0 || px >= MAX_DIM || py >= MAX_DIM) return;
        ensureSize(px + 1, py + 1);
        const idx      = (py * imgWidth + px) * 4;
        const [r, g, b, a] = palette[reg & 0xFF];
        pixels[idx]     = r;
        pixels[idx + 1] = g;
        pixels[idx + 2] = b;
        pixels[idx + 3] = a;
    }

    let i = 0;
    const len = data.length;

    /** Read a non-negative integer at position i; advances i. Returns null if no digits. */
    function readInt() {
        let n = 0, hasDigit = false;
        while (i < len && data[i] >= '0' && data[i] <= '9') {
            n = n * 10 + (data.charCodeAt(i) - 48);
            hasDigit = true;
            i++;
        }
        return hasDigit ? n : null;
    }

    /** Skip a semicolon if present. */
    function skipSemi() {
        if (i < len && data[i] === ';') i++;
    }

    while (i < len) {
        const ch   = data[i];
        const code = data.charCodeAt(i);

        if (ch === '"') {
            // Raster attributes: "Pan;Pad;Ph;Pv  — pre-allocate if dimensions provided
            i++;
            /*const pan =*/ readInt(); skipSemi();
            /*const pad =*/ readInt(); skipSemi();
            const ph  = readInt(); skipSemi();
            const pv  = readInt();
            if (ph > 0 && pv > 0) ensureSize(ph, pv);

        } else if (ch === '#') {
            // Color register selection or definition
            i++;
            const reg = readInt();
            if (reg === null) continue;
            if (i < len && data[i] === ';') {
                // Definition: #n;type;p1;p2;p3
                i++; // skip ';'
                const colorType = readInt() || 1; skipSemi();
                const p1 = readInt() || 0;        skipSemi();
                const p2 = readInt() || 0;        skipSemi();
                const p3 = readInt() || 0;
                let r, g, b;
                if (colorType === 2) {
                    // RGB percentages 0-100
                    r = Math.round(p1 * 2.55);
                    g = Math.round(p2 * 2.55);
                    b = Math.round(p3 * 2.55);
                } else {
                    // HLS (type 1)
                    [r, g, b] = sixelHlsToRgb(p1, p2, p3);
                }
                palette[reg & 0xFF] = [r, g, b, 255];
                currentReg = reg & 0xFF;
            } else {
                currentReg = (reg || 0) & 0xFF;
            }

        } else if (ch === '!') {
            // RLE repeat: !count sixelChar
            i++;
            const count = readInt() || 1;
            if (i < len) {
                const sc = data.charCodeAt(i);
                if (sc >= 63 && sc <= 126) {
                    const bits = sc - 63;
                    for (let n = 0; n < count; n++) {
                        if (bits !== 0) {
                            for (let b = 0; b < 6; b++) {
                                if (bits & (1 << b)) plotPixel(x, y + b, currentReg);
                            }
                        }
                        x++;
                    }
                }
                i++;
            }

        } else if (ch === '$') {
            // Graphics carriage return
            x = 0;
            i++;

        } else if (ch === '-') {
            // Graphics new line (next sixel band)
            x = 0;
            y += 6;
            i++;

        } else if (code >= 63 && code <= 126) {
            // Sixel data character — each bit represents one of 6 vertical pixels
            const bits = code - 63;
            if (bits !== 0) {
                for (let b = 0; b < 6; b++) {
                    if (bits & (1 << b)) plotPixel(x, y + b, currentReg);
                }
            }
            x++;
            i++;

        } else {
            i++;
        }
    }

    if (!pixels || imgWidth === 0 || imgHeight === 0) return null;
    return { width: imgWidth, height: imgHeight, imgData: pixels };
}

/**
 * Detect whether a string contains at least one DEC sixel DCS sequence.
 * @param {string} text
 * @returns {boolean}
 */
function looksLikeSixel(text) {
    return /\x1bP[^q]*q/.test(text);
}

/**
 * Split text into alternating text and sixel segments.
 * @param {string} text
 * @returns {Array<{type:'text'|'sixel', content:string}>}
 */
function splitSixelChunks(text) {
    // Matches: ESC P <params> q <data> (ESC \ | C1 ST)
    const SIXEL_RE = /\x1bP[^q]*q([\s\S]*?)(?:\x1b\\|\x9c)/g;
    const chunks = [];
    let lastIndex = 0;
    let match;
    while ((match = SIXEL_RE.exec(text)) !== null) {
        if (match.index > lastIndex) {
            chunks.push({ type: 'text', content: text.slice(lastIndex, match.index) });
        }
        chunks.push({ type: 'sixel', content: match[1] });
        lastIndex = SIXEL_RE.lastIndex;
    }
    if (lastIndex < text.length) {
        chunks.push({ type: 'text', content: text.slice(lastIndex) });
    }
    return chunks;
}

/**
 * Decode sixel data and render to a new <canvas> element.
 * @param {string} sixelData  Raw sixel data (after 'q', before ST).
 * @returns {HTMLCanvasElement|null}
 */
function renderSixelToCanvas(sixelData) {
    const result = decodeSixelData(sixelData);
    if (!result) return null;
    const canvas  = document.createElement('canvas');
    canvas.width  = result.width;
    canvas.height = result.height;
    const ctx     = canvas.getContext('2d');
    const imgData = ctx.createImageData(result.width, result.height);
    imgData.data.set(result.imgData);
    ctx.putImageData(imgData, 0, 0);
    return canvas;
}

/**
 * Render a message body that may contain sixel DCS sequences into a DOM container.
 * Text segments are handed to renderTextFn; sixel segments become inline <canvas> elements.
 *
 * @param {HTMLElement} container   Target DOM element (will be cleared first).
 * @param {string}      rawText     Raw message body text.
 * @param {function}    renderTextFn  Called with (textChunk: string) → HTML string.
 */
function renderSixelChunks(container, rawText, renderTextFn) {
    container.innerHTML = '';
    const chunks = splitSixelChunks(rawText);
    for (const chunk of chunks) {
        if (chunk.type === 'sixel') {
            const canvas = renderSixelToCanvas(chunk.content);
            if (canvas) {
                canvas.style.maxWidth       = '100%';
                canvas.style.imageRendering = 'pixelated';
                canvas.className            = 'sixel-inline-image d-block my-1';
                container.appendChild(canvas);
            }
        } else if (chunk.content.trim()) {
            const html = renderTextFn(chunk.content);
            const tmp  = document.createElement('div');
            tmp.innerHTML = html;
            while (tmp.firstChild) container.appendChild(tmp.firstChild);
        }
    }
}

/**
 * Render a standalone sixel file (from the file area) into a jQuery container.
 * Handles both files that include the full DCS wrapper and raw sixel data.
 *
 * @param {jQuery} $container  Target jQuery element.
 * @param {string} text        File content as a string.
 */
function renderSixelFilePreview($container, text) {
    let sixelData = null;

    // Try to extract sixel data from a DCS sequence first.
    const match = /\x1bP[^q]*q([\s\S]*?)(?:\x1b\\|\x9c)/.exec(text);
    if (match) {
        sixelData = match[1];
    } else {
        // Treat the whole file as raw sixel data (no DCS wrapper).
        sixelData = text;
    }

    const canvas = renderSixelToCanvas(sixelData);
    if (!canvas) {
        $container.html('<div class="alert alert-danger m-3">Failed to decode sixel image.</div>');
        return;
    }

    canvas.style.maxWidth       = '100%';
    canvas.style.imageRendering = 'pixelated';

    $container.css('background', '#000').empty();
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'overflow:auto;max-height:78vh;padding:8px;text-align:center;';
    wrapper.appendChild(canvas);
    $container[0].appendChild(wrapper);
}
