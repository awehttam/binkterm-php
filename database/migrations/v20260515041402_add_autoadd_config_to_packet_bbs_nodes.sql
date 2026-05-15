-- Migration: 20260515041402 - add_autoadd_config_to_packet_bbs_nodes
-- Add autoadd_config bitmask to bridge nodes so the admin can view and set
-- the auto-add contact policy on the MeshCore device from the admin panel.
-- NULL means the config is not yet known (never read from or written to device).
ALTER TABLE packet_bbs_nodes ADD COLUMN IF NOT EXISTS autoadd_config SMALLINT DEFAULT NULL;
