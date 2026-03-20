-- Migration: 1.11.0.41 - Add end_at to advertisement_campaigns
ALTER TABLE advertisement_campaigns
    ADD COLUMN IF NOT EXISTS end_at TIMESTAMPTZ DEFAULT NULL;
