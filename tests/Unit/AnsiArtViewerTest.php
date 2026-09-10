<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/AnsiArtViewer.php';

use BinktermPHP\TelnetServer\AnsiArtViewer;
use BinktermPHP\TerminalTextSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AnsiArtViewer mode resolution and the reader-policy helpers that
 * decide how the normal Telnet/SSH reader treats an ANSI-art message body.
 */
class AnsiArtViewerTest extends TestCase
{
    private ?string $savedEnv = null;
    private bool $hadEnv = false;

    protected function setUp(): void
    {
        $this->hadEnv = array_key_exists('TERM_ANSI_ART_MODE', $_ENV);
        $this->savedEnv = $this->hadEnv ? (string)$_ENV['TERM_ANSI_ART_MODE'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->hadEnv) {
            $_ENV['TERM_ANSI_ART_MODE'] = $this->savedEnv;
        } else {
            unset($_ENV['TERM_ANSI_ART_MODE']);
        }
    }

    private function setMode(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['TERM_ANSI_ART_MODE']);
        } else {
            $_ENV['TERM_ANSI_ART_MODE'] = $value;
        }
    }

    public function testModeDefaultsToCanvas(): void
    {
        $this->setMode(null);
        $this->assertSame(AnsiArtViewer::MODE_CANVAS, AnsiArtViewer::mode());
    }

    public function testUnknownModeFallsBackToCanvas(): void
    {
        $this->setMode('nonsense');
        $this->assertSame(AnsiArtViewer::MODE_CANVAS, AnsiArtViewer::mode());
    }

    public function testModeRecognisesAllValues(): void
    {
        $this->setMode('viewer');
        $this->assertSame(AnsiArtViewer::MODE_VIEWER, AnsiArtViewer::mode());
        $this->setMode('inline');
        $this->assertSame(AnsiArtViewer::MODE_INLINE, AnsiArtViewer::mode());
        $this->setMode('  RAW  ');
        $this->assertSame(AnsiArtViewer::MODE_RAW, AnsiArtViewer::mode());
        $this->setMode('canvas');
        $this->assertSame(AnsiArtViewer::MODE_CANVAS, AnsiArtViewer::mode());
    }

    public function testReaderRenderModeIsStrictForNonArtRegardlessOfMode(): void
    {
        foreach (['canvas', 'viewer', 'inline', 'raw'] as $m) {
            $this->setMode($m);
            $this->assertSame('strict', AnsiArtViewer::readerRenderMode(false));
        }
    }

    public function testReaderRenderModeForArt(): void
    {
        $this->setMode('canvas');
        $this->assertSame('canvas', AnsiArtViewer::readerRenderMode(true));
        $this->setMode('raw');
        $this->assertSame('raw', AnsiArtViewer::readerRenderMode(true));
        $this->setMode('viewer');
        $this->assertSame('strict', AnsiArtViewer::readerRenderMode(true));
        $this->setMode('inline');
        $this->assertSame('strict', AnsiArtViewer::readerRenderMode(true));
    }

    public function testReaderBodyPolicyFollowsRenderMode(): void
    {
        $this->setMode('canvas');
        $this->assertSame(TerminalTextSanitizer::POLICY_POSITIONING, AnsiArtViewer::readerBodyPolicy(true));
        $this->setMode('raw');
        $this->assertSame(TerminalTextSanitizer::POLICY_POSITIONING, AnsiArtViewer::readerBodyPolicy(true));

        $this->setMode('viewer');
        $this->assertSame(TerminalTextSanitizer::POLICY_STRIP, AnsiArtViewer::readerBodyPolicy(true));
        $this->setMode('canvas');
        $this->assertSame(TerminalTextSanitizer::POLICY_STRIP, AnsiArtViewer::readerBodyPolicy(false));
    }

    public function testIsArtDetectsPositionedBodies(): void
    {
        $this->assertTrue(AnsiArtViewer::isArt("logo\x1b[2;1Hhere"));
        $this->assertFalse(AnsiArtViewer::isArt("plain \x1b[31mred\x1b[0m only"));
    }
}
