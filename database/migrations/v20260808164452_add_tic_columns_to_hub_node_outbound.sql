-- Migration: 20260808164452 - add tic columns to hub_node_outbound
-- Created: 2026-08-08 16:44:52 UTC

-- Phase 4 (TIC/File Area Distribution) of docs/proposals/HubPointSystemJuly2026.md.
-- A TIC delivery is a control-file/data-file pair, not a single .pkt, so it
-- doesn't fit packet_data unchanged. Reuses hub_node_outbound rather than a
-- parallel queue table so the Downlink Queue viewer stays a single source
-- for every distribution type (echomail/netmail/tic).
ALTER TABLE hub_node_outbound
    ADD COLUMN file_id        INTEGER REFERENCES files(id) ON DELETE SET NULL,
    ADD COLUMN tic_file_data  BYTEA,
    ADD COLUMN tic_filename   VARCHAR(255);

ALTER TABLE hub_node_outbound
    DROP CONSTRAINT chk_hno_message_type,
    ADD CONSTRAINT chk_hno_message_type CHECK (message_type IN ('echomail', 'netmail', 'tic'));
