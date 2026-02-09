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
     * @return void
     */
    public function show($conn, array &$state, string $session, int $limit = 5): void
    {
        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('shoutbox')) {
            return;
        }

        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'GET',
            '/api/shoutbox?limit=' . $limit,
            null,
            $session
        );
        $messages = $response['data']['messages'] ?? [];
        $cols = (int)($state['cols'] ?? 80);
        $innerWidth = max(20, min($cols - 2, 78));
        $title = 'Recent Shoutbox';

        $lines = [];
        if (!$messages) {
            $lines[] = 'No shoutbox messages.';
        } else {
            foreach ($messages as $msg) {
                $user = $msg['username'] ?? 'Unknown';
                $text = $msg['message'] ?? '';
                // Strip any newlines/carriage returns from message text
                $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
                // Format date using user's timezone and date format preferences
                $date = TelnetUtils::formatUserDate($msg['created_at'] ?? '', $state, false);
                $lines[] = sprintf('[%s] %s: %s', $date, $user, $text);
            }
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_MAGENTA . TelnetUtils::ANSI_BOLD));

        $lineIndex = 0;
        foreach ($lines as $line) {
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

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Press any key to continue...', TelnetUtils::ANSI_YELLOW));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }
}
