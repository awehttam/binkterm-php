<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\TelnetServer\TelnetServer;

/**
 * NetmailHandler - Handles netmail (private messaging) functionality for telnet daemon
 *
 * Provides methods for displaying, reading, composing, and replying to netmail messages.
 * This handler encapsulates all netmail-specific functionality that was previously in
 * standalone functions within telnet_daemon.php.
 */
class NetmailHandler
{
    /** @var TelnetServer The telnet server instance */
    private TelnetServer $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    /**
     * Create a new NetmailHandler instance
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
     * Display netmail list with pagination and message reading
     *
     * Shows a list of netmail messages with options to:
     * - Navigate pages (n/p)
     * - Read messages by number
     * - Compose new messages (c)
     * - Reply to messages (r)
     * - Quit (q)
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @return void
     */
    public function show($conn, array &$state, string $session): void
    {
        $page = 1;
        $perPage = MailUtils::getMessagesPerPage($state);

        while (true) {
            $response = TelnetUtils::apiRequest(
                $this->apiBase,
                'GET',
                '/api/messages/netmail?page=' . $page . '&per_page=' . $perPage,
                null,
                $session
            );
            $allMessages = $response['data']['messages'] ?? [];
            $pagination = $response['data']['pagination'] ?? [];
            $totalPages = $pagination['pages'] ?? 1;

            if (!$allMessages) {
                TelnetUtils::writeLine($conn, 'No netmail messages.');
                return;
            }

            // Force limit to perPage in case API returns more
            $messages = array_slice($allMessages, 0, $perPage);

            // Clear screen before displaying
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");

            TelnetUtils::writeLine($conn, TelnetUtils::colorize("Netmail (page {$page}/{$totalPages}):", TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
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

            // Read input via server method (will need public accessor or wrapper)
            $input = trim((string)$this->server->readLineWithIdleCheck($conn, $state));
            if ($input === 'q' || $input === '') {
                return;
            }
            if ($input === 'c') {
                $this->compose($conn, $state, $session, null);
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
                    $this->displayMessage($conn, $state, $session, $msg, $id);
                }
            }
        }
    }

    /**
     * Compose new netmail or reply to existing message
     *
     * Prompts user for recipient name, address, subject, and message body.
     * If replying, pre-fills recipient info and quotes original message.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param array|null $reply Reply data from original message (null for new message)
     * @return void
     */
    public function compose($conn, array &$state, string $session, ?array $reply = null): void
    {
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('=== Compose Netmail ===', TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, '');

        $toNameDefault = $reply['replyto_name'] ?? $reply['from_name'] ?? '';
        $toAddressDefault = $reply['replyto_address'] ?? $reply['from_address'] ?? '';
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

        $toAddressPrompt = TelnetUtils::colorize('To Address: ', TelnetUtils::ANSI_CYAN);
        if ($toAddressDefault) {
            $toAddressPrompt .= TelnetUtils::colorize("[{$toAddressDefault}] ", TelnetUtils::ANSI_YELLOW);
        }
        $toAddress = $this->server->prompt($conn, $state, $toAddressPrompt, true);
        if ($toAddress === null) {
            return;
        }
        if ($toAddress === '' && $toAddressDefault !== '') {
            $toAddress = $toAddressDefault;
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
        $signature = MailUtils::getUserSignature($this->apiBase, $session);
        $initialText = MailUtils::appendSignatureToCompose($initialText, $signature);

        $messageText = $this->server->readMultiline($conn, $state, $cols, $initialText);
        if ($messageText === '') {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Message cancelled (empty).', TelnetUtils::ANSI_YELLOW));
            return;
        }

        $payload = [
            'type' => 'netmail',
            'to_name' => $toName,
            'to_address' => $toAddress,
            'subject' => $subject,
            'message_text' => $messageText
        ];
        if (!empty($reply['id'])) {
            $payload['reply_to_id'] = $reply['id'];
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Sending netmail...', TelnetUtils::ANSI_CYAN));
        $result = MailUtils::sendMessage($this->apiBase, $session, $payload);
        if ($result['success']) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('✓ Netmail sent successfully!', TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('✗ Failed to send netmail: ' . ($result['error'] ?? 'Unknown error'), TelnetUtils::ANSI_RED));
        }
        TelnetUtils::writeLine($conn, '');
    }

    /**
     * Display a single netmail message with reply option
     *
     * Shows message subject, sender, and body with pagination.
     * Offers option to reply or return to message list.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param array $msg Message summary data
     * @param int $id Message ID for fetching full details
     * @return void
     */
    private function displayMessage($conn, array &$state, string $session, array $msg, int $id): void
    {
        $detail = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/messages/netmail/' . $id, null, $session);
        $body = $detail['data']['message_text'] ?? '';
        $cols = $state['cols'] ?? 80;
        $rows = $state['rows'] ?? 24;
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($msg['subject'] ?? 'Message', TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('From: ' . ($msg['from_name'] ?? 'Unknown'), TelnetUtils::ANSI_DIM));
        TelnetUtils::writeLine($conn, str_repeat('-', min(78, $cols)));
        TelnetUtils::writeWrappedWithMore($conn, $body, $cols, $rows, $state);
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, 'Press Enter to return, r to reply.');
        $action = trim((string)$this->server->readLineWithIdleCheck($conn, $state));
        if (strtolower($action) === 'r') {
            $replyData = $detail['data'] ?? $msg;
            $this->compose($conn, $state, $session, $replyData);
        }
    }
}
