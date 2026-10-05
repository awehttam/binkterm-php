<?php

declare(strict_types=1);

use BinktermPHP\BbsDirectoryGeocoder;
use BinktermPHP\Config;
use PHPUnit\Framework\TestCase;

/**
 * Focused coverage for BbsDirectoryGeocoder's SUCCESS / NO_RESULT /
 * PROVIDER_ERROR cache semantics. Never calls real
 * Nominatim: a test-only subclass overrides the protected httpGetJson()
 * seam to return a scripted response. Uses the dedicated, disposable
 * binktermphp_test database via constructor
 * injection -- never production.
 */
final class BbsDirectoryGeocoderTest extends TestCase
{
    private const TEST_DATABASE_NAME = 'binktermphp_test';

    private static ?\PDO $testPdo = null;

    protected function setUp(): void
    {
        self::db()->exec("DELETE FROM geocode_cache WHERE normalized_location LIKE 'GeocoderTest%'");
    }

    public function testSuccessResponseCachesCoordinates(): void
    {
        $geocoder = new class(self::db(), ['lat' => '39.7', 'lon' => '-104.9']) extends BbsDirectoryGeocoder {
            public function __construct(\PDO $db, private array $result) { parent::__construct($db); }
            protected function httpGetJson(string $url): ?array { return [$this->result]; }
        };

        $location = 'GeocoderTest Success ' . uniqid();
        $result = $geocoder->geocodeLocation($location);

        $this->assertNotNull($result);
        $this->assertSame(39.7, $result['latitude']);
        $this->assertSame(-104.9, $result['longitude']);

        $row = $this->fetchCacheRow($location);
        $this->assertSame('success', $row['status']);
        $this->assertNotNull($row['latitude']);
    }

    public function testEmptyValidResponseCachesNoResult(): void
    {
        $geocoder = new class(self::db()) extends BbsDirectoryGeocoder {
            protected function httpGetJson(string $url): ?array { return []; }
        };

        $location = 'GeocoderTest NoResult ' . uniqid();
        $result = $geocoder->geocodeLocation($location);

        $this->assertNull($result);
        $row = $this->fetchCacheRow($location);
        $this->assertSame('no_result', $row['status']);
        $this->assertNull($row['latitude']);
    }

    public function testProviderFailureIsNotCached(): void
    {
        $geocoder = new class(self::db()) extends BbsDirectoryGeocoder {
            protected function httpGetJson(string $url): ?array { return null; } // simulated HTTP/network failure
        };

        $location = 'GeocoderTest ProviderError ' . uniqid();
        $result = $geocoder->geocodeLocation($location);

        $this->assertNull($result);
        $this->assertNull($this->fetchCacheRow($location), 'a provider failure must not create a cache row');
    }

    public function testMalformedJsonIsTreatedAsProviderErrorNotCached(): void
    {
        // httpGetJson() itself returns null for non-array/malformed JSON
        // (see json_decode() handling); this proves the caller (geocodeLocation)
        // handles that null exactly like any other provider error.
        $geocoder = new class(self::db()) extends BbsDirectoryGeocoder {
            protected function httpGetJson(string $url): ?array { return null; }
        };

        $location = 'GeocoderTest MalformedJson ' . uniqid();
        $geocoder->geocodeLocation($location);

        $this->assertNull($this->fetchCacheRow($location));
    }

    public function testExistingSuccessCacheReturnedWithoutOutboundRequest(): void
    {
        $location = 'GeocoderTest CachedSuccess ' . uniqid();
        $this->seedCacheRow($location, 40.0, -105.0, 'success');

        $geocoder = new class(self::db()) extends BbsDirectoryGeocoder {
            protected function httpGetJson(string $url): ?array
            {
                throw new \RuntimeException('outbound request attempted on a cache hit');
            }
        };

        $result = $geocoder->geocodeLocation($location);
        $this->assertSame(['latitude' => 40.0, 'longitude' => -105.0], $result);
    }

