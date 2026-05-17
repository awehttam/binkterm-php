ALTER TABLE packet_bbs_nodes
    ADD COLUMN IF NOT EXISTS location VARCHAR(255);
