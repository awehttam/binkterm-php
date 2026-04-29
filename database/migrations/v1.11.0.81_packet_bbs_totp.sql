-- PacketBBS TOTP: rate-limiting table for login attempts.
--
-- TOTP secret and enrollment state are stored in users_meta:
--   packet_bbs_totp_secret         -- active Base32-encoded TOTP secret
--   packet_bbs_totp_enabled        -- '1' when enabled, NULL when disabled
--   packet_bbs_totp_pending_secret -- temporary secret during enrollment (cleared on verify)

CREATE TABLE IF NOT EXISTS packet_bbs_login_attempts (
    id           SERIAL       PRIMARY KEY,
    node_id      VARCHAR(64)  NOT NULL,
    success      BOOLEAN      NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS packet_bbs_login_attempts_node_time_idx
    ON packet_bbs_login_attempts (node_id, attempted_at);
