<?php

require_once __DIR__ . '/../../telnet/src/AnsiCanvasRenderer.php';

use BinktermPHP\TelnetServer\AnsiCanvasRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AnsiCanvasRenderer: resolving an ANSI-art byte stream onto a
 * virtual grid and serialising it back to SGR-only display lines.
 */
class AnsiCanvasRendererTest extends TestCase
{
    /** Visible text of a rendered line (SGR stripped). */
    private static function plain(string $line): string
    {
        return (string)preg_replace('/\x1b\[[0-9;]*m/', '', $line);
    }

    public function testPlainTextPassesThrough(): void
    {
        $out = AnsiCanvasRenderer::render("hello\r\nworld", 80);
        $this->assertSame(['hello', 'world'], array_map([self::class, 'plain'], $out));
    }

    public function testAbsoluteCursorPositioningIsResolved(): void
    {
        // Write "B" at row 1 col 1, then jump to row 3 col 5 and write "X".
        $out = AnsiCanvasRenderer::render("B\x1b[3;5HX", 80);
        $plain = array_map([self::class, 'plain'], $out);
        $this->assertSame('B', $plain[0]);
        $this->assertSame('', $plain[1]);
        $this->assertSame('    X', $plain[2]);
    }

    public function testSaveRestoreCursor(): void
    {
        $out = AnsiCanvasRenderer::render("\x1b[2;3H\x1b[s\r\n\x1b[uYY", 80);
        $plain = array_map([self::class, 'plain'], $out);
        $this->assertSame('  YY', $plain[1]);
    }

    public function testNoPositioningLeaksIntoOutput(): void
    {
        $out = AnsiCanvasRenderer::render("a\x1b[5;1Hb\x1b[2J\x1b[10;10Hc\x1b[K", 80);
        foreach ($out as $line) {
            // Only SGR (ending 'm') sequences may remain.
            $this->assertDoesNotMatchRegularExpression('/\x1b\[[0-9;]*[A-LN-Za-ln-z]/', $line);
        }
    }

    public function testColourIsPreservedAsSgr(): void
    {
        $out = AnsiCanvasRenderer::render("\x1b[1;31mRED\x1b[0m", 80);
        $this->assertStringContainsString('31', $out[0]);
        $this->assertStringContainsString('1', $out[0]);
        $this->assertSame('RED', self::plain($out[0]));
        $this->assertStringEndsWith("\x1b[0m", $out[0]);
    }

    public function testLinesAreClippedToCanvasWidth(): void
    {
        $out = AnsiCanvasRenderer::render(str_repeat('x', 200), 40);
        foreach ($out as $line) {
            $this->assertLessThanOrEqual(40, mb_strwidth(self::plain($line), 'UTF-8'));
        }
        // 200 x's on a 40-wide canvas wrap to 5 full rows.
        $this->assertSame(5, count($out));
    }

    public function testMultibyteBoxDrawingSurvives(): void
    {
        $art = "\u{2554}\u{2550}\u{2557}\r\n\u{255A}\u{2550}\u{255D}";
        $out = AnsiCanvasRenderer::render($art, 80);
        $this->assertSame(["\u{2554}\u{2550}\u{2557}", "\u{255A}\u{2550}\u{255D}"], array_map([self::class, 'plain'], $out));
    }

    public function testCursorUpOverwrite(): void
    {
        // "aaa" then up one, back three, overwrite with "b".
        $out = AnsiCanvasRenderer::render("aaa\r\nccc\x1b[A\x1b[3Db", 80);
        $plain = array_map([self::class, 'plain'], $out);
        $this->assertSame('baa', $plain[0]);
        $this->assertSame('ccc', $plain[1]);
    }

    public function testHeightIsCapped(): void
    {
        $out = AnsiCanvasRenderer::render(str_repeat("\n", 5000) . 'x', 80);
        $this->assertLessThanOrEqual(1000, count($out));
    }
}
