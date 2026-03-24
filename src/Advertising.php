<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


namespace BinktermPHP;

use PDO;

class Advertising
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    public function getAdsDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bbs_ads';
    }

    /**
     * Return the project base directory.
     */
    public static function getBaseDir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Return the list of allowed content commands as [{label, value}] pairs.
     *
     * Allowed commands are:
     *  - Any file inside the project's content_commands/ directory
     *  - scripts/weather_report.php, scripts/report_newfiles.php, and scripts/generate_ad.php (whitelisted scripts)
     *
     * Values are repo-relative paths (e.g. "content_commands/my_script.php").
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function getAvailableContentCommands(): array
    {
        $base = self::getBaseDir();
        $commands = [];

        // Scan content_commands/ directory for any files
        $contentCommandsDir = $base . '/content_commands';
        if (is_dir($contentCommandsDir)) {
            $files = glob($contentCommandsDir . '/*') ?: [];
            sort($files);
            foreach ($files as $file) {
                if (is_file($file)) {
                    $commands[] = [
                        'label' => basename($file),
                        'value' => 'content_commands/' . basename($file),
                    ];
                }
            }
        }

        // Whitelisted scripts in scripts/
        $whitelisted = [
            'scripts/weather_report.php',
            'scripts/report_newfiles.php',
            'scripts/generate_ad.php',
        ];
        foreach ($whitelisted as $rel) {
            if (file_exists($base . '/' . $rel)) {
                $commands[] = ['label' => basename($rel), 'value' => $rel];
            }
        }

        return $commands;
    }

    /**
     * Return true if the given content command value is allowed.
     *
     * The value may include arguments after the script path, e.g.:
     *   "scripts/weather_report.php --city=Seattle"
     *   "content_commands/my_script.php --format=ansi"
     *
     * Validation checks only the script path (first whitespace-delimited token).
     * A script is allowed if it is one of the whitelisted scripts or a file
     * within the content_commands/ directory.
     *
     * Absolute paths, directory traversal, and null bytes are always rejected.
     * Shell metacharacters (;|!&><`$\(){}*?"'~#) are rejected at storage time
     * in addition to being neutralised at execution time via escapeshellarg().
     *
     * @param string $cmd Repo-relative path with optional args
     *                    (e.g. "content_commands/my_script.php --flag=value")
     */
    public static function validateContentCommand(string $cmd): bool
    {
        if ($cmd === '') {
            return true;
        }

        // Reject null bytes and ASCII control characters anywhere in the value
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $cmd)) {
            return false;
        }

        // Reject shell metacharacters anywhere in the value (script path or args)
        if (preg_match('/[;|!&><`$\\\\(){}\*\?"\'~#]/', $cmd)) {
            return false;
        }

        // Extract the script path — the first whitespace-delimited token
        $script = preg_split('/\s+/', trim($cmd), 2)[0];

        // Reject absolute paths and directory traversal in the script portion
        if (str_starts_with($script, '/') || str_contains($script, '..')) {
            return false;
        }

        // Whitelisted exact relative paths
        $whitelist = [
            'scripts/weather_report.php',
            'scripts/report_newfiles.php',
            'scripts/generate_ad.php',
        ];
        if (in_array($script, $whitelist, true)) {
            return true;
        }

        // Must be within content_commands/
        if (!str_starts_with($script, 'content_commands/')) {
            return false;
        }

        $base = self::getBaseDir();
        $contentCommandsDir = realpath($base . '/content_commands');
        if ($contentCommandsDir === false) {
            return false; // directory does not exist
        }

        $resolved = realpath($base . '/' . $script);
        if ($resolved === false) {
            return false; // file does not exist
        }

        return str_starts_with($resolved, $contentCommandsDir . DIRECTORY_SEPARATOR);
    }

    /**
     * Convert legacy ANSI payloads to valid UTF-8 before storing or rendering.
     */
    public static function ensureUtf8(string $text): string
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

    public static function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string)$slug, '-');

        if ($slug === '') {
            return 'ad';
        }

        return substr($slug, 0, 120);
    }

    public static function stripSauce(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $saucePos = strrpos($text, 'SAUCE00');
        if ($saucePos === false) {
            return $text;
        }

        $trailerLength = strlen($text) - $saucePos;
        if ($trailerLength > 4096) {
            return $text;
        }

        return rtrim(substr($text, 0, $saucePos), "\x1a\r\n ");
    }

    /**
     * @return string[]
     */
    public static function normalizeTags(string $tags): array
    {
        $parts = preg_split('/\s*,\s*/', trim($tags));
        $normalized = [];
        foreach ($parts ?: [] as $part) {
            $tag = trim($part);
            if ($tag === '') {
                continue;
            }
            $lower = strtolower($tag);
            if (!isset($normalized[$lower])) {
                $normalized[$lower] = $tag;
            }
        }

        return array_values($normalized);
    }

    public function getRandomAd(): ?array
    {
        $ads = $this->getDashboardAds(1);
        return $ads[0] ?? null;
    }

    public function getRandomAutoPostAd(): ?array
    {
        $stmt = $this->db->query("
            SELECT a.*,
                   COALESCE(STRING_AGG(t.name, ', ' ORDER BY LOWER(t.name)), '') AS tags_csv,
                   OCTET_LENGTH(a.content) AS size_bytes
            FROM advertisements a
            LEFT JOIN advertisement_tag_map atm ON atm.advertisement_id = a.id
            LEFT JOIN advertisement_tags t ON t.id = atm.tag_id
            WHERE a.is_active = TRUE
              AND a.allow_auto_post = TRUE
              AND (a.start_at IS NULL OR a.start_at <= NOW())
              AND (a.end_at IS NULL OR a.end_at >= NOW())
            GROUP BY a.id
            ORDER BY a.dashboard_priority DESC, a.updated_at DESC, a.id ASC
        ");

        $ads = $this->dedupeAdsByContentHash(array_map([$this, 'hydrateAd'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
        if ($ads === []) {
            return null;
        }

        return $ads[$this->pickWeightedIndex($ads)] ?? null;
    }

    public function getDashboardAds(int $limit = 1): array
    {
        $eligible = $this->dedupeAdsByContentHash($this->listEligibleDashboardAds());
        if ($eligible === []) {
            return [];
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $lastId = (int)($_SESSION['dashboard_ad_last_id'] ?? 0);
            if ($lastId > 0 && count($eligible) > 1) {
                $filtered = array_values(array_filter($eligible, static fn(array $ad): bool => (int)$ad['id'] !== $lastId));
                if ($filtered !== []) {
                    $eligible = $filtered;
                }
            }
        }

        $selected = [];
        $working = $eligible;
        $count = min($limit, count($working));
        for ($i = 0; $i < $count; $i++) {
            $index = $this->pickWeightedIndex($working);
            $selected[] = $working[$index];
            array_splice($working, $index, 1);
        }

        if ($selected !== [] && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['dashboard_ad_last_id'] = (int)$selected[0]['id'];
        }

        return array_map([$this, 'resolveAdContent'], $selected);
    }

    public function listAds(bool $includeInactive = true): array
    {
        $sql = "
            SELECT a.id,
                   a.slug,
                   a.title,
                   a.description,
                   a.content_hash,
                   a.source_type,
                   a.legacy_filename,
                   a.created_by_user_id,
                   a.updated_by_user_id,
                   a.is_active,
                   a.show_on_dashboard,
                   a.allow_auto_post,
                   a.dashboard_weight,
                   a.dashboard_priority,
                   a.click_url,
                   a.start_at,
                   a.end_at,
                   a.created_at,
                   a.updated_at,
                   COALESCE(STRING_AGG(t.name, ', ' ORDER BY LOWER(t.name)), '') AS tags_csv,
                   OCTET_LENGTH(COALESCE(a.content, '')) AS size_bytes,
                   (SELECT COUNT(*) FROM advertisement_impressions ai WHERE ai.advertisement_id = a.id) AS impression_count,
                   (SELECT COUNT(*) FROM advertisement_clicks ac WHERE ac.advertisement_id = a.id) AS click_count
            FROM advertisements a
            LEFT JOIN advertisement_tag_map atm ON atm.advertisement_id = a.id
            LEFT JOIN advertisement_tags t ON t.id = atm.tag_id
        ";

        if (!$includeInactive) {
            $sql .= " WHERE a.is_active = TRUE";
        }

        $sql .= "
            GROUP BY a.id
            ORDER BY a.dashboard_priority DESC, LOWER(a.title), a.id ASC
        ";

        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function (array $row): array {
            return $this->resolveAdContent($this->hydrateAd($row));
        }, $rows);
    }

    public function listTags(): array
    {
        $stmt = $this->db->query("
            SELECT id, name, slug
            FROM advertisement_tags
            ORDER BY LOWER(name), id ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
        }

        return $rows;
    }

    public function getAdById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT a.*,
                   COALESCE(STRING_AGG(t.name, ', ' ORDER BY LOWER(t.name)), '') AS tags_csv,
                   OCTET_LENGTH(a.content) AS size_bytes
            FROM advertisements a
            LEFT JOIN advertisement_tag_map atm ON atm.advertisement_id = a.id
            LEFT JOIN advertisement_tags t ON t.id = atm.tag_id
            WHERE a.id = ?
            GROUP BY a.id
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->resolveAdContent($this->hydrateAd($row)) : null;
    }

    public function getAdBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("
            SELECT a.*,
                   COALESCE(STRING_AGG(t.name, ', ' ORDER BY LOWER(t.name)), '') AS tags_csv,
                   OCTET_LENGTH(a.content) AS size_bytes
            FROM advertisements a
            LEFT JOIN advertisement_tag_map atm ON atm.advertisement_id = a.id
            LEFT JOIN advertisement_tags t ON t.id = atm.tag_id
            WHERE a.slug = ?
            GROUP BY a.id
        ");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->resolveAdContent($this->hydrateAd($row)) : null;
    }

    public function getAdByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if (ctype_digit($name)) {
            $byId = $this->getAdById((int)$name);
            if ($byId) {
                return $byId;
            }
        }

        $normalized = basename($name);
        $stmt = $this->db->prepare("
            SELECT a.*,
                   COALESCE(STRING_AGG(t.name, ', ' ORDER BY LOWER(t.name)), '') AS tags_csv,
                   OCTET_LENGTH(a.content) AS size_bytes
            FROM advertisements a
            LEFT JOIN advertisement_tag_map atm ON atm.advertisement_id = a.id
            LEFT JOIN advertisement_tags t ON t.id = atm.tag_id
            WHERE a.slug = ?
               OR a.legacy_filename = ?
               OR a.title = ?
            GROUP BY a.id
            ORDER BY a.id ASC
            LIMIT 1
        ");
        $stmt->execute([$name, $normalized, $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->resolveAdContent($this->hydrateAd($row)) : null;
    }

    public function createAd(array $data, ?int $userId = null): array
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Advertisement title is required');
        }

        $contentCommand = trim((string)($data['content_command'] ?? ''));
        $content = self::ensureUtf8((string)($data['content'] ?? ''));
        if ($content === '' && $contentCommand === '') {
            throw new \InvalidArgumentException('Advertisement content or a content command is required');
        }

        $slugInput = trim((string)($data['slug'] ?? ''));
        $slug = $this->getUniqueSlug($slugInput !== '' ? $slugInput : $title);
        $description = trim((string)($data['description'] ?? ''));
        $legacyFilename = $this->normalizeLegacyFilename((string)($data['legacy_filename'] ?? ''));
        $dashboardWeight = max(1, (int)($data['dashboard_weight'] ?? 1));
        $dashboardPriority = (int)($data['dashboard_priority'] ?? 0);
        $sourceType = trim((string)($data['source_type'] ?? 'upload')) ?: 'upload';

        $clickUrl = trim((string)($data['click_url'] ?? ''));

        $stmt = $this->db->prepare("
            INSERT INTO advertisements (
                slug,
                title,
                description,
                content,
                content_hash,
                content_command,
                source_type,
                legacy_filename,
                created_by_user_id,
                updated_by_user_id,
                is_active,
                show_on_dashboard,
                allow_auto_post,
                dashboard_weight,
                dashboard_priority,
                click_url,
                start_at,
                end_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");

        $stmt->execute([
            $slug,
            $title,
            $description,
            $content,
            hash('sha256', $content),
            $contentCommand !== '' ? $contentCommand : null,
            $sourceType,
            $legacyFilename !== '' ? $legacyFilename : null,
            $userId,
            $userId,
            $this->asPgBool(!empty($data['is_active'])),
            $this->asPgBool(!empty($data['show_on_dashboard'])),
            $this->asPgBool(!empty($data['allow_auto_post'])),
            $dashboardWeight,
            $dashboardPriority,
            $clickUrl !== '' ? $clickUrl : null,
            $this->normalizeTimestamp($data['start_at'] ?? null),
            $this->normalizeTimestamp($data['end_at'] ?? null)
        ]);

        $id = (int)$stmt->fetchColumn();
        $this->syncTags($id, self::normalizeTags((string)($data['tags'] ?? '')));
        return $this->getAdById($id);
    }

    public function updateAd(int $id, array $data, ?int $userId = null): array
    {
        $existing = $this->getAdById($id);
        if (!$existing) {
            throw new \RuntimeException('Advertisement not found');
        }

        $title = trim((string)($data['title'] ?? $existing['title']));
        if ($title === '') {
            throw new \InvalidArgumentException('Advertisement title is required');
        }

        $slugInput = trim((string)($data['slug'] ?? $existing['slug']));
        $slug = $this->getUniqueSlug($slugInput !== '' ? $slugInput : $title, $id);
        $contentCommand = trim((string)($data['content_command'] ?? $existing['content_command'] ?? ''));
        $content = array_key_exists('content', $data)
            ? self::ensureUtf8((string)$data['content'])
            : (string)($existing['content'] ?? '');
        if ($content === '' && $contentCommand === '') {
            throw new \InvalidArgumentException('Advertisement content or a content command is required');
        }

        $clickUrl = array_key_exists('click_url', $data)
            ? trim((string)$data['click_url'])
            : trim((string)($existing['click_url'] ?? ''));

        $stmt = $this->db->prepare("
            UPDATE advertisements
            SET slug = ?,
                title = ?,
                description = ?,
                content = ?,
                content_hash = ?,
                content_command = ?,
                legacy_filename = ?,
                updated_by_user_id = ?,
                is_active = ?,
                show_on_dashboard = ?,
                allow_auto_post = ?,
                dashboard_weight = ?,
                dashboard_priority = ?,
                click_url = ?,
                start_at = ?,
                end_at = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $slug,
            $title,
            trim((string)($data['description'] ?? $existing['description'] ?? '')),
            $content,
            hash('sha256', $content),
            $contentCommand !== '' ? $contentCommand : null,
            $this->normalizeLegacyFilename((string)($data['legacy_filename'] ?? ($existing['legacy_filename'] ?? ''))) ?: null,
            $userId,
            $this->asPgBool(!empty($data['is_active'] ?? $existing['is_active'])),
            $this->asPgBool(!empty($data['show_on_dashboard'] ?? $existing['show_on_dashboard'])),
            $this->asPgBool(!empty($data['allow_auto_post'] ?? $existing['allow_auto_post'])),
            max(1, (int)($data['dashboard_weight'] ?? $existing['dashboard_weight'] ?? 1)),
            (int)($data['dashboard_priority'] ?? $existing['dashboard_priority'] ?? 0),
            $clickUrl !== '' ? $clickUrl : null,
            $this->normalizeTimestamp($data['start_at'] ?? ($existing['start_at'] ?? null)),
            $this->normalizeTimestamp($data['end_at'] ?? ($existing['end_at'] ?? null)),
            $id
        ]);

        if (array_key_exists('tags', $data)) {
            $this->syncTags($id, self::normalizeTags((string)$data['tags']));
        }

        return $this->getAdById($id);
    }

    public function deleteAd(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM advertisements WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function listCampaigns(): array
    {
        $stmt = $this->db->query("
            SELECT c.*,
                   u.username,
                   a.title AS last_posted_ad_title,
                   COUNT(DISTINCT t.id) AS target_count,
                   COUNT(DISTINCT ca.advertisement_id) AS ad_count
            FROM advertisement_campaigns c
            LEFT JOIN users u ON u.id = c.from_user_id
            LEFT JOIN advertisements a ON a.id = c.last_posted_ad_id
            LEFT JOIN advertisement_campaign_targets t ON t.campaign_id = c.id AND t.is_active = TRUE
            LEFT JOIN advertisement_campaign_ads ca ON ca.campaign_id = c.id
            GROUP BY c.id, u.username, a.title
            ORDER BY c.is_active DESC, LOWER(c.name), c.id DESC
        ");
        $campaigns = array_map([$this, 'hydrateCampaign'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        foreach ($campaigns as &$campaign) {
            $campaign['schedules'] = $this->getCampaignSchedules((int)$campaign['id']);
            $campaign['schedule_count'] = count($campaign['schedules']);
            $campaign['schedule_summary'] = $this->summarizeCampaignSchedules($campaign['schedules']);
            $campaign['next_run'] = $this->getNextCampaignRun($campaign['schedules']);
        }

        return $campaigns;
    }

    public function getCampaignById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   u.username,
                   a.title AS last_posted_ad_title
            FROM advertisement_campaigns c
            LEFT JOIN users u ON u.id = c.from_user_id
            LEFT JOIN advertisements a ON a.id = c.last_posted_ad_id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $campaign = $this->hydrateCampaign($row);
        $campaign['targets'] = $this->getCampaignTargets($id);
        $campaign['ads'] = $this->getCampaignAds($id);
        $campaign['tag_filters'] = $this->getCampaignTagFilters($id);
        $campaign['schedules'] = $this->getCampaignSchedules($id);
        $campaign['schedule_count'] = count($campaign['schedules']);
        $campaign['schedule_summary'] = $this->summarizeCampaignSchedules($campaign['schedules']);
        $campaign['next_run'] = $this->getNextCampaignRun($campaign['schedules']);
        return $campaign;
    }

    public function createCampaign(array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Campaign name is required');
        }

        $fromUserId = (int)($data['from_user_id'] ?? 0);
        if ($fromUserId <= 0) {
            throw new \InvalidArgumentException('Posting user is required');
        }

        $targets = $this->normalizeCampaignTargets($data['targets'] ?? []);
        if ($targets === []) {
            throw new \InvalidArgumentException('At least one campaign target is required');
        }

        $ads = $this->normalizeCampaignAds($data['ads'] ?? []);
        $tagFilters = $this->normalizeCampaignTagFilters($data['tag_filters'] ?? []);
        if ($ads === [] && $tagFilters['include'] === [] && $tagFilters['exclude'] === []) {
            throw new \InvalidArgumentException('At least one advertisement or tag filter is required');
        }

        $schedules = $this->normalizeCampaignSchedules($data['schedules'] ?? []);
        if ($schedules === [] && array_key_exists('schedules', $data)) {
            throw new \InvalidArgumentException('At least one valid campaign schedule is required');
        }

        $endAt = $this->parseEndAt($data['end_at'] ?? null);

        $stmt = $this->db->prepare("
            INSERT INTO advertisement_campaigns (
                name,
                description,
                is_active,
                from_user_id,
                to_name,
                selection_mode,
                post_interval_minutes,
                min_repeat_gap_minutes,
                end_at,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ");
        $stmt->execute([
            $name,
            trim((string)($data['description'] ?? '')),
            $this->asPgBool(!empty($data['is_active'])),
            $fromUserId,
            trim((string)($data['to_name'] ?? 'All')) ?: 'All',
            trim((string)($data['selection_mode'] ?? 'weighted_random')) ?: 'weighted_random',
            max(1, (int)($data['post_interval_minutes'] ?? 10080)),
            max(0, (int)($data['min_repeat_gap_minutes'] ?? 10080)),
            $endAt
        ]);

        $campaignId = (int)$stmt->fetchColumn();
        $this->syncCampaignTargets($campaignId, $targets);
        $this->syncCampaignAds($campaignId, $ads);
        $this->syncCampaignTagFilters($campaignId, $tagFilters);
        $this->syncCampaignSchedules($campaignId, $schedules);
        return $this->getCampaignById($campaignId);
    }

    public function updateCampaign(int $id, array $data): array
    {
        $existing = $this->getCampaignById($id);
        if (!$existing) {
            throw new \RuntimeException('Campaign not found');
        }

        $name = trim((string)($data['name'] ?? $existing['name']));
        if ($name === '') {
            throw new \InvalidArgumentException('Campaign name is required');
        }

        $fromUserId = (int)($data['from_user_id'] ?? $existing['from_user_id'] ?? 0);
        if ($fromUserId <= 0) {
            throw new \InvalidArgumentException('Posting user is required');
        }

        $targets = $this->normalizeCampaignTargets($data['targets'] ?? $existing['targets'] ?? []);
        if ($targets === []) {
            throw new \InvalidArgumentException('At least one campaign target is required');
        }

        $ads = $this->normalizeCampaignAds($data['ads'] ?? $existing['ads'] ?? []);
        $tagFilters = array_key_exists('tag_filters', $data)
            ? $this->normalizeCampaignTagFilters($data['tag_filters'])
            : ($existing['tag_filters'] ?? ['include' => [], 'exclude' => []]);
        if ($ads === [] && ($tagFilters['include'] ?? []) === [] && ($tagFilters['exclude'] ?? []) === []) {
            throw new \InvalidArgumentException('At least one advertisement or tag filter is required');
        }

        $schedules = array_key_exists('schedules', $data)
            ? $this->normalizeCampaignSchedules($data['schedules'])
            : ($existing['schedules'] ?? []);
        if (array_key_exists('schedules', $data) && $schedules === []) {
            throw new \InvalidArgumentException('At least one valid campaign schedule is required');
        }

        $endAt = array_key_exists('end_at', $data)
            ? $this->parseEndAt($data['end_at'])
            : ($existing['end_at'] ?? null);

        $stmt = $this->db->prepare("
            UPDATE advertisement_campaigns
            SET name = ?,
                description = ?,
                is_active = ?,
                from_user_id = ?,
                to_name = ?,
                selection_mode = ?,
                post_interval_minutes = ?,
                min_repeat_gap_minutes = ?,
                end_at = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $name,
            trim((string)($data['description'] ?? $existing['description'] ?? '')),
            $this->asPgBool(!empty($data['is_active'] ?? $existing['is_active'])),
            $fromUserId,
            trim((string)($data['to_name'] ?? $existing['to_name'] ?? 'All')) ?: 'All',
            trim((string)($data['selection_mode'] ?? $existing['selection_mode'] ?? 'weighted_random')) ?: 'weighted_random',
            max(1, (int)($data['post_interval_minutes'] ?? $existing['post_interval_minutes'] ?? 10080)),
            max(0, (int)($data['min_repeat_gap_minutes'] ?? $existing['min_repeat_gap_minutes'] ?? 10080)),
            $endAt,
            $id
        ]);

        $this->syncCampaignTargets($id, $targets);
        $this->syncCampaignAds($id, $ads);
        $this->syncCampaignTagFilters($id, $tagFilters);
        $this->syncCampaignSchedules($id, $schedules);
        return $this->getCampaignById($id);
    }

    public function deleteCampaign(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM advertisement_campaigns WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function listPostLog(?int $campaignId = null, int $limit = 50, ?string $status = null): array
    {
        $sql = "
            SELECT l.*,
                   a.title AS advertisement_title,
                   c.name AS campaign_name,
                   u.username AS posted_by_username
            FROM advertisement_post_log l
            LEFT JOIN advertisements a ON a.id = l.advertisement_id
            LEFT JOIN advertisement_campaigns c ON c.id = l.campaign_id
            LEFT JOIN users u ON u.id = l.posted_by_user_id
        ";
        $params = [];
        $conditions = [];
        if ($campaignId !== null) {
            $conditions[] = "l.campaign_id = ?";
            $params[] = $campaignId;
        }
        if ($status !== null && $status !== '') {
            $conditions[] = "l.status = ?";
            $params[] = $status;
        }
        if ($conditions !== []) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY l.posted_at DESC LIMIT " . max(1, $limit);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function processDueCampaigns(?int $campaignId = null, bool $dryRun = false, bool $force = false): array
    {
        $campaigns = $campaignId !== null
            ? array_filter([$this->getCampaignById($campaignId)])
            : array_filter($this->listCampaigns(), static fn(array $campaign): bool => !empty($campaign['is_active']));

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $results = [];
        foreach ($campaigns as $campaign) {
            // Auto-deactivate campaigns that have passed their end_at
            if (!empty($campaign['end_at'])) {
                try {
                    $endAt = new \DateTimeImmutable((string)$campaign['end_at'], new \DateTimeZone('UTC'));
                    if ($nowUtc > $endAt) {
                        $this->db->prepare("UPDATE advertisement_campaigns SET is_active = 'false', updated_at = NOW() WHERE id = ?")
                            ->execute([(int)$campaign['id']]);
                        $results[] = [
                            'campaign_id' => (int)$campaign['id'],
                            'campaign_name' => $campaign['name'],
                            'status' => 'skipped',
                            'reason' => 'Campaign has passed its end date and has been deactivated'
                        ];
                        continue;
                    }
                } catch (\Throwable $e) {
                    // Invalid end_at — ignore and continue
                }
            }

            $dueSchedules = $force ? [['id' => null, 'legacy' => true]] : $this->getDueCampaignSchedules($campaign);
            if ($dueSchedules === []) {
                continue;
            }

            foreach ($dueSchedules as $schedule) {
                $markScheduleTriggered = false;
                foreach ($this->getCampaignTargets((int)$campaign['id']) as $target) {
                    if (empty($target['is_active'])) {
                        continue;
                    }
                    $result = $this->runCampaignTarget($campaign, $target, $dryRun);
                    $result['schedule_slot_at'] = $schedule['slot_at'] ?? null;
                    $result['schedule_time_of_day'] = $schedule['time_of_day'] ?? null;
                    $result['schedule_timezone'] = $schedule['timezone'] ?? null;
                    $result['schedule_days_mask'] = $schedule['days_mask'] ?? null;
                    if (!$dryRun && ($result['status'] ?? '') === 'success') {
                        $markScheduleTriggered = true;
                    }
                    $results[] = $result;
                }

                if (!$dryRun && $markScheduleTriggered && !empty($schedule['id'])) {
                    $this->updateScheduleTriggerState((int)$schedule['id']);
                }
            }
        }

        return $results;
    }

    public function findDuplicatesByContent(string $content, ?int $excludeId = null): array
    {
        $content = self::ensureUtf8($content);
        $hash = hash('sha256', $content);
        $sql = "
            SELECT a.*,
                   COALESCE(STRING_AGG(t.name, ', ' ORDER BY LOWER(t.name)), '') AS tags_csv,
                   OCTET_LENGTH(a.content) AS size_bytes
            FROM advertisements a
            LEFT JOIN advertisement_tag_map atm ON atm.advertisement_id = a.id
            LEFT JOIN advertisement_tags t ON t.id = atm.tag_id
            WHERE a.content_hash = ?
        ";

        $params = [$hash];
        if ($excludeId !== null) {
            $sql .= " AND a.id <> ?";
            $params[] = $excludeId;
        }

        $sql .= " GROUP BY a.id ORDER BY a.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrateAd'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function logPostResult(
        int $advertisementId,
        string $echoareaTag,
        string $domain,
        string $subject,
        ?int $postedByUserId,
        string $postMode = 'manual',
        string $status = 'success',
        ?string $errorText = null,
        ?int $campaignId = null,
        ?int $messageId = null
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO advertisement_post_log (
                advertisement_id,
                campaign_id,
                message_id,
                echoarea_tag,
                domain,
                subject,
                posted_by_user_id,
                post_mode,
                status,
                error_text
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $advertisementId,
            $campaignId,
            $messageId,
            $echoareaTag,
            $domain,
            $subject,
            $postedByUserId,
            $postMode,
            $status,
            $errorText
        ]);
    }

    public function renderAdPage(Template $template, ?array $ad): void
    {
        $template->renderResponse('ads/ad_full.twig', [
            'ad' => $ad
        ]);
    }

    public function renderAdModal(Template $template, ?array $ad, string $modalId = 'adModal'): string
    {
        return $template->render('ads/ad_modal.twig', [
            'ad' => $ad,
            'modal_id' => $modalId
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listEligibleDashboardAds(): array
    {
        $stmt = $this->db->query("
            SELECT a.*,
                   COALESCE(STRING_AGG(t.name, ', ' ORDER BY LOWER(t.name)), '') AS tags_csv,
                   OCTET_LENGTH(a.content) AS size_bytes
            FROM advertisements a
            LEFT JOIN advertisement_tag_map atm ON atm.advertisement_id = a.id
            LEFT JOIN advertisement_tags t ON t.id = atm.tag_id
            WHERE a.is_active = TRUE
              AND a.show_on_dashboard = TRUE
              AND (a.start_at IS NULL OR a.start_at <= NOW())
              AND (a.end_at IS NULL OR a.end_at >= NOW())
            GROUP BY a.id
            ORDER BY a.dashboard_priority DESC, a.updated_at DESC, a.id ASC
        ");

        return array_map([$this, 'hydrateAd'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int, array<string, mixed>> $ads
     * @return array<int, array<string, mixed>>
     */
    private function dedupeAdsByContentHash(array $ads): array
    {
        $seen = [];
        $deduped = [];

        foreach ($ads as $ad) {
            $hash = (string)($ad['content_hash'] ?? '');
            if ($hash === '') {
                $deduped[] = $ad;
                continue;
            }

            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $deduped[] = $ad;
        }

        return $deduped;
    }

    private function resolveAdContent(array $ad): array
    {
        $contentCommand = trim((string)($ad['content_command'] ?? ''));
        if ($contentCommand === '') {
            return $ad;
        }

        [$dynamicBody, $commandError] = $this->runContentCommand($contentCommand);
        if ($commandError !== null) {
            $fallback = trim((string)($ad['content'] ?? ''));
            $ad['content'] = $fallback !== '' ? $fallback : $commandError;
            $ad['content_command_error'] = $commandError;
            return $ad;
        }

        $ad['content'] = $dynamicBody;
        $ad['content_command_error'] = null;
        return $ad;
    }

    private function pickWeightedIndex(array $ads): int
    {
        $totalWeight = 0;
        foreach ($ads as $ad) {
            $totalWeight += max(1, (int)($ad['dashboard_weight'] ?? 1));
        }

        if ($totalWeight <= 0) {
            return array_rand($ads);
        }

        $roll = random_int(1, $totalWeight);
        $running = 0;
        foreach ($ads as $index => $ad) {
            $running += max(1, (int)($ad['dashboard_weight'] ?? 1));
            if ($roll <= $running) {
                return (int)$index;
            }
        }

        return count($ads) - 1;
    }

    private function getCampaignTargets(int $campaignId): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM advertisement_campaign_targets
            WHERE campaign_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$campaignId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['campaign_id'] = (int)$row['campaign_id'];
            $row['is_active'] = $this->dbBool($row['is_active'] ?? false);
        }
        return $rows;
    }

    private function getCampaignSchedules(int $campaignId): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM advertisement_campaign_schedules
            WHERE campaign_id = ?
            ORDER BY time_of_day ASC, id ASC
        ");
        $stmt->execute([$campaignId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['campaign_id'] = (int)$row['campaign_id'];
            $row['days_mask'] = (int)($row['days_mask'] ?? 0);
            $row['is_active'] = $this->dbBool($row['is_active'] ?? false);
        }
        return $rows;
    }

    private function getCampaignAds(int $campaignId): array
    {
        $stmt = $this->db->prepare("
            SELECT ca.advertisement_id,
                   ca.weight,
                   a.title,
                   a.slug,
                   a.is_active,
                   a.allow_auto_post
            FROM advertisement_campaign_ads ca
            INNER JOIN advertisements a ON a.id = ca.advertisement_id
            WHERE ca.campaign_id = ?
            ORDER BY LOWER(a.title), ca.advertisement_id
        ");
        $stmt->execute([$campaignId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['advertisement_id'] = (int)$row['advertisement_id'];
            $row['weight'] = max(1, (int)$row['weight']);
            $row['is_active'] = $this->dbBool($row['is_active'] ?? false);
            $row['allow_auto_post'] = $this->dbBool($row['allow_auto_post'] ?? false);
        }
        return $rows;
    }

    private function getCampaignTagFilters(int $campaignId): array
    {
        $stmt = $this->db->prepare("
            SELECT ctf.filter_mode,
                   t.id,
                   t.name,
                   t.slug
            FROM advertisement_campaign_tag_filters ctf
            INNER JOIN advertisement_tags t ON t.id = ctf.tag_id
            WHERE ctf.campaign_id = ?
            ORDER BY ctf.filter_mode ASC, LOWER(t.name), t.id ASC
        ");
        $stmt->execute([$campaignId]);

        $filters = [
            'include' => [],
            'exclude' => []
        ];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mode = (string)($row['filter_mode'] ?? '');
            if (!isset($filters[$mode])) {
                continue;
            }

            $filters[$mode][] = [
                'id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? ''),
                'slug' => (string)($row['slug'] ?? '')
            ];
        }

        return $filters;
    }

    private function syncCampaignTargets(int $campaignId, array $targets): void
    {
        $delete = $this->db->prepare("DELETE FROM advertisement_campaign_targets WHERE campaign_id = ?");
        $delete->execute([$campaignId]);

        $insert = $this->db->prepare("
            INSERT INTO advertisement_campaign_targets (
                campaign_id,
                echoarea_tag,
                domain,
                subject_template,
                is_active
            ) VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($targets as $target) {
            $insert->execute([
                $campaignId,
                $target['echoarea_tag'],
                $target['domain'],
                $target['subject_template'],
                $this->asPgBool(!empty($target['is_active']))
            ]);
        }
    }

    private function syncCampaignAds(int $campaignId, array $ads): void
    {
        $delete = $this->db->prepare("DELETE FROM advertisement_campaign_ads WHERE campaign_id = ?");
        $delete->execute([$campaignId]);

        $insert = $this->db->prepare("
            INSERT INTO advertisement_campaign_ads (campaign_id, advertisement_id, weight)
            VALUES (?, ?, ?)
        ");

        foreach ($ads as $ad) {
            $insert->execute([
                $campaignId,
                (int)$ad['advertisement_id'],
                max(1, (int)$ad['weight'])
            ]);
        }
    }

    private function syncCampaignTagFilters(int $campaignId, array $tagFilters): void
    {
        $delete = $this->db->prepare("DELETE FROM advertisement_campaign_tag_filters WHERE campaign_id = ?");
        $delete->execute([$campaignId]);

        $insert = $this->db->prepare("
            INSERT INTO advertisement_campaign_tag_filters (campaign_id, tag_id, filter_mode)
            VALUES (?, ?, ?)
        ");

        foreach (['include', 'exclude'] as $mode) {
            foreach ($tagFilters[$mode] ?? [] as $tagId) {
                $insert->execute([
                    $campaignId,
                    (int)$tagId,
                    $mode
                ]);
            }
        }
    }

    private function syncCampaignSchedules(int $campaignId, array $schedules): void
    {
        $delete = $this->db->prepare("DELETE FROM advertisement_campaign_schedules WHERE campaign_id = ?");
        $delete->execute([$campaignId]);

        if ($schedules === []) {
            return;
        }

        $insert = $this->db->prepare("
            INSERT INTO advertisement_campaign_schedules (
                campaign_id,
                days_mask,
                time_of_day,
                timezone,
                is_active
            ) VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($schedules as $schedule) {
            $insert->execute([
                $campaignId,
                (int)$schedule['days_mask'],
                $schedule['time_of_day'],
                $schedule['timezone'],
                $this->asPgBool(!empty($schedule['is_active']))
            ]);
        }
    }

    private function normalizeCampaignTargets($targets): array
    {
        $normalized = [];
        foreach (is_array($targets) ? $targets : [] as $target) {
            if (!is_array($target)) {
                continue;
            }

            $echoareaTag = trim((string)($target['echoarea_tag'] ?? ''));
            $domain = trim((string)($target['domain'] ?? ''));
            $subjectTemplate = trim((string)($target['subject_template'] ?? ''));
            if ($echoareaTag === '' || $subjectTemplate === '') {
                continue;
            }

            $normalized[] = [
                'echoarea_tag' => $echoareaTag,
                'domain' => $domain,
                'subject_template' => $subjectTemplate,
                'is_active' => !array_key_exists('is_active', $target) || !empty($target['is_active'])
            ];
        }

        return $normalized;
    }

    private function normalizeCampaignAds($ads): array
    {
        $normalized = [];
        foreach (is_array($ads) ? $ads : [] as $ad) {
            if (!is_array($ad)) {
                continue;
            }

            $advertisementId = (int)($ad['advertisement_id'] ?? 0);
            if ($advertisementId <= 0) {
                continue;
            }

            $normalized[] = [
                'advertisement_id' => $advertisementId,
                'weight' => max(1, (int)($ad['weight'] ?? 1))
            ];
        }

        return $normalized;
    }

    private function normalizeCampaignSchedules($schedules): array
    {
        $normalized = [];
        foreach (is_array($schedules) ? $schedules : [] as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }

            $daysMask = (int)($schedule['days_mask'] ?? 0);
            if ($daysMask <= 0 && isset($schedule['days']) && is_array($schedule['days'])) {
                foreach ($schedule['days'] as $day) {
                    $dayIndex = (int)$day;
                    if ($dayIndex < 0 || $dayIndex > 6) {
                        continue;
                    }
                    $daysMask |= (1 << $dayIndex);
                }
            }

            $timeOfDay = trim((string)($schedule['time_of_day'] ?? ''));
            if (!preg_match('/^\d{2}:\d{2}$/', $timeOfDay)) {
                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', $timeOfDay, 2));
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                continue;
            }

            $timezone = trim((string)($schedule['timezone'] ?? 'UTC'));
            if ($timezone === '') {
                $timezone = 'UTC';
            }

            try {
                new \DateTimeZone($timezone);
            } catch (\Throwable $e) {
                continue;
            }

            if ($daysMask <= 0) {
                continue;
            }

            $normalized[] = [
                'days_mask' => $daysMask,
                'time_of_day' => sprintf('%02d:%02d', $hour, $minute),
                'timezone' => $timezone,
                'is_active' => !array_key_exists('is_active', $schedule) || !empty($schedule['is_active'])
            ];
        }

        return $normalized;
    }

    private function normalizeCampaignTagFilters($tagFilters): array
    {
        $normalized = [
            'include' => [],
            'exclude' => []
        ];

        if (!is_array($tagFilters)) {
            return $normalized;
        }

        foreach (['include', 'exclude'] as $mode) {
            $seen = [];
            foreach ((array)($tagFilters[$mode] ?? []) as $item) {
                $tagId = 0;
                if (is_array($item)) {
                    $tagId = (int)($item['id'] ?? 0);
                } else {
                    $tagId = (int)$item;
                }

                if ($tagId <= 0 || isset($seen[$tagId])) {
                    continue;
                }

                $seen[$tagId] = true;
                $normalized[$mode][] = $tagId;
            }
        }

        return $normalized;
    }

    private function hydrateCampaign(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['from_user_id'] = isset($row['from_user_id']) ? (int)$row['from_user_id'] : null;
        $row['post_interval_minutes'] = (int)($row['post_interval_minutes'] ?? 0);
        $row['min_repeat_gap_minutes'] = (int)($row['min_repeat_gap_minutes'] ?? 0);
        $row['target_count'] = (int)($row['target_count'] ?? 0);
        $row['ad_count'] = (int)($row['ad_count'] ?? 0);
        $row['schedule_count'] = (int)($row['schedule_count'] ?? 0);
        $row['is_active'] = $this->dbBool($row['is_active'] ?? false);
        $row['tag_filters'] = $row['tag_filters'] ?? ['include' => [], 'exclude' => []];
        // end_at: keep as ISO string or null
        $row['end_at'] = isset($row['end_at']) && $row['end_at'] !== '' ? $row['end_at'] : null;
        return $row;
    }

    private function getDueCampaignSchedules(array $campaign): array
    {
        if (empty($campaign['is_active'])) {
            return [];
        }

        $schedules = $campaign['schedules'] ?? $this->getCampaignSchedules((int)$campaign['id']);
        $activeSchedules = array_values(array_filter($schedules, static fn(array $schedule): bool => !empty($schedule['is_active'])));
        if ($activeSchedules === []) {
            return [];
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $due = [];

        foreach ($activeSchedules as $schedule) {
            $timezoneName = (string)($schedule['timezone'] ?? 'UTC');
            try {
                $timezone = new \DateTimeZone($timezoneName);
            } catch (\Throwable $e) {
                $timezone = new \DateTimeZone('UTC');
            }

            $localNow = $nowUtc->setTimezone($timezone);
            $dayIndex = (int)$localNow->format('w');
            $daysMask = (int)($schedule['days_mask'] ?? 0);
            if (($daysMask & (1 << $dayIndex)) === 0) {
                continue;
            }

            $timeOfDay = (string)($schedule['time_of_day'] ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $timeOfDay)) {
                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', $timeOfDay, 2));
            $slotLocal = $localNow->setTime($hour, $minute, 0);
            $slotUtc = $slotLocal->setTimezone(new \DateTimeZone('UTC'));
            $windowStart = $slotUtc->getTimestamp();
            $windowEnd = $windowStart + (15 * 60);
            $nowTs = $nowUtc->getTimestamp();
            if ($nowTs < $windowStart || $nowTs > $windowEnd) {
                continue;
            }

            $lastTriggered = $schedule['last_triggered_at'] ?? null;
            if ($lastTriggered) {
                $lastTriggeredTs = strtotime((string)$lastTriggered);
                if ($lastTriggeredTs !== false && $lastTriggeredTs >= $windowStart) {
                    continue;
                }
            }

            $schedule['slot_at'] = $slotUtc->format(DATE_ATOM);
            $due[] = $schedule;
        }

        return $due;
    }

    private function runCampaignTarget(array $campaign, array $target, bool $dryRun): array
    {
        $ad = $this->selectCampaignAdvertisement((int)$campaign['id'], (int)($campaign['last_posted_ad_id'] ?? 0));
        if (!$ad) {
            return [
                'campaign_id' => (int)$campaign['id'],
                'campaign_name' => $campaign['name'],
                'target' => $target['echoarea_tag'] . '@' . $target['domain'],
                'status' => 'skipped',
                'reason' => 'No eligible advertisements available'
            ];
        }

        $subject = $this->renderCampaignSubject((string)$target['subject_template'], $ad, $campaign, $target);

        if ($dryRun) {
            return [
                'campaign_id' => (int)$campaign['id'],
                'campaign_name' => $campaign['name'],
                'target' => $target['echoarea_tag'] . '@' . $target['domain'],
                'status' => 'dry-run',
                'advertisement_id' => (int)$ad['id'],
                'advertisement_title' => $ad['title'],
                'subject' => $subject,
                'content_source' => !empty($ad['content_command']) ? 'dynamic' : 'static'
            ];
        }

        // Resolve message body — dynamic command takes precedence over static content
        if (!empty($ad['content_command'])) {
            [$dynamicBody, $commandError] = $this->runContentCommand((string)$ad['content_command']);
            if ($commandError !== null) {
                $this->logPostResult((int)$ad['id'], (string)$target['echoarea_tag'], (string)$target['domain'], $subject, (int)$campaign['from_user_id'], 'campaign', 'skipped', $commandError, (int)$campaign['id']);
                return [
                    'campaign_id' => (int)$campaign['id'],
                    'campaign_name' => $campaign['name'],
                    'target' => $target['echoarea_tag'] . '@' . $target['domain'],
                    'status' => 'skipped',
                    'advertisement_id' => (int)$ad['id'],
                    'advertisement_title' => $ad['title'],
                    'subject' => $subject,
                    'reason' => $commandError
                ];
            }
            $messageBody = $dynamicBody;
        } else {
            $messageBody = self::stripSauce((string)($ad['content'] ?? ''));
        }

        try {
            $handler = new MessageHandler();
            $posted = $handler->postEchomail(
                (int)$campaign['from_user_id'],
                (string)$target['echoarea_tag'],
                (string)$target['domain'],
                (string)($campaign['to_name'] ?? 'All'),
                $subject,
                $messageBody
            );

            if (!$posted) {
                $this->logPostResult((int)$ad['id'], (string)$target['echoarea_tag'], (string)$target['domain'], $subject, (int)$campaign['from_user_id'], 'campaign', 'failed', 'MessageHandler::postEchomail returned false', (int)$campaign['id']);
                return [
                    'campaign_id' => (int)$campaign['id'],
                    'campaign_name' => $campaign['name'],
                    'target' => $target['echoarea_tag'] . '@' . $target['domain'],
                    'status' => 'failed',
                    'advertisement_id' => (int)$ad['id'],
                    'advertisement_title' => $ad['title'],
                    'subject' => $subject,
                    'error' => 'MessageHandler::postEchomail returned false'
                ];
            }

            $this->logPostResult((int)$ad['id'], (string)$target['echoarea_tag'], (string)$target['domain'], $subject, (int)$campaign['from_user_id'], 'campaign', 'success', null, (int)$campaign['id']);
            $this->updateCampaignPostState((int)$campaign['id'], (int)$ad['id']);

            return [
                'campaign_id' => (int)$campaign['id'],
                'campaign_name' => $campaign['name'],
                'target' => $target['echoarea_tag'] . '@' . $target['domain'],
                'status' => 'success',
                'advertisement_id' => (int)$ad['id'],
                'advertisement_title' => $ad['title'],
                'subject' => $subject
            ];
        } catch (\Throwable $e) {
            $this->logPostResult((int)$ad['id'], (string)$target['echoarea_tag'], (string)$target['domain'], $subject, (int)$campaign['from_user_id'], 'campaign', 'failed', $e->getMessage(), (int)$campaign['id']);
            return [
                'campaign_id' => (int)$campaign['id'],
                'campaign_name' => $campaign['name'],
                'target' => $target['echoarea_tag'] . '@' . $target['domain'],
                'status' => 'failed',
                'advertisement_id' => (int)$ad['id'],
                'advertisement_title' => $ad['title'],
                'subject' => $subject,
                'error' => $e->getMessage()
            ];
        }
    }

    private function selectCampaignAdvertisement(int $campaignId, int $lastPostedAdId): ?array
    {
        $tagFilters = $this->getCampaignTagFilters($campaignId);
        $includeTagIds = array_map('intval', $tagFilters['include'] ?? []);
        $excludeTagIds = array_map('intval', $tagFilters['exclude'] ?? []);

        $params = [$campaignId];
        $sql = "
            SELECT a.*,
                   COALESCE(ca.weight, 1) AS campaign_weight,
                   COALESCE(STRING_AGG(t.name, ', ' ORDER BY LOWER(t.name)), '') AS tags_csv,
                   OCTET_LENGTH(a.content) AS size_bytes
            FROM advertisements a
            LEFT JOIN advertisement_campaign_ads ca
                ON ca.advertisement_id = a.id
               AND ca.campaign_id = ?
            LEFT JOIN advertisement_tag_map atm ON atm.advertisement_id = a.id
            LEFT JOIN advertisement_tags t ON t.id = atm.tag_id
            WHERE a.is_active = TRUE
              AND a.allow_auto_post = TRUE
              AND (a.start_at IS NULL OR a.start_at <= NOW())
              AND (a.end_at IS NULL OR a.end_at >= NOW())
              AND (
                    ca.campaign_id IS NOT NULL
                 OR EXISTS (
                        SELECT 1
                        FROM advertisement_tag_map atm_any
                        WHERE atm_any.advertisement_id = a.id
                    )
                 OR " . ($includeTagIds === [] && $excludeTagIds !== [] ? 'TRUE' : 'FALSE') . "
              )
        ";

        if ($includeTagIds !== []) {
            $sql .= "
              AND EXISTS (
                    SELECT 1
                    FROM advertisement_tag_map atm_include
                    WHERE atm_include.advertisement_id = a.id
                      AND atm_include.tag_id IN (" . implode(', ', array_fill(0, count($includeTagIds), '?')) . ")
                )
            ";
            array_push($params, ...$includeTagIds);
        }

        if ($excludeTagIds !== []) {
            $sql .= "
              AND NOT EXISTS (
                    SELECT 1
                    FROM advertisement_tag_map atm_exclude
                    WHERE atm_exclude.advertisement_id = a.id
                      AND atm_exclude.tag_id IN (" . implode(', ', array_fill(0, count($excludeTagIds), '?')) . ")
                )
            ";
            array_push($params, ...$excludeTagIds);
        }

        if ($includeTagIds === [] && $excludeTagIds === []) {
            $sql .= " AND ca.campaign_id IS NOT NULL";
        }

        $sql .= "
            GROUP BY a.id, ca.weight
            ORDER BY
                CASE WHEN MAX(ca.campaign_id) IS NOT NULL THEN 1 ELSE 0 END DESC,
                a.dashboard_priority DESC,
                a.updated_at DESC,
                a.id ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $ads = $this->dedupeAdsByContentHash(array_map([$this, 'hydrateAd'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
        if ($ads === []) {
            return null;
        }

        if ($lastPostedAdId > 0 && count($ads) > 1) {
            $filtered = array_values(array_filter($ads, static fn(array $ad): bool => (int)$ad['id'] !== $lastPostedAdId));
            if ($filtered !== []) {
                $ads = $filtered;
            }
        }

        $weighted = array_map(static function (array $ad): array {
            $ad['dashboard_weight'] = max(1, (int)($ad['campaign_weight'] ?? 1));
            return $ad;
        }, $ads);

        return $weighted[$this->pickWeightedIndex($weighted)] ?? null;
    }

    private function renderCampaignSubject(string $template, array $ad, array $campaign, array $target): string
    {
        $subject = strtr($template, [
            '{title}' => (string)($ad['title'] ?? ''),
            '{slug}' => (string)($ad['slug'] ?? ''),
            '{campaign}' => (string)($campaign['name'] ?? ''),
            '{echoarea}' => (string)($target['echoarea_tag'] ?? ''),
            '{domain}' => (string)($target['domain'] ?? '')
        ]);

        $subject = trim($subject);
        return $subject !== '' ? $subject : (string)($ad['title'] ?? 'BBS Advertisement');
    }

    private function updateCampaignPostState(int $campaignId, int $advertisementId): void
    {
        $stmt = $this->db->prepare("
            UPDATE advertisement_campaigns
            SET last_posted_at = NOW(),
                last_posted_ad_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$advertisementId, $campaignId]);
    }

    private function updateScheduleTriggerState(int $scheduleId): void
    {
        $stmt = $this->db->prepare("
            UPDATE advertisement_campaign_schedules
            SET last_triggered_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$scheduleId]);
    }

    private function summarizeCampaignSchedules(array $schedules): string
    {
        if ($schedules === []) {
            return '-';
        }

        $labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $parts = [];
        foreach ($schedules as $schedule) {
            $days = [];
            $daysMask = (int)($schedule['days_mask'] ?? 0);
            foreach ($labels as $index => $label) {
                if (($daysMask & (1 << $index)) !== 0) {
                    $days[] = $label;
                }
            }

            $parts[] = sprintf(
                '%s %s %s',
                implode(',', $days),
                (string)($schedule['time_of_day'] ?? '--:--'),
                (string)($schedule['timezone'] ?? 'UTC')
            );
        }

        return implode('; ', $parts);
    }

    private function getNextCampaignRun(array $schedules): ?array
    {
        $activeSchedules = array_values(array_filter($schedules, static fn(array $schedule): bool => !empty($schedule['is_active'])));
        if ($activeSchedules === []) {
            return null;
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $best = null;

        foreach ($activeSchedules as $schedule) {
            $candidate = $this->getNextScheduleOccurrence($schedule, $nowUtc);
            if ($candidate === null) {
                continue;
            }

            if ($best === null || $candidate['slot_at_utc_ts'] < $best['slot_at_utc_ts']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function getNextScheduleOccurrence(array $schedule, \DateTimeImmutable $nowUtc): ?array
    {
        $daysMask = (int)($schedule['days_mask'] ?? 0);
        $timeOfDay = (string)($schedule['time_of_day'] ?? '');
        if ($daysMask <= 0 || !preg_match('/^\d{2}:\d{2}$/', $timeOfDay)) {
            return null;
        }

        try {
            $timezone = new \DateTimeZone((string)($schedule['timezone'] ?? 'UTC'));
        } catch (\Throwable $e) {
            $timezone = new \DateTimeZone('UTC');
        }

        [$hour, $minute] = array_map('intval', explode(':', $timeOfDay, 2));
        $localNow = $nowUtc->setTimezone($timezone);

        for ($offsetDays = 0; $offsetDays <= 7; $offsetDays++) {
            $candidateDay = $localNow->modify('+' . $offsetDays . ' day');
            $dayIndex = (int)$candidateDay->format('w');
            if (($daysMask & (1 << $dayIndex)) === 0) {
                continue;
            }

            $candidateLocal = $candidateDay->setTime($hour, $minute, 0);
            $candidateUtc = $candidateLocal->setTimezone(new \DateTimeZone('UTC'));
            if ($candidateUtc <= $nowUtc) {
                continue;
            }

            return [
                'time_of_day' => $timeOfDay,
                'timezone' => $timezone->getName(),
                'days_mask' => $daysMask,
                'slot_at_utc' => $candidateUtc->format(DATE_ATOM),
                'slot_at_utc_ts' => $candidateUtc->getTimestamp(),
                'slot_at_local' => $candidateLocal->format(DATE_ATOM)
            ];
        }

        return null;
    }

    private function getUniqueSlug(string $input, ?int $excludeId = null): string
    {
        $base = self::slugify($input);
        $slug = $base;
        $suffix = 2;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = substr($base, 0, 110) . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM advertisements WHERE slug = ?";
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= " AND id <> ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function syncTags(int $advertisementId, array $tags): void
    {
        $delete = $this->db->prepare("DELETE FROM advertisement_tag_map WHERE advertisement_id = ?");
        $delete->execute([$advertisementId]);

        if ($tags === []) {
            return;
        }

        $selectTag = $this->db->prepare("SELECT id FROM advertisement_tags WHERE slug = ?");
        $insertTag = $this->db->prepare("INSERT INTO advertisement_tags (name, slug) VALUES (?, ?) RETURNING id");
        $insertMap = $this->db->prepare("
            INSERT INTO advertisement_tag_map (advertisement_id, tag_id)
            VALUES (?, ?)
            ON CONFLICT DO NOTHING
        ");

        foreach ($tags as $tag) {
            $slug = self::slugify($tag);
            $selectTag->execute([$slug]);
            $tagId = $selectTag->fetchColumn();
            if ($tagId === false) {
                $insertTag->execute([$tag, $slug]);
                $tagId = $insertTag->fetchColumn();
            }

            if ($tagId !== false) {
                $insertMap->execute([$advertisementId, $tagId]);
            }
        }
    }

    private function normalizeLegacyFilename(string $filename): string
    {
        $filename = trim(basename($filename));
        if ($filename === '') {
            return '';
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        $filename = trim((string)$filename, '._');
        if ($filename === '') {
            return '';
        }

        if (!str_ends_with(strtolower($filename), '.ans')) {
            $filename .= '.ans';
        }

        return $filename;
    }

    private function normalizeTimestamp($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function asPgBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Parse an end_at value from API input.
     * Accepts a date string (YYYY-MM-DD) or datetime string, or null/empty to clear.
     * Returns a UTC datetime string suitable for PostgreSQL, or null.
     */
    private function parseEndAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = trim((string)$value);
        if ($str === '') {
            return null;
        }
        try {
            // If only a date (YYYY-MM-DD), treat as end of that day in UTC
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
                $dt = new \DateTimeImmutable($str . ' 23:59:59', new \DateTimeZone('UTC'));
            } else {
                $dt = new \DateTimeImmutable($str, new \DateTimeZone('UTC'));
            }
            return $dt->format('Y-m-d H:i:sP');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    /**
     * Record a dashboard impression for an advertisement.
     *
     * @param int $adId  Advertisement ID
     * @param int $userId User who saw the ad
     */
    public function recordImpression(int $adId, int $userId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO advertisement_impressions (advertisement_id, user_id) VALUES (?, ?)"
        );
        $stmt->execute([$adId, $userId]);
    }

    /**
     * Record a click-through for an advertisement.
     *
     * @param int $adId  Advertisement ID
     * @param int $userId User who clicked
     */
    public function recordClick(int $adId, int $userId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO advertisement_clicks (advertisement_id, user_id) VALUES (?, ?)"
        );
        $stmt->execute([$adId, $userId]);
    }

    private function hydrateAd(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['dashboard_weight'] = (int)($row['dashboard_weight'] ?? 1);
        $row['dashboard_priority'] = (int)($row['dashboard_priority'] ?? 0);
        $row['size'] = (int)($row['size_bytes'] ?? strlen((string)($row['content'] ?? '')));
        $row['is_active'] = $this->dbBool($row['is_active'] ?? false);
        $row['show_on_dashboard'] = $this->dbBool($row['show_on_dashboard'] ?? false);
        $row['allow_auto_post'] = $this->dbBool($row['allow_auto_post'] ?? false);
        $row['tags'] = self::normalizeTags((string)($row['tags_csv'] ?? ''));
        $row['name'] = (string)($row['legacy_filename'] ?? $row['slug']);
        $row['content_command'] = isset($row['content_command']) && $row['content_command'] !== '' ? $row['content_command'] : null;
        $row['click_url'] = isset($row['click_url']) && $row['click_url'] !== '' ? $row['click_url'] : null;
        $row['impression_count'] = isset($row['impression_count']) ? (int)$row['impression_count'] : null;
        $row['click_count'] = isset($row['click_count']) ? (int)$row['click_count'] : null;
        return $row;
    }

    /**
     * Execute a content_command and return [string $output, ?string $errorReason].
     * Returns a non-null error reason if the command should cause the post to be skipped.
     *
     * The command must be a repo-relative path validated by validateContentCommand().
     * Relative paths are resolved to absolute paths before execution; PHP scripts are
     * run through PHP_BINARY automatically.
     */
    private function runContentCommand(string $command): array
    {
        if (!self::validateContentCommand($command)) {
            return ['', 'content_command is not in the allowed list'];
        }

        // Split into script path and optional arguments
        $parts = preg_split('/\s+/', trim($command), 2);
        $script  = $parts[0];
        $rawArgs = isset($parts[1]) ? trim($parts[1]) : '';

        // Resolve the script to an absolute path
        $absPath = self::getBaseDir() . '/' . $script;

        // Build the escaped command: PHP_BINARY for .php scripts, otherwise direct exec
        if (str_ends_with($script, '.php')) {
            $escaped = PHP_BINARY . ' ' . escapeshellarg($absPath);
        } else {
            $escaped = escapeshellarg($absPath);
        }

        // Append each argument individually through escapeshellarg
        if ($rawArgs !== '') {
            foreach (preg_split('/\s+/', $rawArgs) as $arg) {
                if ($arg !== '') {
                    $escaped .= ' ' . escapeshellarg($arg);
                }
            }
        }

        $command = $escaped;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null);
        if (!is_resource($process)) {
            return ['', 'Failed to launch content_command process'];
        }

        fclose($pipes[0]);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return ['', "content_command exited with code {$exitCode}"];
        }

        if (trim($output) === '') {
            return ['', 'content_command produced no output'];
        }

        return [$output, null];
    }

    private function dbBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}

