-- Migration: v1.7.1_add_crashmail_support.sql
-- Description: Add support for crashmail delivery and insecure binkp sessions
-- Date: 2026-01-23

-- Insecure session allowlist - nodes that can connect without password
CREATE TABLE IF NOT EXISTS binkp_insecure_nodes (
    id SERIAL PRIMARY KEY,
    address VARCHAR(30) NOT NULL,
    description TEXT,
    allow_receive BOOLEAN DEFAULT TRUE,   -- Can receive mail from this node
    allow_send BOOLEAN DEFAULT FALSE,     -- Can send mail to this node (pickup)
    max_messages_per_session INTEGER DEFAULT 100,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_session_at TIMESTAMP,
    UNIQUE(address)
);

-- Crashmail queue - messages awaiting direct delivery
CREATE TABLE IF NOT EXISTS crashmail_queue (
    id SERIAL PRIMARY KEY,
    netmail_id INTEGER NOT NULL REFERENCES netmail(id) ON DELETE CASCADE,
    destination_address VARCHAR(30) NOT NULL,
    destination_host VARCHAR(255),        -- Resolved hostname/IP
    destination_port INTEGER DEFAULT 24554,
    status VARCHAR(20) DEFAULT 'pending', -- pending, attempting, sent, failed
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 3,
    last_attempt_at TIMESTAMP,
    next_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP
);

-- Session log for all binkp connections (audit trail)
CREATE TABLE IF NOT EXISTS binkp_session_log (
    id SERIAL PRIMARY KEY,
    remote_address VARCHAR(30),
    remote_ip INET,
    session_type VARCHAR(20) NOT NULL,    -- 'secure', 'insecure', 'crash_outbound'
    is_inbound BOOLEAN NOT NULL,
    messages_received INTEGER DEFAULT 0,
    messages_sent INTEGER DEFAULT 0,
    files_received INTEGER DEFAULT 0,
    files_sent INTEGER DEFAULT 0,
    bytes_received BIGINT DEFAULT 0,
    bytes_sent BIGINT DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',  -- 'active', 'success', 'failed', 'rejected'
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP
);

-- Indexes for efficient queries
CREATE INDEX IF NOT EXISTS idx_crashmail_queue_pending ON crashmail_queue(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_crashmail_queue_destination ON crashmail_queue(destination_address);
CREATE INDEX IF NOT EXISTS idx_crashmail_queue_netmail ON crashmail_queue(netmail_id);
CREATE INDEX IF NOT EXISTS idx_binkp_insecure_nodes_address ON binkp_insecure_nodes(address);
CREATE INDEX IF NOT EXISTS idx_binkp_insecure_nodes_active ON binkp_insecure_nodes(is_active);
CREATE INDEX IF NOT EXISTS idx_binkp_session_log_remote ON binkp_session_log(remote_address, started_at);
CREATE INDEX IF NOT EXISTS idx_binkp_session_log_type ON binkp_session_log(session_type, started_at);
CREATE INDEX IF NOT EXISTS idx_binkp_session_log_status ON binkp_session_log(status);

-- Add comment for documentation
COMMENT ON TABLE binkp_insecure_nodes IS 'Allowlist of FTN nodes permitted to connect without password authentication';
COMMENT ON TABLE crashmail_queue IS 'Queue for netmail messages marked for immediate direct delivery (crash attribute)';
COMMENT ON TABLE binkp_session_log IS 'Audit log of all binkp sessions including secure, insecure, and crash outbound';
