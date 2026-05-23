-- Migration: 20260523080229 - add_qwk_network
-- Created: 2026-05-23 08:02:29 UTC

INSERT INTO networks (domain, name, description, website, network_type, allow_markup, allow_media, default_charset, posting_name_policy, is_builtin)
VALUES (
    'qwk',
    'QWK',
    'Miscellaneous QWK Areas',
    NULL,
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
    network_type        = EXCLUDED.network_type,
    allow_markup        = EXCLUDED.allow_markup,
    allow_media         = EXCLUDED.allow_media,
    default_charset     = EXCLUDED.default_charset,
    posting_name_policy = EXCLUDED.posting_name_policy,
    is_builtin          = EXCLUDED.is_builtin,
    updated_at          = NOW() AT TIME ZONE 'UTC';
