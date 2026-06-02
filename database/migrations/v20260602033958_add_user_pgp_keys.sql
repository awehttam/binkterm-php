CREATE TABLE IF NOT EXISTS user_pgp_keys (
    id                 SERIAL PRIMARY KEY,
    user_id            INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    fingerprint        CHAR(40) NOT NULL UNIQUE,
    armored_public_key TEXT NOT NULL,
    source             VARCHAR(16) NOT NULL,
    label              VARCHAR(120),
    user_id_string     VARCHAR(255),
    email              VARCHAR(255),
    key_algorithm      VARCHAR(32),
    key_created_at     TIMESTAMPTZ,
    is_primary         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT user_pgp_keys_source_check CHECK (source IN ('uploaded', 'managed'))
);

CREATE INDEX IF NOT EXISTS user_pgp_keys_user_id_idx
    ON user_pgp_keys(user_id);

CREATE INDEX IF NOT EXISTS user_pgp_keys_email_idx
    ON user_pgp_keys(email);

CREATE UNIQUE INDEX IF NOT EXISTS user_pgp_keys_primary_user_idx
    ON user_pgp_keys(user_id)
    WHERE is_primary = TRUE;

CREATE TABLE IF NOT EXISTS user_pgp_private_keys (
    id                    SERIAL PRIMARY KEY,
    pgp_key_id            INTEGER NOT NULL UNIQUE REFERENCES user_pgp_keys(id) ON DELETE CASCADE,
    encrypted_private_key TEXT NOT NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
