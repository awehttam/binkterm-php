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
 * The policy here is a whitelist: SGR (Select Graphic Rendition) sequences
 * (`ESC [ ... m`) are kept so ANSI colour survives; every other escape
 * sequence and every C0/C1 control byte except TAB, CR and LF is removed.
 */
class TerminalTextSanitizer
{
    /**
     * Strip terminal control sequences from untrusted text, keeping only SGR
     * colour/style codes and the TAB/CR/LF whitespace controls.
     *
     * The input is expected to be UTF-8 (the canonical storage form for message
     * text); charset conversion to CP437/ASCII happens downstream and does not
     * reintroduce an ESC introducer.
     *
     * @param string $text Raw untrusted text.
     * @return string Text safe to word-wrap and write to a terminal.
     */
    public static function sanitize(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // Split on well-formed SGR sequences, keeping them as captured
        // delimiters. Odd-indexed parts are the SGR sequences to preserve;
        // even-indexed parts are ordinary text that gets fully scrubbed.
        $parts = preg_split(
            '/(\x1b\[[0-9;:]*m)/',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

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
     * Remove every escape sequence and disallowed control byte from a fragment
     * that is known to contain no SGR sequences worth keeping.
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

        // Any CSI sequence (all non-SGR by construction, plus malformed or
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
