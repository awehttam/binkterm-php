-- FREQ (File REQuest) support
-- Adds freq access control to file_areas and shared_files,
-- a FREQ request log, an outbound delivery queue, and
-- outbound FREQ status tracking on netmail.

-- File area FREQ settings
ALTER TABLE file_areas
    ADD COLUMN freq_enabled  BOOLEAN      NOT NULL DEFAULT FALSE,
    ADD COLUMN freq_password VARCHAR(255);

CREATE INDEX idx_file_areas_freq
    ON file_areas (freq_enabled)
    WHERE freq_enabled = TRUE;

COMMENT ON COLUMN file_areas.freq_enabled  IS 'If true, all approved files in this area are FREQable by any node';
COMMENT ON COLUMN file_areas.freq_password IS 'Optional password required to FREQ files from this area';

-- Per-share FREQ accessibility
ALTER TABLE shared_files
    ADD COLUMN freq_accessible BOOLEAN NOT NULL DEFAULT TRUE;

COMMENT ON COLUMN shared_files.freq_accessible IS 'If true, this shared file is also accessible via binkp FREQ';

-- FREQ request log
CREATE TABLE freq_log (
    id               SERIAL       PRIMARY KEY,
    requested_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    requesting_node  VARCHAR(50)  NOT NULL,
    filename         VARCHAR(255) NOT NULL,
    resolved_file_id INTEGER      REFERENCES files(id) ON DELETE SET NULL,
    served           BOOLEAN      NOT NULL DEFAULT FALSE,
    deny_reason      VARCHAR(100),
    file_size        BIGINT,
    source           VARCHAR(20)  NOT NULL DEFAULT 'binkp', -- 'binkp' | 'netmail'
    session_id       VARCHAR(64)
);

CREATE INDEX idx_freq_log_node ON freq_log (requesting_node);
CREATE INDEX idx_freq_log_time ON freq_log (requested_at DESC);

-- Outbound FREQ file delivery queue
CREATE TABLE freq_outbound (
    id                SERIAL       PRIMARY KEY,
    to_address        VARCHAR(50)  NOT NULL,
    file_path         TEXT         NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size         BIGINT       NOT NULL DEFAULT 0,
    freq_log_id       INTEGER      REFERENCES freq_log(id) ON DELETE SET NULL,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    sent_at           TIMESTAMPTZ,
    status            VARCHAR(20)  NOT NULL DEFAULT 'pending' -- pending | sent | failed
);

CREATE INDEX idx_freq_outbound_pending
    ON freq_outbound (to_address, created_at)
    WHERE status = 'pending';

-- Outbound FREQ status tracking on sent netmail
ALTER TABLE netmail
    ADD COLUMN freq_status VARCHAR(20);

COMMENT ON COLUMN netmail.freq_status IS 'FREQ fulfillment status: pending, fulfilled, denied (null = not a FREQ or pre-dates this feature)';
