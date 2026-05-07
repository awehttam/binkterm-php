-- Migration: 20260507152614 - add echoarea allow media
-- Created: 2026-05-07 15:26:14 UTC

ALTER TABLE echoareas ADD COLUMN allow_media BOOLEAN DEFAULT NULL;
