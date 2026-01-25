-- Add is_local flag to echoareas for local-only message areas

ALTER TABLE echoareas
ADD COLUMN IF NOT EXISTS is_local BOOLEAN DEFAULT FALSE;

COMMENT ON COLUMN echoareas.is_local IS 'If TRUE, messages are not transmitted to uplinks (local-only area)';
