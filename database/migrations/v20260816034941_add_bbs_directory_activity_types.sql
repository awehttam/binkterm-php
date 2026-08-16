-- Migration: 20260816034941 - add bbs directory activity types
-- Created: 2026-08-16 03:49:41 UTC

-- New category for the public BBS directory (list of other BBSes), distinct
-- from the FTN nodelist category.
INSERT INTO activity_categories (id, name) VALUES
    (7, 'bbs_directory')
ON CONFLICT (id) DO NOTHING;

INSERT INTO activity_types (id, category_id, name, label) VALUES
    (15, 7, 'bbs_directory_view',       'BBS Directory Viewed'),
    (16, 7, 'bbs_directory_entry_view', 'BBS Directory Entry Viewed')
ON CONFLICT (id) DO NOTHING;

