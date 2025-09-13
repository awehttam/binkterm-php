-- Add kludge_lines column to netmail table for consistent kludge handling
-- This brings netmail in line with echomail for better separation of message content and kludges

ALTER TABLE netmail ADD COLUMN kludge_lines TEXT;

-- Add basic index for kludge-based queries (using btree instead of gin_trgm_ops which requires pg_trgm extension)
CREATE INDEX IF NOT EXISTS idx_netmail_kludge_lines ON netmail(kludge_lines);

-- Note: Existing netmail messages will have kludge_lines = NULL
-- New messages will have clean message_text and separate kludge_lines storage