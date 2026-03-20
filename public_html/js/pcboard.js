/**
 * PCBoard BBS display file decoder.
 *
 * Handles the .BBS display format used by PCBoard and compatible BBS software.
 * Color codes are written as @XY@ where XY is a two-digit hex CGA attribute
 * byte (high nibble = background 0-7, low nibble = foreground 0-F).
 * Control macros (@CLS@, @NOSTOP@, @NOPAUSE@, etc.) are also handled.
 * Text body is CP437-encoded.
 *
 * Public API (attached to window):
 *   renderPCBoardBuffer(input)   — string | Uint8Array → HTML string
 *   hasPCBoardCodes(text)        — string → boolean (auto-detection)
 *   pcbCp437ToString(bytes)      — Uint8Array → string (CP437 → Unicode)
 */

// ─── CP437 → Unicode map ──────────────────────────────────────────────────────
/* eslint-disable */
const _PCB_CP437 = [
    // 0x00-0x0F  (control codes have visible CP437 glyphs)
    '\u0000','\u263A','\u263B','\u2665','\u2666','\u2663','\u2660','\u2022',
    '\u25D8','\u25CB','\u25D9','\u2642','\u2640','\u266A','\u266B','\u263C',
    // 0x10-0x1F
    '\u25BA','\u25C4','\u2195','\u203C','\u00B6','\u00A7','\u25AC','\u21A8',
    '\u2191','\u2193','\u2192','\u2190','\u221F','\u2194','\u25B2','\u25BC',
    // 0x20-0x7E  (standard printable ASCII)
    ' ','!','"','#','$','%','&',"'",'(',')','*','+',',','-','.','/','0','1',
    '2','3','4','5','6','7','8','9',':',';','<','=','>','?','@','A','B','C',
    'D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U',
    'V','W','X','Y','Z','[','\\',']','^','_','`','a','b','c','d','e','f','g',
    'h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y',
    'z','{','|','}','~',
    // 0x7F
    '\u2302',
    // 0x80-0x8F
    '\u00C7','\u00FC','\u00E9','\u00E2','\u00E4','\u00E0','\u00E5','\u00E7',
    '\u00EA','\u00EB','\u00E8','\u00EF','\u00EE','\u00EC','\u00C4','\u00C5',
    // 0x90-0x9F
    '\u00C9','\u00E6','\u00C6','\u00F4','\u00F6','\u00F2','\u00FB','\u00F9',
    '\u00FF','\u00D6','\u00DC','\u00A2','\u00A3','\u00A5','\u20A7','\u0192',
    // 0xA0-0xAF
    '\u00E1','\u00ED','\u00F3','\u00FA','\u00F1','\u00D1','\u00AA','\u00BA',
    '\u00BF','\u2310','\u00AC','\u00BD','\u00BC','\u00A1','\u00AB','\u00BB',
    // 0xB0-0xBF  (box-drawing, shade blocks)
    '\u2591','\u2592','\u2593','\u2502','\u2524','\u2561','\u2562','\u2556',
    '\u2555','\u2563','\u2551','\u2557','\u255D','\u255C','\u255B','\u2510',
    // 0xC0-0xCF
    '\u2514','\u2534','\u252C','\u251C','\u2500','\u253C','\u255E','\u255F',
    '\u255A','\u2554','\u2569','\u2566','\u2560','\u2550','\u256C','\u2567',
    // 0xD0-0xDF
    '\u2568','\u2564','\u2565','\u2559','\u2558','\u2552','\u2553','\u256B',
    '\u256A','\u2518','\u250C','\u2588','\u2584','\u258C','\u2590','\u2580',
    // 0xE0-0xEF  (Greek and math)
    '\u03B1','\u00DF','\u0393','\u03C0','\u03A3','\u03C3','\u00B5','\u03C4',
    '\u03A6','\u0398','\u03A9','\u03B4','\u221E','\u03C6','\u03B5','\u2229',
    // 0xF0-0xFF
    '\u2261','\u00B1','\u2265','\u2264','\u2320','\u2321','\u00F7','\u2248',
    '\u00B0','\u2219','\u00B7','\u221A','\u207F','\u00B2','\u25A0','\u00A0',
];
/* eslint-enable */

