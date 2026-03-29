<?php
/**
 * Migration: 1.11.0.58 - Dashboard stats BinkStream triggers
 *
 * Adds DB-level triggers on echomail, netmail, and files INSERTs that
 * broadcast a signal-only 'dashboard_stats' event into sse_events.
 *
 * The payload is intentionally empty — clients call /api/dashboard/stats
 * on receipt to get personalised counts. The event just means "something
 * changed; refresh your badges now."
 */

// Shared trigger function — one function, three triggers.
// Debounced: only emits one event per 5-second window to avoid flooding
// clients during a batch mail import.
$db->exec("
    CREATE OR REPLACE FUNCTION notify_dashboard_stats()
    RETURNS trigger AS \$\$
    DECLARE
        evt_id BIGINT;
    BEGIN
        IF EXISTS (
            SELECT 1 FROM sse_events
            WHERE event_type = 'dashboard_stats'
              AND created_at > NOW() - INTERVAL '5 seconds'
        ) THEN
            RETURN NEW;
        END IF;

        INSERT INTO sse_events (event_type, payload, user_id, admin_only)
        VALUES ('dashboard_stats', '{}', NULL, FALSE)
        RETURNING id INTO evt_id;

        PERFORM pg_notify('binkstream', evt_id::text);
        RETURN NEW;
    END;
    \$\$ LANGUAGE plpgsql;
");

// echomail
$db->exec("DROP TRIGGER IF EXISTS trg_echomail_dashboard_notify ON echomail");
$db->exec("
    CREATE TRIGGER trg_echomail_dashboard_notify
        AFTER INSERT ON echomail
        FOR EACH ROW EXECUTE FUNCTION notify_dashboard_stats();
");

// netmail
$db->exec("DROP TRIGGER IF EXISTS trg_netmail_dashboard_notify ON netmail");
$db->exec("
    CREATE TRIGGER trg_netmail_dashboard_notify
        AFTER INSERT ON netmail
        FOR EACH ROW EXECUTE FUNCTION notify_dashboard_stats();
");

// files
$db->exec("DROP TRIGGER IF EXISTS trg_files_dashboard_notify ON files");
$db->exec("
    CREATE TRIGGER trg_files_dashboard_notify
        AFTER INSERT ON files
        FOR EACH ROW EXECUTE FUNCTION notify_dashboard_stats();
");

return true;
