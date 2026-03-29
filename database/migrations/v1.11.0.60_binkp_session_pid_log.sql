-- Migration: v1.11.0.60_binkp_session_pid_log.sql
-- Description: Store process ID and log file on binkp_session_log rows

ALTER TABLE binkp_session_log
    ADD COLUMN IF NOT EXISTS process_id INTEGER,
    ADD COLUMN IF NOT EXISTS log_file VARCHAR(255);

CREATE INDEX IF NOT EXISTS idx_binkp_session_log_process_id
    ON binkp_session_log(process_id, started_at);

COMMENT ON COLUMN binkp_session_log.process_id IS 'Operating system process ID that handled the session';
COMMENT ON COLUMN binkp_session_log.log_file IS 'Log file basename associated with the process handling the session';