    public function testExistingNoResultCacheReturnsNullWithoutOutboundRequest(): void
    {
        $location = 'GeocoderTest CachedNoResult ' . uniqid();
        $this->seedCacheRow($location, null, null, 'no_result');

        $geocoder = new class(self::db()) extends BbsDirectoryGeocoder {
            protected function httpGetJson(string $url): ?array
            {
                throw new \RuntimeException('outbound request attempted on a cache hit');
            }
        };

        // A no_result cache hit returns {latitude: null, longitude: null}
        // (pre-existing, unchanged behavior of getCachedResult()), not a
        // bare null return value -- geocodeLocation() only returns bare
        // null itself for a fresh NO_RESULT/PROVIDER_ERROR, not a cache hit.
        $this->assertSame(['latitude' => null, 'longitude' => null], $geocoder->geocodeLocation($location));
    }

    public function testProviderFailureDoesNotOverwriteExistingSuccess(): void
    {
        $location = 'GeocoderTest ProtectSuccess ' . uniqid();
        $this->seedCacheRow($location, 41.0, -106.0, 'success');

        // Even though this location is already cached, force a fresh
        // instance whose httpGetJson would fail -- geocodeLocation() must
        // never reach it because the cache hit short-circuits first, and
        // even if it somehow did, a null response must never overwrite.
        $geocoder = new class(self::db()) extends BbsDirectoryGeocoder {
            protected function httpGetJson(string $url): ?array { return null; }
        };
        $geocoder->geocodeLocation($location);

        $row = $this->fetchCacheRow($location);
        $this->assertSame('success', $row['status']);
        $this->assertEqualsWithDelta(41.0, (float)$row['latitude'], 0.0001);
    }

    public function testProviderFailureThenSuccessfulRetryCachesSuccess(): void
    {
        $location = 'GeocoderTest RetryAfterFailure ' . uniqid();

        $failing = new class(self::db()) extends BbsDirectoryGeocoder {
            protected function httpGetJson(string $url): ?array { return null; }
        };
        $this->assertNull($failing->geocodeLocation($location));
        $this->assertNull($this->fetchCacheRow($location), 'failed attempt must leave the location retryable (uncached)');

        $succeeding = new class(self::db()) extends BbsDirectoryGeocoder {
            protected function httpGetJson(string $url): ?array { return [['lat' => '10.0', 'lon' => '20.0']]; }
        };
        $result = $succeeding->geocodeLocation($location);

        $this->assertSame(['latitude' => 10.0, 'longitude' => 20.0], $result);
        $this->assertSame('success', $this->fetchCacheRow($location)['status']);
    }

    private function seedCacheRow(string $location, ?float $lat, ?float $lon, string $status): void
    {
        $key = hash('sha256', mb_strtolower(trim($location), 'UTF-8'));
        self::db()->prepare("
            INSERT INTO geocode_cache (location_key, normalized_location, latitude, longitude, status, cached_at)
            VALUES (:key, :loc, :lat, :lon, :status, NOW())
        ")->execute([':key' => $key, ':loc' => $location, ':lat' => $lat, ':lon' => $lon, ':status' => $status]);
    }

    private function fetchCacheRow(string $location): ?array
    {
        $key = hash('sha256', mb_strtolower(trim($location), 'UTF-8'));
        $stmt = self::db()->prepare('SELECT latitude, longitude, status FROM geocode_cache WHERE location_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function db(): \PDO
    {
        if (self::$testPdo instanceof \PDO) {
            return self::$testPdo;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Config::env('DB_HOST', 'localhost'),
            Config::env('DB_PORT', '5432'),
            self::TEST_DATABASE_NAME
        );
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('pdo_pgsql not available');
        }
        try {
            $pdo = new \PDO($dsn, Config::env('DB_USER', 'postgres'), Config::env('DB_PASS', ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            self::markTestSkipped('binktermphp_test database not reachable: ' . $e->getMessage());
        }

        $actual = (string)$pdo->query('SELECT current_database()')->fetchColumn();
        if ($actual !== self::TEST_DATABASE_NAME) {
            throw new \RuntimeException("Refusing to run mutating geocoder tests against non-test database: {$actual}");
        }

        self::$testPdo = $pdo;
        return $pdo;
    }
}
