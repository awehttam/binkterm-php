<?php

require_once __DIR__ . '/../../telnet/src/TerminalMarkupRenderer.php';

use BinktermPHP\TelnetServer\TerminalMarkupRenderer;
use PHPUnit\Framework\TestCase;

class TerminalMarkupRendererTest extends TestCase
{
    public function testExtractImageRefsIncludesMarkdownImagesAndBareImageUrls(): void
    {
        $body = <<<MD
Intro line
![diagram](https://example.com/diagram.png)
Standalone https://example.com/photo.jpg
Ignored markdown link [spec](https://example.com/spec.jpg)
MD;

        $refs = TerminalMarkupRenderer::extractImageRefs('markdown', $body);

        $this->assertSame(
            [
                ['index' => 1, 'alt' => 'diagram', 'url' => 'https://example.com/diagram.png'],
                ['index' => 2, 'alt' => '', 'url' => 'https://example.com/photo.jpg'],
            ],
            $refs
        );
    }

    public function testExtractImageRefsForPlainMessagesFindsDirectRasterUrlsOnly(): void
    {
        $body = <<<TXT
Regular echomail text https://example.com/cat.jpg
Ignore SVG https://example.com/vector.svg
Ignore WEBP https://example.com/photo.webp
Ignore markdown-style link [manual](https://example.com/manual.png)
Keep gif https://example.com/anim.gif
TXT;

        $refs = TerminalMarkupRenderer::extractImageRefs('', $body);

        $this->assertSame(
            [
                ['index' => 1, 'alt' => '', 'url' => 'https://example.com/cat.jpg'],
                ['index' => 2, 'alt' => '', 'url' => 'https://example.com/anim.gif'],
            ],
            $refs
        );
    }

    public function testRenderMarkdownReplacesBareImageUrlsWithImagePlaceholders(): void
    {
        $body = "Image here https://example.com/photo.jpg and more text.";

        $lines = TerminalMarkupRenderer::render('markdown', $body, 120);
        $joined = implode("\n", $lines);

        $this->assertStringContainsString('[Image 1: https://example.com/photo.jpg]', $joined);
    }

    public function testRenderPlainTextReplacesBareImageUrlsWithImagePlaceholders(): void
    {
        $body = "Plain image https://example.com/photo.jpg in regular echomail.";

        $lines = TerminalMarkupRenderer::render('', $body, 120);
        $joined = implode("\n", $lines);

        $this->assertStringContainsString('[Image 1: https://example.com/photo.jpg]', $joined);
    }
}
