-- Migration: 20260926031305 - add areafix grammar memory table
-- Created: 2026-09-26 03:13:05 UTC

-- Per-uplink AreaFix/FileFix parser tier memory (PR460Proposal Improvement 6).
-- Records which AreaFixParser tier ("mystic_blocks", "delimited_table",
-- "columnar_table", "quoted_address_list", "flagged_dotted_quoted_list",
-- "configured:<grammar_id>", or "freeform") successfully parsed the most
-- recent CONFIRMED sync for a given uplink+domain+robot, so the next reply
-- from that uplink can be tried against the remembered tier first, and a
-- change in tier can be flagged to the sysop on the preview screen.
CREATE TABLE IF NOT EXISTS areafix_grammar_memory (
    id SERIAL PRIMARY KEY,
    uplink_address VARCHAR(60) NOT NULL,
    domain VARCHAR(50) NOT NULL,
    robot VARCHAR(10) NOT NULL,
    tier VARCHAR(120) NOT NULL,
    last_matched_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (uplink_address, domain, robot)
);
