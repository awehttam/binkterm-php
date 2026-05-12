<?php

namespace BinktermPHP\Chat;

/**
 * Sends outbound messages to a Matterbridge API gateway.
 */
class MatterbridgeService
{
    private MatterbridgeConfig $config;
    private \BinktermPHP\Binkp\Logger $logger;

    public function __construct(?MatterbridgeConfig $config = null, ?\BinktermPHP\Binkp\Logger $logger = null)
    {
        $this->config = $config ?? MatterbridgeConfig::getInstance();
        $this->logger = $logger ?? getServerLogger();
    }

    public function isAvailable(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * @param array<string,mixed> $options
     */
    public function sendMessage(string $gateway, string $text, string $username, array $options = []): bool
    {
        $gateway = trim($gateway);
        $text = trim($text);
        $username = trim($username);

        if (!$this->isAvailable() || $gateway === '' || $text === '' || $username === '') {
            return false;
        }

        $url = $this->config->getBaseUrl() . '/api/message';
        $payload = [
            'text' => $text,
            'username' => $username,
            'gateway' => $gateway,
        ];

        if (!empty($options['avatar'])) {
            $payload['avatar'] = (string)$options['avatar'];
        }

        if (!empty($options['userid'])) {
            $payload['userid'] = (string)$options['userid'];
        }

        $headers = ['Content-Type: application/json'];
        $token = $this->config->getToken();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode Matterbridge payload');
        }

        [$status, $rawBody] = $this->postJson($url, $json, $headers);

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Matterbridge relay rejected message', [
                'gateway' => $gateway,
                'status' => $status,
                'response' => substr($rawBody, 0, 400),
            ]);
            return false;
        }

        return true;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function postJson(string $url, string $json, array $headers): array
    {
        $timeout = $this->config->getTimeoutSeconds();
        $skipTlsVerify = $this->config->shouldSkipTlsVerify();

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            ]);

            if ($skipTlsVerify) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }

            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                throw new \RuntimeException('Matterbridge network error: ' . $error);
            }

            return [$status, (string) $raw];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => !$skipTlsVerify,
                'verify_peer_name' => !$skipTlsVerify,
            ],
        ]);

        $raw = file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new \RuntimeException('Matterbridge network error while sending message');
        }

        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
        }

        return [$status, (string) $raw];
    }
}
