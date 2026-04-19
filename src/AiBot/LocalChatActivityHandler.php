<?php

namespace BinktermPHP\AiBot;

use BinktermPHP\AI\AiRequest;
use BinktermPHP\AI\AiService;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Database;

/**
 * Handles local chat activity for AI bots.
 *
 * Triggers:
 *   - chat_direct:  a direct message was sent to the bot user
 *   - chat_mention: a room message containing @BotUsername was posted
 *
 * Before the AI is called, a BotMiddlewarePipeline is executed.
 * Middleware can modify the system prompt, rewrite the message, supply a
 * static reply, or abort the response entirely — see BotMiddlewareInterface.
 */
class LocalChatActivityHandler implements BotActivityHandler
{
    private \PDO $db;
    private AiService $ai;
    private Logger $logger;

    public function __construct(\PDO $db, AiService $ai, Logger $logger)
    {
        $this->db     = $db;
        $this->ai     = $ai;
        $this->logger = $logger;
    }

    public function getActivityType(): string
    {
        return 'local_chat';
    }

    public function getLabel(): string
    {
        return 'Local Chat';
    }

    public function isReactive(): bool
    {
        return true;
    }

    public function handle(AiBot $bot, ActivityEvent $event): void
    {
        $payload    = $event->payload;
        $fromUserId = (int)($payload['from_user_id'] ?? 0);
        $roomId     = isset($payload['room_id']) && $payload['room_id'] !== null
            ? (int)$payload['room_id'] : null;
        $toUserId   = isset($payload['to_user_id']) && $payload['to_user_id'] !== null
            ? (int)$payload['to_user_id'] : null;
        $body       = (string)($payload['body'] ?? '');
        $msgId      = (int)($payload['id'] ?? 0);

        // Don't respond to our own messages.
        if ($fromUserId === $bot->userId) {
            return;
        }

        $repo   = new AiBotRepository($this->db);
        $config = $repo->getActivityConfig($bot->id, 'local_chat');

        // Check blocked users.
        $blockedUsers = (array)($config['blocked_user_ids'] ?? []);
        if (in_array($fromUserId, $blockedUsers, true)) {
            return;
        }

        // Validate trigger type against activity config.
        $respondInDm    = (bool)($config['respond_in_dm']    ?? true);
        $respondInRooms = (bool)($config['respond_in_rooms'] ?? true);
        $allowedRooms   = (array)($config['allowed_room_ids'] ?? []);

        if ($event->type === 'chat_direct') {
            if (!$respondInDm) {
                return;
            }
        } elseif ($event->type === 'chat_mention') {
            if (!$respondInRooms) {
                return;
            }
            if (!empty($allowedRooms) && !in_array($roomId, $allowedRooms, true)) {
                return;
            }
        } else {
            return;
        }

        // Look up the sender's username for middleware context.
        $fromUsername = $this->lookupUsername($fromUserId);

        // Build conversation history.
        $history = $this->buildContext(
            $bot,
            $roomId,
            $toUserId !== null ? $fromUserId : null,
            $msgId
        );

        // Assemble the pipeline context.
        $ctx = new BotContext(
            bot:                 $bot,
            fromUserId:          $fromUserId,
            fromUsername:        $fromUsername,
            roomId:              $roomId,
            toUserId:            $toUserId,
            activityConfig:      $config,
            systemPrompt:        $bot->systemPrompt,
            incomingMessage:     $body,
            conversationHistory: $history,
        );

        // Build the middleware pipeline from config.
        $middlewareConfig = (array)($config['middleware'] ?? []);
        $pipeline         = BotMiddlewarePipeline::fromConfig($middlewareConfig, $this->logger);

        $logger = $this->logger;
        $ai     = $this->ai;

        try {
            $pipeline->run($ctx, function (BotContext $ctx) use ($bot, $ai, $logger): void {
                // If a middleware already set a response or aborted, nothing to do.
                if ($ctx->aborted || $ctx->response !== null) {
                    return;
                }

                // Budget check — only enforced when the AI is actually called.
                if (!$bot->isUnderBudget()) {
                    $logger->warning('AI bot weekly budget reached, not responding', [
                        'bot_id'   => $bot->id,
                        'bot_name' => $bot->name,
                    ]);
                    $ctx->response = "I've reached my weekly limit and will be back on Sunday.";
                    return;
                }

                $request = new AiRequest(
                    feature:             'ai_bot',
                    systemPrompt:        $ctx->systemPrompt,
                    userPrompt:          $ctx->incomingMessage,
                    provider:            $bot->provider,
                    model:               $bot->model,
                    temperature:         0.7,
                    maxOutputTokens:     1024,
                    userId:              $bot->userId,
                    metadata:            [],
                    botId:               $bot->id,
                    conversationHistory: $ctx->conversationHistory,
                );

                $response = $ai->generateText($request);
                $content  = trim($response->getContent());
                if ($content === 'NO_RESPONSE') {
                    $ctx->aborted = true;
                    return;
                }
                $ctx->response = $content;
                $costUsd       = $response->getUsage()->getEstimatedCostUsd();

                $bot->addToCachedSpend($costUsd);

                $logger->info('AI bot responded to chat', [
                    'bot_id'   => $bot->id,
                    'bot_name' => $bot->name,
                    'cost_usd' => $costUsd,
                ]);
            });
        } catch (\Throwable $e) {
            $this->logger->error('AI bot chat response failed', [
                'bot_id'   => $bot->id,
                'bot_name' => $bot->name,
                'error'    => $e->getMessage(),
            ]);
            return;
        }

        if ($ctx->aborted || $ctx->response === null || $ctx->response === '') {
            return;
        }

        $this->postBotMessage(
            $bot->userId,
            $roomId,
            $toUserId !== null ? $fromUserId : null,
            $ctx->response
        );
    }

