-- Migration: 20260606034151 - add_meshcore_node_adverts
-- Created: 2026-06-06 03:41:51 UTC

CREATE TABLE IF NOT EXISTS meshcore_node_adverts (
    id SERIAL PRIMARY KEY,
    public_key VARCHAR(64) NOT NULL UNIQUE,
    bridge_node_id VARCHAR(64),
    name VARCHAR(100) NOT NULL,
    adv_type VARCHAR(50) NOT NULL DEFAULT 'meshcore',
    latitude NUMERIC(10,6) NOT NULL,
    longitude NUMERIC(10,6) NOT NULL,
    hop_count SMALLINT,
    bbs_name VARCHAR(50) NOT NULL,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS meshcore_node_adverts_last_seen_idx
    ON meshcore_node_adverts(last_seen_at DESC);

CREATE INDEX IF NOT EXISTS meshcore_node_adverts_bridge_node_id_idx
    ON meshcore_node_adverts(bridge_node_id);

ALTER TABLE packet_bbs_nodes
    ADD COLUMN IF NOT EXISTS public_key VARCHAR(64);

CREATE UNIQUE INDEX IF NOT EXISTS packet_bbs_nodes_public_key_idx
    ON packet_bbs_nodes(public_key)
    WHERE public_key IS NOT NULL;

INSERT INTO meshcore_node_adverts (
    public_key,
    bridge_node_id,
    name,
    adv_type,
    latitude,
    longitude,
    hop_count,
    bbs_name,
    last_seen_at,
    created_at,
    updated_at
)
SELECT DISTINCT ON (c.public_key)
    c.public_key,
    NULL,
    c.ssid,
    COALESCE(NULLIF(c.network_type, ''), 'meshcore'),
    c.latitude::NUMERIC(10,6),
    c.longitude::NUMERIC(10,6),
    c.hop_count,
    COALESCE(NULLIF(c.bbs_name, ''), 'Unknown'),
    COALESCE(c.last_seen_at, c.date_updated, c.date_added, c.created_at, NOW()),
    COALESCE(c.created_at, c.date_added, NOW()),
    COALESCE(c.date_updated, c.last_seen_at, c.date_added, c.created_at, NOW())
FROM cwn_networks c
WHERE c.source_type = 'meshcore'
  AND c.public_key IS NOT NULL
ORDER BY c.public_key,
         COALESCE(c.last_seen_at, c.date_updated, c.date_added, c.created_at, NOW()) DESC,
         c.id DESC
ON CONFLICT (public_key) DO UPDATE SET
    name         = EXCLUDED.name,
    adv_type     = EXCLUDED.adv_type,
    latitude     = EXCLUDED.latitude,
    longitude    = EXCLUDED.longitude,
    hop_count    = EXCLUDED.hop_count,
    bbs_name     = EXCLUDED.bbs_name,
    last_seen_at = EXCLUDED.last_seen_at,
    updated_at   = EXCLUDED.updated_at;

UPDATE packet_bbs_nodes n
SET public_key = src.public_key
FROM (
    SELECT DISTINCT ON (left(public_key, 12))
        left(public_key, 12) AS node_prefix,
        public_key
    FROM meshcore_node_adverts
    ORDER BY left(public_key, 12), last_seen_at DESC, id DESC
) AS src
WHERE n.interface_type = 'meshcore'
  AND n.public_key IS NULL
  AND n.node_id = src.node_prefix;
