/**
 * ANSI Terminal Renderer
 * Renders ANSI escape sequences to HTML by emulating a terminal buffer
 *
 * awehttam@gmail.com
 */
class AnsiTerminal {
    constructor(cols = 80, rows = 25) {
        this.cols = cols;
        this.rows = rows;
        this.defaultCell = {
            char: ' ',
            fg: 7,      // Default white
            bg: 0,      // Default black
            bold: false,
            dim: false,
            italic: false,
            underline: false,
            blink: false,
            reverse: false
        };
        this.reset();
    }

    reset() {
        // Initialize buffer - array of rows, each row is array of cells
        this.buffer = [];
        for (let r = 0; r < this.rows; r++) {
            this.buffer.push(this.createEmptyRow());
        }
        this.cursorRow = 0;
        this.cursorCol = 0;
        this.savedCursor = { row: 0, col: 0 };
        this.currentAttr = { ...this.defaultCell };
        this.maxRowUsed = 0;
        this.maxColUsed = 0;
    }

    createEmptyRow() {
        const row = [];
        for (let c = 0; c < this.cols; c++) {
            row.push({ ...this.defaultCell });
        }
        return row;
    }

    // Ensure cursor is within bounds
    clampCursor() {
        this.cursorRow = Math.max(0, Math.min(this.rows - 1, this.cursorRow));
        this.cursorCol = Math.max(0, Math.min(this.cols - 1, this.cursorCol));
    }

    // Extend buffer if we need more rows
    ensureRows(needed) {
        while (this.buffer.length < needed) {
            this.buffer.push(this.createEmptyRow());
        }
        if (needed > this.rows) {
            this.rows = needed;
        }
    }

    // Write a character at cursor position
    writeChar(char) {
        if (char === '\n') {
            this.cursorRow++;
            this.cursorCol = 0;
            this.ensureRows(this.cursorRow + 1);
            this.maxRowUsed = Math.max(this.maxRowUsed, this.cursorRow);
            return;
        }
        if (char === '\r') {
            this.cursorCol = 0;
            return;
        }
        if (char === '\t') {
            // Tab to next 8-column boundary
            const nextTab = Math.floor(this.cursorCol / 8) * 8 + 8;
            this.cursorCol = Math.min(nextTab, this.cols - 1);
            return;
        }
        if (char === '\b') {
            // Backspace
            if (this.cursorCol > 0) this.cursorCol--;
            return;
        }

        // Skip control characters
        const code = char.charCodeAt(0);
        if (code < 32 && code !== 27) return;

        this.ensureRows(this.cursorRow + 1);
        this.clampCursor();

        // Write character with current attributes
        this.buffer[this.cursorRow][this.cursorCol] = {
            char: char,
            fg: this.currentAttr.fg,
            bg: this.currentAttr.bg,
            bold: this.currentAttr.bold,
            dim: this.currentAttr.dim,
            italic: this.currentAttr.italic,
            underline: this.currentAttr.underline,
            blink: this.currentAttr.blink,
            reverse: this.currentAttr.reverse
        };

        this.maxRowUsed = Math.max(this.maxRowUsed, this.cursorRow);
        this.maxColUsed = Math.max(this.maxColUsed, this.cursorCol);

        this.cursorCol++;
        if (this.cursorCol >= this.cols) {
            this.cursorCol = 0;
            this.cursorRow++;
            this.ensureRows(this.cursorRow + 1);
        }
    }

