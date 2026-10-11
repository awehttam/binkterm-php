<?php

declare(strict_types=1);

use BinktermPHP\Config;
use PHPUnit\Framework\TestCase;

/**
 * The login page's "Remember me" checkbox decides whether the session cookie
 * persists for the session lifetime or only for the browser session.
 * Clients that do not send the flag keep the persistent cookie.
 */
final class RememberMeContractTest extends TestCase
{
    public function testPersistentCookieOptionsKeepTheExpiry(): void
    {
        $before = time();
        $options = Config::getSessionCookieOptions(true);

        self::assertArrayHasKey('expires', $options);
        self::assertGreaterThan($before, $options['expires']);
        self::assertSame(Config::getSessionCookieOptions(), $options, 'persistent is the default');
    }

    public function testBrowserSessionCookieHasNoExpiryButSameAttributes(): void
    {
        $persistent = Config::getSessionCookieOptions(true);
        $session = Config::getSessionCookieOptions(false);

        self::assertArrayNotHasKey('expires', $session);
        unset($persistent['expires']);
        self::assertSame($persistent, $session, 'path/httponly/samesite/secure must not change');
    }

    public function testLoginFormSendsTheCheckboxState(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../templates/login.twig');

        self::assertStringContainsString("remember: $('#remember').is(':checked')", $template);
    }

    public function testLoginRouteUsesTheRememberFlagWithLegacyDefault(): void
    {
        $routes = (string)file_get_contents(__DIR__ . '/../../routes/api-routes.php');
        $start = strpos($routes, "SimpleRouter::post('/auth/login'");
        $end = strpos($routes, "SimpleRouter::post('/auth/logout'", (int)$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $route = substr($routes, $start, $end - $start);

        self::assertStringContainsString("array_key_exists('remember', \$input)", $route);
        self::assertStringContainsString("\$input['remember'] === true", $route);
        self::assertMatchesRegularExpression('/:\s*true;/', $route, 'absent flag must default to persistent');
        self::assertStringContainsString('Config::getSessionCookieOptions($remember)', $route);
    }
}
