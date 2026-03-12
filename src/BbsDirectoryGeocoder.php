<?php

namespace BinktermPHP;

class BbsDirectoryGeocoder
{
    private const DEFAULT_ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const REQUEST_INTERVAL_US = 1000000;
    private const TIMEOUT_SECONDS = 10;

    private static float $lastRequestAt = 0.0;

    public function isEnabled(): bool
    {
        return strtolower((string)Config::env('BBS_DIRECTORY_GEOCODING_ENABLED', 'true')) !== 'false';
    }

    public function geocodeLocation(?string $location): ?array
    {
        $location = trim((string)$location);
        if ($location === '' || !$this->isEnabled()) {
            return null;
        }

        $query = [
            'q' => $location,
            'format' => 'jsonv2',
            'limit' => 1,
        ];

        $email = trim((string)Config::env('BBS_DIRECTORY_GEOCODER_EMAIL', ''));
        if ($email !== '') {
            $query['email'] = $email;
        }

        $endpoint = (string)Config::env('BBS_DIRECTORY_GEOCODER_URL', self::DEFAULT_ENDPOINT);
        $url = rtrim($endpoint, '?') . '?' . http_build_query($query);

        $response = $this->httpGetJson($url);
        if (!is_array($response) || empty($response[0]['lat']) || empty($response[0]['lon'])) {
            return null;
        }

        return [
            'latitude' => round((float)$response[0]['lat'], 6),
            'longitude' => round((float)$response[0]['lon'], 6),
        ];
    }

    private function httpGetJson(string $url): ?array
    {
        $this->throttle();

        $userAgent = trim((string)Config::env('BBS_DIRECTORY_GEOCODER_USER_AGENT', ''));
        if ($userAgent === '') {
            $siteUrl = Config::getSiteUrl();
            $userAgent = 'BinktermPHP BBS Directory Geocoder (+'.$siteUrl.')';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
                CURLOPT_USERAGENT => $userAgent,
            ]);

            $body = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $httpCode < 200 || $httpCode >= 300) {
                return null;
            }

            $decoded = json_decode((string)$body, true);
            return is_array($decoded) ? $decoded : null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'User-Agent: ' . $userAgent,
                ]),
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function throttle(): void
    {
        $elapsedUs = (int)((microtime(true) - self::$lastRequestAt) * 1000000);
        if ($elapsedUs > 0 && $elapsedUs < self::REQUEST_INTERVAL_US) {
            usleep(self::REQUEST_INTERVAL_US - $elapsedUs);
        }
        self::$lastRequestAt = microtime(true);
    }
}
