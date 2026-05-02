-- Migration v1.11.0.83: Dashboard unread count — last_read_id high-watermark
--
-- The dashboard unread echomail query was doing a Materialize + Nested Loop
-- over 85K rows × 236 subscribed areas = 20M comparisons per request (~2.5s).
-- The root cause is per-message read tracking (message_read_status) being used
-- for an aggregate badge count — it fundamentally scans every message.
--
-- This migration adds a last_read_id high-watermark to user_echoarea_subscriptions.
-- The dashboard query becomes: count messages with id > last_read_id for each
-- subscribed area, using an index range scan instead of a full table scan.
--
-- Per-message read state (message_read_status) is preserved for the in-area
-- bold/unread UI — only the badge COUNT is changed to use the watermark.

ALTER TABLE user_echoarea_subscriptions
    ADD COLUMN IF NOT EXISTS last_read_id INTEGER REFERENCES echomail(id) ON DELETE SET NULL;

-- Composite index that makes the watermark count query efficient.
-- Supports: WHERE echoarea_id = ? AND id > ? (one range scan per subscribed area)
CREATE INDEX IF NOT EXISTS idx_echomail_echoarea_id
    ON echomail(echoarea_id, id);

-- Backfill: set last_read_id to the highest message ID each user has explicitly
-- marked read in each echoarea. This prevents the badge from showing a huge
-- backlog of "unread" messages for existing users on first load.
UPDATE user_echoarea_subscriptions ues
SET last_read_id = mrs_max.max_id
FROM (
    SELECT mrs.user_id, em.echoarea_id, MAX(em.id) AS max_id
    FROM message_read_status mrs
    JOIN echomail em ON em.id = mrs.message_id AND mrs.message_type = 'echomail'
    GROUP BY mrs.user_id, em.echoarea_id
) mrs_max
WHERE ues.user_id = mrs_max.user_id
  AND ues.echoarea_id = mrs_max.echoarea_id;
