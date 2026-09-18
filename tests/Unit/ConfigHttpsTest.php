<?php

declare(strict_types=1);

use BinktermPHP\Config;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for HTTPS session-cookie configuration.
 */
final class ConfigHttpsTest extends TestCase
{
    private bool $hadSiteUrl;
    private ?string $siteUrl;
    private bool $hadHttps;
    private ?string $https;

    protected function setUp(): void
    {
        $this->hadSiteUrl = array_key_exists('SITE_URL', $_ENV);
        $this->siteUrl = $_ENV['SITE_URL'] ?? null;
        $this->hadHttps = array_key_exists('HTTPS', $_SERVER);
        $this->https = $_SERVER['HTTPS'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadSiteUrl) {
            $_ENV['SITE_URL'] = $this->siteUrl;
        } else {
            unset($_ENV['SITE_URL']);
        }

        if ($this->hadHttps) {
            $_SERVER['HTTPS'] = $this->https;
        } else {
            unset($_SERVER['HTTPS']);
        }
    }

    public function testHttpsSiteUrlEnablesSecureCookies(): void
    {
        $_ENV['SITE_URL'] = 'https://example.test';
        unset($_SERVER['HTTPS']);

        self::assertTrue(Config::isHttps());
        self::assertTrue(Config::getSessionCookieOptions()['secure']);
    }

    public function testHttpSiteUrlDisablesSecureCookies(): void
    {
        $_ENV['SITE_URL'] = 'http://example.test';
        unset($_SERVER['HTTPS']);

        self::assertFalse(Config::isHttps());
        self::assertFalse(Config::getSessionCookieOptions()['secure']);
    }

    public function testConfiguredSiteUrlTakesPrecedenceOverRequestHttpsSignal(): void
    {
        $_ENV['SITE_URL'] = 'http://example.test';
        $_SERVER['HTTPS'] = 'on';

        self::assertFalse(Config::isHttps());
    }

    public function testRequestHttpsSignalIsUsedWhenSiteUrlIsAbsent(): void
    {
        unset($_ENV['SITE_URL']);
        $_SERVER['HTTPS'] = 'on';

        self::assertTrue(Config::isHttps());

        $_SERVER['HTTPS'] = 'off';
        self::assertFalse(Config::isHttps());
    }

    public function testSessionCookieOptionsPreserveExistingAttributes(): void
    {
        $_ENV['SITE_URL'] = 'https://example.test';
        $before = time() + Config::SESSION_LIFETIME;
        $options = Config::getSessionCookieOptions();
        $after = time() + Config::SESSION_LIFETIME;

        self::assertSame('/', $options['path']);
        self::assertTrue($options['httponly']);
        self::assertSame('Lax', $options['samesite']);
        self::assertGreaterThanOrEqual($before, $options['expires']);
        self::assertLessThanOrEqual($after, $options['expires']);
    }

    public function testLoginUsesSharedSessionCookieOptions(): void
    {
        $source = $this->apiRoutesSource();
        $loginStart = strpos($source, "SimpleRouter::post('/auth/login'");
        $loginEnd = strpos($source, "SimpleRouter::post('/auth/logout'");

        self::assertIsInt($loginStart);
        self::assertIsInt($loginEnd);
        self::assertStringContainsString(
            $this->sessionCookieCall(),
            substr($source, $loginStart, $loginEnd - $loginStart)
        );
    }

    public function testPostRegistrationLoginUsesSharedSessionCookieOptions(): void
    {
        $source = $this->apiRoutesSource();
        $registrationStart = strpos($source, "SimpleRouter::post('/register'");
        $registrationEnd = strpos($source, "SimpleRouter::post('/account/reminder'");

        self::assertIsInt($registrationStart);
        self::assertIsInt($registrationEnd);
        self::assertStringContainsString(
            $this->sessionCookieCall(),
            substr($source, $registrationStart, $registrationEnd - $registrationStart)
        );
    }

    private function apiRoutesSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/routes/api-routes.php');
        self::assertIsString($source);
        return $source;
    }

    private function sessionCookieCall(): string
    {
        return "setcookie('binktermphp_session', \$sessionId, Config::getSessionCookieOptions());";
    }
}
