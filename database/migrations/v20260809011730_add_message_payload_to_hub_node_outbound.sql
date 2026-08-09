-- Migration: 20260809011730 - add message_payload to hub_node_outbound
-- Created: 2026-08-09 01:17:30 UTC

-- Stores the createOutboundPacket()-ready message array (from_address,
-- to_address, subject, message_text, kludge_lines, bottom_kludges, etc.) as
-- JSON for echomail/netmail rows, alongside the existing pre-rendered
-- packet_data blob. Delivery (BinkpSession::sendHubNodeOutbound()) decodes
-- this to bundle every pending row for a subordinate node into a single
-- multi-message .pkt at send time instead of sending one packet per row.
-- packet_data is kept as-is for the admin "Downlink Queue" inspect/download
-- UI and as the send-time fallback for rows queued before this column
-- existed. NULL for message_type='tic' rows, which are never bundled.
ALTER TABLE hub_node_outbound ADD COLUMN message_payload JSONB NULL;
