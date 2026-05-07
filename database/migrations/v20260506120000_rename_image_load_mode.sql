-- Migration: rename image_load_mode -> media_render_mode in users_meta
-- Backfills all existing rows to 'click' (new default: click to expand media)

UPDATE users_meta
SET keyname = 'media_render_mode',
    valname = 'click'
WHERE keyname = 'image_load_mode';
