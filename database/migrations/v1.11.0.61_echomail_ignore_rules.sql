CREATE TABLE IF NOT EXISTS user_echomail_ignore_rules (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    sender_name VARCHAR(255) NOT NULL,
    subject_contains VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'),
    UNIQUE (user_id, sender_name, subject_contains)
);

CREATE INDEX IF NOT EXISTS idx_user_echomail_ignore_rules_user_id
    ON user_echomail_ignore_rules(user_id);
