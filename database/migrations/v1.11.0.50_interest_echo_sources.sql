-- Migration: 1.11.0.50 - Interest echo area source tracking
--
-- Replaces the single interest_id FK on user_echoarea_subscriptions with a
-- proper junction table so an echo area that belongs to multiple interests
-- is handled correctly: unsubscribing from one interest only removes the
-- echo area subscription when no other subscribed interest also covers it.

CREATE TABLE IF NOT EXISTS user_echoarea_interest_sources (
    user_id     INTEGER NOT NULL REFERENCES users(id)     ON DELETE CASCADE,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    interest_id INTEGER NOT NULL REFERENCES interests(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, echoarea_id, interest_id)
);

CREATE INDEX IF NOT EXISTS idx_ueis_user_interest ON user_echoarea_interest_sources(user_id, interest_id);

-- Backfill from existing interest-sourced subscriptions.
INSERT INTO user_echoarea_interest_sources (user_id, echoarea_id, interest_id)
SELECT user_id, echoarea_id, interest_id
FROM user_echoarea_subscriptions
WHERE interest_id IS NOT NULL
ON CONFLICT DO NOTHING;
