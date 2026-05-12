<?php

namespace BinktermPHP\Chat;

use BinktermPHP\ActivityTracker;
use BinktermPHP\Database;
use BinktermPHP\MarkdownRenderer;

/**
 * Centralized local chat sender with optional Matterbridge fan-out.
 */
class ChatMessageService
{
    private \PDO $db;
    private MatterbridgeService $matterbridge;
    private \BinktermPHP\Binkp\Logger $logger;

    public function __construct(
        ?\PDO $db = null,
        ?MatterbridgeService $matterbridge = null,
        ?\BinktermPHP\Binkp\Logger $logger = null
    ) {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->matterbridge = $matterbridge ?? new MatterbridgeService();
        $this->logger = $logger ?? getServerLogger();
    }

    /**
     * @return array{id:int,created_at:string,markup_html:string}
     */
    public function sendMessage(int $fromUserId, ?int $roomId, ?int $toUserId, string $body, bool $bridgeOutbound = true): array
    {
        $body = trim($body);
        if ($fromUserId <= 0) {
            throw new \RuntimeException('A valid sender is required');
        }
        if (($roomId === null && $toUserId === null) || ($roomId !== null && $toUserId !== null)) {
            throw new \RuntimeException('Exactly one chat target is required');
        }
        if ($body === '') {
            throw new \RuntimeException('Message body is required');
        }

        $this->db->beginTransaction();
        try {
            if ($roomId !== null) {
                $stmt = $this->db->prepare("
                    INSERT INTO chat_messages (room_id, from_user_id, to_user_id, body)
                    SELECT ?, ?, ?, ?
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM chat_room_bans
                        WHERE room_id = ? AND user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
                    )
                    RETURNING id, created_at
                ");
                $stmt->execute([$roomId, $fromUserId, $toUserId, $body, $roomId, $fromUserId]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO chat_messages (room_id, from_user_id, to_user_id, body)
                    VALUES (?, ?, ?, ?)
                    RETURNING id, created_at
                ");
                $stmt->execute([$roomId, $fromUserId, $toUserId, $body]);
            }
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \RuntimeException('Chat message insert blocked');
            }

            $chatId = (int) $row['id'];
            $markupHtml = MarkdownRenderer::toHtml($body);
            $enrichStmt = $this->db->prepare("
                UPDATE sse_events
                SET payload = payload || jsonb_build_object('markup_html', ?::text)
                WHERE event_type = 'chat_message'
                  AND (payload->>'id')::bigint = ?
            ");
            $enrichStmt->execute([$markupHtml, $chatId]);

            $this->db->commit();

            if ($roomId !== null) {
                ActivityTracker::track($fromUserId, ActivityTracker::TYPE_CHAT_SEND, $roomId);
            } else {
                ActivityTracker::track($fromUserId, ActivityTracker::TYPE_CHAT_SEND, null);
            }

            if ($bridgeOutbound && $roomId !== null) {
                $this->relayRoomMessageToMatterbridge($roomId, $fromUserId, $body);
            }

            return [
                'id' => $chatId,
                'created_at' => (string) $row['created_at'],
                'markup_html' => $markupHtml,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    public function sendMatterbridgeMessage(string $gateway, string $body, string $username, array $options = []): bool
    {
        return $this->matterbridge->sendMessage($gateway, $body, $username, $options);
    }

    private function relayRoomMessageToMatterbridge(int $roomId, int $fromUserId, string $body): void
    {
        $stmt = $this->db->prepare("
            SELECT name, matterbridge_enabled, matterbridge_gateway, matterbridge_options
            FROM chat_rooms
            WHERE id = ?
        ");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$room || empty($room['matterbridge_enabled'])) {
            return;
        }

        $gateway = trim((string) ($room['matterbridge_gateway'] ?? ''));
        if ($gateway === '') {
            return;
        }

        $userStmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $userStmt->execute([$fromUserId]);
        $sender = $userStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$sender) {
            return;
        }

        $options = [];
        $decodedOptions = json_decode((string) ($room['matterbridge_options'] ?? '{}'), true);
        if (is_array($decodedOptions)) {
            $options = $decodedOptions;
        }

        $usernameTemplate = trim((string) ($options['username_template'] ?? ''));
        $usernameSuffix = trim((string) ($options['username_suffix'] ?? ''));
        if ($usernameTemplate !== '') {
            $username = str_replace(
                ['{username}', '{room_name}'],
                [(string) $sender['username'], (string) ($room['name'] ?? '')],
                $usernameTemplate
            );
        } else {
            $defaultSuffix = $this->matterbridgeUsernameSuffix();
            $suffix = $usernameSuffix !== '' ? $usernameSuffix : $defaultSuffix;
            $username = (string) $sender['username'] . $suffix;
        }

        try {
            $sent = $this->matterbridge->sendMessage($gateway, $body, $username, [
                'userid' => (string) $fromUserId,
            ]);
            if (!$sent) {
                $this->logger->warning('Matterbridge relay returned failure', [
                    'room_id' => $roomId,
                    'gateway' => $gateway,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Matterbridge relay failed', [
                'room_id' => $roomId,
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function matterbridgeUsernameSuffix(): string
    {
        return MatterbridgeConfig::getInstance()->getUsernameSuffix();
    }
}
