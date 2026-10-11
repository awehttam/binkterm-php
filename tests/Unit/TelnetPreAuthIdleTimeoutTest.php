<?php

declare(strict_types=1);

use BinktermPHP\Config;
use BinktermPHP\TelnetServer\BbsSession;
use PHPUnit\Framework\TestCase;

// telnet/src/ classes are not Composer-autoloaded (see telnet/CLAUDE.md) —
// require the same seam BbsSession's write/read primitives transitively touch.
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';

/**
 * Telnet pre-authentication idle timeout.
 *
 * The pre-login prompts (login / register / reset) use a deliberately short
 * idle deadline so an idle Telnet scanner that connects and then sits silent
 * releases its forked handler in ~90s instead of ~7 minutes. Any keystroke
 * before authentication refreshes the timer; a successful login switches the
 * session back to the normal authenticated idle semantics.
 *
 * These tests never touch the host `.env`: Config's lazy-load guard is stubbed
 * so only synthetic `$_ENV` values set here are read (same pattern as
 * tests/Unit/TerminalRegistrationSecretTest.php).
 */
final class TelnetPreAuthIdleTimeoutTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        // Make Config::loadConfig() a no-op so the real .env is never read.
        $loaded = new \ReflectionProperty(Config::class, 'loaded');
        $loaded->setAccessible(true);
        $loaded->setValue(null, true);

        $this->envBackup['TELNET_PREAUTH_IDLE_TIMEOUT'] = $_ENV['TELNET_PREAUTH_IDLE_TIMEOUT'] ?? null;
        unset($_ENV['TELNET_PREAUTH_IDLE_TIMEOUT']);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    // ---- config resolution -------------------------------------------------

    public function testDefaultPreAuthTimeoutIsNinetySeconds(): void
    {
        self::assertSame(90, BbsSession::PREAUTH_IDLE_TIMEOUT_DEFAULT);
        self::assertSame(90, BbsSession::preAuthIdleTimeoutSeconds());
    }

    public function testValidCustomPreAuthTimeoutIsHonoured(): void
    {
        $_ENV['TELNET_PREAUTH_IDLE_TIMEOUT'] = '45';
        self::assertSame(45, BbsSession::preAuthIdleTimeoutSeconds());
    }

    /**
     * A value that would cut off a human mid-login is rejected as a
     * misconfiguration and falls back to the default.
     *
     * @dataProvider unsafePreAuthTimeoutProvider
     */
    public function testUnsafeOrUnparseablePreAuthTimeoutFallsBackToDefault(string $raw): void
    {
        $_ENV['TELNET_PREAUTH_IDLE_TIMEOUT'] = $raw;
        self::assertSame(90, BbsSession::preAuthIdleTimeoutSeconds());
    }

    /** @return array<string,array{0:string}> */
    public static function unsafePreAuthTimeoutProvider(): array
    {
        return [
            'zero'          => ['0'],
            'below floor'   => ['5'],
            'negative'      => ['-30'],
            'non-numeric'   => ['banana'],
        ];
    }

    public function testAuthenticatedDefaultsAreTheHistoricalValues(): void
    {
        // The post-login idle semantics must not move.
        self::assertSame(300, BbsSession::AUTH_IDLE_WARNING_DEFAULT);
        self::assertSame(420, BbsSession::AUTH_IDLE_DISCONNECT_DEFAULT);
    }

    // ---- pre-auth / post-auth state boundary ------------------------------

    public function testApplyPreAuthIdleDefaultsShortensBothDeadlines(): void
    {
        $_ENV['TELNET_PREAUTH_IDLE_TIMEOUT'] = '90';
        $session = $this->makeSession();

        $state = ['idle_warned' => true, 'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420];
        $this->invoke($session, 'applyPreAuthIdleDefaults', [&$state]);

        self::assertSame(90, $state['idle_disconnect_timeout']);
        self::assertSame(90, $state['idle_warning_timeout']);
        self::assertFalse($state['idle_warned']);
    }

    public function testSuccessfulLoginRestoresAuthenticatedIdleSemantics(): void
    {
        $session = $this->makeSession();

        // Start from the shortened pre-auth window ...
        $state = ['idle_warned' => true, 'idle_warning_timeout' => 90, 'idle_disconnect_timeout' => 90];

        // ... then cross the post-login boundary.
        $this->invoke($session, 'applyAuthenticatedIdleDefaults', [&$state]);

        self::assertSame(420, $state['idle_disconnect_timeout']);
        self::assertSame(300, $state['idle_warning_timeout']);
        self::assertFalse($state['idle_warned']);
    }

    // ---- the shortened deadline is actually enforced by the read path -----

    public function testPreAuthReadDisconnectsAtTheShortenedDeadline(): void
    {
        [$session, $server, $client] = $this->makeWiredSession();

        $state = $this->baseState();
        $state['idle_disconnect_timeout'] = 90;
        $state['idle_warning_timeout']    = 90;
        $state['last_activity']           = time() - 91;

        [$line, $timedOut, $shouldDisconnect] = $this->invoke(
            $session,
            'readTelnetLineWithTimeout',
            [$server, &$state]
        );

        self::assertNull($line);
        self::assertTrue($timedOut);
        self::assertTrue($shouldDisconnect, 'pre-auth session past the 90s deadline must disconnect');
        self::assertNotSame('', $this->drain($client), 'the idle-disconnect message must still be written');

        fclose($server);
        fclose($client);
    }

    public function testActivityBeforeAuthRefreshesThePreAuthTimer(): void
    {
        [$session, $server, $client] = $this->makeWiredSession();

        $state = $this->baseState();
        $state['idle_disconnect_timeout'] = 90;
        $state['idle_warning_timeout']    = 90;
        $state['last_activity']           = time() - 30;
        $before = $state['last_activity'];

        fwrite($client, "hello\r\n");

        [$line, $timedOut, $shouldDisconnect] = $this->invoke(
            $session,
            'readTelnetLineWithTimeout',
            [$server, &$state]
        );

        self::assertSame('hello', $line);
        self::assertFalse($shouldDisconnect);
        self::assertFalse($timedOut);
        self::assertGreaterThan($before, $state['last_activity'], 'input must refresh last_activity');
        self::assertFalse($state['idle_warned']);

        fclose($server);
        fclose($client);
    }

    public function testAuthenticatedSessionKeepsTheLongerDeadline(): void
    {
        [$session, $server, $client] = $this->makeWiredSession();

        // Authenticated idle values; idle for 100s — well past the 90s pre-auth
        // deadline but far short of the 420s authenticated one.
        $state = $this->baseState();
        $state['idle_disconnect_timeout'] = 420;
        $state['idle_warning_timeout']    = 300;
        $state['last_activity']           = time() - 100;

        fwrite($client, "still here\r\n");

        [$line, $timedOut, $shouldDisconnect] = $this->invoke(
            $session,
            'readTelnetLineWithTimeout',
            [$server, &$state]
        );

        self::assertSame('still here', $line);
        self::assertFalse($shouldDisconnect, 'an authenticated session must not disconnect at 100s idle');

        fclose($server);
        fclose($client);
    }

    // ---- line reader must not block past the idle deadline on protocol chatter

    private const IAC = "\xff";
    private const CMD_DO = "\xfd";
    private const OPT_NAWS = "\x1f";

    /**
     * A real terminal can send Telnet negotiation chatter but never press
     * Enter. Pre-fix, readTelnetLine() consumed the
     * chatter and then blocked on the next byte, so the idle deadline (only
     * re-checked between lines) was never reached. It must now yield.
     */
    public function testChatterOnlyReadYieldsInsteadOfBlocking(): void
    {
        [$session, $server, $client] = $this->makeWiredSession(blocking: true, readTimeout: 2);

        // Under the deadline at entry, so the top-of-function check does not
        // short-circuit — the chatter path itself must be exercised.
        $state = $this->baseState();
        $state['idle_disconnect_timeout'] = 3;
        $state['idle_warning_timeout']    = 3;
        $state['last_activity']           = time();
        $before = $state['last_activity'];

        fwrite($client, self::IAC . self::CMD_DO . self::OPT_NAWS); // lone negotiation, no CR/LF

        $start = microtime(true);
        [$line, $timedOut, $shouldDisconnect] = $this->invoke(
            $session,
            'readTelnetLineWithTimeout',
            [$server, &$state]
        );
        $elapsed = microtime(true) - $start;

        self::assertLessThan(2.0, $elapsed, 'chatter-only read must not block on the next byte');
        self::assertSame('', $line);
        self::assertTrue($timedOut, 'chatter-only must surface as a soft timeout so the caller re-checks the deadline');
        self::assertFalse($shouldDisconnect);
        self::assertSame($before, $state['last_activity'], 'protocol chatter must not refresh last_activity');
        self::assertFalse($state['idle_warned']);

        fclose($server);
        fclose($client);
    }

    /**
     * And once the soft-timeout loop carries the session past the deadline, the
     * normal idle-disconnect path fires (message written, shouldDisconnect set).
     */
    public function testChatterYieldStillReachesIdleDisconnectPastTheDeadline(): void
    {
        [$session, $server, $client] = $this->makeWiredSession(blocking: true, readTimeout: 2);

        $state = $this->baseState();
        $state['idle_disconnect_timeout'] = 2;
        $state['idle_warning_timeout']    = 2;
        $state['last_activity']           = time() - 5; // already past the deadline

        fwrite($client, self::IAC . self::CMD_DO . self::OPT_NAWS);

        [$line, $timedOut, $shouldDisconnect] = $this->invoke(
            $session,
            'readTelnetLineWithTimeout',
            [$server, &$state]
        );

        self::assertNull($line);
        self::assertTrue($timedOut);
        self::assertTrue($shouldDisconnect);
        self::assertNotSame('', $this->drain($client), 'the existing idle-disconnect message must be written');

        fclose($server);
        fclose($client);
    }

    public function testChatterThenRealLineReturnsTheLineAndAdvancesActivity(): void
    {
        [$session, $server, $client] = $this->makeWiredSession(blocking: true, readTimeout: 2);

        $state = $this->baseState();
        $state['idle_disconnect_timeout'] = 90;
        $state['idle_warning_timeout']    = 90;
        $state['last_activity']           = time() - 10;
        $before = $state['last_activity'];

        // Negotiation immediately followed by a real menu choice.
        fwrite($client, self::IAC . self::CMD_DO . self::OPT_NAWS . "L\r\n");

        [$line, $timedOut, $shouldDisconnect] = $this->invoke(
            $session,
            'readTelnetLineWithTimeout',
            [$server, &$state]
        );

        self::assertSame('L', $line);
        self::assertFalse($timedOut);
        self::assertFalse($shouldDisconnect);
        self::assertGreaterThan($before, $state['last_activity'], 'a real line refreshes last_activity');
        self::assertFalse($state['idle_warned']);

        fclose($server);
        fclose($client);
    }

    public function testReadTelnetLineReturnsTheChatterSentinelDirectly(): void
    {
        [$session, $server, $client] = $this->makeWiredSession(blocking: true, readTimeout: 2);
        $state = $this->baseState();

        fwrite($client, self::IAC . self::CMD_DO . self::OPT_NAWS);

        $result = $this->invoke($session, 'readTelnetLine', [$server, &$state]);

        self::assertSame($this->chatterSentinel(), $result);
        self::assertStringContainsString("\x00", $result, 'sentinel must be unrepresentable as real input');

        fclose($server);
        fclose($client);
    }

    public function testGenuineEofStillReturnsNullNotTheSentinel(): void
    {
        [$session, $server, $client] = $this->makeWiredSession(blocking: true, readTimeout: 2);
        $state = $this->baseState();
        $state['idle_disconnect_timeout'] = 90;
        $state['idle_warning_timeout']    = 90;

        fclose($client); // peer gone

        [$line, $timedOut, $shouldDisconnect] = $this->invoke(
            $session,
            'readTelnetLineWithTimeout',
            [$server, &$state]
        );

        self::assertNull($line, 'EOF still yields null, not the chatter sentinel');
        self::assertFalse($timedOut);

        fclose($server);
    }

    public function testCtrlCStillReturnsNull(): void
    {
        [$session, $server, $client] = $this->makeWiredSession(blocking: true, readTimeout: 2);
        $state = $this->baseState();

        fwrite($client, "\x03"); // ETX

        $result = $this->invoke($session, 'readTelnetLine', [$server, &$state]);

        self::assertNull($result, 'Ctrl-C semantics unchanged: readTelnetLine returns null');
        self::assertStringContainsString('^C', $this->drain($client));

        fclose($server);
        fclose($client);
    }

    // ---- SSH non-impact (source guardrail) -------------------------------

    /**
     * BbsSession::run() must apply the short pre-auth window only on the
     * interactive-login branch. An SSH session authenticated at the protocol
     * layer takes the `isset($this->preAuthSession['session'])` branch and must
     * never reach applyPreAuthIdleDefaults().
     */
    public function testRunAppliesPreAuthWindowOnlyOnTheInteractiveLoginBranch(): void
    {
        $src = file_get_contents(__DIR__ . '/../../telnet/src/BbsSession.php');
        self::assertIsString($src);

        $sshBranch = strpos($src, "isset(\$this->preAuthSession['session'])");
        $preAuthCall = strpos($src, 'applyPreAuthIdleDefaults($state)');
        $loginLoop = strpos($src, 'while ($loginResult === null)');
        $restoreCall = strpos($src, 'applyAuthenticatedIdleDefaults($state)');
        $postLogin = strpos($src, '===== POST-LOGIN SETUP =====');

        self::assertNotFalse($sshBranch);
        self::assertNotFalse($preAuthCall);
        self::assertNotFalse($loginLoop);
        self::assertNotFalse($restoreCall);
        self::assertNotFalse($postLogin);

        // The pre-auth call sits after the SSH short-circuit and before the
        // login prompt loop — i.e. strictly inside the `else` (interactive) arm.
        self::assertGreaterThan($sshBranch, $preAuthCall);
        self::assertLessThan($loginLoop, $preAuthCall);

        // The authenticated restore runs at the top of post-login setup, which
        // both transports reach.
        self::assertGreaterThan($postLogin, $restoreCall);
    }

    // ---- helpers --------------------------------------------------------

    /**
     * @param bool $blocking when true the server-side socket stays blocking with
     *        a short read timeout — used to prove the chatter-only path yields
     *        instead of blocking on the next byte (a regression would stall for
     *        at most $readTimeout seconds, not forever).
     * @return array{0:BbsSession,1:resource,2:resource}
     */
    private function makeWiredSession(bool $blocking = false, int $readTimeout = 2): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair() unavailable');
        }
        [$server, $client] = $pair;
        stream_set_blocking($server, !$blocking ? false : true);
        stream_set_blocking($client, false);
        if ($blocking) {
            stream_set_timeout($server, $readTimeout);
        }

        $session = new BbsSession($server, 'http://127.0.0.1', false, false, false, false);

        return [$session, $server, $client];
    }

    /** The private sentinel readTelnetLine() returns for chatter-only reads. */
    private function chatterSentinel(): string
    {
        return (new \ReflectionClassConstant(BbsSession::class, 'LINE_CHATTER_ONLY'))->getValue();
    }

    private function makeSession(): BbsSession
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        [$server] = $pair !== false ? $pair : [null];

        return new BbsSession($server, 'http://127.0.0.1', false, false, false, false);
    }

    /** @return array<string,mixed> */
    private function baseState(): array
    {
        return [
            'telnet_mode'   => null,
            'input_echo'    => true,
            'cols'          => 80,
            'rows'          => 24,
            'last_activity' => time(),
            'idle_warned'   => false,
            'pushback'      => '',
            'locale'        => 'en',
            'isTls'         => false,
            'isSsh'         => false,
        ];
    }

    /**
     * @param array<int,mixed> $args
     * @return mixed
     */
    private function invoke(BbsSession $session, string $method, array $args)
    {
        $r = new \ReflectionMethod($session, $method);
        $r->setAccessible(true);

        return $r->invokeArgs($session, $args);
    }

    /** @param resource $stream */
    private function drain($stream): string
    {
        $out = '';
        while (($chunk = fread($stream, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }
}
