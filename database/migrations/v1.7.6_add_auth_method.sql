-- Migration: v1.7.6_add_auth_method.sql
-- Description: Add authentication method tracking to binkp session log
-- Date: 2026-01-25

-- Add auth_method column to track whether session used plain text or CRAM-MD5
ALTER TABLE binkp_session_log
ADD COLUMN IF NOT EXISTS auth_method VARCHAR(20) DEFAULT 'plaintext';

-- Add comment for documentation
COMMENT ON COLUMN binkp_session_log.auth_method IS 'Authentication method used: plaintext or cram-md5';
