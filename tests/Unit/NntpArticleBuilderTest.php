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
        $from = $this->builder()->fromHeader('John Gonzales', '1:267/331', 'fidonet');
        self::assertSame('"John Gonzales" (1:267/331.0) <john.gonzales@f331.n267.z1.fidonet>', $from);
    }

    public function testFromHeaderWithPoint(): void
    {
        $from = $this->builder()->fromHeader('Kludge', '227:1/200.5', 'fidonet');
        self::assertStringContainsString('(227:1/200.5)', $from);
        self::assertStringContainsString('<kludge@p5.f200.n1.z227.fidonet>', $from);
    }

    public function testFromHeaderUsesEchoareaDomain(): void
    {
        $from = $this->builder()->fromHeader('Mistigris', '227:1/200.0', 'lovlynet');
        self::assertStringContainsString('<mistigris@f200.n1.z227.lovlynet>', $from);

        $garbled = $this->builder()->fromHeader('Some One', 'garbage', 'lovlynet');
        self::assertStringContainsString('<some.one@unknown.lovlynet>', $garbled);
    }

    public function testFromHeaderUnparseableAddressStillValid(): void
    {
        $from = $this->builder()->fromHeader('Some One', 'garbage');
        self::assertStringContainsString('<some.one@unknown.local>', $from);
    }

    public function testFromHeaderLocalAreaHasNoFtnDomain(): void
    {
        $from = $this->builder()->fromHeader('Some One', '999:1/1');
        self::assertStringContainsString('<some.one@f1.n1.z999.local>', $from);
    }

    public function testEncodeHeaderPassesAsciiThrough(): void
    {
        $b = $this->builder();
        self::assertSame('Plain Subject', $b->encodeHeader('Plain Subject'));
        self::assertSame('=?UTF-8?B?' . base64_encode('café') . '?=', $b->encodeHeader('café'));
    }

    public function testEncodeHeaderFoldsLongCyrillicIntoValidEncodedWords(): void
    {
        $subject = 'Re: Тестосообщение про подписки которые не работают вот вообще совсем никак';
        $encoded = $this->builder()->encodeHeader($subject);

        // Folded onto multiple physical lines, each a legal length, every
        // encoded-word within the RFC 2047 75-char cap.
        $lines = preg_split('/\r\n/', $encoded);
        self::assertGreaterThan(1, count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(76, strlen($line), $line);
        }
        self::assertMatchesRegularExpression('/=\?UTF-8\?B\?[A-Za-z0-9+\/=]{1,63}\?=/', $encoded);
        self::assertDoesNotMatchRegularExpression('/=\?UTF-8\?B\?[A-Za-z0-9+\/=]{64,}\?=/', $encoded);

        // Round-trips back to the original (no character split across words).
        self::assertSame($subject, mb_decode_mimeheader($encoded));
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

    public function testEmitsXrefForThisGroupOnly(): void
    {
        $built = $this->builder()->build(
            ['id' => 5, 'from_name' => 'A', 'from_address' => '1:2/3', 'subject' => 's', 'message_text' => 'x', 'message_id' => '1:2/3 AAAA'],
            ['tag' => 'TEST'],
            'FidoNet.TEST',
            42
        );
        $xref = array_values(array_filter($built['headers'], static fn ($h) => stripos($h, 'Xref:') === 0));
        self::assertSame(['Xref: bbs.example.org FidoNet.TEST:42'], $xref);
    }

    public function testNoXrefWhenNumberIsZero(): void
    {
        $built = $this->builder()->build(
            ['id' => 5, 'from_name' => 'A', 'from_address' => '1:2/3', 'subject' => 's', 'message_text' => 'x', 'message_id' => '1:2/3 AAAA'],
            ['tag' => 'TEST'],
            'FidoNet.TEST',
            0
        );
        self::assertSame([], array_filter($built['headers'], static fn ($h) => stripos($h, 'Xref:') === 0));
    }

    public function testPrefetchedParentMapProducesSingleParentReferencesWithoutDb(): void
    {
        // No usable PDO here — if build() touched the DB for References this would throw.
        $b = new \BinktermPHP\Nntp\NntpArticleBuilder(new PDO('sqlite::memory:'), 'h');
        $built = $b->build(
            ['id' => 9, 'reply_to_id' => 4, 'from_name' => 'A', 'from_address' => '1:2/3', 'subject' => 'Re: s', 'message_text' => 'x', 'message_id' => '1:2/3 BBBB'],
            ['tag' => 'T'],
            'N.T',
            2,
            [4 => '<PARENT.z1n2f3p0.n.t@h>']
        );
        $refs = array_values(array_filter($built['headers'], static fn ($h) => stripos($h, 'References:') === 0));
        self::assertSame(['References: <PARENT.z1n2f3p0.n.t@h>'], $refs);
    }

    public function testConvertQuotesToRfcRewritesBodyWhenEnabled(): void
    {
        $b = new NntpArticleBuilder(new PDO('sqlite::memory:'), 'h', true);
        $built = $b->build(
            ['id' => 1, 'from_name' => 'A', 'from_address' => '1:2/3', 'subject' => 's',
             'message_text' => " MA> quoted line\nmy reply", 'message_id' => '1:2/3 AAAA'],
            ['tag' => 'T'],
            'N.T',
            1
        );
        self::assertSame("> quoted line\nmy reply", $built['body']);
    }

    public function testConvertQuotesOffByDefault(): void
    {
        $built = $this->builder()->build(
            ['id' => 1, 'from_name' => 'A', 'from_address' => '1:2/3', 'subject' => 's',
             'message_text' => " MA> quoted line", 'message_id' => '1:2/3 AAAA'],
            ['tag' => 'T'],
            'N.T',
            1
        );
        self::assertSame(' MA> quoted line', $built['body']);
    }

    public function testPrefetchedParentMapMissingParentYieldsNoReferences(): void
    {
        $b = new \BinktermPHP\Nntp\NntpArticleBuilder(new PDO('sqlite::memory:'), 'h');
        $built = $b->build(
            ['id' => 9, 'reply_to_id' => 4, 'from_name' => 'A', 'from_address' => '1:2/3', 'subject' => 'Re: s', 'message_text' => 'x', 'message_id' => '1:2/3 BBBB'],
            ['tag' => 'T'],
            'N.T',
            2,
            []
        );
        self::assertSame([], array_filter($built['headers'], static fn ($h) => stripos($h, 'References:') === 0));
    }
}
