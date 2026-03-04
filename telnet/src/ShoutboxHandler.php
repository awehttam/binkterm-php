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
    private TelnetServer $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    /**
     * Create a new ShoutboxHandler instance
     *
     * @param TelnetServer $server The telnet server instance for I/O operations
     * @param string $apiBase Base URL for API requests
     */
    public function __construct(TelnetServer $server, string $apiBase)
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

        while (true) {
            $messages = $this->getMessages($session, $limit);
            $cols = (int)($state['cols'] ?? 80);
            $innerWidth = max(20, min($cols - 2, 78));

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Shoutbox', TelnetUtils::ANSI_MAGENTA . TelnetUtils::ANSI_BOLD));
            TelnetUtils::writeLine($conn, '');

            if (!$messages) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize('No shoutbox messages.', TelnetUtils::ANSI_YELLOW));
            } else {
                $lineIndex = 0;
                foreach ($messages as $msg) {
                    $user = $msg['username'] ?? 'Unknown';
                    $text = str_replace(["\r\n", "\r", "\n"], ' ', (string)($msg['message'] ?? ''));
                    $date = TelnetUtils::formatUserDate($msg['created_at'] ?? '', $state, false);
                    $line = sprintf('[%s] %s: %s', $date, $user, $text);
                    $wrapped = wordwrap($line, $innerWidth, "\n", false);
                    foreach (explode("\n", $wrapped) as $part) {
                        if (strlen($part) > $innerWidth) {
                            $part = substr($part, 0, $innerWidth - 3) . '...';
                        }
                        $color = ($lineIndex % 2 === 0) ? TelnetUtils::ANSI_GREEN : TelnetUtils::ANSI_CYAN;
                        TelnetUtils::writeLine($conn, TelnetUtils::colorize($part, $color));
                        $lineIndex++;
                    }
                }
            }

            TelnetUtils::writeLine($conn, '');
            $choice = $this->server->prompt($conn, $state, TelnetUtils::colorize('[P]ost  [R]efresh  [Q]uit: ', TelnetUtils::ANSI_YELLOW), true);
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

            $message = $this->server->prompt($conn, $state, TelnetUtils::colorize('New shout (blank to cancel): ', TelnetUtils::ANSI_CYAN), true);
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
                TelnetUtils::writeLine($conn, TelnetUtils::colorize('Shout posted.', TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize((string)($response['data']['error'] ?? 'Failed to post shout.'), TelnetUtils::ANSI_RED));
            }
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Press any key to continue...', TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
        }
    }

    private function renderReadOnly($conn, array &$state, string $session, int $limit): void
    {
        $messages = $this->getMessages($session, $limit);
        $cols = (int)($state['cols'] ?? 80);
        $innerWidth = max(20, min($cols - 2, 78));

        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Recent Shoutbox', TelnetUtils::ANSI_MAGENTA . TelnetUtils::ANSI_BOLD));

        if (!$messages) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('No shoutbox messages.', TelnetUtils::ANSI_YELLOW));
        } else {
            $lineIndex = 0;
            foreach ($messages as $msg) {
                $user = $msg['username'] ?? 'Unknown';
                $text = str_replace(["\r\n", "\r", "\n"], ' ', (string)($msg['message'] ?? ''));
                $date = TelnetUtils::formatUserDate($msg['created_at'] ?? '', $state, false);
                $line = sprintf('[%s] %s: %s', $date, $user, $text);
                $wrapped = wordwrap($line, $innerWidth, "\n", false);
                foreach (explode("\n", $wrapped) as $part) {
                    if (strlen($part) > $innerWidth) {
                        $part = substr($part, 0, $innerWidth - 3) . '...';
                    }
                    $color = ($lineIndex % 2 === 0) ? TelnetUtils::ANSI_GREEN : TelnetUtils::ANSI_CYAN;
                    TelnetUtils::writeLine($conn, TelnetUtils::colorize($part, $color));
                    $lineIndex++;
                }
            }
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Press any key to continue...', TelnetUtils::ANSI_YELLOW));
        $this->server->readKeyWithIdleCheck($conn, $state);
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
}
