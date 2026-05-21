<?php

use BinktermPHP\PacketBbs\PacketBbsTextRenderer;
use PHPUnit\Framework\TestCase;

class PacketBbsTextRendererTest extends TestCase
{
    public function testRenderDefaultHelpUsesSparseLayout(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');

        $help = $renderer->renderHelp();

        $this->assertStringContainsString('GEN L user code | W | BU #', $help);
        $this->assertStringContainsString('GEN U/Q | M/B', $help);
        $this->assertStringContainsString('NET N | R/Y id | S to subj', $help);
        $this->assertStringContainsString('ECHO A | T tag | P subj', $help);
        $this->assertStringContainsString('HF:fullhelp', $help);
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
        $this->assertStringContainsString('(L)OGIN username code', $help);
        $this->assertStringContainsString('(S)END user|addr subj', $help);
        $this->assertStringContainsString('(P)OST in current area', $help);
        $this->assertStringContainsString('(U)STATUS show context', $help);
    }

    public function testRenderNetmailHelpMentionsSendShortcut(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');

        $help = $renderer->renderHelp('N');

        $this->assertStringContainsString('S to subj:send', $help);
    }

    public function testRenderPostHelpMentionsCanonicalAlias(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');

        $help = $renderer->renderHelp('P');

        $this->assertStringContainsString('H P', $help);
        $this->assertStringContainsString('P/EP: post in current area', $help);
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

    public function testMeshcoreNetmailListFitsTransportBudget(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');

        $output = $renderer->renderNetmailList([
            [
                'id' => 12,
                'from_name' => 'VeryLongSenderName',
                'subject' => 'Long subject line that should be truncated cleanly',
                'read_at' => null,
            ],
            [
                'id' => 13,
                'from_name' => 'AnotherLongSender',
                'subject' => 'Second longish subject for list sizing',
                'read_at' => '2026-05-17 12:00:00',
            ],
            [
                'id' => 14,
                'from_name' => 'ThirdSender',
                'subject' => 'Third subject to verify three-item page budget',
                'read_at' => null,
            ],
        ], 1, 2);

        $this->assertLessThanOrEqual(150, strlen($output));
    }

    public function testMeshcoreWrappedBodyStillPaginatesWithinBudget(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');
        $body = str_repeat('X', 100);

        $this->assertGreaterThan(1, $renderer->countBodyPages($body));

        $output = $renderer->renderNetmailMessage([
            'id' => 12,
            'from_name' => 'AliceLongName',
            'subject' => 'Testing wrapped body paging',
            'message_text' => $body,
            'date_received' => '2026-05-17 12:00:00',
        ], 1);

        $this->assertLessThanOrEqual(150, strlen($output));
    }

    public function testSplitIntoPagesPassesThroughShortResponse(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');
        $short = 'Hi alice. HELP for commands.';
        $pages = $renderer->splitIntoPages($short);
        $this->assertCount(1, $pages);
        $this->assertSame($short, $pages[0]);
    }

    public function testSplitIntoPagesChunksLongResponseAtNewlines(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');
        // 60-char lines: one line = 60, two = 121 (> 150 - 21 = 129), so each line is its own page
        $long = str_repeat('A', 60) . "\n"
              . str_repeat('B', 60) . "\n"
              . str_repeat('C', 60);

        $pages = $renderer->splitIntoPages($long);

        $this->assertGreaterThan(1, count($pages));
        // Every page must fit within budget plus worst-case footer
        foreach ($pages as $page) {
            $this->assertLessThanOrEqual(129, strlen($page)); // 150 - 21 footer reserve
        }
        // No content is dropped — all lines appear somewhere across pages
        $all = implode('', $pages);
        $this->assertStringContainsString(str_repeat('A', 60), $all);
        $this->assertStringContainsString(str_repeat('B', 60), $all);
        $this->assertStringContainsString(str_repeat('C', 60), $all);
    }

    public function testSplitIntoPagesToncHasNoLimit(): void
    {
        $renderer = new PacketBbsTextRenderer('tnc');
        $long = str_repeat('X', 500);
        $pages = $renderer->splitIntoPages($long);
        $this->assertCount(1, $pages);
        $this->assertSame($long, $pages[0]);
    }

    public function testSplitIntoPagesFullhelpProducesMultiplePages(): void
    {
        $renderer = new PacketBbsTextRenderer('meshcore');
        $help     = $renderer->renderHelp('FULLHELP');
        $pages    = $renderer->splitIntoPages($help);

        $this->assertGreaterThan(1, count($pages));

        // Every page content fits within the per-page budget
        foreach ($pages as $page) {
            $this->assertLessThanOrEqual(129, strlen($page));
        }

        // Full help content is preserved across pages
        $all = implode('', $pages);
        $this->assertStringContainsString('(L)OGIN username code', $all);
        $this->assertStringContainsString('(Q)UIT end session', $all);
    }
}
