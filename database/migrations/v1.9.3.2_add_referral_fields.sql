-- Migration: 1.9.3.2 - Add referral system fields to users table
ALTER TABLE users
ADD COLUMN IF NOT EXISTS referral_code VARCHAR(50) UNIQUE,
ADD COLUMN IF NOT EXISTS referred_by INT REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_users_referral_code ON users(referral_code);
CREATE INDEX IF NOT EXISTS idx_users_referred_by ON users(referred_by);
