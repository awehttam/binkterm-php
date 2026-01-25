-- Gateway tokens for external service authentication (bbslinkgateway, etc.)

CREATE TABLE IF NOT EXISTS gateway_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP,
    door VARCHAR(100)
);

CREATE INDEX IF NOT EXISTS idx_gateway_tokens_token ON gateway_tokens(token);
CREATE INDEX IF NOT EXISTS idx_gateway_tokens_user_id ON gateway_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_gateway_tokens_expires_at ON gateway_tokens(expires_at);
