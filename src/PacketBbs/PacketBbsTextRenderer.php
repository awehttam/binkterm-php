<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

namespace BinktermPHP\PacketBbs;

/**
 * Renders BBS data as plain ASCII text for bandwidth-constrained radio links.
 */
class PacketBbsTextRenderer
{
    private const PAGE_SIZES = [
        'meshcore'   => 3,
        'meshtastic' => 4,
        'tnc'        => 8,
    ];

    /** Body lines per page when paginating long messages. */
    private const MSG_PAGE_SIZES = [
        'meshcore'   => 1,
        'meshtastic' => 3,
        'tnc'        => 8,
    ];

    private const LINE_WIDTHS = [
        'meshcore'   => 34,
        'meshtastic' => 34,
        'tnc'        => 64,
    ];

    private string $interface;
    private int $pageSize;
    private int $msgPageSize;
    private int $lineWidth;

    public function __construct(string $interface = 'meshcore')
    {
        $this->interface   = $interface;
        $this->pageSize    = self::PAGE_SIZES[$interface] ?? self::PAGE_SIZES['meshcore'];
        $this->msgPageSize = self::MSG_PAGE_SIZES[$interface] ?? self::MSG_PAGE_SIZES['meshcore'];
        $this->lineWidth   = self::LINE_WIDTHS[$interface] ?? self::LINE_WIDTHS['meshcore'];
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * Count body pages for a message, applying the configured lines-per-page.
     * Returns 1 when the wrapped body fits on a single page.
     */
    public function countBodyPages(string $text): int
    {
        $lines = $this->wrapBody($text);
        if (count($lines) <= $this->msgPageSize) {
            return 1;
        }
        return (int)ceil(max(1, count($lines)) / $this->msgPageSize);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function renderHelp(string $topic = '', string $bbsName = '', array $context = []): string
    {
        $topic = strtoupper(trim($topic));

        if (in_array($topic, ['HELPFULL', 'FULLHELP', 'HELPFUL'], true)) {
            return implode("\n", [
                'FULL HELP',
                '(L)OGIN username code',
                '(W)HO online users',
                '(A)REAS list / (A)REA tag open',
                '(N)ETMAIL list mail',
                '(R)EAD id read msg',
                '(Y) id reply to msg',
                '(S)END user|addr subj',
                '(EP) post in current area',
                '(BU)LLETINS list / (BU) # read',
                '(U)STATUS show context',
                '(M)ORE next page',
                '(B)ACK prev page',
                '(Q)UIT end session',
            ]);
        }

        if (in_array($topic, ['MAIL', 'N', 'NETMAIL'], true)) {
            return implode("\n", [
                'H N',
                'N:list  R id:read',
                'Y id:reply  S to subj:send',
                'M:more  B:back',
            ]);
        }

        if (in_array($topic, ['AREAS', 'AREA', 'E', 'ECHO', 'ECHOMAIL'], true)) {
            return implode("\n", [
                'H A',
                'A:list/open  AREA tag:open',
                'R id:read  EP:post here',
                'M:more  B:back',
            ]);
        }

        if (in_array($topic, ['POST', 'P', 'EP'], true)) {
            return implode("\n", [
                'H EP',
                'EP: post in current area',
                'No area? use T tag',
                'Subj? Msg: /S /C',
            ]);
        }

        if (in_array($topic, ['READ', 'R'], true)) {
            return implode("\n", [
                'H R',
                'R id: read item',
                'R: reread current msg',
                'In list, id may be slot',
                'Use M/B to move',
            ]);
        }

        if (in_array($topic, ['STATUS', 'U'], true)) {
            return implode("\n", [
                'H U',
                'U: show area, list, msg,',
                'or draft state',
            ]);
        }

        if (!empty($context['current_area'])) {
            $area = (string)($context['current_area']['display'] ?? $context['current_area']['tag'] ?? 'area');
            return implode("\n", [
                'Area ' . $this->truncate($area, 24),
                'R id | EP post | A list | U status',
                'Q leave area | QUIT end session',
            ]);
        }

        return implode("\n", [
            'H: L username code | A areas | N mail',
            'T tag | S to subj | R id | Y id',
            'M more | B back | U status | Q quit',
        ]);
    }

    public function renderWho(array $users): string
    {
        if (empty($users)) {
            return 'No one online.';
        }
        $lines = ['WHO'];
        foreach ($users as $u) {
            $service = $u['service'] ?? 'web';
            $lines[] = sprintf('%s [%s]', $this->truncate($u['username'], 24), $service);
        }
        return implode("\n", $lines);
    }

    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) <= $max) {
            return $str;
        }
        return mb_substr($str, 0, $max - 1) . '~';
    }

    /**
     * @param array $messages Netmail rows from MessageHandler::getNetmail()
     */
    public function renderNetmailList(array $messages, int $page, int $totalPages): string
    {
        if (empty($messages)) {
            return 'No mail.';
        }
        $lines = [sprintf('MAIL %d/%d', $page, $totalPages)];
        foreach ($messages as $m) {
            $unread = empty($m['read_at']) ? '*' : ' ';
            $from   = $this->truncate($m['from_name'] ?? '?', 10);
            $prefix = sprintf('%s%d %s ', $unread, (int)$m['id'], $from);
            $subj   = $this->truncate($m['subject'] ?? '(no subject)', max(8, $this->lineWidth - mb_strlen($prefix)));
            $lines[] = $prefix . $subj;
        }
        if ($page < $totalPages) {
            $lines[] = 'R <id>, M:more B:back';
        } else {
            $lines[] = 'R <id>, RP <id>';
        }
        return implode("\n", $lines);
    }

    /**
     * @param int $page 0 = render full body; 1+ = render that body page only.
     */
    public function renderNetmailMessage(array $m, int $page = 0): string
    {
        $date      = $this->messageDate($m['date_received'] ?? $m['date_written'] ?? '');
        $bodyLines = $this->wrapBody($m['message_text'] ?? '');
        $lines     = [
            sprintf('#%d %s %s', (int)$m['id'], $this->truncate($m['from_name'] ?? '?', 18), $date),
            $this->truncate($m['subject'] ?? '(no subject)', $this->lineWidth),
        ];

        if ($page > 0) {
            $totalPages = (int)ceil(max(1, count($bodyLines)) / $this->msgPageSize);
            foreach (array_slice($bodyLines, ($page - 1) * $this->msgPageSize, $this->msgPageSize) as $line) {
                $lines[] = $line;
            }
            $lines[] = $page < $totalPages
                ? sprintf('%d/%d M:more B:back', $page, $totalPages)
                : 'RP ' . (int)$m['id'];
        } else {
            foreach ($bodyLines as $line) {
                $lines[] = $line;
            }
            $lines[] = 'RP ' . (int)$m['id'];
        }

        return implode("\n", $lines);
    }

    private function messageDate(string $date): string
    {
        if (!$date) {
            return '?';
        }
        try {
            return (new \DateTime($date))->format('Y-m-d');
        } catch (\Exception $e) {
            return '?';
        }
    }

    /**
     * Wrap and clean a FTN message body for radio display.
     *
     * @return string[]
     */
    private function wrapBody(string $text): array
    {
        // Strip ANSI escape sequences
        $text = preg_replace('/\x1b\[[0-9;]*[mKHJABCDf]/', '', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines  = explode("\n", $text);
        $output = [];
        $blank = false;
        foreach ($lines as $line) {
            $line = rtrim($line);
            // Skip FTN kludge lines (start with ^A)
            if ($line !== '' && $line[0] === "\x01") {
                continue;
            }
            if ($line === '') {
                if (!$blank) {
                    $output[] = '';
                }
                $blank = true;
                continue;
            }
            $blank = false;
            if (mb_strlen($line) <= $this->lineWidth) {
                $output[] = $line;
            } else {
                foreach (explode("\n", wordwrap($line, $this->lineWidth, "\n", true)) as $wl) {
                    $output[] = $wl;
                }
            }
        }
        return $output;
    }

    public function renderEchoareaList(array $areas, ?string $search, int $page, int $totalPages): string
    {
        if (empty($areas)) {
            return $search !== null
                ? sprintf('No areas match "%s".', $this->truncate($search, 20))
                : 'No areas. Ask sysop.';
        }
        $header = $search !== null
            ? sprintf('AREAS "%s" %d/%d', $this->truncate($search, 12), $page, $totalPages)
            : sprintf('AREAS %d/%d', $page, $totalPages);
        $lines = [$header];
        foreach ($areas as $a) {
            $tag  = strtoupper($a['tag'] ?? '?');
            $domain = strtolower(trim((string)($a['domain'] ?? '')));
            if ($domain !== '') {
                $tag .= '@' . $domain;
            }
            $tag = $this->truncate($tag, 22);
            $desc = $this->truncate($a['description'] ?? '', max(8, $this->lineWidth - mb_strlen($tag) - 1));
            $lines[] = trim($tag . ' ' . $desc);
        }
        $lines[] = $page < $totalPages ? 'AREA <tag>, M:more B:back' : 'AREA <tag>';
        return implode("\n", $lines);
    }

    // --- Private helpers ---

    /**
     * @param array $messages Echomail rows from MessageHandler::getEchomail()
     */
    public function renderEchomailList(array $messages, string $tag, int $page, int $totalPages): string
    {
        if (empty($messages)) {
            return sprintf('No posts in %s.', $this->formatAreaForDisplay($tag));
        }
        $lines = [sprintf('%s %d/%d', $this->formatAreaForDisplay($tag), $page, $totalPages)];
        foreach ($messages as $m) {
            $from   = $this->truncate($m['from_name'] ?? '?', 10);
            $prefix = sprintf('%d %s ', (int)$m['id'], $from);
            $subj   = $this->truncate($m['subject'] ?? '(no subject)', max(8, $this->lineWidth - mb_strlen($prefix)));
            $lines[] = $prefix . $subj;
        }
        if ($page < $totalPages) {
            $lines[] = 'R <id>, M:more B:back';
        } else {
            $lines[] = 'R <id>, RP <id>';
        }
        return implode("\n", $lines);
    }

    private function formatAreaForDisplay(string $area): string
    {
        $parts = explode('@', $area, 2);
        $tag = strtoupper(trim($parts[0] ?? ''));
        $domain = strtolower(trim($parts[1] ?? ''));
        return $domain !== '' ? $tag . '@' . $domain : $tag;
    }

    /**
     * @param int $page 0 = render full body; 1+ = render that body page only.
     */
    public function renderEchomailMessage(array $m, int $page = 0): string
    {
        $date      = $this->messageDate($m['date_received'] ?? $m['date_written'] ?? '');
        $tag       = strtoupper($m['tag'] ?? $m['echoarea_tag'] ?? '?');
        $bodyLines = $this->wrapBody($m['message_text'] ?? '');
        $lines     = [
            sprintf('#%d %s %s %s', (int)$m['id'], $tag, $this->truncate($m['from_name'] ?? '?', 12), $date),
            $this->truncate($m['subject'] ?? '(no subject)', $this->lineWidth),
        ];

        if ($page > 0) {
            $totalPages = (int)ceil(max(1, count($bodyLines)) / $this->msgPageSize);
            foreach (array_slice($bodyLines, ($page - 1) * $this->msgPageSize, $this->msgPageSize) as $line) {
                $lines[] = $line;
            }
            $lines[] = $page < $totalPages
                ? sprintf('%d/%d M:more B:back', $page, $totalPages)
                : 'RP ' . (int)$m['id'];
        } else {
            foreach ($bodyLines as $line) {
                $lines[] = $line;
            }
            $lines[] = 'RP ' . (int)$m['id'];
        }

        return implode("\n", $lines);
    }

    public function renderComposePrompt(string $type, string $to, string $subject): string
    {
        $prefix = stripos($type, 'reply') !== false ? 'Replying to ' : 'To ';
        return implode("\n", [
            $prefix . $this->truncate($to, max(8, $this->lineWidth - mb_strlen($prefix))) . '.',
            'Subj: ' . $this->truncate($subject, max(8, $this->lineWidth - 6)),
            'Send lines. /SEND=send /CANCEL=abort',
        ]);
    }

    /**
     * @param array<string,mixed> $state
     */
    public function renderStatus(array $state): string
    {
        $lines = [];

        if (!empty($state['current_area']['display'])) {
            $lines[] = 'area ' . $this->truncate((string)$state['current_area']['display'], 28);
        } elseif (!empty($state['current_area']['tag'])) {
            $tag = strtoupper((string)$state['current_area']['tag']);
            $domain = strtolower((string)($state['current_area']['domain'] ?? ''));
            $lines[] = 'area ' . ($domain !== '' ? $tag . '@' . $domain : $tag);
        }

        if (!empty($state['active_flow']['type'])) {
            $flow = (string)$state['active_flow']['type'];
            $step = (string)($state['active_flow']['step'] ?? '');
            $subject = trim((string)($state['active_flow']['subject'] ?? ''));
            $target = trim((string)($state['active_flow']['target_display'] ?? ''));
            $line = 'draft ' . $flow;
            if ($target !== '') {
                $line .= ' ' . $target;
            }
            $lines[] = $this->truncate($line, 32);
            if ($subject !== '') {
                $lines[] = 'subj ' . $this->truncate($subject, 29);
            } elseif ($step !== '') {
                $lines[] = 'step ' . $this->truncate($step, 29);
            }
            if (isset($state['active_flow']['body_lines'])) {
                $lines[] = (int)$state['active_flow']['body_lines'] . ' lines';
            }
        } elseif (!empty($state['current_message']['id'])) {
            $msgType = (string)($state['current_message']['type'] ?? 'msg');
            $page = (int)($state['current_list']['page'] ?? 1);
            $lines[] = sprintf('%s #%d p%d', $msgType, (int)$state['current_message']['id'], $page);
        } elseif (!empty($state['current_list']['type'])) {
            $type = (string)$state['current_list']['type'];
            $page = (int)($state['current_list']['page'] ?? 1);
            $total = (int)($state['current_list']['total_pages'] ?? 1);
            $lines[] = sprintf('list %s p%d/%d', $type, $page, $total);
        }

        if (empty($lines)) {
            return 'No active context.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int,array<string,mixed>> $bulletins
     */
    public function renderAbout(string $bbsName, string $bbsUrl): string
    {
        return implode("\n", [
            sprintf('Hi! This is a radio bridge to %s.', $bbsName),
            'Use "L username authcode" to login.',
            sprintf('Visit %s to register and setup PacketBBS access.', $bbsUrl),
        ]);
    }

    public function renderBulletinList(array $bulletins): string
    {
        if (empty($bulletins)) {
            return 'No bulletins.';
        }
        $lines = [sprintf('BULLETINS %d', count($bulletins))];
        foreach ($bulletins as $b) {
            $id     = (int)$b['id'];
            $unread = empty($b['is_read']) ? '*' : ' ';
            $title  = $this->truncate((string)($b['title'] ?? ''), $this->lineWidth - strlen((string)$id) - 3);
            $lines[] = sprintf('%s#%d %s', $unread, $id, $title);
        }
        $lines[] = 'BU # to read';
        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $bulletin
     */
    public function renderBulletin(array $bulletin): string
    {
        $id    = (int)$bulletin['id'];
        $title = $this->truncate((string)($bulletin['title'] ?? ''), $this->lineWidth);
        $body  = $this->stripMarkdown((string)($bulletin['body'] ?? ''));
        $lines = ["#$id $title"];
        foreach ($this->wrapBody($body) as $line) {
            $lines[] = $line;
        }
        $lines[] = 'BU for list';
        return implode("\n", $lines);
    }

    /**
     * @param array<int,array<string,mixed>> $unreadBulletins
     */
    public function renderLoginBulletinNotice(array $unreadBulletins): string
    {
        $count = count($unreadBulletins);
        $lines = [sprintf('%d unread bulletin%s:', $count, $count === 1 ? '' : 's')];
        foreach ($unreadBulletins as $b) {
            $id    = (int)$b['id'];
            $title = $this->truncate((string)($b['title'] ?? ''), $this->lineWidth - strlen((string)$id) - 2);
            $lines[] = sprintf('#%d %s', $id, $title);
        }
        $lines[] = 'BU to read';
        return implode("\n", $lines);
    }

    private function stripMarkdown(string $text): string
    {
        // ATX headings
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        // Bold/italic
        $text = preg_replace('/\*{1,3}([^*\n]+)\*{1,3}/', '$1', $text) ?? $text;
        $text = preg_replace('/_{1,3}([^_\n]+)_{1,3}/', '$1', $text) ?? $text;
        // Inline code
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
        // Links and images
        $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        // Blockquotes
        $text = preg_replace('/^>\s?/m', '', $text) ?? $text;
        // Horizontal rules
        $text = preg_replace('/^[-*_]{3,}\s*$/m', '---', $text) ?? $text;
        return $text;
    }
}
