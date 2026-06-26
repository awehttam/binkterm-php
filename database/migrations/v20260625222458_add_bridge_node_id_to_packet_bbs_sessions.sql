-- Migration: 20260625222458 - add_bridge_node_id_to_packet_bbs_sessions
-- Created: 2026-06-25 22:24:58 UTC

-- Bind each radio-node PacketBBS session to the bridge that owns it.
-- Requests authenticated as a different bridge are rejected, preventing
-- a registered bridge from hijacking another node's authenticated session.
ALTER TABLE packet_bbs_sessions ADD COLUMN bridge_node_id VARCHAR(128) DEFAULT NULL;

