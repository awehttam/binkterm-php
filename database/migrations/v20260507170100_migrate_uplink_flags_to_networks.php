<?php
// Migration: 20260507170100 - Migrate uplink flags to networks
// Created: 2026-05-07 17:01:00 UTC

return function(\PDO $db): bool {
    $projectRoot = dirname(__DIR__, 2);
    $configPath = $projectRoot . '/config/binkp.json';

    $flagKeys = ['allow_markup', 'allow_markdown', 'allow_media', 'default_charset', 'posting_name_policy'];
    $byDomain = [];
    $warnings = [];
    $stripped = 0;
    $config = [];

    if (!file_exists($configPath)) {
        echo "No config/binkp.json found - only echoarea domains will be backfilled.\n";
    } else {
        $raw = file_get_contents($configPath);
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            echo "config/binkp.json is not valid JSON - only echoarea domains will be backfilled.\n";
        } else {
            $config = $decoded;
        }
    }

    if (!empty($config['uplinks']) && is_array($config['uplinks'])) {
        foreach ($config['uplinks'] as $index => &$uplink) {
            if (!is_array($uplink)) {
                continue;
            }

            $domain = strtolower(trim((string)($uplink['domain'] ?? '')));
            if ($domain === '') {
                continue;
            }

            $charset = trim((string)($uplink['default_charset'] ?? ''));
            $values = [
                'allow_markup' => array_key_exists('allow_markup', $uplink) || array_key_exists('allow_markdown', $uplink)
                    ? (!empty($uplink['allow_markup']) || !empty($uplink['allow_markdown']))
                    : null,
                'allow_media' => array_key_exists('allow_media', $uplink) ? (bool)$uplink['allow_media'] : null,
                'default_charset' => array_key_exists('default_charset', $uplink) && $charset !== ''
                    ? \BinktermPHP\Binkp\Config\BinkpConfig::normalizeCharset($charset)
                    : null,
                'posting_name_policy' => array_key_exists('posting_name_policy', $uplink)
                    && in_array(strtolower(trim((string)$uplink['posting_name_policy'])), ['real_name', 'username'], true)
                    ? strtolower(trim((string)$uplink['posting_name_policy']))
                    : null,
            ];

            if (!isset($byDomain[$domain])) {
                $byDomain[$domain] = $values;
            } elseif ($byDomain[$domain] !== $values) {
                $warnings[] = "Conflicting network flags for domain {$domain}; first uplink wins.";
            }

            foreach ($flagKeys as $key) {
                if (array_key_exists($key, $uplink)) {
                    unset($uplink[$key]);
                    $stripped++;
                }
            }
        }
        unset($uplink);
    }

    $insert = $db->prepare("
        INSERT INTO networks (domain, name, allow_markup, allow_media, default_charset, posting_name_policy, is_builtin)
        VALUES (?, ?, ?, ?, ?, ?, FALSE)
        ON CONFLICT (domain) DO NOTHING
    ");
    $update = $db->prepare("
        UPDATE networks
        SET allow_markup = COALESCE(?, allow_markup),
            allow_media = COALESCE(?, allow_media),
            default_charset = COALESCE(?, default_charset),
            posting_name_policy = COALESCE(?, posting_name_policy),
            updated_at = NOW() AT TIME ZONE 'UTC'
        WHERE LOWER(domain) = LOWER(?)
    ");

    foreach ($byDomain as $domain => $values) {
        $insert->execute([
            $domain,
            ucfirst($domain),
            $values['allow_markup'] === true ? 'true' : 'false',
            $values['allow_media'] === true ? 'true' : 'false',
            $values['default_charset'],
            $values['posting_name_policy'] ?? 'real_name',
        ]);
        $update->execute([
            $values['allow_markup'] === null ? null : ($values['allow_markup'] ? 'true' : 'false'),
            $values['allow_media'] === null ? null : ($values['allow_media'] ? 'true' : 'false'),
            $values['default_charset'],
            $values['posting_name_policy'],
            $domain,
        ]);
    }

    $domainStmt = $db->query("
        SELECT DISTINCT LOWER(TRIM(domain)) AS domain
        FROM echoareas
        WHERE domain IS NOT NULL AND TRIM(domain) <> ''
    ");
    foreach ($domainStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $domain) {
        $insert->execute([$domain, ucfirst((string)$domain), 'false', 'false', null, 'real_name']);
    }

    $db->exec("
        UPDATE networks
        SET default_charset = 'CP437',
            updated_at = NOW() AT TIME ZONE 'UTC'
        WHERE default_charset IS NULL
          AND LOWER(domain) = 'fidonet'
    ");

    if ($stripped > 0) {
        $client = new \BinktermPHP\Admin\AdminDaemonClient();
        $client->setFullBinkpConfig($config);
        echo "Removed migrated network flags from config/binkp.json.\n";
    }

    foreach (array_unique($warnings) as $warning) {
        if (function_exists('getServerLogger')) {
            getServerLogger()->warning($warning);
        }
        echo $warning . "\n";
    }

    return true;
};
