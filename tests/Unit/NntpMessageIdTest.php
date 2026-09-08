<?php

declare(strict_types=1);

use BinktermPHP\Nntp\NntpMessageId;
use PHPUnit\Framework\TestCase;

final class NntpMessageIdTest extends TestCase
{
    public function testBuildFoldsInAddressAndSerial(): void
    {
        $id = NntpMessageId::build('227:1/200 6A08C568', 'LovlyNet.LVLY_BINKTERMPHP', 'bbs.example.org');
        self::assertSame('<6A08C568.z227n1f200p0.lovlynet.lvly_binktermphp@bbs.example.org>', $id);
    }

    public function testBuildHandlesPointAndDomainSuffix(): void
    {
        $id = NntpMessageId::build('227:1/200.5@lovlynet 1B2C3D4E', 'X.Y', 'h');
        self::assertStringContainsString('.z227n1f200p5.', $id);
        self::assertStringStartsWith('<1B2C3D4E.', $id);
    }

    public function testParseRoundTripsBuild(): void
    {
        $id = NntpMessageId::build('1:123/456 DEADBEEF', 'FidoNet.SOME.AREA', 'host.tld');
        $parsed = NntpMessageId::parse($id);

        self::assertNotNull($parsed);
        self::assertSame('DEADBEEF', $parsed['serial']);
        self::assertSame('z1n123f456p0', $parsed['address_token']);
        self::assertSame('fidonet.some.area', $parsed['group']);
        self::assertSame('host.tld', $parsed['host']);
        self::assertSame('1:123/456', NntpMessageId::decodeAddressToken($parsed['address_token']));
    }

    public function testParseRejectsNonBrackets(): void
    {
        self::assertNull(NntpMessageId::parse('not-a-message-id'));
        self::assertNull(NntpMessageId::parse('<no-at-sign>'));
    }

    public function testSyntheticIdIsStableAndDependsOnContentNotTranslation(): void
    {
        $a = NntpMessageId::buildSynthetic("\x01CHRS: UTF-8 4", "hello world", '1:2/3', 'N.A', 'h');
        $b = NntpMessageId::buildSynthetic("\x01CHRS: UTF-8 4", "hello world", '1:2/3', 'N.A', 'h');
        $c = NntpMessageId::buildSynthetic("\x01CHRS: UTF-8 4", "hello there", '1:2/3', 'N.A', 'h');

        self::assertSame($a, $b, 'same raw content must yield the same synthetic id');
        self::assertNotSame($a, $c, 'different body must yield a different synthetic id');
        self::assertStringContainsString('.z1n2f3p0.', $a);
    }

    public function testSyntheticIdIsScopedToOriginAddress(): void
    {
        $a = NntpMessageId::buildSynthetic('k', 'same body', '1:2/3', 'N.A', 'h');
        $b = NntpMessageId::buildSynthetic('k', 'same body', '9:9/9', 'N.A', 'h');
        self::assertNotSame($a, $b);
    }

    public function testEncodeAddressTokenFallsBackForGarbage(): void
    {
        self::assertStringStartsWith('x', NntpMessageId::encodeAddressToken('not-an-address'));
        self::assertNull(NntpMessageId::decodeAddressToken('x123'));
    }

    public function testParseRawMsgidExtractsSerialAsLastToken(): void
    {
        $parts = NntpMessageId::parseRawMsgid('  1:234/56.7  ABCDEF01 ');
        self::assertNotNull($parts);
        self::assertSame('1:234/56.7', $parts['address']);
        self::assertSame('ABCDEF01', $parts['serial']);
    }
}
