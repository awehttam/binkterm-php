<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Config;

/**
 * Socket-level tuning for accepted interactive terminal connections.
 *
 * Telnet, TLS-Telnet and SSH all carry latency-sensitive, small-payload
 * traffic — single keystrokes inbound, cursor moves and incremental screen
 * paints outbound. Nagle's algorithm holds those tiny segments back waiting
 * for the previous segment's ACK, which the caller experiences as sluggish
 * echo and choppy redraws. Disabling it with TCP_NODELAY is the standard
 * fix for an interactive terminal socket and mirrors what the BinkP mailer
 * already does for its own sockets (see BINKP_TCP_NODELAY).
 *
 * The option is applied to the plain accepted TCP socket immediately after
 * accept() and before any TLS handshake, so it is set once on the underlying
 * file descriptor and inherited by the TLS stream layered on top.
 *
 * This is a best-effort optimisation: every failure path is swallowed and
 * the connection proceeds unchanged. It must never be able to drop a caller.
 *
 * Controlled by the TERMINAL_TCP_NODELAY env var (default: enabled). Set it
 * to a falsey value (0/false/no/off) to leave Nagle's algorithm in place.
 */
final class TerminalSocketOptions
{
    /**
     * Enable TCP_NODELAY on an accepted interactive client stream.
     *
     * @param resource      $stream   A stream returned by stream_socket_accept().
     * @param callable|null  $debugLog Optional fn(string $message): void for debug logging.
     * @return bool True if TCP_NODELAY was set; false if it was disabled by config,
     *              unsupported by the runtime, or the syscall failed.
     */
    public static function enableNoDelay($stream, ?callable $debugLog = null): bool
    {
        if (!is_resource($stream)
            || !function_exists('socket_import_stream')
            || !defined('TCP_NODELAY')
            || !defined('SOL_TCP')) {
            return false;
        }

        $raw = strtolower(trim((string) Config::env('TERMINAL_TCP_NODELAY', 'true')));
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        $sock = @socket_import_stream($stream);
        if ($sock === false || $sock === null) {
            if ($debugLog) {
                $debugLog('TCP_NODELAY: socket_import_stream() failed');
            }
            return false;
        }

        // The imported Socket shares the stream's underlying fd. Setting the
        // option and letting the Socket handle fall out of scope is safe — the
        // stream retains ownership of the descriptor; we never socket_close() it.
        $ok = @socket_set_option($sock, SOL_TCP, TCP_NODELAY, 1);
        if (!$ok && $debugLog) {
            $debugLog('TCP_NODELAY: socket_set_option() failed');
        }

        return (bool) $ok;
    }
}
