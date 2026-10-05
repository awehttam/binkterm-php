<?php

declare(strict_types=1);

namespace BinktermPHP\Security;

use BinktermPHP\Config;
use BinktermPHP\Database;
use PDO;

/**
 * Shared failed-login throttle for the interactive credential login surface.
 *
 * Enforced at exactly one boundary: the POST /api/auth/login route handler,
 * immediately around the existing Auth::login() call. Every interactive
 * username/password login reaches that route -- Web directly, and the Telnet,
 * TLS-Telnet and SSH daemons by proxying the credential check through it -- so
 * one throttle there covers all four transports. FTP, NNTP and QWK Basic-auth
 * call the deeper Auth::authenticateCredentials() primitive outside this HTTP
 * context and are deliberately NOT in scope here.
 *
 * Two independent rolling-window failure counters, both keyed off values the
 * route already has:
 *
 *   A. identifier_key -- the normalized submitted login identifier
 *      (trim + mb_strtolower). Strongly limits targeted brute force against a
 *      single account regardless of the source IP.
 *   B. ip_key -- the client IP from Auth::resolveClientIp() (which honours the
 *      authenticated X-Binkterm-Client-IP header the terminal daemons send).
 *      Limits broad username spraying from one source. A more generous ceiling
 *      than counter A so NAT / shared households / offices are not throttled
 *      collaterally while the account-specific counter stays tight.
 *
 * A login is allowed only while BOTH counters are below their limits. A
 * throttled attempt returns the identical generic authentication failure the
 * route already emits for a wrong password -- no lockout signal, no
 * username-enumeration signal, no permanent account lockout.
 *
 * Success semantics: on a successful authentication the caller invokes
 * {@see recordSuccess()}, which retires that identifier's outstanding failure
 * rows from counter A. Counter B is deliberately left intact -- a caller who
 * holds one valid account must not be able to reset an IP spray counter by
 * periodically logging into that account while brute-forcing other usernames.
 * Counter B only ages out through the rolling window.
 *
 * Storage is PostgreSQL (table auth_login_attempts) because the state must be
 * shared across php-fpm workers and the separate long-running terminal
 * daemons; an in-process limiter has the wrong scope. Table shape follows the
 * existing house pattern (registration_attempts / packet_bbs_login_attempts /
 * multizork_access_attempts).
 */
final class LoginThrottle
{
    /** Failed attempts per normalized identifier per window before blocking. */
    private const DEFAULT_USER_MAX = 5;

    /** Failed attempts per client IP per window before blocking. */
    private const DEFAULT_IP_MAX = 20;

    /** Rolling window length, seconds. */
    private const DEFAULT_WINDOW_SECONDS = 900;

    /** Rows older than this are removed by {@see cleanOld()} (seconds). */
    private const CLEANUP_AGE_SECONDS = 3600;

    /** Cap on the stored identifier key so an oversized submission cannot bloat the row. */
    private const IDENTIFIER_MAX_LEN = 255;

    private PDO $db;
    private int $userMax;
    private int $ipMax;
    private int $windowSeconds;

    /**
     * @param PDO|null $db            Injectable for tests; defaults to the shared connection.
     * @param int|null $userMax       Override AUTH_LOGIN_USER_MAX (tests).
     * @param int|null $ipMax         Override AUTH_LOGIN_IP_MAX (tests).
     * @param int|null $windowSeconds Override AUTH_LOGIN_WINDOW (tests).
     */
    public function __construct(
        ?PDO $db = null,
        ?int $userMax = null,
        ?int $ipMax = null,
        ?int $windowSeconds = null
    ) {
        $this->db = $db ?? Database::getInstance()->getPdo();

        // Malformed / non-positive configuration falls back to the safe
        // documented default rather than silently disabling the protection.
        $this->userMax = self::sanePositive(
            $userMax ?? self::configInt('AUTH_LOGIN_USER_MAX'),
            self::DEFAULT_USER_MAX
        );
        $this->ipMax = self::sanePositive(
            $ipMax ?? self::configInt('AUTH_LOGIN_IP_MAX'),
            self::DEFAULT_IP_MAX
        );
        $this->windowSeconds = self::sanePositive(
            $windowSeconds ?? self::configInt('AUTH_LOGIN_WINDOW'),
            self::DEFAULT_WINDOW_SECONDS
        );
    }

