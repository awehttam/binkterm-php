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
     * Display recent shoutbox messages with borders
     *
     * Shows a bordered frame containing recent shoutbox messages.
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
        $frameWidth = max(40, min($cols, 80));
        // Border: '+' (1) + dashes + '+' (1) = frameWidth
        // Content: '|  ' (3) + content + '  |' (3) = frameWidth
        $innerWidth = $frameWidth - 6;  // Subtract 3 for '|  ' and 3 for '  |'
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
                $date = $msg['created_at'] ?? '';
                $lines[] = sprintf('[%s] %s: %s', $date, $user, $text);
            }
        }

        // Create borders
        $borderTop = '+' . str_repeat('-', $frameWidth - 2) . '+';
        $titleLine = '| ' . str_pad($title, $frameWidth - 4, ' ', STR_PAD_BOTH) . ' |';

        TelnetUtils::writeLine($conn, TelnetUtils::colorize($borderTop, TelnetUtils::ANSI_MAGENTA));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($titleLine, TelnetUtils::ANSI_MAGENTA));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($borderTop, TelnetUtils::ANSI_MAGENTA));

        foreach ($lines as $line) {
            $wrapped = wordwrap($line, $innerWidth, "\n", false);
            foreach (explode("\n", $wrapped) as $part) {
                // Truncate if line is still too long (e.g., very long words)
                if (strlen($part) > $innerWidth) {
                    $part = substr($part, 0, $innerWidth - 3) . '...';
                }
                $contentLine = '|  ' . str_pad($part, $innerWidth, ' ', STR_PAD_RIGHT) . '  |';
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($contentLine, TelnetUtils::ANSI_MAGENTA));
            }
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize($borderTop, TelnetUtils::ANSI_MAGENTA));
    }
}
