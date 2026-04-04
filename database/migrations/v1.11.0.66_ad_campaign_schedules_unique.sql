-- Migration: 1.11.0.66 - Unique constraint on advertisement_campaign_schedules
-- Prevents duplicate schedule rows per logical slot and enables upsert-based sync
-- so that last_triggered_at is preserved when a campaign is saved mid-window.

ALTER TABLE advertisement_campaign_schedules
    ADD CONSTRAINT uq_ad_campaign_schedules_slot
    UNIQUE (campaign_id, days_mask, time_of_day, timezone);
