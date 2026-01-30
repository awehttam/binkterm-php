-- Add sysop-only flag to echoareas
ALTER TABLE echoareas
    ADD COLUMN IF NOT EXISTS is_sysop_only BOOLEAN DEFAULT FALSE;

COMMENT ON COLUMN echoareas.is_sysop_only IS 'If TRUE, echoarea is restricted to sysop/admin users only';
