<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\TelnetServer\TelnetServer;

/**
 * EchomailHandler - Handles echomail (forum/echo) functionality for telnet daemon
 *
 * Provides methods for displaying echoareas, listing echomail messages, and composing
 * new echomail or replies. This handler encapsulates all echomail-specific functionality
 * that was previously in standalone functions within telnet_daemon.php.
 */
class EchomailHandler
{
    /** @var TelnetServer The telnet server instance */
    private TelnetServer $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    /**
     * Create a new EchomailHandler instance
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
     * Display echoarea list with pagination and area selection
     *
     * Shows a list of available echoareas with options to:
     * - Navigate pages (n/p)
     * - Select echoarea by number to view messages
     * - Quit (q)
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @return void
     */
    public function showEchoareas($conn, array &$state, string $session): void
    {
        $page = 1;
        $perPage = MailUtils::getMessagesPerPage($state);

        while (true) {
            $response = TelnetUtils::apiRequest(
                $this->apiBase,
                'GET',
                '/api/echoareas?subscribed_only=true',
                null,
                $session
            );
            $allAreas = $response['data']['echoareas'] ?? [];

            if (!$allAreas) {
                TelnetUtils::writeLine($conn, 'No echoareas available.');
                return;
            }

            $totalPages = (int)ceil(count($allAreas) / $perPage);
            $page = max(1, min($page, $totalPages));
            $offset = ($page - 1) * $perPage;
            $areas = array_slice($allAreas, $offset, $perPage);

            // Clear screen before displaying
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");

            TelnetUtils::writeLine($conn, TelnetUtils::colorize("Echoareas (page {$page}/{$totalPages}):", TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            foreach ($areas as $idx => $area) {
                $num = $idx + 1;
                $tag = $area['tag'] ?? '';
                $domain = $area['domain'] ?? '';
                $desc = $area['description'] ?? '';
                TelnetUtils::writeLine($conn, sprintf(' %2d) %-20s %-10s %s', $num, substr($tag, 0, 20), substr($domain, 0, 10), substr($desc, 0, 40)));
            }
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, 'Enter #, n/p (next/prev), q (quit)');

            $input = trim((string)$this->server->readLineWithIdleCheck($conn, $state));

            if ($input === 'q' || $input === '') {
                return;
            }

            if ($input === 'n') {
                if ($page < $totalPages) {
                    $page++;
                }
                continue;
            }

            if ($input === 'p' && $page > 1) {
                $page--;
                continue;
            }

            $choice = (int)$input;
            if ($choice > 0 && $choice <= count($areas)) {
                $area = $areas[$choice - 1];
                $tag = $area['tag'] ?? '';
                $domain = $area['domain'] ?? '';
                $this->showMessages($conn, $state, $session, $tag, $domain);
            }
        }
    }

    /**
     * Display echomail message list for a specific echoarea
     *
     * Shows a list of echomail messages for the selected area with options to:
     * - Navigate pages (n/p)
     * - Read messages by number
     * - Compose new messages (c)
     * - Reply to messages (r)
     * - Quit (q)
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param string $tag Echoarea tag
     * @param string $domain Echoarea domain
     * @return void
     */
    public function showMessages($conn, array &$state, string $session, string $tag, string $domain): void
    {
        $page = 1;
        $area = $tag . '@' . $domain;
        $perPage = MailUtils::getMessagesPerPage($state);

        while (true) {
            $response = TelnetUtils::apiRequest(
                $this->apiBase,
                'GET',
                '/api/messages/echomail/' . urlencode($area) . '?page=' . $page . '&per_page=' . $perPage,
                null,
                $session
            );
            $allMessages = $response['data']['messages'] ?? [];
            $pagination = $response['data']['pagination'] ?? [];
            $totalPages = $pagination['pages'] ?? 1;

            if (!$allMessages) {
                TelnetUtils::writeLine($conn, 'No echomail messages.');
                return;
            }

            // Force limit to perPage in case API returns more
            $messages = array_slice($allMessages, 0, $perPage);

            // Clear screen before displaying
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");

            TelnetUtils::writeLine($conn, TelnetUtils::colorize("Echomail: {$area} (page {$page}/{$totalPages})", TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            foreach ($messages as $idx => $msg) {
                $num = $idx + 1;
                $from = $msg['from_name'] ?? 'Unknown';
                $subject = $msg['subject'] ?? '(no subject)';
                $date = $msg['date_written'] ?? '';
                $dateShort = substr($date, 0, 10);
                TelnetUtils::writeLine($conn, sprintf(' %2d) %-20s %-35s %s', $num, substr($from, 0, 20), substr($subject, 0, 35), $dateShort));
            }
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, 'Enter #, n/p (next/prev), c (compose), q (quit)');

            $input = trim((string)$this->server->readLineWithIdleCheck($conn, $state));
            if ($input === 'q' || $input === '') {
                return;
            }
            if ($input === 'c') {
                $this->compose($conn, $state, $session, $area, null);
                continue;
            }
            if ($input === 'n') {
                if ($page < $totalPages) {
                    $page++;
                }
                continue;
            }
            if ($input === 'p' && $page > 1) {
                $page--;
                continue;
            }
            $choice = (int)$input;
            if ($choice > 0 && $choice <= count($messages)) {
                $msg = $messages[$choice - 1];
                $id = $msg['id'] ?? null;
                if ($id) {
                    $this->displayMessage($conn, $state, $session, $msg, $id, $area);
                }
            }
        }
    }

    /**
     * Compose new echomail or reply to existing message
     *
     * Prompts user for recipient name, subject, and message body.
     * If replying, pre-fills recipient info and quotes original message.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param string $area Echoarea tag@domain
     * @param array|null $reply Reply data from original message (null for new message)
     * @return void
     */
    public function compose($conn, array &$state, string $session, string $area, ?array $reply = null): void
    {
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('=== Compose Echomail ===', TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Area: ' . $area, TelnetUtils::ANSI_MAGENTA));
        TelnetUtils::writeLine($conn, '');

        $toNameDefault = $reply['from_name'] ?? 'All';
        $subjectDefault = $reply ? 'Re: ' . MailUtils::normalizeSubject((string)($reply['subject'] ?? '')) : '';

        $toNamePrompt = TelnetUtils::colorize('To Name: ', TelnetUtils::ANSI_CYAN);
        if ($toNameDefault) {
            $toNamePrompt .= TelnetUtils::colorize("[{$toNameDefault}] ", TelnetUtils::ANSI_YELLOW);
        }
        $toName = $this->server->prompt($conn, $state, $toNamePrompt, true);
        if ($toName === null) {
            return;
        }
        if ($toName === '' && $toNameDefault !== '') {
            $toName = $toNameDefault;
        }

        $subjectPrompt = TelnetUtils::colorize('Subject: ', TelnetUtils::ANSI_CYAN);
        if ($subjectDefault) {
            $subjectPrompt .= TelnetUtils::colorize("[{$subjectDefault}] ", TelnetUtils::ANSI_YELLOW);
        }
        $subject = $this->server->prompt($conn, $state, $subjectPrompt, true);
        if ($subject === null) {
            return;
        }
        if ($subject === '' && $subjectDefault !== '') {
            $subject = $subjectDefault;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Enter your message below:', TelnetUtils::ANSI_GREEN));

        $cols = $state['cols'] ?? 80;

        // If replying, quote the original message
        $initialText = '';
        if ($reply) {
            $originalBody = $reply['message_text'] ?? '';
            $originalAuthor = $reply['from_name'] ?? 'Unknown';
            if ($originalBody !== '') {
                $initialText = MailUtils::quoteMessage($originalBody, $originalAuthor);
            }
        }

        $messageText = $this->server->readMultiline($conn, $state, $cols, $initialText);
        if ($messageText === '') {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Message cancelled (empty).', TelnetUtils::ANSI_YELLOW));
            return;
        }

        $payload = [
            'type' => 'echomail',
            'echoarea' => $area,
            'to_name' => $toName,
            'subject' => $subject,
            'message_text' => $messageText
        ];
        if (!empty($reply['id'])) {
            $payload['reply_to_id'] = $reply['id'];
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Posting echomail...', TelnetUtils::ANSI_CYAN));
        $result = MailUtils::sendMessage($this->apiBase, $session, $payload);
        if ($result['success']) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('✓ Echomail posted successfully!', TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('✗ Failed to post echomail: ' . ($result['error'] ?? 'Unknown error'), TelnetUtils::ANSI_RED));
        }
        TelnetUtils::writeLine($conn, '');
    }

    /**
     * Display a single echomail message with reply option
     *
     * Shows message subject, sender, recipient, and body with pagination.
     * Offers option to reply or return to message list.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param array $msg Message summary data
     * @param int $id Message ID for fetching full details
     * @param string $area Echoarea tag@domain
     * @return void
     */
    private function displayMessage($conn, array &$state, string $session, array $msg, int $id, string $area): void
    {
        $detail = TelnetUtils::apiRequest(
            $this->apiBase,
            'GET',
            '/api/messages/echomail/' . urlencode($area) . '/' . $id,
            null,
            $session
        );
        $body = $detail['data']['message_text'] ?? '';
        $cols = $state['cols'] ?? 80;
        $rows = $state['rows'] ?? 24;

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($msg['subject'] ?? 'Message', TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('From: ' . ($msg['from_name'] ?? 'Unknown') . ' to ' . ($msg['to_name'] ?? 'All'), TelnetUtils::ANSI_DIM));
        TelnetUtils::writeLine($conn, str_repeat('-', min(78, $cols)));
        TelnetUtils::writeWrappedWithMore($conn, $body, $cols, $rows, $state);
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, 'Press Enter to return, r to reply.');

        $action = trim((string)$this->server->readLineWithIdleCheck($conn, $state));
        if (strtolower($action) === 'r') {
            $replyData = $detail['data'] ?? $msg;
            $this->compose($conn, $state, $session, $area, $replyData);
        }
    }
}
