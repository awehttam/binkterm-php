-- Migration: 20261102034325 - add archivedoor play activity type
-- Created: 2026-11-02 03:43:25 UTC

INSERT INTO activity_types (id, category_id, name, label) VALUES
    (23, 3, 'archivedoor_play', 'Archive.org Door Played')
ON CONFLICT (id) DO NOTHING;
