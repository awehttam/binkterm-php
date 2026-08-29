<?php

declare(strict_types=1);

use BinktermPHP\Nntp\NntpArticleBuilder;
use PHPUnit\Framework\TestCase;

final class NntpArticleBuilderTest extends TestCase
{
    private function builder(): NntpArticleBuilder
    {
        // A throwaway PDO — the methods under test never touch it.
        return new NntpArticleBuilder(new PDO('sqlite::memory:'), 'bbs.example.org');
    }

    public function testFromHeaderSynthesisWithFtnAddress(): void
    {
        $from = $this->builder()->fromHeader('John Gonzales', '1:267/331');
        self::assertSame('"John Gonzales" (1:267/331.0) <john.gonzales@f331.n267.z1.fidonet>', $from);
    }

    public function testFromHeaderWithPoint(): void
    {
        $from = $this->builder()->fromHeader('Kludge', '227:1/200.5');
        self::assertStringContainsString('(227:1/200.5)', $from);
        self::assertStringContainsString('<kludge@p5.f200.n1.z227.fidonet>', $from);
    }

    public function testFromHeaderUnparseableAddressStillValid(): void
    {
        $from = $this->builder()->fromHeader('Some One', 'garbage');
        self::assertStringContainsString('<some.one@unknown.fidonet>', $from);
    }

    public function testEncodeHeaderPassesAsciiThrough(): void
    {
        $b = $this->builder();
        self::assertSame('Plain Subject', $b->encodeHeader('Plain Subject'));
        self::assertSame('=?UTF-8?B?' . base64_encode('café') . '?=', $b->encodeHeader('café'));
    }

    public function testKludgeValueExtraction(): void
    {
        $kludges = "\x01CHRS: CP437 2\n\x01PID: Mystic 1.12\n\x01MSGID: 1:2/3 ABCD";
        $b = $this->builder();
        self::assertSame('CP437 2', $b->kludgeValue($kludges, 'CHRS'));
        self::assertSame('Mystic 1.12', $b->kludgeValue($kludges, 'PID'));
        self::assertNull($b->kludgeValue($kludges, 'REPLY'));
    }

    public function testMultiLineKludgeReturnsEverySeenByLine(): void
    {
        $bottom = "SEEN-BY: 1/2 3/4\nSEEN-BY: 5/6\n\x01PATH: 1/2 3/4";
        $b = $this->builder();
        self::assertSame(['1/2 3/4', '5/6'], $b->multiLineKludge($bottom, 'SEEN-BY'));
        self::assertSame(['1/2 3/4'], $b->multiLineKludge($bottom, 'PATH'));
    }

    public function testRfcDateFormatsUtc(): void
    {
        $d = $this->builder()->rfcDate('2026-02-05 01:42:03', null);
        self::assertSame('Thu, 05 Feb 2026 01:42:03 +0000', $d);
    }

    public function testRfcDateFallsBackWhenPrimaryMissing(): void
    {
        $d = $this->builder()->rfcDate(null, '2026-03-01 12:00:00');
        self::assertSame('Sun, 01 Mar 2026 12:00:00 +0000', $d);
    }

    public function testNormalizeBodyStripsBomAndTrailingNewlinesAndNormalizesEol(): void
    {
        $b = $this->builder();
        self::assertSame("a\nb", $b->normalizeBody("\xEF\xBB\xBFa\r\nb\r\n\r\n"));
    }

    public function testWireMetricsCountsBodyLines(): void
    {
        [, $lines] = $this->builder()->wireMetrics(['From: x', 'Subject: y'], "one\ntwo\nthree");
        self::assertSame(3, $lines);
    }
}
