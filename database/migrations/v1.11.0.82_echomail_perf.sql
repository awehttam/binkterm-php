-- Migration v1.11.0.82: Echomail performance — cache last-post info on echoareas
--
-- The echolist query previously ran three separate full-table scans of the
-- echomail table on every page load to compute total message count, unread
-- count, and last-post details. This migration:
--
--   1. Adds last_post_subject / last_post_author / last_post_date columns to
--      echoareas so that the "last post" subquery (which was doing an
--      external-merge sort over 90K rows) can be replaced with a simple
--      column read.
--
--   2. Recalibrates message_count for every echoarea from live COUNT(*) to
--      repair any accumulated drift.

ALTER TABLE echoareas
    ADD COLUMN IF NOT EXISTS last_post_subject VARCHAR(255),
    ADD COLUMN IF NOT EXISTS last_post_author  VARCHAR(100),
    ADD COLUMN IF NOT EXISTS last_post_date    TIMESTAMP;

-- Backfill last_post columns from existing echomail data.
-- Uses DISTINCT ON to find the most recently received message per area.
UPDATE echoareas e
SET
    last_post_subject = latest.subject,
    last_post_author  = latest.from_name,
    last_post_date    = latest.date_received
FROM (
    SELECT DISTINCT ON (echoarea_id)
        echoarea_id,
        subject,
        from_name,
        date_received
    FROM echomail
    ORDER BY echoarea_id, date_received DESC
) latest
WHERE e.id = latest.echoarea_id;

-- Recalibrate message_count for all echoareas to fix accumulated drift.
UPDATE echoareas e
SET message_count = (
    SELECT COUNT(*) FROM echomail WHERE echoarea_id = e.id
);
