-- Migration: rename image_load_mode -> media_render_mode in users_meta
-- Backfills all existing rows to 'auto' (new default: automatically render rich media)

UPDATE users_meta
SET keyname = 'media_render_mode',
    valname = 'auto'
WHERE keyname = 'image_load_mode';
