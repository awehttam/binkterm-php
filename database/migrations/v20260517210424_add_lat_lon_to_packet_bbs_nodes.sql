-- Migration: 20260517210424 - add_lat_lon_to_packet_bbs_nodes
-- Created: 2026-05-17 21:04:24 UTC

ALTER TABLE packet_bbs_nodes
    ADD COLUMN IF NOT EXISTS lat NUMERIC(10,6),
    ADD COLUMN IF NOT EXISTS lon  NUMERIC(10,6);
