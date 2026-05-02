-- Add username tracking to PacketBBS login attempts for cross-node rate limiting.
-- Allows blocking brute-force attempts against a single account arriving from
-- multiple different source nodes, not just per-node blocking.
ALTER TABLE packet_bbs_login_attempts ADD COLUMN IF NOT EXISTS username VARCHAR(100);
CREATE INDEX IF NOT EXISTS idx_packet_bbs_login_attempts_username
    ON packet_bbs_login_attempts (username, success, attempted_at)
    WHERE username IS NOT NULL;
