-- Migration: 20261005220928 - add auth login attempts
-- Created: 2026-10-05 22:09:28 UTC
--
-- Shared, PostgreSQL-backed rolling-window failed-login tracking for the
-- interactive credential login surface. Every interactive username/password
-- login -- Web, Telnet, TLS-Telnet, SSH initial password auth, and the SSH
-- fallback BbsSession login UI -- passes through POST /api/auth/login. That
-- route handler is the single shared enforcement boundary; it consults
-- BinktermPHP\Security\LoginThrottle, which reads and writes this table.
--
-- Table shape mirrors the existing rate-limit logs (registration_attempts,
-- packet_bbs_login_attempts): one row per real
-- failed credential attempt. Two independent rolling-window counters:
--   * identifier_key -- the normalized (trimmed, case-folded) submitted login
--     identifier; caps targeted brute force against one account.
--   * ip_key -- the client IP resolved by Auth::resolveClientIp(); caps broad
--     username spraying from one source.
-- A login is allowed only while BOTH counters are below their limits.
--
-- A successful login flips that identifier's outstanding failure rows to
-- success = TRUE, retiring them from the identifier counter. The IP counter
-- ignores the flag and counts every row in the window, so a caller who holds
-- one valid account cannot wipe an IP spray counter by logging into it; the
-- IP counter only ages out through the rolling window.
--
-- No plaintext password or other request payload is stored. Rows are cleaned
-- opportunistically once older than one hour.

CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id             SERIAL       PRIMARY KEY,
    identifier_key VARCHAR(255) NOT NULL,
    ip_key         VARCHAR(45)  NOT NULL,
    success        BOOLEAN      NOT NULL DEFAULT FALSE,
    attempted_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_auth_login_attempts_identifier_time
    ON auth_login_attempts (identifier_key, success, attempted_at);

CREATE INDEX IF NOT EXISTS idx_auth_login_attempts_ip_time
    ON auth_login_attempts (ip_key, attempted_at);
