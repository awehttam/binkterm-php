<?php

namespace BinktermPHP\AiBot;

use BinktermPHP\Database;

/**
 * Database queries for ai_bots and ai_bot_activities.
 */
class AiBotRepository
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * Return all active bots that have at least one enabled activity of the given type.
     *
     * @return AiBot[]
     */
    public function getActiveBotsForActivity(string $activityType): array
    {
        $stmt = $this->db->prepare("
            SELECT b.*
            FROM   ai_bots b
            JOIN   ai_bot_activities a ON a.bot_id = b.id
            WHERE  b.is_active = TRUE
              AND  a.activity_type = ?
              AND  a.is_enabled = TRUE
        ");
        $stmt->execute([$activityType]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn($r) => AiBot::fromRow($r, $this->db), $rows);
    }

    /**
     * Return all active bots indexed by their user_id, for O(1) lookup
     * when processing incoming chat messages.
     *
     * @return array<int, AiBot>  keyed by user_id
     */
    public function getActiveChatBotsByUserId(): array
    {
        $bots = $this->getActiveBotsForActivity('local_chat');
        $indexed = [];
        foreach ($bots as $bot) {
            $indexed[$bot->userId] = $bot;
        }
        return $indexed;
    }

    /**
     * Return the activity config for a given bot and activity type.
     *
     * @return array<string,mixed>
     */
    public function getActivityConfig(int $botId, string $activityType): array
    {
        $stmt = $this->db->prepare("
            SELECT config_json
            FROM   ai_bot_activities
            WHERE  bot_id = ? AND activity_type = ?
        ");
        $stmt->execute([$botId, $activityType]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !$row['config_json']) {
            return [];
        }
        return json_decode($row['config_json'], true) ?? [];
    }

    /**
     * Return a single bot by ID, or null if not found.
     */
    public function findById(int $id): ?AiBot
    {
        $stmt = $this->db->prepare("SELECT * FROM ai_bots WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? AiBot::fromRow($row, $this->db) : null;
    }

    /**
     * Return all bots with current-week spend for the admin list.
     *
     * @return array<int, array<string,mixed>>
     */
    public function getAllBotsWithSpend(): array
    {
        $stmt = $this->db->prepare("
            SELECT b.*,
                   u.username,
                   COALESCE(
                       (
                           SELECT SUM(r.estimated_cost_usd)
                           FROM   ai_requests r
                           WHERE  r.bot_id = b.id
                             AND  r.created_at >= (
                                      date_trunc('week', NOW() AT TIME ZONE 'UTC') - INTERVAL '1 day'
                                      + CASE WHEN EXTRACT(DOW FROM NOW() AT TIME ZONE 'UTC') = 0
                                             THEN INTERVAL '7 days' ELSE INTERVAL '0 days' END
                                  )
                             AND  r.status = 'success'
                       ), 0
                   ) AS weekly_spend_usd
            FROM   ai_bots b
            JOIN   users u ON u.id = b.user_id
            ORDER  BY b.name
        ");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Return all activity rows for a given bot.
     *
     * @return array<int, array<string,mixed>>
     */
    public function getActivitiesForBot(int $botId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, activity_type, is_enabled, config_json
            FROM   ai_bot_activities
            WHERE  bot_id = ?
            ORDER  BY activity_type
        ");
        $stmt->execute([$botId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create a bot and its system user, returning the new bot ID.
     *
     * @param array<string,mixed> $data
     */
    public function createBot(array $data): int
    {
        $this->db->beginTransaction();
        try {
            // Reuse an existing system user with the same username, or create one.
            $existing = $this->db->prepare("
                SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND is_system = TRUE LIMIT 1
            ");
            $existing->execute([$data['username']]);
            $existingId = $existing->fetchColumn();

            if ($existingId !== false) {
                $userId = (int)$existingId;
            } else {
                $lockedHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $stmt = $this->db->prepare("
                    INSERT INTO users (username, password_hash, is_active, is_system)
                    VALUES (?, ?, TRUE, TRUE)
                    RETURNING id
                ");
                $stmt->execute([$data['username'], $lockedHash]);
                $userId = (int)$stmt->fetch(\PDO::FETCH_ASSOC)['id'];
            }

            // Create bot
            $stmt = $this->db->prepare("
                INSERT INTO ai_bots
                    (user_id, name, description, system_prompt, provider, model,
                     weekly_budget_usd, context_messages, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmt->execute([
                $userId,
                $data['name'],
                $data['description'] ?: null,
                $data['system_prompt'] ?? '',
                $data['provider'] ?: null,
                $data['model'] ?: null,
                (float)($data['weekly_budget_usd'] ?? 1.00),
                (int)($data['context_messages'] ?? 10),
                ($data['is_active'] ?? true) ? 'true' : 'false',
            ]);
            $botId = (int)$stmt->fetch(\PDO::FETCH_ASSOC)['id'];

            // Create default local_chat activity
            $activityCfg = json_encode([
                'respond_in_dm'    => true,
                'respond_in_rooms' => true,
                'allowed_room_ids' => [],
                'blocked_user_ids' => [],
            ]);
            $stmt = $this->db->prepare("
                INSERT INTO ai_bot_activities (bot_id, activity_type, is_enabled, config_json)
                VALUES (?, 'local_chat', TRUE, ?::jsonb)
            ");
            $stmt->execute([$botId, $activityCfg]);

            $this->db->commit();
            return $botId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update a bot's settings.
     *
     * @param array<string,mixed> $data
     */
    public function updateBot(int $id, array $data): void
    {
        $stmt = $this->db->prepare("
            UPDATE ai_bots
            SET    name = ?,
                   description = ?,
                   system_prompt = ?,
                   provider = ?,
                   model = ?,
                   weekly_budget_usd = ?,
                   context_messages = ?,
                   is_active = ?,
                   updated_at = NOW()
            WHERE  id = ?
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?: null,
            $data['system_prompt'] ?? '',
            $data['provider'] ?: null,
            $data['model'] ?: null,
            (float)($data['weekly_budget_usd'] ?? 1.00),
            (int)($data['context_messages'] ?? 10),
            ($data['is_active'] ?? true) ? 'true' : 'false',
            $id,
        ]);
    }

    /**
     * Update a single activity's enabled flag and config for a bot.
     *
     * @param array<string,mixed> $config
     */
    public function upsertActivity(int $botId, string $activityType, bool $isEnabled, array $config = []): void
    {
        $configJson = json_encode($config);
        $stmt = $this->db->prepare("
            INSERT INTO ai_bot_activities (bot_id, activity_type, is_enabled, config_json)
            VALUES (?, ?, ?, ?::jsonb)
            ON CONFLICT (bot_id, activity_type)
            DO UPDATE SET is_enabled = EXCLUDED.is_enabled,
                          config_json = EXCLUDED.config_json
        ");
        $stmt->execute([$botId, $activityType, $isEnabled ? 'true' : 'false', $configJson]);
    }

    /**
     * Delete a bot record and its activities. The associated system user is
     * intentionally preserved so that chat history and message attribution
     * remain intact after deletion.
     */
    public function deleteBot(int $id): void
    {
        // ai_bot_activities cascade-delete when the bot row is removed.
        $stmt = $this->db->prepare("DELETE FROM ai_bots WHERE id = ?");
        $stmt->execute([$id]);
    }
}
