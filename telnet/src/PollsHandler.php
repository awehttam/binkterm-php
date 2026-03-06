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
            TelnetUtils::writeLine($conn, $this->server->t('ui.telnet.polls.disabled', 'Voting booth is disabled.', [], $state['locale']));
            return;
        }

        while (true) {
            $polls = $this->getActivePolls($session);
            if (!$polls) {
                TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.telnet.polls.title', 'Polls', [], $state['locale']), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.telnet.polls.no_polls', 'No active polls.', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.telnet.server.press_any_key', 'Press any key to return...', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
                $this->server->readKeyWithIdleCheck($conn, $state);
                return;
            }

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.telnet.polls.title', 'Polls', [], $state['locale']), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            TelnetUtils::writeLine($conn, '');

            foreach ($polls as $index => $poll) {
                $num = $index + 1;
                $status = !empty($poll['has_voted']) ? 'voted' : 'open';
                $question = (string)($poll['question'] ?? '');
                TelnetUtils::writeLine(
                    $conn,
                    sprintf(
                        ' %2d) %s %s',
                        $num,
                        TelnetUtils::colorize(strtoupper($status), !empty($poll['has_voted']) ? TelnetUtils::ANSI_GREEN : TelnetUtils::ANSI_YELLOW),
                        $question
                    )
                );
            }

            TelnetUtils::writeLine($conn, '');
            $choice = $this->server->prompt($conn, $state, TelnetUtils::colorize($this->server->t('ui.telnet.polls.enter_poll', 'Enter poll # or Q to return: ', [], $state['locale']), TelnetUtils::ANSI_CYAN), true);
            if ($choice === null) {
                return;
            }

            $choice = trim($choice);
            if ($choice === '' || strtolower($choice) === 'q') {
                return;
            }

            $selectedIndex = (int)$choice - 1;
            if (!isset($polls[$selectedIndex])) {
                continue;
            }

            $this->showPollDetail($conn, $state, $session, $polls[$selectedIndex]);
        }
    }

    private function getActivePolls(string $session): array
    {
        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'GET',
            '/api/polls/active',
            null,
            $session
        );

        return $response['data']['polls'] ?? [];
    }

    private function showPollDetail($conn, array &$state, string $session, array $poll): void
    {
        while (true) {
            $polls = $this->getActivePolls($session);
            $freshPoll = null;
            foreach ($polls as $item) {
                if ((int)($item['id'] ?? 0) === (int)($poll['id'] ?? 0)) {
                    $freshPoll = $item;
                    break;
                }
            }

            if ($freshPoll === null) {
                return;
            }

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.telnet.polls.detail_title', 'Poll Detail', [], $state['locale']), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeWrapped($conn, 'Q: ' . (string)($freshPoll['question'] ?? ''), max(40, (int)($state['cols'] ?? 80) - 2));
            TelnetUtils::writeLine($conn, '');

            if (!empty($freshPoll['has_voted'])) {
                $totalVotes = (int)($freshPoll['total_votes'] ?? 0);
                foreach ($freshPoll['results'] ?? [] as $index => $result) {
                    $votes = (int)($result['votes'] ?? 0);
                    $percent = $totalVotes > 0 ? (int)round(($votes / $totalVotes) * 100) : 0;
                    $line = sprintf(' %2d) %s - %d vote(s) [%d%%]', $index + 1, $result['option_text'] ?? '', $votes, $percent);
                    TelnetUtils::writeLine($conn, TelnetUtils::colorize($line, TelnetUtils::ANSI_GREEN));
                }
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::writeLine($conn, $this->server->t('ui.telnet.polls.total_votes', 'Total votes: {count}', ['count' => $totalVotes], $state['locale']));
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.telnet.server.press_any_key', 'Press any key to return...', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
                $this->server->readKeyWithIdleCheck($conn, $state);
                return;
            }

            foreach ($freshPoll['options'] ?? [] as $index => $option) {
                TelnetUtils::writeLine($conn, sprintf(' %2d) %s', $index + 1, $option['option_text'] ?? ''));
            }

            TelnetUtils::writeLine($conn, '');
            $choice = $this->server->prompt($conn, $state, TelnetUtils::colorize($this->server->t('ui.telnet.polls.vote_prompt', 'Vote with option # or Q to return: ', [], $state['locale']), TelnetUtils::ANSI_CYAN), true);
            if ($choice === null) {
                return;
            }

            $choice = trim($choice);
            if ($choice === '' || strtolower($choice) === 'q') {
                return;
            }

            $selectedIndex = (int)$choice - 1;
            $options = $freshPoll['options'] ?? [];
            if (!isset($options[$selectedIndex]['id'])) {
                continue;
            }

            $response = TelnetUtils::apiRequest(
                $this->apiBase,
                'POST',
                '/api/polls/' . (int)$freshPoll['id'] . '/vote',
                ['option_id' => (int)$options[$selectedIndex]['id']],
                $session,
                3,
                $state['csrf_token'] ?? null
            );

            TelnetUtils::writeLine($conn, '');
            if (($response['data']['success'] ?? false) === true) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.telnet.polls.voted', 'Vote recorded.', [], $state['locale']), TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize((string)($response['data']['error'] ?? 'Vote failed.'), TelnetUtils::ANSI_RED));
            }
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.telnet.server.press_continue', 'Press any key to continue...', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
        }
    }
}
