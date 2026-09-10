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
    /**
     * Art messages are rendered onto a virtual canvas (see
     * {@see AnsiCanvasRenderer}) and the resulting SGR-only lines are shown
     * inline in the normal reader, which scrolls and resizes them like any
     * other message. Pressing `A` still opens the full-screen positioned view.
     * Default.
     */
    public const MODE_CANVAS = 'canvas';

    /**
     * Inline reader shows the reflowed, escape-filtered body; the user presses
     * `A` for the dedicated full-screen art view.
     */
    public const MODE_VIEWER = 'viewer';

    /**
     * The dedicated full-screen art view opens automatically when an art
     * message is opened; any key drops through to the normal reader.
     */
    public const MODE_INLINE = 'inline';

    /**
     * Cursor-positioning and erase sequences are passed through to the normal
     * reader for art messages (sanitized with
     * {@see TerminalTextSanitizer::POLICY_POSITIONING}), and such bodies are not
     * word-wrapped so the art keeps its own column layout. The sysop accepts
     * the in-screen display-spoofing tradeoff; OSC/DCS/answerback vectors are
     * still removed.
     */
    public const MODE_RAW = 'raw';

    /**
     * Configured art-handling mode from `TERM_ANSI_ART_MODE`. Defaults to
     * {@see MODE_CANVAS}; any unrecognised value also falls back to it.
     */
    public static function mode(): string
    {
        $mode = strtolower(trim((string)Config::env('TERM_ANSI_ART_MODE', self::MODE_CANVAS)));

        return match ($mode) {
            self::MODE_VIEWER => self::MODE_VIEWER,
            self::MODE_INLINE => self::MODE_INLINE,
            self::MODE_RAW    => self::MODE_RAW,
            default           => self::MODE_CANVAS,
        };
    }

    /**
     * Whether a raw (pre-sanitize) message body looks like positioned ANSI art
     * that would not survive the plain word-wrapping reader intact.
     */
    public static function isArt(string $rawBody): bool
    {
        return TerminalTextSanitizer::hasPositionedAnsi($rawBody);
    }

    /**
     * How the normal reader should turn a message body into display lines:
     *
     *  - `strict` — sanitize `POLICY_STRIP`, word-wrap (all non-art bodies, and
     *    art bodies in `viewer` / `inline` mode).
     *  - `canvas` — sanitize `POLICY_POSITIONING`, render through
     *    {@see AnsiCanvasRenderer} (art body, `canvas` mode).
     *  - `raw` — sanitize `POLICY_POSITIONING`, split on newlines only (art
     *    body, `raw` mode).
     */
    public static function readerRenderMode(bool $isArt): string
    {
        if (!$isArt) {
            return 'strict';
        }

        return match (self::mode()) {
            self::MODE_CANVAS => 'canvas',
            self::MODE_RAW    => 'raw',
            default           => 'strict',
        };
    }

    /**
     * The sanitize policy the normal reader should apply to a message body,
     * derived from {@see readerRenderMode()}.
     */
    public static function readerBodyPolicy(bool $isArt): string
    {
        return self::readerRenderMode($isArt) === 'strict'
            ? TerminalTextSanitizer::POLICY_STRIP
            : TerminalTextSanitizer::POLICY_POSITIONING;
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
