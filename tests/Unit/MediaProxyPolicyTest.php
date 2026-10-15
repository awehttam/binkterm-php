<?php

declare(strict_types=1);

use BinktermPHP\Security\MediaProxyPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The media proxy policy (no network: the resolver is injected).
 */
final class MediaProxyPolicyTest extends TestCase
{
    private function policy(array $answers): MediaProxyPolicy
    {
        return new MediaProxyPolicy(static fn (string $host): array => $answers[$host] ?? []);
    }

    public function testPublicHostIsPinnedToEveryCheckedAddress(): void
    {
        $pin = $this->policy(['music.example' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']])
            ->pinFor('https://music.example/songs/a.MOD');
        self::assertNotNull($pin);
        self::assertSame(443, $pin['port']);
        self::assertSame(['music.example:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]'], MediaProxyPolicy::curlResolve($pin));
    }

    public function testAnyNonPublicAnswerRefusesTheHost(): void
    {
        // An AAAA record the old IPv4-only check never saw.
        self::assertNull($this->policy(['mixed.example' => ['93.184.216.34', '::1']])->pinFor('http://mixed.example/a.xm'));
        self::assertNull($this->policy(['mixed.example' => ['93.184.216.34', 'fd00::5']])->pinFor('http://mixed.example/a.xm'));
        self::assertNull($this->policy(['v4.example' => ['10.0.0.8']])->pinFor('http://v4.example/a.xm'));
        self::assertNull($this->policy([])->pinFor('http://nxdomain.example/a.xm'));
    }

    /** @dataProvider nonPublic */
    public function testNonPublicAddressesAreRefused(string $ip): void
    {
        self::assertFalse(MediaProxyPolicy::isPublicIp($ip), $ip);
    }

    public static function nonPublic(): array
    {
        return array_map(static fn ($ip) => [$ip], ['127.0.0.1', '10.1.2.3', '172.31.119.10', '192.168.1.1', '169.254.169.254',
            '100.64.0.1', '100.127.255.254', '0.0.0.0', '::1', '::', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', '::ffff:93.184.216.34',
            '64:ff9b::7f00:1', '2001:db8::1', '::7f00:1']);
    }

    public function testPublicAddressesPass(): void
    {
        foreach (['93.184.216.34', '1.1.1.1', '100.128.0.1', '2606:4700:4700::1111'] as $ip) {
            self::assertTrue(MediaProxyPolicy::isPublicIp($ip), $ip);
        }
    }

    public function testUrlShapeRules(): void
    {
        $p = $this->policy(['music.example' => ['93.184.216.34']]);
        self::assertNotNull($p->pinFor('http://music.example/a.mid'));
        self::assertNotNull($p->pinFor('https://music.example:443/a.mid'));
        self::assertNull($p->pinFor('http://music.example:8080/a.mid'), 'non-standard port');
        self::assertNull($p->pinFor('https://music.example:80/a.mid'), 'https on 80');
        self::assertNull($p->pinFor('ftp://music.example/a.mid'), 'scheme');
        self::assertNull($p->pinFor('http://user:pw@music.example/a.mid'), 'userinfo');
        self::assertNull($p->pinFor('http://music.example/a.html'), 'extension');
        self::assertNull($p->pinFor('http://music.example/a.mid.html'), 'trailing extension decides');
        self::assertNull($p->pinFor('http://localhost/a.mid'), 'localhost');
        self::assertNull($p->pinFor('http://x.localhost/a.mid'), '*.localhost');
        self::assertNull($p->pinFor('http://127.0.0.1/a.mid'), 'loopback literal');
        self::assertNull($p->pinFor('http://[::ffff:7f00:1]/a.mid'), 'mapped literal');
        self::assertNotNull($p->pinFor('http://93.184.216.34/a.mid'), 'public literal');
    }

    public function testResponseHeadersNeverRenderRemoteContent(): void
    {
        $h = MediaProxyPolicy::responseHeaders("evil\".html\r\nX-Injected: 1", 42);
        self::assertContains('Content-Type: application/octet-stream', $h);
        self::assertContains('X-Content-Type-Options: nosniff', $h);
        self::assertContains("Content-Security-Policy: sandbox; default-src 'none'", $h);
        $disp = array_values(array_filter($h, static fn ($l) => str_starts_with($l, 'Content-Disposition:')))[0];
        self::assertStringStartsWith('Content-Disposition: attachment; filename="', $disp);
        foreach ($h as $line) {
            self::assertStringNotContainsString("\r", $line);
            self::assertStringNotContainsString("\n", $line);
            self::assertStringNotContainsString('text/html', $line);
        }
        self::assertSame(1, substr_count($disp, '"', strlen('Content-Disposition: attachment; filename=')) - 1);
    }

    /** RFC 3986 section 5.4 examples (base http://a/b/c/d;p?q), plus the redirect shapes media hosts use. */
    public function testRedirectReferencesResolvePerRfc3986(): void
    {
        $base = 'http://a/b/c/d;p?q';
        $cases = ['g:h' => 'g:h', 'g' => 'http://a/b/c/g', './g' => 'http://a/b/c/g', 'g/' => 'http://a/b/c/g/', '/g' => 'http://a/g',
            '//g' => 'http://g', '?y' => 'http://a/b/c/d;p?y', 'g?y' => 'http://a/b/c/g?y', '#s' => 'http://a/b/c/d;p?q',
            'g#s' => 'http://a/b/c/g', '..' => 'http://a/b/', '../g' => 'http://a/b/g', '../..' => 'http://a/', '../../g' => 'http://a/g',
            '../../../g' => 'http://a/g', '/./g' => 'http://a/g', '/../g' => 'http://a/g', 'g.' => 'http://a/b/c/g.', 'g..' => 'http://a/b/c/g..',
            './../g' => 'http://a/b/g', 'g/./h' => 'http://a/b/c/g/h', 'g/../h' => 'http://a/b/c/h'];
        foreach ($cases as $ref => $want) {
            self::assertSame($want, MediaProxyPolicy::resolveReference($base, $ref), $ref);
        }
        self::assertSame('https://cdn.example/new/a.mid', MediaProxyPolicy::resolveReference('https://music.example/old/a.mid', '//cdn.example/new/a.mid'));
        self::assertSame('https://music.example/new/a.mid', MediaProxyPolicy::resolveReference('https://music.example/old/a.mid', '/new/a.mid'));
        self::assertSame('https://music.example/old/b.mid', MediaProxyPolicy::resolveReference('https://music.example/old/a.mid', 'b.mid'));
        self::assertSame('', MediaProxyPolicy::resolveReference('https://music.example/a.mid', "x\r\ny.mid"));
        self::assertSame('https://[2606:4700:4700::1111]/new/a.mid', MediaProxyPolicy::resolveReference('https://[2606:4700:4700::1111]/old/a.mid', '/new/a.mid'));
        self::assertSame('http://[2606:4700:4700::1111]:80/old/b.mid', MediaProxyPolicy::resolveReference('http://[2606:4700:4700::1111]:80/old/a.mid', 'b.mid'));
        self::assertNotNull($this->policy([])->pinFor(MediaProxyPolicy::resolveReference('https://[2606:4700:4700::1111]/old/a.mid', '/new/a.mid')));
    }

    public function testIpLiteralNeedsNoResolveAndAddressesCompareInBinary(): void
    {
        $p = $this->policy([]);
        $pin = $p->pinFor('http://[2606:2800:220:1:248:1893:25c8:1946]/a.mid');
        self::assertNotNull($pin);
        self::assertSame([], MediaProxyPolicy::curlResolve($pin));
        self::assertTrue(MediaProxyPolicy::isPinnedAddress($pin, '2606:2800:0220:0001:0248:1893:25c8:1946'));
        self::assertTrue(MediaProxyPolicy::isPinnedAddress($pin, '[2606:2800:220:1:248:1893:25c8:1946]'));
        self::assertFalse(MediaProxyPolicy::isPinnedAddress($pin, '2606:2800:220:1:248:1893:25c8:1947'));
        self::assertFalse(MediaProxyPolicy::isPinnedAddress($pin, 'garbage'));
    }
}
