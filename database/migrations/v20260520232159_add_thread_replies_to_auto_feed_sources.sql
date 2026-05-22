-- Migration: 20260520232159 - add thread_replies to auto_feed_sources
-- Created: 2026-05-20 23:21:59 UTC

ALTER TABLE auto_feed_sources
    ADD COLUMN IF NOT EXISTS thread_replies BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS thread_lookup_limit INTEGER NOT NULL DEFAULT 1000;

COMMENT ON COLUMN auto_feed_sources.thread_replies IS 'When true, attempt to thread reply posts by matching RE:/Fwd: subject prefixes against existing echomail in the area';
COMMENT ON COLUMN auto_feed_sources.thread_lookup_limit IS 'Number of recent echomail messages to scan when searching for a reply parent';
