-- Migration: 20260830214259 - netmail tearline component
-- Created: 2026-08-30 21:42:59 UTC
--
-- Mirrors echomail.tearline_component: an optional label inserted into the FTN
-- tearline of an outbound netmail packet, e.g. "NNTP" ->
-- "--- BinktermPHP NNTP v1.10.5". NULL keeps the plain "--- BinktermPHP v1.10.5".
-- Set by MessageHandler::sendNetmail() and read by BinkdProcessor at pack time.

ALTER TABLE netmail ADD COLUMN IF NOT EXISTS tearline_component VARCHAR(64);