    // Process SGR (Select Graphic Rendition) codes
    processSGR(params) {
        if (params.length === 0) params = [0];

        for (let i = 0; i < params.length; i++) {
            const code = params[i];

            if (code === 0) {
                // Reset all attributes
                this.currentAttr = { ...this.defaultCell };
            } else if (code === 1) {
                this.currentAttr.bold = true;
            } else if (code === 2) {
                this.currentAttr.dim = true;
            } else if (code === 3) {
                this.currentAttr.italic = true;
            } else if (code === 4) {
                this.currentAttr.underline = true;
            } else if (code === 5 || code === 6) {
                this.currentAttr.blink = true;
            } else if (code === 7) {
                this.currentAttr.reverse = true;
            } else if (code === 22) {
                this.currentAttr.bold = false;
                this.currentAttr.dim = false;
            } else if (code === 23) {
                this.currentAttr.italic = false;
            } else if (code === 24) {
                this.currentAttr.underline = false;
            } else if (code === 25) {
                this.currentAttr.blink = false;
            } else if (code === 27) {
                this.currentAttr.reverse = false;
            } else if (code >= 30 && code <= 37) {
                this.currentAttr.fg = code - 30;
            } else if (code === 38) {
                // Extended foreground color
                if (params[i + 1] === 5 && params[i + 2] !== undefined) {
                    this.currentAttr.fg = params[i + 2];
                    i += 2;
                }
            } else if (code === 39) {
                this.currentAttr.fg = 7; // Default foreground
            } else if (code >= 40 && code <= 47) {
                this.currentAttr.bg = code - 40;
            } else if (code === 48) {
                // Extended background color
                if (params[i + 1] === 5 && params[i + 2] !== undefined) {
                    this.currentAttr.bg = params[i + 2];
                    i += 2;
                }
            } else if (code === 49) {
                this.currentAttr.bg = 0; // Default background
            } else if (code >= 90 && code <= 97) {
                this.currentAttr.fg = code - 90 + 8; // Bright foreground
            } else if (code >= 100 && code <= 107) {
                this.currentAttr.bg = code - 100 + 8; // Bright background
            }
        }
    }

    // Clear screen based on mode
    clearScreen(mode) {
        if (mode === 0) {
            // Clear from cursor to end of screen
            for (let c = this.cursorCol; c < this.cols; c++) {
                this.buffer[this.cursorRow][c] = { ...this.defaultCell };
            }
            for (let r = this.cursorRow + 1; r < this.buffer.length; r++) {
                this.buffer[r] = this.createEmptyRow();
            }
        } else if (mode === 1) {
            // Clear from start to cursor
            for (let r = 0; r < this.cursorRow; r++) {
                this.buffer[r] = this.createEmptyRow();
            }
            for (let c = 0; c <= this.cursorCol; c++) {
                this.buffer[this.cursorRow][c] = { ...this.defaultCell };
            }
        } else if (mode === 2 || mode === 3) {
            // Clear entire screen
            for (let r = 0; r < this.buffer.length; r++) {
                this.buffer[r] = this.createEmptyRow();
            }
            this.cursorRow = 0;
            this.cursorCol = 0;
        }
    }

    // Clear line based on mode
    clearLine(mode) {
        if (this.cursorRow >= this.buffer.length) return;

        if (mode === 0) {
            // Clear from cursor to end of line
            for (let c = this.cursorCol; c < this.cols; c++) {
                this.buffer[this.cursorRow][c] = { ...this.defaultCell };
            }
        } else if (mode === 1) {
            // Clear from start to cursor
            for (let c = 0; c <= this.cursorCol; c++) {
                this.buffer[this.cursorRow][c] = { ...this.defaultCell };
            }
        } else if (mode === 2) {
            // Clear entire line
            this.buffer[this.cursorRow] = this.createEmptyRow();
        }
    }

