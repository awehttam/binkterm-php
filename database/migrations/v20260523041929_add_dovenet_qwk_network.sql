-- Migration: 20260523041929 - add dovenet qwk network
-- Created: 2026-05-23 04:19:29 UTC

INSERT INTO networks (domain, name, description, website, network_type, allow_markup, allow_media, default_charset, posting_name_policy, is_builtin)
VALUES (
    'dovenetqwk',
    'Dovenet QWK',
    'Main Dovenet QWK Network',
    'https://wiki.synchro.net/network:dove-net',
    2,
    FALSE,
    FALSE,
    'CP437',
    'real_name',
    TRUE
)
ON CONFLICT (domain) DO UPDATE SET
    name                = EXCLUDED.name,
    description         = EXCLUDED.description,
    website             = EXCLUDED.website,
    network_type        = EXCLUDED.network_type,
    allow_markup        = EXCLUDED.allow_markup,
    allow_media         = EXCLUDED.allow_media,
    default_charset     = EXCLUDED.default_charset,
    posting_name_policy = EXCLUDED.posting_name_policy,
    is_builtin          = EXCLUDED.is_builtin,
    updated_at          = NOW() AT TIME ZONE 'UTC';

UPDATE networks
SET name = 'Dovenet FTN (clrghouz)',
    description = 'DoveNet FTN network via the clrghouz FTN gateway.',
    updated_at = NOW() AT TIME ZONE 'UTC'
WHERE domain = 'dovenet';
