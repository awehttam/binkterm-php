<?php
/**
 * RLogin Door Manager
 *
 * RLogin doors have no filesystem footprint — unlike DOS/Native/JS-DOS/WebDoors,
 * there is no executable, no manifest directory, nothing to scan on disk. This
 * class is the sole source of truth for RLogin door definitions, backed
 * directly by the `rlogin_doors` table (including uploaded icon/screenshot
 * images stored as BYTEA), and also runs the pre-login provisioning command
 * for a door before a session is launched.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class RLoginDoorManager
{
    private const VALID_BBS_TYPES = ['plain_rlogin', 'synchronet', 'synchronet_service'];
    private const VALID_ENCODINGS = ['utf8', 'cp437'];

    /**
     * Validate a door ID against a strict whitelist (used in URLs and as a DB key).
     */
    public static function isValidDoorId(string $doorId): bool
    {
        return $doorId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $doorId) === 1;
    }

    /**
     * Get all doors, keyed by door_id.
     *
     * @return array
     */
    public function getAllDoors(): array
    {
        $db = Database::getInstance()->getPdo();
        $stmt = $db->query('SELECT * FROM rlogin_doors ORDER BY name ASC');

        $doors = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $doors[$row['door_id']] = $this->mapRow($row);
        }

        return $doors;
    }

    /**
     * Get enabled doors only.
     *
     * @return array
     */
    public function getEnabledDoors(): array
    {
        $allDoors = $this->getAllDoors();

        $enabled = [];
        foreach ($allDoors as $doorId => $door) {
            if (!empty($door['config']['enabled'])) {
                $enabled[$doorId] = $door;
            }
        }

        return $enabled;
    }

    /**
     * Get a specific door.
     *
     * @param string $doorId Door identifier
     * @return array|null Door data or null if not found
     */
    public function getDoor(string $doorId): ?array
    {
        if (!self::isValidDoorId($doorId)) {
            return null;
        }

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare('SELECT * FROM rlogin_doors WHERE door_id = ?');
        $stmt->execute([$doorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * Check if a door is both installed and enabled.
     *
     * @param string $doorId Door identifier
     * @return bool True if door is available to play
     */
    public function isDoorAvailable(string $doorId): bool
    {
        $door = $this->getDoor($doorId);

        if (!$door) {
            return false;
        }

        return !empty($door['config']['enabled']);
    }

    /**
     * Fetch the stored icon blob for a door.
     *
     * @return array{data: string, mime: string}|null
     */
    public function getIconBlob(string $doorId): ?array
    {
        return $this->getBlob($doorId, 'icon_data', 'icon_mime');
    }

    /**
     * Fetch the stored screenshot blob for a door.
     *
     * @return array{data: string, mime: string}|null
     */
    public function getScreenshotBlob(string $doorId): ?array
    {
        return $this->getBlob($doorId, 'screenshot_data', 'screenshot_mime');
    }

    private function getBlob(string $doorId, string $dataColumn, string $mimeColumn): ?array
    {
        if (!self::isValidDoorId($doorId)) {
            return null;
        }

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare("SELECT $dataColumn AS data, $mimeColumn AS mime FROM rlogin_doors WHERE door_id = ?");
        $stmt->execute([$doorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || $row['data'] === null) {
            return null;
        }

        $data = $row['data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        return ['data' => $data, 'mime' => $row['mime'] ?: 'application/octet-stream'];
    }

    /**
     * Create a new RLogin door.
     *
     * @param string $doorId Door identifier (validated by the caller)
     * @param array $fields Field values (see saveFields())
     * @param array|null $icon ['data' => binary, 'mime' => string]|null
     * @param array|null $screenshot ['data' => binary, 'mime' => string]|null
     * @return bool Success
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
        }

        if ($screenshot !== null) {
            $columns[] = 'screenshot_data';
            $placeholders[] = '?';
            $values[] = $screenshot['data'];
            $lobColumns[count($values)] = true;
            $columns[] = 'screenshot_mime';
            $placeholders[] = '?';
            $values[] = $screenshot['mime'];
        }

        $sql = 'INSERT INTO rlogin_doors (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $db->prepare($sql);

        return $this->executeWithLobs($stmt, $values, $lobColumns);
    }

    /**
     * Update an existing RLogin door.
     *
     * @param string $doorId Door identifier
     * @param array $fields Field values (see saveFields())
     * @param array|null $icon ['data' => binary, 'mime' => string]|null — pass null to leave unchanged, ['data'=>null] to clear
     * @param array|null $screenshot Same as $icon
     * @return bool Success
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
        }

        if ($screenshot !== null) {
            $sets[] = 'screenshot_data = ?';
            $values[] = $screenshot['data'];
            $lobColumns[count($values)] = true;
            $sets[] = 'screenshot_mime = ?';
            $values[] = $screenshot['mime'];
        }

        $sets[] = 'updated_at = NOW()';

        $values[] = $doorId;

        $sql = 'UPDATE rlogin_doors SET ' . implode(', ', $sets) . ' WHERE door_id = ?';
        $stmt = $db->prepare($sql);

        return $this->executeWithLobs($stmt, $values, $lobColumns);
    }

    /**
     * Execute a prepared statement with positional (?) placeholders, binding
     * the given 1-indexed positions as PDO::PARAM_LOB (required for BYTEA
     * columns on the pgsql driver — a plain execute() array bind sends BYTEA
     * as text and corrupts binary data) and everything else as a normal
     * value bind.
     *
     * @param \PDOStatement $stmt
     * @param array $values 0-indexed values in placeholder order
     * @param array $lobColumns Set of 1-indexed positions (matching $values' 1-based position) that are BYTEA
     */
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
     * Delete an RLogin door and its synced dosbox_doors row.
     *
     * @param string $doorId Door identifier
     * @return bool Success
     */
    public function deleteDoor(string $doorId): bool
    {
        if (!self::isValidDoorId($doorId)) {
            return false;
        }

        $db = Database::getInstance()->getPdo();

        $stmt = $db->prepare("DELETE FROM dosbox_doors WHERE door_id = ? AND door_type = 'rlogin'");
        $stmt->execute([$doorId]);

        $stmt = $db->prepare('DELETE FROM rlogin_doors WHERE door_id = ?');

        return $stmt->execute([$doorId]);
    }

    /**
     * Normalize/validate a fields array (as submitted by the admin form) into
     * DB column => value pairs, applying defaults for anything omitted.
     *
     * Recognized keys: name, short_name, author, game_version, release_year,
     * description, genre (array), players, bbs_type, host, port,
     * client_username, server_username, terminal_type, terminal_speed,
     * output_encoding, pre_login_command, pre_login_timeout, admin_only,
     * enabled, credit_cost, max_time_minutes, max_sessions, allow_anonymous,
     * guest_max_sessions, hide_from_web.
     *
     * @param array $fields
     * @return array Column => value
     */
    private function normalizeFields(array $fields): array
    {
        $bbsType = $fields['bbs_type'] ?? 'plain_rlogin';
        if (!in_array($bbsType, self::VALID_BBS_TYPES, true)) {
            $bbsType = 'plain_rlogin';
        }

        $encoding = $fields['output_encoding'] ?? 'cp437';
        if (!in_array($encoding, self::VALID_ENCODINGS, true)) {
            $encoding = 'cp437';
        }

        $genre = $fields['genre'] ?? [];
        if (!is_array($genre)) {
            $genre = [];
        }

        return [
            'name' => (string)($fields['name'] ?? ''),
            'short_name' => !empty($fields['short_name']) ? (string)$fields['short_name'] : null,
            'author' => $fields['author'] ?? null,
            'game_version' => $fields['game_version'] ?? null,
            'release_year' => isset($fields['release_year']) && $fields['release_year'] !== '' ? (int)$fields['release_year'] : null,
            'description' => $fields['description'] ?? null,
            'genre' => json_encode(array_values($genre)),
            'players' => $fields['players'] ?? null,
            'bbs_type' => $bbsType,
            'host' => (string)($fields['host'] ?? ''),
            'port' => isset($fields['port']) && $fields['port'] !== '' ? (int)$fields['port'] : 513,
            // Blank is a deliberate, valid choice here (some rlogin daemons
            // accept an empty username field) -- unlike the other text
            // fields above, an empty value is not replaced with a default.
            'client_username' => isset($fields['client_username']) ? (string)$fields['client_username'] : '',
            'server_username' => isset($fields['server_username']) ? (string)$fields['server_username'] : '',
            // Blank means "use the connecting user's own established terminal type,
            // falling back to xterm-256color" — resolved at launch time, not here.
            'terminal_type' => !empty($fields['terminal_type']) ? (string)$fields['terminal_type'] : null,
            'terminal_speed' => isset($fields['terminal_speed']) && $fields['terminal_speed'] !== '' ? (int)$fields['terminal_speed'] : 38400,
            'output_encoding' => $encoding,
            'pre_login_command' => (isset($fields['pre_login_command']) && trim((string)$fields['pre_login_command']) !== '') ? (string)$fields['pre_login_command'] : null,
            'pre_login_timeout' => isset($fields['pre_login_timeout']) && $fields['pre_login_timeout'] !== '' ? (int)$fields['pre_login_timeout'] : 10,
            'admin_only' => !empty($fields['admin_only']) ? 'true' : 'false',
            'enabled' => !empty($fields['enabled']) ? 'true' : 'false',
            'credit_cost' => isset($fields['credit_cost']) && $fields['credit_cost'] !== '' ? (int)$fields['credit_cost'] : 0,
            'max_time_minutes' => isset($fields['max_time_minutes']) && $fields['max_time_minutes'] !== '' ? (int)$fields['max_time_minutes'] : 30,
            'max_sessions' => isset($fields['max_sessions']) && $fields['max_sessions'] !== '' ? (int)$fields['max_sessions'] : 10,
            'allow_anonymous' => !empty($fields['allow_anonymous']) ? 'true' : 'false',
            'guest_max_sessions' => isset($fields['guest_max_sessions']) && $fields['guest_max_sessions'] !== '' ? (int)$fields['guest_max_sessions'] : 2,
            'hide_from_web' => !empty($fields['hide_from_web']) ? 'true' : 'false',
        ];
    }

    /**
     * Map a DB row into the shape used throughout the door system (mirrors
     * the shape the old file-manifest scanner used to produce, so callers
     * elsewhere in the app didn't need to change).
     */
    private function mapRow(array $row): array
    {
        $genre = json_decode($row['genre'] ?? '[]', true);
        if (!is_array($genre)) {
            $genre = [];
        }

        return [
            'door_id' => $row['door_id'],
            'type' => 'rlogindoor',

            // Game info
            'name' => $row['name'],
            'short_name' => $row['short_name'] ?: $row['name'],
            'author' => $row['author'] ?: 'Unknown',
            'game_version' => $row['game_version'],
            'release_year' => $row['release_year'] !== null ? (int)$row['release_year'] : null,
            'description' => $row['description'] ?? '',
            'genre' => $genre,
            'players' => $row['players'],
            'icon' => $row['icon_data'] !== null,
            'screenshot' => $row['screenshot_data'] !== null,

            // Door technical info
            'bbs_type' => $row['bbs_type'],
            'host' => $row['host'],
            'port' => (int)$row['port'],
            'client_username' => $row['client_username'],
            'server_username' => $row['server_username'],
            'terminal_type' => $row['terminal_type'],
            'terminal_speed' => (int)$row['terminal_speed'],
            'output_encoding' => $row['output_encoding'],
            'pre_login_command' => $row['pre_login_command'],
            'pre_login_timeout' => (int)$row['pre_login_timeout'],

            // Requirements
            'admin_only' => $row['admin_only'] === true || $row['admin_only'] === 't' || $row['admin_only'] === '1',

            // Runtime config
            'config' => [
                'enabled' => $row['enabled'] === true || $row['enabled'] === 't' || $row['enabled'] === '1',
                'credit_cost' => (int)$row['credit_cost'],
                'max_time_minutes' => (int)$row['max_time_minutes'],
                'max_sessions' => (int)$row['max_sessions'],
                'allow_anonymous' => $row['allow_anonymous'] === true || $row['allow_anonymous'] === 't' || $row['allow_anonymous'] === '1',
                'guest_max_sessions' => (int)$row['guest_max_sessions'],
                'hide_from_web' => $row['hide_from_web'] === true || $row['hide_from_web'] === 't' || $row['hide_from_web'] === '1',
            ],
        ];
    }

    /**
     * Sync doors to the shared dosbox_doors table (door_type='rlogin'), the
     * same catalog table DOS/Native doors sync into, so door_sessions' FK
     * and the shared session infrastructure keep working unchanged. Enabled
     * doors are upserted; disabled doors are removed.
     *
     * @return array ['synced' => count, 'errors' => [...]]
     */
    public function syncDoorsToDatabase(): array
    {
        $db = Database::getInstance()->getPdo();
        $allDoors = $this->getAllDoors();

        $synced = 0;
        $errors = [];

        foreach ($allDoors as $doorId => $door) {
            if (empty($door['config']['enabled'])) {
                try {
                    $stmt = $db->prepare("DELETE FROM dosbox_doors WHERE door_id = ? AND door_type = 'rlogin'");
                    $stmt->execute([$doorId]);
                } catch (\Exception $e) {
                    $errors[] = "Failed to remove disabled rlogin door '$doorId': " . $e->getMessage();
                }
                continue;
            }

            try {
                $stmt = $db->prepare("SELECT id FROM dosbox_doors WHERE door_id = ?");
                $stmt->execute([$doorId]);
                $exists = $stmt->fetch();

                $config = $door['config'];
                $executable = $door['host'] . ':' . $door['port'];

                if ($exists) {
                    $stmt = $db->prepare("
                        UPDATE dosbox_doors
                        SET name = ?,
                            description = ?,
                            executable = ?,
                            path = ?,
                            config = ?,
                            enabled = ?,
                            door_type = ?,
                            updated_at = NOW()
                        WHERE door_id = ?
                    ");
                    $stmt->execute([
                        $door['name'],
                        $door['description'] ?? '',
                        $executable,
                        '',
                        json_encode($config),
                        'true',
                        'rlogin',
                        $doorId
                    ]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO dosbox_doors
                        (door_id, name, description, executable, path, config, enabled, door_type)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $doorId,
                        $door['name'],
                        $door['description'] ?? '',
                        $executable,
                        '',
                        json_encode($config),
                        'true',
                        'rlogin'
                    ]);
                }

                $synced++;
            } catch (\Exception $e) {
                $errors[] = "Failed to sync rlogin door '$doorId': " . $e->getMessage();
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    /**
     * Run the door's pre-login provisioning command, if configured.
     *
     * The command template supports the placeholders {user_name}, {real_name},
     * and {user_number}, substituted before execution. On success (exit code 0),
     * stdout is optionally parsed as JSON to pick up override values the launch
     * step can't know in advance (e.g. remote_username, otp). On failure
     * (non-zero exit), the launch must be aborted.
     *
     * @param array $door Door data as returned by getDoor()
     * @param array $placeholders ['user_name' => ..., 'real_name' => ..., 'user_number' => ...]
     * @return array{ok: bool, overrides: array, error: ?string}
     */
    public function runPreLoginCommand(array $door, array $placeholders): array
    {
        $template = $door['pre_login_command'] ?? null;

        if ($template === null || trim($template) === '') {
            return ['ok' => true, 'overrides' => [], 'error' => null];
        }

        // Tokenize the template BEFORE substituting placeholders, not after.
        // Placeholder values (usernames, real names) are arbitrary user data
        // that may contain quote characters — e.g. a real name of "G'ould
        // Master". Substituting first and tokenizing second would let a
        // stray quote in that data be misread as shell-style quoting syntax
        // by tokenizeCommand(), silently merging/mangling arguments. Tokenizing
        // the sysop-authored template first means placeholder values are only
        // ever inserted as literal token content.
        $rawTokens = $this->tokenizeCommand($template);
        if (empty($rawTokens)) {
            return ['ok' => false, 'overrides' => [], 'error' => 'pre_login_command could not be parsed'];
        }

        $substitutions = [
            '{user_name}' => $placeholders['user_name'] ?? '',
            '{real_name}' => $placeholders['real_name'] ?? '',
            '{user_number}' => $placeholders['user_number'] ?? '',
        ];
        $tokens = array_map(static function ($token) use ($substitutions) {
            return strtr($token, $substitutions);
        }, $rawTokens);

        $timeout = (int)($door['pre_login_timeout'] ?? 10);
        if ($timeout <= 0) {
            $timeout = 10;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cmdArgs = array_map(static function ($token) {
            return escapeshellarg($token);
        }, $tokens);
        $cmdLine = implode(' ', $cmdArgs);

        // Run from the project root so relative paths in pre_login_command
        // (e.g. "php scripts/synchronet_add_user.php ...") resolve
        // the same way they would from a terminal, regardless of what cwd the
        // web server process itself happens to run with.
        $cwd = defined('BINKTERMPHP_BASEDIR') ? BINKTERMPHP_BASEDIR : __DIR__ . '/..';

        $process = proc_open($cmdLine, $descriptorSpec, $pipes, $cwd);

        if (!is_resource($process)) {
            return ['ok' => false, 'overrides' => [], 'error' => 'Failed to start pre_login_command'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();
        $exitCode = null;

        while (true) {
            $status = proc_get_status($process);

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                // proc_get_status() only reports the real exit code on the
                // first call after the child has exited; every subsequent
                // call (including the one proc_close() makes internally)
                // returns -1 because the kernel has already reaped the
                // child and PHP's cached value was consumed here. Capture
                // it from this status snapshot rather than from proc_close()'s
                // return value.
                $exitCode = $status['exitcode'];
                break;
            }

            if ((time() - $start) >= $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return ['ok' => false, 'overrides' => [], 'error' => 'pre_login_command timed out'];
            }

            usleep(50000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($exitCode !== 0) {
            $error = trim($stderr) !== '' ? trim($stderr) : "pre_login_command exited with status $exitCode";
            return ['ok' => false, 'overrides' => [], 'error' => $error];
        }

        $overrides = [];
        $stdout = trim($stdout);
        if ($stdout !== '') {
            $decoded = json_decode($stdout, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (isset($decoded['remote_username'])) {
                    $overrides['remote_username'] = (string)$decoded['remote_username'];
                }
                if (isset($decoded['otp'])) {
                    $overrides['otp'] = (string)$decoded['otp'];
                }
            }
        }

        return ['ok' => true, 'overrides' => $overrides, 'error' => null];
    }

    /**
     * Split a command string into tokens, respecting single/double quotes.
     *
     * @param string $command
     * @return string[]
     */
    private function tokenizeCommand(string $command): array
    {
        $tokens = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;
        $len = strlen($command);

        for ($i = 0; $i < $len; $i++) {
            $ch = $command[$i];

            if ($inSingle) {
                if ($ch === "'") {
                    $inSingle = false;
                } else {
                    $current .= $ch;
                }
                continue;
            }

            if ($inDouble) {
                if ($ch === '"') {
                    $inDouble = false;
                } else {
                    $current .= $ch;
                }
                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                continue;
            }

            if ($ch === '"') {
                $inDouble = true;
                continue;
            }

            if (ctype_space($ch)) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                continue;
            }

            $current .= $ch;
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
