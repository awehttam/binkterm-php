<?php

namespace BinktermPHP\TelnetServer;

/**
 * FreqHandler - Outbound file request (FREQ) list and submission for BBS sessions.
 *
 * Thin terminal client of the same /api/freq/requests endpoints used by
 * templates/file_requests.twig — all validation, concurrency limits, and
 * daemon dispatch live server-side in that API and FreqRequestTracker.
 */
class FreqHandler
{
    private BbsSession $server;
    private string $apiBase;
    /** Whether the session is over SSH (no TELNET IAC escaping needed). */
    private bool $isSsh;

    public function __construct(BbsSession $server, string $apiBase, bool $isSsh = false)
    {
        $this->server  = $server;
        $this->apiBase = $apiBase;
        $this->isSsh   = $isSsh;
    }

    /**
     * Show the file request list and handle new/delete/detail actions.
     *
     * @param resource $conn    Socket connection
     * @param array    $state   Terminal state
     * @param string   $session Session token
     */
    public function show($conn, array &$state, string $session): void
    {
        $shell         = TerminalShellFactory::create($this->server, $state);
        $page          = 1;
        $perPage       = MailUtils::getMessagesPerPage($state);
        $locale        = $state['locale'] ?? '';
        $selectedIndex = 0;

        while (true) {
            $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/freq/requests', null, $session);
            $requests = $response['data']['requests'] ?? [];

            $result        = $this->pickRequest($conn, $state, $requests, $page, $perPage, $selectedIndex, $shell);
            $page          = $result['page'];
            $selectedIndex = $result['selectedIndex'] ?? 0;

            if ($result['action'] === 'quit') {
                return;
            }

            if ($result['action'] === 'new') {
                $this->promptNewRequest($conn, $state, $session, $locale, $shell);
                $page          = 1;
                $selectedIndex = 0;
                continue;
            }

            if ($result['action'] === 'delete') {
                $row = $result['row'] ?? null;
                if ($row !== null) {
                    $this->deleteRequest($conn, $state, $session, $row, $locale, $shell);
                }
                continue;
            }

            if ($result['action'] === 'download') {
                $row = $result['row'] ?? null;
                if ($row !== null) {
                    $this->downloadRequestFile($conn, $state, $session, $row, $locale, $shell);
                }
                continue;
            }

            if ($result['action'] === 'select') {
                $row = $result['row'] ?? null;
                if ($row !== null) {
                    $this->showRequestDetail($conn, $state, $session, $row, $locale, $shell);
                }
            }
        }
    }

    // ===========================================================
    // REQUEST LIST
    // ===========================================================

