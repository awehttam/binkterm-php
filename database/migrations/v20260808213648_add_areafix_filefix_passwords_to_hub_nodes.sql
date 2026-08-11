-- Migration: 20260808213648 - add areafix filefix passwords to hub_nodes
-- Created: 2026-08-08 21:36:48 UTC

-- Phase 5 (Areafix/FileFix) of docs/proposals/HubPointSystemJuly2026.md.
-- Dedicated robot passwords, separate from the binkp session_password, so
-- a subordinate's Areafix/Filefix netmail password can differ from (and be
-- rotated independently of) its binkp session credentials.
ALTER TABLE hub_nodes
    ADD COLUMN areafix_password VARCHAR(255),
    ADD COLUMN filefix_password VARCHAR(255);
