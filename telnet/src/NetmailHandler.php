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
    private BbsSession $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    /**
     * Create a new NetmailHandler instance
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
        $page          = 1;
        $perPage       = MailUtils::getMessagesPerPage($state);
        $selectedIndex = 0;

        while (true) {
            [$messages, $totalPages] = $this->fetchMessagesPage($session, $page, $perPage);

            if (!$messages) {
                TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.netmail.no_messages', 'No netmail messages.', [], $state['locale']));
                return;
            }

            $title  = TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.netmail.header', 'Netmail (page {page}/{total}):', ['page' => $page, 'total' => $totalPages], $state['locale']),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );
            $result = TelnetUtils::runMessageList($conn, $state, $this->server, $title, $messages, $page, $totalPages, $selectedIndex);
            $selectedIndex = $result['selectedIndex'];

            switch ($result['action']) {
                case 'disconnect':
                    return;
                case 'quit':
                    return;
                case 'prev':
                    $page--;
                    $selectedIndex = 0;
                    break;
                case 'next':
                    $page++;
                    $selectedIndex = 0;
                    break;
                case 'compose':
                    $this->compose($conn, $state, $session, null);
                    break;
                case 'read':
                    [$page, $selectedIndex] = $this->displayMessage($conn, $state, $session, $page, $perPage, $totalPages, $result['index']);
                    break;
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
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.netmail.compose_title', '=== Compose Netmail ===', [], $state['locale']), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, '');

        if ($reply && !empty($reply['id'])) {
            $detail = TelnetUtils::apiRequest(
                $this->apiBase,
                'GET',
                '/api/messages/netmail/' . $reply['id'],
                null,
                $session
            );
            if (($detail['status'] ?? 0) === 200 && !empty($detail['data']['message_text'])) {
                $reply['message_text'] = $detail['data']['message_text'];
            }
        }

        $toNameDefault = $reply['replyto_name'] ?? $reply['from_name'] ?? '';
        $toAddressDefault = $reply['replyto_address'] ?? $reply['from_address'] ?? '';
        $subjectDefault = $reply ? 'Re: ' . MailUtils::normalizeSubject((string)($reply['subject'] ?? '')) : '';

        $toNamePrompt = TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.to_name', 'To Name: ', [], $state['locale']), TelnetUtils::ANSI_CYAN);
        if ($toNameDefault) {
            $toNamePrompt .= TelnetUtils::colorize("[{$toNameDefault}] ", TelnetUtils::ANSI_YELLOW);
        }
        $toName = $this->server->prompt($conn, $state, $toNamePrompt, true);
        if ($toName === null) {
            return;
        }
        if (trim($toName) === '') {
            if ($toNameDefault !== '') {
                $toName = $toNameDefault;
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.no_recipient', 'Recipient name required. Message cancelled.', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
                return;
            }
        }

        $toAddressPrompt = TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.to_address', 'To Address: ', [], $state['locale']), TelnetUtils::ANSI_CYAN);
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

        $subjectPrompt = TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.subject', 'Subject: ', [], $state['locale']), TelnetUtils::ANSI_CYAN);
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
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.enter_message', 'Enter your message below:', [], $state['locale']), TelnetUtils::ANSI_GREEN));

        $cols = $state['cols'] ?? 80;

        $selectedTagline = '';
        $taglines = MailUtils::getTaglines($this->apiBase, $session);
        $defaultTagline = MailUtils::getUserDefaultTagline($this->apiBase, $session);
        if (!empty($taglines)) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.select_tagline', 'Select a tagline:', [], $state['locale']), TelnetUtils::ANSI_CYAN));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.no_tagline', ' 0) None', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            foreach ($taglines as $idx => $tagline) {
                TelnetUtils::writeLine($conn, sprintf(' %d) %s', $idx + 1, $tagline));
            }
            $defaultIndex = 0;
            if ($defaultTagline !== '') {
                foreach ($taglines as $idx => $tagline) {
                    if (trim($tagline) === $defaultTagline) {
                        $defaultIndex = $idx + 1;
                        break;
                    }
                }
            }
            $prompt = $defaultIndex > 0
                ? TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.tagline_default', 'Tagline # [{default}] (Enter for Default): ', ['default' => $defaultIndex], $state['locale']), TelnetUtils::ANSI_CYAN)
                : TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.tagline_none', 'Tagline # (Enter for None): ', [], $state['locale']), TelnetUtils::ANSI_CYAN);
            $choice = $this->server->prompt($conn, $state, $prompt, true);
            if ($choice === null) {
                return;
            }
            $choice = trim($choice);
            if ($choice === '') {
                if ($defaultIndex > 0) {
                    $selectedTagline = $taglines[$defaultIndex - 1];
                }
            } elseif (ctype_digit($choice)) {
                $num = (int)$choice;
                if ($num > 0 && $num <= count($taglines)) {
                    $selectedTagline = $taglines[$num - 1];
                }
            }
        }

        // If replying, quote the original message
        $initialText = '';
        if ($reply) {
            $originalBody = $reply['message_text'] ?? '';
            $originalAuthor = $reply['from_name'] ?? 'Unknown';
            if ($originalBody !== '') {
                $initialText = MailUtils::quoteMessage($originalBody, $originalAuthor, $state);
            }
        }
        $signature = MailUtils::getUserSignature($this->apiBase, $session);
        $initialText = MailUtils::appendSignatureToCompose($initialText, $signature);

        $messageText = $this->server->readMultiline($conn, $state, $cols, $initialText);
        if ($messageText === '') {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.message_cancelled', 'Message cancelled (empty).', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
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
        if ($selectedTagline !== '') {
            $payload['tagline'] = $selectedTagline;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.netmail.sending', 'Sending netmail...', [], $state['locale']), TelnetUtils::ANSI_CYAN));
        $result = MailUtils::sendMessage($this->apiBase, $session, $payload, $state['csrf_token'] ?? null);
        if ($result['success']) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.netmail.send_success', '✓ Netmail sent successfully!', [], $state['locale']), TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.netmail.send_failed', '✗ Failed to send netmail: {error}', ['error' => $result['error'] ?? 'Unknown error'], $state['locale']), TelnetUtils::ANSI_RED));
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
    private function displayMessage($conn, array &$state, string $session, int $page, int $perPage, int $totalPages, int $index): array
    {
        $cols  = $state['cols'] ?? 80;
        $rows  = $state['rows'] ?? 24;
        $width = max(10, $cols - 2);

        $statusLine = TelnetUtils::buildStatusBar([
            ['text' => 'U/D',       'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Scroll  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'PgUp/PgDn', 'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Page  ',   'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'L/R',       'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Prev/Next  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'R',         'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Reply  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',         'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Quit',     'color' => TelnetUtils::ANSI_BLUE],
        ], $width);

        while (true) {
            [$messages, $totalPages] = $this->fetchMessagesPage($session, $page, $perPage);
            $msg = $messages[$index] ?? null;
            if (!$msg) {
                return [$page, 0];
            }
            $id = $msg['id'] ?? null;
            if (!$id) {
                return [$page, $index];
            }

            $detail       = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/messages/netmail/' . $id, null, $session);
            $body         = $detail['data']['message_text'] ?? '';
            $markupFormat = $detail['data']['markup_format'] ?? null;

            $fromName    = $msg['from_name'] ?? 'Unknown';
            $fromAddress = $msg['from_address'] ?? '';
            $fromLine    = $fromAddress ? "From: {$fromName} <{$fromAddress}>" : "From: {$fromName}";
            $border      = str_repeat('-', $width);
            $headerLines = [
                $border,
                TelnetUtils::colorize(substr($fromLine, 0, $width), TelnetUtils::ANSI_DIM),
                TelnetUtils::colorize(substr('Date: ' . TelnetUtils::formatUserDate($msg['date_written'] ?? '', $state), 0, $width), TelnetUtils::ANSI_DIM),
                TelnetUtils::colorize(substr('Subj: ' . ($msg['subject'] ?? 'Message'), 0, $width), TelnetUtils::ANSI_BOLD),
                $border,
            ];

            $wrappedLines = $markupFormat !== null
                ? TerminalMarkupRenderer::render($markupFormat, $body, $width)
                : TelnetUtils::wrapTextLines($body, $width);

            $result = TelnetUtils::runMessageViewer($conn, $state, $this->server, $headerLines, $wrappedLines, $statusLine, $rows);

            switch ($result['action']) {
                case 'quit':
                    return [$page, $index];
                case 'prev':
                    if ($index > 0) { $index--; break; }
                    if ($page > 1)  { $page--; $index = max(0, $perPage - 1); }
                    break;
                case 'next':
                    if ($index < count($messages) - 1) { $index++; break; }
                    if ($page < $totalPages)            { $page++; $index = 0; }
                    break;
                case 'reply':
                    TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                    $this->compose($conn, $state, $session, $detail['data'] ?? $msg);
                    TelnetUtils::setCursorVisible($conn, true);
                    return [$page, $index];
            }
        }
    }

    /**
     * Fetch a page of netmail messages.
     *
     * @return array [messages, totalPages]
     */
    private function fetchMessagesPage(string $session, int $page, int $perPage): array
    {
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
        $messages = array_slice($allMessages, 0, $perPage);

        return [$messages, (int)$totalPages];
    }

}

