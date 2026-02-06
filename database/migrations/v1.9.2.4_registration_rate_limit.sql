-- Registration rate limiting table
-- Tracks registration attempts by IP address to prevent spam
CREATE TABLE IF NOT EXISTS registration_attempts (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT FALSE
);

-- Index for efficient IP lookup and cleanup
CREATE INDEX IF NOT EXISTS idx_registration_attempts_ip ON registration_attempts(ip_address, attempt_time);
CREATE INDEX IF NOT EXISTS idx_registration_attempts_time ON registration_attempts(attempt_time);
