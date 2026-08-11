-- Migration: 20260809023241 - add compress_outbound to hub_nodes
-- Created: 2026-08-09 02:32:41 UTC

-- Opt-in per-downlink setting: when true, hub node bundled outbound
-- (BinkpSession::sendHubNodeOutboundBundle) is packed into a ZIP arcmail
-- bundle (FTS-5001 day-of-week extension) instead of sent as a raw .pkt.
ALTER TABLE hub_nodes ADD COLUMN compress_outbound BOOLEAN NOT NULL DEFAULT FALSE;

