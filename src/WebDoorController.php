<?php

namespace BinktermPHP;

use BinktermPHP\Binkp\Config\BinkpConfig;

class WebDoorController
{
    private $db;
    private $auth;
    private $user;
    private $gameId;

    // Session lifetime in seconds (1 hour)
    private const SESSION_LIFETIME = 3600;

    // Maximum storage size per game per user (100KB)
    private const MAX_STORAGE_SIZE = 102400;

    // Maximum save slots per game
    private const MAX_SLOTS = 5;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->auth = new Auth();
    }

    /**
     * Set the current game context from the request
     */
    public function setGameContext(string $gameId): void
    {
        $this->gameId = $gameId;
    }

    /**
     * Get or create a WebDoor session
     */
    public function getSession(): array
    {
        $this->user = $this->auth->getCurrentUser();

        if (!$this->user) {
            return $this->errorResponse('Not authenticated', 401);
        }

        // Get game ID from query param or referer
        $gameId = $_GET['game_id'] ?? $this->detectGameIdFromReferer() ?? 'unknown';
        $this->gameId = $gameId;

        // Check for existing valid session
        $stmt = $this->db->prepare('
            SELECT session_id, game_id, created_at, expires_at
            FROM webdoor_sessions
            WHERE user_id = ? AND game_id = ? AND expires_at > NOW() AND ended_at IS NULL
            ORDER BY created_at DESC
            LIMIT 1
        ');
        $stmt->execute([$this->user['user_id'], $gameId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$session) {
            // Create new session
            $sessionId = bin2hex(random_bytes(32));
            $stmt = $this->db->prepare('
                INSERT INTO webdoor_sessions (session_id, user_id, game_id, expires_at)
                VALUES (?, ?, ?, NOW() + INTERVAL \'' . self::SESSION_LIFETIME . ' seconds\')
                RETURNING session_id, created_at, expires_at
            ');
            $stmt->execute([$sessionId, $this->user['user_id'], $gameId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            $session['game_id'] = $gameId;
        }

        $binkpConfig = BinkpConfig::getInstance();

        return [
            'session_id' => $session['session_id'],
            'user' => [
                'display_name' => $this->user['username'] ?: $this->user['real_name'],
                'user_id_hash' => hash('sha256', $this->user['user_id'] . Config::env('APP_SECRET', 'webdoor'))
            ],
            'host' => [
                'name' => $binkpConfig->getSystemName(),
                'version' => Version::getVersion(),
                'features' => ['storage', 'leaderboard']
            ],
            'game' => [
                'id' => $gameId,
                'name' => ucfirst($gameId)
            ],
            'expires_at' => $session['expires_at']
        ];
    }

    /**
     * End a WebDoor session
     */
    public function endSession(): array
    {
        $this->user = $this->auth->getCurrentUser();

        if (!$this->user) {
            return $this->errorResponse('Not authenticated', 401);
        }

        $input = $this->getJsonInput();
        $playtimeSeconds = (int)($input['playtime_seconds'] ?? 0);

        // End most recent active session for this user
        $stmt = $this->db->prepare('
            UPDATE webdoor_sessions
            SET ended_at = NOW(), playtime_seconds = ?
            WHERE user_id = ? AND ended_at IS NULL
            AND id = (
                SELECT id FROM webdoor_sessions
                WHERE user_id = ? AND ended_at IS NULL
                ORDER BY created_at DESC
                LIMIT 1
            )
        ');
        $stmt->execute([$playtimeSeconds, $this->user['user_id'], $this->user['user_id']]);

        return ['success' => true];
    }

    /**
     * List all save slots for current game
     */
    public function listSaves(): array
    {
        $this->user = $this->auth->getCurrentUser();

        if (!$this->user) {
            return $this->errorResponse('Not authenticated', 401);
        }

        $gameId = $_GET['game_id'] ?? $this->detectGameIdFromReferer() ?? 'unknown';

        $stmt = $this->db->prepare('
            SELECT slot, metadata, saved_at
            FROM webdoor_storage
            WHERE user_id = ? AND game_id = ?
            ORDER BY slot
        ');
        $stmt->execute([$this->user['user_id'], $gameId]);
        $saves = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Calculate total storage used
        $stmt = $this->db->prepare('
            SELECT COALESCE(SUM(LENGTH(data::text)), 0) as total_bytes
            FROM webdoor_storage
            WHERE user_id = ? AND game_id = ?
        ');
        $stmt->execute([$this->user['user_id'], $gameId]);
        $usage = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'slots' => array_map(function($save) {
                return [
                    'slot' => $save['slot'],
                    'metadata' => json_decode($save['metadata'], true) ?? [],
                    'saved_at' => $save['saved_at']
                ];
            }, $saves),
            'max_slots' => self::MAX_SLOTS,
            'used_bytes' => (int)$usage['total_bytes'],
            'max_bytes' => self::MAX_STORAGE_SIZE
        ];
    }

    /**
     * Load a specific save slot
     */
    public function loadSave(int $slot): ?array
    {
        $this->user = $this->auth->getCurrentUser();

        if (!$this->user) {
            return $this->errorResponse('Not authenticated', 401);
        }

        $gameId = $_GET['game_id'] ?? $this->detectGameIdFromReferer() ?? 'unknown';

        $stmt = $this->db->prepare('
            SELECT slot, data, metadata, saved_at
            FROM webdoor_storage
            WHERE user_id = ? AND game_id = ? AND slot = ?
        ');
        $stmt->execute([$this->user['user_id'], $gameId, $slot]);
        $save = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$save) {
            return null;
        }

        return [
            'slot' => $save['slot'],
            'data' => json_decode($save['data'], true) ?? [],
            'metadata' => json_decode($save['metadata'], true) ?? [],
            'saved_at' => $save['saved_at']
        ];
    }

    /**
     * Save game data to a slot
     */
    public function saveGame(int $slot): array
    {
        $this->user = $this->auth->getCurrentUser();

        if (!$this->user) {
            return $this->errorResponse('Not authenticated', 401);
        }

        if ($slot < 0 || $slot >= self::MAX_SLOTS) {
            return $this->errorResponse('Invalid slot number', 400);
        }

        $gameId = $_GET['game_id'] ?? $this->detectGameIdFromReferer() ?? 'unknown';
        $input = $this->getJsonInput();

        $data = $input['data'] ?? [];
        $metadata = $input['metadata'] ?? [];

        $dataJson = json_encode($data);
        $metadataJson = json_encode($metadata);

        // Check storage size
        if (strlen($dataJson) > self::MAX_STORAGE_SIZE) {
            return $this->errorResponse('Save data exceeds maximum size', 400);
        }

        // Upsert save data
        $stmt = $this->db->prepare('
            INSERT INTO webdoor_storage (user_id, game_id, slot, data, metadata, saved_at)
            VALUES (?, ?, ?, ?::jsonb, ?::jsonb, NOW())
            ON CONFLICT (user_id, game_id, slot)
            DO UPDATE SET data = EXCLUDED.data, metadata = EXCLUDED.metadata, saved_at = NOW()
            RETURNING saved_at
        ');
        $stmt->execute([$this->user['user_id'], $gameId, $slot, $dataJson, $metadataJson]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'slot' => $slot,
            'saved_at' => $result['saved_at']
        ];
    }

    /**
     * Delete a save slot
     */
    public function deleteSave(int $slot): array
    {
        $this->user = $this->auth->getCurrentUser();

        if (!$this->user) {
            return $this->errorResponse('Not authenticated', 401);
        }

        $gameId = $_GET['game_id'] ?? $this->detectGameIdFromReferer() ?? 'unknown';

        $stmt = $this->db->prepare('
            DELETE FROM webdoor_storage
            WHERE user_id = ? AND game_id = ? AND slot = ?
        ');
        $stmt->execute([$this->user['user_id'], $gameId, $slot]);

        return ['success' => true];
    }

    /**
     * Get leaderboard entries
     */
    public function getLeaderboard(string $board): array
    {
        $this->user = $this->auth->getCurrentUser();

        if (!$this->user) {
            return $this->errorResponse('Not authenticated', 401);
        }

        $gameId = $_GET['game_id'] ?? $this->detectGameIdFromReferer() ?? 'unknown';
        $limit = min((int)($_GET['limit'] ?? 10), 100);
        $scope = $_GET['scope'] ?? 'all';

        // Build date filter based on scope
        $dateFilter = '';
        $dateFilterSimple = '';
        switch ($scope) {
            case 'today':
                $dateFilter = "AND l.created_at >= CURRENT_DATE";
                $dateFilterSimple = "AND created_at >= CURRENT_DATE";
                break;
            case 'week':
                $dateFilter = "AND l.created_at >= CURRENT_DATE - INTERVAL '7 days'";
                $dateFilterSimple = "AND created_at >= CURRENT_DATE - INTERVAL '7 days'";
                break;
            case 'month':
                $dateFilter = "AND l.created_at >= CURRENT_DATE - INTERVAL '30 days'";
                $dateFilterSimple = "AND created_at >= CURRENT_DATE - INTERVAL '30 days'";
                break;
        }

        // Get top scores (one per user, highest score)
        $stmt = $this->db->prepare("
            SELECT DISTINCT ON (l.user_id)
                l.user_id, u.real_name, u.username, l.score, l.metadata, l.created_at
            FROM webdoor_leaderboards l
            JOIN users u ON l.user_id = u.id
            WHERE l.game_id = ? AND l.board = ? $dateFilter
            ORDER BY l.user_id, l.score DESC
        ");
        $stmt->execute([$gameId, $board]);
        $allScores = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Sort by score and rank
        usort($allScores, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        $entries = [];
        $userEntry = null;
        $rank = 0;

        foreach ($allScores as $index => $score) {
            $rank = $index + 1;
            $displayName = $score['username'] ?: $score['real_name'];

            $entry = [
                'rank' => $rank,
                'display_name' => $displayName,
                'score' => $score['score'],
                'metadata' => json_decode($score['metadata'], true) ?? [],
                'date' => substr($score['created_at'], 0, 10)
            ];

            if ($rank <= $limit) {
                $entries[] = $entry;
            }

            if ($score['user_id'] == $this->user['user_id']) {
                $userEntry = $entry;
            }
        }

        // Count total entries
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT user_id) as total
            FROM webdoor_leaderboards
            WHERE game_id = ? AND board = ? $dateFilterSimple
        ");
        $stmt->execute([$gameId, $board]);
        $total = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'board' => $board,
            'entries' => $entries,
            'user_entry' => $userEntry,
            'total_entries' => (int)$total['total']
        ];
    }

    /**
     * Submit a score to the leaderboard
     */
    public function submitScore(string $board): array
    {
        $this->user = $this->auth->getCurrentUser();

        if (!$this->user) {
            return $this->errorResponse('Not authenticated', 401);
        }

        $gameId = $_GET['game_id'] ?? $this->detectGameIdFromReferer() ?? 'unknown';
        $input = $this->getJsonInput();

        $score = (int)($input['score'] ?? 0);
        $metadata = $input['metadata'] ?? [];

        // Get user's previous best score
        $stmt = $this->db->prepare('
            SELECT MAX(score) as best_score
            FROM webdoor_leaderboards
            WHERE user_id = ? AND game_id = ? AND board = ?
        ');
        $stmt->execute([$this->user['user_id'], $gameId, $board]);
        $previous = $stmt->fetch(\PDO::FETCH_ASSOC);
        $previousBest = $previous['best_score'];

        // Insert new score
        $stmt = $this->db->prepare('
            INSERT INTO webdoor_leaderboards (user_id, game_id, board, score, metadata)
            VALUES (?, ?, ?, ?, ?::jsonb)
            RETURNING id
        ');
        $stmt->execute([
            $this->user['user_id'],
            $gameId,
            $board,
            $score,
            json_encode($metadata)
        ]);

        // Get current rank
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT user_id) + 1 as rank
            FROM webdoor_leaderboards
            WHERE game_id = ? AND board = ? AND score > ?
        ");
        $stmt->execute([$gameId, $board, $score]);
        $rankResult = $stmt->fetch(\PDO::FETCH_ASSOC);

        $isPersonalBest = $previousBest === null || $score > $previousBest;

        return [
            'accepted' => true,
            'rank' => (int)$rankResult['rank'],
            'is_personal_best' => $isPersonalBest,
            'previous_best' => $previousBest !== null ? (int)$previousBest : null
        ];
    }

    /**
     * Detect game ID from HTTP referer
     */
    private function detectGameIdFromReferer(): ?string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        // Extract game ID from paths like /webdoors/wordle/
        if (preg_match('#/webdoors/([a-zA-Z0-9_-]+)/#', $referer, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }

    /**
     * Create error response
     */
    private function errorResponse(string $message, int $code = 400): array
    {
        http_response_code($code);
        return ['error' => $message];
    }

    /**
     * Clean up expired sessions
     */
    public function cleanExpiredSessions(): void
    {
        $stmt = $this->db->prepare('
            DELETE FROM webdoor_sessions
            WHERE expires_at < NOW() - INTERVAL \'1 day\'
        ');
        $stmt->execute();
    }
}