    // Process the input text
    process(text) {
        let i = 0;
        while (i < text.length) {
            const char = text[i];

            // Check for escape sequence
            if (char === '\x1b' && text[i + 1] === '[') {
                // CSI sequence
                i += 2;
                let params = '';

                // Collect parameters
                while (i < text.length && /[0-9;]/.test(text[i])) {
                    params += text[i];
                    i++;
                }

                // Get command character
                const cmd = text[i] || '';
                i++;

                // Parse parameters
                const paramList = params ? params.split(';').map(p => parseInt(p, 10) || 0) : [];
                const n = paramList[0] || 1;
                const m = paramList[1] || 1;

                switch (cmd) {
                    case 'A': // Cursor up
                        this.cursorRow -= n;
                        this.clampCursor();
                        break;
                    case 'B': // Cursor down
                        this.cursorRow += n;
                        this.ensureRows(this.cursorRow + 1);
                        this.clampCursor();
                        break;
                    case 'C': // Cursor forward
                        this.cursorCol += n;
                        this.clampCursor();
                        break;
                    case 'D': // Cursor back
                        this.cursorCol -= n;
                        this.clampCursor();
                        break;
                    case 'E': // Cursor next line
                        this.cursorRow += n;
                        this.cursorCol = 0;
                        this.ensureRows(this.cursorRow + 1);
                        break;
                    case 'F': // Cursor previous line
                        this.cursorRow -= n;
                        this.cursorCol = 0;
                        this.clampCursor();
                        break;
                    case 'G': // Cursor horizontal absolute
                        this.cursorCol = n - 1;
                        this.clampCursor();
                        break;
                    case 'H': // Cursor position
                    case 'f':
                        this.cursorRow = (paramList[0] || 1) - 1;
                        this.cursorCol = (paramList[1] || 1) - 1;
                        this.ensureRows(this.cursorRow + 1);
                        this.clampCursor();
                        break;
                    case 'J': // Clear screen
                        this.clearScreen(paramList[0] || 0);
                        break;
                    case 'K': // Clear line
                        this.clearLine(paramList[0] || 0);
                        break;
                    case 'm': // SGR - Select Graphic Rendition
                        this.processSGR(paramList);
                        break;
                    case 's': // Save cursor position
                        this.savedCursor = { row: this.cursorRow, col: this.cursorCol };
                        break;
                    case 'u': // Restore cursor position
                        this.cursorRow = this.savedCursor.row;
                        this.cursorCol = this.savedCursor.col;
                        break;
                    case 'S': // Scroll up
                        for (let s = 0; s < n; s++) {
                            this.buffer.shift();
                            this.buffer.push(this.createEmptyRow());
                        }
                        break;
                    case 'T': // Scroll down
                        for (let s = 0; s < n; s++) {
                            this.buffer.pop();
                            this.buffer.unshift(this.createEmptyRow());
                        }
                        break;
                    // Ignore other sequences
                }
            } else if (char === '\x1b') {
                // Other escape sequences - skip
                i++;
                if (i < text.length) i++; // Skip next char too
            } else {
                this.writeChar(char);
                i++;
            }
        }
    }

    // Color index to CSS class mapping
    getColorClass(colorIndex, isBg = false) {
        const colors = [
            'black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white',
            'bright-black', 'bright-red', 'bright-green', 'bright-yellow',
            'bright-blue', 'bright-magenta', 'bright-cyan', 'bright-white'
        ];
        const prefix = isBg ? 'ansi-bg-' : 'ansi-';
        if (colorIndex >= 0 && colorIndex < 16) {
            return prefix + colors[colorIndex];
        }
        return '';
    }

    // Render the buffer to HTML
    // Each character is placed in its own fixed-width span (ansi-c) so that box-drawing
    // and block characters align correctly even when the browser uses a fallback font
    // that has different glyph metrics than the primary monospace font.
    render() {
        let html = '';

        // Only render up to the last used row
        const rowsToRender = Math.min(this.buffer.length, this.maxRowUsed + 1);

        for (let r = 0; r < rowsToRender; r++) {
            const row = this.buffer[r];

            // Find last non-space character in row for trimming
            let lastNonSpace = -1;
            for (let c = row.length - 1; c >= 0; c--) {
                if (row[c].char !== ' ' || row[c].bg !== 0) {
                    lastNonSpace = c;
                    break;
                }
            }

            for (let c = 0; c <= lastNonSpace; c++) {
                const cell = row[c];

                // Build class list — always include ansi-c for fixed-width cell layout
                const classes = ['ansi-c'];

                const fgClass = this.getColorClass(cell.fg, false);
                const bgClass = this.getColorClass(cell.bg, true);

                if (fgClass && cell.fg !== 7) classes.push(fgClass);
                if (bgClass && cell.bg !== 0) classes.push(bgClass);
                if (cell.bold) classes.push('ansi-bold');
                if (cell.dim) classes.push('ansi-dim');
                if (cell.italic) classes.push('ansi-italic');
                if (cell.underline) classes.push('ansi-underline');
                if (cell.blink) classes.push('ansi-blink');
                if (cell.reverse) classes.push('ansi-reverse');

                html += `<span class="${classes.join(' ')}">${this.escapeChar(cell.char)}</span>`;
            }

            // End of row
            if (r < rowsToRender - 1) {
                html += '\n';
            }
        }

        return html;
    }

