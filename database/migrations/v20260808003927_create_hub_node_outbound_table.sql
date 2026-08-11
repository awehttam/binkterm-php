-- Migration: 20260808003927 - create hub_node_outbound table
-- Created: 2026-08-08 00:39:27 UTC

-- Per-subordinate outbound packet queue for the hub fanout engine. Distinct
-- from the existing uplink outbound mechanism (a flat-file .pkt drop
-- directory, data/outbound/, see src/Binkp/Queue/OutboundQueue.php) since
-- fanout to many subordinates needs per-destination retry/status tracking.
-- See docs/proposals/HubPointSystemJuly2026.md.
CREATE TABLE hub_node_outbound (
    id              SERIAL PRIMARY KEY,
    hub_node_id     INTEGER     NOT NULL REFERENCES hub_nodes(id) ON DELETE CASCADE,
    message_type    VARCHAR(20) NOT NULL DEFAULT 'echomail',
    echoarea_id     INTEGER     REFERENCES echoareas(id) ON DELETE SET NULL,
    echomail_id     INTEGER     REFERENCES echomail(id)  ON DELETE SET NULL,
    netmail_id      INTEGER     REFERENCES netmail(id)   ON DELETE SET NULL,
    packet_data     BYTEA       NOT NULL,
    size_bytes      INTEGER     NOT NULL DEFAULT 0,
    priority        SMALLINT    NOT NULL DEFAULT 5,
    attempts        SMALLINT    NOT NULL DEFAULT 0,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    sent_at         TIMESTAMPTZ,
    error_message   TEXT,
    CONSTRAINT chk_hno_message_type CHECK (message_type IN ('echomail', 'netmail')),
    CONSTRAINT chk_hno_status CHECK (status IN ('pending', 'sent', 'failed', 'held'))
);

CREATE INDEX idx_hno_pending ON hub_node_outbound (hub_node_id, next_attempt_at)
    WHERE status = 'pending';
CREATE INDEX idx_hno_node ON hub_node_outbound (hub_node_id);
