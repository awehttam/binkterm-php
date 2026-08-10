-- Migration: 20260808003926 - create hub_node_areas table
-- Created: 2026-08-08 00:39:26 UTC

-- Per-subordinate echoarea subscriptions for the hub fanout engine.
-- See docs/proposals/HubPointSystemJuly2026.md.
CREATE TABLE hub_node_areas (
    id            SERIAL PRIMARY KEY,
    hub_node_id   INTEGER NOT NULL REFERENCES hub_nodes(id) ON DELETE CASCADE,
    echoarea_id   INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    paused        BOOLEAN NOT NULL DEFAULT FALSE,
    subscribed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (hub_node_id, echoarea_id)
);

CREATE INDEX idx_hub_node_areas_echoarea ON hub_node_areas (echoarea_id);
