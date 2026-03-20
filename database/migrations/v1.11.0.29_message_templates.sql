-- Migration: 1.11.0.29 - Message templates (premium feature)
CREATE TABLE IF NOT EXISTS message_templates (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name        VARCHAR(100) NOT NULL,
    type        VARCHAR(10) NOT NULL DEFAULT 'both' CHECK (type IN ('netmail', 'echomail', 'both')),
    subject     VARCHAR(255) NOT NULL DEFAULT '',
    body        TEXT NOT NULL DEFAULT '',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_message_templates_user_id ON message_templates(user_id);
