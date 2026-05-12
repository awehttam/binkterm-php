<?php

namespace BinktermPHP\Chat;

/**
 * Matterbridge API configuration loader/saver.
 */
class MatterbridgeConfig
{
    private static ?self $instance = null;

    /** @var array<string,mixed> */
    private array $config = [];
    private string $configPath;

    private function __construct()
    {
        $this->configPath = __DIR__ . '/../../config/matterbridge.json';
        $this->loadConfig();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function reloadConfig(): void
    {
        $this->loadConfig();
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    public function getBaseUrl(): string
    {
        return rtrim((string)($this->config['api']['base_url'] ?? 'http://127.0.0.1:4240'), '/');
    }

    public function getToken(): string
    {
        return trim((string)($this->config['api']['token'] ?? ''));
    }

    public function getTimeoutSeconds(): int
    {
        $timeout = (int)($this->config['api']['timeout_seconds'] ?? 10);
        return $timeout > 0 ? $timeout : 10;
    }

    public function shouldSkipTlsVerify(): bool
    {
        return !empty($this->config['api']['skip_tls_verify']);
    }

    public function getUsernameSuffix(): string
    {
        return (string)($this->config['defaults']['username_suffix'] ?? ' @ BinktermPHP');
    }

    /**
     * User ID used to post inbound bridge messages into local chat.
     * Returns 0 when not configured, which the daemon treats as disabled.
     */
    public function getBridgeUserId(): int
    {
        return (int)($this->config['bridge_user_id'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    public function getFullConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array<string,mixed> $config
     */
    public function setFullConfig(array $config): void
    {
        $merged = array_replace_recursive($this->getDefaultConfig(), $config);
        $this->validateConfig($merged);
        $this->config = $merged;
        $this->saveConfig();
    }

    private function loadConfig(): void
    {
        if (!file_exists($this->configPath)) {
            $this->config = $this->getDefaultConfig();
            return;
        }

        $json = file_get_contents($this->configPath);
        $decoded = json_decode($json ?: '', true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON in Matterbridge configuration file');
        }

        $this->config = array_replace_recursive($this->getDefaultConfig(), $decoded);
    }

    private function saveConfig(): void
    {
        $dir = dirname($this->configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode Matterbridge config');
        }

        file_put_contents($this->configPath, $json . PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function getDefaultConfig(): array
    {
        $examplePath = dirname($this->configPath) . '/matterbridge.json.example';
        if (file_exists($examplePath)) {
            $json = file_get_contents($examplePath);
            $decoded = json_decode($json ?: '', true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'enabled' => false,
            'bridge_user_id' => 0,
            'api' => [
                'base_url' => 'http://127.0.0.1:4240',
                'token' => '',
                'timeout_seconds' => 10,
                'skip_tls_verify' => false,
            ],
            'defaults' => [
                'username_suffix' => ' @ BinktermPHP',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config
     */
    private function validateConfig(array $config): void
    {
        $baseUrl = trim((string)($config['api']['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('Matterbridge base URL is required');
        }

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('Matterbridge base URL must be a valid URL');
        }

        $timeout = (int)($config['api']['timeout_seconds'] ?? 0);
        if ($timeout < 1 || $timeout > 120) {
            throw new \RuntimeException('Matterbridge timeout must be between 1 and 120 seconds');
        }
    }
}
