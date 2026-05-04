<?php

namespace BinktermPHP\TelnetServer;

/**
 * Displays sysop bulletins in telnet/SSH sessions.
 */
class BulletinsHandler
{
    private BbsSession $server;
    private string $apiBase;

    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server = $server;
        $this->apiBase = $apiBase;
    }

    public function showUnread($conn, array &$state, string $session): void
    {
        $bulletins = $this->fetchBulletins($session, true);
        if (empty($bulletins)) {
            return;
        }

        $this->showBulletins($conn, $state, $session, $bulletins, true);
    }

    public function show($conn, array &$state, string $session): void
    {
        $bulletins = $this->fetchBulletins($session, false);
        if (empty($bulletins)) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.bulletins.none', 'No bulletins are available.', [], $state['locale']),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_continue', 'Press any key to continue...', [], $state['locale']),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $this->showBulletins($conn, $state, $session, $bulletins, false);
    }

    /**
     * @param array<int,array<string,mixed>> $bulletins
     */
    private function showBulletins($conn, array &$state, string $session, array $bulletins, bool $markRead): void
    {
        $box = new TerminalBoxRenderer($this->server);
        $ids = [];
        $width = max(40, min(92, (int)($state['cols'] ?? 80) - 8));

        foreach ($bulletins as $index => $bulletin) {
            $ids[] = (int)($bulletin['id'] ?? 0);
            $format = (string)($bulletin['format'] ?? 'plain');
            $body = (string)($bulletin['body'] ?? '');
            $lines = $format === 'markdown'
                ? TerminalMarkupRenderer::render('markdown', $body, $width)
                : $this->renderAnsiPlainBulletin($body, $width, ($state['terminal_ansi_color'] ?? 'yes') !== 'no');
            $title = (string)($bulletin['title'] ?? $this->server->t('ui.terminalserver.bulletins.title', 'Bulletins', [], $state['locale']));
            $prompt = $this->server->t(
                'ui.terminalserver.bulletins.continue',
                'Bulletin {current} of {total}. Press Enter for next, S to skip all...',
                ['current' => $index + 1, 'total' => count($bulletins)],
                $state['locale']
            );
            $key = $box->showPagedBox($conn, $state, $title, $lines, $prompt, 2, ['CHAR:s', 'CHAR:S']);
            if ($key === 'CHAR:s' || $key === 'CHAR:S') {
                break;
            }
        }

        if ($markRead) {
            $this->markRead($session, $ids, $state['csrf_token'] ?? null);
        }
    }

    /**
     * Render a plain bulletin as terminal text while preserving display ANSI color.
     *
     * The boxed pager can safely display SGR color sequences, but cursor movement,
     * erase, OSC, and other control sequences would affect the frame itself. Strip
     * those controls for terminal display; the web renderer handles full ANSI art.
     *
     * @return string[]
     */
    private function renderAnsiPlainBulletin(string $body, int $width, bool $ansiColor): array
    {
        $body = $this->stripSauce($body);
        $body = $this->convertPipeCodesToAnsi($body);
        $body = $this->stripNonDisplayAnsi($body);

        if (!$ansiColor) {
            $body = $this->stripSgrAnsi($body);
        }

        $lines = preg_split('/\r\n|\r|\n/', $body);
        $rendered = [];
        foreach ($lines ?: [''] as $line) {
            if ($line === '') {
                $rendered[] = '';
                continue;
            }

            foreach ($this->wrapAnsiSgrLine($line, $width) as $wrapped) {
                $rendered[] = $wrapped;
            }
        }

        return $rendered ?: [''];
    }

    private function stripSauce(string $text): string
    {
        $sauceIndex = strrpos($text, 'SAUCE00');
        if ($sauceIndex === false) {
            return $text;
        }

        $trailerLength = strlen($text) - $sauceIndex;
        if ($trailerLength > 4096) {
            return $text;
        }

        return rtrim(substr($text, 0, $sauceIndex), "\x1a\r\n ");
    }

    private function stripNonDisplayAnsi(string $text): string
    {
        $text = preg_replace('/\033\[[0-9;?]*[ABCDEFGHJKSTfnsu]/', '', $text) ?? $text;
        $text = preg_replace('/\033\][^\x07]*(?:\x07|\033\\\\)/', '', $text) ?? $text;
        return preg_replace('/\033[^\[]/', '', $text) ?? $text;
    }

    private function stripSgrAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;?]*m/', '', $text) ?? $text;
    }

    /**
     * @return string[]
     */
    private function wrapAnsiSgrLine(string $line, int $width): array
    {
        $width = max(10, $width);
        $segments = [];
        $current = '';
        $visible = 0;
        $activeSgr = '';
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === "\033" && preg_match('/\G\033\[[0-9;?]*m/', $line, $match, 0, $i)) {
                $sgr = $match[0];
                $current .= $sgr;
                $activeSgr = $this->nextActiveSgr($activeSgr, $sgr);
                $i += strlen($sgr) - 1;
                continue;
            }

            if ($visible >= $width) {
                $segments[] = $current . ($activeSgr !== '' ? "\033[0m" : '');
                $current = $activeSgr;
                $visible = 0;
            }

            $current .= $line[$i];
            $visible++;
        }

        $segments[] = $current . ($activeSgr !== '' ? "\033[0m" : '');
        return $segments;
    }

    private function nextActiveSgr(string $activeSgr, string $sgr): string
    {
        if (preg_match('/\033\[(?:0|39|49)?m/', $sgr)) {
            return '';
        }

        return $sgr;
    }

    private function convertPipeCodesToAnsi(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = str_replace('||', "\x00DOUBLEPIPE\x00", $text);
        $text = preg_replace('/\|PI/i', "\x00PIPE\x00", $text) ?? $text;
        $text = preg_replace('/\|CD/i', "\033[0m", $text) ?? $text;

        $knownPipeCodes = [
            'CL', 'PA', 'PO', 'NL', 'CR', 'BS', 'BE', 'LF', 'FF',
            'UN', 'TI', 'DA', 'DN', 'LD', 'RD', 'LT', 'RT',
            'KP', 'KR', 'KS', 'KT', 'KU', 'KD',
            'GE', 'GV', 'GL', 'GR', 'GN', 'GO'
        ];
        $text = preg_replace_callback('/\|([A-Z]{2})/', static function (array $match) use ($knownPipeCodes): string {
            return in_array($match[1], $knownPipeCodes, true) ? '' : $match[0];
        }, $text) ?? $text;

        $pipeToAnsiFg = [
            0 => 30, 1 => 34, 2 => 32, 3 => 36, 4 => 31, 5 => 35, 6 => 33, 7 => 37,
            8 => 90, 9 => 94, 10 => 92, 11 => 96, 12 => 91, 13 => 95, 14 => 93, 15 => 97
        ];
        $pipeToAnsiBg = [
            0 => 40, 1 => 44, 2 => 42, 3 => 46, 4 => 41, 5 => 45, 6 => 43, 7 => 47,
            8 => 100, 9 => 104, 10 => 102, 11 => 106, 12 => 101, 13 => 105, 14 => 103, 15 => 107
        ];

        $text = preg_replace_callback('/\|([0-9](?![0-9A-F])|[0-9A-F]{2}(?![0-9A-F]))/', static function (array $match) use ($pipeToAnsiFg, $pipeToAnsiBg): string {
            $code = $match[1];
            if (strlen($code) === 1) {
                return "\033[" . ($pipeToAnsiFg[(int)$code] ?? 37) . 'm';
            }

            if (preg_match('/[A-F]/i', $code)) {
                $background = hexdec($code[0]);
                $foreground = hexdec($code[1]);
                $fg = $pipeToAnsiFg[$foreground] ?? 37;
                if ($background > 0) {
                    return "\033[" . ($pipeToAnsiBg[$background] ?? 40) . ';' . $fg . 'm';
                }
                return "\033[{$fg}m";
            }

            $value = (int)$code;
            if ($value <= 15) {
                return "\033[" . ($pipeToAnsiFg[$value] ?? 37) . 'm';
            }
            if ($value >= 16 && $value <= 23) {
                return "\033[" . ($pipeToAnsiBg[$value - 16] ?? 40) . 'm';
            }

            return $match[0];
        }, $text) ?? $text;

        $text = preg_replace('/\|T[0-9]/i', '', $text) ?? $text;
        return str_replace(["\x00DOUBLEPIPE\x00", "\x00PIPE\x00"], ['||', '|'], $text);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchBulletins(string $session, bool $unreadOnly): array
    {
        $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/bulletins', null, $session);
        $bulletins = $response['data']['bulletins'] ?? [];
        $displayMode = (string)($response['data']['bulletin_display_mode'] ?? 'once');
        if (!$unreadOnly || $displayMode === 'always') {
            return is_array($bulletins) ? $bulletins : [];
        }

        return array_values(array_filter(
            is_array($bulletins) ? $bulletins : [],
            static fn($bulletin) => empty($bulletin['is_read'])
        ));
    }

    /**
     * @param int[] $ids
     */
    private function markRead(string $session, array $ids, ?string $csrfToken): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
        if (empty($ids)) {
            return;
        }

        TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/bulletins/read-all',
            ['ids' => $ids],
            $session,
            3,
            $csrfToken
        );
    }
}
