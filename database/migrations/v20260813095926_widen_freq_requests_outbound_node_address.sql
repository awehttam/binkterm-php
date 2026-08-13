-- Migration: 20260813095926 - widen freq requests outbound node address
-- Created: 2026-08-13 09:59:26 UTC

-- Outbound FREQ requests can now target a plain internet hostname/IP
-- (optionally "host:port") instead of an FTN address, for nodes with no
-- nodelist/binkp_zone entry. VARCHAR(50) is too narrow for a hostname plus
-- port, so widen the column.
ALTER TABLE freq_requests_outbound ALTER COLUMN node_address TYPE VARCHAR(255);
