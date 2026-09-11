<?php

namespace BinktermPHP\TelnetServer;

/**
 * Renders an ANSI-art byte stream onto a virtual character canvas and returns
 * the result as an array of plain display lines that carry SGR colour codes
 * only -- no cursor positioning.
 *
 * The Telnet/SSH message reader draws body lines inside a scroll region with its
 * own header box and status bar. Art that positions its pieces with absolute
 * cursor moves (`ESC[row;colH`, `ESC[s`/`ESC[u`, ...) cannot be handed to that
 * reader verbatim -- the moves land against the real screen and fight the
 * reader's layout. This renderer resolves every cursor movement against an
 * off-screen grid of cells, then serialises each used row back to text. The
 * reader then treats the art like any other coloured, wrapped message: it
 * scrolls, repaints and resizes normally, and no control code reaches the
 * terminal except SGR.
 *
 * Ported from the browser-side `AnsiTerminal` in `public_html/js/ansisys.js`.
 * Input is expected to have already passed through
 * {@see \BinktermPHP\TerminalTextSanitizer} with the positioning policy, so
 * OSC/DCS/answerback and other injection vectors are gone before it gets here.
 */
class AnsiCanvasRenderer
{
    /** Hard cap on canvas height, so a crafted body cannot allocate forever. */
    private const MAX_ROWS = 1000;

    /** @var array<int, array<int, array{ch:string,fg:int,bg:int,bold:bool,blink:bool,reverse:bool,underline:bool}>> */
    private array $grid = [];

    private int $cols;
    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private int $savedRow = 0;
    private int $savedCol = 0;
    private int $maxRow = 0;

    private int $fg = 7;
    private int $bg = 0;
    private bool $bold = false;
    private bool $blink = false;
    private bool $reverse = false;
    private bool $underline = false;

    private function __construct(int $cols)
    {
        $this->cols = max(16, $cols);
        $this->grid[0] = $this->emptyRow();
    }

    /**
     * Render $ansi onto a $width-column canvas and return the used rows as
     * strings (UTF-8, SGR codes only). Trailing blank cells are trimmed; a row
     * that ends coloured keeps its background run.
     *
     * @return string[] Never empty.
     */
    public static function render(string $ansi, int $width): array
    {
        $r = new self($width);
        $r->process($ansi);
        return $r->serialize();
    }

    /** @return array<int, array{ch:string,fg:int,bg:int,bold:bool,blink:bool,reverse:bool,underline:bool}> */
    private function emptyRow(): array
    {
        $row = [];
        $blank = ['ch' => ' ', 'fg' => 7, 'bg' => 0, 'bold' => false, 'blink' => false, 'reverse' => false, 'underline' => false];
        for ($c = 0; $c < $this->cols; $c++) {
            $row[$c] = $blank;
        }
        return $row;
    }

    private function ensureRow(int $row): void
    {
        for ($r = count($this->grid); $r <= $row && $r < self::MAX_ROWS; $r++) {
            $this->grid[$r] = $this->emptyRow();
        }
    }

    private function clampCursor(): void
    {
        if ($this->cursorRow < 0) {
            $this->cursorRow = 0;
        }
        if ($this->cursorRow >= self::MAX_ROWS) {
            $this->cursorRow = self::MAX_ROWS - 1;
        }
        if ($this->cursorCol < 0) {
            $this->cursorCol = 0;
        }
        if ($this->cursorCol >= $this->cols) {
            $this->cursorCol = $this->cols - 1;
        }
    }

