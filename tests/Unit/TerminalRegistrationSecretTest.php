<?php

declare(strict_types=1);

/**
 * Regression tests for the TERMINAL_REGISTRATION_SECRET trust boundary.
 *
 * Config::terminalRegistrationSecret() is the single canonical interpretation
 * of this secret: unset, empty, and the publicly-known shipped default
 * (`Chang3Me`) are all treated as "no site-specific secret configured", so
 * every consumer falls back to its existing safe behavior instead of
 * trusting a header any caller who knows the public default could also
 * present. Only a genuinely custom value is honored.
 *
 * Covers:
 *   1. The canonical accessor's four cases directly.
 *   2. Auth::resolveClientIp() end-to-end: a default-configured secret must
 *      not let a caller assert their own session IP; a custom secret with a
 *      matching token must retain the existing trusted-IP behavior.
 *   3. Source-level guardrails proving /api/register uses the canonical
 *      accessor rather than a raw Config::env() call with a literal default,
 *      and that new installs get a site-specific secret (Docker entrypoint)
 *      or a setup warning.
 *
 * Test isolation: Config::env() lazily loads the real .env file into $_ENV
 * exactly once per process, guarded by Config's private static $loaded flag.
 * This test must never trigger that real load at all — not even once, and
 * regardless of what other tests in the same process have already done —
 * because the host's real TERMINAL_REGISTRATION_SECRET (if configured) must
 * never be read, snapshotted, compared, or exposed here. So instead of ever
 * calling Config::env()/loadConfig(), each test stubs Config's $loaded flag
 * to true via reflection first, which makes loadConfig() a no-op and guarantees the real
 * .env file is never touched by this file's execution — only the synthetic
 * $_ENV values each test sets itself are ever read back.
 *
 * For the same reason, $_ENV['TERMINAL_REGISTRATION_SECRET'] is never
 * snapshotted for later restoration (unlike the non-secret $_SERVER keys
 * below) — it is simply unset at the end of every test, never round-tripped.
 *
 * No real secret value is used, printed, or logged anywhere in this file —
 * only the publicly-documented default string and made-up test values.
 */

use BinktermPHP\Auth;
use BinktermPHP\Config;
use PHPUnit\Framework\TestCase;

final class TerminalRegistrationSecretTest extends TestCase
{
    private const KNOWN_DEFAULT = 'Chang3Me';
    private const CUSTOM_SECRET = 'unit-test-only-not-a-real-secret-value';

    /** @var array<string,mixed> Snapshot of touched (non-secret) $_SERVER keys. */
    private array $serverSnapshot = [];

    private const TOUCHED_SERVER_KEYS = [
        'REMOTE_ADDR',
        'HTTP_X_BINKTERM_CLIENT_IP',
        'HTTP_X_BINKTERM_CLIENT_TOKEN',
        'HTTP_X_BINKTERM_REGISTRATION_TOKEN',
    ];

    /**
     * Stub Config::$loaded to true via reflection so Config::env() (and thus
     * Config::terminalRegistrationSecret()) never attempts to read the real
     * .env file — only the $_ENV value this test just set synthetically.
     */
    private static function preventRealEnvLoad(): void
    {
        $loaded = new \ReflectionProperty(Config::class, 'loaded');
        $loaded->setAccessible(true);
        $loaded->setValue(null, true);
    }

