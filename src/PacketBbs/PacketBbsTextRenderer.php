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
        'meshcore'   => 5,
        'meshtastic' => 4,
        'tnc'        => 8,
    ];

    /** Body lines per page when paginating long messages. */
    private const MSG_PAGE_SIZES = [
        'meshcore'   => 4,
        'meshtastic' => 3,
        'tnc'        => 8,
    ];

    /** Raw character threshold above which a message body is paginated. */
    private const MSG_BODY_THRESHOLD = 120;

    private const LINE_WIDTHS = [
        'meshcore'   => 42,
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
     * Returns 1 when the raw body text is within the pagination threshold.
     */
    public function countBodyPages(string $text): int
    {
        if (strlen($text) <= self::MSG_BODY_THRESHOLD) {
            return 1;
        }
        $lines = $this->wrapBody($text);
        return (int)ceil(max(1, count($lines)) / $this->msgPageSize);
    }

    public function renderHelp(string $topic = '', string $bbsName = ''): string
    {
        $topic = strtoupper(trim($topic));

        if (in_array($topic, ['MAIL', 'N', 'NETMAIL'], true)) {
            return implode("\n", [
                'MAIL/N: list netmail',
                'R <id>: read',
                'RP <id>: reply',
                'SEND <user> <subj>: new netmail',
                'M: more  P: prev',
            ]);
        }

        if (in_array($topic, ['AREAS', 'AREA', 'E', 'ECHO', 'ECHOMAIL'], true)) {
            return implode("\n", [
                'AREAS/E: list areas',
                'AREA <tag>/ER <tag>: list messages',
                'R <id>: read',
                'RP <id>: reply',
                'POST <tag> <subj>: new post',
                'M: more  P: prev',
            ]);
        }

        if (in_array($topic, ['POST', 'REPLY', 'RP', 'COMPOSE'], true)) {
            return implode("\n", [
                'Send one line at a time.',
                '/SEND or .: send',
                '/CANCEL or CANCEL: abort',
            ]);
        }

        $intro = trim($bbsName) !== ''
            ? sprintf("Hi, I'm %s. Here's help:", trim($bbsName))
            : "Hi, I'm PacketBBS. Here's help:";

        return implode("\n", [
            $intro,
            'LOGIN, WHO, MAIL, AREAS',
            'R <id>, RP <id>, M, P, Q',
            'WEB, More: HELP MAIL, HELP AREAS',
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
            $lines[] = 'R <id>, M';
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
                ? sprintf('%d/%d M:more', $page, $totalPages)
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

    public function renderEchoareaList(array $areas): string
    {
        if (empty($areas)) {
            return 'No areas. Ask sysop.';
        }
        $lines = ['AREAS'];
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
        $lines[] = 'AREA <tag>';
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
            $lines[] = 'R <id>, M';
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
                ? sprintf('%d/%d M:more', $page, $totalPages)
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
}
