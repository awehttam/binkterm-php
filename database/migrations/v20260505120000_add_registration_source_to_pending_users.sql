ALTER TABLE pending_users
    ADD COLUMN IF NOT EXISTS registration_source VARCHAR(20) NOT NULL DEFAULT 'web';
