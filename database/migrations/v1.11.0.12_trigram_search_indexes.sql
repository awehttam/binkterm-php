-- v1.11.0.12 - Add trigram GIN indexes for fast ILIKE search on message text and subject
-- Without these, ILIKE '%term%' does a full sequential table scan.
-- pg_trgm lets PostgreSQL use a GIN index for arbitrary substring/ILIKE queries.
-- Initial index build may take a moment on large tables.

CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX IF NOT EXISTS idx_echomail_subject_trgm   ON echomail USING GIN (subject gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_echomail_body_trgm      ON echomail USING GIN (message_text gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_netmail_subject_trgm    ON netmail  USING GIN (subject gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_netmail_body_trgm       ON netmail  USING GIN (message_text gin_trgm_ops);
