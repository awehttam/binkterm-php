-- Packet BBS sessions are keyed by the remote radio sender node ID.
-- Those senders do not need to exist in packet_bbs_nodes, which stores
-- registered bridge device nodes and their API keys.
ALTER TABLE packet_bbs_sessions
    DROP CONSTRAINT IF EXISTS packet_bbs_sessions_node_id_fkey;
