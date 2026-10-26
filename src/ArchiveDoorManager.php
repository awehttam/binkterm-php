<?php
/**
 * Archive Door Manager
 *
 * Manages Internet Archive DOS games streamed directly into the browser.
 * Games require zero server-side disk storage and are emulated using
 * Internet Archive's in-browser EM-DOSBox engine.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class ArchiveDoorManager
{
    /**
     * Validate a door ID against a strict whitelist (used in URLs and as a DB key).
     */
    public static function isValidDoorId(string $doorId): bool
    {
        return $doorId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $doorId) === 1;
    }

    /**
     * Clean / extract an Internet Archive item ID from a full URL or identifier.
     * Examples:
     *   https://archive.org/details/msdos_Oregon_Trail_The_1990 -> msdos_Oregon_Trail_The_1990
     *   https://archive.org/embed/msdos_Oregon_Trail_The_1990   -> msdos_Oregon_Trail_The_1990
     *   msdos_Oregon_Trail_The_1990                             -> msdos_Oregon_Trail_The_1990
     */
    public static function cleanArchiveItemId(string $input): string
    {
        $input = trim($input);
        if (preg_match('~archive\.org/(?:details|embed)/([^/?#]+)~i', $input, $m)) {
            return trim($m[1]);
        }
        return trim($input);
    }

    /**
     * Fetch metadata and thumbnail for an Internet Archive item.
     *
     * @param string $itemIdOrUrl
     * @return array
     * @throws \Exception
     */
    public function fetchArchiveMetadata(string $itemIdOrUrl): array
    {
        $itemId = self::cleanArchiveItemId($itemIdOrUrl);
        if ($itemId === '') {
            throw new \Exception('Invalid or empty Internet Archive identifier.');
        }

        $apiUrl = "https://archive.org/metadata/{$itemId}";
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'BinktermPHP-BBS/1.0',
            ]
        ]);

        $json = @file_get_contents($apiUrl, false, $ctx);
        if ($json === false) {
            throw new \Exception("Could not contact Internet Archive API for '{$itemId}'.");
        }

        $data = json_decode($json, true);
        if (!$data || empty($data['metadata'])) {
            throw new \Exception("Internet Archive item '{$itemId}' not found or has no metadata.");
        }

        $meta = $data['metadata'];
        $title = $meta['title'] ?? $itemId;
        $description = '';
        if (!empty($meta['description'])) {
            // Strip HTML tags for clean text description
            $description = trim(html_entity_decode(strip_tags((string)$meta['description'])));
            // Limit to reasonable snippet
            if (mb_strlen($description) > 300) {
                $description = mb_substr($description, 0, 297) . '...';
            }
        }

        $author = '';
        if (!empty($meta['creator'])) {
            $author = is_array($meta['creator']) ? implode(', ', $meta['creator']) : (string)$meta['creator'];
        }

        $year = null;
        if (!empty($meta['year']) && is_numeric($meta['year'])) {
            $year = (int)$meta['year'];
        } elseif (!empty($meta['date']) && preg_match('/^(\d{4})/', (string)$meta['date'], $ym)) {
            $year = (int)$ym[1];
        }

        // Find cover or thumbnail image
        $thumbnailUrl = null;
        $server = $data['server'] ?? '';
        $dir = $data['dir'] ?? '';
        if ($server && $dir && !empty($data['files'])) {
            foreach ($data['files'] as $f) {
                $name = $f['name'] ?? '';
                if ($name === '__ia_thumb.jpg' || str_ends_with($name, '_thumb.jpg') || str_ends_with($name, 'coverscreenshot.jpg')) {
                    $thumbnailUrl = "https://{$server}{$dir}/{$name}";
                    break;
                }
            }
        }

        // Generate recommended door_id
        $cleanDoorId = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $itemId));
        $cleanDoorId = preg_replace('/^-+|-+$/', '', $cleanDoorId);
        if (str_starts_with($cleanDoorId, 'msdos-')) {
            $cleanDoorId = substr($cleanDoorId, 6);
        }

        return [
            'archive_item_id' => $itemId,
            'suggested_door_id' => substr($cleanDoorId, 0, 40),
            'name' => $title,
            'short_name' => substr($title, 0, 30),
            'author' => $author ?: 'Unknown',
            'release_year' => $year,
            'description' => $description,
            'genre' => [DoorCategoryHelper::INTERNET_ARCHIVE, 'Classic'],
            'thumbnail_url' => $thumbnailUrl,
            'embed_url' => "https://archive.org/embed/{$itemId}",
        ];
    }

    /**
     * Get all archive doors, keyed by door_id.
     *
     * @return array
     */
    public function getAllDoors(): array
    {
        $db = Database::getInstance()->getPdo();
        $stmt = $db->query('SELECT * FROM archive_doors ORDER BY name ASC');

        $doors = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $doors[$row['door_id']] = $this->mapRow($row);
        }

        return $doors;
    }

    /**
     * Get enabled archive doors only.
     *
     * @return array
     */
    public function getEnabledDoors(): array
    {
        $all = $this->getAllDoors();
        $enabled = [];
        foreach ($all as $id => $d) {
            if (!empty($d['config']['enabled'])) {
                $enabled[$id] = $d;
            }
        }
        return $enabled;
    }

    /**
     * Get a specific archive door.
     */
    public function getDoor(string $doorId): ?array
    {
        if (!self::isValidDoorId($doorId)) {
            return null;
        }

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare('SELECT * FROM archive_doors WHERE door_id = ?');
        $stmt->execute([$doorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * Fetch the stored icon blob for a door.
     *
     * @return array{data: string, mime: string, size: int}|null
     */
    public function getIconBlob(string $doorId): ?array
    {
        return $this->getBlob($doorId, 'icon_data', 'icon_mime', 'icon_size');
    }

    /**
     * Fetch the stored screenshot blob for a door.
     *
     * @return array{data: string, mime: string, size: int}|null
     */
    public function getScreenshotBlob(string $doorId): ?array
    {
        return $this->getBlob($doorId, 'screenshot_data', 'screenshot_mime', 'screenshot_size');
    }

    private function getBlob(string $doorId, string $dataColumn, string $mimeColumn, string $sizeColumn): ?array
    {
        if (!self::isValidDoorId($doorId)) {
            return null;
        }

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare("SELECT $dataColumn AS data, $mimeColumn AS mime, $sizeColumn AS size FROM archive_doors WHERE door_id = ?");
        $stmt->execute([$doorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || $row['data'] === null) {
            return null;
        }

        $data = $row['data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        $size = $row['size'] !== null ? (int)$row['size'] : strlen($data);

        return ['data' => $data, 'mime' => $row['mime'] ?: 'application/octet-stream', 'size' => $size];
    }

    /**
     * Create a new Archive door.
     */
    public function createDoor(string $doorId, array $fields, ?array $icon = null, ?array $screenshot = null): bool
    {
        if (!self::isValidDoorId($doorId)) {
            return false;
        }

        $db = Database::getInstance()->getPdo();

        $columns = ['door_id'];
        $placeholders = ['?'];
        $values = [$doorId];
        $lobColumns = [];

        foreach ($this->normalizeFields($fields) as $column => $value) {
            $columns[] = $column;
            $placeholders[] = $column === 'genre' ? '?::jsonb' : '?';
            $values[] = $value;
        }

        if ($icon !== null) {
            $columns[] = 'icon_data';
            $placeholders[] = '?';
            $values[] = $icon['data'];
            $lobColumns[count($values)] = true;
            $columns[] = 'icon_mime';
            $placeholders[] = '?';
            $values[] = $icon['mime'];
            $columns[] = 'icon_size';
            $placeholders[] = '?';
            $values[] = $icon['data'] !== null ? strlen($icon['data']) : null;
        }

        if ($screenshot !== null) {
            $columns[] = 'screenshot_data';
            $placeholders[] = '?';
            $values[] = $screenshot['data'];
            $lobColumns[count($values)] = true;
            $columns[] = 'screenshot_mime';
            $placeholders[] = '?';
            $values[] = $screenshot['mime'];
            $columns[] = 'screenshot_size';
            $placeholders[] = '?';
            $values[] = $screenshot['data'] !== null ? strlen($screenshot['data']) : null;
        }

        $sql = 'INSERT INTO archive_doors (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $db->prepare($sql);

        return $this->executeWithLobs($stmt, $values, $lobColumns);
    }

    /**
     * Update an existing Archive door.
     */
    public function updateDoor(string $doorId, array $fields, ?array $icon = null, ?array $screenshot = null): bool
    {
        if (!self::isValidDoorId($doorId)) {
            return false;
        }

        $db = Database::getInstance()->getPdo();

        $sets = [];
        $values = [];
        $lobColumns = [];

        foreach ($this->normalizeFields($fields) as $column => $value) {
            $sets[] = $column === 'genre' ? "$column = ?::jsonb" : "$column = ?";
            $values[] = $value;
        }

        if ($icon !== null) {
            $sets[] = 'icon_data = ?';
            $values[] = $icon['data'];
            $lobColumns[count($values)] = true;
            $sets[] = 'icon_mime = ?';
            $values[] = $icon['mime'];
            $sets[] = 'icon_size = ?';
            $values[] = $icon['data'] !== null ? strlen($icon['data']) : null;
        }

        if ($screenshot !== null) {
            $sets[] = 'screenshot_data = ?';
            $values[] = $screenshot['data'];
            $lobColumns[count($values)] = true;
            $sets[] = 'screenshot_mime = ?';
            $values[] = $screenshot['mime'];
            $sets[] = 'screenshot_size = ?';
            $values[] = $screenshot['data'] !== null ? strlen($screenshot['data']) : null;
        }

        $sets[] = 'updated_at = NOW()';
        $values[] = $doorId;

        $sql = 'UPDATE archive_doors SET ' . implode(', ', $sets) . ' WHERE door_id = ?';
        $stmt = $db->prepare($sql);

        return $this->executeWithLobs($stmt, $values, $lobColumns);
    }

    private function executeWithLobs(\PDOStatement $stmt, array $values, array $lobColumns): bool
    {
        foreach (array_values($values) as $i => $value) {
            $position = $i + 1;
            if (isset($lobColumns[$position])) {
                $stmt->bindValue($position, $value, $value === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
            } else {
                $stmt->bindValue($position, $value);
            }
        }

        return $stmt->execute();
    }

    /**
     * Delete an Archive door.
     */
    public function deleteDoor(string $doorId): bool
    {
        if (!self::isValidDoorId($doorId)) {
            return false;
        }

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare('DELETE FROM archive_doors WHERE door_id = ?');
        return $stmt->execute([$doorId]);
    }

    private function normalizeFields(array $fields): array
    {
        $genre = $fields['genre'] ?? [DoorCategoryHelper::INTERNET_ARCHIVE];
        if (!is_array($genre)) {
            $genre = [];
        }
        // Ensure Internet Archive Games is in genre
        if (!in_array(DoorCategoryHelper::INTERNET_ARCHIVE, $genre, true)) {
            array_unshift($genre, DoorCategoryHelper::INTERNET_ARCHIVE);
        }

        return [
            'name' => (string)($fields['name'] ?? ''),
            'short_name' => !empty($fields['short_name']) ? (string)$fields['short_name'] : null,
            'author' => $fields['author'] ?? null,
            'game_version' => $fields['game_version'] ?? null,
            'release_year' => isset($fields['release_year']) && $fields['release_year'] !== '' ? (int)$fields['release_year'] : null,
            'description' => $fields['description'] ?? null,
            'genre' => json_encode(array_values(array_unique($genre))),
            'players' => $fields['players'] ?? '1 Player',
            'archive_item_id' => self::cleanArchiveItemId((string)($fields['archive_item_id'] ?? '')),
            'enabled' => !empty($fields['enabled']) ? 'true' : 'false',
            'credit_cost' => isset($fields['credit_cost']) && $fields['credit_cost'] !== '' ? (int)$fields['credit_cost'] : 0,
            'max_time_minutes' => isset($fields['max_time_minutes']) && $fields['max_time_minutes'] !== '' ? (int)$fields['max_time_minutes'] : 60,
            'allow_anonymous' => !empty($fields['allow_anonymous']) ? 'true' : 'false',
            'hide_from_web' => !empty($fields['hide_from_web']) ? 'true' : 'false',
        ];
    }

    /**
     * Start (or resume) a play window for an archive door, enforcing the door's
     * anonymous-access, credit cost and time limit settings.
     *
     * The game itself runs on archive.org, so a play window is tracked in the PHP
     * session: reloading the page inside the window neither charges again nor resets
     * the clock. Credits are only ever debited here, server-side.
     *
     * @param array    $door   Door as returned by getDoor()
     * @param int|null $userId Logged-in user ID, or null for an anonymous visitor
     * @return array{ok: bool, error_code?: string, seconds_remaining: ?int, credits_charged: int}
     *         seconds_remaining is null when the door has no time limit
     */
    public function startPlay(array $door, ?int $userId): array
    {
        $config = $door['config'] ?? [];
        $cost = max(0, (int)($config['credit_cost'] ?? 0));
        $maxMinutes = max(0, (int)($config['max_time_minutes'] ?? 0));
        $doorId = (string)$door['door_id'];
        $now = time();

        if ($userId === null && (empty($config['allow_anonymous']) || $cost > 0)) {
            return ['ok' => false, 'error_code' => 'ui.webdoors.errors.archive_login_required', 'seconds_remaining' => null, 'credits_charged' => 0];
        }

        // A door with no time limit still needs a payment window so reloads do not re-charge
        $windowSeconds = $maxMinutes > 0 ? $maxMinutes * 60 : 14400;
        $owner = $userId ?? 0;

        $existing = $_SESSION['archive_door_play'][$doorId] ?? null;
        if (is_array($existing) && ($existing['user'] ?? null) === $owner && (int)($existing['expires'] ?? 0) > $now) {
            return [
                'ok' => true,
                'seconds_remaining' => $maxMinutes > 0 ? (int)$existing['expires'] - $now : null,
                'credits_charged' => 0,
            ];
        }

        $charged = 0;
        if ($userId !== null && $cost > 0 && UserCredit::isEnabled()) {
            if (UserCredit::getBalance($userId) < $cost
                || !UserCredit::debit($userId, $cost, 'Played archive door: ' . $doorId)) {
                return ['ok' => false, 'error_code' => 'ui.webdoors.errors.archive_insufficient_credits', 'seconds_remaining' => null, 'credits_charged' => 0];
            }
            $charged = $cost;
        }

        $_SESSION['archive_door_play'][$doorId] = ['user' => $owner, 'expires' => $now + $windowSeconds];

        return [
            'ok' => true,
            'seconds_remaining' => $maxMinutes > 0 ? $windowSeconds : null,
            'credits_charged' => $charged,
        ];
    }

    private function mapRow(array $row): array
    {
        $genre = json_decode($row['genre'] ?? '[]', true);
        if (!is_array($genre)) {
            $genre = [DoorCategoryHelper::INTERNET_ARCHIVE];
        }

        $archiveItemId = $row['archive_item_id'];

        return [
            'door_id' => $row['door_id'],
            'type' => 'archivedoor',
            'archive_item_id' => $archiveItemId,
            'embed_url' => "https://archive.org/embed/{$archiveItemId}",

            // Game info
            'name' => $row['name'],
            'short_name' => $row['short_name'] ?: $row['name'],
            'author' => $row['author'] ?: 'Unknown',
            'game_version' => $row['game_version'],
            'release_year' => $row['release_year'] !== null ? (int)$row['release_year'] : null,
            'description' => $row['description'] ?? '',
            'genre' => $genre,
            'players' => $row['players'] ?: '1 Player',
            'icon' => $row['icon_data'] !== null,
            'screenshot' => $row['screenshot_data'] !== null,

            // Config
            'config' => [
                'enabled' => $row['enabled'] === true || $row['enabled'] === 't' || $row['enabled'] === '1',
                'credit_cost' => (int)$row['credit_cost'],
                'max_time_minutes' => (int)$row['max_time_minutes'],
                'allow_anonymous' => $row['allow_anonymous'] === true || $row['allow_anonymous'] === 't' || $row['allow_anonymous'] === '1',
                'hide_from_web' => $row['hide_from_web'] === true || $row['hide_from_web'] === 't' || $row['hide_from_web'] === '1',
            ],
        ];
    }
}
