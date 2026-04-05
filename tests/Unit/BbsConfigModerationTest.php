<?php

/**
 * PHPUnit tests for BbsConfig::getEchomailModerationThreshold().
 * No HTTP server or database required — exercises the static config accessor.
 *
 * Run: php vendor/bin/phpunit tests/Unit/BbsConfigModerationTest.php
 */

use BinktermPHP\BbsConfig;
use PHPUnit\Framework\TestCase;

class BbsConfigModerationTest extends TestCase
{
    /** Snapshot of static config state before each test */
    private static \ReflectionProperty $configProp;
    private static \ReflectionProperty $loadedProp;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(BbsConfig::class);
        self::$configProp = $ref->getProperty('config');
        self::$configProp->setAccessible(true);
        self::$loadedProp = $ref->getProperty('loaded');
        self::$loadedProp->setAccessible(true);
    }

    /** Inject an arbitrary config array into the static class, bypassing file I/O */
    private function injectConfig(array $config): void
    {
        self::$configProp->setValue(null, $config);
        self::$loadedProp->setValue(null, true);
    }

    protected function tearDown(): void
    {
        // Restore state so the next test starts clean
        BbsConfig::reload();
    }

    // ── Default / disabled ─────────────────────────────────────────────────────

    public function testReturnsZeroWhenKeyAbsent(): void
    {
        $this->injectConfig([]);
        $this->assertSame(0, BbsConfig::getEchomailModerationThreshold());
    }

    public function testReturnsZeroWhenValueIsZero(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => 0]);
        $this->assertSame(0, BbsConfig::getEchomailModerationThreshold());
    }

    public function testReturnsZeroWhenValueIsNull(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => null]);
        $this->assertSame(0, BbsConfig::getEchomailModerationThreshold());
    }

    // ── Positive threshold ─────────────────────────────────────────────────────

    public function testReturnsFiveWhenConfiguredAsFive(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => 5]);
        $this->assertSame(5, BbsConfig::getEchomailModerationThreshold());
    }

    public function testReturnsOneWhenConfiguredAsOne(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => 1]);
        $this->assertSame(1, BbsConfig::getEchomailModerationThreshold());
    }

    public function testReturnsTenWhenConfiguredAsTen(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => 10]);
        $this->assertSame(10, BbsConfig::getEchomailModerationThreshold());
    }

    // ── String coercion ────────────────────────────────────────────────────────

    public function testCoercesStringNumberToInt(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => '7']);
        $this->assertSame(7, BbsConfig::getEchomailModerationThreshold());
    }

    public function testCoercesStringZeroToInt(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => '0']);
        $this->assertSame(0, BbsConfig::getEchomailModerationThreshold());
    }

    // ── Negative value clamped to zero ─────────────────────────────────────────

    public function testNegativeValueIsClampedToZero(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => -3]);
        $this->assertSame(0, BbsConfig::getEchomailModerationThreshold());
    }

    public function testNegativeStringValueIsClampedToZero(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => '-5']);
        $this->assertSame(0, BbsConfig::getEchomailModerationThreshold());
    }

    // ── Example config file ────────────────────────────────────────────────────

    public function testExampleConfigFileContainsEchomailModerationThreshold(): void
    {
        $examplePath = dirname(__DIR__, 2) . '/config/bbs.json.example';
        $this->assertFileExists($examplePath, 'bbs.json.example must exist');

        $data = json_decode(file_get_contents($examplePath), true);
        $this->assertIsArray($data, 'bbs.json.example must be valid JSON');
        $this->assertArrayHasKey(
            'echomail_moderation_threshold',
            $data,
            'bbs.json.example must include echomail_moderation_threshold'
        );
        $this->assertSame(
            0,
            $data['echomail_moderation_threshold'],
            'echomail_moderation_threshold default must be 0 (disabled)'
        );
    }

    // ── Return type ────────────────────────────────────────────────────────────

    public function testReturnTypeIsAlwaysInt(): void
    {
        $this->injectConfig(['echomail_moderation_threshold' => 3.9]);
        $result = BbsConfig::getEchomailModerationThreshold();
        $this->assertIsInt($result);
    }
}