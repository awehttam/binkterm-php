<?php

namespace BinktermPHP;

/**
 * Sanitizes untrusted text (FTN message bodies, kludge lines, forwarded/quoted
 * content, subjects, author names) before it is rendered to an ANSI/VT terminal
 * over the Telnet, SSH, QWK or packet-BBS surfaces.
 *
 * A message body can arrive from any local user or any upstream FTN node and is
 * displayed more or less verbatim by the terminal read paths. Without filtering,
 * a body containing raw escape sequences can drive the reader's terminal:
 * cursor and screen manipulation, display spoofing, and — on emulators that
 * honour them — OSC title/clipboard writes or answerback/device-status queries
 * that reflect input back into the session.
 *
 * Two policies are supported:
 *
 *  - {@see POLICY_STRIP} (default): a strict whitelist. Only SGR (colour/style)
 *    sequences and TAB/CR/LF survive; every other escape sequence and C0/C1
 *    control byte is removed. This is what the inline message reader uses.
 *  - {@see POLICY_POSITIONING}: additionally preserves a whitelist of cursor
 *    movement and erase sequences so genuine ANSI art renders. OSC, DCS/APC/PM,
 *    private-mode sequences, device-status/answerback queries and C0/C1 bytes
 *    are still removed — the input-injection and clipboard/title vectors stay
 *    closed; only in-screen display spoofing becomes possible again. Used by the
 *    dedicated full-screen ANSI art viewer.
 */
class TerminalTextSanitizer
{
    /** Strict policy: keep SGR colour codes only. */
    public const POLICY_STRIP = 'strip';

    /** Permissive policy: also keep cursor-movement and erase sequences. */
    public const POLICY_POSITIONING = 'positioning';

    /**
     * Well-formed SGR sequence: `ESC [ <params> m`.
     */
    private const SGR_PATTERN = '\x1b\[[0-9;:]*m';

    /**
     * Cursor movement / erase / scroll / save-restore sequences that the
     * positioning policy preserves: final byte in [A-H] (CUU/CUD/CUF/CUB/CNL/
     * CPL/CHA/CUP), J/K (ED/EL), S/T (SU/SD), d (VPA), f (HVP), s/u (SCP/RCP).
     * No private ('?','<','>','=') markers and no intermediate bytes are
     * allowed, so mode changes and device queries never match.
     */
    private const POSITIONING_PATTERN = '\x1b\[[0-9;]*[A-HJKSTdfsu]';

    /**
     * Strip terminal control sequences from untrusted text.
     *
     * The input is expected to be UTF-8 (the canonical storage form for message
     * text); charset conversion to CP437/ASCII happens downstream and does not
     * reintroduce an ESC introducer.
     *
     * @param string $text   Raw untrusted text.
     * @param string $policy One of {@see POLICY_STRIP} or {@see POLICY_POSITIONING}.
     * @return string Text safe to write to a terminal.
     */
    public static function sanitize(string $text, string $policy = self::POLICY_STRIP): string
    {
        if ($text === '') {
            return $text;
        }

        $keep = $policy === self::POLICY_POSITIONING
            ? '/(' . self::SGR_PATTERN . '|' . self::POSITIONING_PATTERN . ')/'
            : '/(' . self::SGR_PATTERN . ')/';

        // Split on the sequences to keep, retaining them as captured delimiters.
        // Odd-indexed parts are the preserved sequences; even-indexed parts are
        // ordinary text that gets fully scrubbed.
        $parts = preg_split($keep, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return self::scrub($text);
        }

        $out = '';
        foreach ($parts as $i => $part) {
            $out .= ($i % 2 === 1) ? $part : self::scrub($part);
        }

        return $out;
    }

    /**
     * Whether $text contains an ANSI sequence that positions the cursor or
     * erases part of the screen (i.e. a CSI sequence whose final byte is a
     * letter other than `m`). Used to decide whether a message body is ANSI
     * art that warrants the dedicated art viewer.
     *
     * Operates on the raw (pre-sanitize) text.
     */
    public static function hasPositionedAnsi(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        // CSI whose final byte is a letter other than 'm' (SGR).
        return (bool)preg_match('/\x1b\[[0-9;<>=?]*[A-Za-ln-z]/', $text);
    }

    /**
     * Remove every escape sequence and disallowed control byte from a fragment
     * that is known to contain no sequences worth keeping.
     */
    private static function scrub(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // OSC (Operating System Command): ESC ] ... (BEL | ST). Window titles,
        // clipboard writes and answerback on permissive emulators.
        $text = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)?/', '', $text);

        // DCS / SOS / PM / APC strings: ESC (P|X|^|_) ... ST.
        $text = preg_replace('/\x1b[PX^_][^\x1b]*(?:\x1b\\\\)?/', '', $text);

        // Any CSI sequence (all non-preserved by construction, plus malformed or
        // unterminated ones): cursor movement, erase, scroll region, mode
        // changes, device-status queries.
        $text = preg_replace('/\x1b\[[0-9;:?<>=]*[ -\/]*[@-~]?/', '', $text);

        // Character-set designation: ESC ( B , ESC ) 0 , ESC * A , ...
        $text = preg_replace('/\x1b[()*+\-.\/][0-9A-Za-z]/', '', $text);

        // Any other two-byte escape (ESC c, ESC 7, ESC =, ...) and stray ESC.
        $text = preg_replace('/\x1b[\x20-\x7e]?/', '', $text);

        // Remaining C0 control bytes except TAB (0x09), LF (0x0A), CR (0x0D),
        // plus DEL (0x7F).
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);

        // UTF-8-encoded C1 control range (U+0080–U+009F) — 0x9B is an alternate
        // CSI introducer on some terminals.
        $text = preg_replace('/\xc2[\x80-\x9f]/', '', $text);

        return $text;
    }
}
