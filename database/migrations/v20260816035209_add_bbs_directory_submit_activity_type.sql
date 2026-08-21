-- Migration: 20260816035209 - add bbs directory submit activity type
-- Created: 2026-08-16 03:52:09 UTC

INSERT INTO activity_types (id, category_id, name, label) VALUES
    (17, 7, 'bbs_directory_submit', 'BBS Directory Listing Submitted')
ON CONFLICT (id) DO NOTHING;

