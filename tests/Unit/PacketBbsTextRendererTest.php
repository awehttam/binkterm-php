<?php

use BinktermPHP\PacketBbs\PacketBbsTextRenderer;
use PHPUnit\Framework\TestCase;

class PacketBbsTextRendererTest extends TestCase
{
    public function testRenderDefaultHelpUsesSparseLayout(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');

        $help = $renderer->renderHelp();

        $this->assertStringContainsString('H: L username code | A areas | N mail', $help);
        $this->assertStringContainsString('U status', $help);
    }

    public function testRenderAreaContextHelpShowsCurrentArea(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');

        $help = $renderer->renderHelp('', '', [
            'current_area' => [
                'display' => 'LVLY_TEST',
            ],
        ]);

        $this->assertStringContainsString('Area LVLY_TEST', $help);
        $this->assertStringContainsString('P post', $help);
    }

    public function testRenderReadHelpMentionsCurrentMessageReplay(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');

        $help = $renderer->renderHelp('R');

        $this->assertStringContainsString('R: reread current msg', $help);
    }

    public function testRenderVerboseHelpUsesFullNames(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');

        $help = $renderer->renderHelp('FULLHELP');

        $this->assertStringContainsString('FULL HELP', $help);
        $this->assertStringContainsString('LOGIN username code', $help);
        $this->assertStringContainsString('STATUS show context', $help);
    }

    public function testRenderStatusShowsDraftSummary(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');

        $status = $renderer->renderStatus([
            'current_area' => [
                'display' => 'LVLY_TEST',
            ],
            'active_flow' => [
                'type' => 'post',
                'target_display' => 'LVLY_TEST',
                'subject' => 'Testing from radio',
                'body_lines' => 2,
            ],
        ]);

        $this->assertStringContainsString('area LVLY_TEST', $status);
        $this->assertStringContainsString('draft post LVLY_TEST', $status);
        $this->assertStringContainsString('subj Testing from radio', $status);
        $this->assertStringContainsString('2 lines', $status);
    }
}
