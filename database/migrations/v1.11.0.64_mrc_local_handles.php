<?php
/**
 * Migration: 1.11.0.64 - add active local MRC handle mapping
 */

$db->exec("
    CREATE TABLE IF NOT EXISTS mrc_local_handles (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        username VARCHAR(30) NOT NULL,
        bbs_name VARCHAR(64) NOT NULL,
        connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("
    CREATE INDEX IF NOT EXISTS idx_mrc_local_handles_last_seen
        ON mrc_local_handles(last_seen)
");

return true;
