<?php

namespace BinktermPHP\AI;

use BinktermPHP\Config;
use BinktermPHP\AI\Providers\AnthropicProvider;
use BinktermPHP\AI\Providers\OpenAIProvider;

/**
 * Main AI orchestration layer used by feature-specific code.
 */
class AiService
{
    /** @var array<string, AiProviderInterface> */
    private array $providers = [];

    private UsageRecorder $usageRecorder;

    public function __construct(?UsageRecorder $usageRecorder = null)
    {
        $this->usageRecorder = $usageRecorder ?? new UsageRecorder();
    }

    public function addProvider(AiProviderInterface $provider): void
    {
        if ($provider->isConfigured()) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function generateText(AiRequest $request): AiResponse
    {
        return $this->execute('generate_text', $request);
    }

    public function generateJson(AiRequest $request): AiResponse
    {
        return $this->execute('generate_json', $request);
    }

    /**
     * Resolve the provider and model for a request without executing it.
     *
     * @return array{provider: AiProviderInterface, provider_name: string, model: string}
     */
    public function resolveRequest(AiRequest $request): array
    {
        [$providerName, $model] = $this->resolveProviderAndModel($request);

        if (!isset($this->providers[$providerName])) {
            throw new \RuntimeException("AI provider '{$providerName}' is not configured.");
        }

        return [
            'provider' => $this->providers[$providerName],
            'provider_name' => $providerName,
            'model' => $model,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getConfiguredProviders(): array
    {
        return array_values(array_keys($this->providers));
    }

    public static function create(): self
    {
        $service = new self();
        $pricing = new AiPricing();

        $service->addProvider(new OpenAIProvider(
            (string)Config::env('OPENAI_API_KEY', ''),
            (string)Config::env('OPENAI_API_BASE', 'https://api.openai.com/v1'),
            $pricing
        ));

        $service->addProvider(new AnthropicProvider(
            (string)Config::env('ANTHROPIC_API_KEY', ''),
            (string)Config::env('ANTHROPIC_API_BASE', 'https://api.anthropic.com/v1'),
            $pricing
        ));

        return $service;
    }

    private function execute(string $operation, AiRequest $request): AiResponse
    {
        [$providerName, $model] = $this->resolveProviderAndModel($request);

        if (!isset($this->providers[$providerName])) {
            throw new \RuntimeException("AI provider '{$providerName}' is not configured.");
        }

        $provider = $this->providers[$providerName];
        $resolvedRequest = $request->withResolvedProviderAndModel($providerName, $model);
        $startedAt = microtime(true);

        try {
            $response = $operation === 'generate_json'
                ? $provider->generateJson($resolvedRequest)
                : $provider->generateText($resolvedRequest);

            $this->usageRecorder->recordSuccess(
                $resolvedRequest,
                $operation,
                $response,
                $this->calculateDurationMs($startedAt)
            );

            return $response;
        } catch (\Throwable $exception) {
            $this->usageRecorder->recordFailure(
                $resolvedRequest,
                $providerName,
                $model,
                $operation,
                $this->calculateDurationMs($startedAt),
                $exception
            );
            throw $exception;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveProviderAndModel(AiRequest $request): array
    {
        if (empty($this->providers)) {
            throw new \RuntimeException('No AI providers are configured.');
        }

        $featureKey = $this->normalizeFeatureKey($request->getFeature());
        $provider = $request->getProvider();
        $model = $request->getModel();

        if ($provider === null || $provider === '') {
            $provider = (string)Config::env("AI_{$featureKey}_PROVIDER", '');
        }
        if ($provider === '') {
            $provider = (string)Config::env('AI_DEFAULT_PROVIDER', '');
        }
        if ($provider === '') {
            $provider = isset($this->providers['openai'])
                ? 'openai'
                : (string)array_key_first($this->providers);
        }

        $provider = $this->normalizeProviderName($provider);
        if (!isset($this->providers[$provider])) {
            throw new \RuntimeException("AI provider '{$provider}' is not configured.");
        }

        if ($model === null || $model === '') {
            $model = (string)Config::env("AI_{$featureKey}_MODEL", '');
        }
        if ($model === '') {
            $model = (string)Config::env('AI_DEFAULT_MODEL', '');
        }
        if ($model === '') {
            $model = $this->providers[$provider]->getDefaultModel();
        }

        return [$provider, $model];
    }

    private function normalizeProviderName(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'claude') {
            return 'anthropic';
        }
        return $provider;
    }

    private function normalizeFeatureKey(string $feature): string
    {
        $feature = strtoupper(trim($feature));
        $feature = preg_replace('/[^A-Z0-9]+/', '_', $feature) ?? $feature;
        return trim($feature, '_');
    }

    private function calculateDurationMs(float $startedAt): int
    {
        return (int)round((microtime(true) - $startedAt) * 1000);
    }
}
