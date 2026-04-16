<?php

namespace BinktermPHP\AI;

use BinktermPHP\Database;

/**
 * Persists normalized AI request rows for reporting and cost estimation.
 */
class UsageRecorder
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    public function recordSuccess(AiRequest $request, string $operation, AiResponse $response, int $durationMs): void
    {
        $usage = $response->getUsage();

        $this->insertRow([
            'user_id' => $request->getUserId(),
            'bot_id' => $request->getBotId(),
            'provider' => $response->getProvider(),
            'model' => $response->getModel(),
            'feature' => $request->getFeature(),
            'operation' => $operation,
            'status' => 'success',
            'request_id' => $response->getRequestId(),
            'input_tokens' => $usage->getInputTokens(),
            'output_tokens' => $usage->getOutputTokens(),
            'cached_input_tokens' => $usage->getCachedInputTokens(),
            'cache_write_tokens' => $usage->getCacheWriteTokens(),
            'total_tokens' => $usage->getTotalTokens(),
            'estimated_cost_usd' => $usage->getEstimatedCostUsd(),
            'duration_ms' => $durationMs,
            'http_status' => null,
            'error_code' => null,
            'error_message' => null,
            'metadata_json' => $request->getMetadata(),
        ]);
    }

    public function recordFailure(
        AiRequest $request,
        string $provider,
        string $model,
        string $operation,
        int $durationMs,
        \Throwable $exception
    ): void {
        $httpStatus = null;
        $errorCode = '';
        $metadata = $request->getMetadata();

        if ($exception instanceof AiException) {
            $httpStatus = $exception->getHttpStatus();
            $errorCode = $exception->getErrorCode();
            $responseBody = $exception->getResponseBody();
            if ($responseBody !== null && $responseBody !== '') {
                $metadata['response_body'] = $responseBody;
            }
        }

        $this->insertRow([
            'user_id' => $request->getUserId(),
            'bot_id' => $request->getBotId(),
            'provider' => $provider,
            'model' => $model,
            'feature' => $request->getFeature(),
            'operation' => $operation,
            'status' => 'error',
            'request_id' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cached_input_tokens' => 0,
            'cache_write_tokens' => 0,
            'total_tokens' => 0,
            'estimated_cost_usd' => 0,
            'duration_ms' => $durationMs,
            'http_status' => $httpStatus,
            'error_code' => $errorCode !== '' ? $errorCode : null,
            'error_message' => $exception->getMessage(),
            'metadata_json' => $metadata,
        ]);
    }

    /**
     * Record one round of an agentic tool-use loop.
     *
     * Used by AgentService, which calls generateWithTools() directly on a
     * provider (bypassing AiService) and accumulates usage across rounds.
     *
     * @param int         $userId
     * @param string      $feature     e.g. 'message_ai_assistant'
     * @param string      $provider    e.g. 'anthropic'
     * @param string      $model
     * @param int         $round       1-based round number (stored in metadata)
     * @param int         $toolCalls   number of tool calls executed this round
     * @param AiUsage     $usage       token counts and estimated cost for this round
     * @param int         $durationMs
     * @param string|null $stopReason  'tool_use', 'end_turn', etc.
     */
    public function recordAgentRound(
        int $userId,
        string $feature,
        string $provider,
        string $model,
        int $round,
        int $toolCalls,
        AiUsage $usage,
        int $durationMs,
        ?string $stopReason = null
    ): void {
        $this->insertRow([
            'user_id'              => $userId,
            'bot_id'               => null,
            'provider'             => $provider,
            'model'                => $model,
            'feature'              => $feature,
            'operation'            => 'generate_with_tools',
            'status'               => 'success',
            'request_id'           => null,
            'input_tokens'         => $usage->getInputTokens(),
            'output_tokens'        => $usage->getOutputTokens(),
            'cached_input_tokens'  => $usage->getCachedInputTokens(),
            'cache_write_tokens'   => $usage->getCacheWriteTokens(),
            'total_tokens'         => $usage->getTotalTokens(),
            'estimated_cost_usd'   => $usage->getEstimatedCostUsd(),
            'duration_ms'          => $durationMs,
            'http_status'          => null,
            'error_code'           => null,
            'error_message'        => null,
            'metadata_json'        => [
                'agent_round'       => $round,
                'tool_calls'        => $toolCalls,
                'stop_reason'       => $stopReason,
            ],
        ]);
    }

    private function insertRow(array $row): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO ai_requests (
                user_id,
                bot_id,
                provider,
                model,
                feature,
                operation,
                status,
                request_id,
                input_tokens,
                output_tokens,
                cached_input_tokens,
                cache_write_tokens,
                total_tokens,
                estimated_cost_usd,
                duration_ms,
                http_status,
                error_code,
                error_message,
                metadata_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
        ");

        $json = json_encode($row['metadata_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }

        $stmt->execute([
            $row['user_id'],
            $row['bot_id'] ?? null,
            $row['provider'],
            $row['model'],
            $row['feature'],
            $row['operation'],
            $row['status'],
            $row['request_id'],
            $row['input_tokens'],
            $row['output_tokens'],
            $row['cached_input_tokens'],
            $row['cache_write_tokens'],
            $row['total_tokens'],
            $row['estimated_cost_usd'],
            $row['duration_ms'],
            $row['http_status'],
            $row['error_code'],
            $row['error_message'],
            $json,
        ]);
    }
}
