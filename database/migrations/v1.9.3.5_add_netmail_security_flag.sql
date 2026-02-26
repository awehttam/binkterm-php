-- Migration: 1.9.3.5 - Add security flag to track insecure sessions
-- Add received_insecure column to netmail table to track messages received
-- through insecure/unauthenticated binkp sessions

ALTER TABLE netmail
ADD COLUMN IF NOT EXISTS received_insecure BOOLEAN DEFAULT FALSE;

CREATE INDEX IF NOT EXISTS idx_netmail_received_insecure
ON netmail(received_insecure) WHERE received_insecure = TRUE;
