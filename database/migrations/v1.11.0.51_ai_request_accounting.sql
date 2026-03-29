-- Migration: 1.11.0.51 - AI request accounting ledger
-- Stores normalized usage, error, and estimated cost data for external AI API calls.

CREATE TABLE IF NOT EXISTS ai_requests (
    id                  SERIAL PRIMARY KEY,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    user_id             INTEGER REFERENCES users(id) ON DELETE SET NULL,
    provider            VARCHAR(50) NOT NULL,
    model               VARCHAR(150) NOT NULL,
    feature             VARCHAR(100) NOT NULL,
    operation           VARCHAR(50) NOT NULL,
    status              VARCHAR(20) NOT NULL,
    request_id          VARCHAR(150),
    input_tokens        INTEGER NOT NULL DEFAULT 0,
    output_tokens       INTEGER NOT NULL DEFAULT 0,
    cached_input_tokens INTEGER NOT NULL DEFAULT 0,
    cache_write_tokens  INTEGER NOT NULL DEFAULT 0,
    total_tokens        INTEGER NOT NULL DEFAULT 0,
    estimated_cost_usd  NUMERIC(14, 8) NOT NULL DEFAULT 0,
    duration_ms         INTEGER NOT NULL DEFAULT 0,
    http_status         INTEGER,
    error_code          VARCHAR(100),
    error_message       TEXT,
    metadata_json       JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_ai_requests_created_at ON ai_requests(created_at);
CREATE INDEX IF NOT EXISTS idx_ai_requests_feature_created_at ON ai_requests(feature, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_requests_provider_model_created_at ON ai_requests(provider, model, created_at);
CREATE INDEX IF NOT EXISTS idx_ai_requests_user_created_at ON ai_requests(user_id, created_at);
