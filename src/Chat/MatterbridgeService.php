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
     * Fetch and drain the inbound message queue from Matterbridge.
     *
     * Returns an array of message objects (each is an associative array).
     * Only messages that did NOT originate from the API side are returned —
     * this prevents BinktermPHP's own outbound messages from echoing back.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pollMessages(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $url = $this->config->getBaseUrl() . '/api/messages';
        $headers = ['Accept: application/json'];
        $token = $this->config->getToken();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        [$status, $rawBody] = $this->getJson($url, $headers);

        if ($status === 0 || $status >= 400) {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return [];
        }

        $filtered = [];
        foreach ($decoded as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $protocol = strtolower((string)($msg['protocol'] ?? ''));
            if ($protocol === 'api') {
                continue;
            }
            $filtered[] = $msg;
        }

        return $filtered;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function getJson(string $url, array $headers): array
    {
        $timeout = $this->config->getTimeoutSeconds();
        $skipTlsVerify = $this->config->shouldSkipTlsVerify();

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
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
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
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
            throw new \RuntimeException('Matterbridge network error while polling messages');
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
