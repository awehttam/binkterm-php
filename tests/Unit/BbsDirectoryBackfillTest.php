<?php

declare(strict_types=1);

use BinktermPHP\BbsDirectory;
use BinktermPHP\BbsDirectoryGeocoder;
use BinktermPHP\Config;
use PHPUnit\Framework\TestCase;

/**
 * Focused coverage for BbsDirectory::backfillMissingCoordinates()'s
 * anti-starvation exclusion of rows whose location is already cached as a
 * permanent geocode_cache NO_RESULT. Never calls real Nominatim: a
 * test-only BbsDirectoryGeocoder subclass overrides the protected
 * httpGetJson() seam. Uses the dedicated, disposable binktermphp_test
 * database via constructor injection -- never
 * production.
 */
final class BbsDirectoryBackfillTest extends TestCase
{
    private const TEST_DATABASE_NAME = 'binktermphp_test';

    private static ?\PDO $testPdo = null;
    private array $insertedIds = [];

    protected function tearDown(): void
    {
        if (!self::$testPdo instanceof \PDO) {
            return; // never connected (test skipped) - nothing to clean up
        }
        if ($this->insertedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->insertedIds), '?'));
            self::db()->prepare("DELETE FROM bbs_directory WHERE id IN ($placeholders)")
                ->execute($this->insertedIds);
            $this->insertedIds = [];
        }
        self::db()->exec("DELETE FROM geocode_cache WHERE normalized_location LIKE 'ZZZ-Backfill-Test-%'");
    }

    public function testRowWithNoCacheEntryIsEligibleAndGetsGeocoded(): void
    {
        $location = $this->uniqueLocation('Fresh');
        $id = $this->insertRow($location);

        $geocoder = $this->scriptedGeocoder(['lat' => '10.0', 'lon' => '20.0']);
        $directory = new BbsDirectory(self::db(), $geocoder);

        $result = $directory->backfillMissingCoordinates(10, false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['excluded_known_no_result']);
        $this->assertRowHasCoordinates($id, 10.0, 20.0);
    }

    public function testRowWithCachedSuccessIsNotExcludedAndUsesExistingSemantics(): void
    {
        $location = $this->uniqueLocation('CachedSuccess');
        $id = $this->insertRow($location);
        $this->seedCacheRow($location, 41.0, -106.0, 'success');

        // If backfill ever reached the network for this row, the cache
        // lookup contract would be broken -- throw to prove it never does.
        $geocoder = $this->scriptedGeocoder(null, true);
        $directory = new BbsDirectory(self::db(), $geocoder);

        $result = $directory->backfillMissingCoordinates(10, false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['excluded_known_no_result'], 'a cached SUCCESS row must not be excluded');
        $this->assertRowHasCoordinates($id, 41.0, -106.0);
    }

    public function testRowWithCachedNoResultIsExcludedFromBoundedSelection(): void
    {
        $location = $this->uniqueLocation('CachedNoResult');
        $id = $this->insertRow($location);
        $this->seedCacheRow($location, null, null, 'no_result');

        // Must never even be attempted -- no outbound call, no processing.
        $geocoder = $this->scriptedGeocoder(null, true);
        $directory = new BbsDirectory(self::db(), $geocoder);

        $result = $directory->backfillMissingCoordinates(10, false);

        $this->assertSame(0, $result['selected'], 'the only candidate row is a known no_result and must not be selected');
        $this->assertSame(1, $result['excluded_known_no_result']);
        $this->assertRowHasNullCoordinates($id);
    }

    public function testLowIdNoResultRowsCannotStarveANewerGeocodableRowUnderLimit(): void
    {
        // Five low-id rows, each permanently cached as no_result.
        $noResultIds = [];
        for ($i = 0; $i < 5; $i++) {
            $location = $this->uniqueLocation('Starve' . $i);
            $noResultIds[] = $this->insertRow($location);
            $this->seedCacheRow($location, null, null, 'no_result');
        }

        // One newer (higher-id) row with no cache entry yet -- a genuinely
        // fresh, geocodable row.
        $freshLocation = $this->uniqueLocation('StarveFresh');
        $freshId = $this->insertRow($freshLocation);

        $geocoder = $this->scriptedGeocoder(['lat' => '5.0', 'lon' => '6.0']);
        $directory = new BbsDirectory(self::db(), $geocoder);

        // A tight limit that would previously (id ASC + LIMIT only) have
        // been entirely consumed by the 5 permanent no_result rows,
        // reaching updated=0 and never touching the fresh row.
        $result = $directory->backfillMissingCoordinates(2, false);

        $this->assertSame(1, $result['updated'], 'the fresh row must still be reached despite 5 lower-id permanent no_result rows ahead of it');
        $this->assertGreaterThanOrEqual(2, $result['excluded_known_no_result']);
        $this->assertRowHasCoordinates($freshId, 5.0, 6.0);

        foreach ($noResultIds as $id) {
            $this->assertRowHasNullCoordinates($id, 'permanent no_result rows must remain unmodified, not deleted, not given invented coordinates');
        }
    }

    public function testDryRunSemanticsUnchangedForFreshRow(): void
    {
        $location = $this->uniqueLocation('DryRun');
        $id = $this->insertRow($location);

        $geocoder = $this->scriptedGeocoder(['lat' => '1.0', 'lon' => '2.0']);
        $directory = new BbsDirectory(self::db(), $geocoder);

        $result = $directory->backfillMissingCoordinates(10, true);

        $this->assertSame(1, $result['updated'], 'dry-run still reports what WOULD be updated');
        $this->assertRowHasNullCoordinates($id, 'dry-run must never write to the database');
    }

    private function scriptedGeocoder(?array $successResult, bool $throwIfCalled = false): BbsDirectoryGeocoder
    {
        $db = self::db();
        return new class($db, $successResult, $throwIfCalled) extends BbsDirectoryGeocoder {
            public function __construct(\PDO $db, private ?array $result, private bool $throwIfCalled)
            {
                parent::__construct($db);
            }
            protected function httpGetJson(string $url): ?array
            {
                if ($this->throwIfCalled) {
                    throw new \RuntimeException('outbound geocoding request attempted for an excluded/cached row');
                }
                return $this->result !== null ? [$this->result] : [];
            }
        };
    }

    private function insertRow(string $location): int
    {
        $stmt = self::db()->prepare("
            INSERT INTO bbs_directory (name, location, status)
            VALUES (:name, :location, 'active')
            RETURNING id
        ");
        $stmt->execute([':name' => 'ZZZ Backfill Test BBS ' . $location, ':location' => $location]);
        $id = (int)$stmt->fetchColumn();
        $this->insertedIds[] = $id;
        return $id;
    }

    private function seedCacheRow(string $location, ?float $lat, ?float $lon, string $status): void
    {
        $key = hash('sha256', mb_strtolower(trim($location), 'UTF-8'));
        self::db()->prepare("
            INSERT INTO geocode_cache (location_key, normalized_location, latitude, longitude, status, cached_at)
            VALUES (:key, :loc, :lat, :lon, :status, NOW())
        ")->execute([':key' => $key, ':loc' => $location, ':lat' => $lat, ':lon' => $lon, ':status' => $status]);
    }

    private function assertRowHasCoordinates(int $id, float $lat, float $lon): void
    {
        $stmt = self::db()->prepare('SELECT latitude, longitude FROM bbs_directory WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['latitude']);
        $this->assertEqualsWithDelta($lat, (float)$row['latitude'], 0.0001);
        $this->assertEqualsWithDelta($lon, (float)$row['longitude'], 0.0001);
    }

    private function assertRowHasNullCoordinates(int $id, string $message = ''): void
    {
        $stmt = self::db()->prepare('SELECT latitude, longitude FROM bbs_directory WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['latitude'], $message);
        $this->assertNull($row['longitude'], $message);
    }

    private function uniqueLocation(string $tag): string
    {
        // No commas -- keeps BbsDirectory::normalizeLocation() a no-op
        // (identity on a single segment) so the cache key we seed here
        // matches exactly what the code under test will compute.
        return 'ZZZ-Backfill-Test-' . $tag . '-' . uniqid();
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
            throw new \RuntimeException("Refusing to run mutating backfill tests against non-test database: {$actual}");
        }

        self::$testPdo = $pdo;
        return $pdo;
    }
}
