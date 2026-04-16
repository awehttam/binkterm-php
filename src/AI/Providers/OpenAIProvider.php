<?php

namespace BinktermPHP\AI\Providers;

use BinktermPHP\AI\AiException;
use BinktermPHP\AI\AiPricing;
use BinktermPHP\AI\AiProviderInterface;
use BinktermPHP\AI\AiRequest;
use BinktermPHP\AI\AiResponse;
use BinktermPHP\AI\AiUsage;
use BinktermPHP\AI\HttpClient;

class OpenAIProvider implements AiProviderInterface
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
        return 'openai';
    }

    public function getDefaultModel(): string
    {
        return 'gpt-4o-mini';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function supportsTools(): bool
    {
        return false;
    }

    public function generateText(AiRequest $request): AiResponse
    {
        return $this->requestCompletion($request, false);
    }

    public function generateJson(AiRequest $request): AiResponse
    {
        return $this->requestCompletion($request, true);
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

        if ($expectJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = HttpClient::postJson(
                $this->apiBase . '/chat/completions',
                $payload,
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                $request->getTimeoutSeconds()
            );
        } catch (\Throwable $exception) {
            throw new AiException($this->getName(), $exception->getMessage(), null, 'network_error', null, $exception);
        }

        $body = $response['body'];
        if ($response['status'] >= 400) {
            $message = $body['error']['message'] ?? 'OpenAI API request failed.';
            $code = $body['error']['code'] ?? 'api_error';
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

        throw new AiException($this->getName(), 'Missing assistant content in OpenAI API response.', null, 'invalid_response');
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

        throw new AiException($this->getName(), 'OpenAI response did not contain valid JSON.', null, 'invalid_json');
    }
}
