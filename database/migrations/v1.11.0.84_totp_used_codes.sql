-- Stores TOTP time-step counters that have already been accepted, preventing
-- replay of a code within its valid window. Rows older than a few minutes are
-- purged by PacketBbsTotp::verifyCode() on each successful authentication.
CREATE TABLE IF NOT EXISTS totp_used_codes (
    user_id  INTEGER     NOT NULL,
    step     BIGINT      NOT NULL,
    used_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, step)
);

CREATE INDEX IF NOT EXISTS idx_totp_used_codes_used_at ON totp_used_codes (used_at);
