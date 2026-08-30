<?php

declare(strict_types=1);

use BinktermPHP\Nntp\NntpQuoteStyle;
use PHPUnit\Framework\TestCase;

final class NntpQuoteStyleTest extends TestCase
{
    // ── toRfc: FSC-0032 -> "> " ────────────────────────────────────────────

    public function testToRfcRewritesSingleLevelInitialsPrefix(): void
    {
        self::assertSame(
            "> the original text\nplain reply",
            NntpQuoteStyle::toRfc(" MA> the original text\nplain reply")
        );
    }

    public function testToRfcPreservesDepthFromStackedGt(): void
    {
        self::assertSame(">> deep", NntpQuoteStyle::toRfc(" MA>> deep"));
    }

    public function testToRfcCollapsesMultiSegmentPrefixToDepth(): void
    {
        self::assertSame(">> x", NntpQuoteStyle::toRfc(" MA> JS> x"));
    }

    public function testToRfcLeavesBareGtAndArtAlone(): void
    {
        self::assertSame("> already", NntpQuoteStyle::toRfc("> already"));
        self::assertSame(">>>---> zoom", NntpQuoteStyle::toRfc(">>>---> zoom"));
    }

    public function testToRfcHandlesEmptyQuotedLine(): void
    {
        self::assertSame(">", NntpQuoteStyle::toRfc(" MA>"));
    }

    // ── toFtn: "> " -> FSC-0032 ────────────────────────────────────────────

    public function testToFtnAddsInitialsPrefix(): void
    {
        self::assertSame(
            " JG> hello there\nmy reply",
            NntpQuoteStyle::toFtn("> hello there\nmy reply", 'John Gonzales')
        );
    }

    public function testToFtnPreservesDepth(): void
    {
        self::assertSame(" JG>> nested", NntpQuoteStyle::toFtn(">> nested", 'John Gonzales'));
    }

    public function testToFtnSingleTokenNameUsesTwoLetters(): void
    {
        self::assertSame(" SN> hi", NntpQuoteStyle::toFtn("> hi", 'SNap'));
    }

    public function testToFtnLeavesExistingFtnPrefixAlone(): void
    {
        self::assertSame(" MA> already ftn", NntpQuoteStyle::toFtn(" MA> already ftn", 'John Gonzales'));
    }

    public function testToFtnNoOpWhenAuthorHasNoInitials(): void
    {
        self::assertSame("> hi", NntpQuoteStyle::toFtn("> hi", '   '));
    }

    // ── guards ────────────────────────────────────────────────────────────

    public function testFencedCodeBlocksAreUntouched(): void
    {
        $in = "```\n MA> not a quote in code\n```\n MA> real quote";
        self::assertSame(
            "```\n MA> not a quote in code\n```\n> real quote",
            NntpQuoteStyle::toRfc($in)
        );
    }

    public function testLinesWithEscBytesAreUntouched(): void
    {
        $art = "\x1b[31m MA> colored\x1b[0m";
        self::assertSame($art, NntpQuoteStyle::toRfc($art));
    }

    public function testRoundTripPreservesDepthThoughNotAttribution(): void
    {
        $ftn = " AB> one\n AB>> two\nbody";
        $rfc = NntpQuoteStyle::toRfc($ftn);
        self::assertSame("> one\n>> two\nbody", $rfc);
        self::assertSame(" CD> one\n CD>> two\nbody", NntpQuoteStyle::toFtn($rfc, 'Carol Danvers'));
    }

    // ── initials ──────────────────────────────────────────────────────────

    public function testInitials(): void
    {
        self::assertSame('JG', NntpQuoteStyle::initials('John Gonzales'));
        self::assertSame('SN', NntpQuoteStyle::initials('SNap'));
        self::assertSame('JD', NntpQuoteStyle::initials('John Q. Doe'));
        self::assertSame('', NntpQuoteStyle::initials(''));
    }
}
