<?php

namespace BinktermPHP;

class BbsDirectoryGeocoder
{
    private const DEFAULT_ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const REQUEST_INTERVAL_US = 1000000;
    private const TIMEOUT_SECONDS = 10;
    private const CACHE_TTL_SECONDS = 2764800; // 32 days

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

        $cacheKey = $this->buildCacheKey($location);
        $cached = $this->getCachedResult($cacheKey);
        if ($cached !== null) {
            return $cached;
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
            $this->storeCachedResult($cacheKey, $location, null);
            return null;
        }

        $result = [
            'latitude' => round((float)$response[0]['lat'], 6),
            'longitude' => round((float)$response[0]['lon'], 6),
        ];

        $this->storeCachedResult($cacheKey, $location, $result);

        return $result;
    }

    private function buildCacheKey(string $location): string
    {
        return hash('sha256', mb_strtolower(trim($location), 'UTF-8'));
    }

    private function getCachedResult(string $cacheKey): ?array
    {
        try {
            $db = Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                SELECT latitude, longitude, cached_at
                FROM bbs_directory_geocode_cache
                WHERE location_key = ?
                LIMIT 1
            ");
            $stmt->execute([$cacheKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            $cachedAt = strtotime((string)($row['cached_at'] ?? ''));
            if ($cachedAt === false || (time() - $cachedAt) > self::CACHE_TTL_SECONDS) {
                return null;
            }

            if ($row['latitude'] === null || $row['longitude'] === null) {
                return ['latitude' => null, 'longitude' => null];
            }

            return [
                'latitude' => round((float)$row['latitude'], 6),
                'longitude' => round((float)$row['longitude'], 6),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function storeCachedResult(string $cacheKey, string $location, ?array $result): void
    {
        try {
            $db = Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                INSERT INTO bbs_directory_geocode_cache (location_key, normalized_location, latitude, longitude, cached_at)
                VALUES (:location_key, :normalized_location, :latitude, :longitude, NOW())
                ON CONFLICT (location_key) DO UPDATE
                SET normalized_location = EXCLUDED.normalized_location,
                    latitude = EXCLUDED.latitude,
                    longitude = EXCLUDED.longitude,
                    cached_at = NOW()
            ");
            $stmt->execute([
                ':location_key' => $cacheKey,
                ':normalized_location' => $location,
                ':latitude' => $result['latitude'] ?? null,
                ':longitude' => $result['longitude'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Ignore cache failures and allow live geocoding to continue.
        }
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
