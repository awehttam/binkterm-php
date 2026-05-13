-- Migration: 20260513221608 - fix dovenet website url
-- Created: 2026-05-13 22:16:08 UTC

UPDATE networks
SET website = 'https://clrghouz.bbs.dege.au/domain/view/34',
    updated_at = NOW() AT TIME ZONE 'UTC'
WHERE domain = 'dovenet';

