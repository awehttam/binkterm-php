-- Migration: 1.8.1 - Add index on echomail.message_id for duplicate detection
-- This index improves performance of duplicate MSGID checks during packet processing

CREATE INDEX IF NOT EXISTS idx_echomail_message_id ON echomail(message_id);

-- Add index on combination of echoarea_id and message_id for faster duplicate lookups
CREATE INDEX IF NOT EXISTS idx_echomail_area_msgid ON echomail(echoarea_id, message_id);