    /**
     * Normalize a submitted login identifier to its counter key: trimmed,
     * case-folded, length-capped. Unknown usernames and real usernames are
     * normalized identically, so a wrong-password attempt and an
     * unknown-username attempt share (and advance) the same counter.
     */
    public static function normalizeIdentifier(string $identifier): string
    {
        $normalized = mb_strtolower(trim($identifier));
        if (mb_strlen($normalized) > self::IDENTIFIER_MAX_LEN) {
            $normalized = mb_substr($normalized, 0, self::IDENTIFIER_MAX_LEN);
        }
        return $normalized;
    }

    /**
     * True only while BOTH the identifier counter and the IP counter are below
     * their limits. An empty/invalid key for either dimension simply skips that
     * dimension's check (it is never keyed under a bogus value).
     */
    public function isAllowed(string $identifier, string $ip): bool
    {
        $idKey = self::normalizeIdentifier($identifier);
        if ($idKey !== '' && $this->identifierFailures($idKey) >= $this->userMax) {
            return false;
        }

        $ipKey = self::normalizeIp($ip);
        if ($ipKey !== '' && $this->ipFailures($ipKey) >= $this->ipMax) {
            return false;
        }

        return true;
    }

    /**
     * Record one real failed credential attempt. Call this ONLY when
     * Auth::login() actually returned false for a submitted username/password;
     * never for malformed requests, network/API failures, client disconnects,
     * or successful auth.
     */
    public function recordFailure(string $identifier, string $ip): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO auth_login_attempts (identifier_key, ip_key, success) VALUES (?, ?, FALSE)'
        );
        $stmt->execute([
            self::normalizeIdentifier($identifier),
            self::normalizeIp($ip),
        ]);
    }

    /**
     * Retire this identifier's outstanding failure rows from the identifier
     * counter after a successful login. The IP counter is intentionally NOT
     * cleared here (see the class docblock).
     */
    public function recordSuccess(string $identifier): void
    {
        $idKey = self::normalizeIdentifier($identifier);
        if ($idKey === '') {
            return;
        }
        $stmt = $this->db->prepare(
            'UPDATE auth_login_attempts SET success = TRUE WHERE identifier_key = ? AND success = FALSE'
        );
        $stmt->execute([$idKey]);
    }

    /**
     * Delete rows older than the cleanup age. Best-effort housekeeping the
     * route calls opportunistically; failures here never affect a login.
     */
    public function cleanOld(): void
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM auth_login_attempts WHERE attempted_at < NOW() - INTERVAL '1 second' * ?"
            );
            $stmt->execute([self::CLEANUP_AGE_SECONDS]);
        } catch (\Throwable $e) {
            // Housekeeping only -- ignore.
        }
    }

    public function userMax(): int
    {
        return $this->userMax;
    }

    public function ipMax(): int
    {
        return $this->ipMax;
    }

    public function windowSeconds(): int
    {
        return $this->windowSeconds;
    }

    private function identifierFailures(string $idKey): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM auth_login_attempts
             WHERE identifier_key = ? AND success = FALSE
               AND attempted_at > NOW() - INTERVAL '1 second' * ?"
        );
        $stmt->execute([$idKey, $this->windowSeconds]);
        return (int)$stmt->fetchColumn();
    }

    private function ipFailures(string $ipKey): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM auth_login_attempts
             WHERE ip_key = ?
               AND attempted_at > NOW() - INTERVAL '1 second' * ?"
        );
        $stmt->execute([$ipKey, $this->windowSeconds]);
        return (int)$stmt->fetchColumn();
    }

    private static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * Read an integer env var, returning null (→ caller uses the default) when
     * it is unset, empty, or not numeric.
     */
    private static function configInt(string $key): ?int
    {
        $raw = Config::env($key, null);
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (int)$raw;
    }

    private static function sanePositive(?int $value, int $default): int
    {
        return ($value !== null && $value > 0) ? $value : $default;
    }
}
