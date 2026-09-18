<?php

declare(strict_types=1);

use BinktermPHP\Binkp\Protocol\BinkpSession;
use PHPUnit\Framework\TestCase;

/**
 * Security regression coverage for BinkP authentication logging.
 */
final class BinkpCramAuthLoggingTest extends TestCase
{
    private const FAKE_PASSWORD = 's3Kret-unit-passphrase-9876';
    private const FAKE_CHALLENGE = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
    private const FAKE_ADDRESS = '999:1/1';

    /** @var resource */
    private $socket;

    /** @var list<string> */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->socket = fopen('php://memory', 'r+');
        $this->captured = [];
    }

    protected function tearDown(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function testCramDigestRemainsCorrectWithoutLoggingAuthenticationMaterial(): void
    {
        $session = $this->makeSession();
        $digest = $this->invoke($session, 'computeCramDigest', [
            self::FAKE_CHALLENGE,
            self::FAKE_PASSWORD,
        ]);
        $expected = hash_hmac('md5', hex2bin(self::FAKE_CHALLENGE), self::FAKE_PASSWORD);

        self::assertSame($expected, $digest);
        self::assertStringContainsString(
            'Computed CRAM-MD5 authentication response',
            $this->allLogText()
        );
        $this->assertLogsExcludeAuthenticationMaterial($digest);
    }

    public function testCramValidationPreservesSuccessAndFailureLogging(): void
    {
        $digest = hash_hmac('md5', hex2bin(self::FAKE_CHALLENGE), self::FAKE_PASSWORD);

        $success = $this->makeAuthenticationSession();
        self::assertTrue($this->invoke($success, 'validatePassword', ['CRAM-MD5-' . $digest]));
        self::assertStringContainsString('CRAM-MD5 validation: OK', $this->allLogText());
        $this->assertLogsExcludeAuthenticationMaterial($digest);

        $this->captured = [];
        $failure = $this->makeAuthenticationSession();
        $wrongDigest = str_repeat('0', 32);
        self::assertFalse($this->invoke($failure, 'validatePassword', ['CRAM-MD5-' . $wrongDigest]));
        self::assertStringContainsString('CRAM-MD5 validation: FAILED', $this->allLogText());
        $this->assertLogsExcludeAuthenticationMaterial($digest, $wrongDigest);
    }

    public function testPlaintextValidationLogsOnlyOutcome(): void
    {
        $success = $this->makeAuthenticationSession(false);
        self::assertTrue($this->invoke($success, 'validatePassword', [self::FAKE_PASSWORD]));
        self::assertStringContainsString('Plain text password validation: OK', $this->allLogText());
        $this->assertLogsExcludeAuthenticationMaterial('');

        $this->captured = [];
        $failure = $this->makeAuthenticationSession(false);
        $wrongPassword = 'wR0ng-unit-passphrase-5432';
        self::assertFalse($this->invoke($failure, 'validatePassword', [$wrongPassword]));
        self::assertStringContainsString('Plain text password validation: FAILED', $this->allLogText());
        $this->assertLogsExcludeAuthenticationMaterial('', $wrongPassword);
    }

    public function testChallengeParsingAndPasswordConfigurationLogsAreRedacted(): void
    {
        $session = $this->makeSession();
        $parsed = $this->invoke(
            $session,
            'parseCramChallenge',
            ['OPT CRAM-MD5-' . self::FAKE_CHALLENGE]
        );
        $session->setUplinkPassword(self::FAKE_PASSWORD);

        self::assertSame(self::FAKE_CHALLENGE, $parsed);
        self::assertStringContainsString('Parsed CRAM-MD5 challenge from remote', $this->allLogText());
        self::assertStringContainsString('Uplink password configured', $this->allLogText());
        $this->assertLogsExcludeAuthenticationMaterial('');
    }

    public function testKnownCredentialLoggingPatternsAreAbsent(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/Binkp/Protocol/BinkpSession.php'
        );
        self::assertIsString($source);

        foreach ([
            'setUplinkPassword: length=',
            'password_len=',
            'CRAM-MD5 HMAC digest:',
            'Parsed CRAM-MD5 challenge: " . $challenge',
            'Sent password (length=',
            '$receivedPreview',
            '$expectedPreview',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testDiagnosticScriptsRedactPasswordFramesAndDerivedValues(): void
    {
        foreach (['test_crashmail.php', 'test_plaintext_auth.php'] as $script) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/scripts/' . $script);
            self::assertIsString($source);
            self::assertStringContainsString('if ($opcode === 2)', $source);
            self::assertStringContainsString('(contents redacted)', $source);
            self::assertStringNotContainsString('password_len=', $source);
            self::assertStringNotContainsString('challenge={$cramChallenge}', $source);
            self::assertStringNotContainsString('digest={$digest}', $source);
        }
    }

    private function makeAuthenticationSession(bool $withChallenge = true): BinkpSession
    {
        $config = new class(self::FAKE_PASSWORD) {
            public function __construct(private string $password)
            {
            }

            public function getUplinkByAddress(string $address): array
            {
                return ['password' => $this->password];
            }
        };

        $session = $this->makeSession($config);
        $this->setProperty($session, 'remoteAddress', self::FAKE_ADDRESS);
        if ($withChallenge) {
            $this->setProperty($session, 'cramChallenge', self::FAKE_CHALLENGE);
        }
        return $session;
    }

    private function makeSession(?object $config = null): BinkpSession
    {
        $session = new BinkpSession($this->socket, true, $config ?? new \stdClass());
        $logger = new class($this->captured) {
            /** @var list<string> */
            private array $sink;

            /** @param list<string> $sink */
            public function __construct(array &$sink)
            {
                $this->sink = &$sink;
            }

            public function log($level, $message, $context = []): void
            {
                $this->sink[] = $level . '|' . $message;
            }
        };
        $session->setLogger($logger);
        return $session;
    }

    /** @param list<mixed> $arguments */
    private function invoke(BinkpSession $session, string $method, array $arguments)
    {
        $reflection = new \ReflectionMethod($session, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($session, $arguments);
    }

    private function setProperty(BinkpSession $session, string $property, $value): void
    {
        $reflection = new \ReflectionProperty($session, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($session, $value);
    }

    private function allLogText(): string
    {
        return implode("\n", $this->captured);
    }

    private function assertLogsExcludeAuthenticationMaterial(
        string $digest,
        string $additionalSecret = ''
    ): void {
        $log = $this->allLogText();
        foreach ([
            self::FAKE_PASSWORD,
            substr(self::FAKE_PASSWORD, 0, 3),
            self::FAKE_CHALLENGE,
            $digest,
            $additionalSecret,
        ] as $secret) {
            if ($secret !== '') {
                self::assertStringNotContainsString($secret, $log);
            }
        }

        self::assertDoesNotMatchRegularExpression('/password_len\s*=/i', $log);
        self::assertDoesNotMatchRegularExpression('/(?:length|len)\s*=\s*\d/i', $log);
        self::assertDoesNotMatchRegularExpression('/(?:challenge|digest)\s*=/i', $log);
    }
}
