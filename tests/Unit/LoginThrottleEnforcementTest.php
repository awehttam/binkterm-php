<?php

declare(strict_types=1);

require_once __DIR__ . '/../../ssh/src/SshStreamWrapper.php';
require_once __DIR__ . '/../../ssh/src/SshSession.php';
require_once __DIR__ . '/../../ssh/src/SshServer.php';

use BinktermPHP\Security\LoginThrottle;
use BinktermPHP\SshServer\SshSession;
use PHPUnit\Framework\TestCase;

/**
 * Structural / wiring proof for the shared login throttle slice:
 *
 *  - the throttle is enforced at exactly one boundary (the /api/auth/login
 *    route), around Auth::login(), and its throttled response is byte-identical
 *    to the ordinary wrong-password response (no enumeration / lockout signal);
 *  - failures are recorded only on the real credential-failure branch;
 *  - the SSH initial password check now sends the authenticated real-client-IP
 *    headers, mirroring BbsSession/TelnetUtils;
 *  - the deeper Auth::authenticateCredentials() primitive (FTP / NNTP / QWK
 *    Basic-auth) is left outside the route-level throttle.
 */
final class LoginThrottleEnforcementTest extends TestCase
{
    private static function routeSrc(): string
    {
        $src = file_get_contents(__DIR__ . '/../../routes/api-routes.php');
        self::assertIsString($src);
        return $src;
    }

    private static function loginHandlerSrc(): string
    {
        $src = self::routeSrc();
        $start = strpos($src, "SimpleRouter::post('/auth/login'");
        self::assertNotFalse($start);
        $end = strpos($src, "SimpleRouter::post('/auth/logout'", $start);
        self::assertNotFalse($end);
        return substr($src, $start, $end - $start);
    }

    public function testThrottleCheckPrecedesCredentialVerification(): void
    {
        $h = self::loginHandlerSrc();
        $checkPos = strpos($h, '$loginThrottle->isAllowed(');
        $loginPos = strpos($h, '$auth->login(');
        self::assertNotFalse($checkPos, 'handler must consult the throttle');
        self::assertNotFalse($loginPos, 'handler must still call Auth::login()');
        self::assertLessThan($loginPos, $checkPos, 'throttle check must run before Auth::login()');
    }

    public function testThrottledResponseIsIdenticalToWrongPasswordResponse(): void
    {
        $h = self::loginHandlerSrc();
        // Exactly one apiError signature is used for invalid credentials, and
        // the throttled branch uses that same call — no bespoke "locked" code,
        // message, or status.
        $needle = "apiError('errors.auth.invalid_credentials', apiLocalizedText('errors.auth.invalid_credentials', 'Invalid credentials'), 401)";
        $count = substr_count($h, $needle);
        self::assertGreaterThanOrEqual(2, $count, 'throttled + wrong-password branches share one generic 401 response');
        self::assertStringNotContainsString('locked', strtolower($h));
        self::assertStringNotContainsString('too many', strtolower($h));
        self::assertStringNotContainsString('429', $h);
    }

    public function testFailureRecordedOnlyOnCredentialFailureBranch(): void
    {
        $h = self::loginHandlerSrc();

        // recordFailure lives after "else {" (the !$sessionId branch), not in
        // the success branch.
        $elsePos = strpos($h, '} else {');
        $failPos = strpos($h, '$loginThrottle->recordFailure(');
        $successPos = strpos($h, '$loginThrottle->recordSuccess(');
        self::assertNotFalse($elsePos);
        self::assertNotFalse($failPos);
        self::assertNotFalse($successPos);
        self::assertGreaterThan($elsePos, $failPos, 'recordFailure must be in the !$sessionId branch');
        self::assertLessThan($elsePos, $successPos, 'recordSuccess must be in the success branch');

        // recordSuccess clears only the username (no IP argument).
        self::assertMatchesRegularExpression(
            '/recordSuccess\(\s*\$username\s*\)/',
            $h,
            'recordSuccess takes only the identifier — the IP counter is left to age out'
        );
    }

