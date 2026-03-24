-- v1.11.0.53: Ad click-through URLs and impression/click tracking

ALTER TABLE advertisements
    ADD COLUMN IF NOT EXISTS click_url VARCHAR(2048) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS advertisement_impressions (
    id               SERIAL PRIMARY KEY,
    advertisement_id INT  NOT NULL REFERENCES advertisements(id) ON DELETE CASCADE,
    user_id          INT  NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    shown_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ad_impressions_ad ON advertisement_impressions(advertisement_id);
CREATE INDEX IF NOT EXISTS idx_ad_impressions_shown ON advertisement_impressions(shown_at);

CREATE TABLE IF NOT EXISTS advertisement_clicks (
    id               SERIAL PRIMARY KEY,
    advertisement_id INT  NOT NULL REFERENCES advertisements(id) ON DELETE CASCADE,
    user_id          INT  NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    clicked_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ad_clicks_ad ON advertisement_clicks(advertisement_id);
