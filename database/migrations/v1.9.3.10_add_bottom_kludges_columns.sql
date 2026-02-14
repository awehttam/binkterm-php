-- Migration: 1.9.3.10 - Add bottom_kludges columns for FTS-4009 compliance
-- Separates bottom kludges (Via, etc.) from top kludges to place them correctly in message structure

ALTER TABLE netmail ADD COLUMN IF NOT EXISTS bottom_kludges TEXT;
ALTER TABLE echomail ADD COLUMN IF NOT EXISTS bottom_kludges TEXT;

COMMENT ON COLUMN netmail.bottom_kludges IS 'Kludges that appear after message text per FTS-4009.001 (Via, etc.)';
COMMENT ON COLUMN echomail.bottom_kludges IS 'Kludges that appear after message text per FTS-4009.001 (Via, etc.)';