    public function testThrottleIsNotWiredIntoTheDeeperCredentialPrimitive(): void
    {
        // The throttle must never leak into Auth::authenticateCredentials()
        // itself, nor into the FTP / NNTP transports (separate daemons, not
        // externally exposed in this deployment — see docs/proposals for the
        // direct-caller recon).
        foreach ([
            __DIR__ . '/../../src/Auth.php',
            __DIR__ . '/../../src/Ftp/FtpServer.php',
            __DIR__ . '/../../src/Nntp/NntpAuth.php',
        ] as $file) {
            $src = file_get_contents($file);
            self::assertIsString($src);
            self::assertStringNotContainsString(
                'LoginThrottle',
                $src,
                basename($file) . ' must not reference the route-level LoginThrottle'
            );
        }
    }

    public function testQwkHttpBasicAuthIsThrottled(): void
    {
        // The public QWK-over-HTTP Basic-auth helper calls
        // Auth::authenticateCredentials() directly, bypassing /api/auth/login.
        // It must apply the same shared throttle around that call.
        $src = str_replace("\r\n", "\n", (string)file_get_contents(__DIR__ . '/../../routes/web-routes.php'));
        self::assertNotSame('', $src);

        $start = strpos($src, 'function requireBasicAuthUser(');
        self::assertNotFalse($start);
        $end = strpos($src, "\n    }\n}", $start);
        self::assertNotFalse($end);
        $fn = substr($src, $start, $end - $start);

        $checkPos = strpos($fn, '$throttle->isAllowed(');
        $authPos = strpos($fn, '$auth->authenticateCredentials(');
        $failPos = strpos($fn, '$throttle->recordFailure(');
        $successPos = strpos($fn, '$throttle->recordSuccess(');

        self::assertNotFalse($checkPos, 'requireBasicAuthUser must consult the throttle');
        self::assertNotFalse($authPos);
        self::assertNotFalse($failPos, 'a failed Basic-auth attempt must be recorded');
        self::assertNotFalse($successPos, 'a successful Basic-auth must clear the identifier counter');
        self::assertLessThan($authPos, $checkPos, 'throttle check precedes the credential check');
        self::assertGreaterThan($authPos, $failPos, 'failure recorded after the credential check returns false');

        // recordSuccess clears only the identifier — the IP counter ages out.
        self::assertMatchesRegularExpression(
            '/recordSuccess\(\s*\$credentials\[.username.\]\s*\)/',
            $fn
        );
        // Same generic 401 as a wrong password — no lockout / enumeration signal.
        self::assertStringNotContainsString('locked', strtolower($fn));
        self::assertStringNotContainsString('too many', strtolower($fn));
        self::assertStringNotContainsString('429', $fn);
    }

    public function testSshServerPassesParsedPeerIpIntoSshSession(): void
    {
        $src = file_get_contents(__DIR__ . '/../../ssh/src/SshServer.php');
        self::assertIsString($src);
        // handleConnection builds a validated IP from the peer name and passes it
        // as the new SshSession constructor argument.
        self::assertMatchesRegularExpression(
            '/\$clientIp\s*=\s*filter_var\(\$host, FILTER_VALIDATE_IP\) !== false \? \$host : null;/',
            $src
        );
        $ctorPos = strpos($src, 'new SshSession(');
        self::assertNotFalse($ctorPos);
        $ctorCall = substr($src, $ctorPos, 260);
        self::assertStringContainsString('$clientIp', $ctorCall, 'SshSession must receive the parsed peer IP');
    }

