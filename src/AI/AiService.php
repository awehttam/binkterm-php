<?php

namespace BinktermPHP\AI;

use BinktermPHP\Config;
use BinktermPHP\AI\PowerCostAwareInterface;
use BinktermPHP\AI\Providers\AnthropicProvider;
use BinktermPHP\AI\Providers\OllamaProvider;
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

    /**
     * Static metadata for all known AI providers.
     * Single source of truth for provider display names, default models, and env hints.
     *
     * @return array<string, array{display_name: string, default_model: string, tools_default: bool, is_self_hosted: bool, env_var_hint: string}>
     */
    public static function getKnownProviderMeta(): array
    {
        return [
            'openai' => [
                'display_name' => 'OpenAI',
                'default_model' => 'gpt-4o-mini',
                'tools_default' => true,
                'is_self_hosted' => false,
                'env_var_hint' => 'OPENAI_API_KEY',
            ],
            'anthropic' => [
                'display_name' => 'Anthropic',
                'default_model' => 'claude-sonnet-4-6',
                'tools_default' => true,
                'is_self_hosted' => false,
                'env_var_hint' => 'ANTHROPIC_API_KEY',
            ],
            'ollama' => [
                'display_name' => 'Ollama',
                'default_model' => 'llama3.2',
                'tools_default' => false,
                'is_self_hosted' => true,
                'env_var_hint' => 'OLLAMA_API_BASE',
            ],
        ];
    }

    /**
     * Returns configuration status for all known providers.
     * Used by the admin AI settings page to display the current provider status.
     *
     * @return array<int, array{
     *   name: string,
     *   display_name: string,
     *   configured: bool,
     *   default_model: string,
     *   supports_tools: bool,
     *   is_self_hosted: bool,
     *   env_var_hint: string,
     *   pricing: array{input: float, output: float, cached_input: float, cache_write: float, has_pricing: bool},
     *   power_cost: array{gpu_watts: float, per_kwh: float, cost_per_hour: float}|null,
     * }>
     */
    public function getProviderStatusList(): array
    {
        $pricing = new AiPricing();
        $result = [];

        foreach (self::getKnownProviderMeta() as $name => $info) {
            $configured = isset($this->providers[$name]);
            $provider = $this->providers[$name] ?? null;
            $defaultModel = $provider ? $provider->getDefaultModel() : $info['default_model'];
            $supportsTools = $provider ? $provider->supportsTools() : $info['tools_default'];

            $rates = $pricing->getRates($name, $defaultModel);
            $hasPricing = $rates['input'] > 0.0 || $rates['output'] > 0.0;

            $powerCost = null;
            if ($info['is_self_hosted']) {
                $gpuWatts = (float)Config::env('OLLAMA_GPU_POWER_WATTS', '0');
                $perKwh = (float)Config::env('OLLAMA_POWER_COST_PER_KWH_USD', '0');
                $costPerHour = ($gpuWatts > 0.0 && $perKwh > 0.0)
                    ? round(($gpuWatts / 1000.0) * $perKwh, 6)
                    : 0.0;
                $powerCost = [
                    'gpu_watts' => $gpuWatts,
                    'per_kwh' => $perKwh,
                    'cost_per_hour' => $costPerHour,
                ];
            }

            $result[] = [
                'name' => $name,
                'display_name' => $info['display_name'],
                'configured' => $configured,
                'default_model' => $defaultModel,
                'supports_tools' => $supportsTools,
                'is_self_hosted' => $info['is_self_hosted'],
                'env_var_hint' => $info['env_var_hint'],
                'pricing' => [
                    'input' => $rates['input'],
                    'output' => $rates['output'],
                    'cached_input' => $rates['cached_input'],
                    'cache_write' => $rates['cache_write'],
                    'has_pricing' => $hasPricing,
                ],
                'power_cost' => $powerCost,
            ];
        }

        return $result;
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

        $service->addProvider(new OllamaProvider(
            (string)Config::env('OLLAMA_API_BASE', ''),
            (string)Config::env('OLLAMA_DEFAULT_MODEL', 'llama3.2'),
            Config::env('OLLAMA_SUPPORTS_TOOLS', 'false') === 'true',
            $pricing,
            (float)Config::env('OLLAMA_POWER_COST_PER_KWH_USD', '0'),
            (float)Config::env('OLLAMA_GPU_POWER_WATTS', '0'),
            (string)Config::env('OLLAMA_API_KEY', '')
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

            $durationMs = $this->calculateDurationMs($startedAt);

            if ($provider instanceof PowerCostAwareInterface) {
                $powerCost = $provider->computePowerCost($durationMs);
                if ($powerCost > 0.0) {
                    $response = $response->withUsage(
                        $response->getUsage()->withEstimatedCostUsd(
                            $response->getUsage()->getEstimatedCostUsd() + $powerCost
                        )
                    );
                }
            }

            $this->usageRecorder->recordSuccess(
                $resolvedRequest,
                $operation,
                $response,
                $durationMs
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
