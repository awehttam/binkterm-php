<?php

declare(strict_types=1);

use BinktermPHP\Nntp\NntpArticleBuilder;
use BinktermPHP\Nntp\NntpNetmailPost;
use PHPUnit\Framework\TestCase;

/**
 * Address parsing for the netmail POST path — the static helpers that invert a
 * `To:` header back to an FTN address. Pure functions, no DB.
 */
final class NntpNetmailPostTest extends TestCase
{
    public function testIsFtnAddress(): void
    {
        self::assertTrue(NntpNetmailPost::isFtnAddress('21:1/100'));
        self::assertTrue(NntpNetmailPost::isFtnAddress('21:1/100.5'));
        self::assertFalse(NntpNetmailPost::isFtnAddress('not-an-address'));
        self::assertFalse(NntpNetmailPost::isFtnAddress('21:1'));
    }

    public function testAddressFromDisplayComment(): void
    {
        $to = '"Jane Doe" (21:1/100.5) <jane.doe@p5.f100.n1.z21.fidonet>';
        self::assertSame('21:1/100.5', NntpNetmailPost::addressFromToHeader($to));
    }

    public function testAddressFromHostForm(): void
    {
        // No display comment — must fall back to inverting the host form.
        $to = 'Jane Doe <jane.doe@f100.n1.z21.fidonet>';
        self::assertSame('21:1/100', NntpNetmailPost::addressFromToHeader($to));
    }

    public function testAddressFromHostFormWithPoint(): void
    {
        $to = '<x@p7.f200.n2.z21.lovlynet>';
        self::assertSame('21:2/200.7', NntpNetmailPost::addressFromToHeader($to));
    }

    public function testAddressFromToHeaderReturnsNullWhenUnparseable(): void
    {
        self::assertNull(NntpNetmailPost::addressFromToHeader('Jane Doe <jane@example.org>'));
    }

    public function testDisplayName(): void
    {
        self::assertSame('Jane Doe', NntpNetmailPost::displayName('"Jane Doe" (21:1/100) <j@h>'));
        self::assertSame('Jane Doe', NntpNetmailPost::displayName('Jane Doe <j@h>'));
        self::assertSame('', NntpNetmailPost::displayName('<j@h>'));
        // A client that quotes the whole value leaves an unbalanced quote once
        // the parser splits on "<" — it must not leak into the name.
        self::assertSame('awehttam', NntpNetmailPost::displayName('"awehttam <227:1/200>"'));
        self::assertSame('awehttam', NntpNetmailPost::cleanName('"awehttam'));
        self::assertSame('O\'Brien', NntpNetmailPost::cleanName('"O\'Brien"'));
    }

    /**
     * The forms this test inverts are exactly what the builder emits, so a
     * round trip through fromHeader() -> addressFromToHeader() is lossless.
     */
    public function testRoundTripThroughBuilderFromHeader(): void
    {
        $builder = new NntpArticleBuilder(new PDO('sqlite::memory:'), 'h');
        foreach (['21:1/100', '21:1/100.5', '3:770/1'] as $addr) {
            $header = $builder->fromHeader('Some One', $addr, 'fidonet');
            self::assertSame($addr, NntpNetmailPost::addressFromToHeader($header), "round trip for {$addr}");
        }
    }
}
