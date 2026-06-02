CREATE TABLE IF NOT EXISTS user_pgp_contact_keys (
    id                 SERIAL PRIMARY KEY,
    user_id            INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    fingerprint        CHAR(40) NOT NULL,
    armored_public_key TEXT NOT NULL,
    source             VARCHAR(16) NOT NULL DEFAULT 'address_book',
    label              VARCHAR(120),
    user_id_string     VARCHAR(255),
    email              VARCHAR(255),
    key_algorithm      VARCHAR(32),
    key_created_at     TIMESTAMPTZ,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT user_pgp_contact_keys_user_fingerprint_unique UNIQUE (user_id, fingerprint),
    CONSTRAINT user_pgp_contact_keys_source_check CHECK (source IN ('address_book', 'imported', 'remote_lookup'))
);

CREATE INDEX IF NOT EXISTS user_pgp_contact_keys_user_id_idx
    ON user_pgp_contact_keys(user_id);

CREATE INDEX IF NOT EXISTS user_pgp_contact_keys_email_idx
    ON user_pgp_contact_keys(email);

ALTER TABLE address_book
    ADD COLUMN IF NOT EXISTS pgp_contact_key_id INTEGER REFERENCES user_pgp_contact_keys(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS address_book_pgp_contact_key_id_idx
    ON address_book(pgp_contact_key_id);
