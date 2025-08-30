-- Add message_id field to netmail table for MSGID storage
-- This enables proper REPLY kludge generation using actual stored MSGIDs

ALTER TABLE netmail ADD COLUMN message_id VARCHAR(100);