    /**
     * Look up a username by user ID; returns 'Unknown' on miss.
     */
    private function lookupUsername(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (string)$row['username'] : 'Unknown';
    }

    /**
     * Build a context array from recent chat history.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildContext(AiBot $bot, ?int $roomId, ?int $otherUserId, int $latestMsgId): array
    {
        if ($roomId !== null) {
            // Room context: last N messages before the triggering message.
            $stmt = $this->db->prepare("
                SELECT from_user_id, body
                FROM   chat_messages
                WHERE  room_id = ?
                  AND  id < ?
                ORDER  BY id DESC
                LIMIT  ?
            ");
            $stmt->execute([$roomId, $latestMsgId, $bot->contextMessages]);
        } else {
            // DM context: conversation between the two users.
            $stmt = $this->db->prepare("
                SELECT from_user_id, body
                FROM   chat_messages
                WHERE  (
                           (from_user_id = ? AND to_user_id = ?)
                        OR (from_user_id = ? AND to_user_id = ?)
                       )
                  AND  id < ?
                ORDER  BY id DESC
                LIMIT  ?
            ");
            $stmt->execute([
                $bot->userId, $otherUserId,
                $otherUserId, $bot->userId,
                $latestMsgId,
                $bot->contextMessages,
            ]);
        }

        $rows     = array_reverse($stmt->fetchAll(\PDO::FETCH_ASSOC));
        $messages = [];
        foreach ($rows as $row) {
            $messages[] = [
                'role'    => ((int)$row['from_user_id'] === $bot->userId) ? 'assistant' : 'user',
                'content' => (string)$row['body'],
            ];
        }
        return $messages;
    }

    /**
     * Insert a chat message from the bot into chat_messages and pre-render
     * markup_html into the sse_events row the INSERT trigger creates.
     *
     * Both operations are wrapped in an explicit transaction.  The INSERT
     * trigger calls pg_notify, but PostgreSQL only delivers NOTIFY to
     * listeners after the transaction commits — so wrapping INSERT + UPDATE
     * together guarantees the markup_html is present in sse_events before
     * any SSE client is woken up.  Without the transaction, there is a race
     * window between INSERT commit and the subsequent UPDATE where a client
     * could read the unenriched row and render raw markdown.
     */
    private function postBotMessage(int $botUserId, ?int $roomId, ?int $toUserId, string $body): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO chat_messages (room_id, from_user_id, to_user_id, body)
                VALUES (?, ?, ?, ?)
                RETURNING id
            ");
            $stmt->execute([$roomId, $botUserId, $toUserId, $body]);
            $chatId = (int)$stmt->fetchColumn();

            // Enrich the sse_events row the trigger just created with pre-rendered HTML.
            // This must happen before COMMIT so the payload is ready when pg_notify fires.
            $markupHtml = \BinktermPHP\MarkdownRenderer::toHtml($body);
            $enrichStmt = $this->db->prepare("
                UPDATE sse_events
                SET payload = payload || jsonb_build_object('markup_html', ?::text)
                WHERE event_type = 'chat_message'
                  AND (payload->>'id')::bigint = ?
            ");
            $enrichStmt->execute([$markupHtml, $chatId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
