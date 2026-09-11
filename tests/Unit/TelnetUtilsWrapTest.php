<?php

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';

use BinktermPHP\TelnetServer\TelnetUtils;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TelnetUtils::wrapTextLines() ANSI- and UTF-8-aware wrapping.
 *
 * Regression coverage for the 1.10.5 interaction where TerminalTextSanitizer
 * strips absolute cursor positioning from ANSI art, collapsing positioned
 * fragments into long logical lines that a byte-oriented wrap would hard-cut
 * mid escape-sequence (literal "[35m") or mid multi-byte glyph.
 */
class TelnetUtilsWrapTest extends TestCase
{
    public function testPlainTextWrapsAtWordBoundaries(): void
    {
        $this->assertSame(
            ['the quick', 'brown fox', 'jumps over'],
            TelnetUtils::wrapTextLines('the quick brown fox jumps over', 10)
        );
    }

    public function testEmptyAndBlankLinesArePreserved(): void
    {
        $this->assertSame([''], TelnetUtils::wrapTextLines('', 20));
        $this->assertSame(['a', '', 'b'], TelnetUtils::wrapTextLines("a\n\nb", 20));
    }

    public function testLongUnbrokenWordIsHardCutOnCharacterBoundary(): void
    {
        $this->assertSame(
            ['abcdefghij', 'klmnop'],
            TelnetUtils::wrapTextLines('abcdefghijklmnop', 10)
        );
    }

    public function testMultibyteCharactersAreNeverSplit(): void
    {
        $out = TelnetUtils::wrapTextLines(str_repeat('é', 12), 10);
        $this->assertSame([str_repeat('é', 10), 'éé'], $out);
        foreach ($out as $line) {
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'wrapped line is valid UTF-8');
        }
    }

    public function testSgrSequencesHaveZeroWidthAndAreNotSplit(): void
    {
        $this->assertSame(
            ["\x1b[31mhello", "world foo\x1b[0m"],
            TelnetUtils::wrapTextLines("\x1b[31mhello world foo\x1b[0m", 10)
        );
    }

    public function testCollapsedAnsiArtLineWrapsWithoutCorruption(): void
    {
        // ESC[35m + text + ESC[0;32m + box run + ESC[1m + box run, all on one
        // logical line (as produced after cursor positioning is sanitized out).
        $line = "\x1b[35mport:2023\x1b[0;32m" . str_repeat("\u{2500}", 20)
              . "\x1b[1m" . str_repeat("\u{2500}", 40);

        foreach (TelnetUtils::wrapTextLines($line, 78) as $wrapped) {
            $this->assertTrue(mb_check_encoding($wrapped, 'UTF-8'));
            // No escape sequence was severed: every "[NN m" is preceded by ESC.
            $this->assertDoesNotMatchRegularExpression('/(?<!\x1b)\[[0-9;]*m/', $wrapped);
            // No dangling ESC at end of a line.
            $this->assertStringEndsNotWith("\x1b", $wrapped);
            // Visible width (escapes removed) stays within the limit.
            $visible = preg_replace('/\x1b\[[0-9;:?]*[ -\/]*[@-~]/', '', $wrapped);
            $this->assertLessThanOrEqual(78, mb_strwidth($visible, 'UTF-8'));
        }
    }
}
