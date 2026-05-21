<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\TelnetServer\TelnetServer;

/**
 * ShoutboxHandler - Handles shoutbox display functionality for telnet daemon
 *
 * Provides methods for displaying recent shoutbox messages in a bordered frame.
 * This handler encapsulates shoutbox-specific functionality that was previously in
 * standalone functions within telnet_daemon.php.
 */
class ShoutboxHandler
{
    /** @var TelnetServer The telnet server instance */
    private BbsSession $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    /**
     * Create a new ShoutboxHandler instance
     *
     * @param BbsSession $server The telnet server instance for I/O operations
     * @param string $apiBase Base URL for API requests
     */
    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server = $server;
        $this->apiBase = $apiBase;
    }

    /**
     * Display recent shoutbox messages
     *
     * Shows recent shoutbox messages in alternating colors.
     * If the shoutbox feature is disabled, returns silently.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param int $limit Maximum number of messages to display (default: 5)
     * @param bool $interactive Whether to prompt for posting/refresh controls
     * @return void
     */
    public function show($conn, array &$state, string $session, int $limit = 5, bool $interactive = true): void
    {
        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('shoutbox')) {
            return;
        }

        $shell = TerminalShellFactory::create($this->server, $state);

        if (!$interactive) {
            $this->renderReadOnly($conn, $state, $session, $limit, $shell);
            return;
        }

        $this->server->logAction($state['username'] ?? 'unknown', "Shoutbox: entered");
        while (true) {
            $messages = $this->getMessages($session, $limit);
            $contentWidth = $this->getScrollablePanelContentWidth($state);
            $lines = $this->buildMessageLines($messages, $state, $contentWidth);
            $choice = $shell->showScrollablePanel(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.shoutbox.title', 'Shoutbox', [], $state['locale']),
                $lines,
                [
                    'status_segments' => $this->buildShoutboxCommandFooter(true),
                    'extra_keys' => ['p' => 'post', 'r' => 'refresh'],
                    'color_scheme' => [
                        'border' => TelnetUtils::ANSI_RED . TelnetUtils::ANSI_BOLD,
                        'divider' => TelnetUtils::ANSI_RED,
                        'title_bar' => TelnetUtils::ANSI_BG_RED . TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD,
                        'body' => "\033[40m\033[37m",
                        'status_bar_bg' => "\033[40m",
                        'status_bar_fill' => TelnetUtils::ANSI_BLUE,
                    ],
                    'redraw_fn' => function (array &$resizeState) use ($messages): array {
                        return [
                            'lines' => $this->buildMessageLines($messages, $resizeState, $this->getScrollablePanelContentWidth($resizeState)),
                            'offset' => 0,
                        ];
                    },
                ]
            );
            if ($choice === null || $choice === 'quit') {
                return;
            }
            if ($choice === 'refresh') {
                continue;
            }
            if ($choice !== 'post') {
                continue;
            }

            $message = $shell->promptText(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.shoutbox.title', 'Shoutbox', [], $state['locale']),
                $this->server->t('ui.terminalserver.shoutbox.new_shout', 'New shout (blank to cancel): ', [], $state['locale'])
            );
            if ($message === null) {
                return;
            }

            $message = trim($message);
            if ($message === '') {
                continue;
            }

            $response = TelnetUtils::apiRequest(
                $this->apiBase,
                'POST',
                '/api/shoutbox',
                ['message' => $message],
                $session,
                3,
                $state['csrf_token'] ?? null
            );

            if (($response['data']['success'] ?? false) === true) {
                $this->server->logAction($state['username'] ?? 'unknown', "Shoutbox: posted message");
                $shell->showAlert(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.shoutbox.title', 'Shoutbox', [], $state['locale']),
                    $this->server->t('ui.terminalserver.shoutbox.posted', 'Shout posted.', [], $state['locale']),
                    'info'
                );
            } else {
                $this->server->logAction($state['username'] ?? 'unknown', "Shoutbox: post failed: " . ($response['data']['error'] ?? 'unknown'));
                $shell->showAlert(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.shoutbox.title', 'Shoutbox', [], $state['locale']),
                    (string)($response['data']['error'] ?? $this->server->t('ui.terminalserver.shoutbox.post_failed', 'Failed to post shout.', [], $state['locale'])),
                    'error'
                );
            }
        }
    }

    private function renderReadOnly($conn, array &$state, string $session, int $limit, TerminalShellInterface $shell): void
    {
        while (true) {
            $messages = $this->getMessages($session, $limit);
            $contentWidth = $this->getScrollablePanelContentWidth($state);
            $lines = $this->buildMessageLines($messages, $state, $contentWidth);
            $choice = $shell->showScrollablePanel(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.shoutbox.title', 'Shoutbox', [], $state['locale']),
                $lines,
                [
                    'status_segments' => $this->buildShoutboxCommandFooter(false),
                    'color_scheme' => [
                        'border' => TelnetUtils::ANSI_RED . TelnetUtils::ANSI_BOLD,
                        'divider' => TelnetUtils::ANSI_RED,
                        'title_bar' => TelnetUtils::ANSI_BG_RED . TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD,
                        'body' => "\033[40m\033[37m",
                        'status_bar_bg' => "\033[40m",
                        'status_bar_fill' => TelnetUtils::ANSI_BLUE,
                    ],
                    'redraw_fn' => function (array &$resizeState) use ($messages): array {
                        return [
                            'lines' => $this->buildMessageLines($messages, $resizeState, $this->getScrollablePanelContentWidth($resizeState)),
                            'offset' => 0,
                        ];
                    },
                ]
            );

            if ($choice === null || $choice === 'quit') {
                return;
            }
        }
    }

    private function getMessages(string $session, int $limit): array
    {
        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'GET',
            '/api/shoutbox?limit=' . $limit,
            null,
            $session
        );

        return $response['data']['messages'] ?? [];
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @param resource $conn
     */
    private function renderShoutboxBox($conn, array &$state, string $title, array $messages, int $scrollOffset = 0, ?array $footerSegments = null, int $verticalMargin = 4): array
    {
        $layout = $this->getShoutboxLayout($state, 0, $verticalMargin);
        $contentWidth = max(12, $layout['contentWidth'] - 1);
        $messageRows = max(1, $layout['contentHeight'] - ($footerSegments === null ? 0 : 1));

        $lines = $this->buildMessageLines($messages, $state, $contentWidth);
        $maxOffset = max(0, count($lines) - $messageRows);
        $scrollOffset = max(0, min($scrollOffset, $maxOffset));
        $visibleLines = array_slice($lines, $scrollOffset, $messageRows);
        if ($scrollOffset > 0 && $visibleLines !== []) {
            $visibleLines[0] = TelnetUtils::colorize('...', TelnetUtils::ANSI_DIM) . ' ' . $visibleLines[0];
        } elseif ($scrollOffset > 0) {
            $visibleLines[] = TelnetUtils::colorize('...', TelnetUtils::ANSI_DIM);
        }
        if ($scrollOffset < $maxOffset && $visibleLines !== []) {
            $lastIndex = array_key_last($visibleLines);
            $visibleLines[$lastIndex] = $visibleLines[$lastIndex] . ' ' . TelnetUtils::colorize('...', TelnetUtils::ANSI_DIM);
        } elseif ($scrollOffset < $maxOffset) {
            $visibleLines[] = TelnetUtils::colorize('...', TelnetUtils::ANSI_DIM);
        }

        $shoutboxLabel = $this->server->t('ui.terminalserver.shoutbox.title', 'Shoutbox', [], $state['locale']);
        $messageCount = count($visibleLines) - ($footerSegments === null ? 0 : 1);
        $pageLabel = $maxOffset > 0 ? sprintf(' (%d-%d/%d)', $scrollOffset + 1, min($scrollOffset + $messageCount, count($lines)), count($lines)) : '';
        $headerTitle = trim($title) === $shoutboxLabel ? $shoutboxLabel . $pageLabel : $shoutboxLabel . ': ' . $title . $pageLabel;

        $renderer = new TerminalBoxRenderer($this->server);
        $renderer->renderBox($conn, $state, $headerTitle, $visibleLines, $verticalMargin, TerminalBoxRenderer::SCHEME_SHOUTBOX, 0);
        if ($footerSegments !== null) {
            $this->renderFooterLine($conn, $state, $layout, $footerSegments, $messageRows);
        }
        $this->renderScrollbar($conn, $state, $layout, $scrollOffset, $maxOffset, $messageRows);

        return [
            'contentHeight' => $messageRows,
            'maxOffset' => $maxOffset,
            'contentWidth' => $contentWidth,
            'topPadRows' => $layout['topPadRows'],
            'leftPadCols' => $layout['leftPadCols'],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return string[]
     */
    private function buildMessageLines(array $messages, array $state, int $contentWidth): array
    {
        if (empty($messages)) {
            return [
                TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.shoutbox.no_messages', 'No shoutbox messages.', [], $state['locale']),
                    TelnetUtils::ANSI_YELLOW
                )
            ];
        }

        $lines = [];
        foreach ($messages as $index => $msg) {
            $user = trim((string)($msg['username'] ?? 'Unknown'));
            if ($user === '') {
                $user = 'Unknown';
            }

            $text = trim(str_replace(["\r\n", "\r", "\n"], ' ', (string)($msg['message'] ?? '')));
            $date = TelnetUtils::formatUserDate((string)($msg['created_at'] ?? ''), $state, false);
            $header = TelnetUtils::colorize($date, TelnetUtils::ANSI_YELLOW)
                . ' '
                . TelnetUtils::colorize($user, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD);
            $lines[] = $header;

            $messageWidth = max(12, $contentWidth - 2);
            $wrapped = $this->wrapPlainText($text === '' ? '-' : $text, $messageWidth);
            foreach ($wrapped as $part) {
                $lines[] = '  ' . TelnetUtils::colorize($part, TelnetUtils::ANSI_GREEN);
            }

            if ($index !== array_key_last($messages)) {
                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * @return string[]
     */
    private function wrapPlainText(string $text, int $width): array
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
        if ($text === '') {
            return [''];
        }

        $wrapped = wordwrap($text, max(1, $width), "\n", false);
        $lines = explode("\n", $wrapped);

        return array_map(static function (string $line) use ($width): string {
            if (mb_strwidth($line, 'UTF-8') <= $width) {
                return $line;
            }

            return mb_strimwidth($line, 0, max(0, $width - 3), '...', 'UTF-8');
        }, $lines);
    }

    private function normalizeChoiceToken(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }
        if ($key === 'UP' || $key === 'DOWN' || $key === 'PGUP' || $key === 'PGDOWN' || $key === 'HOME' || $key === 'END') {
            return strtolower($key);
        }
        if (str_starts_with($key, 'CHAR:')) {
            return strtolower(substr($key, 5));
        }
        if ($key === 'ENTER') {
            return '';
        }
        return strtolower($key);
    }

    private function getShoutboxLayout(array $state, int $footerLines = 1, int $verticalMargin = 4): array
    {
        $cols = max(40, (int)($state['cols'] ?? 80));
        $rows = max(12, (int)($state['rows'] ?? 24));
        $boxWidth = max(38, min($cols - 4, 96));
        $contentWidth = max(20, $boxWidth - 4);
        $reservedFooter = max(0, $footerLines);
        $boxHeight = max(8, $rows - max(2, $verticalMargin) - $reservedFooter);
        $contentHeight = max(3, $boxHeight - 4);
        $leftPadCols = max(0, (int)floor(($cols - $boxWidth) / 2));
        $topPadRows = max(0, (int)floor(($rows - $boxHeight - $reservedFooter - 1) / 2));

        return [
            'boxWidth' => $boxWidth,
            'contentWidth' => $contentWidth,
            'contentHeight' => $contentHeight,
            'leftPadCols' => $leftPadCols,
            'topPadRows' => $topPadRows,
        ];
    }

    private function getScrollablePanelContentWidth(array $state): int
    {
        $cols = max(20, (int)($state['cols'] ?? 80));
        $boxWidth = max(10, min($cols - 2, 78));
        return max(6, $boxWidth - 4);
    }

    private function renderScrollbar($conn, array &$state, array $layout, int $scrollOffset, int $maxOffset, int $messageRows): void
    {
        if ($maxOffset <= 0 || $messageRows <= 0) {
            return;
        }

        $contentWidth = max(1, (int)$layout['contentWidth']);
        $leftCol = (int)$layout['leftPadCols'] + 2;
        $topRow = (int)$layout['topPadRows'] + 3;
        $thumbStart = (int)floor(($scrollOffset / max(1, $maxOffset)) * max(0, $messageRows - 1));
        $thumbEnd = (int)floor((min($scrollOffset + $messageRows - 1, $maxOffset) / max(1, $maxOffset)) * max(0, $messageRows - 1));

        for ($row = 0; $row < $messageRows; $row++) {
            $glyph = ($row >= $thumbStart && $row <= $thumbEnd) ? '█' : '░';
            $targetRow = $topRow + $row;
            $targetCol = $leftCol + $contentWidth - 1;
            TelnetUtils::safeWrite($conn, sprintf("\033[%d;%dH%s", $targetRow, $targetCol, TelnetUtils::colorize($glyph, TelnetUtils::ANSI_MAGENTA . TelnetUtils::ANSI_BOLD)));
        }
    }

    private function renderFooterLine($conn, array &$state, array $layout, array $footerSegments, int $messageRows): void
    {
        $left = (int)$layout['leftPadCols'] + 3;
        $row = (int)$layout['topPadRows'] + 3 + max(0, $messageRows);
        $width = max(1, (int)$layout['contentWidth'] - 2);
        $plain = '';
        foreach ($footerSegments as $segment) {
            $plain .= (string)($segment['text'] ?? '');
        }
        $plain = preg_replace('/\s+/', ' ', trim($plain)) ?? trim($plain);
        $plain = mb_strimwidth($plain, 0, $width, '', 'UTF-8');
        $pad = str_repeat(' ', max(0, $width - mb_strwidth($plain, 'UTF-8')));

        $line = '';
        $used = 0;
        foreach ($footerSegments as $segment) {
            $text = (string)($segment['text'] ?? '');
            $color = (string)($segment['color'] ?? '');
            if ($text === '') {
                continue;
            }
            $remaining = max(0, $width - $used);
            if ($remaining <= 0) {
                break;
            }
            $chunk = mb_strimwidth($text, 0, $remaining, '', 'UTF-8');
            if ($chunk === '') {
                continue;
            }
            $line .= $color !== '' ? TelnetUtils::colorize($chunk, $color) : $chunk;
            $used += mb_strwidth($chunk, 'UTF-8');
        }
        $line .= $pad;
        TelnetUtils::safeWrite($conn, sprintf("\033[%d;%dH%s", $row, $left, $line));
    }

    /**
     * @return array<int,array{text:string,color:string}>
     */
    private function buildShoutboxCommandFooter(bool $interactive): array
    {
        $keyColor = TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD;
        $labelColor = TelnetUtils::ANSI_BLUE;
        $divider = TelnetUtils::ANSI_BLUE;

        if ($interactive) {
            return [
                ['text' => 'P', 'color' => $keyColor],
                ['text' => '=', 'color' => $divider],
                ['text' => 'Post', 'color' => $labelColor],
                ['text' => '  ', 'color' => ''],
                ['text' => 'R', 'color' => $keyColor],
                ['text' => '=', 'color' => $divider],
                ['text' => 'Refresh', 'color' => $labelColor],
                ['text' => '  ', 'color' => ''],
                ['text' => 'Q', 'color' => $keyColor],
                ['text' => '=', 'color' => $divider],
                ['text' => 'Quit', 'color' => $labelColor],
                ['text' => '  ', 'color' => ''],
                ['text' => 'U/D', 'color' => $keyColor],
                ['text' => '=', 'color' => $divider],
                ['text' => 'Scroll', 'color' => $labelColor],
                ['text' => '  ', 'color' => ''],
                ['text' => 'PgUp/PgDn', 'color' => $keyColor],
                ['text' => '=', 'color' => $divider],
                ['text' => 'Page', 'color' => $labelColor],
                ['text' => '  ', 'color' => ''],
                ['text' => 'Home/End', 'color' => $keyColor],
                ['text' => '=', 'color' => $divider],
                ['text' => 'Top/Bottom', 'color' => $labelColor],
            ];
        }

        return [
            ['text' => 'U/D', 'color' => $keyColor],
            ['text' => '=', 'color' => $divider],
            ['text' => 'Scroll', 'color' => $labelColor],
            ['text' => '  ', 'color' => ''],
            ['text' => 'PgUp/PgDn', 'color' => $keyColor],
            ['text' => '=', 'color' => $divider],
            ['text' => 'Page', 'color' => $labelColor],
            ['text' => '  ', 'color' => ''],
            ['text' => 'Home/End', 'color' => $keyColor],
            ['text' => '=', 'color' => $divider],
            ['text' => 'Top/Bottom', 'color' => $labelColor],
            ['text' => '  ', 'color' => ''],
            ['text' => 'Q', 'color' => $keyColor],
            ['text' => '=', 'color' => $divider],
            ['text' => 'Continue', 'color' => $labelColor],
        ];
    }

}
