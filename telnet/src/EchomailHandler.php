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
        $searchFilter    = null;
        $allAreasMode    = false;

        while (true) {
            $locale = $state['locale'];
            $url    = $allAreasMode
                ? '/api/echoareas'
                : '/api/echoareas?subscribed_only=true';
            $response = TelnetUtils::apiRequest($this->apiBase, 'GET', $url, null, $session);
            $allAreas = $response['data']['echoareas'] ?? [];

            if (!$allAreas) {
                if (!$allAreasMode) {
                    // No subscribed areas — show a hint and let the user press A or Q
                    TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                    TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                        $this->server->t('ui.terminalserver.echomail.no_areas', 'You are not subscribed to any areas.', [], $locale),
                        TelnetUtils::ANSI_YELLOW
                    ));
                    TelnetUtils::writeLine($conn, '');
                    TelnetUtils::writeLine($conn, $this->server->t(
                        'ui.terminalserver.echomail.no_areas_browse_hint',
                        'Press A to browse all areas, Q to quit.',
                        [],
                        $locale
                    ));
                    while (true) {
                        $key = $this->server->readKeyWithIdleCheck($conn, $state);
                        if ($key === null) {
                            return;
                        }
                        if (str_starts_with($key, 'CHAR:')) {
                            $char = strtolower(substr($key, 5));
                            if ($char === 'q') {
                                return;
                            }
                            if ($char === 'a') {
                                $allAreasMode = true;
                                $page = 1;
                                break;
                            }
                        }
                    }
                    continue;
                }
                TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.echomail.no_areas_all', 'No echo areas available.', [], $locale));
                return;
            }

            $filteredAreas = $searchFilter !== null
                ? $this->filterAreas($allAreas, $searchFilter)
                : $allAreas;

            $headerKey      = $allAreasMode ? 'ui.terminalserver.echomail.areas_all_header' : 'ui.terminalserver.echomail.areas_header';
            $headerFallback = $allAreasMode ? 'All Echoareas (page {page}/{total}):' : 'Echoareas (page {page}/{total}):';

            $result = $this->pickEchoarea(
                $conn, $state, $filteredAreas, $page, $perPage,
                $this->server->t($headerKey, $headerFallback, [], $locale),
                $showInterestKey,
                function(int $newPage) use ($session, &$state) {
                    $this->saveEchoareasPage($session, $newPage, $state['csrf_token'] ?? null);
                },
                $searchFilter,
                $allAreasMode
            );
            $page = $result['page'];

            switch ($result['action']) {
                case 'quit':
                    return;

                case 'allareas':
                    $allAreasMode = !$allAreasMode;
                    $page         = 1;
                    $searchFilter = null;
                    break;

                case 'filter':
                    $searchFilter = $result['filter'];
                    $page         = 1;
                    break;

                case 'interests':
                    $this->browseByInterest($conn, $state, $session);
                    break;

                case 'search':
                    $this->searchAllAreas($conn, $state, $session);
                    break;

                case 'unsubscribe':
                    $area = $result['area'] ?? null;
                    if (!$area || empty($area['subscribed'])) {
                        break; // nothing to unsubscribe
                    }
                    $areaLabel = $this->formatEchoareaIdentifier($area['tag'] ?? '', $area['domain'] ?? '');
                    $choice    = TelnetUtils::showConfirmDialog(
                        $conn, $state, $this->server,
                        $this->server->t('ui.terminalserver.echomail.unsubscribe_title', 'Unsubscribe', [], $locale),
                        $this->server->t('ui.terminalserver.echomail.unsubscribe_prompt', 'Unsubscribe from {area}?', ['area' => $areaLabel], $locale),
                        [
                            'y' => $this->server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $locale),
                            'n' => $this->server->t('ui.terminalserver.server.confirm_no',  'Cancel',  [], $locale),
                        ],
                        'n'
                    );
                    if ($choice === 'y') {
                        $ok  = $this->callUnsubscribeArea($session, (int)($area['id'] ?? 0), $state['csrf_token'] ?? null);
                        $msg = $ok
                            ? $this->server->t('ui.terminalserver.echomail.unsubscribe_success', 'Unsubscribed from {area}.', ['area' => $areaLabel], $locale)
                            : $this->server->t('ui.terminalserver.echomail.unsubscribe_failed', 'Failed to unsubscribe from {area}.', ['area' => $areaLabel], $locale);
                        TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Unsubscribe', $msg, $ok ? 'info' : 'error');
                        if ($ok) {
                            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: unsubscribed from {$areaLabel}");
                        }
                    }
                    break;

                case 'select':
                    $area      = $result['area'];
                    $tag       = $area['tag'] ?? '';
                    $domain    = $area['domain'] ?? '';
                    $areaLabel = $this->formatEchoareaIdentifier($tag, $domain);

                    if ($allAreasMode && empty($area['subscribed'])) {
                        $choice = TelnetUtils::showConfirmDialog(
                            $conn, $state, $this->server,
                            $this->server->t('ui.terminalserver.echomail.subscribe_title', 'Subscribe?', [], $locale),
                            $this->server->t('ui.terminalserver.echomail.subscribe_prompt', 'Subscribe to {area}?', ['area' => $areaLabel], $locale),
                            [
                                's' => $this->server->t('ui.terminalserver.echomail.subscribe_and_browse', 'Subscribe & Browse', [], $locale),
                                'b' => $this->server->t('ui.terminalserver.echomail.browse_only', 'Browse Only', [], $locale),
                                'q' => $this->server->t('ui.terminalserver.server.confirm_no', 'Cancel', [], $locale),
                            ],
                            'b'
                        );
                        if ($choice === 'q') {
                            break;
                        }
                        if ($choice === 's') {
                            $ok = $this->callSubscribeArea($session, (int)($area['id'] ?? 0), $state['csrf_token'] ?? null);
                            if (!$ok) {
                                TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Subscribe',
                                    $this->server->t('ui.terminalserver.echomail.subscribe_failed', 'Failed to subscribe to {area}.', ['area' => $areaLabel], $locale),
                                    'error');
                                break;
                            }
                            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: subscribed to {$areaLabel}");
                        }
                    }

                    $this->server->logAction($state['username'] ?? 'unknown', "Echomail: entered area {$areaLabel}");
                    $this->showMessages($conn, $state, $session, $tag, $domain);
                    break;
            }
        }
    }

    /**
     * Subscribe the current user to an echoarea via the subscriptions API.
     *
     * @return bool True on success
     */
    private function callSubscribeArea(string $session, int $echoareaId, ?string $csrfToken): bool
    {
        $result = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', '/api/subscriptions/user',
            ['action' => 'subscribe', 'echoarea_id' => $echoareaId],
            $session, 3, $csrfToken
        );
        return ($result['status'] === 200) && !empty($result['data']['success']);
    }

    /**
     * Unsubscribe the current user from an echoarea via the subscriptions API.
     *
     * @return bool True on success
     */
    private function callUnsubscribeArea(string $session, int $echoareaId, ?string $csrfToken): bool
    {
        $result = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', '/api/subscriptions/user',
            ['action' => 'unsubscribe', 'echoarea_id' => $echoareaId],
            $session, 3, $csrfToken
        );
        return ($result['status'] === 200) && !empty($result['data']['success']);
    }

    /**
     * Prompt for a search term and display matching messages within a single echoarea.
     *
     * @param string $area Echoarea identifier (tag@domain)
     */
    private function searchInArea($conn, array &$state, string $session, string $area): void
    {
        $locale = $state['locale'];

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::safeWrite($conn, $this->server->t(
            'ui.terminalserver.echomail.search_messages_prompt',
            'Search messages: ',
            [], $locale
        ));

        $term = $this->server->readLineWithIdleCheck($conn, $state);
        if ($term === null) {
            return;
        }
        $term = trim($term);

        if (strlen($term) < 2) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.search_term_too_short', 'Search term must be at least 2 characters.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        TelnetUtils::showWorkingOverlay($conn, $state, $this->server, 'Searching...');

        $response = TelnetUtils::apiRequest(
            $this->apiBase, 'GET',
            '/api/messages/search?' . http_build_query(['q' => $term, 'type' => 'echomail', 'echoarea' => $area]),
            null, $session
        );

        $messages = $response['data']['messages'] ?? [];

        if (empty($messages)) {
            TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Search',
                $this->server->t('ui.terminalserver.echomail.search_no_results', 'No messages found for \'{term}\'.', ['term' => $term], $locale),
                'info');
            return;
        }

        $this->showAreaSearchResults($conn, $state, $session, $term, $area, $messages);
    }

    /**
     * Display a paginated list of within-area search results and allow reading individual messages.
     *
     * Uses the standard message list format (no area tag prefix since all results share the same area).
     * Prev/next in the viewer navigates the flat search result list, not the area message list.
     *
     * @param string $area       Echoarea identifier (tag@domain), used for compose
     * @param array  $allMessages Full flat list of search result messages from the API
     */
    private function showAreaSearchResults($conn, array &$state, string $session, string $term, string $area, array $allMessages): void
    {
        $perPage    = MailUtils::getMessagesPerPage($state);
        $totalPages = max(1, (int)ceil(count($allMessages) / $perPage));
        $page       = 1;
        $selectedIndex = 0;

        while (true) {
            $offset       = ($page - 1) * $perPage;
            $pageMessages = array_slice($allMessages, $offset, $perPage);

            $title = TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.echomail.search_results_header',
                    'Search: {term} (page {page}/{total})',
                    ['term' => $term, 'page' => $page, 'total' => $totalPages],
                    $state['locale']
                ),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );

            $result = TelnetUtils::runMessageList(
                $conn, $state, $this->server, $title, $pageMessages, $page, $totalPages, $selectedIndex
            );
            $selectedIndex = $result['selectedIndex'];

            switch ($result['action']) {
                case 'disconnect':
                case 'quit':
                    return;
                case 'prev':
                    if ($page > 1) { $page--; $selectedIndex = 0; }
                    break;
                case 'next':
                    if ($page < $totalPages) { $page++; $selectedIndex = 0; }
                    break;
                case 'compose':
                    $this->compose($conn, $state, $session, $area, null);
                    break;
                case 'read':
                    $absIndex    = $offset + $result['index'];
                    $newAbsIndex = $this->displaySearchMessage($conn, $state, $session, $allMessages, $absIndex, $term);
                    $page        = max(1, (int)floor($newAbsIndex / $perPage) + 1);
                    $selectedIndex = $newAbsIndex - ($page - 1) * $perPage;
                    break;
            }
        }
    }

    /**
     * Prompt for a search term and display matching echomail messages across all subscribed areas.
     *
     * Calls GET /api/messages/search?q=<term>&type=echomail, then presents results
     * in a paginated selectable list.  Selecting a message opens it in the viewer.
     */
    private function searchAllAreas($conn, array &$state, string $session): void
    {
        $locale = $state['locale'];

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::safeWrite($conn, $this->server->t(
            'ui.terminalserver.echomail.search_messages_prompt',
            'Search messages: ',
            [], $locale
        ));

        $term = $this->server->readLineWithIdleCheck($conn, $state);
        if ($term === null) {
            return;
        }
        $term = trim($term);

        if (strlen($term) < 2) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.search_term_too_short', 'Search term must be at least 2 characters.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        TelnetUtils::showWorkingOverlay($conn, $state, $this->server, 'Searching...');

        $response = TelnetUtils::apiRequest(
            $this->apiBase, 'GET',
            '/api/messages/search?' . http_build_query(['q' => $term, 'type' => 'echomail']),
            null, $session
        );

        $messages = $response['data']['messages'] ?? [];

        if (empty($messages)) {
            TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Search',
                $this->server->t('ui.terminalserver.echomail.search_no_results', 'No messages found for \'{term}\'.', ['term' => $term], $locale),
                'info');
            return;
        }

        $this->showSearchResults($conn, $state, $session, $term, $messages);
    }

    /**
     * Display a paginated list of echomail search results and allow reading individual messages.
     *
     * @param array $allMessages Full flat list of search result messages (from API)
     */
    private function showSearchResults($conn, array &$state, string $session, string $term, array $allMessages): void
    {
        $perPage      = MailUtils::getMessagesPerPage($state);
        $totalCount   = count($allMessages);
        $totalPages   = max(1, (int)ceil($totalCount / $perPage));
        $page         = 1;
        $selectedIndex = 0;

        while (true) {
            $offset       = ($page - 1) * $perPage;
            $pageMessages = array_slice($allMessages, $offset, $perPage);
            $locale       = $state['locale'];

            // Prepend the echoarea tag to the from_name so the area is visible in the list.
            $displayMessages = array_map(function (array $msg): array {
                $copy = $msg;
                $copy['from_name'] = '[' . ($msg['echoarea'] ?? '?') . '] ' . ($msg['from_name'] ?? '');
                return $copy;
            }, $pageMessages);

            $title = TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.echomail.search_results_header',
                    'Search: {term} (page {page}/{total})',
                    ['term' => $term, 'page' => $page, 'total' => $totalPages],
                    $locale
                ),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );

            $cols = $state['cols'] ?? 80;
            $rows = [];
            foreach ($displayMessages as $idx => $msg) {
                $rows[] = TelnetUtils::formatMessageListEntry($msg, $idx + 1, false, $cols, $state);
            }
            $server = $this->server;
            if (method_exists($server, 'encodeForTerminal')) {
                $rows         = array_map(static fn(string $r): string => $server->encodeForTerminal($r), $rows);
                $encodedTitle = $server->encodeForTerminal($title);
            } else {
                $encodedTitle = $title;
            }

            $rebuildFn = static function (array &$s) use ($displayMessages, $server, $encodedTitle): array {
                $newCols = $s['cols'] ?? 80;
                $newRows = [];
                foreach ($displayMessages as $idx => $msg) {
                    $newRows[] = TelnetUtils::formatMessageListEntry($msg, $idx + 1, false, $newCols, $s);
                }
                if (method_exists($server, 'encodeForTerminal')) {
                    $newRows = array_map(static fn(string $r): string => $server->encodeForTerminal($r), $newRows);
                }
                return ['rows' => $newRows, 'title' => $encodedTitle];
            };

            $statusBar = [
                ['text' => 'U/D',     'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Move  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'L/R',     'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Page  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Enter',   'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Read  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Q',       'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Quit',   'color' => TelnetUtils::ANSI_BLUE],
            ];

            $result = TelnetUtils::runSelectableList(
                $conn, $state, $this->server,
                $encodedTitle, $rows, $page, $totalPages, $selectedIndex,
                $statusBar, [], $rebuildFn
            );
            $selectedIndex = $result['selectedIndex'];

            switch ($result['action']) {
                case 'disconnect':
                case 'quit':
                    return;
                case 'prev':
                    if ($page > 1) { $page--; $selectedIndex = 0; }
                    break;
                case 'next':
                    if ($page < $totalPages) { $page++; $selectedIndex = 0; }
                    break;
                case 'select':
                    $absIndex    = $offset + $result['index'];
                    $newAbsIndex = $this->displaySearchMessage($conn, $state, $session, $allMessages, $absIndex, $term);
                    $page        = max(1, (int)floor($newAbsIndex / $perPage) + 1);
                    $selectedIndex = $newAbsIndex - ($page - 1) * $perPage;
                    break;
            }
        }
    }

    /**
     * Open a single echomail search result in the message viewer.
     *
     * Prev/next navigate the flat $allMessages array across area boundaries.
     * Returns the index of the message that was active when the user quit.
     *
     * @param array $allMessages Full flat search result list
     * @param int   $index       Zero-based index into $allMessages to open
     * @return int The index active when the viewer was closed
     */
    private function displaySearchMessage($conn, array &$state, string $session, array $allMessages, int $index, string $searchTerm = ''): int
    {
        while (true) {
            $msg = $allMessages[$index] ?? null;
            if (!$msg) {
                return $index;
            }

            $id     = (int)($msg['id'] ?? 0);
            $tag    = (string)($msg['echoarea'] ?? '');
            $domain = (string)($msg['echoarea_domain'] ?? '');
            $area   = $this->formatEchoareaIdentifier($tag, $domain);

            $this->server->logAction($state['username'] ?? 'unknown', "Echomail search: read message #{$id} in {$area}");

            $detail       = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/messages/echomail/' . urlencode($area) . '/' . $id, null, $session);
            $body         = $detail['data']['message_text'] ?? '';
            $markupFormat = $detail['data']['markup_format'] ?? null;
            $rawKludges   = ($detail['data']['kludge_lines'] ?? '') . "\n" . ($detail['data']['bottom_kludges'] ?? '');
            $kludgeLines  = TerminalMarkupRenderer::extractKludgeLines($rawKludges);
            $kludgeLines  = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $kludgeLines);
            $imageRefs    = TerminalMarkupRenderer::extractImageRefs((string)($markupFormat ?? ''), $body);
            $isSaved      = (bool)($detail['data']['is_saved'] ?? false);

            $fromName    = (string)($msg['from_name'] ?? 'Unknown');
            $fromAddress = (string)($msg['from_address'] ?? '');

            $buildView = function (array $s) use ($msg, $body, $markupFormat, $area, $fromName, $fromAddress, $imageRefs, $searchTerm): array {
                $cols     = $s['cols'] ?? 80;
                $width    = max(10, $cols - 2);
                $charset  = $this->server->getTerminalCharset();
                $fromLine = $fromAddress ? "{$fromName} <{$fromAddress}>" : $fromName;

                $segments = [
                    ['text' => 'U/D',          'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Scroll  ',    'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'L/R',          'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Prev/Next  ', 'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'R',            'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Reply  ',     'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'Ctrl-K',       'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Help  ',      'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'Q',            'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Quit',        'color' => TelnetUtils::ANSI_BLUE],
                ];

                $wrappedLines = $markupFormat !== null
                    ? TerminalMarkupRenderer::render($markupFormat, $body, $width)
                    : TelnetUtils::wrapTextLines($body, $width);
                $wrappedLines = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $wrappedLines);
                if ($searchTerm !== '') {
                    $wrappedLines = $this->highlightSearchTerm($wrappedLines, $searchTerm);
                }

                return [
                    'headerLines'  => TelnetUtils::buildMessageHeaderBox($width, [
                        ['label' => 'From: ', 'value' => $fromLine,                                                      'style' => 'normal'],
                        ['label' => 'Subj: ', 'value' => $msg['subject'] ?? 'Message',                                  'style' => 'bold'],
                        ['label' => 'To:   ', 'value' => $msg['to_name'] ?? 'All',                                      'style' => 'dim'],
                        ['label' => 'Area: ', 'value' => $area,                                                         'style' => 'dim'],
                        ['label' => 'Date: ', 'value' => TelnetUtils::formatUserDate($msg['date_written'] ?? '', $s),   'style' => 'dim'],
                    ], $charset),
                    'wrappedLines' => $wrappedLines,
                    'statusLine'   => TelnetUtils::buildStatusBar($segments, $width),
                ];
            };

            $apiBase = $this->apiBase;
            $server  = $this->server;
            $imageFn = !empty($imageRefs)
                ? static function (int $idx) use ($conn, &$state, $server, $imageRefs, $apiBase): void {
                    TelnetUtils::showSixelImageViewer($conn, $state, $server, $imageRefs[$idx], count($imageRefs), $apiBase);
                }
                : null;

            $view      = $buildView($state);
            $locale    = $state['locale'] ?? 'en';
            $helpItems = [
                ['key' => 'PgUp / PgDn', 'label' => $this->server->t('ui.terminalserver.message.help_page',       'Scroll one page',            [], $locale)],
                ['key' => 'H',           'label' => $this->server->t('ui.terminalserver.message.help_headers',    'View message headers',        [], $locale)],
                ['key' => 'B',           'label' => $this->server->t('ui.terminalserver.echomail.help_bookmark',  'Bookmark / unsave message',   [], $locale)],
                ['key' => 'T',           'label' => $this->server->t('ui.terminalserver.echomail.help_text_dl',   'Download as .txt (ZMODEM)',   [], $locale)],
                ['key' => 'E',           'label' => $this->server->t('ui.terminalserver.echomail.help_email_fwd', 'Forward to my email address', [], $locale)],
            ];
            if (!empty($imageRefs)) {
                $helpItems[] = ['key' => 'I', 'label' => $this->server->t('ui.terminalserver.message.help_images', 'View inline image(s)', [], $locale)];
            }

            $result = TelnetUtils::runMessageViewer(
                $conn, $state, $this->server,
                $view['headerLines'], $view['wrappedLines'], $view['statusLine'],
                $state['rows'] ?? 24, 0, false, $kludgeLines, $buildView,
                $imageRefs, $imageFn, ['b' => 'save', 't' => 'download', 'e' => 'emailforward'], $helpItems
            );

            switch ($result['action']) {
                case 'quit':
                    return $index;
                case 'prev':
                    if ($index > 0) { $index--; }
                    break;
                case 'next':
                    if ($index < count($allMessages) - 1) { $index++; }
                    break;
                case 'reply':
                    TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                    $this->compose($conn, $state, $session, $area, $detail['data'] ?? $msg);
                    TelnetUtils::setCursorVisible($conn, true);
                    return $index;
                case 'save':
                    $csrfToken = $state['csrf_token'] ?? null;
                    if ($isSaved) {
                        TelnetUtils::apiRequest($this->apiBase, 'DELETE', '/api/messages/echomail/' . $id . '/save', null, $session, 3, $csrfToken);
                        $confirmMsg = 'Message removed from saved.';
                    } else {
                        TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/echomail/' . $id . '/save', null, $session, 3, $csrfToken);
                        $confirmMsg = 'Message saved.';
                    }
                    $isSaved = !$isSaved;
                    $detail['data']['is_saved'] = $isSaved;
                    TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Bookmark', $confirmMsg, 'info');
                    break;
                case 'download':
                    $this->downloadAsText($conn, $state, $session, $id, $msg['subject'] ?? 'message');
                    break;
                case 'emailforward':
                    $csrfToken = $state['csrf_token'] ?? null;
                    TelnetUtils::showWorkingOverlay($conn, $state, $this->server, 'Forwarding message to email...');
                    $fwdResult = TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/echomail/' . $id . '/forward-email', null, $session, 3, $csrfToken);
                    if ($fwdResult['status'] === 200) {
                        TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Email Forward', 'Forwarded to your email address.', 'info');
                    } else {
                        $errMsg = $fwdResult['data']['error'] ?? 'Failed to forward message.';
                        TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Email Forward', $errMsg, 'error');
                    }
                    break;
            }
        }
    }

    /**
     * Wrap each case-insensitive match of $term in the given lines with ANSI yellow highlighting.
     *
     * The replacement is ANSI-aware: ANSI escape sequences are passed through unchanged so that
     * existing color codes in markup-rendered bodies are not accidentally matched.
     *
     * @param string[] $lines Encoded, post-render body lines (may contain ANSI escape sequences)
     * @param string   $term  Search term entered by the user
     * @return string[]
     */
    private function highlightSearchTerm(array $lines, string $term): array
    {
        if ($term === '') {
            return $lines;
        }
        $pattern   = '/' . preg_quote($term, '/') . '/iu';
        $ansiSplit = '/(\033\[[0-9;]*m)/';
        return array_map(function (string $line) use ($pattern, $ansiSplit): string {
            // Split on ANSI escape sequences, keeping them as captured delimiters.
            $parts  = preg_split($ansiSplit, $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
            $result = '';
            foreach ($parts as $part) {
                if (preg_match($ansiSplit, $part)) {
                    $result .= $part; // ANSI escape — pass through unchanged
                } else {
                    $result .= preg_replace_callback($pattern, static function (array $m): string {
                        return "\033[43;97m" . $m[0] . "\033[0m";
                    }, $part) ?? $part;
                }
            }
            return $result;
        }, $lines);
    }

    /**
     * Filter echoareas by a case-insensitive search term matching tag, domain, or description.
     *
     * @param array $areas List of echoarea arrays
     * @param string $term Search term
     * @return array Filtered list (re-indexed)
     */
    private function filterAreas(array $areas, string $term): array
    {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return $areas;
        }
        return array_values(array_filter($areas, function (array $area) use ($term): bool {
            return str_contains(mb_strtolower((string)($area['tag'] ?? '')), $term)
                || str_contains(mb_strtolower((string)($area['domain'] ?? '')), $term)
                || str_contains(mb_strtolower((string)($area['description'] ?? '')), $term);
        }));
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

        $interestRows = [];
        foreach ($interests as $idx => $interest) {
            $name       = (string)($interest['name'] ?? '');
            $subscribed = !empty($interest['subscribed']);
            $badge      = $subscribed
                ? TelnetUtils::colorize('[+]', TelnetUtils::ANSI_GREEN)
                : TelnetUtils::colorize('[ ]', TelnetUtils::ANSI_DIM);
            $interestRows[] = ' '
                . TelnetUtils::colorize(sprintf('%2d', $idx + 1), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
                . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
                . ' ' . $badge . ' ' . $name;
        }

        $interestTitle = TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.echomail.interests_title', 'Browse by Interest', [], $locale),
            TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
        );
        $interestStatusBar = [
            ['text' => 'U/D',      'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter',    'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Select  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',        'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Quit',    'color' => TelnetUtils::ANSI_BLUE],
        ];

        $interestResult = TelnetUtils::runSelectableList(
            $conn, $state, $this->server,
            $interestTitle, $interestRows, 1, 1, 0,
            $interestStatusBar, [], null
        );

        if ($interestResult['action'] === 'disconnect' || $interestResult['action'] === 'quit') {
            return;
        }
        $idx = $interestResult['index'];
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
                $areaLabel = $this->formatEchoareaIdentifier($tag, $domain);
                $this->server->logAction($state['username'] ?? 'unknown', "Echomail: entered area {$areaLabel} via interest \"{$interestName}\"");
                $this->showMessages($conn, $state, $session, $tag, $domain);
            }
        }
    }

    /**
     * Render a paginated echo area list using the standard selectable-list widget.
     *
     * Returns an array with:
     *   'action' => 'quit' | 'select' | 'interests' | 'redraw' | 'filter' | 'search'
     *   'area'   => array   (only when action === 'select')
     *   'filter' => ?string (only when action === 'filter'; null means clear)
     *   'page'   => int     (current page after the action)
     *
     * @param callable|null $onPageChange Called with the new page number when page changes (for persistence)
     * @param string|null   $searchFilter Active search filter string, or null if none
     */
    private function pickEchoarea(
        $conn,
        array &$state,
        array $allAreas,
        int $page,
        int $perPage,
        string $title,
        bool $showInterestKey,
        ?callable $onPageChange,
        ?string $searchFilter = null,
        bool $allAreasMode = false
    ): array {
        $locale     = $state['locale'];
        $totalPages = max(1, (int)ceil(count($allAreas) / $perPage));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * $perPage;
        $areas      = array_slice($allAreas, $offset, $perPage);

        $header = str_replace(['{page}', '{total}'], [$page, $totalPages], $title);
        if ($searchFilter !== null) {
            $header .= ' — ' . $this->server->t(
                'ui.terminalserver.echomail.areas_filter',
                'Filter: {term} ({count} results)',
                ['term' => $searchFilter, 'count' => count($allAreas)],
                $locale
            );
        }
        $styledHeader  = TelnetUtils::colorize($header, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD);
        $encodedHeader = method_exists($this->server, 'encodeForTerminal')
            ? $this->server->encodeForTerminal($styledHeader)
            : $styledHeader;

        $buildRows = function (array $pageAreas) use ($allAreasMode): array {
            $rows = [];
            foreach ($pageAreas as $idx => $area) {
                $subscribed = !empty($area['subscribed']);
                $tagWidth   = $allAreasMode ? 16 : 20;
                $row = $this->renderEchoAreaSelectionLine(
                    $idx + 1,
                    (string)substr($area['tag'] ?? '', 0, $tagWidth),
                    (string)substr($area['domain'] ?? '', 0, 10),
                    (string)substr($area['description'] ?? '', 0, 38),
                    $allAreasMode,
                    $subscribed
                );
                if (method_exists($this->server, 'encodeForTerminal')) {
                    $row = $this->server->encodeForTerminal($row);
                }
                $rows[] = $row;
            }
            return $rows;
        };

        $rows = $buildRows($areas);
        if (empty($rows)) {
            $rows = [TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.areas_no_results', 'No areas match your search.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            )];
        }

        $extraKeys = ['/' => 'filter', 's' => 'search', 'a' => 'allareas', 'u' => 'unsubscribe'];
        if ($showInterestKey) {
            $extraKeys['i'] = 'interests';
        }
        if ($searchFilter !== null) {
            $extraKeys['c'] = 'clearfilter';
        }

        $allAreasLabel = $allAreasMode ? 'My Areas' : 'All';
        $statusBar = [
            ['text' => 'U/D',               'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',           'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'L/R',               'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Page  ',           'color' => TelnetUtils::ANSI_BLUE],
            ['text' => '/',                 'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Filter  ',         'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'A',                 'color' => TelnetUtils::ANSI_RED],
            ['text' => " {$allAreasLabel}  ", 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'U',                 'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Unsub  ',          'color' => TelnetUtils::ANSI_BLUE],
        ];
        if ($showInterestKey) {
            $statusBar[] = ['text' => 'I',           'color' => TelnetUtils::ANSI_RED];
            $statusBar[] = ['text' => ' Interests  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        $statusBar[] = ['text' => 'Q',    'color' => TelnetUtils::ANSI_RED];
        $statusBar[] = ['text' => ' Quit', 'color' => TelnetUtils::ANSI_BLUE];

        $rebuildFn = function (array &$s) use ($areas, $encodedHeader, $buildRows): array {
            return ['rows' => $buildRows($areas), 'title' => $encodedHeader];
        };

        $result = TelnetUtils::runSelectableList(
            $conn, $state, $this->server,
            $encodedHeader, $rows, $page, $totalPages, 0,
            $statusBar, $extraKeys, $rebuildFn
        );

        switch ($result['action']) {
            case 'disconnect':
            case 'quit':
                return ['action' => 'quit', 'page' => $page];

            case 'next':
                if ($page < $totalPages) {
                    $page++;
                    if ($onPageChange) { ($onPageChange)($page); }
                }
                return ['action' => 'redraw', 'page' => $page];

            case 'prev':
                if ($page > 1) {
                    $page--;
                    if ($onPageChange) { ($onPageChange)($page); }
                }
                return ['action' => 'redraw', 'page' => $page];

            case 'select':
                $index = $result['index'];
                if (isset($areas[$index])) {
                    return ['action' => 'select', 'area' => $areas[$index], 'page' => $page];
                }
                return ['action' => 'redraw', 'page' => $page];

            case 'allareas':
                return ['action' => 'allareas', 'page' => $page];

            case 'unsubscribe':
                $index = $result['index'];
                if (isset($areas[$index])) {
                    return ['action' => 'unsubscribe', 'area' => $areas[$index], 'page' => $page];
                }
                return ['action' => 'redraw', 'page' => $page];

            case 'interests':
                return ['action' => 'interests', 'page' => $page];

            case 'filter':
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::safeWrite($conn, $this->server->t(
                    'ui.terminalserver.echomail.areas_search_prompt', 'Search: ', [], $locale
                ));
                $term = $this->server->readLineWithIdleCheck($conn, $state);
                if ($term === null) {
                    return ['action' => 'quit', 'page' => $page];
                }
                $term = trim($term);
                return ['action' => 'filter', 'filter' => ($term !== '' ? $term : null), 'page' => 1];

            case 'clearfilter':
                return ['action' => 'filter', 'filter' => null, 'page' => 1];

            case 'search':
                return ['action' => 'search', 'page' => $page];

            default:
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
        $area          = $this->formatEchoareaIdentifier($tag, $domain);
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
            $result = TelnetUtils::runMessageList(
                $conn, $state, $this->server, $title, $messages, $page, $totalPages, $selectedIndex,
                ['s' => 'search'],
                [['text' => 'S', 'color' => TelnetUtils::ANSI_RED], ['text' => ' Search', 'color' => TelnetUtils::ANSI_BLUE]]
            );
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
                case 'search':
                    $this->searchInArea($conn, $state, $session, $area);
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
            $kludgeLines  = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $kludgeLines);
            $imageRefs    = TerminalMarkupRenderer::extractImageRefs((string)($markupFormat ?? ''), $body);
            $isSaved      = (bool)($detail['data']['is_saved'] ?? false);

            $fromName    = $msg['from_name'] ?? 'Unknown';
            $fromAddress = $msg['from_address'] ?? '';

            // Closure that rebuilds all layout-dependent view components from current $state.
            // Called once on open and again whenever the terminal is resized.
            $buildView = function(array $s) use ($msg, $body, $markupFormat, $area, $fromName, $fromAddress, $imageRefs): array {
                $cols     = $s['cols'] ?? 80;
                $width    = max(10, $cols - 2);
                $charset  = $this->server->getTerminalCharset();
                $fromLine = $fromAddress ? "{$fromName} <{$fromAddress}>" : $fromName;

                $segments = [
                    ['text' => 'U/D',          'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Scroll  ',    'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'L/R',          'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Prev/Next  ', 'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'R',            'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Reply  ',     'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'Ctrl-K',       'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Help  ',      'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'Q',            'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Quit',        'color' => TelnetUtils::ANSI_BLUE],
                ];

                $wrappedLines = $markupFormat !== null
                    ? TerminalMarkupRenderer::render($markupFormat, $body, $width)
                    : TelnetUtils::wrapTextLines($body, $width);
                $wrappedLines = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $wrappedLines);

                return [
                    'headerLines'  => TelnetUtils::buildMessageHeaderBox($width, [
                        ['label' => 'From: ', 'value' => $fromLine,                                                      'style' => 'normal'],
                        ['label' => 'Subj: ', 'value' => $msg['subject'] ?? 'Message',                                  'style' => 'bold'],
                        ['label' => 'To:   ', 'value' => $msg['to_name'] ?? 'All',                                      'style' => 'dim'],
                        ['label' => 'Area: ', 'value' => $area,                                                         'style' => 'dim'],
                        ['label' => 'Date: ', 'value' => TelnetUtils::formatUserDate($msg['date_written'] ?? '', $s),   'style' => 'dim'],
                    ], $charset),
                    'wrappedLines' => $wrappedLines,
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
            $locale = $state['locale'] ?? 'en';
            $helpItems = [
                ['key' => 'PgUp / PgDn', 'label' => $this->server->t('ui.terminalserver.message.help_page',      'Scroll one page',             [], $locale)],
                ['key' => 'H',           'label' => $this->server->t('ui.terminalserver.message.help_headers',   'View message headers',         [], $locale)],
                ['key' => 'B',           'label' => $this->server->t('ui.terminalserver.echomail.help_bookmark',  'Bookmark / unsave message',    [], $locale)],
                ['key' => 'T',           'label' => $this->server->t('ui.terminalserver.echomail.help_text_dl',  'Download as .txt (ZMODEM)',    [], $locale)],
                ['key' => 'E',           'label' => $this->server->t('ui.terminalserver.echomail.help_email_fwd', 'Forward to my email address',  [], $locale)],
            ];
            if (!empty($imageRefs)) {
                $helpItems[] = ['key' => 'I', 'label' => $this->server->t('ui.terminalserver.message.help_images', 'View inline image(s)', [], $locale)];
            }

            $result = TelnetUtils::runMessageViewer(
                $conn, $state, $this->server,
                $view['headerLines'], $view['wrappedLines'], $view['statusLine'],
                $state['rows'] ?? 24, 0, false, $kludgeLines, $buildView,
                $imageRefs, $imageFn, ['b' => 'save', 't' => 'download', 'e' => 'emailforward'], $helpItems
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
                case 'save':
                    $csrfToken = $state['csrf_token'] ?? null;
                    if ($isSaved) {
                        TelnetUtils::apiRequest($this->apiBase, 'DELETE', '/api/messages/echomail/' . $id . '/save', null, $session, 3, $csrfToken);
                        $confirmMsg = 'Message removed from saved.';
                    } else {
                        TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/echomail/' . $id . '/save', null, $session, 3, $csrfToken);
                        $confirmMsg = 'Message saved.';
                    }
                    $isSaved = !$isSaved;
                    $detail['data']['is_saved'] = $isSaved;
                    TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Bookmark', $confirmMsg, 'info');
                    break;
                case 'download':
                    $this->downloadAsText($conn, $state, $session, (int)$id, $msg['subject'] ?? 'message');
                    break;
                case 'emailforward':
                    $csrfToken = $state['csrf_token'] ?? null;
                    TelnetUtils::showWorkingOverlay($conn, $state, $this->server, 'Forwarding message to email...');
                    $fwdResult = TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/echomail/' . $id . '/forward-email', null, $session, 3, $csrfToken);
                    if ($fwdResult['status'] === 200) {
                        TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Email Forward', 'Forwarded to your email address.', 'info');
                    } else {
                        $errMsg = $fwdResult['data']['error'] ?? 'Failed to forward message.';
                        TelnetUtils::showAlertDialog($conn, $state, $this->server, 'Email Forward', $errMsg, 'error');
                    }
                    break;
            }
        }
    }

    /**
     * Download the current echomail message as a plain-text .txt file via ZMODEM.
     *
     * @param resource $conn
     * @param array    $state
     * @param string   $session
     * @param int      $id      Message ID
     * @param string   $subject Message subject (used to derive the filename)
     */
    private function downloadAsText($conn, array &$state, string $session, int $id, string $subject): void
    {
        $locale = $state['locale'] ?? 'en';

        if (!ZmodemTransfer::canDownload()) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.files.transfer_unavailable',
                    'ZMODEM disabled: install lrzsz (sz/rz) on the server to enable transfers.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/messages/echomail/' . $id . '/download', null, $session);
        $content  = $response['data']['raw'] ?? null;

        if ($response['status'] !== 200 || $content === null || $content === '') {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.netmail.text_download_fetch_failed',
                    'Could not fetch message text.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $subject);
        $safeName = trim((string)$safeName, '_');
        if ($safeName === '') {
            $safeName = 'message';
        }
        $filename = $safeName . '.txt';
        $tmpPath  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'binkterm_' . uniqid() . '_' . $filename;

        if (file_put_contents($tmpPath, $content) === false) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.netmail.text_download_fetch_failed',
                    'Could not fetch message text.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.files.download_starting', 'Starting ZMODEM download: {name}', ['name' => $filename], $locale),
            TelnetUtils::ANSI_CYAN
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.files.download_hint', 'Start ZMODEM receive in your terminal now...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        sleep(1);

        $ok = ZmodemTransfer::send($conn, $tmpPath, $filename, !($state['isSsh'] ?? false));
        @unlink($tmpPath);

        TelnetUtils::writeLine($conn, '');
        if ($ok) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.files.download_done', 'Transfer complete.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.files.download_failed', 'Transfer failed or was cancelled.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
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
     *
     * @param bool $showBadge  When true, prefix a [+] / [ ] subscription badge
     * @param bool $subscribed Used with $showBadge to choose which badge to display
     */
    private function renderEchoAreaSelectionLine(int $num, string $tag, string $domain, string $desc, bool $showBadge = false, bool $subscribed = true): string
    {
        $badge = '';
        if ($showBadge) {
            $badge = $subscribed
                ? TelnetUtils::colorize('[+]', TelnetUtils::ANSI_GREEN) . ' '
                : TelnetUtils::colorize('[ ]', TelnetUtils::ANSI_DIM) . ' ';
        }
        $tagWidth = $showBadge ? 16 : 20;
        $suffix   = sprintf(' %-' . $tagWidth . 's %-10s %s', $tag, $domain, $desc);
        return ' '
            . TelnetUtils::colorize(sprintf('%2d', $num), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
            . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
            . ' ' . $badge . ltrim($suffix);
    }

    /**
     * Format an echoarea identifier for terminal display and API routes.
     */
    private function formatEchoareaIdentifier(string $tag, string $domain): string
    {
        $domain = trim($domain);

        return $domain !== '' ? $tag . '@' . $domain : $tag;
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