    /**
     * Render the request list using the shared selectable-list widget.
     *
     * @return array{action:string,page:int,selectedIndex?:int,row?:array}
     */
    private function pickRequest(
        $conn,
        array &$state,
        array $requests,
        int $page,
        int $perPage,
        int $selectedIndex,
        TerminalShellInterface $shell
    ): array {
        $locale     = $state['locale'] ?? '';
        $totalPages = max(1, (int)ceil(count($requests) / $perPage));
        $page       = max(1, min($page, $totalPages));
        $pageItems  = array_slice($requests, ($page - 1) * $perPage, $perPage);

        $title = $this->t('ui.terminalserver.freq.list_header', 'File Requests (page {page}/{total})', [
            'page'  => $page,
            'total' => $totalPages,
        ], $locale);

        $rows = [];
        if (empty($pageItems)) {
            $rows[] = $this->encodeForTerminal(TelnetUtils::colorize(
                $this->t('ui.terminalserver.freq.empty', 'You have not requested any files yet.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        } else {
            foreach ($pageItems as $idx => $row) {
                $rows[] = $this->encodeForTerminal($this->renderRequestLine($idx + 1, $row, $locale));
            }
        }

        $statusBar = [
            ['text' => 'U/D',   'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.freq.status_move', 'Move', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter', 'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.freq.status_view', 'View', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'A',     'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.freq.status_new', 'New', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'D',     'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.files.status_download', 'Download', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'X',     'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.freq.status_delete', 'Delete', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',     'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.freq.status_quit', 'Quit', [], $locale), 'color' => TelnetUtils::ANSI_BLUE],
        ];
        // 'n'/'p' are reserved by showSelectableList for next/prev page — use 'a' (Add/New) instead.
        $extraKeys = ['a' => 'new', 'd' => 'download', 'x' => 'delete'];

        $result = $shell->showSelectableList(
            $conn,
            $state,
            $this->encodeForTerminal(TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)),
            $rows,
            $page,
            $totalPages,
            $selectedIndex,
            $statusBar,
            $extraKeys
        );

        return match ($result['action']) {
            'disconnect', 'quit' => ['action' => 'quit', 'page' => $page],
            'next' => ['action' => 'redraw', 'page' => min($page + 1, $totalPages), 'selectedIndex' => 0],
            'prev' => ['action' => 'redraw', 'page' => max($page - 1, 1), 'selectedIndex' => 0],
            'new' => ['action' => 'new', 'page' => $page, 'selectedIndex' => $result['selectedIndex'] ?? 0],
            'delete' => isset($pageItems[$result['index']])
                ? ['action' => 'delete', 'page' => $page, 'row' => $pageItems[$result['index']], 'selectedIndex' => $result['index']]
                : ['action' => 'redraw', 'page' => $page, 'selectedIndex' => 0],
            'download' => isset($pageItems[$result['index']])
                ? ['action' => 'download', 'page' => $page, 'row' => $pageItems[$result['index']], 'selectedIndex' => $result['index']]
                : ['action' => 'redraw', 'page' => $page, 'selectedIndex' => 0],
            'select' => isset($pageItems[$result['index']])
                ? ['action' => 'select', 'page' => $page, 'row' => $pageItems[$result['index']], 'selectedIndex' => $result['index']]
                : ['action' => 'redraw', 'page' => $page, 'selectedIndex' => 0],
            default => ['action' => 'redraw', 'page' => $page, 'selectedIndex' => 0],
        };
    }

    /**
     * Render one file-request row with cyan number hotkey and status color.
     */
    private function renderRequestLine(int $num, array $row, string $locale): string
    {
        $node   = sprintf('%-20s', mb_substr((string)($row['node_address'] ?? ''), 0, 20));
        $files  = $this->decodeRequestedFiles($row['requested_files'] ?? '');
        $fname  = sprintf('%-24s', mb_substr($files, 0, 24));
        $mode   = sprintf('%-4s', strtoupper((string)($row['mode'] ?? 'req')));
        [$statusLabel, $statusColor] = $this->statusLabelAndColor((string)($row['status'] ?? 'pending'), $locale);
        $downloadable = $this->isDownloadable($row);

        return ' '
            . TelnetUtils::colorize(sprintf('%2d', $num), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
            . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
            . ' '
            . TelnetUtils::colorize($node, TelnetUtils::ANSI_MAGENTA)
            . '  '
            . TelnetUtils::colorize($fname, TelnetUtils::ANSI_GREEN)
            . '  '
            . TelnetUtils::colorize($mode, TelnetUtils::ANSI_DIM)
            . '  '
            . TelnetUtils::colorize(sprintf('%-8s', $statusLabel), $statusColor)
            . ($downloadable ? '  ' . TelnetUtils::colorize('[DL]', TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD) : '');
    }

    /**
     * Whether a request row has a received file available to download.
     */
    private function isDownloadable(array $row): bool
    {
        return (string)($row['status'] ?? '') === 'complete' && !empty($row['file_id']);
    }

    /**
     * @return array{0:string,1:string} [label, ANSI color]
     */
    private function statusLabelAndColor(string $status, string $locale): array
    {
        return match ($status) {
            'complete' => [$this->t('ui.terminalserver.freq.status_complete', 'Complete', [], $locale), TelnetUtils::ANSI_GREEN],
            'failed'   => [$this->t('ui.terminalserver.freq.status_failed', 'Failed', [], $locale), TelnetUtils::ANSI_RED],
            default    => [$this->t('ui.terminalserver.freq.status_pending', 'Pending', [], $locale), TelnetUtils::ANSI_YELLOW],
        };
    }

    /**
     * Decode the JSON-encoded requested_files column into a display string.
     */
    private function decodeRequestedFiles(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return implode(', ', array_map('strval', $decoded));
        }
        return $raw;
    }

    // ===========================================================
    // REQUEST DETAIL
    // ===========================================================

    private function showRequestDetail(
        $conn,
        array &$state,
        string $session,
        array $row,
        string $locale,
        TerminalShellInterface $shell
    ): void {
        $title = $this->t('ui.terminalserver.freq.detail_title', 'File Request', [], $locale);
        $downloadAvailable = $this->isDownloadable($row) && ZmodemTransfer::canDownload();

        $lines = $this->buildRequestDetailLines($row, $locale);

        $statusSegments = [
            ['text' => 'U/D', 'color' => TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD],
            ['text' => ' ' . $this->t('ui.terminalserver.freq.status_move', 'Move', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
        ];
        if ($downloadAvailable) {
            $statusSegments[] = ['text' => 'D', 'color' => TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD];
            $statusSegments[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_download', 'Download', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        $statusSegments[] = ['text' => 'Q', 'color' => TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD];
        $statusSegments[] = ['text' => ' ' . $this->t('ui.terminalserver.freq.status_quit', 'Quit', [], $locale), 'color' => TelnetUtils::ANSI_BLUE];

        $action = $shell->showScrollablePanel(
            $conn,
            $state,
            $title,
            $lines,
            [
                'status_segments' => $statusSegments,
                'extra_keys' => $downloadAvailable ? ['d' => 'download'] : [],
                'redraw_fn' => function () use ($row, $locale, $title): array {
                    return [
                        'title' => $title,
                        'lines' => $this->buildRequestDetailLines($row, $locale),
                        'offset' => 0,
                    ];
                },
            ]
        );

        if ($action === 'download') {
            $this->downloadRequestFile($conn, $state, $session, $row, $locale, $shell);
        }
    }

    /**
     * @return string[]
     */
    private function buildRequestDetailLines(array $row, string $locale): array
    {
        [$statusLabel] = $this->statusLabelAndColor((string)($row['status'] ?? 'pending'), $locale);

        $lines   = [];
        $lines[] = $this->t('ui.terminalserver.freq.detail_node', 'Node: {node}', ['node' => (string)($row['node_address'] ?? '')], $locale);
        $lines[] = $this->t('ui.terminalserver.freq.detail_filename', 'File(s): {files}', ['files' => $this->decodeRequestedFiles($row['requested_files'] ?? '')], $locale);
        $lines[] = $this->t('ui.terminalserver.freq.detail_mode', 'Method: {mode}', ['mode' => strtoupper((string)($row['mode'] ?? 'req'))], $locale);
        $lines[] = $this->t('ui.terminalserver.freq.detail_status', 'Status: {status}', ['status' => $statusLabel], $locale);
        $lines[] = $this->t('ui.terminalserver.freq.detail_attempts', 'Attempts: {count}', ['count' => (int)($row['attempts'] ?? 0)], $locale);
        $lines[] = $this->t('ui.terminalserver.freq.detail_submitted', 'Submitted: {date}', ['date' => $this->formatDate((string)($row['created_at'] ?? ''))], $locale);
        if (!empty($row['completed_at'])) {
            $lines[] = $this->t('ui.terminalserver.freq.detail_completed', 'Completed: {date}', ['date' => $this->formatDate((string)($row['completed_at']))], $locale);
        }
        if (!$this->isDownloadable($row) && (string)($row['status'] ?? '') !== 'complete') {
            $lines[] = '';
            $lines[] = TelnetUtils::colorize(
                $this->t('ui.terminalserver.freq.download_not_ready', 'This file has not been received yet.', [], $locale),
                TelnetUtils::ANSI_DIM
            );
        }

        return $lines;
    }

    private function formatDate(string $dateStr): string
    {
        if ($dateStr === '') {
            return '-';
        }
        $ts = strtotime($dateStr);
        return $ts !== false ? date('Y-m-d H:i', $ts) : $dateStr;
    }

    // ===========================================================
    // DOWNLOAD
    // ===========================================================

    /**
     * Fetch the received file for a completed request and offer it via ZMODEM.
     */
    private function downloadRequestFile(
        $conn,
        array &$state,
        string $session,
        array $row,
        string $locale,
        TerminalShellInterface $shell
    ): void {
        $title = $this->t('ui.terminalserver.freq.detail_title', 'File Request', [], $locale);

        if (!$this->isDownloadable($row)) {
            $shell->showAlert(
                $conn,
                $state,
                $title,
                $this->t('ui.terminalserver.freq.download_not_ready', 'This file has not been received yet.', [], $locale),
                'info'
            );
            return;
        }

        if (!ZmodemTransfer::canDownload()) {
            $shell->showAlert(
                $conn,
                $state,
                $title,
                $this->t('ui.terminalserver.files.transfer_unavailable', 'ZMODEM disabled: install lrzsz (sz/rz) on the server to enable transfers.', [], $locale),
                'error'
            );
            return;
        }

        $fileRecord = $this->fetchFullFileRecord($session, (int)($row['file_id'] ?? 0));
        if ($fileRecord === null) {
            $shell->showAlert(
                $conn,
                $state,
                $title,
                $this->t('ui.terminalserver.files.download_error', 'File not found on server.', [], $locale),
                'error'
            );
            return;
        }

        $this->downloadFile($conn, $state, $session, $fileRecord, $locale);
    }

    private function fetchFullFileRecord(string $session, int $fileId): ?array
    {
        if ($fileId <= 0) {
            return null;
        }
        $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/files/' . $fileId, null, $session);
        return $response['data']['file'] ?? null;
    }

    /**
     * Initiate a ZMODEM send for a file record that already contains storage_path.
     *
     * @param resource $conn
     * @param array    $fileRecord Full file record (must include storage_path and filename)
     */
    private function downloadFile($conn, array &$state, string $session, array $fileRecord, string $locale): void
    {
        $name = (string)($fileRecord['filename'] ?? 'file');

        // Resolve the filesystem path — ISO-backed files reconstruct path from mount point + relative path
        if (($fileRecord['source_type'] ?? '') === 'iso_import' && !empty($fileRecord['iso_rel_path'])) {
            $mountPoint  = rtrim((string)($fileRecord['iso_mount_point'] ?? ''), '/\\');
            $storagePath = $mountPoint !== '' ? $mountPoint . '/' . ltrim($fileRecord['iso_rel_path'], '/\\') : '';
        } else {
            $storagePath = (string)($fileRecord['storage_path'] ?? '');
        }

        if ($storagePath === '') {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.download_error', 'File not found on server.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            sleep(2);
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->t('ui.terminalserver.files.download_starting', 'Starting ZMODEM download: {name}', ['name' => $name], $locale),
            TelnetUtils::ANSI_CYAN
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->t('ui.terminalserver.files.download_hint', 'Start ZMODEM receive in your terminal now...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        sleep(1);

        $this->server->logAction($state['username'] ?? 'unknown', "FREQ: download started {$name}");
        $ok = ZmodemTransfer::send($conn, $storagePath, $name, !$this->isSsh);

        TelnetUtils::writeLine($conn, '');
        if ($ok) {
            $this->server->logAction($state['username'] ?? 'unknown', "FREQ: download complete {$name}");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.download_done', 'Transfer complete.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            $this->server->logAction($state['username'] ?? 'unknown', "FREQ: download failed/cancelled {$name}");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.download_failed', 'Transfer failed or was cancelled.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    // ===========================================================
    // NEW REQUEST
    // ===========================================================

    private function promptNewRequest(
        $conn,
        array &$state,
        string $session,
        string $locale,
        TerminalShellInterface $shell
    ): void {
        $title = $this->t('ui.terminalserver.freq.new_title', '=== New File Request ===', [], $locale);

        $node = $shell->promptText(
            $conn,
            $state,
            $title,
            $this->t('ui.terminalserver.freq.prompt_node', 'Node Address or Hostname: ', [], $locale),
            ['footer_hint' => '?=' . $this->t('ui.terminalserver.freq.address_book', 'Address Book', [], $locale), 'inline_prompt' => true]
        );
        if ($node === null) {
            return;
        }
        $node = trim($node);
        if ($node === '?') {
            $picked = $shell->showAddressPicker($conn, $state, $this->apiBase, $session);
            $node   = $picked['address'] ?? '';
        }
        if ($node === '') {
            return;
        }

        $filename = $shell->promptText(
            $conn,
            $state,
            $title,
            $this->t('ui.terminalserver.freq.prompt_filename', 'Filename or Magic Name: ', [], $locale),
            ['inline_prompt' => true]
        );
        if ($filename === null || trim($filename) === '') {
            return;
        }
        $filename = trim($filename);

        $password = $shell->promptText(
            $conn,
            $state,
            $title,
            $this->t('ui.terminalserver.freq.prompt_password', 'Area Password (optional): ', [], $locale),
            ['inline_prompt' => true]
        );
        $password = $password !== null ? trim($password) : '';

        $modeChoice = $shell->promptKey(
            $conn,
            $state,
            $title,
            $this->t('ui.terminalserver.freq.prompt_mode', 'Request method - R).req file (recommended)  M) M_GET (live session): ', [], $locale),
            ['r', 'm'],
            ['default' => 'r']
        );
        $mode = $modeChoice === 'm' ? 'mget' : 'req';

        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/freq/requests',
            [
                'node'     => $node,
                'filename' => $filename,
                'mode'     => $mode,
                'password' => $password !== '' ? $password : null,
            ],
            $session,
            3,
            $state['csrf_token'] ?? null
        );

        if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300 && !empty($response['data']['success'])) {
            $this->server->logAction($state['username'] ?? 'unknown', "FREQ: requested {$filename} from {$node}");
            $shell->showAlert(
                $conn,
                $state,
                $title,
                $this->t('ui.terminalserver.freq.submitted', 'File request submitted.', [], $locale),
                'info'
            );
        } else {
            $error = (string)($response['data']['error'] ?? $this->t('ui.terminalserver.freq.submit_failed', 'Failed to submit file request.', [], $locale));
            $this->server->logAction($state['username'] ?? 'unknown', "FREQ: request failed for {$filename} from {$node}: {$error}");
            $shell->showAlert($conn, $state, $title, $error, 'error');
        }
    }

    // ===========================================================
    // DELETE
    // ===========================================================

    private function deleteRequest(
        $conn,
        array &$state,
        string $session,
        array $row,
        string $locale,
        TerminalShellInterface $shell
    ): void {
        $confirm = $shell->showConfirmDialog(
            $conn,
            $state,
            $this->t('ui.terminalserver.freq.detail_title', 'File Request', [], $locale),
            $this->t('ui.terminalserver.freq.delete_confirm', 'Delete this file request? This does not delete the file itself if it was already received.', [], $locale),
            ['y' => $this->t('ui.terminalserver.freq.confirm_yes', 'Yes', [], $locale), 'n' => $this->t('ui.terminalserver.freq.confirm_no', 'No', [], $locale)],
            'n'
        );

        if ($confirm !== 'y') {
            return;
        }

        $id       = (int)($row['id'] ?? 0);
        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'DELETE',
            '/api/freq/requests/' . $id,
            null,
            $session,
            3,
            $state['csrf_token'] ?? null
        );

        if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300 && !empty($response['data']['success'])) {
            $this->server->logAction($state['username'] ?? 'unknown', "FREQ: deleted request #{$id}");
        } else {
            $error = (string)($response['data']['error'] ?? $this->t('ui.terminalserver.freq.delete_failed', 'Failed to delete file request.', [], $locale));
            $shell->showAlert($conn, $state, $this->t('ui.terminalserver.freq.detail_title', 'File Request', [], $locale), $error, 'error');
        }
    }

    // ===========================================================
    // HELPERS
    // ===========================================================

    private function encodeForTerminal(string $text): string
    {
        if (method_exists($this->server, 'encodeForTerminal')) {
            return $this->server->encodeForTerminal($text);
        }
        return $text;
    }

    /**
     * Translate a string from the 'terminalserver' catalog namespace via the BbsSession.
     */
    private function t(string $key, string $fallback, array $params = [], string $locale = ''): string
    {
        return $this->server->t($key, $fallback, $params, $locale);
    }
}
