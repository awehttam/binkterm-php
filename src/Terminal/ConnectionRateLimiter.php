<?php

namespace BinktermPHP\Terminal;

/**
 * Fixed-window per-source-IP connection rate limiter shared by the Telnet
 * daemon (`TELNET_RATE_LIMIT_*`) and the SSH daemon (`SSH_RATE_LIMIT_*`).
 * Both check it right after accept(), before forking or doing any protocol
 * work.
 *
 * Addresses are normalized before they are counted ({@see normalizeIp()}), so
 * an IPv4 client seen on a dual-stack listener as `::ffff:192.0.2.1` shares one
 * counter with `192.0.2.1`.
 *
 * Window semantics: the first connection from an IP starts a fixed window; every
 * connection inside that window is counted; once `max` is exceeded the remainder
 * of the window is rejected; when the window expires the counter resets on the
 * next connection.
 *
 * {@see check()} returns:
 *   0 — allow
 *   1 — reject, and this is the first rejection this window (caller should log)
 *   2 — reject, but a rejection was already logged this window (caller stays quiet);
 *       a suppressed-count notice is emitted through the logger when the window ends.
 *
 * A `max` of 0 or less disables limiting entirely (matches
 * `TELNET_RATE_LIMIT_MAX=0`).
 */
class ConnectionRateLimiter
{
    private int $max;
    private int $window;

    /** @var callable|null fn(string $message): void */
    private $logger;

    /** @var array<string, array{count:int,window_start:int,logged:bool,suppressed:int}> */
    private array $table = [];

    public function __construct(int $max, int $window, ?callable $logger = null)
    {
        $this->max    = $max;
        // A zero/negative window would make every connection look like a new
        // window and defeat the limiter; fall back to the Telnet default.
        $this->window = $window > 0 ? $window : 60;
        $this->logger = $logger;
    }

    public function isEnabled(): bool
    {
        return $this->max > 0;
    }

    /**
     * @return int 0 = allow, 1 = reject (log), 2 = reject (suppressed)
     */
    public function check(string $ip): int
    {
        if ($this->max <= 0) {
            return 0;
        }

        $ip  = self::normalizeIp($ip);
        $now = time();

        if (!isset($this->table[$ip])) {
            $this->table[$ip] = ['count' => 1, 'window_start' => $now, 'logged' => false, 'suppressed' => 0];
            return 0;
        }

        $entry = &$this->table[$ip];

        if ($now - $entry['window_start'] >= $this->window) {
            if ($entry['suppressed'] > 0) {
                $this->flush($ip, $entry['suppressed']);
            }
            $entry = ['count' => 1, 'window_start' => $now, 'logged' => false, 'suppressed' => 0];
            return 0;
        }

        $entry['count']++;

        if ($entry['count'] <= $this->max) {
            return 0;
        }

        if (!$entry['logged']) {
            $entry['logged'] = true;
            return 1;
        }

        $entry['suppressed']++;
        return 2;
    }

    /**
     * Drop expired entries. The Telnet daemon calls the equivalent on each
     * accept-loop select() timeout to keep the table from growing unbounded.
     * Flushes suppression counts first so no rejection notice is lost.
     */
    public function clean(): void
    {
        if (empty($this->table)) {
            return;
        }

        $now = time();
        foreach ($this->table as $ip => $entry) {
            if ($now - $entry['window_start'] >= $this->window) {
                if ($entry['suppressed'] > 0) {
                    $this->flush($ip, $entry['suppressed']);
                }
                unset($this->table[$ip]);
            }
        }
    }

    /**
     * Canonical form of a client address: IPv4-mapped IPv6 (`::ffff:a.b.c.d`)
     * becomes plain IPv4, and other IPv6 addresses are written in their
     * canonical compressed form. Anything that is not an IP is returned as is.
     */
    public static function normalizeIp(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        if (strlen($packed) === 16 && strncmp($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12) === 0) {
            $packed = substr($packed, 12);
        }
        return (string)inet_ntop($packed);
    }

    /** Number of source IPs currently tracked (test/introspection aid). */
    public function trackedIpCount(): int
    {
        return count($this->table);
    }

    private function flush(string $ip, int $suppressed): void
    {
        if ($this->logger !== null) {
            ($this->logger)("Rate limit: {$suppressed} additional rejection(s) from {$ip} suppressed");
        }
    }
}
