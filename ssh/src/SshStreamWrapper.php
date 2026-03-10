<?php

namespace BinktermPHP\SshServer;

use BinktermPHP\SshServer\SshSession;

/**
 * PHP user-land stream wrapper that presents an SshSession channel as a
 * regular PHP stream resource so BbsSession can read/write it without knowing
 * about SSH framing or encryption.
 *
 * Usage:
 *   $stream = SshStreamWrapper::open($sshSession);
 *   // pass $stream to BbsSession just like a TCP socket
 *
 * stream_select() support: stream_cast() returns the raw SSH socket, so PHP's
 * select() watches the real network socket for readability.  When the socket
 * is readable, stream_read() decrypts the packet via SshSession and returns
 * the plaintext payload.
 *
 * Known limitation: if the SSH peer sends a window-adjust packet without any
 * channel data, stream_cast() signals readability but stream_read() will block
 * briefly until the next channel-data packet arrives.  For an interactive BBS
 * session this is negligible.
 *
 * Used on platforms without pcntl_fork() (Windows) where the bridge+fork
 * pattern is not available.
 */
class SshStreamWrapper
{
    // -------------------------------------------------------------------------
    // Static registry — thread-safe enough for single-process PHP
    // -------------------------------------------------------------------------

    /** @var array<int,SshSession> */
    private static array $registry = [];
    private static int   $nextId   = 0;

    // -------------------------------------------------------------------------
    // Instance state
    // -------------------------------------------------------------------------

    /** @var SshSession */
    private SshSession $session;

    /** Read-ahead buffer for partial reads */
    private string $readBuffer = '';

    /** True once the SSH channel has been closed / readChannelData returned null */
    private bool $eof = false;

    /** @required by PHP stream wrapper interface */
    public $context;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Register the wrapper (once) and return an open stream resource wrapping
     * $session.  Returns false on failure.
     *
     * @return resource|false
     */
    public static function open(SshSession $session): mixed
    {
        if (!in_array('sshconn', stream_get_wrappers())) {
            stream_wrapper_register('sshconn', self::class);
        }
        $id = ++self::$nextId;
        self::$registry[$id] = $session;
        $stream = @fopen("sshconn://{$id}", 'r+b');
        if ($stream === false) {
            unset(self::$registry[$id]);
        }
        return $stream;
    }

    // -------------------------------------------------------------------------
    // PHP stream wrapper interface
    // -------------------------------------------------------------------------

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $id = (int)(parse_url($path, PHP_URL_HOST) ?? 0);
        if (!isset(self::$registry[$id])) { return false; }
        $this->session = self::$registry[$id];
        unset(self::$registry[$id]);
        return true;
    }

    public function stream_read(int $count): string|false
    {
        // Serve from buffer first
        if (strlen($this->readBuffer) > 0) {
            return $this->drainBuffer($count);
        }

        if ($this->eof) { return false; }

        // Fetch one SSH channel-data packet; handles window-adjust transparently
        $chunk = $this->session->readChannelData();
        if ($chunk === null) {
            $this->eof = true;
            return false;
        }

        // If a window-change arrived while reading, inject a NAWS subneg so
        // BbsSession's existing NAWS handler updates its terminal dimensions.
        $resize = $this->session->consumePendingResize();
        if ($resize !== null) {
            $this->readBuffer .= SshSession::nawsBytes($resize['cols'], $resize['rows']);
        }

        $this->readBuffer .= $chunk;
        return $this->drainBuffer($count);
    }

    public function stream_write(string $data): int
    {
        try {
            $this->session->sendChannelData($data);
        } catch (\Throwable $e) {
            return 0;
        }
        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->eof && $this->readBuffer === '';
    }

    public function stream_close(): void
    {
        $this->session->sendChannelClose();
    }

    /**
     * Allow stream_select() to watch the raw SSH socket directly.
     * When the underlying socket becomes readable, our stream_read() will
     * decrypt the incoming SSH packet and return the payload to the caller.
     *
     * @return resource
     */
    public function stream_cast(int $cast_as): mixed
    {
        return $this->session->getSocket();
    }

    /** @return array<string,int> */
    public function stream_stat(): array
    {
        return [];
    }

    /**
     * Handle stream_set_blocking() and stream_set_timeout() by delegating
     * to the underlying SSH socket.
     */
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        $sock = $this->session->getSocket();
        return match ($option) {
            STREAM_OPTION_BLOCKING      => stream_set_blocking($sock, (bool)$arg1),
            STREAM_OPTION_READ_TIMEOUT  => stream_set_timeout($sock, $arg1, $arg2 ?? 0),
            default                     => false,
        };
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function drainBuffer(int $count): string|false
    {
        if ($this->readBuffer === '') {
            return $this->eof ? false : '';
        }
        $out              = substr($this->readBuffer, 0, $count);
        $this->readBuffer = substr($this->readBuffer, $count);
        return $out;
    }
}
