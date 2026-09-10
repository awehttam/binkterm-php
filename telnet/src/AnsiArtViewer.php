<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Config;
use BinktermPHP\TerminalTextSanitizer;

/**
 * Dedicated full-screen viewer for ANSI-art message bodies on the Telnet/SSH
 * terminal reader.
 *
 * Since 1.10.5, {@see TerminalTextSanitizer} strips absolute cursor positioning
 * from message bodies before the inline reader renders them, so genuine ANSI art
 * (which places its pieces with `ESC[row;colH`) reflows into unreadable text.
 * This viewer renders such a body on its own cleared screen with cursor
 * positioning preserved — while still removing OSC (title/clipboard), DCS/APC/PM,
 * private-mode sequences and device-status/answerback queries, so the
 * input-injection and clipboard vectors stay closed. Only in-screen display
 * spoofing is reintroduced, and only inside a view the user explicitly enters.
 *
 * The `TERM_ANSI_ART_MODE` env setting controls behaviour:
 *
 *  - `viewer` (default): the inline reader shows the reflowed body; the user
 *    presses `A` to open this viewer.
 *  - `inline`: this viewer opens automatically when an art message is opened,
 *    and any key drops through to the normal reader.
 */
class AnsiArtViewer
{
    /** Art messages open this viewer only on demand (key `A`). */
    public const MODE_VIEWER = 'viewer';

    /** Art messages open this viewer automatically on open. */
    public const MODE_INLINE = 'inline';

    /**
     * Configured art-handling mode from `TERM_ANSI_ART_MODE`. Defaults to
     * {@see MODE_VIEWER}; any unrecognised value also falls back to it.
     */
    public static function mode(): string
    {
        $mode = strtolower(trim((string)Config::env('TERM_ANSI_ART_MODE', self::MODE_VIEWER)));
        return $mode === self::MODE_INLINE ? self::MODE_INLINE : self::MODE_VIEWER;
    }

    /**
     * Whether a raw (pre-sanitize) message body looks like positioned ANSI art
     * that would not survive the inline reader intact.
     */
    public static function isArt(string $rawBody): bool
    {
        return TerminalTextSanitizer::hasPositionedAnsi($rawBody);
    }

    /**
     * Render an ANSI-art message body full-screen and wait for a keypress.
     *
     * Clears the screen, draws the positioning-sanitized art, then parks a
     * dismiss prompt on the last row regardless of where the art left the
     * cursor. The caller must repaint its own screen afterwards (the message
     * viewer's rebuild/redraw handles this on the next loop iteration).
     *
     * @param resource $conn    Terminal socket.
     * @param object   $server  BbsSession instance.
     * @param array    $state   Session state (rows, cols, locale, ...).
     * @param string   $rawBody Raw message body (before strict sanitization).
     */
    public static function show($conn, $server, array &$state, string $rawBody): void
    {
        $locale = $state['locale'] ?? 'en';
        $rows   = max(2, (int)($state['rows'] ?? 24));

        $art = TerminalTextSanitizer::sanitize($rawBody, TerminalTextSanitizer::POLICY_POSITIONING);
        $art = str_replace(["\r\n", "\r"], "\n", $art);
        $art = rtrim($art, "\n");
        $art = $server->encodeForTerminal($art);

        TelnetUtils::safeWrite($conn, "\033[0m\033[2J\033[H\033[?25l");
        TelnetUtils::safeWrite($conn, str_replace("\n", "\r\n", $art));

        TelnetUtils::safeWrite($conn, "\033[0m\033[{$rows};1H\033[K");
        TelnetUtils::safeWrite($conn, TelnetUtils::colorize(
            $server->t(
                'ui.terminalserver.message.ansi_art_dismiss',
                'ANSI art view - press any key to return...',
                [],
                $locale
            ),
            TelnetUtils::ANSI_YELLOW
        ));

        while (true) {
            $key = $server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) {
                break; // idle disconnect
            }
            if ($key !== '') {
                break; // recognised keypress
            }
        }

        TelnetUtils::safeWrite($conn, "\033[0m\033[?25l");
    }
}
