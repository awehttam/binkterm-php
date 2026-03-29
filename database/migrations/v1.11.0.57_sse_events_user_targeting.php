<?php
/**
 * Migration: 1.11.0.57 - Add user targeting to sse_events
 *
 * Adds two columns to sse_events:
 *   user_id    - nullable FK to users; NULL = broadcast to all authenticated users,
 *                non-NULL = deliver only to the named user.
 *   admin_only - when TRUE, deliver only to users with is_admin = TRUE.
 *
 * Also rewrites notify_chat_message() to build a fat payload containing all
 * data the client needs (username, room_name, body, etc.) so the SSE delivery
 * query can forward payload directly without JOINing back to domain tables.
 * Room messages set user_id = NULL; DMs set user_id = NEW.to_user_id.
 */

$db->exec("
    ALTER TABLE sse_events
        ADD COLUMN IF NOT EXISTS user_id    BIGINT  NULL REFERENCES users(id) ON DELETE CASCADE,
        ADD COLUMN IF NOT EXISTS admin_only BOOLEAN NOT NULL DEFAULT FALSE
");

$db->exec("CREATE INDEX IF NOT EXISTS idx_sse_events_user_id ON sse_events(user_id)");

// Rewrite the trigger to produce a fat payload and set user_id correctly.
$db->exec("
    CREATE OR REPLACE FUNCTION notify_chat_message()
    RETURNS trigger AS \$\$
    DECLARE
        evt_id    BIGINT;
        target_uid BIGINT;
        room_name  TEXT;
        from_username TEXT;
    BEGIN
        SELECT username INTO from_username FROM users WHERE id = NEW.from_user_id;

        IF NEW.room_id IS NOT NULL THEN
            SELECT name INTO room_name FROM chat_rooms WHERE id = NEW.room_id;
            target_uid := NULL;  -- broadcast to all connected users
        ELSE
            room_name  := NULL;
            target_uid := NEW.to_user_id;  -- DM: deliver only to recipient
        END IF;

        INSERT INTO sse_events (event_type, payload, user_id, admin_only)
        VALUES (
            'chat_message',
            json_build_object(
                'id',            NEW.id,
                'type',          CASE WHEN NEW.room_id IS NOT NULL THEN 'room' ELSE 'dm' END,
                'room_id',       NEW.room_id,
                'room_name',     room_name,
                'from_user_id',  NEW.from_user_id,
                'from_username', from_username,
                'to_user_id',    NEW.to_user_id,
                'body',          NEW.body,
                'created_at',    NEW.created_at
            ),
            target_uid,
            FALSE
        )
        RETURNING id INTO evt_id;

        PERFORM pg_notify('binkstream', evt_id::text);
        RETURN NEW;
    END;
    \$\$ LANGUAGE plpgsql;
");

return true;
