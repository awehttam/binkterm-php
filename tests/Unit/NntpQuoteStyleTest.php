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

    // ── toFtnAgainstParent: reconstruct from the canonical parent ──────────

    public function testAgainstParentPreservesPerAuthorAttribution(): void
    {
        // Parent (stored echomail) — foobar answering awehttamdev.
        $parent = " AW> the grandparent question\nfoobar's answer here";

        // Newsreader reply: parent went out as toRfc($parent), the client
        // quoted it one level deeper, then the user added a line.
        $article = "On some date, foobar wrote:\n"
            . ">> the grandparent question\n"
            . "> foobar's answer here\n"
            . "\n"
            . "my new reply";

        self::assertSame(
            "On some date, foobar wrote:\n"
            . " AW>> the grandparent question\n"
            . " FO> foobar's answer here\n"
            . "\n"
            . "my new reply",
            NntpQuoteStyle::toFtnAgainstParent($article, 'foobar', $parent)
        );
    }

    public function testAgainstParentIncrementsEveryExistingQuoteLevel(): void
    {
        // Parent already carries a two-deep chain; each level must gain one '>'.
        $parent = " JS>> oldest point\n JS> awehttamdev replied\nfoobar wrote this";

        $article = "On some date, foobar wrote:\n"
            . ">>> oldest point\n"
            . ">> awehttamdev replied\n"
            . "> foobar wrote this\n"
            . "\n"
            . "and here is my answer";

        self::assertSame(
            "On some date, foobar wrote:\n"
            . " JS>>> oldest point\n"
            . " JS>> awehttamdev replied\n"
            . " FO> foobar wrote this\n"
            . "\n"
            . "and here is my answer",
            NntpQuoteStyle::toFtnAgainstParent($article, 'foobar', $parent)
        );
    }

    public function testAgainstParentFallsBackWhenQuoteIsInterleaved(): void
    {
        // Two quoted blocks => inline replies; never disturb them, just flat-attribute.
        $article = "> alpha bravo charlie\n\nmid comment\n\n> delta echo foxtrot";
        $parent = "alpha bravo charlie\ndelta echo foxtrot";

        self::assertSame(
            " CD> alpha bravo charlie\n\nmid comment\n\n CD> delta echo foxtrot",
            NntpQuoteStyle::toFtnAgainstParent($article, 'Carol Danvers', $parent)
        );
    }

    public function testAgainstParentFallsBackWhenBlockDoesNotMatchParent(): void
    {
        $article = "> zulu yankee xray\n> whiskey victor uniform";
        $parent = "alpha bravo charlie delta echo foxtrot golf";

        self::assertSame(
            " CD> zulu yankee xray\n CD> whiskey victor uniform",
            NntpQuoteStyle::toFtnAgainstParent($article, 'Carol Danvers', $parent)
        );
    }

    public function testAgainstParentFallsBackWhenParentEmpty(): void
    {
        self::assertSame(
            " CD> hi there",
            NntpQuoteStyle::toFtnAgainstParent("> hi there", 'Carol Danvers', '   ')
        );
    }

    public function testAgainstParentToleratesReWrappedQuoteLines(): void
    {
        $parent = "the quick brown fox jumps over the lazy dog near the riverbank at dawn";
        // Client re-wrapped the single parent line into three quoted lines.
        $article = "> the quick brown fox jumps\n> over the lazy dog near the\n> riverbank at dawn";

        $out = NntpQuoteStyle::toFtnAgainstParent($article, 'Carol Danvers', $parent);
        self::assertStringContainsString(' CD> the quick brown fox', $out);
        self::assertStringNotContainsString('>>', $out);
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
