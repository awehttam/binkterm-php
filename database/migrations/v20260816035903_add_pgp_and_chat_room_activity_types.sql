-- Migration: 20260816035903 - add pgp and chat room activity types
-- Created: 2026-08-16 03:59:03 UTC

-- New category for PGP key management actions.
INSERT INTO activity_categories (id, name) VALUES
    (8, 'pgp')
ON CONFLICT (id) DO NOTHING;

INSERT INTO activity_types (id, category_id, name, label) VALUES
    (18, 5, 'chat_room_enter',   'Chat Room Entered'),
    (19, 8, 'pgp_key_upload',    'PGP Key Uploaded'),
    (20, 8, 'pgp_key_generate',  'PGP Key Generated'),
    (21, 8, 'pgp_key_primary',   'PGP Primary Key Changed'),
    (22, 8, 'pgp_key_delete',    'PGP Key Deleted')
ON CONFLICT (id) DO NOTHING;

