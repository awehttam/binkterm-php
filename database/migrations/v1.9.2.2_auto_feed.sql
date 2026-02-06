-- Auto Feed: RSS to Echoarea posting system
-- Monitors RSS feeds and automatically posts new articles to echoareas

CREATE TABLE IF NOT EXISTS auto_feed_sources (
    id SERIAL PRIMARY KEY,
    feed_url TEXT NOT NULL UNIQUE,
    feed_name VARCHAR(100),
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    post_as_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    active BOOLEAN DEFAULT TRUE,
    max_articles_per_check INTEGER DEFAULT 10,
    last_article_guid TEXT,
    last_check TIMESTAMP,
    articles_posted INTEGER DEFAULT 0,
    last_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_auto_feed_active ON auto_feed_sources(active);
CREATE INDEX IF NOT EXISTS idx_auto_feed_last_check ON auto_feed_sources(last_check);

COMMENT ON TABLE auto_feed_sources IS 'RSS/Atom feeds monitored for automatic posting to echoareas';
COMMENT ON COLUMN auto_feed_sources.post_as_user_id IS 'User ID to post messages as (e.g., NewsBot user)';
COMMENT ON COLUMN auto_feed_sources.last_article_guid IS 'GUID of last posted article to track position in feed';
