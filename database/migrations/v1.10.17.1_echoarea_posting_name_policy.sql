-- Add per-echoarea posting name policy override.
-- NULL means "inherit from uplink policy".

ALTER TABLE echoareas
    ADD COLUMN IF NOT EXISTS posting_name_policy VARCHAR(20);

-- Normalize any unexpected values back to inherit/default behavior.
UPDATE echoareas
SET posting_name_policy = NULL
WHERE posting_name_policy IS NOT NULL
  AND posting_name_policy NOT IN ('real_name', 'username');

COMMENT ON COLUMN echoareas.posting_name_policy IS
    'Optional sender-name override for outbound echomail: real_name, username, or NULL to inherit uplink policy';
