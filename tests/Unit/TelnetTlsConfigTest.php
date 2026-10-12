<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TelnetServer.php';

use BinktermPHP\TelnetServer\TelnetServer;
use PHPUnit\Framework\TestCase;

/**
 * Configuration parsing for the TLS-Telnet listener.
 *
 * TELNET_TLS_MIN_VERSION picks the protocol floor; everything from that floor
 * up through TLS 1.3 is always offered (so adding 1.3 support never drops a
 * legacy client). TELNET_TLS_CIPHERS overrides the historical
 * DEFAULT:@SECLEVEL=0 cipher string. Bad values must fall back safely, never
 * disable TLS by producing an empty crypto mask.
 */
final class TelnetTlsConfigTest extends TestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['TELNET_TLS_MIN_VERSION', 'TELNET_TLS_CIPHERS'] as $k) {
            $this->savedEnv[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    private function server(): TelnetServer
    {
        return new TelnetServer('127.0.0.1', 0, 'http://127.0.0.1:9');
    }

    private const TLS10 = STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
    private const TLS11 = STREAM_CRYPTO_METHOD_TLSv1_1_SERVER;
    private const TLS12 = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;

    private function tls13(): int
    {
        return defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')
            ? STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
            : 0;
    }

    public function testDefaultFloorIsTls10AndOffersEverythingUpTo13(): void
    {
        $mask = $this->server()->tlsCryptoMethod();
        self::assertSame(
            self::TLS10 | self::TLS11 | self::TLS12 | $this->tls13(),
            $mask,
            'default (no env) must match the historical 1.0/1.1/1.2 set plus 1.3'
        );
    }

    public function testRaisingTheFloorDropsTheOlderVersions(): void
    {
        $_ENV['TELNET_TLS_MIN_VERSION'] = '1.2';
        self::assertSame(self::TLS12 | $this->tls13(), $this->server()->tlsCryptoMethod());

        $_ENV['TELNET_TLS_MIN_VERSION'] = '1.3';
        self::assertSame($this->tls13() ?: self::TLS12, $this->server()->tlsCryptoMethod());

        $_ENV['TELNET_TLS_MIN_VERSION'] = '1.1';
        self::assertSame(
            self::TLS11 | self::TLS12 | $this->tls13(),
            $this->server()->tlsCryptoMethod()
        );
    }

    public function testInvalidFloorFallsBackToTls10NeverEmpty(): void
    {
        foreach (['9.9', 'garbage', 'TLSv1.2', '', '  '] as $bad) {
            $_ENV['TELNET_TLS_MIN_VERSION'] = $bad;
            $mask = $this->server()->tlsCryptoMethod();
            self::assertNotSame(0, $mask, "value '$bad' must not yield an empty mask");
            self::assertSame(
                self::TLS10 | self::TLS11 | self::TLS12 | $this->tls13(),
                $mask,
                "value '$bad' must fall back to the 1.0 floor"
            );
        }
    }

    public function testCipherListDefaultAndOverride(): void
    {
        self::assertSame('DEFAULT:@SECLEVEL=0', $this->server()->tlsCipherList());

        $_ENV['TELNET_TLS_CIPHERS'] = 'ECDHE+AESGCM:!aNULL';
        self::assertSame('ECDHE+AESGCM:!aNULL', $this->server()->tlsCipherList());

        $_ENV['TELNET_TLS_CIPHERS'] = '   ';
        self::assertSame('DEFAULT:@SECLEVEL=0', $this->server()->tlsCipherList());
    }

    /**
     * @return array{0:string,1:string} [certPath, keyPath]
     */
    private function writePair(string $dir, string $name = 'a'): array
    {
        $pk = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $pk);
        $crt = openssl_csr_sign($csr, null, $pk, 1);
        openssl_x509_export($crt, $certPem);
        openssl_pkey_export($pk, $keyPem);
        $certPath = "$dir/$name.crt";
        $keyPath = "$dir/$name.key";
        file_put_contents($certPath, $certPem);
        file_put_contents($keyPath, $keyPem);
        return [$certPath, $keyPath];
    }

    private function explicitPairUsable(string $cert, string $key): bool
    {
        $server = $this->server();
        foreach (['tlsCert' => $cert, 'tlsKey' => $key] as $prop => $val) {
            $p = new \ReflectionProperty($server, $prop);
            $p->setAccessible(true);
            $p->setValue($server, $val);
        }
        $m = new \ReflectionMethod($server, 'explicitTlsPairUsable');
        $m->setAccessible(true);
        return (bool) $m->invoke($server);
    }

    public function testExplicitCertKeyPairValidation(): void
    {
        $dir = sys_get_temp_dir() . '/tlspair_' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            [$cert, $key] = $this->writePair($dir, 'good');
            self::assertTrue($this->explicitPairUsable($cert, $key), 'a matching PEM pair is usable');

            self::assertFalse(
                $this->explicitPairUsable("$dir/missing.crt", $key),
                'a nonexistent certificate path is rejected'
            );

            file_put_contents("$dir/junk.crt", "not a certificate\n");
            self::assertFalse(
                $this->explicitPairUsable("$dir/junk.crt", $key),
                'a non-PEM certificate file is rejected'
            );

            [, $otherKey] = $this->writePair($dir, 'other');
            self::assertFalse(
                $this->explicitPairUsable($cert, $otherKey),
                'a cert and an unrelated key are rejected'
            );
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }
}
