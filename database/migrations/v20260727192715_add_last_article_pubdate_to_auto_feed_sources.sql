-- Migration: 20260727192715 - add last article pubdate to auto feed sources
-- Created: 2026-07-27 19:27:15 UTC

ALTER TABLE auto_feed_sources
    ADD COLUMN IF NOT EXISTS last_article_pubdate TIMESTAMP NULL;

COMMENT ON COLUMN auto_feed_sources.last_article_pubdate IS 'Publish timestamp of the last posted article, used as a fallback watermark when last_article_guid is no longer present in the feed';

