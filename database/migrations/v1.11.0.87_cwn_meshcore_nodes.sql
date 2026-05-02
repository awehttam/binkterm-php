-- Migration: 1.11.0.87 - Add MeshCore node ingest support to CWN

ALTER TABLE cwn_networks
    ALTER COLUMN submitted_by DROP NOT NULL,
    ALTER COLUMN submitted_by_username DROP NOT NULL,
    ALTER COLUMN description DROP NOT NULL;

ALTER TABLE cwn_networks
    ADD COLUMN IF NOT EXISTS public_key VARCHAR(64),
    ADD COLUMN IF NOT EXISTS source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMP,
    ADD COLUMN IF NOT EXISTS hop_count SMALLINT;

CREATE UNIQUE INDEX IF NOT EXISTS idx_cwn_networks_public_key
    ON cwn_networks(public_key)
    WHERE public_key IS NOT NULL;
