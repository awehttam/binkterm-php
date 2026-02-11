-- Allow NULL for tcp_port and dosbox_pid since they're set by bridge after WebSocket connects
-- This supports the v3 architecture where PHP creates session record, bridge allocates port and launches DOSBox

ALTER TABLE door_sessions
ALTER COLUMN tcp_port DROP NOT NULL;

ALTER TABLE door_sessions
ALTER COLUMN dosbox_pid DROP NOT NULL;

COMMENT ON COLUMN door_sessions.tcp_port IS 'TCP port allocated by bridge (NULL until bridge allocates)';
COMMENT ON COLUMN door_sessions.dosbox_pid IS 'DOSBox process ID (NULL until bridge launches)';
