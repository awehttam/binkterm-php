-- Migration: 1.9.2.6 - Add referral tracking to pending users
ALTER TABLE pending_users
ADD COLUMN IF NOT EXISTS referral_code VARCHAR(50),
ADD COLUMN IF NOT EXISTS referrer_id INT REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_pending_users_referrer_id ON pending_users(referrer_id);
