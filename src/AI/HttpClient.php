<?php

namespace BinktermPHP\AI;

/**
 * Small JSON HTTP helper shared by AI providers.
 */
class HttpClient
{
    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: array<string, mixed>, raw: string}
     */
    public static function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode AI request JSON.');
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 20,
            ]);

            $raw = curl_exec($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                throw new \RuntimeException('Network error: ' . $curlError);
            }

            return self::parseResponse((string)$raw, $httpStatus);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $json,
                'timeout' => $timeoutSeconds,
            ],
        ]);

        $raw = file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new \RuntimeException('Network error while calling AI API.');
        }

        $httpStatus = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                    $httpStatus = (int)$matches[1];
                    break;
                }
            }
        }

        return self::parseResponse((string)$raw, $httpStatus);
    }

    /**
     * @return array{status: int, body: array<string, mixed>, raw: string}
     */
    private static function parseResponse(string $raw, int $httpStatus): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('AI API response was not valid JSON.');
        }

        return [
            'status' => $httpStatus,
            'body' => $decoded,
            'raw' => $raw,
        ];
    }
}
