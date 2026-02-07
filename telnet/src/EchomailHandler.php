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

            $buffer = '';
            while (true) {
                $key = $this->server->readKeyWithIdleCheck($conn, $state);
                if ($key === null) {
                    return;
                }
                if ($key === 'ENTER') {
                    $input = trim($buffer);
                    if ($input === '' || $input === 'q') {
                        return;
                    }
                    if ($input === 'n') {
                        if ($page < $totalPages) {
                            $page++;
                        }
                        break;
                    }
                    if ($input === 'p') {
                        if ($page > 1) {
                            $page--;
                        }
                        break;
                    }
                    $choice = (int)$input;
                    if ($choice > 0 && $choice <= count($areas)) {
                        $area = $areas[$choice - 1];
                        $tag = $area['tag'] ?? '';
                        $domain = $area['domain'] ?? '';
                        $this->showMessages($conn, $state, $session, $tag, $domain);
                    }
                    break;
                }
                if ($key === 'BACKSPACE') {
                    if ($buffer !== '') {
                        $buffer = substr($buffer, 0, -1);
                        TelnetUtils::safeWrite($conn, "\x08 \x08");
                    }
                    continue;
                }
                if (str_starts_with($key, 'CHAR:')) {
                    $char = substr($key, 5);
                    $lower = strtolower($char);
                    if ($lower === 'q') {
                        return;
                    }
                    if ($lower === 'n') {
                        if ($page < $totalPages) {
                            $page++;
                        }
                        break;
                    }
                    if ($lower === 'p') {
                        if ($page > 1) {
                            $page--;
                        }
                        break;
                    }
                    if (ctype_digit($char)) {
                        $buffer .= $char;
                        TelnetUtils::safeWrite($conn, $char);
                        continue;
                    }
                    $buffer .= $char;
                    TelnetUtils::safeWrite($conn, $char);
                }
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

        $selectedIndex = 0;
        while (true) {
            [$messages, $totalPages] = $this->fetchMessagesPage($session, $area, $page, $perPage);

            if (!$messages) {
                TelnetUtils::writeLine($conn, 'No echomail messages.');
                return;
            }

            // Clear screen before displaying
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");

            TelnetUtils::writeLine($conn, TelnetUtils::colorize("Echomail: {$area} (page {$page}/{$totalPages})", TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            $listStartRow = 2;
            $cols = $state['cols'] ?? 80;
            $rows = $state['rows'] ?? 24;
            foreach ($messages as $idx => $msg) {
                $num = $idx + 1;
                $from = $msg['from_name'] ?? 'Unknown';
                $subject = $msg['subject'] ?? '(no subject)';
                $dateShort = TelnetUtils::formatUserDate($msg['date_written'] ?? '', $state, false);
                $line = TelnetUtils::formatMessageListLine($num, $from, $subject, $dateShort, $cols);
                if ($idx === $selectedIndex) {
                    $line = TelnetUtils::colorize($line, TelnetUtils::ANSI_BG_BLUE . TelnetUtils::ANSI_BOLD);
                }
                TelnetUtils::writeLine($conn, $line);
            }
            $inputRow = max(1, $rows - 1);

            // Build status bar with menu options
            $statusLine = TelnetUtils::buildStatusBar([
                ['text' => 'U/D', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Move  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'L/R', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Page  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'C', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Compose  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Enter', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Read  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Q', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Quit', 'color' => TelnetUtils::ANSI_BLUE],
            ], $cols);

            TelnetUtils::safeWrite($conn, "\033[{$inputRow};1H\033[K");
            TelnetUtils::safeWrite($conn, $statusLine . "\r");
            TelnetUtils::safeWrite($conn, "\033[{$inputRow};1H");

            $buffer = '';
            while (true) {
                $key = $this->server->readKeyWithIdleCheck($conn, $state);
                if ($key === null) {
                    return;
                }
                if ($key === 'LEFT') {
                    if ($page > 1) {
                        $page--;
                        $selectedIndex = 0;
                        break;
                    }
                    continue;
                }
                if ($key === 'RIGHT') {
                    if ($page < $totalPages) {
                        $page++;
                        $selectedIndex = 0;
                        break;
                    }
                    continue;
                }
                if ($key === 'UP') {
                    if ($selectedIndex > 0) {
                        $prevIndex = $selectedIndex;
                        $selectedIndex--;
                        $this->renderMessageListLine($conn, $messages, $prevIndex, false, $listStartRow, $cols, $state);
                        $this->renderMessageListLine($conn, $messages, $selectedIndex, true, $listStartRow, $cols, $state);
                    }
                    TelnetUtils::safeWrite($conn, "\033[{$inputRow};" . ($inputColStart + strlen($buffer)) . "H");
                    continue;
                }
                if ($key === 'DOWN') {
                    if ($selectedIndex < count($messages) - 1) {
                        $prevIndex = $selectedIndex;
                        $selectedIndex++;
                        $this->renderMessageListLine($conn, $messages, $prevIndex, false, $listStartRow, $cols, $state);
                        $this->renderMessageListLine($conn, $messages, $selectedIndex, true, $listStartRow, $cols, $state);
                    }
                    TelnetUtils::safeWrite($conn, "\033[{$inputRow};" . ($inputColStart + strlen($buffer)) . "H");
                    continue;
                }
                if ($key === 'ENTER') {
                    $input = trim($buffer);
                    if ($input === '') {
                        $msg = $messages[$selectedIndex] ?? null;
                        $id = $msg['id'] ?? null;
                        if ($msg && $id) {
                            [$page, $selectedIndex] = $this->displayMessage($conn, $state, $session, $area, $page, $perPage, $totalPages, $selectedIndex);
                        }
                        break;
                    }
                    if ($input === 'q') {
                        return;
                    }
                    if ($input === 'c') {
                        $this->compose($conn, $state, $session, $area, null);
                        break;
                    }
                    if ($input === 'n') {
                        if ($page < $totalPages) {
                            $page++;
                            $selectedIndex = 0;
                        }
                        break;
                    }
                    if ($input === 'p') {
                        if ($page > 1) {
                            $page--;
                            $selectedIndex = 0;
                        }
                        break;
                    }
                    $choice = (int)$input;
                    if ($choice > 0 && $choice <= count($messages)) {
                        $msg = $messages[$choice - 1];
                        $id = $msg['id'] ?? null;
                        if ($id) {
                            [$page, $selectedIndex] = $this->displayMessage($conn, $state, $session, $area, $page, $perPage, $totalPages, $choice - 1);
                        }
                    }
                    break;
                }
                if ($key === 'BACKSPACE') {
                    if ($buffer !== '') {
                        $buffer = substr($buffer, 0, -1);
                        TelnetUtils::safeWrite($conn, "\x08 \x08");
                    }
                    continue;
                }
                if (str_starts_with($key, 'CHAR:')) {
                    $char = substr($key, 5);
                    $lower = strtolower($char);
                    if ($lower === 'q') {
                        return;
                    }
                    if ($lower === 'c') {
                        $this->compose($conn, $state, $session, $area, null);
                        break;
                    }
                    if ($lower === 'n') {
                        if ($page < $totalPages) {
                            $page++;
                            $selectedIndex = 0;
                        }
                        break;
                    }
                    if ($lower === 'p') {
                        if ($page > 1) {
                            $page--;
                            $selectedIndex = 0;
                        }
                        break;
                    }
                    if (ctype_digit($char)) {
                        $choice = (int)$char;
                        if ($choice > 0 && $choice <= count($messages)) {
                            $msg = $messages[$choice - 1];
                            $id = $msg['id'] ?? null;
                            if ($id) {
                                [$page, $selectedIndex] = $this->displayMessage($conn, $state, $session, $area, $page, $perPage, $totalPages, $choice - 1);
                            }
                        }
                        break;
                    }
                    $buffer .= $char;
                    TelnetUtils::safeWrite($conn, $char);
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

        if ($reply && !empty($reply['id'])) {
            $detail = TelnetUtils::apiRequest(
                $this->apiBase,
                'GET',
                '/api/messages/echomail/' . urlencode($area) . '/' . $reply['id'],
                null,
                $session
            );
            if (($detail['status'] ?? 0) === 200 && !empty($detail['data']['message_text'])) {
                $reply['message_text'] = $detail['data']['message_text'];
            }
        }

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
        if (trim($toName) === '') {
            if ($toNameDefault !== '') {
                $toName = $toNameDefault;
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize('Recipient name required. Message cancelled.', TelnetUtils::ANSI_YELLOW));
                return;
            }
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

        $selectedTagline = '';
        $taglines = MailUtils::getTaglines($this->apiBase, $session);
        $defaultTagline = MailUtils::getUserDefaultTagline($this->apiBase, $session);
        if (!empty($taglines)) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Select a tagline:', TelnetUtils::ANSI_CYAN));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(' 0) None', TelnetUtils::ANSI_YELLOW));
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
                ? TelnetUtils::colorize("Tagline # [{$defaultIndex}] (Enter for Default): ", TelnetUtils::ANSI_CYAN)
                : TelnetUtils::colorize('Tagline # (Enter for None): ', TelnetUtils::ANSI_CYAN);
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
            'type' => 'echomail',
            'echoarea' => $area,
            'to_name' => $toName,
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
    private function displayMessage($conn, array &$state, string $session, string $area, int $page, int $perPage, int $totalPages, int $index): array
    {
        $cols = $state['cols'] ?? 80;
        $rows = $state['rows'] ?? 24;
        $width = max(10, $cols - 2);

        $offset = 0;
        while (true) {
            [$messages, $totalPages] = $this->fetchMessagesPage($session, $area, $page, $perPage);
            $msg = $messages[$index] ?? null;
            if (!$msg) {
                return [$page, 0];
            }
            $id = $msg['id'] ?? null;
            if (!$id) {
                return [$page, $index];
            }

            $detail = TelnetUtils::apiRequest(
                $this->apiBase,
                'GET',
                '/api/messages/echomail/' . urlencode($area) . '/' . $id,
                null,
                $session
            );
            $body = $detail['data']['message_text'] ?? '';

            // Format from line with address
            $fromName = $msg['from_name'] ?? 'Unknown';
            $fromAddress = $msg['from_address'] ?? '';
            $fromLine = $fromAddress ? "From: {$fromName} <{$fromAddress}>" : "From: {$fromName}";

            // Format date using user's timezone and date format preferences
            $dateFormatted = TelnetUtils::formatUserDate($msg['date_written'] ?? '', $state);

            $border = str_repeat('-', $width);
            $headerLines = [
                $border,
                TelnetUtils::colorize(substr($fromLine, 0, $width), TelnetUtils::ANSI_DIM),
                TelnetUtils::colorize(substr('Subj: ' . ($msg['subject'] ?? 'Message'), 0, $width), TelnetUtils::ANSI_BOLD),
                TelnetUtils::colorize(substr('To: ' . ($msg['to_name'] ?? 'All'), 0, $width), TelnetUtils::ANSI_DIM),
                TelnetUtils::colorize(substr('Area: ' . $area, 0, $width), TelnetUtils::ANSI_DIM),
                TelnetUtils::colorize(substr('Date: ' . $dateFormatted, 0, $width), TelnetUtils::ANSI_DIM),
                $border
            ];

            $wrappedLines = TelnetUtils::wrapTextLines($body, $width);
            $bodyHeight = max(1, $rows - count($headerLines) - 1);
            $maxOffset = max(0, count($wrappedLines) - $bodyHeight);
            $offset = min($offset, $maxOffset);

            $visibleLines = array_slice($wrappedLines, $offset, $bodyHeight);
            $statusLine = TelnetUtils::buildStatusBar([
                ['text' => 'U/D', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Scroll  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'L/R', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Prev/Next  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'R', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Reply  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Q', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Quit', 'color' => TelnetUtils::ANSI_BLUE],
            ], $width);
            TelnetUtils::renderFullScreen($conn, $headerLines, $visibleLines, $statusLine, $rows);

            $key = $this->server->readKeyWithIdleCheck($conn, $state);
            if ($key === null || $key === 'ENTER') {
                TelnetUtils::setCursorVisible($conn, true);
                return [$page, $index];
            }
            if ($key === 'CHAR:q' || $key === 'CHAR:Q') {
                TelnetUtils::setCursorVisible($conn, true);
                return [$page, $index];
            }
            if ($key === 'UP') {
                if ($offset > 0) {
                    $offset--;
                }
                continue;
            }
            if ($key === 'DOWN') {
                if ($offset < $maxOffset) {
                    $offset++;
                }
                continue;
            }
            if ($key === 'HOME') {
                $offset = 0;
                continue;
            }
            if ($key === 'END') {
                $offset = $maxOffset;
                continue;
            }
            if ($key === 'LEFT') {
                if ($index > 0) {
                    $index--;
                    $offset = 0;
                    continue;
                }
                if ($page > 1) {
                    $page--;
                    $index = max(0, $perPage - 1);
                    $offset = 0;
                }
                continue;
            }
            if ($key === 'RIGHT') {
                if ($index < count($messages) - 1) {
                    $index++;
                    $offset = 0;
                    continue;
                }
                if ($page < $totalPages) {
                    $page++;
                    $index = 0;
                    $offset = 0;
                }
                continue;
            }
            if (str_starts_with($key, 'CHAR:')) {
                $char = strtolower(substr($key, 5));
                if ($char === 'r') {
                    $replyData = $detail['data'] ?? $msg;
                    TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                    $this->compose($conn, $state, $session, $area, $replyData);
                    TelnetUtils::setCursorVisible($conn, true);
                    return [$page, $index];
                }
            }
        }
    }

    /**
     * Fetch a page of echomail messages for an area.
     *
     * @return array [messages, totalPages]
     */
    private function fetchMessagesPage(string $session, string $area, int $page, int $perPage): array
    {
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
        $messages = array_slice($allMessages, 0, $perPage);

        return [$messages, (int)$totalPages];
    }

    /**
     * Re-render a single message list line without redrawing the whole screen.
     */
    private function renderMessageListLine($conn, array $messages, int $idx, bool $selected, int $listStartRow, int $cols, array &$state): void
    {
        if (!isset($messages[$idx])) {
            return;
        }
        $msg = $messages[$idx];
        $num = $idx + 1;
        $from = $msg['from_name'] ?? 'Unknown';
        $subject = $msg['subject'] ?? '(no subject)';
        $dateShort = TelnetUtils::formatUserDate($msg['date_written'] ?? '', $state, false);
        $line = TelnetUtils::formatMessageListLine($num, $from, $subject, $dateShort, $cols);
        if ($selected) {
            $line = TelnetUtils::colorize($line, TelnetUtils::ANSI_BG_BLUE . TelnetUtils::ANSI_BOLD);
        }
        $row = $listStartRow + $idx;
        TelnetUtils::safeWrite($conn, "\033[{$row};1H");
        TelnetUtils::safeWrite($conn, str_pad($line, max(1, $cols - 1)));
    }
}
