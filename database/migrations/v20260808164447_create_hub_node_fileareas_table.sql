-- Migration: 20260808164447 - create hub_node_fileareas table
-- Created: 2026-08-08 16:44:47 UTC

-- Per-subordinate file area subscriptions for the hub TIC/file distribution
-- engine (Phase 4). Mirrors hub_node_areas exactly, one row per
-- (downlink, file area) pair. See docs/proposals/HubPointSystemJuly2026.md.
CREATE TABLE hub_node_fileareas (
    id            SERIAL PRIMARY KEY,
    hub_node_id   INTEGER NOT NULL REFERENCES hub_nodes(id) ON DELETE CASCADE,
    file_area_id  INTEGER NOT NULL REFERENCES file_areas(id) ON DELETE CASCADE,
    paused        BOOLEAN NOT NULL DEFAULT FALSE,
    subscribed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (hub_node_id, file_area_id)
);

CREATE INDEX idx_hub_node_fileareas_node      ON hub_node_fileareas (hub_node_id);
CREATE INDEX idx_hub_node_fileareas_filearea  ON hub_node_fileareas (file_area_id);
