<?php
/**
 * PHPUnit tests for message art format detection.
 */

use BinktermPHP\ArtFormatDetector;
use PHPUnit\Framework\TestCase;

class ArtFormatDetectorTest extends TestCase
{
    public function testExplicitPetsciiEncodingStillWins(): void
    {
        $body = "10 PRINT \"HELLO\"\n20 GOTO 10\n";

        $this->assertSame('petscii', ArtFormatDetector::detectArtFormat($body, 'PETSCII'));
    }

    public function testAnsiSequencesAreDetected(): void
    {
        $body = "\x1b[31mRED\x1b[0m\n";

        $this->assertSame('ansi', ArtFormatDetector::detectArtFormat($body, null));
    }

    public function testUnknownEightBitTextIsNotMisclassifiedAsPetscii(): void
    {
        $body = "Files arrived at Zruspa's BBS\n"
            . "Area 957HELP\n"
            . "\x91\x94\xe1\xef\xf0\x9d\xee\xeb\xea\xae\xbf\x80\x81\x82\n"
            . "Orig: 2:5053/51\n"
            . "From: 2:5020/1042\n";

        $this->assertNull(ArtFormatDetector::detectArtFormat($body, null));
    }
}
