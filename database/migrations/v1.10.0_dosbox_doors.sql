/**
 * Migration: 1.10.0 - DOSBox Door System
 *
 * Adds database tables for DOSBox door game management:
 * - dosbox_doors: Door game definitions and configuration
 * - door_sessions: Active and historical door session tracking
 * - door_session_logs: Session activity and event logging
 */

-- Door game definitions
CREATE TABLE IF NOT EXISTS dosbox_doors (
    id SERIAL PRIMARY KEY,
    door_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    executable VARCHAR(100) NOT NULL,
    path VARCHAR(255) NOT NULL,
    config JSONB,
    enabled BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_dosbox_doors_enabled ON dosbox_doors(enabled);
CREATE INDEX IF NOT EXISTS idx_dosbox_doors_door_id ON dosbox_doors(door_id);

COMMENT ON TABLE dosbox_doors IS 'DOSBox door game definitions and configuration';
COMMENT ON COLUMN dosbox_doors.door_id IS 'Unique identifier for the door (e.g., "lord", "tw2002")';
COMMENT ON COLUMN dosbox_doors.executable IS 'Main executable filename (e.g., "LORD.EXE")';
COMMENT ON COLUMN dosbox_doors.path IS 'Relative path to door directory from DOS root';
COMMENT ON COLUMN dosbox_doors.config IS 'JSON configuration: timeLimit, cpuCycles, maxSessions, dropFileType, etc.';

-- Active door sessions
CREATE TABLE IF NOT EXISTS door_sessions (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(64) UNIQUE NOT NULL,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    door_id VARCHAR(50) REFERENCES dosbox_doors(door_id) ON DELETE CASCADE,
    node_number INTEGER NOT NULL,
    tcp_port INTEGER NOT NULL,
    ws_port INTEGER NOT NULL,
    dosbox_pid INTEGER,
    bridge_pid INTEGER,
    session_path VARCHAR(255),
    started_at TIMESTAMPTZ DEFAULT NOW(),
    ended_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ NOT NULL,
    exit_status VARCHAR(50)
);

CREATE INDEX IF NOT EXISTS idx_door_sessions_session_id ON door_sessions(session_id);
CREATE INDEX IF NOT EXISTS idx_door_sessions_user_id ON door_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_door_sessions_door_id ON door_sessions(door_id);
CREATE INDEX IF NOT EXISTS idx_door_sessions_expires_at ON door_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_door_sessions_active ON door_sessions(ended_at) WHERE ended_at IS NULL;

COMMENT ON TABLE door_sessions IS 'Active and historical DOSBox door game sessions';
COMMENT ON COLUMN door_sessions.session_id IS 'Unique session identifier (e.g., "door_1_node1_1234567890")';
COMMENT ON COLUMN door_sessions.node_number IS 'Node number assigned to this session (1-100)';
COMMENT ON COLUMN door_sessions.tcp_port IS 'TCP port for DOSBox nullmodem connection';
COMMENT ON COLUMN door_sessions.ws_port IS 'WebSocket port for browser client';
COMMENT ON COLUMN door_sessions.dosbox_pid IS 'Process ID of DOSBox instance';
COMMENT ON COLUMN door_sessions.bridge_pid IS 'Process ID of bridge server';
COMMENT ON COLUMN door_sessions.expires_at IS 'When the session should be automatically terminated';
COMMENT ON COLUMN door_sessions.exit_status IS 'Exit status: normal, timeout, crashed, killed, etc.';

-- Session activity logs
CREATE TABLE IF NOT EXISTS door_session_logs (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(64) REFERENCES door_sessions(session_id) ON DELETE CASCADE,
    event_type VARCHAR(50) NOT NULL,
    event_data JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_door_session_logs_session_id ON door_session_logs(session_id);
CREATE INDEX IF NOT EXISTS idx_door_session_logs_created_at ON door_session_logs(created_at);

COMMENT ON TABLE door_session_logs IS 'Activity and event logs for door sessions';
COMMENT ON COLUMN door_session_logs.event_type IS 'Event type: launched, connected, disconnected, error, terminated, etc.';
COMMENT ON COLUMN door_session_logs.event_data IS 'Additional event-specific data (JSON)';
