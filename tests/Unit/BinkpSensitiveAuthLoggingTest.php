<?php

declare(strict_types=1);

use BinktermPHP\Binkp\Protocol\BinkpSession;
use PHPUnit\Framework\TestCase;

/**
 * BinkP authentication DEBUG logging must stay useful without writing
 * secrets: by default no password characters and no CRAM-MD5 challenge or
 * digest values appear; BINKP_LOG_SENSITIVE_AUTH=true restores them and logs
 * a warning that it is enabled.
 */
final class BinkpSensitiveAuthLoggingTest extends TestCase
{
    private const SECRET = 'Sekrit-Passw0rd';
    private const CHALLENGE = '0123456789abcdef0123456789abcdef';

    private bool $hadFlag = false;
    private mixed $previousFlag = null;

    protected function setUp(): void
    {
        \BinktermPHP\Config::env('APP_ENV'); // load .env (if any) before overriding
        $this->hadFlag = array_key_exists('BINKP_LOG_SENSITIVE_AUTH', $_ENV);
        $this->previousFlag = $_ENV['BINKP_LOG_SENSITIVE_AUTH'] ?? null;
        unset($_ENV['BINKP_LOG_SENSITIVE_AUTH']);
    }

    protected function tearDown(): void
    {
        if ($this->hadFlag) {
            $_ENV['BINKP_LOG_SENSITIVE_AUTH'] = $this->previousFlag;
        } else {
            unset($_ENV['BINKP_LOG_SENSITIVE_AUTH']);
        }
    }

    public function testDefaultPlaintextLoggingHasNoPasswordCharactersButKeepsDiagnostics(): void
    {
        [$session, $log] = $this->session();
        $this->call($session, 'validatePassword', ['sekrit-passw0rd']);

        $text = implode("\n", $log->lines);
        self::assertStringNotContainsString('Sek', $text);
        self::assertStringNotContainsString('sek', $text);
        self::assertStringContainsString('received len=15, expected len=15, differs only by letter case', $text);
        self::assertStringContainsString('Password validation: FAILED', $text);
        self::assertStringNotContainsString('BINKP_LOG_SENSITIVE_AUTH', $text);
    }

    public function testDefaultMismatchHints(): void
    {
        [$session, $log] = $this->session();
        $this->call($session, 'validatePassword', [self::SECRET . ' ']);
        $this->call($session, 'validatePassword', ['short']);
        $this->call($session, 'validatePassword', ['Xekrit-Passw0rd']);

        $text = implode("\n", $log->lines);
        self::assertStringContainsString('differs only by leading/trailing whitespace', $text);
        self::assertStringContainsString('received len=5, expected len=15, length differs', $text);
        self::assertStringContainsString('same length, content differs', $text);
    }

    public function testDefaultCramLoggingHasNoChallengeOrDigest(): void
    {
        [$session, $log] = $this->session();
        $digest = $this->call($session, 'computeCramDigest', [self::CHALLENGE, self::SECRET]);
        $this->call($session, 'parseCramChallenge', ['OPT CRAM-MD5-' . self::CHALLENGE]);

        $text = implode("\n", $log->lines);
        self::assertStringNotContainsString($digest, $text);
        self::assertStringNotContainsString(self::CHALLENGE, $text);
        self::assertStringContainsString('challenge_len=32', $text);
        self::assertStringContainsString('Parsed CRAM-MD5 challenge (len=32)', $text);
    }

    public function testOptInRestoresSensitiveDetailAndWarnsOnce(): void
    {
        $_ENV['BINKP_LOG_SENSITIVE_AUTH'] = 'true';
        [$session, $log] = $this->session();
        $this->call($session, 'validatePassword', ['Sekrit-wrong!!!']);
        $digest = $this->call($session, 'computeCramDigest', [self::CHALLENGE, self::SECRET]);
        $this->call($session, 'parseCramChallenge', ['OPT CRAM-MD5-' . self::CHALLENGE]);

        $text = implode("\n", $log->lines);
        self::assertStringContainsString('received=Sek...', $text);
        self::assertStringContainsString('expected=Sek...', $text);
        self::assertStringContainsString('digest=' . $digest, $text);
        self::assertStringContainsString('Parsed CRAM-MD5 challenge: ' . self::CHALLENGE, $text);

        $warnings = array_values(array_filter($log->lines, fn ($l) => str_contains($l, 'BINKP_LOG_SENSITIVE_AUTH is enabled')));
        self::assertCount(1, $warnings, 'warning is logged exactly once per session');
        self::assertStringStartsWith('[WARNING]', $warnings[0]);
    }

    public function testFalseyValuesKeepTheSafeDefault(): void
    {
        foreach (['false', '0', 'no', ''] as $value) {
            $_ENV['BINKP_LOG_SENSITIVE_AUTH'] = $value;
            [$session, $log] = $this->session();
            $this->call($session, 'parseCramChallenge', ['OPT CRAM-MD5-' . self::CHALLENGE]);
            self::assertStringNotContainsString(self::CHALLENGE, implode("\n", $log->lines), "value '{$value}'");
        }
    }

    private function session(): array
    {
        $session = (new ReflectionClass(BinkpSession::class))->newInstanceWithoutConstructor();
        $config = new class {
            public function getUplinkByAddress($address)
            {
                return ['address' => $address, 'password' => 'Sekrit-Passw0rd'];
            }

            public function getAllowPlaintextFallback()
            {
                return true;
            }
        };
        foreach (['config' => $config, 'remoteAddress' => '999:1/1', 'cramChallenge' => null] as $name => $value) {
            $property = new ReflectionProperty(BinkpSession::class, $name);
            $property->setAccessible(true);
            $property->setValue($session, $value);
        }
        $log = new class {
            public array $lines = [];

            public function log($level, $message, $context = [])
            {
                $this->lines[] = "[{$level}] {$message}";
            }
        };
        $session->setLogger($log);

        return [$session, $log];
    }

    private function call(BinkpSession $session, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($session, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($session, $args);
    }
}
