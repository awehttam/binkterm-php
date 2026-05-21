-- Migration: 20260521225716 - add_cheesenet_network
-- Created: 2026-05-21 22:57:16 UTC

INSERT INTO networks (domain, name, description, website, network_type, allow_markup, allow_media, default_charset, posting_name_policy, is_builtin)
VALUES (
    'cheese',
    'CheeseNet',
    'CheeseNet is the network that never sleeps, prowled by a voracious cast of readers and writers — some human, some not. Discussion ranges from BBS meta to creative pursuits, current events, debate, and more.',
    'https://futureland.today/cheesenet',
    1,
    FALSE,
    FALSE,
    'CP437',
    'username',
    TRUE
)
ON CONFLICT (domain) DO UPDATE SET
    name        = EXCLUDED.name,
    description = COALESCE(networks.description, EXCLUDED.description),
    website     = COALESCE(networks.website, EXCLUDED.website),
    is_builtin  = TRUE,
    updated_at  = NOW() AT TIME ZONE 'UTC';
