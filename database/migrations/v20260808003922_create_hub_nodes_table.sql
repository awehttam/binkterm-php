-- Migration: 20260808003922 - create hub_nodes table
-- Created: 2026-08-08 00:39:22 UTC

-- Subordinate FTN systems BinktermPHP distributes echomail/netmail to.
-- A subordinate is either a 'node' (independently-addressed peer/downlink)
-- or a 'point' (addressed as one of our own AKAs plus a point number,
-- e.g. 1:153/149.1). See docs/proposals/HubPointSystemJuly2026.md.
CREATE TABLE hub_nodes (
    id                      SERIAL PRIMARY KEY,
    node_type               VARCHAR(10)  NOT NULL DEFAULT 'node',
    node_address             VARCHAR(50)  NOT NULL UNIQUE,
    boss_address             VARCHAR(50),
    point_number             INTEGER,
    name                     VARCHAR(100),
    sysop_name               VARCHAR(100),
    session_password         VARCHAR(255),
    packet_password          VARCHAR(255),
    inet_host                VARCHAR(255),
    port                     INTEGER,
    enabled                  BOOLEAN     NOT NULL DEFAULT TRUE,
    allow_inbound            BOOLEAN     NOT NULL DEFAULT TRUE,
    allow_outbound           BOOLEAN     NOT NULL DEFAULT TRUE,
    allow_inbound_echomail   BOOLEAN     NOT NULL DEFAULT TRUE,
    allow_inbound_netmail    BOOLEAN     NOT NULL DEFAULT TRUE,
    max_packet_kb            INTEGER     NOT NULL DEFAULT 0,
    hold_mail                BOOLEAN     NOT NULL DEFAULT FALSE,
    queue_retention_days     INTEGER     NOT NULL DEFAULT 30,
    capability_flags         VARCHAR(50),
    notes                    TEXT,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_session_at          TIMESTAMPTZ,
    CONSTRAINT chk_hub_nodes_node_type CHECK (node_type IN ('node', 'point')),
    CONSTRAINT chk_hub_nodes_point_fields CHECK (
        (node_type = 'point' AND boss_address IS NOT NULL AND point_number IS NOT NULL)
        OR
        (node_type = 'node' AND boss_address IS NULL AND point_number IS NULL)
    )
);

CREATE INDEX idx_hub_nodes_enabled ON hub_nodes (enabled) WHERE enabled = TRUE;
CREATE INDEX idx_hub_nodes_boss    ON hub_nodes (boss_address) WHERE node_type = 'point';
