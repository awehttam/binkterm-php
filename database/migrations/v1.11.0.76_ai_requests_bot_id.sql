ALTER TABLE ai_requests
    ADD COLUMN bot_id INTEGER REFERENCES ai_bots(id) ON DELETE SET NULL;

CREATE INDEX idx_ai_requests_bot_id ON ai_requests(bot_id)
    WHERE bot_id IS NOT NULL;
