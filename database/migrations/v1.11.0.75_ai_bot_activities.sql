CREATE TABLE ai_bot_activities (
    id            SERIAL PRIMARY KEY,
    bot_id        INTEGER NOT NULL REFERENCES ai_bots(id) ON DELETE CASCADE,
    activity_type VARCHAR(50) NOT NULL,
    is_enabled    BOOLEAN NOT NULL DEFAULT TRUE,
    config_json   JSONB,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(bot_id, activity_type)
);

CREATE INDEX idx_ai_bot_activities_bot_id ON ai_bot_activities(bot_id);
