-- Migration: v1.11.0.16 - Composite index on shared_messages for echomail listing joins
--
-- The LEFT JOIN in every echomail list query filters on:
--   message_id = ? AND message_type = ? AND shared_by_user_id = ? AND is_active = TRUE
--
-- The existing idx_shared_messages_message only covers (message_id, message_type),
-- leaving shared_by_user_id as a post-index filter and causing ~450k seq scans.
-- The new partial index covers all three equality conditions for active rows only,
-- which is the only subset that matters for share lookups.
--
-- idx_shared_messages_active is also dropped — a standalone boolean partial index
-- with no other columns is not useful for any actual query pattern.

DROP INDEX IF EXISTS idx_shared_messages_message;
DROP INDEX IF EXISTS idx_shared_messages_active;

CREATE INDEX IF NOT EXISTS idx_shared_messages_lookup
    ON shared_messages (message_id, message_type, shared_by_user_id)
    WHERE is_active = TRUE;
