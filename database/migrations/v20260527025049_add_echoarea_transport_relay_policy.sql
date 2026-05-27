-- Migration: 20260527025049 - add_echoarea_transport_relay_policy
-- Created: 2026-05-27 02:50:49 UTC

ALTER TABLE echoareas
    ADD COLUMN relay_mode VARCHAR(20) NOT NULL DEFAULT 'auto',
    ADD CONSTRAINT chk_echoareas_relay_mode
        CHECK (relay_mode IN ('none', 'auto', 'manual'));

CREATE TABLE echo_area_relay_rules (
    id SERIAL PRIMARY KEY,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    origin_type VARCHAR(50) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    is_allowed BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_echo_area_relay_rules_types
        CHECK (origin_type <> '' AND target_type <> ''),
    CONSTRAINT uq_echo_area_relay_rules UNIQUE (echoarea_id, origin_type, target_type)
);
