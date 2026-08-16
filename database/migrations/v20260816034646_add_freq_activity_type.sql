-- Migration: 20260816034646 - add freq activity type
-- Created: 2026-08-16 03:46:46 UTC

-- Adds a "freq_request" activity type (category: file) so outbound FREQ
-- requests submitted via the web/terminal File Requests UI are recorded in
-- user_activity_log, matching the other file-area actions (view/download/upload).
INSERT INTO activity_types (id, category_id, name, label) VALUES
    (14, 2, 'freq_request', 'File Requested (FREQ)')
ON CONFLICT (id) DO NOTHING;

