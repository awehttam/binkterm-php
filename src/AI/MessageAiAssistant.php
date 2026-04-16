<?php

namespace BinktermPHP\AI;

use BinktermPHP\Config;
use BinktermPHP\UserCredit;
use BinktermPHP\UserMeta;

/**
 * AI assistant for echomail and netmail message readers.
 *
 * Ensures the user has an MCP server key (generating one automatically if
 * needed), then runs an agentic tool-use loop via AgentService so the AI
 * can look up messages, threads, and echoareas through the MCP server.
 * Credit cost is calculated from actual token spend and debited from the
 * user's balance.
 */
class MessageAiAssistant
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a concise, helpful assistant embedded in a Fidonet BBS message reader.
You have access to tools that let you fetch messages, conversation threads,
and search echoareas. When the user asks about a message, use the tools to
retrieve the relevant content before answering. Keep your responses brief
and relevant to FTN/BBS users. Do not make up message content — always fetch
it with a tool first.
PROMPT;

    /**
     * Execute an AI assistant request for a message reader.
     *
     * @param string      $userPrompt   The user's prompt (max 500 chars, enforced by the route)
     * @param int|null    $messageId    ID of the message currently being viewed (optional context)
     * @param string      $messageType  'echomail' or 'netmail'
     * @param int         $userId       Authenticated user ID
     * @return array{response: string, credits_used: int, balance: int}
     * @throws \RuntimeException if the AI provider is not configured or the request fails
     * @throws InsufficientCreditsException if the user cannot afford the request
     */
    public static function execute(
        string $userPrompt,
        ?int $messageId,
        string $messageType,
        int $userId
    ): array {
        $mcpKey = self::ensureMcpKey($userId);

        $mcpUrl    = (string)Config::env('MCP_SERVER_URL', 'http://localhost:3740');
        $mcpClient = new McpClient($mcpUrl, $mcpKey);

        // Prepend message context hint so the AI knows where to start
        $fullPrompt = $userPrompt;
        if ($messageId !== null) {
            $fullPrompt = "[Context: the user is viewing {$messageType} message ID {$messageId}]\n\n{$userPrompt}";
        }

        $request = new AiRequest(
            'message_ai_assistant',
            self::SYSTEM_PROMPT,
            $fullPrompt,
            null,
            null,
            0.2,
            4096,
            60,
            $userId
        );

        $resolved = self::buildResolvedProvider($request);
        $agent = new AgentService($resolved['provider'], $resolved['model']);

        $result = $agent->run(
            self::SYSTEM_PROMPT,
            $fullPrompt,
            $mcpClient,
            maxRounds: 5,
            feature: 'message_ai_assistant',
            userId: $userId
        );

        $costUsd     = $result->getTotalUsage()->getEstimatedCostUsd();
        $creditsUsed = UserCredit::calculateAiUsageCost($costUsd);

        if ($creditsUsed > 0) {
            $debited = UserCredit::debit(
                $userId,
                $creditsUsed,
                'AI Assistant',
                null,
                UserCredit::TYPE_PAYMENT
            );
            if (!$debited) {
                throw new InsufficientCreditsException($creditsUsed);
            }
        }

        $balance = UserCredit::getBalance($userId);

        return [
            'response'     => $result->getText(),
            'credits_used' => $creditsUsed,
            'balance'      => $balance,
        ];
    }

    /**
     * Return the user's MCP server key, generating and storing one if absent.
     */
    private static function ensureMcpKey(int $userId): string
    {
        $meta = new UserMeta();
        $key  = $meta->getValue($userId, 'mcp_serverkey');

        if ($key === null || $key === '') {
            $key = bin2hex(random_bytes(32));
            $meta->setValue($userId, 'mcp_serverkey', $key);
        }

        return $key;
    }

    /**
     * Resolve a configured tool-capable provider and model for the assistant.
     *
     * @return array{provider: AiProviderInterface, model: string}
     * @throws \RuntimeException if no configured tool-capable provider is available
     */
    private static function buildResolvedProvider(AiRequest $request): array
    {
        $service = AiService::create();
        $resolved = $service->resolveRequest($request);
        $provider = $resolved['provider'];

        if (!$provider->supportsTools()) {
            throw new \RuntimeException("AI provider '{$provider->getName()}' does not support tool use.");
        }

        return [
            'provider' => $provider,
            'model' => $resolved['model'],
        ];
    }
}
