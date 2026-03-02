-- Migration: 1.10.16 - Native Linux Door Support
-- Adds door_type column to dosbox_doors and door_sessions tables
-- to differentiate between DOS (DOSBox/DOSEMU) and native Linux doors

ALTER TABLE dosbox_doors ADD COLUMN IF NOT EXISTS door_type VARCHAR(20) NOT NULL DEFAULT 'dos';
ALTER TABLE door_sessions ADD COLUMN IF NOT EXISTS door_type VARCHAR(20) NOT NULL DEFAULT 'dos';
