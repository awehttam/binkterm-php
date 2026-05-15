CREATE TABLE IF NOT EXISTS meshcore_contacts (
    id             SERIAL PRIMARY KEY,
    bridge_node_id INTEGER REFERENCES packet_bbs_nodes(id) ON DELETE SET NULL,
    pub_key_full   CHAR(64),             -- full 64-char hex key; unique when known
    pub_key_prefix VARCHAR(12) NOT NULL, -- first 12 hex chars, for display only (NOT unique)
    name           VARCHAR(64),
    adv_type       VARCHAR(32),
    user_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
    lat            NUMERIC(10,6),
    lon            NUMERIC(10,6),
    last_seen_at   TIMESTAMPTZ,
    notes          TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Partial unique index: two records may share a prefix, but the full key is globally unique.
-- NULL is allowed so users can register by 12-char prefix before the bridge has seen the node.
CREATE UNIQUE INDEX IF NOT EXISTS meshcore_contacts_pub_key_full_idx
    ON meshcore_contacts(pub_key_full)
    WHERE pub_key_full IS NOT NULL;

CREATE INDEX IF NOT EXISTS meshcore_contacts_pub_key_prefix_idx ON meshcore_contacts(pub_key_prefix);
CREATE INDEX IF NOT EXISTS meshcore_contacts_bridge_node_id_idx  ON meshcore_contacts(bridge_node_id);
CREATE INDEX IF NOT EXISTS meshcore_contacts_user_id_idx         ON meshcore_contacts(user_id);
