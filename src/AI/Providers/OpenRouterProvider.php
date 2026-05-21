<?php

namespace BinktermPHP\AI\Providers;

use BinktermPHP\AI\AiException;
use BinktermPHP\AI\AiPricing;
use BinktermPHP\AI\AiProviderInterface;
use BinktermPHP\AI\AiRequest;
use BinktermPHP\AI\AiResponse;
use BinktermPHP\AI\AiUsage;
use BinktermPHP\AI\HttpClient;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;

/**
 * OpenRouter provider — uses OpenRouter's OpenAI-compatible /chat/completions endpoint.
 *
 * OpenRouter acts as a proxy to many upstream models. The special model name
 * "openrouter/auto" lets OpenRouter choose the best available model automatically.
 * Because the actual model is unknown at request time when using auto-routing,
 * response_format (JSON mode) is not sent — JSON responses rely on prompt-level
 * instructions and the decodeJsonContent fallback parser instead.
 */
class OpenRouterProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $apiBase;
    private string $defaultModel;
    private bool $toolsSupported;
    private AiPricing $pricing;
    private Logger $logger;

    public function __construct(
        string $apiKey,
        string $apiBase,
        string $defaultModel,
        bool $toolsSupported,
        AiPricing $pricing
    ) {
        $this->apiKey = trim($apiKey);
        $this->apiBase = rtrim(trim($apiBase), '/');
        $this->defaultModel = $defaultModel !== '' ? $defaultModel : 'openrouter/auto';
        $this->toolsSupported = $toolsSupported;
        $this->pricing = $pricing;
        $this->logger = new Logger(Config::getLogPath('server.log'), Logger::LEVEL_DEBUG, false);
    }

    public function getName(): string
    {
        return 'openrouter';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function supportsTools(): bool
    {
        return $this->toolsSupported;
    }

    /**
     * @return array<int, string>
     */
    private function buildHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'HTTP-Referer: ' . Config::getSiteUrl(),
            'X-Title: BinktermPHP',
        ];
    }

    public function generateText(AiRequest $request): AiResponse
    {
        return $this->requestCompletion($request, false);
    }

    public function generateJson(AiRequest $request): AiResponse
    {
        return $this->requestCompletion($request, true);
    }

    /**
     * @param array<int, array{role: string, content: mixed}> $messages
     * @param array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @return array{content: array<int, mixed>, stop_reason: string, usage: AiUsage}
     */
    public function generateWithTools(
        array $messages,
        array $tools,
        string $systemPrompt,
        string $model,
        int $maxTokens = 4096
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $this->convertToolMessages($messages, $systemPrompt),
            'max_tokens' => $maxTokens,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->convertTools($tools);
            $payload['tool_choice'] = 'auto';
        }

        $url = $this->apiBase . '/chat/completions';
        $this->logger->debug('OpenRouter generateWithTools request: ' . $url . ' model=' . $model);

        try {
            $response = HttpClient::postJson(
                $url,
                $payload,
                $this->buildHeaders(),
                60
            );
        } catch (\Throwable $exception) {
            $this->logger->debug('OpenRouter generateWithTools network error: ' . $exception->getMessage());
            throw new AiException($this->getName(), $exception->getMessage(), null, 'network_error', null, $exception);
        }

        $this->logger->debug('OpenRouter generateWithTools response status=' . $response['status']);

        $body = $response['body'];
        if ($response['status'] >= 400) {
            $message = $body['error']['message'] ?? 'OpenRouter API request failed.';
            $code = $body['error']['code'] ?? 'api_error';
            $this->logger->error('OpenRouter API error: status=' . $response['status'] . ' code=' . $code . ' message=' . $message);
            throw new AiException($this->getName(), (string)$message, $response['status'], (string)$code, $response['raw']);
        }

        $message = is_array($body['choices'][0]['message'] ?? null) ? $body['choices'][0]['message'] : [];
        $contentBlocks = $this->normalizeToolResponseMessage($message);
        $finishReason = (string)($body['choices'][0]['finish_reason'] ?? 'stop');
        $stopReason = $finishReason === 'tool_calls' ? 'tool_use' : 'end_turn';

        $usage = new AiUsage(
            (int)($body['usage']['prompt_tokens'] ?? 0),
            (int)($body['usage']['completion_tokens'] ?? 0),
            0,
            0,
            (int)($body['usage']['total_tokens'] ?? 0)
        );
        $reportedCost = isset($body['usage']['cost']) ? (float)$body['usage']['cost'] : null;
        $usage = $usage->withEstimatedCostUsd(
            $reportedCost ?? $this->pricing->estimateCost($this->getName(), $model, $usage)
        );

        return [
            'content' => $contentBlocks,
            'stop_reason' => $stopReason,
            'usage' => $usage,
        ];
    }

    private function requestCompletion(AiRequest $request, bool $expectJson): AiResponse
    {
        $messages = [['role' => 'system', 'content' => $request->getSystemPrompt()]];
        if ($request->getConversationHistory() !== null) {
            foreach ($request->getConversationHistory() as $turn) {
                $messages[] = ['role' => (string)$turn['role'], 'content' => (string)$turn['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $request->getUserPrompt()];

        $payload = [
            'model' => $request->getModel(),
            'messages' => $messages,
            'temperature' => $request->getTemperature(),
            'max_tokens' => $request->getMaxOutputTokens(),
        ];

        // Omit response_format: the underlying model selected by openrouter/auto may not
        // support JSON mode. JSON responses rely on prompt-level instructions instead.

        $url = $this->apiBase . '/chat/completions';
        $this->logger->debug('OpenRouter request: ' . $url . ' model=' . ($request->getModel() ?? $this->defaultModel));

        try {
            $response = HttpClient::postJson(
                $url,
                $payload,
                $this->buildHeaders(),
                $request->getTimeoutSeconds()
            );
        } catch (\Throwable $exception) {
            $this->logger->debug('OpenRouter network error: ' . $exception->getMessage());
            throw new AiException($this->getName(), $exception->getMessage(), null, 'network_error', null, $exception);
        }

        $this->logger->debug('OpenRouter response status=' . $response['status']);

        $body = $response['body'];
        if ($response['status'] >= 400) {
            $message = $body['error']['message'] ?? 'OpenRouter API request failed.';
            $code = $body['error']['code'] ?? 'api_error';
            $this->logger->error('OpenRouter API error: status=' . $response['status'] . ' code=' . $code . ' message=' . $message);
            throw new AiException($this->getName(), (string)$message, $response['status'], (string)$code, $response['raw']);
        }

        $content = $this->extractContent($body);
        $parsedJson = $expectJson ? $this->decodeJsonContent($content) : null;

        $usage = new AiUsage(
            (int)($body['usage']['prompt_tokens'] ?? 0),
            (int)($body['usage']['completion_tokens'] ?? 0),
            0,
            0,
            (int)($body['usage']['total_tokens'] ?? 0)
        );
        $reportedCost = isset($body['usage']['cost']) ? (float)$body['usage']['cost'] : null;
        $usage = $usage->withEstimatedCostUsd(
            $reportedCost ?? $this->pricing->estimateCost($this->getName(), $request->getModel() ?? $this->getDefaultModel(), $usage)
        );

        return new AiResponse(
            $this->getName(),
            $request->getModel() ?? $this->getDefaultModel(),
            $content,
            $usage,
            $parsedJson,
            isset($body['id']) ? (string)$body['id'] : null,
            isset($body['choices'][0]['finish_reason']) ? (string)$body['choices'][0]['finish_reason'] : null,
            $body
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractContent(array $body): string
    {
        $content = $body['choices'][0]['message']['content'] ?? null;

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $item) {
                if (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                    $parts[] = $item['text'];
                }
            }
            if (!empty($parts)) {
                return implode("\n", $parts);
            }
        }

        throw new AiException($this->getName(), 'Missing assistant content in OpenRouter API response.', null, 'invalid_response');
    }

    private function decodeJsonContent(string $content): array
    {
        $decoded = json_decode(trim($content), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $preview = substr(trim($content), 0, 500);
        $this->logger->error('OpenRouter invalid JSON response. First 500 chars: ' . $preview);
        throw new AiException($this->getName(), 'OpenRouter response did not contain valid JSON.', null, 'invalid_json');
    }

    /**
     * @param array<int, array{role: string, content: mixed}> $messages
     * @return array<int, array<string, mixed>>
     */
    private function convertToolMessages(array $messages, string $systemPrompt): array
    {
        $converted = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? '');
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $converted[] = [
                    'role' => $role,
                    'content' => $content,
                ];
                continue;
            }

            if (!is_array($content)) {
                continue;
            }

            if ($role === 'assistant') {
                $assistantMessage = $this->convertAssistantBlocksToMessage($content);
                if ($assistantMessage !== null) {
                    $converted[] = $assistantMessage;
                }
                continue;
            }

            if ($role === 'user') {
                $toolMessages = $this->convertUserBlocksToToolMessages($content);
                foreach ($toolMessages as $toolMessage) {
                    $converted[] = $toolMessage;
                }
            }
        }

        return $converted;
    }

    /**
     * @param array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @return array<int, array<string, mixed>>
     */
    private function convertTools(array $tools): array
    {
        $converted = [];
        foreach ($tools as $tool) {
            $converted[] = [
                'type' => 'function',
                'function' => [
                    'name' => (string)$tool['name'],
                    'description' => (string)$tool['description'],
                    'parameters' => is_array($tool['input_schema'] ?? null)
                        ? $tool['input_schema']
                        : ['type' => 'object', 'properties' => []],
                ],
            ];
        }

        return $converted;
    }

    /**
     * @param array<int, mixed> $content
     * @return array<string, mixed>|null
     */
    private function convertAssistantBlocksToMessage(array $content): ?array
    {
        $textParts = [];
        $toolCalls = [];

        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $textParts[] = (string)$block['text'];
                continue;
            }

            if (($block['type'] ?? '') === 'tool_use') {
                $arguments = json_encode($block['input'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($arguments === false) {
                    $arguments = '{}';
                }

                $toolCalls[] = [
                    'id' => (string)($block['id'] ?? ''),
                    'type' => 'function',
                    'function' => [
                        'name' => (string)($block['name'] ?? ''),
                        'arguments' => $arguments,
                    ],
                ];
            }
        }

        if (empty($textParts) && empty($toolCalls)) {
            return null;
        }

        $message = [
            'role' => 'assistant',
            'content' => !empty($textParts) ? implode("\n", $textParts) : null,
        ];

        if (!empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
        }

        return $message;
    }

    /**
     * @param array<int, mixed> $content
     * @return array<int, array<string, mixed>>
     */
    private function convertUserBlocksToToolMessages(array $content): array
    {
        $converted = [];

        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'tool_result') {
                continue;
            }

            $converted[] = [
                'role' => 'tool',
                'tool_call_id' => (string)($block['tool_use_id'] ?? ''),
                'content' => (string)($block['content'] ?? ''),
            ];
        }

        return $converted;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<int, array<string, mixed>>
     */
    private function normalizeToolResponseMessage(array $message): array
    {
        $blocks = [];
        $content = $message['content'] ?? null;
        if (is_string($content) && trim($content) !== '') {
            $blocks[] = [
                'type' => 'text',
                'text' => $content,
            ];
        } elseif (is_array($content)) {
            foreach ($content as $item) {
                if (is_array($item) && isset($item['text']) && is_string($item['text']) && trim($item['text']) !== '') {
                    $blocks[] = [
                        'type' => 'text',
                        'text' => $item['text'],
                    ];
                }
            }
        }

        $toolCalls = $message['tool_calls'] ?? [];
        if (is_array($toolCalls)) {
            foreach ($toolCalls as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }

                $arguments = [];
                $rawArguments = $toolCall['function']['arguments'] ?? '{}';
                if (is_string($rawArguments)) {
                    $decodedArguments = json_decode($rawArguments, true);
                    if (is_array($decodedArguments)) {
                        $arguments = $decodedArguments;
                    }
                }

                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => (string)($toolCall['id'] ?? ''),
                    'name' => (string)($toolCall['function']['name'] ?? ''),
                    'input' => $arguments,
                ];
            }
        }

        return $blocks;
    }
}
