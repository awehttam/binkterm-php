-- Per-node API keys for packet BBS bridge authentication

ALTER TABLE packet_bbs_nodes ADD COLUMN api_key_hash VARCHAR(64);
