<?php

namespace BinktermPHP\AI\Providers;

use BinktermPHP\AI\AiException;
use BinktermPHP\AI\AiPricing;
use BinktermPHP\AI\AiProviderInterface;
use BinktermPHP\AI\AiRequest;
use BinktermPHP\AI\AiResponse;
use BinktermPHP\AI\AiUsage;
use BinktermPHP\AI\HttpClient;

class AnthropicProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $apiBase;
    private AiPricing $pricing;

    public function __construct(string $apiKey, string $apiBase, AiPricing $pricing)
    {
        $this->apiKey = trim($apiKey);
        $this->apiBase = rtrim(trim($apiBase), '/');
        $this->pricing = $pricing;
    }

    public function getName(): string
    {
        return 'anthropic';
    }

    public function getDefaultModel(): string
    {
        return 'claude-sonnet-4-6';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function generateText(AiRequest $request): AiResponse
    {
        return $this->requestMessage($request, false);
    }

    public function generateJson(AiRequest $request): AiResponse
    {
        return $this->requestMessage($request, true);
    }

    private function requestMessage(AiRequest $request, bool $expectJson): AiResponse
    {
        $systemPrompt = $request->getSystemPrompt();
        if ($expectJson) {
            $systemPrompt .= "\nReturn only valid JSON. Do not add markdown or commentary.";
        }

        $payload = [
            'model' => $request->getModel(),
            'max_tokens' => $request->getMaxOutputTokens(),
            'temperature' => $request->getTemperature(),
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $request->getUserPrompt()],
            ],
        ];

        try {
            $response = HttpClient::postJson(
                $this->apiBase . '/messages',
                $payload,
                [
                    'Content-Type: application/json',
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: 2023-06-01',
                ],
                $request->getTimeoutSeconds()
            );
        } catch (\Throwable $exception) {
            throw new AiException($this->getName(), $exception->getMessage(), null, 'network_error', null, $exception);
        }

        $body = $response['body'];
        if ($response['status'] >= 400) {
            $message = $body['error']['message'] ?? 'Anthropic API request failed.';
            $code = $body['error']['type'] ?? 'api_error';
            throw new AiException($this->getName(), (string)$message, $response['status'], (string)$code, $response['raw']);
        }

        $content = $this->extractContent($body);
        $parsedJson = $expectJson ? $this->decodeJsonContent($content) : null;

        $usage = new AiUsage(
            (int)($body['usage']['input_tokens'] ?? 0),
            (int)($body['usage']['output_tokens'] ?? 0),
            (int)($body['usage']['cache_read_input_tokens'] ?? 0),
            (int)($body['usage']['cache_creation_input_tokens'] ?? 0)
        );
        $usage = $usage->withEstimatedCostUsd(
            $this->pricing->estimateCost($this->getName(), $request->getModel() ?? $this->getDefaultModel(), $usage)
        );

        return new AiResponse(
            $this->getName(),
            $request->getModel() ?? $this->getDefaultModel(),
            $content,
            $usage,
            $parsedJson,
            isset($body['id']) ? (string)$body['id'] : null,
            isset($body['stop_reason']) ? (string)$body['stop_reason'] : null,
            $body
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractContent(array $body): string
    {
        if (isset($body['content']) && is_array($body['content'])) {
            $parts = [];
            foreach ($body['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text']) && is_string($block['text'])) {
                    $parts[] = $block['text'];
                }
            }
            if (!empty($parts)) {
                return implode("\n", $parts);
            }
        }

        throw new AiException($this->getName(), 'Missing content in Anthropic API response.', null, 'invalid_response');
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

        throw new AiException($this->getName(), 'Anthropic response did not contain valid JSON.', null, 'invalid_json');
    }
}
