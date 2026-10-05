<?php

namespace BinktermPHP;

class BbsDirectoryGeocoder
{
    private const DEFAULT_ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const REQUEST_INTERVAL_US = 1000000;
    private const TIMEOUT_SECONDS = 10;
    private static float $lastRequestAt = 0.0;
    private ?\PDO $db;

    /**
     * @param \PDO|null $db Optional injected connection (tests use this to
     *   point at an isolated database instead of production).
     *   Defaults to the production singleton, unchanged from prior behavior.
     */
    public function __construct(?\PDO $db = null)
    {
        $this->db = $db;
    }

    private function getDb(): \PDO
    {
        return $this->db ?? Database::getInstance()->getPdo();
    }

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
        if ($response === null) {
            // PROVIDER_ERROR (network/timeout/non-2xx/malformed JSON --
            // see httpGetJson()). Deliberately NOT cached: this must stay
            // retryable on the next run rather than becoming a permanent
            // false "no result". Do not overwrite any existing cache row
            // (there is none to overwrite here -- we only reach this branch
            // after a cache miss above).
            return null;
        }

        if (empty($response[0]['lat']) || empty($response[0]['lon'])) {
            // NO_RESULT: the provider answered successfully (valid JSON) but
            // found no match. This is a genuine, cacheable answer.
            $this->storeCachedResult($cacheKey, $location, null, 'no_result');
            return null;
        }

        $result = [
            'latitude' => round((float)$response[0]['lat'], 6),
            'longitude' => round((float)$response[0]['lon'], 6),
        ];

        $this->storeCachedResult($cacheKey, $location, $result, 'success');

        return $result;
    }

    private function buildCacheKey(string $location): string
    {
        return hash('sha256', mb_strtolower(trim($location), 'UTF-8'));
    }

    /**
     * True if this location is already permanently cached as NO_RESULT --
     * i.e. a prior geocode attempt reached the provider and got a genuine
     * "no match" answer. Uses the exact same cache key derivation as
     * geocodeLocation() itself, so it reflects the real cache, not an
     * approximation. Callers use this to exclude known-unresolvable rows
     * from consuming a bounded batch slot, without making any outbound
     * request and without altering the cache.
     */
    public function isKnownNoResult(?string $location): bool
    {
        $location = trim((string)$location);
        if ($location === '') {
            return false;
        }

        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                SELECT status
                FROM geocode_cache
                WHERE location_key = ?
                LIMIT 1
            ");
            $stmt->execute([$this->buildCacheKey($location)]);
            return $stmt->fetchColumn() === 'no_result';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getCachedResult(string $cacheKey): ?array
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                SELECT latitude, longitude
                FROM geocode_cache
                WHERE location_key = ?
                LIMIT 1
            ");
            $stmt->execute([$cacheKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
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

    /**
     * Persists a SUCCESS or NO_RESULT outcome. PROVIDER_ERROR is never
     * passed here -- geocodeLocation() returns early for that case, so a
     * transient provider failure can never overwrite (or create) a cache
     * row, and an existing success/no_result entry is never at risk of
     * being clobbered by a later failed refresh attempt.
     */
    private function storeCachedResult(string $cacheKey, string $location, ?array $result, string $status): void
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                INSERT INTO geocode_cache (location_key, normalized_location, latitude, longitude, status, cached_at)
                VALUES (:location_key, :normalized_location, :latitude, :longitude, :status, NOW())
                ON CONFLICT (location_key) DO UPDATE
                SET normalized_location = EXCLUDED.normalized_location,
                    latitude = EXCLUDED.latitude,
                    longitude = EXCLUDED.longitude,
                    status = EXCLUDED.status,
                    cached_at = NOW()
            ");
            $stmt->execute([
                ':location_key' => $cacheKey,
                ':normalized_location' => $location,
                ':latitude' => $result['latitude'] ?? null,
                ':longitude' => $result['longitude'] ?? null,
                ':status' => $status,
            ]);
        } catch (\Throwable $e) {
            // Ignore cache failures and allow live geocoding to continue.
        }
    }

    protected function httpGetJson(string $url): ?array
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