    public function testSshVerifyPasswordSendsAuthenticatedClientIpHeadersWhenConfigured(): void
    {
        $saved = $_ENV['TERMINAL_REGISTRATION_SECRET'] ?? null;
        $_ENV['TERMINAL_REGISTRATION_SECRET'] = 'site-specific-secret-' . bin2hex(random_bytes(4));
        try {
            $headers = $this->invokeTerminalClientIpHeaders('198.51.100.42');
            $this->assertContains('X-Binkterm-Client-IP: 198.51.100.42', $headers);
            $this->assertContains(
                'X-Binkterm-Client-Token: ' . $_ENV['TERMINAL_REGISTRATION_SECRET'],
                $headers
            );

            // verifyPassword() must actually merge these into the cURL header list.
            $vp = $this->methodSource('verifyPassword');
            $this->assertStringContainsString('terminalClientIpHeaders()', $vp);
            $this->assertStringContainsString('CURLOPT_HTTPHEADER', $vp);
        } finally {
            if ($saved === null) {
                unset($_ENV['TERMINAL_REGISTRATION_SECRET']);
            } else {
                $_ENV['TERMINAL_REGISTRATION_SECRET'] = $saved;
            }
        }
    }

    public function testSshVerifyPasswordSendsNoClientIpHeaderWithoutAParseablePeerIp(): void
    {
        $saved = $_ENV['TERMINAL_REGISTRATION_SECRET'] ?? null;
        $_ENV['TERMINAL_REGISTRATION_SECRET'] = 'site-specific-secret';
        try {
            $this->assertSame([], $this->invokeTerminalClientIpHeaders(null));
            $this->assertSame([], $this->invokeTerminalClientIpHeaders('not-an-ip'));
        } finally {
            if ($saved === null) {
                unset($_ENV['TERMINAL_REGISTRATION_SECRET']);
            } else {
                $_ENV['TERMINAL_REGISTRATION_SECRET'] = $saved;
            }
        }
    }

    public function testTelnetClientIpHeaderBehaviourIsUnchangedBySlice(): void
    {
        // The slice does not touch the Telnet/BbsSession client-IP path — it
        // still builds the same authenticated header the SSH side now mirrors.
        $bbs = file_get_contents(__DIR__ . '/../../telnet/src/BbsSession.php');
        $utils = file_get_contents(__DIR__ . '/../../telnet/src/TelnetUtils.php');
        self::assertIsString($bbs);
        self::assertIsString($utils);
        self::assertStringContainsString("'X-Binkterm-Client-IP: ' . \$this->peerIp", $bbs);
        self::assertStringContainsString('X-Binkterm-Client-IP: ', $utils);
        self::assertStringNotContainsString('LoginThrottle', $bbs);
        self::assertStringNotContainsString('LoginThrottle', $utils);
    }

    /**
     * Invoke SshSession::terminalClientIpHeaders() on an instance built without
     * the constructor (which would need a real host key), with $peerIp forced.
     *
     * @return list<string>
     */
    private function invokeTerminalClientIpHeaders(?string $peerIp): array
    {
        $rc = new ReflectionClass(SshSession::class);
        $session = $rc->newInstanceWithoutConstructor();

        $prop = $rc->getProperty('peerIp');
        $prop->setAccessible(true);
        $prop->setValue($session, $peerIp);

        $method = $rc->getMethod('terminalClientIpHeaders');
        $method->setAccessible(true);

        return $method->invoke($session);
    }

    public function testThrottleIpKeySkipsLoopbackAndServerAddress(): void
    {
        $rm = new ReflectionMethod(LoginThrottle::class, 'throttleIpKey');
        $rm->setAccessible(true);

        $saved = $_SERVER['SERVER_ADDR'] ?? null;
        $_SERVER['SERVER_ADDR'] = '203.0.113.5';
        try {
            self::assertSame('', $rm->invoke(null, '127.0.0.1'));
            self::assertSame('', $rm->invoke(null, '::1'));
            self::assertSame('', $rm->invoke(null, '203.0.113.5'));
            self::assertSame('', $rm->invoke(null, 'not-an-ip'));
            self::assertSame('198.51.100.7', $rm->invoke(null, '198.51.100.7'));
        } finally {
            if ($saved === null) {
                unset($_SERVER['SERVER_ADDR']);
            } else {
                $_SERVER['SERVER_ADDR'] = $saved;
            }
        }
    }

    private function methodSource(string $method): string
    {
        $rm = new ReflectionMethod(SshSession::class, $method);
        $src = file(__DIR__ . '/../../ssh/src/SshSession.php');
        return implode('', array_slice($src, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }
}
