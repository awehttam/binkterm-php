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
                let privatePrefix = '';

                if (i < text.length && text[i] === '?') {
                    privatePrefix = '?';
                    i++;
                }

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
                    case 'h': // Set mode
                    case 'l': // Reset mode
                        // DEC private modes such as ESC[?7h (autowrap) are common in
                        // ANSI ads. We do not emulate those modes yet, but we must
                        // consume the sequence cleanly instead of leaking trailing
                        // characters into the rendered output.
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

class ArtScreenBuffer {
    constructor(cols = 80, rows = 25) {
        this.cols = cols;
        this.rows = rows;
        this.defaultCell = {
            char: ' ',
            fg: 7,
            bg: 0,
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

    clampCursor() {
        this.cursorRow = Math.max(0, Math.min(this.rows - 1, this.cursorRow));
        this.cursorCol = Math.max(0, Math.min(this.cols - 1, this.cursorCol));
    }

    ensureRows(needed) {
        while (this.buffer.length < needed) {
            this.buffer.push(this.createEmptyRow());
        }
        if (needed > this.rows) {
            this.rows = needed;
        }
    }

    writeChar(char, attr = this.currentAttr) {
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
            const nextTab = Math.floor(this.cursorCol / 8) * 8 + 8;
            this.cursorCol = Math.min(nextTab, this.cols - 1);
            return;
        }
        if (char === '\b') {
            if (this.cursorCol > 0) this.cursorCol--;
            return;
        }

        const code = char.charCodeAt(0);
        if (code < 32 && code !== 27) return;

        this.ensureRows(this.cursorRow + 1);
        this.clampCursor();
        this.buffer[this.cursorRow][this.cursorCol] = {
            char,
            fg: attr.fg,
            bg: attr.bg,
            bold: attr.bold,
            dim: attr.dim,
            italic: attr.italic,
            underline: attr.underline,
            blink: attr.blink,
            reverse: attr.reverse
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

    clearScreen(mode) {
        if (mode === 0) {
            for (let c = this.cursorCol; c < this.cols; c++) {
                this.buffer[this.cursorRow][c] = { ...this.defaultCell };
            }
            for (let r = this.cursorRow + 1; r < this.buffer.length; r++) {
                this.buffer[r] = this.createEmptyRow();
            }
        } else if (mode === 1) {
            for (let r = 0; r < this.cursorRow; r++) {
                this.buffer[r] = this.createEmptyRow();
            }
            for (let c = 0; c <= this.cursorCol; c++) {
                this.buffer[this.cursorRow][c] = { ...this.defaultCell };
            }
        } else if (mode === 2 || mode === 3) {
            for (let r = 0; r < this.buffer.length; r++) {
                this.buffer[r] = this.createEmptyRow();
            }
            this.cursorRow = 0;
            this.cursorCol = 0;
        }
    }

    clearLine(mode) {
        if (this.cursorRow >= this.buffer.length) return;

        if (mode === 0) {
            for (let c = this.cursorCol; c < this.cols; c++) {
                this.buffer[this.cursorRow][c] = { ...this.defaultCell };
            }
        } else if (mode === 1) {
            for (let c = 0; c <= this.cursorCol; c++) {
                this.buffer[this.cursorRow][c] = { ...this.defaultCell };
            }
        } else if (mode === 2) {
            this.buffer[this.cursorRow] = this.createEmptyRow();
        }
    }

    scrollUp(lines = 1) {
        for (let s = 0; s < lines; s++) {
            this.buffer.shift();
            this.buffer.push(this.createEmptyRow());
        }
    }

    scrollDown(lines = 1) {
        for (let s = 0; s < lines; s++) {
            this.buffer.pop();
            this.buffer.unshift(this.createEmptyRow());
        }
    }
}

class ArtHtmlRenderer {
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

    escapeChar(char) {
        switch (char) {
            case ' ': return ' ';
            case '&': return '&amp;';
            case '<': return '&lt;';
            case '>': return '&gt;';
            case '"': return '&quot;';
            case "'": return '&#039;';
            default: return char;
        }
    }

    getCellClasses(cell) {
        const classes = [];
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
        return classes;
    }

    render(buffer, options = {}) {
        let html = '';
        const preserveFullWidth = options.preserveFullWidth === true;
        const preserveFullHeight = options.preserveFullHeight === true;
        const requestedRowCount = Number.isInteger(options.rowCount) && options.rowCount > 0
            ? options.rowCount
            : buffer.rows;
        const usedRows = Math.min(buffer.buffer.length, buffer.maxRowUsed + 1);
        const rowsToRender = preserveFullHeight
            ? Math.min(buffer.buffer.length, Math.max(usedRows, requestedRowCount))
            : usedRows;

        for (let r = 0; r < rowsToRender; r++) {
            const row = buffer.buffer[r];
            let lastNonSpace;

            if (preserveFullWidth) {
                lastNonSpace = Math.max(0, buffer.cols - 1);
            } else {
                lastNonSpace = -1;
                for (let c = row.length - 1; c >= 0; c--) {
                    if (row[c].char !== ' ' || row[c].bg !== 0) {
                        lastNonSpace = c;
                        break;
                    }
                }
            }

            // Group consecutive cells with identical styling into one span
            let spanOpen = false;
            let currentClassStr = null;

            for (let c = 0; c <= lastNonSpace; c++) {
                const cell = row[c];
                const classes = this.getCellClasses(cell);
                const classStr = classes.join(' ');

                if (classStr !== currentClassStr) {
                    if (spanOpen) html += '</span>';
                    if (classStr) {
                        html += `<span class="${classStr}">`;
                        spanOpen = true;
                    } else {
                        spanOpen = false;
                    }
                    currentClassStr = classStr;
                }

                html += this.escapeChar(cell.char);
            }

            if (spanOpen) html += '</span>';

            if (r < rowsToRender - 1) {
                html += '\n';
            }
        }

        return html;
    }
}

/**
 * Per-character HTML renderer — identical styling logic to ArtHtmlRenderer but
 * wraps every cell in its own <span>. Enabled via ANSI_RENDERER_MODE=perchar.
 * Trade-off: more verbose HTML, but each character is independently addressable.
 * Note: URL hyperlinking does not work in this mode because URLs are fragmented
 * across individual spans.
 */
class ArtHtmlRendererPerChar extends ArtHtmlRenderer {
    render(buffer, options = {}) {
        let html = '';
        const preserveFullWidth = options.preserveFullWidth === true;
        const preserveFullHeight = options.preserveFullHeight === true;
        const requestedRowCount = Number.isInteger(options.rowCount) && options.rowCount > 0
            ? options.rowCount
            : buffer.rows;
        const usedRows = Math.min(buffer.buffer.length, buffer.maxRowUsed + 1);
        const rowsToRender = preserveFullHeight
            ? Math.min(buffer.buffer.length, Math.max(usedRows, requestedRowCount))
            : usedRows;

        for (let r = 0; r < rowsToRender; r++) {
            const row = buffer.buffer[r];
            let lastNonSpace;
            if (preserveFullWidth) {
                lastNonSpace = Math.max(0, buffer.cols - 1);
            } else {
                lastNonSpace = -1;
                for (let c = row.length - 1; c >= 0; c--) {
                    if (row[c].char !== ' ' || row[c].bg !== 0) {
                        lastNonSpace = c;
                        break;
                    }
                }
            }

            for (let c = 0; c <= lastNonSpace; c++) {
                const cell = row[c];
                const classes = this.getCellClasses(cell);
                if (classes.length > 0) {
                    html += `<span class="${classes.join(' ')}">${this.escapeChar(cell.char)}</span>`;
                } else {
                    html += this.escapeChar(cell.char);
                }
            }

            if (r < rowsToRender - 1) {
                html += '\n';
            }
        }

        return html;
    }
}

/**
 * HTML renderer that emits exact C64 palette CSS classes (c64-fg-N / c64-bg-N)
 * instead of ANSI approximations. Used with ArtScreenRamDecoder where color RAM
 * values are C64 color indices 0–15.
 *
 * Overrides render() to always apply the fg class — C64 color 7 (yellow) is a
 * real foreground color, not a "no-class" default like ANSI color 7.
 */
class C64HtmlRenderer extends ArtHtmlRenderer {
    getColorClass(colorIndex, isBg = false) {
        const prefix = isBg ? 'c64-bg-' : 'c64-fg-';
        if (colorIndex >= 0 && colorIndex < 16) {
            return prefix + colorIndex;
        }
        return '';
    }

    render(buffer) {
        let html = '';
        const rowsToRender = Math.min(buffer.buffer.length, buffer.maxRowUsed + 1);

        for (let r = 0; r < rowsToRender; r++) {
            const row = buffer.buffer[r];
            let lastNonSpace = -1;
            for (let c = row.length - 1; c >= 0; c--) {
                if (row[c].char !== ' ' || row[c].bg !== 0) {
                    lastNonSpace = c;
                    break;
                }
            }

            for (let c = 0; c <= lastNonSpace; c++) {
                const cell   = row[c];
                const classes = ['ansi-c'];
                const fgClass = this.getColorClass(cell.fg, false);
                const bgClass = this.getColorClass(cell.bg, true);

                // Always apply fg class — all 16 C64 colors are distinct.
                if (fgClass) classes.push(fgClass);
                // Skip bg class for color 0 (black = transparent against black bg).
                if (bgClass && cell.bg !== 0) classes.push(bgClass);

                html += `<span class="${classes.join(' ')}">${this.escapeChar(cell.char)}</span>`;
            }

            if (r < rowsToRender - 1) html += '\n';
        }

        return html;
    }
}

class ArtAnsiDecoder {
    constructor(buffer) {
        this.buffer = buffer;
    }

    translateChar(char) {
        return char;
    }

    processSGR(params) {
        if (params.length === 0) params = [0];

        for (let i = 0; i < params.length; i++) {
            const code = params[i];
            if (code === 0) {
                this.buffer.currentAttr = { ...this.buffer.defaultCell };
            } else if (code === 1) {
                this.buffer.currentAttr.bold = true;
            } else if (code === 2) {
                this.buffer.currentAttr.dim = true;
            } else if (code === 3) {
                this.buffer.currentAttr.italic = true;
            } else if (code === 4) {
                this.buffer.currentAttr.underline = true;
            } else if (code === 5 || code === 6) {
                this.buffer.currentAttr.blink = true;
            } else if (code === 7) {
                this.buffer.currentAttr.reverse = true;
            } else if (code === 22) {
                this.buffer.currentAttr.bold = false;
                this.buffer.currentAttr.dim = false;
            } else if (code === 23) {
                this.buffer.currentAttr.italic = false;
            } else if (code === 24) {
                this.buffer.currentAttr.underline = false;
            } else if (code === 25) {
                this.buffer.currentAttr.blink = false;
            } else if (code === 27) {
                this.buffer.currentAttr.reverse = false;
            } else if (code >= 30 && code <= 37) {
                this.buffer.currentAttr.fg = code - 30;
            } else if (code === 38) {
                if (params[i + 1] === 5 && params[i + 2] !== undefined) {
                    this.buffer.currentAttr.fg = params[i + 2];
                    i += 2;
                }
            } else if (code === 39) {
                this.buffer.currentAttr.fg = 7;
            } else if (code >= 40 && code <= 47) {
                this.buffer.currentAttr.bg = code - 40;
            } else if (code === 48) {
                if (params[i + 1] === 5 && params[i + 2] !== undefined) {
                    this.buffer.currentAttr.bg = params[i + 2];
                    i += 2;
                }
            } else if (code === 49) {
                this.buffer.currentAttr.bg = 0;
            } else if (code >= 90 && code <= 97) {
                this.buffer.currentAttr.fg = code - 90 + 8;
            } else if (code >= 100 && code <= 107) {
                this.buffer.currentAttr.bg = code - 100 + 8;
            }
        }
    }

    decode(text) {
        let i = 0;
        while (i < text.length) {
            const char = text[i];
            if (char === '\x1b' && text[i + 1] === '[') {
                i += 2;
                let params = '';
                let privatePrefix = '';

                if (i < text.length && text[i] === '?') {
                    privatePrefix = '?';
                    i++;
                }

                while (i < text.length && /[0-9;]/.test(text[i])) {
                    params += text[i];
                    i++;
                }

                const cmd = text[i] || '';
                i++;
                const paramList = params ? params.split(';').map(p => parseInt(p, 10) || 0) : [];
                const n = paramList[0] || 1;

                switch (cmd) {
                    case 'A':
                        this.buffer.cursorRow -= n;
                        this.buffer.clampCursor();
                        break;
                    case 'B':
                        this.buffer.cursorRow += n;
                        this.buffer.ensureRows(this.buffer.cursorRow + 1);
                        this.buffer.clampCursor();
                        break;
                    case 'C':
                        this.buffer.cursorCol += n;
                        this.buffer.clampCursor();
                        break;
                    case 'D':
                        this.buffer.cursorCol -= n;
                        this.buffer.clampCursor();
                        break;
                    case 'E':
                        this.buffer.cursorRow += n;
                        this.buffer.cursorCol = 0;
                        this.buffer.ensureRows(this.buffer.cursorRow + 1);
                        break;
                    case 'F':
                        this.buffer.cursorRow -= n;
                        this.buffer.cursorCol = 0;
                        this.buffer.clampCursor();
                        break;
                    case 'G':
                        this.buffer.cursorCol = n - 1;
                        this.buffer.clampCursor();
                        break;
                    case 'H':
                    case 'f':
                        this.buffer.cursorRow = (paramList[0] || 1) - 1;
                        this.buffer.cursorCol = (paramList[1] || 1) - 1;
                        this.buffer.ensureRows(this.buffer.cursorRow + 1);
                        this.buffer.clampCursor();
                        break;
                    case 'J':
                        this.buffer.clearScreen(paramList[0] || 0);
                        break;
                    case 'K':
                        this.buffer.clearLine(paramList[0] || 0);
                        break;
                    case 'm':
                        this.processSGR(paramList);
                        break;
                    case 's':
                        this.buffer.savedCursor = { row: this.buffer.cursorRow, col: this.buffer.cursorCol };
                        break;
                    case 'u':
                        this.buffer.cursorRow = this.buffer.savedCursor.row;
                        this.buffer.cursorCol = this.buffer.savedCursor.col;
                        break;
                    case 'S':
                        this.buffer.scrollUp(n);
                        break;
                    case 'T':
                        this.buffer.scrollDown(n);
                        break;
                    case 'h':
                    case 'l':
                        // Ignore mode set/reset sequences, including DEC private modes
                        // like ESC[?7h, after consuming them fully.
                        break;
                }
            } else if (char === '\x1b') {
                i++;
                if (i < text.length) i++;
            } else {
                this.buffer.writeChar(this.translateChar(char), this.buffer.currentAttr);
                i++;
            }
        }
    }
}

class ArtAmigaAnsiDecoder extends ArtAnsiDecoder {
    constructor(buffer) {
        super(buffer);
        this.translationMap = {
            '\u00a0': ' ',
            '\u00f7': '\u00f7',
            '\u00d7': '\u00d7'
        };
    }

    translateChar(char) {
        return this.translationMap[char] || char;
    }
}

class ArtPetsciiDecoder {
    constructor(buffer) {
        this.buffer = buffer;
        this.reverse = false;
        this.charsetMode = 'upper_graphics';
        // Maps PETSCII color-change codes to C64 color indices 0–15.
        // These indices are used with C64HtmlRenderer (c64-fg-N / c64-bg-N classes)
        // and the exact C64 palette defined in ansisys.css.
        this.colorMap = {
            5:   1,   // white        → C64 1
            28:  2,   // red          → C64 2
            30:  5,   // green        → C64 5
            31:  6,   // blue         → C64 6
            129: 8,   // orange       → C64 8
            144: 0,   // black        → C64 0
            149: 9,   // brown        → C64 9
            150: 10,  // light red    → C64 10
            151: 11,  // dark grey    → C64 11
            152: 12,  // grey         → C64 12
            153: 13,  // light green  → C64 13
            154: 14,  // light blue   → C64 14
            155: 15,  // light grey   → C64 15
            156: 4,   // purple       → C64 4
            158: 7,   // yellow       → C64 7
            159: 3    // cyan         → C64 3
        };
    }

    decode(input) {
        const bytes = this.toBytes(input);
        for (const value of bytes) {
            this.processByte(value);
        }
    }

    toBytes(input) {
        if (input instanceof Uint8Array) {
            return input;
        }

        if (Array.isArray(input)) {
            return Uint8Array.from(input);
        }

        const text = String(input || '');
        const bytes = new Uint8Array(text.length);
        for (let i = 0; i < text.length; i++) {
            bytes[i] = text.charCodeAt(i) & 0xff;
        }
        return bytes;
    }

    processByte(byte) {
        if (Object.prototype.hasOwnProperty.call(this.colorMap, byte)) {
            this.buffer.currentAttr.fg = this.colorMap[byte];
            return;
        }

        switch (byte) {
            case 13: // CR
                this.buffer.writeChar('\n');
                return;
            case 14: // switch to lower/upper case character set
                this.charsetMode = 'lower_upper';
                return;
            case 10: // LF
                return;
            case 17: // cursor down
                this.buffer.cursorRow++;
                this.buffer.ensureRows(this.buffer.cursorRow + 1);
                this.buffer.clampCursor();
                return;
            case 18: // reverse on
                this.reverse = true;
                return;
            case 19: // home
                this.buffer.cursorRow = 0;
                this.buffer.cursorCol = 0;
                return;
            case 20: // delete
                if (this.buffer.cursorCol > 0) {
                    this.buffer.cursorCol--;
                    this.buffer.buffer[this.buffer.cursorRow][this.buffer.cursorCol] = { ...this.buffer.defaultCell };
                }
                return;
            case 29: // cursor right
                this.buffer.cursorCol++;
                this.buffer.clampCursor();
                return;
            case 141: // SHIFT+RETURN — display newline (advance to next line, col 0)
                this.buffer.cursorCol = 0;
                this.buffer.cursorRow++;
                this.buffer.ensureRows(this.buffer.cursorRow + 1);
                return;
            case 145: // cursor up
                this.buffer.cursorRow = Math.max(0, this.buffer.cursorRow - 1);
                return;
            case 142: // switch to upper/graphics character set
                this.charsetMode = 'upper_graphics';
                return;
            case 146: // reverse off
                this.reverse = false;
                return;
            case 147: // clear / home
                this.buffer.clearScreen(2);
                this.buffer.cursorRow = 0;
                this.buffer.cursorCol = 0;
                return;
            case 157: // cursor left
                this.buffer.cursorCol = Math.max(0, this.buffer.cursorCol - 1);
                return;
            default:
                this.writeMappedByte(byte);
        }
    }

    writeMappedByte(byte) {
        const attr = { ...this.buffer.currentAttr };
        if (this.reverse) {
            const fg = attr.fg;
            attr.fg = attr.bg;
            attr.bg = fg;
            // Don't set attr.reverse — colors are already pre-swapped; the CSS
            // invert class would double-invert and cancel the swap out.
        }
        this.buffer.writeChar(this.mapByteToChar(byte), attr);
    }

    mapByteToChar(byte) {
        // PETSCII 0–31: control codes — no displayable glyph.
        if (byte < 32) return ' ';

        // PETSCII 32–63: standard printable ASCII (space through '?') — identical in C64.
        if (byte < 64) return String.fromCharCode(byte);

        // PETSCII 64–127: Use Pet Me 64 PUA (U+E040–U+E07F) for authentic C64 glyphs.
        // This covers @, A–Z, [, £, ], ↑, ← (64–95) and block graphics (96–127).
        // Exception: in lower/upper charset mode bytes 96–127 are lowercase letters;
        // use standard ASCII for those so they remain readable without Pet Me 64.
        if (byte < 128) {
            if (this.charsetMode === 'lower_upper' && byte >= 96) {
                return String.fromCharCode(byte);
            }
            return String.fromCodePoint(0xE000 + byte);
        }

        // PETSCII 128–159: shifted control codes — no displayable glyph.
        if (byte < 160) return ' ';

        // PETSCII 160–255: high-bit graphics and symbols → PUA U+E0A0–U+E0FF.
        return String.fromCodePoint(0xE000 + byte);
    }
}

/**
 * Decoder for C64 screen RAM dumps.
 *
 * C64 screen RAM stores character indices into the current charset, not PETSCII
 * stream codes. The mapping from screen code → display character differs from
 * the PETSCII stream mapping used by ArtPetsciiDecoder:
 *
 *   Screen code  0–31  → PETSCII 64–95  (@, A–Z, [\]↑←)
 *   Screen code 32–63  → PETSCII 32–63  (space, !"#…?)
 *   Screen code 64–127 → PETSCII 96–127 (C64 block graphics), wrapping modulo 64
 *   Screen code 128–255 → same as 0–127 but reverse-video
 *
 * Color RAM (optional) follows screen RAM at offset cols×rows. Each byte is a
 * C64 color index 0–15 mapped to the nearest ANSI color.
 */
class ArtScreenRamDecoder {
    constructor(buffer, cols = 40, rows = 25) {
        this.buffer = buffer;
        this.cols   = cols;
        this.rows   = rows;
    }

    /** Return the C64 color index (0–15) clamped to the valid nybble range. */
    static c64ColorIndex(c64) {
        return c64 & 0x0F;
    }

    /**
     * Map a screen code (0–127, reverse-video stripped) to a display character.
     *
     * For PETSCII 64+ we use Pet Me 64's Private Use Area codepoints
     * (U+E000 + petscii_code) instead of the Unicode approximations.
     * Every PUA glyph is a different (more accurate) glyph than its Unicode
     * equivalent — the PUA contains the authentic C64 ROM pixel patterns.
     */
    static screenCodeToChar(code) {
        let petscii;
        if (code < 32) {
            petscii = code + 64;          // 0–31  → PETSCII 64–95
        } else if (code < 64) {
            petscii = code;               // 32–63 → PETSCII 32–63 (printable ASCII)
        } else {
            petscii = (code & 31) + 96;   // 64–127 → PETSCII 96–127
        }

        // Use Pet Me 64's PUA (U+E000 + petscii) for PETSCII 64+.
        // These contain the exact C64 ROM glyphs (uppercase chars, block graphics,
        // special symbols) rather than generic Unicode approximations.
        if (petscii >= 64) {
            return String.fromCodePoint(0xE000 + petscii);
        }

        // PETSCII 32–63 are standard ASCII — use directly.
        return String.fromCharCode(petscii);
    }

    decode(data) {
        const bytes = (data instanceof Uint8Array)
            ? data
            : (function() {
                const a = new Uint8Array(data.length);
                for (let i = 0; i < data.length; i++) a[i] = data.charCodeAt(i) & 0xff;
                return a;
            })();

        const screenSize = this.cols * this.rows;
        const hasColorRam = bytes.length > screenSize;

        for (let i = 0; i < Math.min(bytes.length, screenSize); i++) {
            const col = i % this.cols;
            const row = Math.floor(i / this.cols);
            let   code    = bytes[i];
            let   reverse = false;

            if (code >= 128) { code -= 128; reverse = true; }

            const char = ArtScreenRamDecoder.screenCodeToChar(code);

            let fg = 14; // default: C64 light blue
            let bg = 0;  // default: black

            if (hasColorRam) {
                const colorByte = bytes[screenSize + i] ?? 14;
                fg = ArtScreenRamDecoder.c64ColorIndex(colorByte);
            }

            if (reverse) { const t = fg; fg = bg; bg = t; }

            this.buffer.buffer[row][col] = {
                char, fg, bg,
                bold: false, dim: false, italic: false,
                underline: false, blink: false, reverse: false,
            };
            if (row > this.buffer.maxRowUsed) this.buffer.maxRowUsed = row;
        }
    }
}

/**
 * Render a C64 screen RAM dump (with optional color RAM) to HTML spans.
 * data: byte string or Uint8Array — first cols×rows bytes are screen codes,
 *       next cols×rows bytes (if present) are color RAM values.
 */
function renderScreenRamBuffer(data, cols = 40, rows = 25) {
    const buffer  = new ArtScreenBuffer(cols, rows);
    const decoder = new ArtScreenRamDecoder(buffer, cols, rows);
    const renderer = new C64HtmlRenderer();
    decoder.decode(data);
    return renderer.render(buffer);
}

/**
 * Render C64 screen RAM + color RAM to an HTML5 canvas element.
 *
 * Each character cell is drawn at 2× scale (16×16 canvas pixels) and
 * hard-clipped to its cell bounds. This prevents glyph overflow when a
 * fallback font has different metrics, giving consistent grid alignment
 * regardless of which font is ultimately used to render each character.
 *
 * Set canvas.style.imageRendering = 'pixelated' on the returned element
 * when scaling it up with CSS to keep pixels sharp.
 *
 * @param {Uint8Array} uBytes  Screen RAM followed by optional color RAM.
 * @param {number}     cols    Characters per row (default 40).
 * @param {number}     rows    Number of rows (default 25).
 * @returns {HTMLCanvasElement}
 */
/**
 * Render C64 screen RAM + color RAM to an HTML5 canvas element.
 *
 * Rendered at 1:1 scale (8×8 canvas pixels per character cell) so that Pet Me 64's
 * 8-unit-per-row glyph grid maps exactly to one pixel per row, avoiding the
 * sub-pixel blur produced by rendering at larger point sizes. CSS scaling
 * (image-rendering: pixelated) is used to enlarge the result without blurring.
 *
 * @param {Uint8Array} uBytes  Screen RAM followed by optional color RAM.
 * @param {number}     cols    Characters per row (default 40).
 * @param {number}     rows    Number of rows (default 25).
 * @returns {HTMLCanvasElement}
 */
function renderPrgToCanvas(uBytes, cols = 40, rows = 25) {
    // Render at native 8×8 per char so Pet Me 64's pixel rows align 1:1.
    // CSS will scale up with image-rendering:pixelated.
    const charW = 8;
    const charH = 8;

    const canvas = document.createElement('canvas');
    canvas.width  = cols * charW;
    canvas.height = rows * charH;
    const ctx = canvas.getContext('2d');

    const palette = [
        '#000000', '#ffffff', '#880000', '#aaffee',
        '#cc44cc', '#00cc55', '#0000aa', '#eeee77',
        '#dd8855', '#664400', '#ff7777', '#333333',
        '#777777', '#aaff66', '#0088ff', '#bbbbbb',
    ];

    const screenSize = cols * rows;
    ctx.font = `${charH}px 'Pet Me 64', Consolas, 'DejaVu Sans Mono', monospace`;
    ctx.textBaseline = 'top';

    for (let i = 0; i < Math.min(uBytes.length, screenSize); i++) {
        const col = i % cols;
        const row = Math.floor(i / cols);
        let code = uBytes[i];
        let reverse = false;
        if (code >= 128) { code -= 128; reverse = true; }

        const char = ArtScreenRamDecoder.screenCodeToChar(code);
        let fgIdx = 14; // default: C64 light blue
        let bgIdx = 0;  // default: black
        if (uBytes.length > screenSize) {
            fgIdx = uBytes[screenSize + i] & 0x0F;
        }
        if (reverse) { const t = fgIdx; fgIdx = bgIdx; bgIdx = t; }

        const x = col * charW;
        const y = row * charH;

        ctx.fillStyle = palette[bgIdx];
        ctx.fillRect(x, y, charW, charH);

        ctx.save();
        ctx.beginPath();
        ctx.rect(x, y, charW, charH);
        ctx.clip();
        ctx.fillStyle = palette[fgIdx];
        ctx.fillText(char, x, y);
        ctx.restore();
    }

    return canvas;
}

/**
 * Scan a byte array for an embedded screen RAM + color RAM block.
 *
 * Heuristic: find 1000 consecutive bytes where every value is 0–15
 * (valid C64 color nybbles). The 1000 bytes immediately before that
 * block are treated as screen codes. Machine code opcodes are almost
 * always > 15, so false positives are very unlikely.
 *
 * Returns the byte offset where screen data starts, or -1 if not found.
 */
function findScreenRamOffset(bytes) {
    const screenSize = 40 * 25; // 1000
    if (bytes.length < screenSize * 2) return -1;

    for (let p = 0; p <= bytes.length - screenSize * 2; p++) {
        let allLow = true;
        for (let i = 0; i < screenSize; i++) {
            if (bytes[p + screenSize + i] > 15) { allLow = false; break; }
        }
        if (allLow) return p;
    }
    return -1;
}

function renderAnsiBuffer(text, cols = 80, rows = 500, rendererMode = null, renderOptions = null) {
    const buffer = new ArtScreenBuffer(cols, rows);
    const decoder = new ArtAnsiDecoder(buffer);
    const effectiveRendererMode = rendererMode || window.siteConfig?.ansiRendererMode || 'grouped';
    const renderer = (effectiveRendererMode === 'perchar')
        ? new ArtHtmlRendererPerChar()
        : new ArtHtmlRenderer();
    decoder.decode(text);
    return renderer.render(buffer, renderOptions || {});
}


function renderAmigaAnsiBuffer(text, cols = 80, rows = 500) {
    const buffer = new ArtScreenBuffer(cols, rows);
    const decoder = new ArtAmigaAnsiDecoder(buffer);
    const renderer = new ArtHtmlRenderer();
    decoder.decode(text);
    return renderer.render(buffer);
}

function renderPetsciiBuffer(input, cols = 40, rows = 500) {
    const buffer = new ArtScreenBuffer(cols, rows);
    buffer.currentAttr.fg = 14; // C64 default: light blue
    buffer.currentAttr.bg = 0;  // C64 default: black
    const decoder = new ArtPetsciiDecoder(buffer);
    const renderer = new C64HtmlRenderer();
    decoder.decode(input);
    return renderer.render(buffer);
}

function byteStringFromBase64(base64Text) {
    if (!base64Text) {
        return '';
    }

    try {
        return atob(base64Text);
    } catch (error) {
        return '';
    }
}

function resolveArtSource(options = {}) {
    if (options.byteString) {
        return options.byteString;
    }

    if (options.bytesBase64) {
        const decoded = byteStringFromBase64(options.bytesBase64);
        if (decoded) {
            return decoded;
        }
    }

    return options.text || '';
}

function normalizeArtFormat(format) {
    const normalized = String(format || 'auto').toLowerCase();
    if (normalized === 'amiga' || normalized === 'amigaansi') {
        return 'amiga_ansi';
    }
    if (normalized === 'petscii') {
        return 'petscii';
    }
    if (normalized === 'ansi') {
        return 'ansi';
    }
    return 'auto';
}

function getArtProfileClass(format) {
    const normalized = normalizeArtFormat(format);
    if (normalized === 'amiga_ansi') {
        return 'art-format-amiga-ansi';
    }
    if (normalized === 'petscii') {
        return 'art-format-petscii';
    }
    return 'art-format-ansi';
}

function renderArtMessage(text, options = {}) {
    const format = normalizeArtFormat(options.format || 'auto');
    let sourceText = options.byteString || text || '';
    const rendererMode = options.rendererMode || null;

    if (format !== 'petscii' && hasPipeCodes(sourceText)) {
        sourceText = convertPipeCodesToAnsi(sourceText);
    }

    if (format === 'amiga_ansi') {
        return renderAmigaAnsiBuffer(sourceText, options.cols || 80, options.rows || 500);
    }

    if (format === 'petscii') {
        const sourceBytes = options.bytesBase64 ? byteStringFromBase64(options.bytesBase64) : sourceText;
        return renderPetsciiBuffer(sourceBytes, options.cols || 40, options.rows || 500);
    }

    if (format === 'ansi' || format === 'auto' || !format) {
        return renderAnsiBuffer(sourceText, options.cols || 80, options.rows || 500, rendererMode);
    }

    return renderAnsiBuffer(sourceText, options.cols || 80, options.rows || 500, rendererMode);
}

function stripNonSgrAnsi(text) {
    if (!text) {
        return text;
    }

    let stripped = text.replace(/\x1b\[[0-9;]*[ABCDEFGHJKSTfnsu]/g, '');
    stripped = stripped.replace(/\x1b[^\[]/g, '');
    stripped = stripped.replace(/\x1b\][^\x07]*\x07/g, '');
    stripped = stripped.replace(/\x1b\][^\x1b]*\x1b\\/g, '');
    return stripped;
}

function stripAllAnsi(text) {
    if (!text) {
        return text;
    }

    let stripped = stripNonSgrAnsi(text);
    stripped = stripped.replace(/\x1b\[[0-9;?]*m/g, '');
    stripped = stripped.replace(/\x1b\[[0-9;?]*[A-Za-z]/g, '');
    stripped = stripped.replace(/\x1b./g, '');
    return stripped;
}

function renderAnsiSgrOnly(text, cols = 80, rows = 500) {
    const stripped = stripNonSgrAnsi(text);
    return renderAnsiBuffer(stripped, cols, rows);
}

function stripSauce(text) {
    if (!text) {
        return text;
    }

    const sauceIndex = text.lastIndexOf('SAUCE00');
    if (sauceIndex === -1) {
        return text;
    }

    const trailerLength = text.length - sauceIndex;
    if (trailerLength > 4096) {
        return text;
    }

    return text.slice(0, sauceIndex).replace(/[\x1a\r\n ]+$/, '');
}

/**
 * Render ANSI text using terminal emulation
 * Falls back to simple parsing for non-ANSI text
 * Also handles pipe codes (BBS color codes)
 */
function renderAnsiTerminal(text, cols = 80, rows = 500) {
    if (!text) return '';

    text = stripSauce(text);

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

    return renderArtMessage(text, { format: 'ansi', cols, rows });
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

    text = stripSauce(text);

    // Check if ANSI parsing is enabled in user settings (default: true)
    if (window.userSettings?.ansi_parsing === false) {
        return escapeHtml(text);
    }

    const hasPipes = hasPipeCodes(text);
    const hasAnsi = hasAnsiCodes(text);

    if (!hasPipes && !hasAnsi) {
        if (looksLikeAnsiArtText(text)) {
            return renderAnsiSgrOnly(text);
        }
        return escapeHtml(text);
    }

    // Convert pipe codes to ANSI first if present
    if (hasPipes) {
        text = convertPipeCodesToAnsi(text);
    }

    return renderAnsiSgrOnly(text);
}

function looksLikeAnsiArtText(text) {
    if (!text) {
        return false;
    }

    const lines = text.split(/\r\n|\r|\n/);
    const nonEmptyLines = lines.filter(line => line.trim() !== '').length;
    if (nonEmptyLines < 3) {
        return false;
    }

    const hasUnicodeBoxChars = /[\u2500-\u257F\u2580-\u259F]/.test(text);
    const hasHighBitChars = /[\u0080-\u00FF]/.test(text);
    const maxLineLength = lines.reduce((max, line) => Math.max(max, line.length), 0);

    return (hasUnicodeBoxChars || hasHighBitChars) && maxLineLength >= 20;
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
    if (!text) {
        return false;
    }

    const normalized = String(text).replace(/\|\|/g, '');
    // Match single-digit shorthand (|1), two-digit color codes (|01, |0A, |1F),
    // or special letter codes (|CL, |PA, etc.).
    // Requires uppercase letters for both hex and letter codes — all real BBS software
    // produces uppercase pipe codes, and the case-insensitive version causes false positives
    // on natural English text (e.g. |Advertise → |Ad matches as a Mystic hex color code).
    return /\|(?:[0-9](?![0-9A-Fa-f])|[0-9A-F]{2}(?![0-9A-Fa-f])|[A-Z]{2}(?![A-Z]))/.test(normalized);
}

/**
 * Convert pipe codes to ANSI escape sequences
 * This allows pipe codes to be processed through the existing ANSI parser
 * Also strips special pipe codes (|CL, |PA, etc.) that don't make sense in web context
 */
function convertPipeCodesToAnsi(text) {
    if (!text) return text;

    text = text.replace(/\|\|/g, '\x00DOUBLEPIPE\x00');

    // Handle |PI first: Mystic BBS escape for a literal pipe character
    text = text.replace(/\|PI/gi, '\x00PIPE\x00');

    // Handle |CD: Mystic BBS "reset color to default" → ANSI reset
    text = text.replace(/\|CD/gi, '\x1b[0m');

    // Convert Mystic BBS cursor/screen control codes to ANSI escape sequences.
    // The ANSI parser (AnsiScreen) handles these natively; when the simpler
    // colour-only path is used they are stripped harmlessly by that path.
    text = text.replace(/\|\[([ABCD])(\d{1,3})/gi, (m, dir, n) => `\x1b[${n}${dir.toUpperCase()}`); // cursor up/down/right/left
    text = text.replace(/\|\[X(\d{1,3})/gi,        (m, n) => `\x1b[${n}G`);    // cursor to column (horizontal absolute)
    text = text.replace(/\|\[Y(\d{1,3})/gi,        (m, n) => `\x1b[${n};1H`);  // cursor to row (position to row, col 1)
    text = text.replace(/\|\[K/gi,                  '\x1b[K');                  // clear to end of line
    // Hide/show cursor (|[0 / |[1) have no meaningful equivalent in the web viewer — strip them
    text = text.replace(/\|\[[01]/g, '');

    // Strip only known letter-based control and information codes.
    // Unknown codes should be preserved verbatim.
    const knownPipeCodes = [
        'CL', 'PA', 'PO', 'NL', 'CR', 'BS', 'BE', 'LF', 'FF',
        'UN', 'TI', 'DA', 'DN', 'LD', 'RD', 'LT', 'RT',
        'KP', 'KR', 'KS', 'KT', 'KU', 'KD',
        'GE', 'GV', 'GL', 'GR', 'GN', 'GO'
    ];
    text = text.replace(/\|([A-Z]{2})/g, (match, code) => {
        return knownPipeCodes.includes(code) ? '' : match;
    });

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

    // Replace pipe color codes with ANSI escape sequences.
    // Codes use Renegade-style decimal notation: |00-|15 = foreground, |16-|23 = background.
    // Mystic-style hex codes (|0A = bright green, |1F = blue bg + white fg, etc.) are also
    // handled: codes with letters A-F are parsed as hex nibbles.
    text = text.replace(/\|([0-9](?![0-9A-Fa-f])|[0-9A-F]{2}(?![0-9A-Fa-f]))/g, (match, codeStr) => {
        if (codeStr.length === 1) {
            const ansiFg = pipeToAnsiFg[parseInt(codeStr, 10)] || 37;
            return `\x1b[${ansiFg}m`;
        }

        // Detect Mystic-style hex encoding: code contains a letter (A-F)
        const isMysticHex = /[A-Fa-f]/.test(codeStr);

        if (isMysticHex) {
            // Mystic format: |XY = upper nibble X is background (0-F), lower nibble Y is foreground (0-F)
            const hi = parseInt(codeStr[0], 16);
            const lo = parseInt(codeStr[1], 16);
            const ansiFg = pipeToAnsiFg[lo] || 37;
            const ansiBg = pipeToAnsiBg[hi] || 40;
            // Only emit background if non-zero (hi > 0), so |0F = just bright white fg
            if (hi > 0) {
                return `\x1b[${ansiBg};${ansiFg}m`;
            }
            return `\x1b[${ansiFg}m`;
        }

        // Renegade-style decimal: |00-|15 = foreground, |16-|23 = background
        const code = parseInt(codeStr, 10);
        if (code <= 15) {
            const ansiFg = pipeToAnsiFg[code] || 37;
            return `\x1b[${ansiFg}m`;
        } else if (code >= 16 && code <= 23) {
            const bg = code - 16;
            const ansiBg = pipeToAnsiBg[bg] || 40;
            return `\x1b[${ansiBg}m`;
        }
        // Codes above 23 with no letters — no standard meaning, strip
        return match;
    });

    // Strip Mystic theme color codes |T0-|T9 (theme-dependent, can't render without theme context)
    text = text.replace(/\|T[0-9]/gi, '');

    // Restore escaped pipe characters
    text = text.replace(/\x00DOUBLEPIPE\x00/g, '||');
    text = text.replace(/\x00PIPE\x00/g, '|');

    return text;
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

    text = text.replace(/\|\|/g, '\x00DOUBLEPIPE\x00');

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

    // Pipe code pattern: |XX where XX is hex digits (uppercase only to avoid false positives)
    const pipePattern = /\|([0-9](?![0-9A-Fa-f])|[0-9A-F]{2}(?![0-9A-Fa-f]))/g;
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

        if (match[1].length === 1) {
            currentFg = parseInt(match[1], 10);
        } else {
            // Parse the pipe code as decimal (Renegade style)
            const code = parseInt(match[1], 10);

            if (code <= 15) {
                // Codes 00-15: foreground color 0-15
                currentFg = code;
            } else if (code >= 16 && code <= 23) {
                // Codes 16-23: background color 0-7 (code - 16)
                currentBg = code - 16;
            }
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

    return result
        .replace(/\x00DOUBLEPIPE\x00/g, '||')
        .replace(/\x00PIPE\x00/g, '|');
}

/**
 * Auto-detect and parse both ANSI and pipe codes
 * Tries to intelligently detect which format is used
 * This is an alias for renderAnsiTerminal which now handles both formats
 */
function parseColorCodes(text) {
    return renderAnsiTerminal(text);
}

window.ArtScreenBuffer = ArtScreenBuffer;
window.ArtHtmlRenderer = ArtHtmlRenderer;
window.ArtAnsiDecoder = ArtAnsiDecoder;
window.ArtAmigaAnsiDecoder = ArtAmigaAnsiDecoder;
window.ArtPetsciiDecoder = ArtPetsciiDecoder;
window.renderArtMessage = renderArtMessage;
window.byteStringFromBase64 = byteStringFromBase64;
window.normalizeArtFormat = normalizeArtFormat;
window.getArtProfileClass = getArtProfileClass;
window.stripAllAnsi = stripAllAnsi;
window.renderAnsiTerminal = renderAnsiTerminal;
window.parseAnsi = parseAnsi;
window.parsePipeCodes = parsePipeCodes;
window.parseColorCodes = parseColorCodes;
window.hasAnsiCodes = hasAnsiCodes;
window.hasPipeCodes = hasPipeCodes;
window.convertPipeCodesToAnsi = convertPipeCodesToAnsi;
