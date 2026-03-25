<?php
/**
 * Migration: 1.11.0.55 - SSE events unlogged table
 *
 * Introduces an UNLOGGED sse_events table as the SSE delivery queue.
 * UNLOGGED means no WAL overhead on writes; the table is truncated on
 * crash, which is acceptable since it is a transient delivery mechanism —
 * actual messages live in their domain tables (chat_messages, etc.) forever.
 *
 * Using this table's BIGSERIAL id as the SSE Last-Event-ID decouples the
 * SSE cursor from any domain entity id so that multiple event types
 * (chat, MRC, notifications, …) can share one stream without id-space
 * conflicts.
 *
 * The pg_notify payload becomes the sse_events.id as a plain integer so
 * the admin daemon can update its maxSseEventId with a simple intval().
 */

$db->exec("
    CREATE UNLOGGED TABLE IF NOT EXISTS sse_events (
        id          BIGSERIAL    PRIMARY KEY,
        event_type  VARCHAR(64)  NOT NULL,
        payload     JSONB        NOT NULL DEFAULT '{}',
        created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
    )
");

$db->exec("CREATE INDEX IF NOT EXISTS idx_sse_events_created_at ON sse_events(created_at)");

// Replace the trigger function installed by v1.11.0.54 with a version that
// inserts into sse_events and notifies with the sse_events.id.
$db->exec("
    CREATE OR REPLACE FUNCTION notify_chat_message()
    RETURNS trigger AS \$\$
    DECLARE
        evt_id BIGINT;
    BEGIN
        INSERT INTO sse_events (event_type, payload)
        VALUES (
            'chat_message',
            json_build_object(
                'chat_id',      NEW.id,
                'room_id',      NEW.room_id,
                'from_user_id', NEW.from_user_id,
                'to_user_id',   NEW.to_user_id
            )
        )
        RETURNING id INTO evt_id;

        PERFORM pg_notify('binkstream', evt_id::text);
        RETURN NEW;
    END;
    \$\$ LANGUAGE plpgsql;
");

// Re-create the trigger in case this is a fresh install that never ran v1.11.0.54.
$db->exec("DROP TRIGGER IF EXISTS trg_chat_message_notify ON chat_messages");
$db->exec("
    CREATE TRIGGER trg_chat_message_notify
        AFTER INSERT ON chat_messages
        FOR EACH ROW EXECUTE FUNCTION notify_chat_message();
");

return true;