/** CGA 16-colour palette (attribute nibble → CSS colour) */
const _PCB_CGA = [
    '#000000','#0000AA','#00AA00','#00AAAA',
    '#AA0000','#AA00AA','#AA5500','#AAAAAA',
    '#555555','#5555FF','#55FF55','#55FFFF',
    '#FF5555','#FF55FF','#FFFF55','#FFFFFF',
];

/** PCBoard control macros that carry no visible output — skip them entirely. */
const _PCB_SKIP = new Set([
    'NOSTOP','NOPAUSE','MORE','PAUSE','BEEP','EOL','POS',
    'TIME','DATE','TIMELEFT','MINLEFT','INAME','RNAME',
    'FIRST','LAST','ALIAS','CITY','BAUD','WHO',
    'BOARDNAME','SYSOP','OPTEXT','TEMPTYPE',
]);

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Decode a CP437 byte array to a Unicode string.
 * @param {Uint8Array} bytes
 * @returns {string}
 */
function pcbCp437ToString(bytes) {
    let s = '';
    for (let i = 0; i < bytes.length; i++) s += _PCB_CP437[bytes[i]];
    return s;
}

/**
 * Heuristic check — returns true if the string contains PCBoard @ codes.
 * @param {string} text
 * @returns {boolean}
 */
function hasPCBoardCodes(text) {
    return /@[0-9A-Fa-f]{2}@/.test(text) ||
           /@CLS@/i.test(text)            ||
           /@NOSTOP@/i.test(text);
}

/**
 * Render a PCBoard display file to an HTML string suitable for a <pre>.
 *
 * @param {string|Uint8Array} input  String (message body) or raw bytes (file).
 * @returns {string} HTML fragment.
 */
function renderPCBoardBuffer(input) {
    // Decode bytes if needed
    const text = (input instanceof Uint8Array) ? pcbCp437ToString(input) : input;

    // Strip trailing SAUCE metadata record (starts with "SAUCE", within last 200 chars)
    let src = text;
    const sauceIdx = src.lastIndexOf('SAUCE');
    if (sauceIdx !== -1 && src.length - sauceIdx <= 200) {
        src = src.substring(0, sauceIdx);
    }

    let out      = '';
    let fgIdx    = 7;   // default light gray
    let bgIdx    = 0;   // default black
    let spanOpen = false;
    let i        = 0;

    function esc(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function openSpan() {
        out += `<span style="color:${_PCB_CGA[fgIdx]};background-color:${_PCB_CGA[bgIdx]}">`;
        spanOpen = true;
    }

    function closeSpan() {
        if (spanOpen) { out += '</span>'; spanOpen = false; }
    }

    function setAttr(attr) {
        closeSpan();
        fgIdx = attr & 0x0F;
        bgIdx = (attr >> 4) & 0x07;
        openSpan();
    }

    openSpan();

    while (i < src.length) {
        const ch = src[i];

        if (ch === '@') {
            // Search for closing @ within a reasonable distance
            const end = src.indexOf('@', i + 1);
            if (end > i && end - i <= 24) {
                const code  = src.substring(i + 1, end);
                const upper = code.toUpperCase();

                // Two-character hex color attribute: @XY@ → CGA attribute byte
                if (/^[0-9A-Fa-f]{2}$/.test(code)) {
                    setAttr(parseInt(code, 16));
                    i = end + 1;
                    continue;
                }

                // Clear screen — discard all previous output
                if (upper === 'CLS') {
                    closeSpan();
                    out = '';
                    openSpan();
                    i = end + 1;
                    continue;
                }

                // Known display-control macros with no visible output
                if (_PCB_SKIP.has(upper)) {
                    i = end + 1;
                    continue;
                }

                // Unrecognised @WORD@ that looks like a macro — skip silently
                if (/^[A-Za-z][A-Za-z0-9_]{0,23}$/.test(code)) {
                    i = end + 1;
                    continue;
                }
            }

            // Not a valid code — emit the literal @ character
            out += '@';
            i++;

        } else if (ch === '\r') {
            // Normalise CR/CRLF to LF
            i++;
            if (i < src.length && src[i] === '\n') i++;
            out += '\n';

        } else {
            out += esc(ch);
            i++;
        }
    }

    closeSpan();
    return out;
}

// ─── Attach to window ─────────────────────────────────────────────────────────
if (typeof window !== 'undefined') {
    window.renderPCBoardBuffer = renderPCBoardBuffer;
    window.hasPCBoardCodes     = hasPCBoardCodes;
    window.pcbCp437ToString    = pcbCp437ToString;
}