    escapeChar(char) {
        switch (char) {
            case '&': return '&amp;';
            case '<': return '&lt;';
            case '>': return '&gt;';
            case '"': return '&quot;';
            case "'": return '&#039;';
            default: return char;
        }
    }
}

/**
 * Render ANSI text using terminal emulation
 * Falls back to simple parsing for non-ANSI text
 * Also handles pipe codes (BBS color codes)
 */
function renderAnsiTerminal(text, cols = 80, rows = 500) {
    if (!text) return '';

    // Check if ANSI parsing is enabled
    if (window.userSettings?.ansi_parsing === false) {
        return escapeHtml(text);
    }

    // Check for pipe codes first - convert them to ANSI then process
    if (hasPipeCodes(text)) {
        // Convert pipe codes to ANSI, then process ANSI
        text = convertPipeCodesToAnsi(text);
    }

    // Check if text contains cursor positioning sequences
    // If not, use the simpler parseAnsi for better performance
    if (!/\x1b\[[0-9;]*[ABCDEFGHJKfsu]/.test(text)) {
        return parseAnsi(text);
    }

    const terminal = new AnsiTerminal(cols, rows);
    terminal.process(text);
    return terminal.render();
}



/**
 * Parse ANSI escape codes and convert to HTML spans with CSS classes
 * Supports SGR (Select Graphic Rendition) codes for colors and styles
 * Strips cursor control and other non-display sequences
 * Also handles pipe codes (BBS color codes)
 *
 * Must be called BEFORE escapeHtml since it processes raw escape sequences
 * Text content is escaped within this function for XSS safety
 */
function parseAnsi(text) {
    if (!text) return text;

    // Check if ANSI parsing is enabled in user settings (default: true)
    if (window.userSettings?.ansi_parsing === false) {
        return escapeHtml(text);
    }

    // Convert pipe codes to ANSI first if present
    if (hasPipeCodes(text)) {
        text = convertPipeCodesToAnsi(text);
    }

    // First, strip all non-SGR escape sequences (cursor movement, clear screen, etc.)
    // These don't make sense in web display context
    // Pattern matches: ESC[ followed by optional params and a command letter (not 'm')
    // Common sequences: A (up), B (down), C (forward), D (back), H (position), J (clear), K (erase line), etc.
    text = text.replace(/\x1b\[[0-9;]*[ABCDEFGHJKSTfnsu]/g, '');

    // Also strip other escape sequences: ESC followed by single char, or ESC] (OSC), etc.
    text = text.replace(/\x1b[^\[]/g, '');
    text = text.replace(/\x1b\][^\x07]*\x07/g, ''); // OSC sequences ending with BEL
    text = text.replace(/\x1b\][^\x1b]*\x1b\\/g, ''); // OSC sequences ending with ST

    // ANSI color maps
    const fgColors = {
        30: 'ansi-black',
        31: 'ansi-red',
        32: 'ansi-green',
        33: 'ansi-yellow',
        34: 'ansi-blue',
        35: 'ansi-magenta',
        36: 'ansi-cyan',
        37: 'ansi-white',
        39: '', // default
        90: 'ansi-bright-black',
        91: 'ansi-bright-red',
        92: 'ansi-bright-green',
        93: 'ansi-bright-yellow',
        94: 'ansi-bright-blue',
        95: 'ansi-bright-magenta',
        96: 'ansi-bright-cyan',
        97: 'ansi-bright-white'
    };

    const bgColors = {
        40: 'ansi-bg-black',
        41: 'ansi-bg-red',
        42: 'ansi-bg-green',
        43: 'ansi-bg-yellow',
        44: 'ansi-bg-blue',
        45: 'ansi-bg-magenta',
        46: 'ansi-bg-cyan',
        47: 'ansi-bg-white',
        49: '', // default
        100: 'ansi-bg-bright-black',
        101: 'ansi-bg-bright-red',
        102: 'ansi-bg-bright-green',
        103: 'ansi-bg-bright-yellow',
        104: 'ansi-bg-bright-blue',
        105: 'ansi-bg-bright-magenta',
        106: 'ansi-bg-bright-cyan',
        107: 'ansi-bg-bright-white'
    };

    // Current state
    let currentClasses = [];
    let result = '';
    let spanOpen = false;

    // ANSI SGR escape sequence pattern: ESC[ followed by params and ending with 'm'
    // ESC is \x1b (decimal 27)
    const ansiPattern = /\x1b\[([0-9;]*)m/g;

    let lastIndex = 0;
    let match;

    while ((match = ansiPattern.exec(text)) !== null) {
        // Add text before this escape sequence (escaped for XSS safety)
        if (match.index > lastIndex) {
            const textBefore = text.substring(lastIndex, match.index);
            result += escapeHtml(textBefore);
        }

        // Parse the SGR parameters
        const params = match[1] ? match[1].split(';').map(p => parseInt(p, 10) || 0) : [0];

        for (const code of params) {
            if (code === 0) {
                // Reset all attributes
                if (spanOpen) {
                    result += '</span>';
                    spanOpen = false;
                }
                currentClasses = [];
            } else if (code === 1) {
                // Bold
                if (!currentClasses.includes('ansi-bold')) {
                    currentClasses.push('ansi-bold');
                }
            } else if (code === 2) {
                // Dim/faint
                if (!currentClasses.includes('ansi-dim')) {
                    currentClasses.push('ansi-dim');
                }
            } else if (code === 3) {
                // Italic
                if (!currentClasses.includes('ansi-italic')) {
                    currentClasses.push('ansi-italic');
                }
            } else if (code === 4) {
                // Underline
                if (!currentClasses.includes('ansi-underline')) {
                    currentClasses.push('ansi-underline');
                }
            } else if (code === 5 || code === 6) {
                // Blink (slow/fast)
                if (!currentClasses.includes('ansi-blink')) {
                    currentClasses.push('ansi-blink');
                }
            } else if (code === 7) {
                // Reverse/inverse
                if (!currentClasses.includes('ansi-reverse')) {
                    currentClasses.push('ansi-reverse');
                }
            } else if (code === 8) {
                // Hidden
                if (!currentClasses.includes('ansi-hidden')) {
                    currentClasses.push('ansi-hidden');
                }
            } else if (code === 9) {
                // Strikethrough
                if (!currentClasses.includes('ansi-strike')) {
                    currentClasses.push('ansi-strike');
                }
            } else if (code >= 30 && code <= 37 || code === 39 || code >= 90 && code <= 97) {
                // Foreground colors - remove any existing fg color first
                currentClasses = currentClasses.filter(c => !c.startsWith('ansi-') || c.startsWith('ansi-bg-') ||
                    ['ansi-bold', 'ansi-dim', 'ansi-italic', 'ansi-underline', 'ansi-blink', 'ansi-reverse', 'ansi-hidden', 'ansi-strike'].includes(c));
                if (fgColors[code]) {
                    currentClasses.push(fgColors[code]);
                }
            } else if (code >= 40 && code <= 47 || code === 49 || code >= 100 && code <= 107) {
                // Background colors - remove any existing bg color first
                currentClasses = currentClasses.filter(c => !c.startsWith('ansi-bg-'));
                if (bgColors[code]) {
                    currentClasses.push(bgColors[code]);
                }
            }
            // Note: codes 22-29 reset specific attributes, could add if needed
        }

        // Close previous span if open and update
        if (spanOpen) {
            result += '</span>';
            spanOpen = false;
        }

        // Open new span if we have classes
        if (currentClasses.length > 0) {
            result += `<span class="${currentClasses.join(' ')}">`;
            spanOpen = true;
        }

        lastIndex = ansiPattern.lastIndex;
    }

    // Add remaining text after last escape sequence
    if (lastIndex < text.length) {
        result += escapeHtml(text.substring(lastIndex));
    }

    // Close any remaining open span
    if (spanOpen) {
        result += '</span>';
    }

    return result;
}

/**
 * Check if text contains ANSI escape sequences (any type)
 */
function hasAnsiCodes(text) {
    return /\x1b[\[\]PX^_][^\x1b]*|(\x1b.)/.test(text);
}

/**
 * Check if text contains pipe codes (BBS color codes like |15, |04, etc. or special codes like |CL)
 */
function hasPipeCodes(text) {
    // Match either hex color codes (|00-|FF) or special letter codes (|CL, |PA, etc.)
    return /\|[0-9A-Fa-f]{2}|\|[A-Z]{2}/i.test(text);
}

/**
 * Convert pipe codes to ANSI escape sequences
 * This allows pipe codes to be processed through the existing ANSI parser
 * Also strips special pipe codes (|CL, |PA, etc.) that don't make sense in web context
 */
function convertPipeCodesToAnsi(text) {
    if (!text) return text;

    // Strip special pipe codes (clear screen, pause, etc.) - not relevant for message viewing
    // Common special codes: |CL (clear), |PA (pause), |DE (delete), |RD (read), |CR (carriage return), |LF (line feed)
    // Use explicit whitelist of known special codes (case-insensitive)
    text = text.replace(/\|(CL|PA|DE|RD|CR|LF)/gi, '');

    // Pipe code to ANSI color mapping
    const pipeToAnsiFg = {
        0: 30,   // Black
        1: 34,   // Blue
        2: 32,   // Green
        3: 36,   // Cyan
        4: 31,   // Red
        5: 35,   // Magenta
        6: 33,   // Yellow
        7: 37,   // White
        8: 90,   // Bright Black (Gray)
        9: 94,   // Bright Blue
        10: 92,  // Bright Green
        11: 96,  // Bright Cyan
        12: 91,  // Bright Red
        13: 95,  // Bright Magenta
        14: 93,  // Bright Yellow
        15: 97   // Bright White
    };

    const pipeToAnsiBg = {
        0: 40,   // Black
        1: 44,   // Blue
        2: 42,   // Green
        3: 46,   // Cyan
        4: 41,   // Red
        5: 45,   // Magenta
        6: 43,   // Yellow
        7: 47,   // White
        8: 100,  // Bright Black
        9: 104,  // Bright Blue
        10: 102, // Bright Green
        11: 106, // Bright Cyan
        12: 101, // Bright Red
        13: 105, // Bright Magenta
        14: 103, // Bright Yellow
        15: 107  // Bright White
    };

    // Replace pipe codes with ANSI escape sequences
    // Codes use Renegade-style decimal notation: |00-|15 = foreground, |16-|23 = background
    return text.replace(/\|([0-9A-Fa-f]{2})/g, (match, codeHex) => {
        const code = parseInt(codeHex, 10);

        if (code <= 15) {
            // Codes 00-15: foreground color 0-15
            const ansiFg = pipeToAnsiFg[code] || 37;
            return `\x1b[${ansiFg}m`;
        } else if (code >= 16 && code <= 23) {
            // Codes 16-23: background color 0-7 (code - 16)
            const bg = code - 16;
            const ansiBg = pipeToAnsiBg[bg] || 40;
            return `\x1b[${ansiBg}m`;
        } else {
            // Codes above 23 have no standard meaning in this scheme
            return '';
        }
    });
}

/**
 * Parse pipe codes (Renegade/Mystic style) and convert to HTML
 * Pipe codes: |XX where XX is a two-digit DECIMAL code
 *
 * Standard 16-color mapping (decimal):
 * |00-|07: Normal colors (Black, Blue, Green, Cyan, Red, Magenta, Yellow, White)
 * |08-|15: Bright colors
 * |16-|23: Background colors 0-7 (code - 16)
 *
 * Format: set foreground with |00-|15, then background with |16-|23
 * Examples:
 *   |15 = Bright white on black
 *   |0C = Bright red on black
 *   |1E = Yellow on blue
 */
function parsePipeCodes(text) {
    if (!text) return text;

    // Check if pipe code parsing is enabled (default: true)
    if (window.userSettings?.pipe_parsing === false) {
        return escapeHtml(text);
    }

    // Pipe code color mapping (0-15 standard colors)
    const pipeColors = [
        'black',        // 0
        'blue',         // 1
        'green',        // 2
        'cyan',         // 3
        'red',          // 4
        'magenta',      // 5
        'yellow',       // 6 (brown in some systems)
        'white',        // 7
        'bright-black', // 8 (gray)
        'bright-blue',  // 9
        'bright-green', // 10 (A)
        'bright-cyan',  // 11 (B)
        'bright-red',   // 12 (C)
        'bright-magenta', // 13 (D)
        'bright-yellow',  // 14 (E)
        'bright-white'    // 15 (F)
    ];

    // Current state
    let currentFg = 7;  // Default white
    let currentBg = 0;  // Default black
    let result = '';
    let spanOpen = false;

    // Pipe code pattern: |XX where XX is hex digits
    const pipePattern = /\|([0-9A-Fa-f]{2})/g;

    let lastIndex = 0;
    let match;

    function updateSpan() {
        if (spanOpen) {
            result += '</span>';
            spanOpen = false;
        }

        const classes = [];
        if (currentFg !== 7) {
            classes.push('ansi-' + pipeColors[currentFg]);
        }
        if (currentBg !== 0) {
            classes.push('ansi-bg-' + pipeColors[currentBg]);
        }

        if (classes.length > 0) {
            result += `<span class="${classes.join(' ')}">`;
            spanOpen = true;
        }
    }

    while ((match = pipePattern.exec(text)) !== null) {
        // Add text before this pipe code (escaped for XSS safety)
        if (match.index > lastIndex) {
            const textBefore = text.substring(lastIndex, match.index);
            result += escapeHtml(textBefore);
        }

        // Parse the pipe code as decimal (Renegade style)
        const code = parseInt(match[1], 10);

        if (code <= 15) {
            // Codes 00-15: foreground color 0-15
            currentFg = code;
        } else if (code >= 16 && code <= 23) {
            // Codes 16-23: background color 0-7 (code - 16)
            currentBg = code - 16;
        }
        // Codes above 23 have no standard meaning in this scheme

        // Ensure colors are in valid range
        currentFg = currentFg & 0x0F;
        currentBg = currentBg & 0x0F;

        updateSpan();
        lastIndex = pipePattern.lastIndex;
    }

    // Add remaining text after last pipe code
    if (lastIndex < text.length) {
        result += escapeHtml(text.substring(lastIndex));
    }

    // Close any remaining open span
    if (spanOpen) {
        result += '</span>';
    }

    return result;
}

/**
 * Auto-detect and parse both ANSI and pipe codes
 * Tries to intelligently detect which format is used
 * This is an alias for renderAnsiTerminal which now handles both formats
 */
function parseColorCodes(text) {
    return renderAnsiTerminal(text);
}