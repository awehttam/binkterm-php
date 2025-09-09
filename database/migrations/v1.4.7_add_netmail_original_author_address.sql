-- Add original_author_address field to netmail table for MSGID-based routing
-- This enables more accurate reply addressing by using the address from MSGID kludge

ALTER TABLE netmail ADD COLUMN original_author_address VARCHAR(20);

-- Create index for better query performance
CREATE INDEX IF NOT EXISTS idx_netmail_original_author_address ON netmail(original_author_address);