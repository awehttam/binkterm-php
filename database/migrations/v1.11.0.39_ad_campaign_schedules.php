<?php
/**
 * Migration: 1.11.0.39 - Add explicit schedules for advertisement campaigns
 */

return function ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS advertisement_campaign_schedules (
            id SERIAL PRIMARY KEY,
            campaign_id INTEGER NOT NULL REFERENCES advertisement_campaigns(id) ON DELETE CASCADE,
            days_mask INTEGER NOT NULL DEFAULT 0,
            time_of_day CHAR(5) NOT NULL DEFAULT '12:00',
            timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            last_triggered_at TIMESTAMPTZ DEFAULT NULL
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_ad_campaign_schedules_campaign
            ON advertisement_campaign_schedules (campaign_id, is_active)
    ");

    return true;
};
