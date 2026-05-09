ALTER TABLE auto_feed_sources
    ADD COLUMN IF NOT EXISTS source_type VARCHAR(20) NOT NULL DEFAULT 'rss';

CREATE INDEX IF NOT EXISTS idx_auto_feed_source_type ON auto_feed_sources(source_type);

COMMENT ON COLUMN auto_feed_sources.source_type IS 'Auto feed source adapter, such as rss or bluesky';
