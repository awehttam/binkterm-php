-- Add soft delete columns for netmail
-- Allows sender and recipient to independently delete their copy
ALTER TABLE netmail ADD COLUMN IF NOT EXISTS deleted_by_sender BOOLEAN DEFAULT FALSE;
ALTER TABLE netmail ADD COLUMN IF NOT EXISTS deleted_by_recipient BOOLEAN DEFAULT FALSE;

-- Create index for efficient querying
CREATE INDEX IF NOT EXISTS idx_netmail_deleted_flags ON netmail(deleted_by_sender, deleted_by_recipient);
