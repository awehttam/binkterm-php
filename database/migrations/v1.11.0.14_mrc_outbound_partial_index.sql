-- Migration: v1.11.0.14 - Replace mrc_outbound pending index with a partial index
--
-- The daemon polls mrc_outbound every 100ms with:
--   WHERE sent_at IS NULL ORDER BY priority DESC, created_at ASC LIMIT 10
--
-- The old index (sent_at, priority) didn't match the ORDER BY, so PostgreSQL
-- fell back to a seq scan on the (usually tiny) table, accumulating millions
-- of seq scans over time. A partial index covering only unsent rows with the
-- exact sort order allows PostgreSQL to use an index scan with no extra sort.

DROP INDEX IF EXISTS idx_mrc_outbound_pending;

CREATE INDEX IF NOT EXISTS idx_mrc_outbound_unsent
    ON mrc_outbound (priority DESC, created_at ASC)
    WHERE sent_at IS NULL;
