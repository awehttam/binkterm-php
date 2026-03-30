<?php
/**
 * Migration: 1.11.0.67 - Targeted dashboard stats BinkStream triggers
 *
 * Replaces the broadcast notify_dashboard_stats() function with three
 * table-specific functions that create per-user sse_events rows rather
 * than a single NULL-user_id broadcast.
 *
 * - echomail: only notifies users subscribed to the affected echo area
 *             AND who have an active session (i.e. are online).
 * - netmail:  only notifies the recipient user if they are online.
 * - files:    notifies all users who are currently online (no per-area
 *             subscription model exists for file areas).
 *
 * Per-user debouncing: each INSERT checks for a recent dashboard_stats
 * event already queued for that specific user before inserting another.
 *
 * A user is considered online if they have at least one row in
 * user_sessions with expires_at > NOW().
 */

// Drop the old shared broadcast function and its triggers
$db->exec("DROP TRIGGER IF EXISTS trg_echomail_dashboard_notify ON echomail");
$db->exec("DROP TRIGGER IF EXISTS trg_netmail_dashboard_notify ON netmail");
$db->exec("DROP TRIGGER IF EXISTS trg_files_dashboard_notify ON files");
$db->exec("DROP FUNCTION IF EXISTS notify_dashboard_stats()");

// echomail: notify subscribed + online users only
$db->exec("
    CREATE OR REPLACE FUNCTION notify_echomail_dashboard()
    RETURNS trigger AS \$\$
    DECLARE
        rec RECORD;
        evt_id BIGINT;
    BEGIN
        FOR rec IN
            SELECT DISTINCT ues.user_id
            FROM user_echoarea_subscriptions ues
            INNER JOIN user_sessions us ON us.user_id = ues.user_id
                AND us.expires_at > NOW()
            WHERE ues.echoarea_id = NEW.echoarea_id
              AND ues.is_active = TRUE
        LOOP
            -- Per-user debounce: skip if a recent event already exists for this user
            IF EXISTS (
                SELECT 1 FROM sse_events
                WHERE event_type = 'dashboard_stats'
                  AND user_id = rec.user_id
                  AND created_at > NOW() - INTERVAL '5 seconds'
            ) THEN
                CONTINUE;
            END IF;

            INSERT INTO sse_events (event_type, payload, user_id, admin_only)
            VALUES ('dashboard_stats', '{}', rec.user_id, FALSE)
            RETURNING id INTO evt_id;

            PERFORM pg_notify('binkstream', evt_id::text);
        END LOOP;

        RETURN NEW;
    END;
    \$\$ LANGUAGE plpgsql;
");

// netmail: notify the recipient if online
$db->exec("
    CREATE OR REPLACE FUNCTION notify_netmail_dashboard()
    RETURNS trigger AS \$\$
    DECLARE
        evt_id BIGINT;
    BEGIN
        -- Only proceed if the netmail has a resolved recipient user
        IF NEW.user_id IS NULL THEN
            RETURN NEW;
        END IF;

        -- Check recipient is online
        IF NOT EXISTS (
            SELECT 1 FROM user_sessions
            WHERE user_id = NEW.user_id
              AND expires_at > NOW()
        ) THEN
            RETURN NEW;
        END IF;

        -- Per-user debounce
        IF EXISTS (
            SELECT 1 FROM sse_events
            WHERE event_type = 'dashboard_stats'
              AND user_id = NEW.user_id
              AND created_at > NOW() - INTERVAL '5 seconds'
        ) THEN
            RETURN NEW;
        END IF;

        INSERT INTO sse_events (event_type, payload, user_id, admin_only)
        VALUES ('dashboard_stats', '{}', NEW.user_id, FALSE)
        RETURNING id INTO evt_id;

        PERFORM pg_notify('binkstream', evt_id::text);
        RETURN NEW;
    END;
    \$\$ LANGUAGE plpgsql;
");

// files: notify all online users (no per-area subscription model)
$db->exec("
    CREATE OR REPLACE FUNCTION notify_files_dashboard()
    RETURNS trigger AS \$\$
    DECLARE
        rec RECORD;
        evt_id BIGINT;
    BEGIN
        FOR rec IN
            SELECT DISTINCT user_id
            FROM user_sessions
            WHERE expires_at > NOW()
        LOOP
            IF EXISTS (
                SELECT 1 FROM sse_events
                WHERE event_type = 'dashboard_stats'
                  AND user_id = rec.user_id
                  AND created_at > NOW() - INTERVAL '5 seconds'
            ) THEN
                CONTINUE;
            END IF;

            INSERT INTO sse_events (event_type, payload, user_id, admin_only)
            VALUES ('dashboard_stats', '{}', rec.user_id, FALSE)
            RETURNING id INTO evt_id;

            PERFORM pg_notify('binkstream', evt_id::text);
        END LOOP;

        RETURN NEW;
    END;
    \$\$ LANGUAGE plpgsql;
");

// Re-create triggers using the new targeted functions
$db->exec("
    CREATE TRIGGER trg_echomail_dashboard_notify
        AFTER INSERT ON echomail
        FOR EACH ROW EXECUTE FUNCTION notify_echomail_dashboard();
");

$db->exec("
    CREATE TRIGGER trg_netmail_dashboard_notify
        AFTER INSERT ON netmail
        FOR EACH ROW EXECUTE FUNCTION notify_netmail_dashboard();
");

$db->exec("
    CREATE TRIGGER trg_files_dashboard_notify
        AFTER INSERT ON files
        FOR EACH ROW EXECUTE FUNCTION notify_files_dashboard();
");

return true;
