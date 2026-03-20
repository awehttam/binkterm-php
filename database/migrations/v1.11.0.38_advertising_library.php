<?php
/**
 * Migration: 1.11.0.38 - Add advertising library tables and import legacy ANSI ads
 */

function advertisingMigrationSlugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string)$slug, '-');
    if ($slug === '') {
        return 'ad';
    }

    return substr($slug, 0, 120);
}

function advertisingMigrationEnsureUtf8(string $text): string
{
    if ($text === '') {
        return '';
    }

    if (mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }

    $converted = @iconv('CP437', 'UTF-8//IGNORE', $text);
    if ($converted !== false && $converted !== '') {
        return $converted;
    }

    $detected = mb_detect_encoding($text, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true);
    if ($detected !== false) {
        return mb_convert_encoding($text, 'UTF-8', $detected);
    }

    return mb_convert_encoding($text, 'UTF-8', 'CP437');
}

return function ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS advertisements (
            id SERIAL PRIMARY KEY,
            slug VARCHAR(120) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT '',
            content TEXT NOT NULL,
            content_hash VARCHAR(64) NOT NULL,
            source_type VARCHAR(32) NOT NULL DEFAULT 'upload',
            legacy_filename VARCHAR(255) DEFAULT NULL,
            created_by_user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
            updated_by_user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            show_on_dashboard BOOLEAN NOT NULL DEFAULT TRUE,
            allow_auto_post BOOLEAN NOT NULL DEFAULT FALSE,
            dashboard_weight INTEGER NOT NULL DEFAULT 1,
            dashboard_priority INTEGER NOT NULL DEFAULT 0,
            start_at TIMESTAMPTZ DEFAULT NULL,
            end_at TIMESTAMPTZ DEFAULT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_advertisements_dashboard
            ON advertisements (is_active, show_on_dashboard, dashboard_priority, updated_at)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_advertisements_content_hash
            ON advertisements (content_hash)
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS advertisement_tags (
            id SERIAL PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            slug VARCHAR(80) NOT NULL UNIQUE
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS advertisement_tag_map (
            advertisement_id INTEGER NOT NULL REFERENCES advertisements(id) ON DELETE CASCADE,
            tag_id INTEGER NOT NULL REFERENCES advertisement_tags(id) ON DELETE CASCADE,
            PRIMARY KEY (advertisement_id, tag_id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS advertisement_campaigns (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT '',
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            from_user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
            to_name VARCHAR(255) NOT NULL DEFAULT 'All',
            selection_mode VARCHAR(32) NOT NULL DEFAULT 'weighted_random',
            post_interval_minutes INTEGER NOT NULL DEFAULT 10080,
            min_repeat_gap_minutes INTEGER NOT NULL DEFAULT 10080,
            last_posted_at TIMESTAMPTZ DEFAULT NULL,
            last_posted_ad_id INTEGER DEFAULT NULL REFERENCES advertisements(id) ON DELETE SET NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS advertisement_campaign_targets (
            id SERIAL PRIMARY KEY,
            campaign_id INTEGER NOT NULL REFERENCES advertisement_campaigns(id) ON DELETE CASCADE,
            echoarea_tag VARCHAR(255) NOT NULL,
            domain VARCHAR(100) NOT NULL,
            subject_template VARCHAR(255) NOT NULL DEFAULT 'BBS Advertisement',
            is_active BOOLEAN NOT NULL DEFAULT TRUE
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_ad_campaign_targets_campaign
            ON advertisement_campaign_targets (campaign_id, is_active)
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS advertisement_campaign_ads (
            campaign_id INTEGER NOT NULL REFERENCES advertisement_campaigns(id) ON DELETE CASCADE,
            advertisement_id INTEGER NOT NULL REFERENCES advertisements(id) ON DELETE CASCADE,
            weight INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (campaign_id, advertisement_id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS advertisement_post_log (
            id SERIAL PRIMARY KEY,
            advertisement_id INTEGER DEFAULT NULL REFERENCES advertisements(id) ON DELETE SET NULL,
            campaign_id INTEGER DEFAULT NULL REFERENCES advertisement_campaigns(id) ON DELETE SET NULL,
            message_id INTEGER DEFAULT NULL,
            echoarea_tag VARCHAR(255) NOT NULL,
            domain VARCHAR(100) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            posted_by_user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
            post_mode VARCHAR(32) NOT NULL DEFAULT 'manual',
            posted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            status VARCHAR(32) NOT NULL DEFAULT 'success',
            error_text TEXT DEFAULT NULL
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_ad_post_log_advertisement
            ON advertisement_post_log (advertisement_id, posted_at DESC)
    ");

    $adsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bbs_ads';
    if (!is_dir($adsDir)) {
        return true;
    }

    $selectByLegacy = $db->prepare("SELECT id FROM advertisements WHERE legacy_filename = ?");
    $selectBySlug = $db->prepare("SELECT id FROM advertisements WHERE slug = ?");
    $insertAd = $db->prepare("
        INSERT INTO advertisements (
            slug,
            title,
            description,
            content,
            content_hash,
            source_type,
            legacy_filename,
            is_active,
            show_on_dashboard,
            allow_auto_post,
            dashboard_weight,
            dashboard_priority
        ) VALUES (?, ?, '', ?, ?, 'legacy_import', ?, TRUE, TRUE, TRUE, 1, 0)
    ");

    foreach (glob($adsDir . DIRECTORY_SEPARATOR . '*.ans') ?: [] as $path) {
        $legacyFilename = basename($path);

        $selectByLegacy->execute([$legacyFilename]);
        if ($selectByLegacy->fetch(\PDO::FETCH_ASSOC)) {
            continue;
        }

        $rawContent = @file_get_contents($path);
        if ($rawContent === false) {
            continue;
        }

        $content = advertisingMigrationEnsureUtf8($rawContent);
        $contentHash = hash('sha256', $content);
        $title = pathinfo($legacyFilename, PATHINFO_FILENAME);
        $baseSlug = advertisingMigrationSlugify($title !== '' ? $title : $legacyFilename);
        $slug = $baseSlug;
        $suffix = 2;

        while (true) {
            $selectBySlug->execute([$slug]);
            if (!$selectBySlug->fetch(\PDO::FETCH_ASSOC)) {
                break;
            }
            $slug = substr($baseSlug, 0, 110) . '-' . $suffix;
            $suffix++;
        }

        $insertAd->execute([
            $slug,
            $title !== '' ? $title : $legacyFilename,
            $content,
            $contentHash,
            $legacyFilename
        ]);
    }

    return true;
};
