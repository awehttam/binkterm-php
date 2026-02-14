/**
 * Migration: 1.10.1 - Add WebSocket Authentication Token
 *
 * Adds ws_token column to door_sessions for WebSocket authentication.
 * This prevents unauthorized users from connecting to active door sessions.
 */

-- Add WebSocket authentication token column
ALTER TABLE door_sessions
ADD COLUMN IF NOT EXISTS ws_token VARCHAR(64);

-- Add index for token lookups
CREATE INDEX IF NOT EXISTS idx_door_sessions_ws_token ON door_sessions(ws_token);

COMMENT ON COLUMN door_sessions.ws_token IS 'Authentication token for WebSocket connection';
