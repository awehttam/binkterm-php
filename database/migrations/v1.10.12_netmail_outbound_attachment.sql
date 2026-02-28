ALTER TABLE netmail ADD COLUMN IF NOT EXISTS outbound_attachment_path TEXT;
COMMENT ON COLUMN netmail.outbound_attachment_path IS 'Path to pending outbound file attachment; NULL when no attachment or after delivery';
