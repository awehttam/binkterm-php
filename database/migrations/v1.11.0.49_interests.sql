-- Migration: 1.11.0.49 - Interests system
-- Admin-defined topic categories that group echo areas and file areas.
-- Users can subscribe to an interest to auto-subscribe to its member areas.

CREATE TABLE IF NOT EXISTS interests (
    id          SERIAL PRIMARY KEY,
    slug        VARCHAR(100) NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    icon        VARCHAR(50)  NOT NULL DEFAULT 'fa-layer-group',
    color       VARCHAR(7)   NOT NULL DEFAULT '#6c757d',
    sort_order  INTEGER      NOT NULL DEFAULT 0,
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Echo areas belonging to an interest
CREATE TABLE IF NOT EXISTS interest_echoareas (
    interest_id INTEGER NOT NULL REFERENCES interests(id) ON DELETE CASCADE,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    PRIMARY KEY (interest_id, echoarea_id)
);

-- File areas belonging to an interest
CREATE TABLE IF NOT EXISTS interest_fileareas (
    interest_id INTEGER NOT NULL REFERENCES interests(id) ON DELETE CASCADE,
    filearea_id INTEGER NOT NULL REFERENCES file_areas(id) ON DELETE CASCADE,
    PRIMARY KEY (interest_id, filearea_id)
);

-- User subscriptions to interests (the interest-level subscription record)
CREATE TABLE IF NOT EXISTS user_interest_subscriptions (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    interest_id   INTEGER NOT NULL REFERENCES interests(id) ON DELETE CASCADE,
    subscribed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, interest_id)
);

CREATE INDEX IF NOT EXISTS idx_user_interest_subs_user ON user_interest_subscriptions(user_id);

-- Track which echo area subscriptions were created via an interest.
-- ON DELETE SET NULL: if the interest is deleted, the echo area sub stays
-- but loses its interest_id link, becoming an independent subscription.
ALTER TABLE user_echoarea_subscriptions
    ADD COLUMN IF NOT EXISTS interest_id INTEGER REFERENCES interests(id) ON DELETE SET NULL;
