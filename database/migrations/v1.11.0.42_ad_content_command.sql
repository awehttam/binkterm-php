-- Migration: 1.11.0.42 - Add content_command to advertisements
ALTER TABLE advertisements
    ADD COLUMN IF NOT EXISTS content_command VARCHAR(1024) DEFAULT NULL;
