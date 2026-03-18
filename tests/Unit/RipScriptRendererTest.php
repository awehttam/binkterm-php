<?php

use BinktermPHP\RipScriptRenderer;
use PHPUnit\Framework\TestCase;

class RipScriptRendererTest extends TestCase
{
    public function testHtmlRendersSvgWithLinesAndText(): void
    {
        $renderer = new RipScriptRenderer("!|c01|L0C0CHG0C\n!|c14|@3C16<Hello>");
        $html = $renderer->getHTML();

        $this->assertStringContainsString('rip-script-renderer', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('<line ', $html);
        $this->assertStringContainsString('&lt;Hello&gt;', $html);
    }

    public function testAnsiRendersBoxDrawingAndText(): void
    {
        $renderer = new RipScriptRenderer(implode("\n", [
            '!|c01|L0C0CHG0C|LHG0CHG9E|LHG9E0C9E|L0C9E0C0C',
            '!|c14|@3C16CLAUDE\'S BBS',
        ]));
        $ansi = $renderer->getAnsi();

        $this->assertStringContainsString("\033[38;2;", $ansi);
        $this->assertStringContainsString('CLAUDE\'S BBS', $ansi);
        $this->assertMatchesRegularExpression('/[┌┐└┘│─]/u', $ansi);
    }

    public function testPlainTextRoundTripsNormalizedInput(): void
    {
        $renderer = new RipScriptRenderer("one\r\ntwo\rthree");

        $this->assertSame("one\ntwo\nthree", $renderer->getPlainText());
    }

    public function testHtmlRendersFilledAndOutlineRectangles(): void
    {
        $renderer = new RipScriptRenderer(implode("\n", [
            '!|c09|B0C0C1S1S',
            '!|c14|R0C0C1S1S',
        ]));

        $html = $renderer->getHTML();

        $this->assertStringContainsString('fill="#00ffff"', $html);
        $this->assertStringContainsString('fill="none"', $html);
        $this->assertStringContainsString('stroke="#8e8e8e"', $html);
    }

    public function testAnsiApproximatesDiagonalLinesAndFilledAreas(): void
    {
        $renderer = new RipScriptRenderer(implode("\n", [
            '!|c10|L0C0C1S1S',
            '!|c12|B1E0C2A0Q',
        ]));

        $ansi = $renderer->getAnsi();

        $this->assertStringContainsString('•', $ansi);
        $this->assertStringContainsString('█', $ansi);
    }

    public function testHtmlRendersCirclesAndEllipses(): void
    {
        $renderer = new RipScriptRenderer(implode("\n", [
            '!|c11|C40400C',
            '!|c13|O1E0C3C1S',
        ]));

        $html = $renderer->getHTML();

        $this->assertStringContainsString('<circle ', $html);
        $this->assertStringContainsString('<ellipse ', $html);
        $this->assertStringContainsString('stroke="#ff40ff"', $html);
        $this->assertStringContainsString('stroke="#ffff40"', $html);
    }

    public function testAnsiApproximatesEllipticGeometry(): void
    {
        $renderer = new RipScriptRenderer(implode("\n", [
            '!|c11|C40400C',
            '!|c13|O1E0C3C1S',
        ]));

        $ansi = $renderer->getAnsi();

        $this->assertStringContainsString('•', $ansi);
        $this->assertStringContainsString("\033[38;2;", $ansi);
    }

    public function testHtmlRendersFilledEllipseAndPolygons(): void
    {
        $renderer = new RipScriptRenderer(implode("\n", [
            '!|c10|E1E0C3C1S',
            '!|c12|P031E0C3C0C2N1S',
            '!|c09|F031E0Q2A0Q241S',
        ]));

        $html = $renderer->getHTML();

        $this->assertStringContainsString('<ellipse ', $html);
        $this->assertStringContainsString('fill="#ff4040"', $html);
        $this->assertStringContainsString('<polygon ', $html);
        $this->assertStringContainsString('stroke="#40ff40"', $html);
        $this->assertStringContainsString('fill="#00ffff"', $html);
    }

    public function testAnsiApproximatesFilledEllipseAndPolygonFill(): void
    {
        $renderer = new RipScriptRenderer(implode("\n", [
            '!|c10|E1E0C3C1S',
            '!|c09|F031E0Q2A0Q241S',
        ]));

        $ansi = $renderer->getAnsi();

        $this->assertStringContainsString('█', $ansi);
        $this->assertStringContainsString("\033[38;2;", $ansi);
    }

    public function testHtmlRendersSpecLikeFilledCircleOvalAndArc(): void
    {
        $renderer = new RipScriptRenderer(implode("\n", [
            '!|c10|G40400C',
            '!|c11|o40400A06',
            '!|c14|O404000900C06',
        ]));

        $html = $renderer->getHTML();

        $this->assertStringContainsString('<circle ', $html);
        $this->assertStringContainsString('fill="#ff4040"', $html);
        $this->assertStringContainsString('<ellipse ', $html);
        $this->assertStringContainsString('fill="#ff40ff"', $html);
        $this->assertStringContainsString('<path d="M ', $html);
    }

    public function testFilledPolygonLowercaseAliasAndArcAppearInAnsi(): void
    {
        $renderer = new RipScriptRenderer(implode("\n", [
            '!|c09|p031E0Q2A0Q241S',
            '!|c14|O404000900C06',
        ]));

        $ansi = $renderer->getAnsi();

        $this->assertStringContainsString('█', $ansi);
        $this->assertStringContainsString('•', $ansi);
    }
    public function testTextCanContainEscapedAndDoubledPipes(): void
    {
        $renderer = new RipScriptRenderer("!|c14|@3C16A\\|B||C");

        $html = $renderer->getHTML();

        $this->assertStringContainsString('A|B|C', html_entity_decode($html, ENT_QUOTES, 'UTF-8'));
    }

    public function testTextStopsAtRealNextOpcodeButNotOpcodeLikeContentWithoutPipe(): void
    {
        $renderer = new RipScriptRenderer('!|c14|@3C16hello c01 not-a-command|c10|L0C0CHG0C');

        $html = $renderer->getHTML();

        $this->assertStringContainsString('hello c01 not-a-command', html_entity_decode($html, ENT_QUOTES, 'UTF-8'));
        $this->assertStringContainsString('<line ', $html);
    }

    public function testTextDecodesEscapedNewlinesTabsAndBackslashes(): void
    {
        $renderer = new RipScriptRenderer('!|c14|@3C16Line1\nLine2\t\\Done');

        $html = $renderer->getHTML();
        $decoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        $this->assertStringContainsString('Line1', $decoded);
        $this->assertStringContainsString('Line2', $decoded);
        $this->assertStringContainsString('\\Done', $decoded);
        $this->assertGreaterThanOrEqual(2, substr_count($html, '<text '));
    }
}
