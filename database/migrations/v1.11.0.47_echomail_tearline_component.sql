-- Migration: v1.11.0.47 - Add tearline_component column to echomail
ALTER TABLE echomail ADD COLUMN IF NOT EXISTS tearline_component VARCHAR(64) DEFAULT NULL;
