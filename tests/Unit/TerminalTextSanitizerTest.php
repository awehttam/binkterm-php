<?php
/**
 * PHPUnit tests for terminal escape-sequence sanitization of untrusted text.
 */

use BinktermPHP\TerminalTextSanitizer;
use PHPUnit\Framework\TestCase;

class TerminalTextSanitizerTest extends TestCase
{
    public function testEmptyStringIsUnchanged(): void
    {
        $this->assertSame('', TerminalTextSanitizer::sanitize(''));
    }

    public function testPlainTextIsUnchanged(): void
    {
        $text = "Hello, world!\nSecond line\tindented\r\n";
        $this->assertSame($text, TerminalTextSanitizer::sanitize($text));
    }

    public function testMultibyteUtf8IsPreserved(): void
    {
        $text = "café — résumé — ☕ — Ω";
        $this->assertSame($text, TerminalTextSanitizer::sanitize($text));
    }

    public function testSgrColourSequencesAreKept(): void
    {
        $this->assertSame(
            "\x1b[31mred\x1b[0m and \x1b[1;32mbold green\x1b[0m",
            TerminalTextSanitizer::sanitize("\x1b[31mred\x1b[0m and \x1b[1;32mbold green\x1b[0m")
        );
    }

    public function testExtendedSgrSequencesAreKept(): void
    {
        $this->assertSame(
            "\x1b[38;5;196mx\x1b[48;2;0;0;0my",
            TerminalTextSanitizer::sanitize("\x1b[38;5;196mx\x1b[48;2;0;0;0my")
        );
    }

    public function testCursorAndEraseSequencesAreStripped(): void
    {
        $this->assertSame('abc', TerminalTextSanitizer::sanitize("a\x1b[2Jb\x1b[10;10Hc"));
        $this->assertSame('ab', TerminalTextSanitizer::sanitize("a\x1b[1;1H\x1b[Kb"));
    }

    public function testOscSequencesAreStripped(): void
    {
        // Window title (BEL-terminated) and clipboard write (ST-terminated).
        $this->assertSame('ab', TerminalTextSanitizer::sanitize("a\x1b]0;pwned\x07b"));
        $this->assertSame('ab', TerminalTextSanitizer::sanitize("a\x1b]52;c;YmFzZTY0\x1b\\b"));
    }

    public function testDcsAndCharsetDesignationAreStripped(): void
    {
        $this->assertSame('ab', TerminalTextSanitizer::sanitize("a\x1bP\$q\"p\x1b\\b"));
        $this->assertSame('ab', TerminalTextSanitizer::sanitize("a\x1b(0b"));
    }

    public function testMalformedAndLoneEscapesAreStripped(): void
    {
        $this->assertSame('a', TerminalTextSanitizer::sanitize("a\x1b[999;999"));
        $this->assertSame('a', TerminalTextSanitizer::sanitize("a\x1b"));
        $this->assertSame('ab', TerminalTextSanitizer::sanitize("a\x1bcb"));
    }

    public function testControlBytesAreStrippedExceptWhitespace(): void
    {
        $this->assertSame("ab\tc\r\nd", TerminalTextSanitizer::sanitize("a\x00\x07\x08b\tc\r\nd"));
        $this->assertSame('ab', TerminalTextSanitizer::sanitize("a\x7fb"));
    }

    public function testUtf8EncodedC1ControlsAreStripped(): void
    {
        // 0x9B (CSI) encoded as UTF-8 C2 9B, followed by what would be its params.
        $this->assertSame('a31mb', TerminalTextSanitizer::sanitize("a\xc2\x9b31mb"));
    }

    public function testColourSurvivesAlongsideAnAttack(): void
    {
        $this->assertSame(
            "\x1b[1m\x1b[31mHI\x1b[0m",
            TerminalTextSanitizer::sanitize("\x1b[1m\x1b[31mHI\x1b[0m\x1b[2J\x1b]0;x\x07")
        );
    }

    public function testPositioningPolicyKeepsCursorAndEraseSequences(): void
    {
        $in = "\x1b[2J\x1b[5;1Hport\x1b[1;33m\x1b[K";
        $this->assertSame($in, TerminalTextSanitizer::sanitize($in, TerminalTextSanitizer::POLICY_POSITIONING));
    }

    public function testPositioningPolicyStillStripsInjectionVectors(): void
    {
        // OSC, DSR query, DA query, private-mode, DCS — all gone even in positioning mode.
        $this->assertSame(
            "\x1b[10;5Hok",
            TerminalTextSanitizer::sanitize(
                "\x1b[10;5H\x1b]0;title\x07\x1b[6n\x1b[c\x1b[?1049hok\x1bP\$q\"p\x1b\\",
                TerminalTextSanitizer::POLICY_POSITIONING
            )
        );
    }

    public function testStrictPolicyRemovesPositioningThatPositioningPolicyKeeps(): void
    {
        $this->assertSame('port', TerminalTextSanitizer::sanitize("\x1b[5;1Hport\x1b[K"));
    }

    public function testHasPositionedAnsiDetectsCursorAndEraseOnly(): void
    {
        $this->assertTrue(TerminalTextSanitizer::hasPositionedAnsi("art\x1b[5;1Hhere"));
        $this->assertTrue(TerminalTextSanitizer::hasPositionedAnsi("art\x1b[2Jhere"));
        $this->assertTrue(TerminalTextSanitizer::hasPositionedAnsi("art\x1b[sthere\x1b[u"));
        $this->assertFalse(TerminalTextSanitizer::hasPositionedAnsi("just \x1b[31mcolour\x1b[0m text"));
        $this->assertFalse(TerminalTextSanitizer::hasPositionedAnsi('plain text'));
        $this->assertFalse(TerminalTextSanitizer::hasPositionedAnsi(''));
    }
}
