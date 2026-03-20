-- Migration: v1.11.0.46_drop_qwk_user_conference_map
--
-- The QWK numbering model now uses canonical BBS-wide conference IDs stored
-- on echoareas.qwk_conference_number. The temporary per-user mapping table is
-- no longer used and can be dropped.

DROP TABLE IF EXISTS qwk_user_conference_map;
