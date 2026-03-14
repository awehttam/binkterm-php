-- Migration: v1.11.0.17 - Add (message_id, message_type, user_id) index on saved_messages
--
-- Every echomail listing LEFT JOINs saved_messages from the echomail side:
--   ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
--
-- The existing UNIQUE index is (user_id, message_id, message_type) — wrong column
-- order for a join driven from message_id. PostgreSQL cannot efficiently seek into
-- it by message_id alone. The new index puts message_id first so nested-loop joins
-- from echomail can probe it directly per row.
--
-- The existing idx_saved_messages_message on (message_id, message_type) is
-- superseded by the new index (which adds user_id), so it is dropped.

DROP INDEX IF EXISTS idx_saved_messages_message;

CREATE INDEX IF NOT EXISTS idx_saved_messages_msg_user
    ON saved_messages (message_id, message_type, user_id);
