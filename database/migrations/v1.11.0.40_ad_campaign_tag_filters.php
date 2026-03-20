<?php
/**
 * Migration: 1.11.0.40 - Add advertisement campaign tag filters
 */

return function ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS advertisement_campaign_tag_filters (
            campaign_id INTEGER NOT NULL REFERENCES advertisement_campaigns(id) ON DELETE CASCADE,
            tag_id INTEGER NOT NULL REFERENCES advertisement_tags(id) ON DELETE CASCADE,
            filter_mode VARCHAR(16) NOT NULL,
            PRIMARY KEY (campaign_id, tag_id, filter_mode)
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_ad_campaign_tag_filters_campaign
            ON advertisement_campaign_tag_filters (campaign_id, filter_mode)
    ");

    return true;
};
