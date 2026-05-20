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
    private BbsSession $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    private const DEFAULT_LIST_STATUS = [
        ['text' => 'U/D', 'color' => TelnetUtils::ANSI_RED],
        ['text' => ' Move  ', 'color' => TelnetUtils::ANSI_BLUE],
        ['text' => 'Enter', 'color' => TelnetUtils::ANSI_RED],
        ['text' => ' Select  ', 'color' => TelnetUtils::ANSI_BLUE],
        ['text' => 'Q', 'color' => TelnetUtils::ANSI_RED],
        ['text' => ' Back', 'color' => TelnetUtils::ANSI_BLUE],
    ];

    /**
     * Create a new PollsHandler instance
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
        $shell = TerminalShellFactory::create($this->server, $state);

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('voting_booth')) {
            $shell->showAlert(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.polls.title', 'Polls', [], $state['locale']),
                $this->server->t('ui.terminalserver.polls.disabled', 'Voting booth is disabled.', [], $state['locale']),
                'error'
            );
            return;
        }

        while (true) {
            $polls = $this->getActivePolls($session);
            if (!$polls) {
                $shell->showText(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.polls.title', 'Polls', [], $state['locale']),
                    [$this->server->t('ui.terminalserver.polls.no_polls', 'No active polls.', [], $state['locale'])]
                );
                return;
            }

            $items = $this->buildPollListItems($polls);
            $selectedIndex = $shell->chooseFromList(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.polls.title', 'Polls', [], $state['locale']),
                $items,
                [
                    'prompt' => $this->server->t('ui.terminalserver.polls.enter_poll', 'Enter poll # or Q to return: ', [], $state['locale']),
                    'status_bar' => self::DEFAULT_LIST_STATUS,
                ]
            );
            if ($selectedIndex === null || !isset($polls[$selectedIndex])) {
                return;
            }

            $pollQuestion = $polls[$selectedIndex]['question'] ?? '';
            $this->server->logAction($state['username'] ?? 'unknown', "Polls: viewed poll #{$polls[$selectedIndex]['id']} \"{$pollQuestion}\"");
            $this->showPollDetail($conn, $state, $session, $polls[$selectedIndex], $shell);
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

    private function showPollDetail($conn, array &$state, string $session, array $poll, TerminalShellInterface $shell): void
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

            if (!empty($freshPoll['has_voted'])) {
                $shell->showText(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.polls.detail_title', 'Poll Detail', [], $state['locale']),
                    $this->buildPollResultLines($freshPoll, $state)
                );
                return;
            }

            $options = $freshPoll['options'] ?? [];
            $optionItems = $this->buildOptionListItems($freshPoll);
            $selectedIndex = $shell->chooseFromList(
                $conn,
                $state,
                $this->thisPollTitle($freshPoll, $state['locale'] ?? 'en'),
                $optionItems,
                [
                    'prompt' => $this->server->t('ui.terminalserver.polls.vote_prompt', 'Vote with option # or Q to return: ', [], $state['locale']),
                    'status_bar' => self::DEFAULT_LIST_STATUS,
                ]
            );
            if ($selectedIndex === null || !isset($options[$selectedIndex]['id'])) {
                return;
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

            if (($response['data']['success'] ?? false) === true) {
                $votedOption = $options[$selectedIndex]['option_text'] ?? '';
                $this->server->logAction($state['username'] ?? 'unknown', "Polls: voted on poll #{$freshPoll['id']} option \"{$votedOption}\"");
                $shell->showAlert(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.polls.title', 'Polls', [], $state['locale']),
                    $this->server->t('ui.terminalserver.polls.voted', 'Vote recorded.', [], $state['locale']),
                    'info'
                );
            } else {
                $this->server->logAction($state['username'] ?? 'unknown', "Polls: vote failed on poll #{$freshPoll['id']}: " . ($response['data']['error'] ?? 'unknown'));
                $shell->showAlert(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.polls.title', 'Polls', [], $state['locale']),
                    (string)($response['data']['error'] ?? 'Vote failed.'),
                    'error'
                );
            }
        }
    }

    private function buildPollListItems(array $polls): array
    {
        $items = [];
        foreach ($polls as $poll) {
            $status = !empty($poll['has_voted']) ? 'VOTED' : 'OPEN';
            $statusColor = !empty($poll['has_voted']) ? TelnetUtils::ANSI_GREEN : TelnetUtils::ANSI_YELLOW;
            $items[] = sprintf(
                '%s %s',
                TelnetUtils::colorize(str_pad($status, 5), $statusColor),
                (string)($poll['question'] ?? '')
            );
        }
        return $items;
    }

    private function buildOptionListItems(array $poll): array
    {
        $items = [];
        foreach ($poll['options'] ?? [] as $option) {
            $items[] = (string)($option['option_text'] ?? '');
        }

        return $items;
    }

    private function buildPollResultLines(array $poll, array $state): array
    {
        $lines = [];
        $question = (string)($poll['question'] ?? '');
        if ($question !== '') {
            $lines[] = 'Q: ' . $question;
            $lines[] = '';
        }

        $totalVotes = (int)($poll['total_votes'] ?? 0);
        foreach ($poll['results'] ?? [] as $index => $result) {
            $votes = (int)($result['votes'] ?? 0);
            $percent = $totalVotes > 0 ? (int)round(($votes / $totalVotes) * 100) : 0;
            $lines[] = sprintf(' %2d) %s - %d vote(s) [%d%%]', $index + 1, $result['option_text'] ?? '', $votes, $percent);
        }

        $lines[] = '';
        $lines[] = $this->server->t('ui.terminalserver.polls.total_votes', 'Total votes: {count}', ['count' => $totalVotes], $state['locale']);

        return $lines;
    }

    private function thisPollTitle(array $poll, string $locale): string
    {
        $question = trim((string)($poll['question'] ?? ''));
        if ($question === '') {
            return $this->server->t('ui.terminalserver.polls.detail_title', 'Poll Detail', [], $locale);
        }

        return mb_strlen($question) > 48
            ? mb_substr($question, 0, 45) . '...'
            : $question;
    }
}
