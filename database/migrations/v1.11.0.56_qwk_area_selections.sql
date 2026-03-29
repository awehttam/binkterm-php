-- Migration: 1.11.0.56 — QWK area selections
--
-- Per-user list of echo areas to include in QWK packets.
-- When a user has rows here, only those areas are included in the packet;
-- if no rows exist, the existing behaviour (all subscribed areas) is used.
-- This lets users trim down a large subscription list for offline reading
-- or add non-subscribed areas they still want in their packets.

CREATE TABLE IF NOT EXISTS qwk_area_selections (
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, echoarea_id)
);

CREATE INDEX IF NOT EXISTS idx_qwk_area_selections_user ON qwk_area_selections(user_id);
