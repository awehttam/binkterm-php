-- Migration v1.6.0: Add Echoarea Subscription System
-- Implements admin-controlled default subscriptions

-- Add default subscription flag to echoareas table
ALTER TABLE echoareas ADD COLUMN IF NOT EXISTS is_default_subscription BOOLEAN DEFAULT FALSE;

-- User echoarea subscriptions table
CREATE TABLE IF NOT EXISTS user_echoarea_subscriptions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    subscription_type VARCHAR(20) DEFAULT 'user', -- 'auto', 'user', 'admin'
    UNIQUE(user_id, echoarea_id)
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_user ON user_echoarea_subscriptions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_echoarea ON user_echoarea_subscriptions(echoarea_id);
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_active ON user_echoarea_subscriptions(user_id, is_active);
CREATE INDEX IF NOT EXISTS idx_echoareas_default ON echoareas(is_default_subscription);

-- Set some existing areas as default subscriptions (admin can modify later)
UPDATE echoareas SET is_default_subscription = TRUE 
WHERE tag IN ('GENERAL', 'LOCALTEST') AND is_active = TRUE;

-- Auto-subscribe all existing users to default echoareas
INSERT INTO user_echoarea_subscriptions (user_id, echoarea_id, subscription_type, subscribed_at)
SELECT u.id, e.id, 'auto', CURRENT_TIMESTAMP
FROM users u
CROSS JOIN echoareas e
WHERE e.is_default_subscription = TRUE 
  AND e.is_active = TRUE
  AND u.is_active = TRUE
  AND NOT EXISTS (
    SELECT 1 FROM user_echoarea_subscriptions s 
    WHERE s.user_id = u.id AND s.echoarea_id = e.id
  );