    private function process(string $text): void
    {
        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            $ch = $text[$i];

            if ($ch === "\x1b" && $i + 1 < $len && $text[$i + 1] === '[') {
                $i += 2;
                $params = '';
                while ($i < $len && strpos('0123456789;:<>=?', $text[$i]) !== false) {
                    $params .= $text[$i];
                    $i++;
                }
                // Intermediate bytes (space through /).
                while ($i < $len && ord($text[$i]) >= 0x20 && ord($text[$i]) <= 0x2F) {
                    $i++;
                }
                $cmd = $i < $len ? $text[$i] : '';
                $i++;
                $this->handleCsi($cmd, $this->parseParams($params));
                continue;
            }

            if ($ch === "\x1b") {
                // Any other escape: skip introducer + one byte.
                $i += ($i + 1 < $len) ? 2 : 1;
                continue;
            }

            // Decode one UTF-8 character as a single cell.
            $o = ord($ch);
            $clen = $o >= 0xF0 ? 4 : ($o >= 0xE0 ? 3 : ($o >= 0xC0 ? 2 : 1));
            if ($clen > 1 && $i + $clen > $len) {
                $clen = 1;
            }
            $this->writeChar(substr($text, $i, $clen));
            $i += $clen;
        }
    }

    /** @return int[] */
    private function parseParams(string $params): array
    {
        if ($params === '') {
            return [];
        }
        $params = ltrim($params, '<>=?');
        $out = [];
        foreach (explode(';', $params) as $p) {
            // Sub-parameters (38:2:...) are not supported here; take the head.
            $p = strtok($p, ':');
            $out[] = ($p === false || $p === '') ? 0 : (int)$p;
        }
        return $out;
    }

    /** @param int[] $p */
    private function handleCsi(string $cmd, array $p): void
    {
        $n = ($p[0] ?? 0) > 0 ? $p[0] : 1;

        switch ($cmd) {
            case 'A': $this->cursorRow -= $n; $this->clampCursor(); break;
            case 'B': $this->cursorRow += $n; $this->ensureRow($this->cursorRow); $this->clampCursor(); break;
            case 'C': $this->cursorCol += $n; $this->clampCursor(); break;
            case 'D': $this->cursorCol -= $n; $this->clampCursor(); break;
            case 'E': $this->cursorRow += $n; $this->cursorCol = 0; $this->ensureRow($this->cursorRow); $this->clampCursor(); break;
            case 'F': $this->cursorRow -= $n; $this->cursorCol = 0; $this->clampCursor(); break;
            case 'G': $this->cursorCol = (($p[0] ?? 1) ?: 1) - 1; $this->clampCursor(); break;
            case 'd': $this->cursorRow = (($p[0] ?? 1) ?: 1) - 1; $this->ensureRow($this->cursorRow); $this->clampCursor(); break;
            case 'H':
            case 'f':
                $this->cursorRow = (($p[0] ?? 1) ?: 1) - 1;
                $this->cursorCol = (($p[1] ?? 1) ?: 1) - 1;
                $this->ensureRow($this->cursorRow);
                $this->clampCursor();
                break;
            case 'J': $this->eraseDisplay($p[0] ?? 0); break;
            case 'K': $this->eraseLine($p[0] ?? 0); break;
            case 'm': $this->applySgr($p); break;
            case 's': $this->savedRow = $this->cursorRow; $this->savedCol = $this->cursorCol; break;
            case 'u': $this->cursorRow = $this->savedRow; $this->cursorCol = $this->savedCol; $this->ensureRow($this->cursorRow); $this->clampCursor(); break;
            // S/T (scroll) and h/l (modes) and everything else: consumed, ignored.
        }
    }

    private function writeChar(string $ch): void
    {
        if ($ch === "\n") {
            $this->cursorRow++;
            $this->cursorCol = 0;
            $this->ensureRow($this->cursorRow);
            $this->clampCursor();
            $this->maxRow = max($this->maxRow, $this->cursorRow);
            return;
        }
        if ($ch === "\r") {
            $this->cursorCol = 0;
            return;
        }
        if ($ch === "\t") {
            $this->cursorCol = min((int)($this->cursorCol / 8) * 8 + 8, $this->cols - 1);
            return;
        }
        if ($ch === "\x08") {
            if ($this->cursorCol > 0) {
                $this->cursorCol--;
            }
            return;
        }
        if ($ch < ' ' && strlen($ch) === 1) {
            return; // other C0 controls: nothing to draw
        }

        $this->ensureRow($this->cursorRow);
        if (!isset($this->grid[$this->cursorRow])) {
            return; // hit MAX_ROWS
        }

        $this->grid[$this->cursorRow][$this->cursorCol] = [
            'ch'        => $ch,
            'fg'        => $this->fg,
            'bg'        => $this->bg,
            'bold'      => $this->bold,
            'blink'     => $this->blink,
            'reverse'   => $this->reverse,
            'underline' => $this->underline,
        ];
        $this->maxRow = max($this->maxRow, $this->cursorRow);

        $this->cursorCol++;
        if ($this->cursorCol >= $this->cols) {
            $this->cursorCol = 0;
            $this->cursorRow++;
            $this->ensureRow($this->cursorRow);
            $this->clampCursor();
            $this->maxRow = max($this->maxRow, $this->cursorRow);
        }
    }

    private function eraseDisplay(int $mode): void
    {
        $blank = $this->emptyRow();
        if ($mode === 0) {
            for ($c = $this->cursorCol; $c < $this->cols; $c++) {
                $this->grid[$this->cursorRow][$c] = $blank[$c];
            }
            for ($r = $this->cursorRow + 1; $r < count($this->grid); $r++) {
                $this->grid[$r] = $this->emptyRow();
            }
        } elseif ($mode === 1) {
            for ($r = 0; $r < $this->cursorRow; $r++) {
                $this->grid[$r] = $this->emptyRow();
            }
            for ($c = 0; $c <= $this->cursorCol && $c < $this->cols; $c++) {
                $this->grid[$this->cursorRow][$c] = $blank[$c];
            }
        } else { // 2 / 3
            foreach (array_keys($this->grid) as $r) {
                $this->grid[$r] = $this->emptyRow();
            }
            $this->cursorRow = 0;
            $this->cursorCol = 0;
        }
    }

    private function eraseLine(int $mode): void
    {
        if (!isset($this->grid[$this->cursorRow])) {
            return;
        }
        $blank = $this->emptyRow();
        if ($mode === 0) {
            for ($c = $this->cursorCol; $c < $this->cols; $c++) {
                $this->grid[$this->cursorRow][$c] = $blank[$c];
            }
        } elseif ($mode === 1) {
            for ($c = 0; $c <= $this->cursorCol && $c < $this->cols; $c++) {
                $this->grid[$this->cursorRow][$c] = $blank[$c];
            }
        } else {
            $this->grid[$this->cursorRow] = $this->emptyRow();
        }
    }

    /** @param int[] $params */
    private function applySgr(array $params): void
    {
        if ($params === []) {
            $params = [0];
        }
        for ($i = 0; $i < count($params); $i++) {
            $code = $params[$i];
            if ($code === 0) {
                $this->fg = 7; $this->bg = 0;
                $this->bold = $this->blink = $this->reverse = $this->underline = false;
            } elseif ($code === 1) {
                $this->bold = true;
            } elseif ($code === 4) {
                $this->underline = true;
            } elseif ($code === 5 || $code === 6) {
                $this->blink = true;
            } elseif ($code === 7) {
                $this->reverse = true;
            } elseif ($code === 22) {
                $this->bold = false;
            } elseif ($code === 24) {
                $this->underline = false;
            } elseif ($code === 25) {
                $this->blink = false;
            } elseif ($code === 27) {
                $this->reverse = false;
            } elseif ($code >= 30 && $code <= 37) {
                $this->fg = $code - 30;
            } elseif ($code === 38) {
                if (($params[$i + 1] ?? null) === 5 && isset($params[$i + 2])) {
                    $this->fg = $params[$i + 2];
                    $i += 2;
                } elseif (($params[$i + 1] ?? null) === 2 && isset($params[$i + 4])) {
                    $this->fg = 7;
                    $i += 4;
                }
            } elseif ($code === 39) {
                $this->fg = 7;
            } elseif ($code >= 40 && $code <= 47) {
                $this->bg = $code - 40;
            } elseif ($code === 48) {
                if (($params[$i + 1] ?? null) === 5 && isset($params[$i + 2])) {
                    $this->bg = $params[$i + 2];
                    $i += 2;
                } elseif (($params[$i + 1] ?? null) === 2 && isset($params[$i + 4])) {
                    $this->bg = 0;
                    $i += 4;
                }
            } elseif ($code === 49) {
                $this->bg = 0;
            } elseif ($code >= 90 && $code <= 97) {
                $this->fg = $code - 90 + 8;
            } elseif ($code >= 100 && $code <= 107) {
                $this->bg = $code - 100 + 8;
            }
        }
    }

    /**
     * Serialise the used rows. Each row emits SGR codes as the attribute run
     * changes and a reset at end of line; trailing default cells are trimmed.
     *
     * @return string[]
     */
    private function serialize(): array
    {
        $rows = min(count($this->grid), $this->maxRow + 1);
        $out = [];

        for ($r = 0; $r < $rows; $r++) {
            $row = $this->grid[$r];

            $last = -1;
            for ($c = $this->cols - 1; $c >= 0; $c--) {
                if ($row[$c]['ch'] !== ' ' || $row[$c]['bg'] !== 0 || $row[$c]['reverse']) {
                    $last = $c;
                    break;
                }
            }

            if ($last < 0) {
                $out[] = '';
                continue;
            }

            $line = '';
            $curSig = null;
            for ($c = 0; $c <= $last; $c++) {
                $cell = $row[$c];
                $sig = $cell['fg'] . ':' . $cell['bg'] . ':' . ($cell['bold'] ? 1 : 0)
                     . ($cell['blink'] ? 1 : 0) . ($cell['reverse'] ? 1 : 0) . ($cell['underline'] ? 1 : 0);
                if ($sig !== $curSig) {
                    $line .= self::sgrFor($cell);
                    $curSig = $sig;
                }
                $line .= $cell['ch'];
            }
            $line .= "\x1b[0m";
            $out[] = $line;
        }

        // Drop trailing blank rows (e.g. one left by a final wrap), keep interior spacing.
        while (count($out) > 1 && end($out) === '') {
            array_pop($out);
        }

        if ($out === []) {
            $out[] = '';
        }
        return $out;
    }

    /** @param array{fg:int,bg:int,bold:bool,blink:bool,reverse:bool,underline:bool} $cell */
    private static function sgrFor(array $cell): string
    {
        $codes = ['0'];
        if ($cell['bold']) {
            $codes[] = '1';
        }
        if ($cell['underline']) {
            $codes[] = '4';
        }
        if ($cell['blink']) {
            $codes[] = '5';
        }
        if ($cell['reverse']) {
            $codes[] = '7';
        }

        $fg = $cell['fg'];
        if ($fg >= 0 && $fg <= 7) {
            $codes[] = (string)(30 + $fg);
        } elseif ($fg >= 8 && $fg <= 15) {
            $codes[] = (string)(90 + $fg - 8);
        } elseif ($fg >= 16) {
            $codes[] = '38';
            $codes[] = '5';
            $codes[] = (string)$fg;
        }

        $bg = $cell['bg'];
        if ($bg >= 1 && $bg <= 7) {
            $codes[] = (string)(40 + $bg);
        } elseif ($bg >= 8 && $bg <= 15) {
            $codes[] = (string)(100 + $bg - 8);
        } elseif ($bg >= 16) {
            $codes[] = '48';
            $codes[] = '5';
            $codes[] = (string)$bg;
        }

        return "\x1b[" . implode(';', $codes) . 'm';
    }
}
