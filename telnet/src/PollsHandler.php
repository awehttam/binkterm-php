<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\TelnetServer\TelnetServer;

/**
 * PollsHandler - Handles voting booth/polls functionality for telnet daemon
 *
 * Provides methods for displaying active polls with results and voting options.
 * This handler encapsulates poll-specific functionality that was previously in
 * standalone functions within telnet_daemon.php.
 */
class PollsHandler
{
    /** @var TelnetServer The telnet server instance */
    private TelnetServer $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    /**
     * Create a new PollsHandler instance
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
     * Display active polls with results
     *
     * Shows all active polls with their questions and options.
     * If the user has already voted, displays results with vote counts.
     * If the voting booth feature is disabled, displays an error message.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @return void
     */
    public function show($conn, array &$state, string $session): void
    {
        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('voting_booth')) {
            TelnetUtils::writeLine($conn, 'Voting booth is disabled.');
            return;
        }

        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'GET',
            '/api/polls/active',
            null,
            $session
        );
        $polls = $response['data']['polls'] ?? [];
        if (!$polls) {
            TelnetUtils::writeLine($conn, 'No active polls.');
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, 'Active Polls');
        foreach ($polls as $poll) {
            $question = $poll['question'] ?? '';
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, 'Q: ' . $question);
            $options = $poll['options'] ?? [];
            foreach ($options as $idx => $opt) {
                $num = $idx + 1;
                $text = $opt['option_text'] ?? '';
                TelnetUtils::writeLine($conn, "  {$num}) {$text}");
            }
            if (!empty($poll['has_voted']) && !empty($poll['results'])) {
                TelnetUtils::writeLine($conn, 'Results:');
                $total = (int)($poll['total_votes'] ?? 0);
                foreach ($poll['results'] as $result) {
                    $text = $result['option_text'] ?? '';
                    $votes = (int)($result['votes'] ?? 0);
                    TelnetUtils::writeLine($conn, sprintf('  %s - %d', $text, $votes));
                }
                TelnetUtils::writeLine($conn, 'Total votes: ' . $total);
            }
        }
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, 'Press Enter to return.');
        $this->server->readLineWithIdleCheck($conn, $state);
    }
}
