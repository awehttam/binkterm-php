-- Migration: 1.11.0.77 - Relax door_sessions constraints for JS-DOS sessions
--
-- jsdos sessions store a game ID in door_id that has no matching row in
-- dosbox_doors, so the foreign key constraint must be dropped. node_number
-- and ws_port are also not meaningful for browser-side sessions, so they
-- are made nullable.

-- Drop the foreign-key constraint so jsdos game IDs can be stored in door_id
ALTER TABLE door_sessions DROP CONSTRAINT IF EXISTS door_sessions_door_id_fkey;

-- node_number is not used by jsdos sessions (no server-side process)
ALTER TABLE door_sessions ALTER COLUMN node_number DROP NOT NULL;

-- ws_port is not used by jsdos sessions (no server-side WebSocket bridge)
ALTER TABLE door_sessions ALTER COLUMN ws_port DROP NOT NULL;

COMMENT ON COLUMN door_sessions.door_id IS 'Door or game ID. For dos/native sessions references dosbox_doors(door_id); for jsdos sessions contains the jsdos game ID directly.';
COMMENT ON COLUMN door_sessions.node_number IS 'Node number for dos/native sessions (NULL for jsdos sessions)';
COMMENT ON COLUMN door_sessions.ws_port IS 'WebSocket port for dos/native sessions (NULL for jsdos sessions)';
