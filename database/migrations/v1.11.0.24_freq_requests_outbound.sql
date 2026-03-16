-- Migration: v1.11.0.24 - Outbound FREQ request tracking
--
-- Persists FREQ requests initiated by freq_getfile.php so that a subsequent
-- binkp session (inbound or outbound) can route received response files to
-- the correct user's private file area, even when the remote node fulfils
-- the request asynchronously in a separate session.

CREATE TABLE IF NOT EXISTS freq_requests_outbound (
    id              SERIAL PRIMARY KEY,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    node_address    VARCHAR(50)  NOT NULL,
    requested_files TEXT         NOT NULL,  -- JSON array of filenames / magic names
    user_id         INTEGER      REFERENCES users(id) ON DELETE SET NULL,
    mode            VARCHAR(10)  NOT NULL DEFAULT 'req',  -- 'req' | 'mget'
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending',  -- pending | complete
    completed_at    TIMESTAMPTZ
);

-- Partial index: only pending requests need fast lookup by node address
CREATE INDEX IF NOT EXISTS idx_freq_req_out_pending
    ON freq_requests_outbound (node_address, created_at DESC)
    WHERE status = 'pending';
