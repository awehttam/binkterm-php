<?php

namespace BinktermPHP\TelnetServer;

/**
 * MailUtils - Message and mail-related utility functions for telnet daemon
 *
 * This class provides static utility methods for handling netmail and echomail
 * operations including sending, quoting, subject normalization, and pagination.
 */
class MailUtils
{
    /**
     * Save a draft via the shared draft API.
     *
     * @return array{success:bool,draft_id?:int,error?:string,message?:string}
     */
    public static function saveDraft(string $apiBase, string $session, array $payload, ?string $csrfToken = null): array
    {
        $result = TelnetUtils::apiRequest($apiBase, 'POST', '/api/messages/draft', $payload, $session, 3, $csrfToken);
        $success = ($result['status'] ?? 0) === 200 && !empty($result['data']['success']);

        if ($success) {
            return [
                'success' => true,
                'draft_id' => (int)($result['data']['draft_id'] ?? 0),
                'message' => (string)($result['data']['message'] ?? ''),
            ];
        }

        return [
            'success' => false,
            'error' => (string)($result['data']['error'] ?? $result['error'] ?? 'Failed to save draft'),
        ];
    }

    /**
     * Fetch the user's drafts, optionally filtered by message type.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getDrafts(string $apiBase, string $session, ?string $type = null): array
    {
        $path = '/api/messages/drafts';
        if ($type !== null && $type !== '') {
            $path .= '?type=' . urlencode($type);
        }

        $result = TelnetUtils::apiRequest($apiBase, 'GET', $path, null, $session);
        if (($result['status'] ?? 0) !== 200) {
            return [];
        }

        return is_array($result['data']['drafts'] ?? null) ? $result['data']['drafts'] : [];
    }

    /**
     * Fetch a specific draft by ID.
     *
     * @return array<string,mixed>|null
     */
    public static function getDraft(string $apiBase, string $session, int $draftId): ?array
    {
        $result = TelnetUtils::apiRequest($apiBase, 'GET', '/api/messages/drafts/' . $draftId, null, $session);
        if (($result['status'] ?? 0) !== 200 || !is_array($result['data']['draft'] ?? null)) {
            return null;
        }

        return $result['data']['draft'];
    }

