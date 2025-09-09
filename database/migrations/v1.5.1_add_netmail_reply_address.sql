-- Migration: Add REPLYADDR kludge support for netmail messages
-- Version: 1.5.1
-- Description: Adds reply_address field to netmail table to store REPLYADDR kludge values

-- Add reply_address column to netmail table
-- This field stores the address from REPLYADDR kludge line when present
-- Takes precedence over original_author_address for reply addressing
ALTER TABLE netmail ADD COLUMN reply_address VARCHAR(20);

-- Create index for better query performance
CREATE INDEX IF NOT EXISTS idx_netmail_reply_address ON netmail(reply_address);

-- Comments for documentation
COMMENT ON COLUMN netmail.reply_address IS 'Address from REPLYADDR kludge line, used for reply addressing when present';