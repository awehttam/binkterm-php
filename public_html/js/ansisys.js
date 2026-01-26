/**
 * ANSI Terminal Renderer
 * Renders ANSI escape sequences to HTML by emulating a terminal buffer
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
    render() {
        let html = '';
        let currentClasses = [];
        let spanOpen = false;

        // Only render up to the last used row (plus some margin for content)
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
                const classes = [];

                // Build class list
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

                // Check if we need to change span
                const classStr = classes.sort().join(' ');
                const currentClassStr = currentClasses.sort().join(' ');

                if (classStr !== currentClassStr) {
                    if (spanOpen) {
                        html += '</span>';
                        spanOpen = false;
                    }
                    if (classes.length > 0) {
                        html += `<span class="${classStr}">`;
                        spanOpen = true;
                    }
                    currentClasses = [...classes];
                }

                // Escape and add character
                html += this.escapeChar(cell.char);
            }

            // End of row
            if (r < rowsToRender - 1) {
                html += '\n';
            }
        }

        if (spanOpen) {
            html += '</span>';
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
 */
function renderAnsiTerminal(text, cols = 80, rows = 500) {
    if (!text) return '';

    // Check if ANSI parsing is enabled
    if (window.userSettings?.ansi_parsing === false) {
        return escapeHtml(text);
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
