-- Migration: 1.8.6 - Add user credits and transactions

ALTER TABLE users
ADD COLUMN IF NOT EXISTS credit_balance INT NOT NULL DEFAULT 0;

-- Set existing users with 0 credits to 300
UPDATE users
SET credit_balance = 300
WHERE credit_balance = 0;

CREATE TABLE IF NOT EXISTS user_transactions (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id),
    other_party_id INT,
    amount INT NOT NULL,
    balance_after INT NOT NULL,
    description VARCHAR(500),
    transaction_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_user_transactions_user_id ON user_transactions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_transactions_created_at ON user_transactions(created_at);
