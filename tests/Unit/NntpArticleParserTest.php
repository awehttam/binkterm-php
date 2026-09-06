<?php

declare(strict_types=1);

use BinktermPHP\Nntp\NntpArticleParser;
use PHPUnit\Framework\TestCase;

final class NntpArticleParserTest extends TestCase
{
    public function testParsesHeadersAndBody(): void
    {
        $raw = "From: alice <a@b>\r\nNewsgroups: FidoNet.TEST\r\nSubject: Hi there\r\n\r\nline one\r\nline two\r\n";
        $p = NntpArticleParser::parse($raw);

        self::assertSame('FidoNet.TEST', $p['headers']['newsgroups']);
        self::assertSame('Hi there', $p['headers']['subject']);
        self::assertSame("line one\nline two", $p['body']);
    }

    public function testUnfoldsContinuationLines(): void
    {
        $raw = "Subject: a very\r\n long subject\r\nNewsgroups: X.Y\r\n\r\nbody";
        $p = NntpArticleParser::parse($raw);
        self::assertSame('a very long subject', $p['headers']['subject']);
    }

    public function testUndotStuffsBody(): void
    {
        $raw = "Subject: x\r\nNewsgroups: X.Y\r\n\r\n..hidden dot\r\nnormal";
        $p = NntpArticleParser::parse($raw);
        self::assertSame(".hidden dot\nnormal", $p['body']);
    }

    public function testTokenListSplitsCommaAndWhitespace(): void
    {
        self::assertSame(['A.B', 'C.D'], NntpArticleParser::tokenList('A.B, C.D'));
        self::assertSame(['<1@h>', '<2@h>'], NntpArticleParser::tokenList('<1@h> <2@h>'));
        self::assertSame([], NntpArticleParser::tokenList(null));
    }

    public function testLastReferencePrefersReferencesThenInReplyTo(): void
    {
        self::assertSame('<c@h>', NntpArticleParser::lastReference(['references' => '<a@h> <b@h> <c@h>']));
        self::assertSame('<x@h>', NntpArticleParser::lastReference(['in-reply-to' => 'someone wrote <x@h>']));
        self::assertNull(NntpArticleParser::lastReference([]));
    }

    public function testIsControlDetectsControlAndSupersedes(): void
    {
        self::assertTrue(NntpArticleParser::isControl(['control' => 'cancel <a@h>']));
        self::assertTrue(NntpArticleParser::isControl(['supersedes' => '<a@h>']));
        self::assertFalse(NntpArticleParser::isControl(['subject' => 'normal']));
    }
}
