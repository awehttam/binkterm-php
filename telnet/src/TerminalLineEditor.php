<?php

namespace BinktermPHP\TelnetServer;

/**
 * A single line of editable terminal input, as a pure state machine.
 *
 * It consumes the *normalised key tokens* the session's key readers already
 * produce ({@see BbsSession::readKeyWithTimeout()} /
 * {@see BbsSession::readKeyWithIdleCheck()} — `CHAR:<utf8>`, `BACKSPACE`,
 * `DELETE`, `LEFT`, `RIGHT`, `HOME`, `END`, `ENTER`, `CTRL_C`, `ESC`/`''`) and
 * maintains a UTF-8 codepoint buffer plus a cursor. It performs no I/O, so the
 * whole editing contract is unit-testable without a socket, and it is shared by
 * every line-input surface (`showInputDialog`, `LineShell` prompts, …) so they
 * all edit identically.
 *
 * What it deliberately does NOT do: rendering (the caller owns the widget),
 * reading bytes (the caller owns the reader), history storage
 * ({@see TerminalLineHistory}), or paste framing (the reader/`drainPendingInput`
 * own that). It just answers "given this key, what is the buffer and cursor now,
 * and are we done?".
 */
final class TerminalLineEditor
{
    public const RESULT_CONTINUE = 'continue';
    public const RESULT_SUBMIT   = 'submit';
    public const RESULT_CANCEL   = 'cancel';

    /** @var string[] one entry per grapheme-approx (codepoint) */
    private array $chars;
    private int $cursor;

    public function __construct(
        string $initial = '',
        private readonly int $maxLength = 255,
        private readonly bool $allowCursor = true,
    ) {
        $this->chars  = $initial === '' ? [] : (mb_str_split($initial, 1, 'UTF-8') ?: []);
        if ($this->maxLength > 0 && count($this->chars) > $this->maxLength) {
            $this->chars = array_slice($this->chars, 0, $this->maxLength);
        }
        $this->cursor = count($this->chars);
    }

    public function value(): string
    {
        return implode('', $this->chars);
    }

    /** Cursor position as a codepoint offset from the start of the line. */
    public function cursor(): int
    {
        return $this->cursor;
    }

    public function length(): int
    {
        return count($this->chars);
    }

    /**
     * Replace the whole buffer (history recall). Cursor goes to the end.
     */
    public function setValue(string $value): void
    {
        $this->chars = $value === '' ? [] : (mb_str_split($value, 1, 'UTF-8') ?: []);
        if ($this->maxLength > 0 && count($this->chars) > $this->maxLength) {
            $this->chars = array_slice($this->chars, 0, $this->maxLength);
        }
        $this->cursor = count($this->chars);
    }

    /**
     * Apply one normalised key token.
     *
     * @return self::RESULT_* whether the caller should keep reading, accept the
     *         line, or treat it as cancelled
     */
    public function apply(string $token): string
    {
        if ($token === 'ENTER') {
            return self::RESULT_SUBMIT;
        }
        // A bare ESC normalises to '' in the legacy key reader and to 'ESC' in
        // the key reader; CTRL_C is the universal cancel.
        if ($token === 'ESC' || $token === '' || $token === 'CTRL_C') {
            return self::RESULT_CANCEL;
        }

        if ($token === 'BACKSPACE') {
            if ($this->cursor > 0) {
                array_splice($this->chars, $this->cursor - 1, 1);
                $this->cursor--;
            }
            return self::RESULT_CONTINUE;
        }
        if ($token === 'DELETE') {
            if ($this->cursor < count($this->chars)) {
                array_splice($this->chars, $this->cursor, 1);
            }
            return self::RESULT_CONTINUE;
        }
        if ($this->allowCursor) {
            if ($token === 'LEFT') {
                $this->cursor = max(0, $this->cursor - 1);
                return self::RESULT_CONTINUE;
            }
            if ($token === 'RIGHT') {
                $this->cursor = min(count($this->chars), $this->cursor + 1);
                return self::RESULT_CONTINUE;
            }
            if ($token === 'HOME' || $token === 'CTRL_A') {
                $this->cursor = 0;
                return self::RESULT_CONTINUE;
            }
            if ($token === 'END' || $token === 'CTRL_E') {
                $this->cursor = count($this->chars);
                return self::RESULT_CONTINUE;
            }
        }

        if (str_starts_with($token, 'CHAR:')) {
            $ch = substr($token, 5);
            // Guard against a stray control byte arriving as CHAR: and against a
            // multi-codepoint token (paste bursts still arrive one CHAR: at a
            // time from the reader, but be defensive).
            foreach (mb_str_split($ch, 1, 'UTF-8') ?: [] as $one) {
                if ($one === '' || $this->isControl($one)) {
                    continue;
                }
                if ($this->maxLength > 0 && count($this->chars) >= $this->maxLength) {
                    break;
                }
                array_splice($this->chars, $this->cursor, 0, [$one]);
                $this->cursor++;
            }
            return self::RESULT_CONTINUE;
        }

        // Any other token (UP, DOWN, TAB, PGUP, …) is not line editing — ignore.
        return self::RESULT_CONTINUE;
    }

    private function isControl(string $oneChar): bool
    {
        if (strlen($oneChar) === 1) {
            $o = ord($oneChar);
            return $o < 32 || $o === 127;
        }

        return false;
    }
}
