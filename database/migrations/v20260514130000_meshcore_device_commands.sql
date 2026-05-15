-- Queue for bridge-side device commands (e.g. remove_contact).
-- Rows are inserted when a user/admin deletes a bridged contact.
-- The bridge polls for unexecuted rows and ACKs them after sending the command to the radio.

CREATE TABLE IF NOT EXISTS meshcore_device_commands (
    id             SERIAL PRIMARY KEY,
    bridge_node_id INTEGER NOT NULL REFERENCES packet_bbs_nodes(id) ON DELETE CASCADE,
    command_type   VARCHAR(32) NOT NULL,
    payload        JSONB NOT NULL DEFAULT '{}',
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    executed_at    TIMESTAMPTZ
);

-- Partial index for fast pending-command lookups per bridge node.
CREATE INDEX IF NOT EXISTS meshcore_device_commands_pending_idx
    ON meshcore_device_commands(bridge_node_id, created_at)
    WHERE executed_at IS NULL;