    /**
     * Delete a draft by ID.
     *
     * @return array{success:bool,error?:string}
     */
    public static function deleteDraft(string $apiBase, string $session, int $draftId, ?string $csrfToken = null): array
    {
        $result = TelnetUtils::apiRequest($apiBase, 'DELETE', '/api/messages/drafts/' . $draftId, null, $session, 3, $csrfToken);
        $success = ($result['status'] ?? 0) === 200 && !empty($result['data']['success']);

        if ($success) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => (string)($result['data']['error'] ?? $result['error'] ?? 'Failed to delete draft'),
        ];
    }

    /**
     * Interactive picker for terminal netmail/echomail drafts.
     *
     * @return array{action:'resume'|'cancel',draft?:array<string,mixed>}|null
     */
    public static function pickDraft(
        $conn,
        array &$state,
        $server,
        ?TerminalShellInterface $shell,
        string $apiBase,
        string $session,
        string $type,
        ?string $csrfToken = null
    ): ?array {
        $locale = $state['locale'] ?? 'en';
        $selectedIndex = 0;
        $page = 1;

        while (true) {
            $drafts = self::getDrafts($apiBase, $session, $type);
            if ($drafts === []) {
                return ['action' => 'cancel'];
            }

            $perPage = max(1, ($state['rows'] ?? 24) - 3);
            $totalPages = max(1, (int)ceil(count($drafts) / $perPage));
            $page = min(max(1, $page), $totalPages);
            $offset = ($page - 1) * $perPage;
            $pageDrafts = array_slice($drafts, $offset, $perPage);
            $selectedIndex = min($selectedIndex, max(0, count($pageDrafts) - 1));

            $title = TelnetUtils::colorize(
                $server->t(
                    'ui.terminalserver.compose.drafts_title',
                    '{type} Drafts (page {page}/{total})',
                    [
                        'type' => $type === 'echomail'
                            ? $server->t('ui.terminalserver.compose.echomail_label', 'Echomail', [], $locale)
                            : $server->t('ui.terminalserver.compose.netmail_label', 'Netmail', [], $locale),
                        'page' => $page,
                        'total' => $totalPages,
                    ],
                    $locale
                ),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );

            $buildRows = static function(array $draftRows, array $renderState) use ($server, $type, $locale): array {
                $cols = $renderState['cols'] ?? 80;
                $rows = [];
                foreach ($draftRows as $draft) {
                    $subject = trim((string)($draft['subject'] ?? ''));
                    if ($subject === '') {
                        $subject = $server->t('ui.terminalserver.compose.no_subject', '(No Subject)', [], $locale);
                    }
                    $target = $type === 'echomail'
                        ? trim((string)($draft['echoarea'] ?? ''))
                        : trim((string)($draft['to_name'] ?? ''));
                    if ($target === '') {
                        $target = $type === 'echomail'
                            ? $server->t('ui.terminalserver.compose.no_area', 'No area', [], $locale)
                            : $server->t('ui.terminalserver.compose.unknown', 'Unknown', [], $locale);
                    }
                    $date = TelnetUtils::formatUserDate((string)($draft['updated_at'] ?? ''), $renderState, false);
                    $targetWidth = max(10, min(24, (int)floor($cols * 0.28)));
                    $dateWidth = 16;
                    $subjectWidth = max(10, $cols - 5 - 2 - $targetWidth - 2 - $dateWidth - 1);
                    $rows[] = mb_substr(str_pad($subject, $subjectWidth), 0, $subjectWidth)
                        . '  '
                        . TelnetUtils::colorize(mb_substr(str_pad($target, $targetWidth), 0, $targetWidth), TelnetUtils::ANSI_CYAN)
                        . '  '
                        . TelnetUtils::colorize(mb_substr(str_pad($date, $dateWidth), 0, $dateWidth), TelnetUtils::ANSI_DIM);
                }
                return $rows;
            };

            $rows = $buildRows($pageDrafts, $state);
            $statusBar = [
                ['text' => 'Enter',  'color' => TelnetUtils::ANSI_RED],
                ['text' => ' ' . $server->t('ui.terminalserver.compose.drafts_status_resume', 'Resume', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'X',      'color' => TelnetUtils::ANSI_RED],
                ['text' => ' ' . $server->t('ui.terminalserver.compose.drafts_status_delete', 'Delete', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'L/R',    'color' => TelnetUtils::ANSI_RED],
                ['text' => ' ' . $server->t('ui.terminalserver.compose.drafts_status_page', 'Page', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Q',      'color' => TelnetUtils::ANSI_RED],
                ['text' => ' ' . $server->t('ui.terminalserver.compose.drafts_status_back', 'Back', [], $locale), 'color' => TelnetUtils::ANSI_BLUE],
            ];
            $helpItems = [
                ['key' => 'X', 'label' => $server->t('ui.terminalserver.compose.drafts_delete_selected', 'Delete selected draft', [], $locale)],
            ];

            $rebuildFn = function(array &$resizeState) use ($buildRows, $pageDrafts, $title): array {
                return ['rows' => $buildRows($pageDrafts, $resizeState), 'title' => $title];
            };

            $shell ??= TerminalShellFactory::create($server, $state);
            $result = $shell->showSelectableList(
                $conn,
                $state,
                $title,
                $rows,
                $page,
                $totalPages,
                $selectedIndex,
                $statusBar,
                ['x' => 'delete'],
                $rebuildFn,
                [],
                $helpItems
            );
            $selectedIndex = $result['selectedIndex'];

            switch ($result['action']) {
                case 'disconnect':
                    return null;
                case 'quit':
                    return ['action' => 'cancel'];
                case 'prev':
                    if ($page > 1) {
                        $page--;
                        $selectedIndex = 0;
                    }
                    break;
                case 'next':
                    if ($page < $totalPages) {
                        $page++;
                        $selectedIndex = 0;
                    }
                    break;
                case 'delete':
                    $draft = $pageDrafts[$result['index']] ?? null;
                    if ($draft === null) {
                        break;
                    }
                    $choice = $shell->showConfirmDialog(
                        $conn,
                        $state,
                        $server->t('ui.terminalserver.compose.drafts_delete_title', 'Delete Draft', [], $locale),
                        $server->t('ui.terminalserver.compose.drafts_delete_confirm', 'Delete this draft?', [], $locale),
                        [
                            'y' => $server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $locale),
                            'n' => $server->t('ui.terminalserver.server.confirm_no', 'Cancel', [], $locale),
                        ],
                        'n'
                    );
                    if ($choice === 'y') {
                        $deleteResult = self::deleteDraft($apiBase, $session, (int)($draft['id'] ?? 0), $csrfToken);
                        if (empty($deleteResult['success'])) {
                            $shell->showAlert(
                                $conn,
                                $state,
                                $server->t('ui.terminalserver.compose.drafts_title_short', 'Drafts', [], $locale),
                                (string)($deleteResult['error'] ?? $server->t('ui.terminalserver.compose.drafts_delete_failed', 'Failed to delete draft.', [], $locale)),
                                'error'
                            );
                        }
                    }
                    break;
                case 'select':
                    $draft = $pageDrafts[$result['index']] ?? null;
                    if ($draft === null) {
                        break;
                    }
                    $fullDraft = self::getDraft($apiBase, $session, (int)($draft['id'] ?? 0));
                    if ($fullDraft === null) {
                        $shell->showAlert(
                            $conn,
                            $state,
                            $server->t('ui.terminalserver.compose.drafts_title_short', 'Drafts', [], $locale),
                            $server->t('ui.terminalserver.compose.drafts_load_failed', 'Failed to load draft.', [], $locale),
                            'error'
                        );
                        break;
                    }
                    return ['action' => 'resume', 'draft' => $fullDraft];
            }
        }
    }

    /**
     * Send a netmail or echomail message via API
     *
     * @param string $apiBase Base URL for API requests
     * @param string $session Session token for authentication
     * @param array $payload Message data to send (to, from, subject, body, etc.)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendMessage(string $apiBase, string $session, array $payload, ?string $csrfToken = null): array
    {
        $result = TelnetUtils::apiRequest($apiBase, 'POST', '/api/messages/send', $payload, $session, 3, $csrfToken);
        $success = ($result['status'] ?? 0) === 200 && !empty($result['data']['success']);
        $error = null;

        if (!$success) {
            // Try to get error message from API response
            if (!empty($result['data']['error'])) {
                $error = $result['data']['error'];
            } elseif (!empty($result['data']['message'])) {
                $error = $result['data']['message'];
            } elseif (!empty($result['error'])) {
                $error = 'Network error: ' . $result['error'];
            } else {
                $error = 'HTTP ' . ($result['status'] ?? 'unknown');
            }
        }

        return ['success' => $success, 'error' => $error];
    }

    /**
     * Quote message text for replies
     *
     * Formats the original message body with quote markers (>) and attribution line.
     *
     * @param string $body Original message body to quote
     * @param string $author Author of the original message
     * @return string Quoted message text with attribution
     */
    public static function quoteMessage(string $body, string $author, ?array $state = null): string
    {
        $lines = explode("\n", $body);
        $quoted = [];
        $quoted[] = '';

        // Use user's date format if state is provided
        if ($state) {
            $currentUtc = gmdate('Y-m-d H:i:s');
            $dateStr = TelnetUtils::formatUserDate($currentUtc, $state, false);
        } else {
            $dateStr = date('Y-m-d');
        }

        $quoted[] = "On {$dateStr}, {$author} wrote:";
        $quoted[] = '';

        // Derive FSC-0032 initials from author name (up to 2 chars)
        $nameParts = array_values(array_filter(explode(' ', trim($author))));
        if (count($nameParts) >= 2) {
            $initials = strtoupper(substr($nameParts[0], 0, 1) . substr(end($nameParts), 0, 1));
        } elseif (count($nameParts) === 1) {
            $initials = strtoupper(substr($nameParts[0], 0, min(2, strlen($nameParts[0]))));
        } else {
            $initials = '??';
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $quoted[] = '';
                continue;
            }
            // Bump existing FSC-0032 quoted lines: add an extra > after the initials prefix
            if (preg_match('/^\s*[A-Za-z]{0,2}>+/', $trimmed)) {
                $quoted[] = preg_replace('/^(\s*[A-Za-z]{0,2})(>+)/', '$1$2>', $trimmed);
            } else {
                // New quote: " XX> text" per FSC-0032
                $quoted[] = ' ' . $initials . '> ' . $trimmed;
            }
        }

        $quoted[] = '';
        $quoted[] = '';
        return implode("\n", $quoted);
    }

    /**
     * Normalize subject line by removing RE: prefixes
     *
     * @param string $subject Subject line to normalize
     * @return string Subject with RE: prefix removed
     */
    public static function normalizeSubject(string $subject): string
    {
        return preg_replace('/^Re:\\s*/i', '', trim($subject));
    }

    /**
     * Fetch the user's signature from settings (max 4 lines).
     */
    public static function getUserSignature(string $apiBase, string $session): string
    {
        $response = TelnetUtils::apiRequest($apiBase, 'GET', '/api/user/settings', null, $session);
        if (($response['status'] ?? 0) !== 200) {
            return '';
        }

        $settings = $response['data']['settings'] ?? $response['data'] ?? [];
        if (!is_array($settings)) {
            return '';
        }

        $signature = trim((string)($settings['signature_text'] ?? ''));
        if ($signature === '') {
            return '';
        }

        $signature = str_replace(["\r\n", "\r"], "\n", $signature);
        $lines = preg_split('/\n/', $signature) ?: [];
        $lines = array_slice($lines, 0, 4);
        $lines = array_map('rtrim', $lines);

        return implode("\n", $lines);
    }

    /**
     * Append signature to composed text if not already present.
     */
    public static function appendSignatureToCompose(string $text, string $signature): string
    {
        if ($signature === '') {
            return $text;
        }

        $sigLines = preg_split('/\r\n|\r|\n/', $signature) ?: [];
        $sigLines = array_map('rtrim', $sigLines);
        if ($sigLines === []) {
            return $text;
        }

        $lines = preg_split('/\r\n|\r|\n/', rtrim($text, "\r\n")) ?: [];
        while (!empty($lines) && trim((string)end($lines)) === '') {
            array_pop($lines);
        }

        $tail = array_slice($lines, -count($sigLines));
        $alreadyHasSignature = ($tail === $sigLines);
        if ($alreadyHasSignature) {
            return $text;
        }

        $base = rtrim($text);
        return $base === '' ? "\n\n" . $signature : $base . "\n\n\n" . $signature;
    }

    /**
     * Calculate messages per page based on terminal height
     *
     * Accounts for headers, prompts, and UI elements to determine
     * how many messages can fit on screen at once.
     *
     * @param array $state Terminal state containing 'rows' key
     * @return int Number of messages that fit per page (minimum 5)
     */
    public static function getMessagesPerPage(array &$state): int
    {
        $rows = $state['rows'] ?? 24;
        // Be very conservative: header (1), messages (N), blank (1), prompt (1-2), input (1), safety (2) = N + 7
        // So N = rows - 7
        $perPage = max(5, $rows - 7);

        // Log in debug mode
        if (!empty($GLOBALS['telnet_debug'])) {
            echo "[" . date('Y-m-d H:i:s') . "] List view: Screen rows={$rows}, messages per page={$perPage}\n";
        }

        return $perPage;
    }

    /**
     * Get message counts for netmail and echomail
     *
     * Retrieves total counts of netmail messages and echomail messages
     * from all subscribed echoareas.
     *
     * @param string $apiBase Base URL for API requests
     * @param string $session Session token for authentication
     * @return array ['netmail' => int, 'echomail' => int]
     */
    public static function getMessageCounts(string $apiBase, string $session): array
    {
        $counts = ['netmail' => 0, 'echomail' => 0];

        $netmailResponse = TelnetUtils::apiRequest($apiBase, 'GET', '/api/messages/netmail?page=1', null, $session);
        if (!empty($netmailResponse['data']['pagination']['total'])) {
            $counts['netmail'] = (int)$netmailResponse['data']['pagination']['total'];
        }

        $areasResponse = TelnetUtils::apiRequest($apiBase, 'GET', '/api/echoareas?subscribed_only=true', null, $session);
        $areas = $areasResponse['data']['echoareas'] ?? [];
        $totalEcho = 0;
        foreach ($areas as $area) {
            $totalEcho += (int)($area['message_count'] ?? 0);
        }
        $counts['echomail'] = $totalEcho;

        return $counts;
    }

    /**
     * Fetches dashboard stats for the main menu via /api/dashboard/stats.
     *
     * Returns unread/new counts for messaging, online users, bulletins, and
     * credits. Replaces getMessageCounts() for the main menu — one API call
     * instead of two, and provides true unread/new figures instead of totals.
     *
     * @param string $apiBase Base URL for API requests
     * @param string $session Session token for authentication
     * @return array{
     *   unread_netmail: int,
     *   new_echomail: int,
     *   online_count: int,
     *   unread_bulletins: int,
     *   credit_balance: int|null
     * }
     */
    public static function getDashboardStats(string $apiBase, string $session): array
    {
        $defaults = [
            'unread_netmail'   => 0,
            'new_echomail'     => 0,
            'online_count'     => 0,
            'unread_bulletins' => 0,
            'credit_balance'   => null,
        ];

        $response = TelnetUtils::apiRequest($apiBase, 'GET', '/api/dashboard/stats', null, $session);
        if (($response['status'] ?? 0) !== 200 || empty($response['data'])) {
            return $defaults;
        }

        $data = $response['data'];
        return [
            'unread_netmail'   => (int)($data['total_netmail']    ?? 0),
            'new_echomail'     => (int)($data['new_echomail']      ?? 0),
            'online_count'     => (int)($data['online_count']      ?? 0),
            'unread_bulletins' => (int)($data['unread_bulletins']  ?? 0),
            'credit_balance'   => isset($data['credit_balance']) ? (int)$data['credit_balance'] : null,
        ];
    }

    /**
     * Fetch available taglines.
     *
     * @return array List of taglines
     */
    public static function getTaglines(string $apiBase, string $session): array
    {
        $response = TelnetUtils::apiRequest($apiBase, 'GET', '/api/taglines', null, $session);
        if (($response['status'] ?? 0) !== 200) {
            return [];
        }

        $taglines = $response['data']['taglines'] ?? [];
        if (!is_array($taglines)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $taglines), function($line) {
            return $line !== '';
        }));
    }

    /**
     * Fetch the user's default tagline selection.
     *
     * @return string Default tagline or empty string
     */
    public static function getUserDefaultTagline(string $apiBase, string $session): string
    {
        $response = TelnetUtils::apiRequest($apiBase, 'GET', '/api/user/settings', null, $session);
        if (($response['status'] ?? 0) !== 200) {
            return '';
        }

        $settings = $response['data']['settings'] ?? [];
        $defaultTagline = trim((string)($settings['default_tagline'] ?? ''));
        return $defaultTagline;
    }
}
