-- Migration: 20261019233808 - add emulate_modem_speed to rlogin_doors
-- Created: 2026-10-19 23:38:08 UTC

ALTER TABLE rlogin_doors ADD COLUMN IF NOT EXISTS emulate_modem_speed BOOLEAN NOT NULL DEFAULT FALSE;
