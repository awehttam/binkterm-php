ALTER TABLE shared_messages
    ADD COLUMN IF NOT EXISTS og_image_path TEXT DEFAULT NULL;
