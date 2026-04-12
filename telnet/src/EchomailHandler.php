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
    private BbsSession $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    /**
     * Create a new EchomailHandler instance
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
        $savedState      = $this->loadSavedListState($session);
        $page            = $savedState['areas_page'];
        $perPage         = MailUtils::getMessagesPerPage($state);
        $showInterestKey = \BinktermPHP\Config::env('ENABLE_INTERESTS') === 'true';

        while (true) {
            $response = TelnetUtils::apiRequest(
                $this->apiBase, 'GET', '/api/echoareas?subscribed_only=true', null, $session
            );
            $allAreas = $response['data']['echoareas'] ?? [];

            if (!$allAreas) {
                TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.echomail.no_areas', 'No echoareas available.', [], $state['locale']));
                return;
            }

            $result = $this->pickEchoarea(
                $conn, $state, $allAreas, $page, $perPage,
                $this->server->t('ui.terminalserver.echomail.areas_header', 'Echoareas (page {page}/{total}):', [], $state['locale']),
                $showInterestKey,
                function(int $newPage) use ($session, &$state) {
                    $this->saveEchoareasPage($session, $newPage, $state['csrf_token'] ?? null);
                }
            );
            $page = $result['page'];

            if ($result['action'] === 'quit') {
                return;
            }
            if ($result['action'] === 'interests') {
                $this->browseByInterest($conn, $state, $session);
                continue;
            }
            if ($result['action'] === 'redraw') {
                continue;
            }
            if ($result['action'] === 'select') {
                $area   = $result['area'];
                $tag    = $area['tag'] ?? '';
                $domain = $area['domain'] ?? '';
                $this->server->logAction($state['username'] ?? 'unknown', "Echomail: entered area {$tag}@{$domain}");
                $this->showMessages($conn, $state, $session, $tag, $domain);
            }
        }
    }

    /**
     * Show a numbered interest list, let the user pick one, then display
     * that interest's echo areas for selection.
     */
    private function browseByInterest($conn, array &$state, string $session): void
    {
        $locale  = $state['locale'];
        $perPage = MailUtils::getMessagesPerPage($state);

        // ── Interest picker ───────────────────────────────────────────────────
        $userId        = (int)($state['user_id'] ?? 0);
        $interestMgr   = new \BinktermPHP\InterestManager();
        $interests     = $interestMgr->getInterests(true);
        $subscribedIds = $userId > 0
            ? array_flip($interestMgr->getUserSubscribedInterestIds($userId))
            : [];
        foreach ($interests as &$_i) {
            $_i['subscribed'] = isset($subscribedIds[(int)$_i['id']]);
        }
        unset($_i);

        if (empty($interests)) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.interests_none', 'No interests available.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.echomail.interests_title', 'Browse by Interest', [], $locale),
            TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
        ));
        TelnetUtils::writeLine($conn, '');

        foreach ($interests as $idx => $interest) {
            $num        = $idx + 1;
            $name       = (string)($interest['name'] ?? '');
            $subscribed = !empty($interest['subscribed']);
            $badge      = $subscribed
                ? TelnetUtils::colorize('[+]', TelnetUtils::ANSI_GREEN)
                : TelnetUtils::colorize('[ ]', TelnetUtils::ANSI_DIM);
            TelnetUtils::writeLine($conn, sprintf(' %2d) %s %s', $num, $badge, $name));
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, $this->server->t(
            'ui.terminalserver.echomail.interests_prompt', 'Enter # to browse, Q to return:', [], $locale
        ));

        $choice = $this->server->prompt($conn, $state, '> ', true);
        if ($choice === null || strtolower(trim($choice)) === 'q' || trim($choice) === '') {
            return;
        }

        $idx = (int)trim($choice) - 1;
        if (!isset($interests[$idx])) {
            return;
        }

        $interest   = $interests[$idx];
        $interestId = (int)($interest['id'] ?? 0);
        $interestName = (string)($interest['name'] ?? '');

        // ── Area list for chosen interest ─────────────────────────────────────
        $resp  = TelnetUtils::apiRequest($this->apiBase, 'GET', "/api/interests/{$interestId}/echoareas", null, $session);
        $areas = $resp['data']['echoareas'] ?? [];

        if (empty($areas)) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.interests.no_areas', 'No echo areas assigned to this interest.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $page  = 1;
        $title = $this->server->t(
            'ui.terminalserver.echomail.interest_areas_header',
            '{name} (page {page}/{total}):',
            ['name' => $interestName],
            $locale
        );

        while (true) {
            $result = $this->pickEchoarea(
                $conn, $state, $areas, $page, $perPage, $title, false, null
            );
            $page = $result['page'];

            if ($result['action'] === 'quit') {
                return;
            }
            if ($result['action'] === 'redraw') {
                continue;
            }
            if ($result['action'] === 'select') {
                $area   = $result['area'];
                $tag    = $area['tag'] ?? '';
                $domain = $area['domain'] ?? '';
                $this->server->logAction($state['username'] ?? 'unknown', "Echomail: entered area {$tag}@{$domain} via interest \"{$interestName}\"");
                $this->showMessages($conn, $state, $session, $tag, $domain);
            }
        }
    }

    /**
     * Render a paginated echo area list and read one keypress/selection.
     *
     * Returns an array with:
     *   'action' => 'quit' | 'select' | 'interests'
     *   'area'   => array (only when action === 'select')
     *   'page'   => int   (current page after the action)
     *
     * @param callable|null $onPageChange Called with the new page number when page changes (for persistence)
     */
    private function pickEchoarea(
        $conn,
        array &$state,
        array $allAreas,
        int $page,
        int $perPage,
        string $title,
        bool $showInterestKey,
        ?callable $onPageChange
    ): array {
        $locale     = $state['locale'];
        $totalPages = max(1, (int)ceil(count($allAreas) / $perPage));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * $perPage;
        $areas      = array_slice($allAreas, $offset, $perPage);

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");

        // Substitute {page}/{total} into the title
        $header = str_replace(['{page}', '{total}'], [$page, $totalPages], $title);
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($header, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));

        foreach ($areas as $idx => $area) {
            TelnetUtils::writeLine($conn, $this->renderEchoAreaSelectionLine(
                $idx + 1,
                (string)substr($area['tag'] ?? '', 0, 20),
                (string)substr($area['domain'] ?? '', 0, 10),
                (string)substr($area['description'] ?? '', 0, 40)
            ));
        }

        TelnetUtils::writeLine($conn, '');
        $navKey = $showInterestKey
            ? $this->server->t('ui.terminalserver.echomail.areas_nav_interests', 'Enter #, n/p (next/prev), i (by interest), q (quit)', [], $locale)
            : $this->server->t('ui.terminalserver.echomail.areas_nav', 'Enter #, n/p (next/prev), q (quit)', [], $locale);
        TelnetUtils::writeLine($conn, $navKey);

        $buffer = '';
        while (true) {
            $key = $this->server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) {
                return ['action' => 'quit', 'page' => $page];
            }

            $input = null;
            if ($key === 'ENTER') {
                $input = strtolower(trim($buffer));
                $buffer = '';
            } elseif ($key === 'BACKSPACE') {
                if ($buffer !== '') {
                    $buffer = substr($buffer, 0, -1);
                    TelnetUtils::safeWrite($conn, "\x08 \x08");
                }
                continue;
            } elseif (str_starts_with($key, 'CHAR:')) {
                $char  = substr($key, 5);
                $lower = strtolower($char);
                if ($lower === 'q') {
                    return ['action' => 'quit', 'page' => $page];
                }
                if ($lower === 'i' && $showInterestKey) {
                    return ['action' => 'interests', 'page' => $page];
                }
                if ($lower === 'n') {
                    if ($page < $totalPages) {
                        $page++;
                        if ($onPageChange) { ($onPageChange)($page); }
                    }
                    return ['action' => 'redraw', 'page' => $page];
                }
                if ($lower === 'p') {
                    if ($page > 1) {
                        $page--;
                        if ($onPageChange) { ($onPageChange)($page); }
                    }
                    return ['action' => 'redraw', 'page' => $page];
                }
                if (ctype_digit($char)) {
                    $buffer .= $char;
                    TelnetUtils::safeWrite($conn, $char);
                }
                continue;
            } else {
                continue;
            }

            // ENTER was pressed — evaluate $input
            if ($input === '' || $input === 'q') {
                return ['action' => 'quit', 'page' => $page];
            }
            if ($input === 'i' && $showInterestKey) {
                return ['action' => 'interests', 'page' => $page];
            }
            if ($input === 'n') {
                if ($page < $totalPages) {
                    $page++;
                    if ($onPageChange) { ($onPageChange)($page); }
                }
                return ['action' => 'redraw', 'page' => $page];
            }
            if ($input === 'p') {
                if ($page > 1) {
                    $page--;
                    if ($onPageChange) { ($onPageChange)($page); }
                }
                return ['action' => 'redraw', 'page' => $page];
            }
            $choice = (int)$input;
            if ($choice > 0 && $choice <= count($areas)) {
                return ['action' => 'select', 'area' => $areas[$choice - 1], 'page' => $page];
            }
            // Invalid input — redraw
            return ['action' => 'redraw', 'page' => $page];
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
        $area          = $tag . '@' . $domain;
        $this->server->logAction($state['username'] ?? 'unknown', "Echomail: read message list for {$area}");
        $savedState    = $this->loadSavedListState($session);
        $positions     = $savedState['positions'];
        $areaPosition  = $positions[$area] ?? null;
        $page          = max(1, (int)($areaPosition['page'] ?? 1));
        $perPage       = MailUtils::getMessagesPerPage($state);
        $selectedIndex = 0;
        $selectedMessageId = isset($areaPosition['selected_message_id'])
            ? (int)$areaPosition['selected_message_id']
            : null;
        if ($selectedMessageId !== null && $selectedMessageId < 1) {
            $selectedMessageId = null;
        }

        while (true) {
            [$messages, $totalPages] = $this->fetchMessagesPage($session, $area, $page, $perPage);

            if (!$messages) {
                if ($page > 1 && $totalPages > 0) {
                    $page = min($page, $totalPages);
                    $selectedIndex = 0;
                    $selectedMessageId = null;
                    continue;
                }
                TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.echomail.no_messages', 'No echomail messages.', [], $state['locale']));
                return;
            }

            if ($selectedMessageId !== null) {
                $restoredIndex = $this->findMessageIndexById($messages, $selectedMessageId);
                if ($restoredIndex !== null) {
                    $selectedIndex = $restoredIndex;
                }
                $selectedMessageId = null;
            }

            if ($selectedIndex < 0 || $selectedIndex >= count($messages)) {
                $selectedIndex = 0;
            }

            $title  = TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.messages_header', 'Echomail: {area} (page {page}/{total})', ['area' => $area, 'page' => $page, 'total' => $totalPages], $state['locale']),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );
            $result = TelnetUtils::runMessageList($conn, $state, $this->server, $title, $messages, $page, $totalPages, $selectedIndex);
            $selectedIndex = $result['selectedIndex'];
            $currentSelectedId = isset($messages[$selectedIndex]['id']) ? (int)$messages[$selectedIndex]['id'] : null;
            $this->saveEchomailState($session, $positions, $area, $page, $currentSelectedId, $state['csrf_token'] ?? null);

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
                    $this->compose($conn, $state, $session, $area, null);
                    break;
                case 'read':
                    [$page, $selectedIndex] = $this->displayMessage($conn, $state, $session, $area, $page, $perPage, $totalPages, $result['index']);
                    break;
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
        $action = $reply ? "Echomail: composing reply to msg #{$reply['id']} in {$area}" : "Echomail: composing new message in {$area}";
        $this->server->logAction($state['username'] ?? 'unknown', $action);
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.compose_title', '=== Compose Echomail ===', [], $state['locale']), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.area_label', 'Area: {area}', ['area' => $area], $state['locale']), TelnetUtils::ANSI_MAGENTA));
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
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.posting', 'Posting echomail...', [], $state['locale']), TelnetUtils::ANSI_CYAN));
        $result = MailUtils::sendMessage($this->apiBase, $session, $payload, $state['csrf_token'] ?? null);
        if ($result['success']) {
            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: posted message to {$area} subject=\"{$subject}\"");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.post_success', '✓ Echomail posted successfully!', [], $state['locale']), TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
        } else {
            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: failed to post to {$area}: " . ($result['error'] ?? 'unknown'));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.post_failed', '✗ Failed to post echomail: {error}', ['error' => $result['error'] ?? 'Unknown error'], $state['locale']), TelnetUtils::ANSI_RED));
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

            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: read message #{$id} in {$area}");
            $detail       = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/messages/echomail/' . urlencode($area) . '/' . $id, null, $session);
            $body         = $detail['data']['message_text'] ?? '';
            $markupFormat = $detail['data']['markup_format'] ?? null;
            $rawKludges   = ($detail['data']['kludge_lines'] ?? '') . "\n" . ($detail['data']['bottom_kludges'] ?? '');
            $kludgeLines  = TerminalMarkupRenderer::extractKludgeLines($rawKludges);
            $imageRefs    = $markupFormat !== null
                ? TerminalMarkupRenderer::extractImageRefs($markupFormat, $body)
                : [];

            $fromName    = $msg['from_name'] ?? 'Unknown';
            $fromAddress = $msg['from_address'] ?? '';

            // Closure that rebuilds all layout-dependent view components from current $state.
            // Called once on open and again whenever the terminal is resized.
            $buildView = function(array $s) use ($msg, $body, $markupFormat, $area, $fromName, $fromAddress, $imageRefs): array {
                $cols     = $s['cols'] ?? 80;
                $width    = max(10, $cols - 2);
                $charset  = $s['terminal_charset'] ?? 'ascii';
                $fromLine = $fromAddress ? "{$fromName} <{$fromAddress}>" : $fromName;

                $segments = [
                    ['text' => 'U/D',          'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Scroll  ',    'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'PgUp/PgDn',    'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Page  ',      'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'L/R',          'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Prev/Next  ', 'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'R',            'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Reply  ',     'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'H',            'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Headers  ',   'color' => TelnetUtils::ANSI_BLUE],
                ];
                if (!empty($imageRefs)) {
                    $imageHint = count($imageRefs) === 1 ? ' Image  ' : ' Image (1-' . count($imageRefs) . ')  ';
                    $segments[] = ['text' => 'I',         'color' => TelnetUtils::ANSI_RED];
                    $segments[] = ['text' => $imageHint,  'color' => TelnetUtils::ANSI_BLUE];
                }
                $segments[] = ['text' => 'Q',    'color' => TelnetUtils::ANSI_RED];
                $segments[] = ['text' => ' Quit', 'color' => TelnetUtils::ANSI_BLUE];

                return [
                    'headerLines'  => TelnetUtils::buildMessageHeaderBox($width, [
                        ['label' => 'From: ', 'value' => $fromLine,                                                      'style' => 'normal'],
                        ['label' => 'Subj: ', 'value' => $msg['subject'] ?? 'Message',                                  'style' => 'bold'],
                        ['label' => 'To:   ', 'value' => $msg['to_name'] ?? 'All',                                      'style' => 'dim'],
                        ['label' => 'Area: ', 'value' => $area,                                                         'style' => 'dim'],
                        ['label' => 'Date: ', 'value' => TelnetUtils::formatUserDate($msg['date_written'] ?? '', $s),   'style' => 'dim'],
                    ], $charset),
                    'wrappedLines' => $markupFormat !== null
                        ? TerminalMarkupRenderer::render($markupFormat, $body, $width)
                        : TelnetUtils::wrapTextLines($body, $width),
                    'statusLine'   => TelnetUtils::buildStatusBar($segments, $width),
                ];
            };

            $apiBase   = $this->apiBase;
            $server    = $this->server;
            $imageFn   = !empty($imageRefs)
                ? static function(int $idx) use ($conn, &$state, $server, $imageRefs, $apiBase): void {
                    TelnetUtils::showSixelImageViewer($conn, $state, $server, $imageRefs[$idx], count($imageRefs), $apiBase);
                }
                : null;

            $view   = $buildView($state);
            $result = TelnetUtils::runMessageViewer(
                $conn, $state, $this->server,
                $view['headerLines'], $view['wrappedLines'], $view['statusLine'],
                $state['rows'] ?? 24, 0, false, $kludgeLines, $buildView,
                $imageRefs, $imageFn
            );

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
                    $this->compose($conn, $state, $session, $area, $detail['data'] ?? $msg);
                    TelnetUtils::setCursorVisible($conn, true);
                    return [$page, $index];
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
     * Render one echoarea option with cyan number hotkey and blue ")" accent.
     */
    private function renderEchoAreaSelectionLine(int $num, string $tag, string $domain, string $desc): string
    {
        $suffix = sprintf(' %-20s %-10s %s', $tag, $domain, $desc);
        return ' '
            . TelnetUtils::colorize(sprintf('%2d', $num), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
            . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
            . $suffix;
    }

    /**
     * Load saved echomail browser state from user meta.
     *
     * @return array{areas_page:int, positions:array<string,array{page:int,selected_message_id:?int}>}
     */
    private function loadSavedListState(string $session): array
    {
        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'GET',
            '/api/user/terminal-mail-state',
            null,
            $session
        );

        $settings = $response['data']['settings'] ?? [];
        $areasPage = (int)($settings['terminal_echomail_areas_page'] ?? 1);
        $positionsRaw = $settings['terminal_echomail_positions'] ?? '';
        $positions = [];
        if (is_string($positionsRaw) && trim($positionsRaw) !== '') {
            $decoded = json_decode($positionsRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $area => $item) {
                    if (!is_string($area) || !is_array($item)) {
                        continue;
                    }
                    $page = max(1, (int)($item['page'] ?? 1));
                    $selected = $item['selected_message_id'] ?? null;
                    if ($selected !== null) {
                        $selected = (int)$selected;
                        if ($selected < 1) {
                            $selected = null;
                        }
                    }
                    $positions[$area] = [
                        'page' => $page,
                        'selected_message_id' => $selected,
                    ];
                }
            }
        }

        return [
            'areas_page' => max(1, $areasPage),
            'positions' => $positions,
        ];
    }

    /**
     * Save echomail message-list state (per area).
     */
    private function saveEchomailState(
        string $session,
        array &$positions,
        string $area,
        int $page,
        ?int $selectedMessageId,
        ?string $csrfToken = null
    ): void
    {
        $positions[$area] = [
            'page' => max(1, $page),
            'selected_message_id' => ($selectedMessageId !== null && $selectedMessageId > 0) ? $selectedMessageId : null,
        ];

        $payload = [
            'terminal_echomail_positions' => $positions,
        ];

        TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/user/terminal-mail-state',
            $payload,
            $session,
            3,
            $csrfToken
        );
    }

    /**
     * Save current echoarea listing page.
     */
    private function saveEchoareasPage(string $session, int $page, ?string $csrfToken = null): void
    {
        TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/user/terminal-mail-state',
            ['terminal_echomail_areas_page' => max(1, $page)],
            $session,
            3,
            $csrfToken
        );
    }

    /**
     * Find index of a message id in the current message page.
     */
    private function findMessageIndexById(array $messages, int $messageId): ?int
    {
        foreach ($messages as $idx => $msg) {
            if ((int)($msg['id'] ?? 0) === $messageId) {
                return $idx;
            }
        }

        return null;
    }

}