    protected function setUp(): void
    {
        self::preventRealEnvLoad();
        unset($_ENV['TERMINAL_REGISTRATION_SECRET']);

        foreach (self::TOUCHED_SERVER_KEYS as $key) {
            $this->serverSnapshot[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV['TERMINAL_REGISTRATION_SECRET']);

        foreach (self::TOUCHED_SERVER_KEYS as $key) {
            if ($this->serverSnapshot[$key] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $this->serverSnapshot[$key];
            }
        }

        // Restore Config's real loader for any other test in this process
        // that legitimately needs it, so this file's isolation stub doesn't
        // leak into unrelated tests.
        $loaded = new \ReflectionProperty(Config::class, 'loaded');
        $loaded->setAccessible(true);
        $loaded->setValue(null, false);
    }

    // ---- 1. Config::terminalRegistrationSecret() ---------------------------

    public function testUnsetReturnsEmpty(): void
    {
        unset($_ENV['TERMINAL_REGISTRATION_SECRET']);
        self::assertSame('', Config::terminalRegistrationSecret());
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $_ENV['TERMINAL_REGISTRATION_SECRET'] = '';
        self::assertSame('', Config::terminalRegistrationSecret());
    }

    public function testKnownPublishedDefaultReturnsEmpty(): void
    {
        $_ENV['TERMINAL_REGISTRATION_SECRET'] = self::KNOWN_DEFAULT;
        self::assertSame('', Config::terminalRegistrationSecret());
    }

    public function testCustomValueReturnsUnchanged(): void
    {
        $_ENV['TERMINAL_REGISTRATION_SECRET'] = self::CUSTOM_SECRET;
        self::assertSame(self::CUSTOM_SECRET, Config::terminalRegistrationSecret());
    }

    // ---- 2. Auth::resolveClientIp() ----------------------------------------

    public function testDefaultSecretDoesNotTrustClaimedClientIp(): void
    {
        $_ENV['TERMINAL_REGISTRATION_SECRET'] = self::KNOWN_DEFAULT;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_BINKTERM_CLIENT_IP'] = '198.51.100.42';
        // A caller who only knows the public default can trivially present it
        // as the token too -- exactly the case that must not be trusted.
        $_SERVER['HTTP_X_BINKTERM_CLIENT_TOKEN'] = self::KNOWN_DEFAULT;

        self::assertSame('203.0.113.9', Auth::resolveClientIp(), 'must fall back to REMOTE_ADDR when only the published default is configured');
    }

    public function testUnsetSecretDoesNotTrustClaimedClientIp(): void
    {
        unset($_ENV['TERMINAL_REGISTRATION_SECRET']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_BINKTERM_CLIENT_IP'] = '198.51.100.42';
        $_SERVER['HTTP_X_BINKTERM_CLIENT_TOKEN'] = 'anything';

        self::assertSame('203.0.113.9', Auth::resolveClientIp(), 'must fall back to REMOTE_ADDR when unset');
    }

    public function testCustomSecretWithMatchingTokenTrustsClaimedIp(): void
    {
        $_ENV['TERMINAL_REGISTRATION_SECRET'] = self::CUSTOM_SECRET;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_BINKTERM_CLIENT_IP'] = '198.51.100.42';
        $_SERVER['HTTP_X_BINKTERM_CLIENT_TOKEN'] = self::CUSTOM_SECRET;

        self::assertSame('198.51.100.42', Auth::resolveClientIp(), 'a genuinely configured custom secret with a matching token must keep working exactly as before');
    }

    public function testCustomSecretWithWrongTokenStillRejected(): void
    {
        $_ENV['TERMINAL_REGISTRATION_SECRET'] = self::CUSTOM_SECRET;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_BINKTERM_CLIENT_IP'] = '198.51.100.42';
        $_SERVER['HTTP_X_BINKTERM_CLIENT_TOKEN'] = 'not-the-configured-secret';

        self::assertSame('203.0.113.9', Auth::resolveClientIp(), 'a mismatched token must still be rejected exactly as before');
    }

    // ---- 3. Source guardrails: routes/api-routes.php wiring ---------------

    private static function apiRoutesSource(): string
    {
        $src = file_get_contents(__DIR__ . '/../../routes/api-routes.php');
        self::assertIsString($src);
        return $src;
    }

    public function testRegisterRouteUsesCanonicalAccessor(): void
    {
        $src = self::apiRoutesSource();

        self::assertStringContainsString(
            '$expectedRegistrationToken = \BinktermPHP\Config::terminalRegistrationSecret();',
            $src,
            '/api/register must derive its expected token from the canonical accessor'
        );

        // The old raw call with a literal default must not reappear.
        self::assertDoesNotMatchRegularExpression(
            "/Config::env\\(\\s*'TERMINAL_REGISTRATION_SECRET',\\s*'Chang3Me'\\s*\\)/",
            $src,
            'no consumer should read TERMINAL_REGISTRATION_SECRET via a raw Config::env() call with a literal default any more'
        );
    }

    public function testRegisterRouteLogsWhenTerminalSecretIsNotConfigured(): void
    {
        self::assertStringContainsString(
            'Terminal registration received but TERMINAL_REGISTRATION_SECRET is unset or still the published default',
            self::apiRoutesSource()
        );
    }

    public function testDockerEntrypointGeneratesAndPersistsTerminalSecret(): void
    {
        $src = file_get_contents(__DIR__ . '/../../docker/entrypoint.sh');
        self::assertIsString($src);
        self::assertStringContainsString('[ "$SRC_TERMINAL_SECRET" = "Chang3Me" ]', $src);
        self::assertStringContainsString('RESOLVED_TERMINAL_SECRET=$(openssl rand -hex 32)', $src);
        self::assertStringContainsString('echo "TERMINAL_REGISTRATION_SECRET=$RESOLVED_TERMINAL_SECRET"', $src);
    }

    public function testSetupWarnsWhenTerminalSecretIsNotConfigured(): void
    {
        $src = file_get_contents(__DIR__ . '/../../scripts/setup.php');
        self::assertIsString($src);
        self::assertStringContainsString('$this->checkTerminalRegistrationSecret();', $src);
        self::assertStringContainsString("\\BinktermPHP\\Config::terminalRegistrationSecret() !== ''", $src);
    }

    public function testNoRawLiteralDefaultRemainsAnywhereInApiRoutes(): void
    {
        $src = self::apiRoutesSource();

        self::assertStringNotContainsString(
            "'TERMINAL_REGISTRATION_SECRET', 'Chang3Me'",
            $src
        );
        self::assertStringNotContainsString(
            "'TERMINAL_REGISTRATION_SECRET',\n            'Chang3Me'",
            $src
        );
    }
}
