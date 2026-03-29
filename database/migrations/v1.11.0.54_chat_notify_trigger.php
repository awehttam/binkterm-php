<?php
/**
 * Migration: v1.11.0.54 - Postgres NOTIFY trigger for chat_messages
 *
 * When a chat message is inserted, notify the 'binkstream' channel so that
 * any active SSE stream can push the event to connected clients immediately.
 */

return function ($db) {
    $db->exec("
        CREATE OR REPLACE FUNCTION notify_chat_message()
        RETURNS trigger AS \$\$
        DECLARE
            payload TEXT;
        BEGIN
            payload := json_build_object(
                'type',         'chat_message',
                'id',           NEW.id,
                'room_id',      NEW.room_id,
                'from_user_id', NEW.from_user_id,
                'to_user_id',   NEW.to_user_id
            )::text;
            PERFORM pg_notify('binkstream', payload);
            RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql;
    ");

    $db->exec("
        DROP TRIGGER IF EXISTS trg_chat_message_notify ON chat_messages;
        CREATE TRIGGER trg_chat_message_notify
            AFTER INSERT ON chat_messages
            FOR EACH ROW EXECUTE FUNCTION notify_chat_message();
    ");

    return true;
};
