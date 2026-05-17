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

        if (!$interactive) {
            $this->renderReadOnly($conn, $state, $session, $limit);
            return;
        }

        $this->server->logAction($state['username'] ?? 'unknown', "Shoutbox: entered");
        while (true) {
            $messages = $this->getMessages($session, $limit);
        $this->renderShoutboxBox(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.shoutbox.title', 'Shoutbox', [], $state['locale']),
            $messages
        );
            $choice = $this->server->prompt($conn, $state, TelnetUtils::colorize($this->server->t('ui.terminalserver.shoutbox.menu', '[P]ost  [R]efresh  [Q]uit: ', [], $state['locale']), TelnetUtils::ANSI_YELLOW), true);
            if ($choice === null) {
                return;
            }

            $choice = strtolower(trim($choice));
            if ($choice === '' || $choice === 'r') {
                continue;
            }
            if ($choice === 'q') {
                return;
            }
            if ($choice !== 'p') {
                continue;
            }

            $message = $this->server->prompt($conn, $state, TelnetUtils::colorize($this->server->t('ui.terminalserver.shoutbox.new_shout', 'New shout (blank to cancel): ', [], $state['locale']), TelnetUtils::ANSI_CYAN), true);
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

            TelnetUtils::writeLine($conn, '');
            if (($response['data']['success'] ?? false) === true) {
                $this->server->logAction($state['username'] ?? 'unknown', "Shoutbox: posted message");
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.shoutbox.posted', 'Shout posted.', [], $state['locale']), TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
            } else {
                $this->server->logAction($state['username'] ?? 'unknown', "Shoutbox: post failed: " . ($response['data']['error'] ?? 'unknown'));
                TelnetUtils::writeLine($conn, TelnetUtils::colorize((string)($response['data']['error'] ?? $this->server->t('ui.terminalserver.shoutbox.post_failed', 'Failed to post shout.', [], $state['locale'])), TelnetUtils::ANSI_RED));
            }
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.server.press_continue', 'Press any key to continue...', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
        }
    }

    private function renderReadOnly($conn, array &$state, string $session, int $limit): void
    {
        $messages = $this->getMessages($session, $limit);
        $this->renderShoutboxBox(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.shoutbox.recent_title', 'Recent Shoutbox', [], $state['locale']),
            $messages,
            6
        );
        TelnetUtils::writeLine(
            $conn,
            TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.shoutbox.quick_prompt', 'Press S to shout, or any other key to continue...', [], $state['locale']),
                TelnetUtils::ANSI_YELLOW
            )
        );

        $key = $this->server->readKeyWithIdleCheck($conn, $state);
        if ($key === null) {
            return;
        }

        if ($key === 'CHAR:s' || $key === 'CHAR:S') {
            TelnetUtils::writeLine($conn, '');
            $message = $this->server->prompt(
                $conn,
                $state,
                TelnetUtils::colorize($this->server->t('ui.terminalserver.shoutbox.new_shout', 'New shout (blank to cancel): ', [], $state['locale']), TelnetUtils::ANSI_CYAN),
                true
            );
            if ($message === null) {
                return;
            }

            $message = trim($message);
            if ($message === '') {
                return;
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

            TelnetUtils::writeLine($conn, '');
            if (($response['data']['success'] ?? false) === true) {
                $this->server->logAction($state['username'] ?? 'unknown', "Shoutbox: posted message");
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.shoutbox.posted', 'Shout posted.', [], $state['locale']), TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
            } else {
                $this->server->logAction($state['username'] ?? 'unknown', "Shoutbox: post failed: " . ($response['data']['error'] ?? 'unknown'));
                TelnetUtils::writeLine($conn, TelnetUtils::colorize((string)($response['data']['error'] ?? $this->server->t('ui.terminalserver.shoutbox.post_failed', 'Failed to post shout.', [], $state['locale'])), TelnetUtils::ANSI_RED));
            }
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.server.press_continue', 'Press any key to continue...', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
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
    private function renderShoutboxBox($conn, array &$state, string $title, array $messages, int $verticalMargin = 4): void
    {
        $cols = max(40, (int)($state['cols'] ?? 80));
        $rows = max(12, (int)($state['rows'] ?? 24));
        $boxWidth = max(38, min($cols - 4, 96));
        $contentWidth = max(20, $boxWidth - 4);
        $boxHeight = max(8, $rows - max(2, $verticalMargin));
        $contentHeight = max(3, $boxHeight - 4);

        $lines = $this->buildMessageLines($messages, $state, $contentWidth);
        if (count($lines) > $contentHeight) {
            $lines = array_slice($lines, -(max(1, $contentHeight - 1)));
            array_unshift($lines, TelnetUtils::colorize('...', TelnetUtils::ANSI_DIM));
        }

        $shoutboxLabel = $this->server->t('ui.terminalserver.shoutbox.title', 'Shoutbox', [], $state['locale']);
        $headerTitle = trim($title) === $shoutboxLabel ? $shoutboxLabel : $shoutboxLabel . ': ' . $title;

        $renderer = new TerminalBoxRenderer($this->server);
        $renderer->renderBox($conn, $state, $headerTitle, $lines, $verticalMargin, TerminalBoxRenderer::SCHEME_SHOUTBOX);
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

}
