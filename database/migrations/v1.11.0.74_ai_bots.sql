CREATE TABLE ai_bots (
    id                SERIAL PRIMARY KEY,
    user_id           INTEGER NOT NULL REFERENCES users(id),
    name              VARCHAR(100) NOT NULL,
    description       TEXT,
    system_prompt     TEXT NOT NULL DEFAULT '',
    provider          VARCHAR(50),
    model             VARCHAR(100),
    weekly_budget_usd NUMERIC(10,4) NOT NULL DEFAULT 1.00,
    context_messages  SMALLINT NOT NULL DEFAULT 10,
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ai_bots_user_id ON ai_bots(user_id);
