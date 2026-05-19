<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\FileAreaManager;

/**
 * FileHandler - File areas, download (ZMODEM), and upload (ZMODEM) for BBS sessions.
 *
 * Lists file areas, then files within a selected area.  Users can download
 * files via ZMODEM (sz) or upload files via ZMODEM (rz).  Uploads are saved
 * through FileAreaManager::uploadFileFromPath() so all area rules, duplicate
 * detection, and statistics updates apply.
 */
class FileHandler
{
    private BbsSession $server;
    private string $apiBase;
    /** Whether the session is over SSH (no TELNET IAC escaping needed). */
    private bool $isSsh;

    private const FILES_PER_PAGE = 10;

    private function zdbg(string $message): void
    {
        $val = (string)\BinktermPHP\Config::env('TELNET_ZMODEM_DEBUG', 'false');
        if (!in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true)) {
            return;
        }
        $file = dirname(__DIR__, 2) . '/data/logs/zmodem.log';
        @error_log('[' . date('Y-m-d H:i:s') . '] FILE ' . $message . PHP_EOL, 3, $file);
    }

    /**
     * @param BbsSession $server  BBS session instance (for I/O helpers)
     * @param string     $apiBase API base URL
     * @param bool       $isSsh   true when running over SSH (skip IAC escaping)
     */
    public function __construct(BbsSession $server, string $apiBase, bool $isSsh = false)
    {
        $this->server  = $server;
        $this->apiBase = $apiBase;
        $this->isSsh   = $isSsh;
    }

    // ===========================================================
    // FILE AREA LIST
    // ===========================================================

    /**
     * Show the file area list and handle user area selection.
     *
     * @param resource $conn    Socket connection
     * @param array    $state   Terminal state
     * @param string   $session Session token
     */
    public function show($conn, array &$state, string $session): void
    {
        $page    = 1;
        $perPage = self::FILES_PER_PAGE;
        $locale  = $state['locale'] ?? '';

        while (true) {
            $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/fileareas', null, $session);
            $areas    = $response['data']['fileareas'] ?? [];

            if (empty($areas)) {
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.no_areas', 'No file areas available.', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                ));
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                    TelnetUtils::ANSI_DIM
                ));
                $this->server->readKeyWithIdleCheck($conn, $state);
                return;
            }

            $result = $this->pickFileArea($conn, $state, $areas, $page, $perPage);
            $page   = $result['page'];

            if ($result['action'] === 'quit') {
                return;
            }
            if ($result['action'] === 'select') {
                $selectedArea = $result['area'];
                $areaTag      = $selectedArea['tag'] ?? '';
                $this->server->logAction($state['username'] ?? 'unknown', "Files: entered area {$areaTag}");
                $this->showFiles($conn, $state, $session, $selectedArea);
            }
        }
    }

    // ===========================================================
    // FILE LIST
    // ===========================================================

    /**
     * Show files in a selected area and handle download/upload actions.
     *
     * @param resource $conn    Socket connection
     * @param array    $state   Terminal state
     * @param string   $session Session token
     * @param array    $area    File area record
     */
    private function showFiles($conn, array &$state, string $session, array $area): void
    {
        $page             = 1;
        $perPage          = self::FILES_PER_PAGE;
        $locale           = $state['locale'] ?? '';
        $areaId           = (int)($area['id'] ?? 0);
        $areaTag          = $area['tag'] ?? '';
        $currentSubfolder = null; // null = area root

        $uploadPerm        = (int)($area['upload_permission'] ?? FileAreaManager::UPLOAD_READ_ONLY);
        $canUploadByPolicy = $uploadPerm !== FileAreaManager::UPLOAD_READ_ONLY;

        $allFiles      = null;
        $allSubfolders = [];
        $needsFetch    = true;

        while (true) {
            if ($needsFetch) {
                $url = '/api/files?area_id=' . $areaId;
                if ($currentSubfolder !== null) {
                    $url .= '&subfolder=' . urlencode($currentSubfolder);
                }
                $allResponse   = TelnetUtils::apiRequest($this->apiBase, 'GET', $url, null, $session);
                $allFiles      = $allResponse['data']['files'] ?? [];
                $allSubfolders = $allResponse['data']['subfolders'] ?? [];
                $needsFetch    = false;
            }

            // Build combined list: folders first, then files
            $entries = [];
            foreach ($allSubfolders as $sf) {
                $entries[] = ['type' => 'folder', 'data' => $sf];
            }
            foreach ($allFiles as $file) {
                $entries[] = ['type' => 'file', 'data' => $file];
            }

            // Build path display for header
            $pathDisplay = $areaTag;
            if ($currentSubfolder !== null) {
                foreach (explode('/', $currentSubfolder) as $part) {
                    $pathDisplay .= ' > ' . $part;
                }
            }

            $downloadAvailable  = ZmodemTransfer::canDownload();
            $uploadAvailable    = $canUploadByPolicy && ZmodemTransfer::canUpload();
            $inSubfolder        = $currentSubfolder !== null;

            $result = $this->pickFileEntry(
                $conn,
                $state,
                $entries,
                $page,
                $perPage,
                $pathDisplay,
                $downloadAvailable,
                $uploadAvailable,
                $inSubfolder
            );
            $page = $result['page'];

            if ($result['action'] === 'quit') {
                return;
            }

            if ($result['action'] === 'back' && $inSubfolder) {
                $parts            = explode('/', $currentSubfolder);
                array_pop($parts);
                $currentSubfolder = empty($parts) ? null : implode('/', $parts);
                $page             = 1;
                $needsFetch       = true;
                continue;
            }

            if ($result['action'] === 'upload') {
                $this->zdbg('action=upload');
                $this->promptUpload($conn, $state, $session, $area, $locale);
                $needsFetch = true;
                continue;
            }

            if ($result['action'] === 'download') {
                $entry = $result['entry'] ?? null;
                if (($entry['type'] ?? '') !== 'file') {
                    TelnetUtils::showAlertDialog(
                        $conn,
                        $state,
                        $this->server,
                        $this->t('ui.terminalserver.files.detail_title', 'File Info', [], $locale),
                        $this->t('ui.terminalserver.files.not_a_file', 'That entry is a folder, not a file.', [], $locale),
                        'error'
                    );
                    continue;
                }

                $this->zdbg('action=download');
                $fileRecord = $this->fetchFullFileRecord($session, (int)($entry['data']['id'] ?? 0));
                if ($fileRecord === null) {
                    TelnetUtils::showAlertDialog(
                        $conn,
                        $state,
                        $this->server,
                        $this->t('ui.terminalserver.files.detail_title', 'File Info', [], $locale),
                        $this->t('ui.terminalserver.files.download_error', 'File not found on server.', [], $locale),
                        'error'
                    );
                    continue;
                }

                $this->downloadFile($conn, $state, $session, $fileRecord, $locale);
                continue;
            }

            if ($result['action'] === 'select') {
                $entry = $result['entry'];
                if (($entry['type'] ?? '') === 'folder') {
                    $currentSubfolder = (string)($entry['data']['subfolder'] ?? '');
                    $this->server->logAction($state['username'] ?? 'unknown', "Files: entered subfolder {$areaTag}/{$currentSubfolder}");
                    $page             = 1;
                    $needsFetch       = true;
                    continue;
                }

                $fname = $entry['data']['filename'] ?? '?';
                $this->server->logAction($state['username'] ?? 'unknown', "Files: viewed details for {$areaTag}/{$fname}");
                $this->showFileDetail(
                    $conn,
                    $state,
                    $session,
                    $entry['data'],
                    $downloadAvailable,
                    function (array &$dialogState) use ($conn, $pathDisplay, $entries, $result, $page, $perPage, $downloadAvailable, $uploadAvailable, $inSubfolder): void {
                        $this->renderFileListScreen(
                            $conn,
                            $dialogState,
                            $pathDisplay,
                            $entries,
                            $page,
                            $perPage,
                            $result['selectedIndex'] ?? 0,
                            $downloadAvailable,
                            $uploadAvailable,
                            $inSubfolder
                        );
                    }
                );
            }
        }
    }

    // ===========================================================
    // FILE DETAIL VIEW
    // ===========================================================

    /**
     * Display full details for a single file and offer a download option.
     *
     * @param resource $conn             Socket connection
     * @param array    $state            Terminal state
     * @param string   $session          Session token
     * @param array    $file             Basic file record from the list API
     * @param bool     $downloadAvailable Whether ZMODEM download is available
     */
    private function showFileDetail(
        $conn,
        array &$state,
        string $session,
        array $file,
        bool $downloadAvailable,
        ?callable $redrawFn = null
    ): void {
        $locale       = $state['locale'] ?? '';
        $fileId       = (int)($file['id'] ?? 0);
        $full         = $this->fetchFullFileRecord($session, $fileId) ?? $file;
        $scrollOffset = 0;
        $lastRows     = $state['rows'] ?? 24;
        $lastCols     = $state['cols'] ?? 80;

        $render = function () use ($conn, &$state, $full, $downloadAvailable, $locale, &$scrollOffset): void {
            $title = $this->t('ui.terminalserver.files.detail_title', 'File Info', [], $locale);
            $modal = $this->buildFileDetailModal($full, $downloadAvailable, $locale, $state);
            $scrollOffset = max(0, min($scrollOffset, $modal['maxScroll']));
            $this->drawModal($conn, $title, $modal['bodyLines'], $modal['statusLine'], $scrollOffset, $modal['bodyHeight'], $state);
        };

        $render();

        while (true) {
            $key = $this->server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) {
                return;
            }

            $newRows = $state['rows'] ?? $lastRows;
            $newCols = $state['cols'] ?? $lastCols;
            if ($newRows !== $lastRows || $newCols !== $lastCols) {
                $lastRows = $newRows;
                $lastCols = $newCols;
                if ($redrawFn !== null) {
                    $redrawFn($state);
                }
                $render();
                continue;
            }

            if ($key === 'UP') {
                $scrollOffset = max(0, $scrollOffset - 1);
                $render();
                continue;
            }
            if ($key === 'DOWN') {
                $scrollOffset++;
                $render();
                continue;
            }
            if ($key === 'PGUP') {
                $scrollOffset = max(0, $scrollOffset - 5);
                $render();
                continue;
            }
            if ($key === 'PGDOWN') {
                $scrollOffset += 5;
                $render();
                continue;
            }
            if ($key === 'ENTER' || $key === 'ESC') {
                return;
            }
            if (!str_starts_with($key, 'CHAR:')) {
                continue;
            }

            $input = strtolower(substr($key, 5));
            if ($input === 'b' || $input === 'q') {
                return;
            }
            if ($input === 'd' && $downloadAvailable) {
                $this->zdbg('action=download from detail view');
                $this->downloadFile($conn, $state, $session, $full, $locale);
                return;
            }
        }
    }

    /**
     * Format a UTC/local datetime string for terminal display.
     */
    private function formatDate(string $dateStr): string
    {
        if ($dateStr === '') {
            return '-';
        }
        $ts = strtotime($dateStr);
        return $ts !== false ? date('Y-m-d H:i', $ts) : $dateStr;
    }

    /**
     * Render the file-area chooser using the shared selectable-list widget.
     *
     * @return array{action:string,page:int,area?:array}
     */
    private function pickFileArea($conn, array &$state, array $areas, int $page, int $perPage): array
    {
        $locale     = $state['locale'] ?? '';
        $totalPages = max(1, (int)ceil(count($areas) / $perPage));
        $page       = max(1, min($page, $totalPages));
        $pageAreas  = array_slice($areas, ($page - 1) * $perPage, $perPage);
        $title      = $this->t('ui.terminalserver.files.areas_header', 'File Areas (page {page}/{total}):', [
            'page' => $page,
            'total' => $totalPages,
        ], $locale);

        $rows = [];
        foreach ($pageAreas as $idx => $area) {
            $rows[] = $this->encodeForTerminal($this->renderFileAreaSelectionLine(
                $idx + 1,
                (string)($area['tag'] ?? ''),
                (string)($area['description'] ?? ''),
                (int)($area['file_count'] ?? 0)
            ));
        }

        $result = TelnetUtils::runSelectableList(
            $conn,
            $state,
            $this->server,
            $this->encodeForTerminal(TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)),
            $rows,
            $page,
            $totalPages,
            0,
            [
                ['text' => 'U/D',   'color' => TelnetUtils::ANSI_RED],
                ['text' => ' ' . $this->t('ui.terminalserver.files.status_move', 'Move', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'L/R',   'color' => TelnetUtils::ANSI_RED],
                ['text' => ' ' . $this->t('ui.terminalserver.files.status_page', 'Page', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Enter', 'color' => TelnetUtils::ANSI_RED],
                ['text' => ' ' . $this->t('ui.terminalserver.files.status_open', 'Open', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Q',     'color' => TelnetUtils::ANSI_RED],
                ['text' => ' ' . $this->t('ui.terminalserver.files.status_quit', 'Quit', [], $locale), 'color' => TelnetUtils::ANSI_BLUE],
            ]
        );

        return match ($result['action']) {
            'disconnect', 'quit' => ['action' => 'quit', 'page' => $page],
            'next' => ['action' => 'redraw', 'page' => min($page + 1, $totalPages)],
            'prev' => ['action' => 'redraw', 'page' => max($page - 1, 1)],
            'select' => isset($pageAreas[$result['index']])
                ? ['action' => 'select', 'page' => $page, 'area' => $pageAreas[$result['index']]]
                : ['action' => 'redraw', 'page' => $page],
            default => ['action' => 'redraw', 'page' => $page],
        };
    }

    /**
     * Render the file/folder browser using the shared selectable-list widget.
     *
     * @return array{action:string,page:int,entry?:array,selectedIndex?:int}
     */
    private function pickFileEntry(
        $conn,
        array &$state,
        array $entries,
        int $page,
        int $perPage,
        string $pathDisplay,
        bool $downloadAvailable,
        bool $uploadAvailable,
        bool $inSubfolder
    ): array {
        $locale     = $state['locale'] ?? '';
        $totalPages = max(1, (int)ceil(count($entries) / $perPage));
        $page       = max(1, min($page, $totalPages));
        $pageEntries = array_slice($entries, ($page - 1) * $perPage, $perPage);

        $rows = [];
        if (empty($pageEntries)) {
            $rows[] = $this->encodeForTerminal(TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.no_files', 'No files in this area.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        } else {
            foreach ($pageEntries as $idx => $entry) {
                if (($entry['type'] ?? '') === 'folder') {
                    $sfPath      = (string)($entry['data']['subfolder'] ?? '');
                    $sfParts     = explode('/', $sfPath);
                    $displayName = (string)end($sfParts);
                    $rows[]      = $this->encodeForTerminal($this->renderFolderSelectionLine(
                        $idx + 1,
                        $displayName,
                        (string)($entry['data']['description'] ?? '')
                    ));
                    continue;
                }

                $rows[] = $this->encodeForTerminal($this->renderFileSelectionLine(
                    $idx + 1,
                    (string)($entry['data']['filename'] ?? '?'),
                    (string)($entry['data']['short_description'] ?? ''),
                    $this->formatSize((int)($entry['data']['filesize'] ?? 0))
                ));
            }
        }

        $statusBar = [
            ['text' => 'U/D',   'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.files.status_move', 'Move', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'L/R',   'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.files.status_page', 'Page', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter', 'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.files.status_open', 'Open', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
        ];
        $extraKeys = [];

        if ($downloadAvailable) {
            $statusBar[] = ['text' => 'D', 'color' => TelnetUtils::ANSI_RED];
            $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_download', 'Download', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE];
            $extraKeys['d'] = 'download';
        }
        if ($uploadAvailable) {
            $statusBar[] = ['text' => 'U', 'color' => TelnetUtils::ANSI_RED];
            $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_upload', 'Upload', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE];
            $extraKeys['u'] = 'upload';
        }
        if ($inSubfolder) {
            $statusBar[] = ['text' => 'B', 'color' => TelnetUtils::ANSI_RED];
            $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_up', 'Up', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE];
            $extraKeys['b'] = 'back';
        }
        $statusBar[] = ['text' => 'Q', 'color' => TelnetUtils::ANSI_RED];
        $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_quit', 'Quit', [], $locale), 'color' => TelnetUtils::ANSI_BLUE];

        $rebuildFn = function (array &$dialogState) use ($pathDisplay, $entries, $page, $perPage, $downloadAvailable, $uploadAvailable, $inSubfolder): array {
            return $this->buildFileListScreenData($pathDisplay, $entries, $page, $perPage, 0, $downloadAvailable, $uploadAvailable, $inSubfolder, $dialogState);
        };

        $screen = $this->buildFileListScreenData(
            $pathDisplay,
            $entries,
            $page,
            $perPage,
            0,
            $downloadAvailable,
            $uploadAvailable,
            $inSubfolder,
            $state
        );

        $result = TelnetUtils::runSelectableList(
            $conn,
            $state,
            $this->server,
            $screen['title'],
            $screen['rows'],
            $page,
            $totalPages,
            0,
            $statusBar,
            $extraKeys,
            $rebuildFn
        );

        return match ($result['action']) {
            'disconnect', 'quit' => ['action' => 'quit', 'page' => $page, 'selectedIndex' => $result['selectedIndex'] ?? 0],
            'next' => ['action' => 'redraw', 'page' => min($page + 1, $totalPages), 'selectedIndex' => 0],
            'prev' => ['action' => 'redraw', 'page' => max($page - 1, 1), 'selectedIndex' => 0],
            'upload', 'back' => ['action' => $result['action'], 'page' => $page, 'selectedIndex' => $result['selectedIndex'] ?? 0],
            'download', 'select' => isset($pageEntries[$result['index']])
                ? ['action' => $result['action'], 'page' => $page, 'entry' => $pageEntries[$result['index']], 'selectedIndex' => $result['index']]
                : ['action' => 'redraw', 'page' => $page, 'selectedIndex' => 0],
            default => ['action' => 'redraw', 'page' => $page, 'selectedIndex' => 0],
        };
    }

    /**
     * Render the file list screen outside the input loop so modal dialogs can repaint it on resize.
     */
    private function renderFileListScreen(
        $conn,
        array &$state,
        string $pathDisplay,
        array $entries,
        int $page,
        int $perPage,
        int $selectedIndex,
        bool $downloadAvailable,
        bool $uploadAvailable,
        bool $inSubfolder
    ): void {
        $screen = $this->buildFileListScreenData(
            $pathDisplay,
            $entries,
            $page,
            $perPage,
            $selectedIndex,
            $downloadAvailable,
            $uploadAvailable,
            $inSubfolder,
            $state
        );

        $cols = $state['cols'] ?? 80;
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, $screen['title']);
        foreach ($screen['rows'] as $idx => $row) {
            if ($idx === $selectedIndex) {
                TelnetUtils::writeLine(
                    $conn,
                    TelnetUtils::colorize(str_pad($this->stripAnsi($row), max(1, $cols - 1)), TelnetUtils::ANSI_BG_BLUE . TelnetUtils::ANSI_BOLD)
                );
            } else {
                TelnetUtils::writeLine($conn, $row);
            }
        }
        $inputRow = max(1, ($state['rows'] ?? 24) - 1);
        TelnetUtils::safeWrite($conn, "\033[{$inputRow};1H\033[K");
        TelnetUtils::safeWrite($conn, $screen['statusLine'] . "\r");
        TelnetUtils::safeWrite($conn, "\033[{$inputRow};1H");
    }

    /**
     * @return array{title:string,rows:array,statusLine:string}
     */
    private function buildFileListScreenData(
        string $pathDisplay,
        array $entries,
        int $page,
        int $perPage,
        int $selectedIndex,
        bool $downloadAvailable,
        bool $uploadAvailable,
        bool $inSubfolder,
        array &$state
    ): array {
        $locale     = $state['locale'] ?? '';
        $totalPages = max(1, (int)ceil(count($entries) / $perPage));
        $page       = max(1, min($page, $totalPages));
        $pageEntries = array_slice($entries, ($page - 1) * $perPage, $perPage);
        $rows       = [];

        if (empty($pageEntries)) {
            $rows[] = $this->encodeForTerminal(TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.no_files', 'No files in this area.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        } else {
            foreach ($pageEntries as $idx => $entry) {
                if (($entry['type'] ?? '') === 'folder') {
                    $sfPath      = (string)($entry['data']['subfolder'] ?? '');
                    $sfParts     = explode('/', $sfPath);
                    $displayName = (string)end($sfParts);
                    $rows[]      = $this->encodeForTerminal($this->renderFolderSelectionLine(
                        $idx + 1,
                        $displayName,
                        (string)($entry['data']['description'] ?? '')
                    ));
                    continue;
                }

                $rows[] = $this->encodeForTerminal($this->renderFileSelectionLine(
                    $idx + 1,
                    (string)($entry['data']['filename'] ?? '?'),
                    (string)($entry['data']['short_description'] ?? ''),
                    $this->formatSize((int)($entry['data']['filesize'] ?? 0))
                ));
            }
        }

        $statusBar = [
            ['text' => 'U/D',   'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.files.status_move', 'Move', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'L/R',   'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.files.status_page', 'Page', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter', 'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.files.status_open', 'Open', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
        ];
        if ($downloadAvailable) {
            $statusBar[] = ['text' => 'D', 'color' => TelnetUtils::ANSI_RED];
            $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_download', 'Download', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        if ($uploadAvailable) {
            $statusBar[] = ['text' => 'U', 'color' => TelnetUtils::ANSI_RED];
            $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_upload', 'Upload', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        if ($inSubfolder) {
            $statusBar[] = ['text' => 'B', 'color' => TelnetUtils::ANSI_RED];
            $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_up', 'Up', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        $statusBar[] = ['text' => 'Q', 'color' => TelnetUtils::ANSI_RED];
        $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_quit', 'Quit', [], $locale), 'color' => TelnetUtils::ANSI_BLUE];

        return [
            'title' => $this->encodeForTerminal(TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.area_header', 'Files: {area} (page {page}/{total})', [
                    'area' => $pathDisplay,
                    'page' => $page,
                    'total' => $totalPages,
                ], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            )),
            'rows' => $rows,
            'statusLine' => TelnetUtils::buildStatusBar($statusBar, $state['cols'] ?? 80),
        ];
    }

    private function fetchFullFileRecord(string $session, int $fileId): ?array
    {
        if ($fileId <= 0) {
            return null;
        }
        $detailResponse = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/files/' . $fileId, null, $session);
        return $detailResponse['data']['file'] ?? null;
    }

    /**
     * @return array{bodyLines:array,statusLine:string,bodyHeight:int,maxScroll:int}
     */
    private function buildFileDetailModal(array $full, bool $downloadAvailable, string $locale, array &$state): array
    {
        $cols      = max(48, (int)($state['cols'] ?? 80));
        $rows      = max(20, (int)($state['rows'] ?? 24));
        $boxWidth  = max(46, min($cols - 6, 78));
        $innerWidth = $boxWidth - 2;
        $bodyWidth = max(24, $innerWidth - 2);
        $bodyHeight = max(8, min($rows - 8, 14));

        $scanLine = $this->t('ui.terminalserver.files.detail_not_scanned', 'Not scanned', [], $locale);
        if (!empty($full['virus_scanned'])) {
            $result = (string)($full['virus_scan_result'] ?? '');
            if ($result === 'clean') {
                $scanLine = $this->t('ui.terminalserver.files.detail_scan_clean', 'Clean', [], $locale);
            } elseif ($result === 'infected') {
                $sig = (string)($full['virus_signature'] ?? $this->t('ui.common.unknown', 'Unknown', [], $locale));
                $scanLine = $this->t('ui.terminalserver.files.detail_scan_infected', 'INFECTED: {sig}', ['sig' => $sig], $locale);
            } elseif ($result === 'error') {
                $scanLine = $this->t('ui.terminalserver.files.detail_scan_error', 'Scan error', [], $locale);
            } elseif ($result === 'skipped') {
                $scanLine = $this->t('ui.terminalserver.files.detail_scan_skipped', 'Skipped', [], $locale);
            }
        }

        $bodyLines = [];
        $bodyLines = array_merge($bodyLines, $this->wrapLabelValue(
            $this->t('ui.terminalserver.files.detail_header', 'File', [], $locale),
            (string)($full['filename'] ?? '?'),
            $bodyWidth
        ));
        $bodyLines = array_merge($bodyLines, $this->wrapLabelValue(
            $this->t('ui.terminalserver.files.detail_size', 'Size', [], $locale),
            $this->formatSize((int)($full['filesize'] ?? 0)),
            $bodyWidth
        ));
        $bodyLines = array_merge($bodyLines, $this->wrapLabelValue(
            $this->t('ui.terminalserver.files.detail_uploaded', 'Uploaded', [], $locale),
            $this->formatDate((string)($full['created_at'] ?? '')),
            $bodyWidth
        ));
        $bodyLines = array_merge($bodyLines, $this->wrapLabelValue(
            $this->t('ui.terminalserver.files.detail_area', 'Area', [], $locale),
            (string)($full['area_tag'] ?? ''),
            $bodyWidth
        ));

        $from = (string)($full['uploaded_from_address'] ?? '');
        if ($from !== '') {
            $bodyLines = array_merge($bodyLines, $this->wrapLabelValue(
                $this->t('ui.terminalserver.files.detail_from', 'From', [], $locale),
                $from,
                $bodyWidth
            ));
        }

        $bodyLines = array_merge($bodyLines, $this->wrapLabelValue(
            $this->t('ui.terminalserver.files.detail_virus_scan', 'Virus scan', [], $locale),
            $scanLine,
            $bodyWidth
        ));

        $shortDesc = trim((string)($full['short_description'] ?? ''));
        if ($shortDesc !== '') {
            $bodyLines = array_merge($bodyLines, $this->wrapLabelValue(
                $this->t('ui.terminalserver.files.detail_description', 'Description', [], $locale),
                $shortDesc,
                $bodyWidth
            ));
        }

        $longDesc = trim((string)($full['long_description'] ?? ''));
        if ($longDesc !== '') {
            $bodyLines[] = '';
            $bodyLines[] = TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.detail_long_description', 'Details', [], $locale) . ':',
                TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD
            );
            foreach (preg_split('/\r\n|\r|\n/', $longDesc) ?: [] as $paragraph) {
                $wrapped = TelnetUtils::wrapTextLines($paragraph === '' ? ' ' : $paragraph, max(16, $bodyWidth - 2));
                foreach ($wrapped as $line) {
                    $bodyLines[] = '  ' . $line;
                }
            }
        }

        $statusBar = [
            ['text' => 'U/D', 'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->t('ui.terminalserver.files.status_scroll', 'Scroll', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE],
        ];
        if ($downloadAvailable) {
            $statusBar[] = ['text' => 'D', 'color' => TelnetUtils::ANSI_RED];
            $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_download', 'Download', [], $locale) . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        $statusBar[] = ['text' => 'B', 'color' => TelnetUtils::ANSI_RED];
        $statusBar[] = ['text' => ' ' . $this->t('ui.terminalserver.files.status_back', 'Back', [], $locale), 'color' => TelnetUtils::ANSI_BLUE];

        return [
            'bodyLines' => array_map(fn(string $line): string => $this->encodeForTerminal($line), $bodyLines),
            'statusLine' => TelnetUtils::buildStatusBar($statusBar, $innerWidth),
            'bodyHeight' => $bodyHeight,
            'maxScroll' => max(0, count($bodyLines) - $bodyHeight),
        ];
    }

    private function drawModal(
        $conn,
        string $title,
        array $bodyLines,
        string $statusLine,
        int $scrollOffset,
        int $bodyHeight,
        array &$state
    ): void {
        [$tl, $tr, $bl, $br, $hz, $vt] = $this->getDialogChars();
        $rows       = $state['rows'] ?? 24;
        $cols       = $state['cols'] ?? 80;
        $innerWidth = max(44, min($cols - 8, 76));
        $boxWidth   = $innerWidth + 2;
        $boxHeight  = $bodyHeight + 3;
        $startRow   = max(1, (int)round(($rows - $boxHeight) / 2));
        $startCol   = max(1, (int)round(($cols - $boxWidth) / 2));
        $titleLine  = ' ' . $title . ' ';
        $titleLen   = mb_strlen($titleLine);
        $totalHz    = max(0, $innerWidth - $titleLen);

        $topBorder  = $tl . str_repeat($hz, (int)floor($totalHz / 2)) . $titleLine . str_repeat($hz, (int)ceil($totalHz / 2)) . $tr;
        $bottomBorder = $bl . str_repeat($hz, $innerWidth) . $br;
        $frameColor = TelnetUtils::ANSI_BG_BLUE . "\033[1;37m";
        $bodyColor  = TelnetUtils::ANSI_BG_BLUE . "\033[37m";

        TelnetUtils::safeWrite($conn, "\033[?25l");
        TelnetUtils::safeWrite($conn, "\033[{$startRow};{$startCol}H" . $frameColor . $topBorder . TelnetUtils::ANSI_RESET);

        for ($i = 0; $i < $bodyHeight; $i++) {
            $line = $bodyLines[$scrollOffset + $i] ?? '';
            $line = str_replace(TelnetUtils::ANSI_RESET, $bodyColor, $line);
            $line = $this->padAnsi($line, $innerWidth - 2);
            TelnetUtils::safeWrite(
                $conn,
                "\033[" . ($startRow + $i + 1) . ";{$startCol}H" . $bodyColor . $vt . ' ' . $line . ' ' . $vt . TelnetUtils::ANSI_RESET
            );
        }

        TelnetUtils::safeWrite(
            $conn,
            "\033[" . ($startRow + $bodyHeight + 1) . ";{$startCol}H" . $bodyColor . $vt . $statusLine . $vt . TelnetUtils::ANSI_RESET
        );
        TelnetUtils::safeWrite(
            $conn,
            "\033[" . ($startRow + $bodyHeight + 2) . ";{$startCol}H" . $frameColor . $bottomBorder . TelnetUtils::ANSI_RESET
        );
        TelnetUtils::safeWrite($conn, "\033[?25h");
    }

    /**
     * @return array{0:string,1:string,2:string,3:string,4:string,5:string}
     */
    private function getDialogChars(): array
    {
        $charset = method_exists($this->server, 'getTerminalCharset') ? $this->server->getTerminalCharset() : 'ascii';
        if ($charset === 'utf8') {
            return ['┌', '┐', '└', '┘', '─', '│'];
        }
        if ($charset === 'cp437') {
            return ["\xda", "\xbf", "\xc0", "\xd9", "\xc4", "\xb3"];
        }
        return ['+', '+', '+', '+', '-', '|'];
    }

    /**
     * @return string[]
     */
    private function wrapLabelValue(string $label, string $value, int $width): array
    {
        $labelText   = $label . ':';
        $labelWidth  = min(14, max(8, mb_strlen($labelText)));
        $valueWidth  = max(12, $width - $labelWidth - 1);
        $wrapped     = TelnetUtils::wrapTextLines($value, $valueWidth);
        if (empty($wrapped)) {
            $wrapped = [''];
        }

        $lines   = [];
        $prefix  = TelnetUtils::colorize(str_pad($labelText, $labelWidth), TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD) . ' ';
        $indent  = str_repeat(' ', $labelWidth + 1);
        foreach ($wrapped as $idx => $line) {
            $lines[] = ($idx === 0 ? $prefix : $indent) . $line;
        }
        return $lines;
    }

    private function stripAnsi(string $text): string
    {
        return (string)preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $text);
    }

    private function padAnsi(string $text, int $width): string
    {
        $plain = $this->stripAnsi($text);
        $plainWidth = mb_strlen($plain);
        if ($plainWidth >= $width) {
            return $text;
        }
        return $text . str_repeat(' ', $width - $plainWidth);
    }

    private function encodeForTerminal(string $text): string
    {
        if (method_exists($this->server, 'encodeForTerminal')) {
            return $this->server->encodeForTerminal($text);
        }
        return $text;
    }

    // ===========================================================
    // DOWNLOAD
    // ===========================================================

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

        $this->zdbg('download resolved path=' . $storagePath . ' name=' . $name . ' source_type=' . ($fileRecord['source_type'] ?? 'n/a'));
        $this->server->logAction($state['username'] ?? 'unknown', "Files: download started {$name}");
        $ok = ZmodemTransfer::send($conn, $storagePath, $name, !$this->isSsh);
        $this->zdbg('download send result=' . ($ok ? 'ok' : 'fail') . ' name=' . $name);

        TelnetUtils::writeLine($conn, '');
        if ($ok) {
            $this->server->logAction($state['username'] ?? 'unknown', "Files: download complete {$name}");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.download_done', 'Transfer complete.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            $this->server->logAction($state['username'] ?? 'unknown', "Files: download failed/cancelled {$name}");
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
    // UPLOAD
    // ===========================================================

    /**
     * Collect upload metadata, receive file via ZMODEM, then register it.
     */
    private function promptUpload($conn, array &$state, string $session, array $area, string $locale): void
    {
        if (!ZmodemTransfer::canUpload()) {
            return;
        }
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->t('ui.terminalserver.files.upload_title', '=== Upload File ===', [], $locale),
            TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->t('ui.terminalserver.files.upload_area', 'Area: {area}', ['area' => $area['tag'] ?? ''], $locale),
            TelnetUtils::ANSI_GREEN
        ));
        TelnetUtils::writeLine($conn, '');

        $shortDesc = trim($this->server->prompt($conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.upload_desc_prompt', 'Short description (blank to cancel): ', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ),
            true
        ) ?? '');

        if ($shortDesc === '') {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.upload_cancelled', 'Upload cancelled.', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            sleep(1);
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->t('ui.terminalserver.files.upload_starting', 'Start ZMODEM send in your terminal now...', [], $locale),
            TelnetUtils::ANSI_CYAN
        ));

        $tmpDir   = sys_get_temp_dir();
        $this->server->logAction($state['username'] ?? 'unknown', "Files: upload started to area " . ($area['tag'] ?? ''));
        $destPath = ZmodemTransfer::receive($conn, $tmpDir, !$this->isSsh);
        $this->zdbg('upload receive result=' . ($destPath !== null ? 'ok' : 'fail'));

        if ($destPath === null) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.upload_failed', 'Transfer failed or was cancelled.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        // Resolve user info for audit trail and permission check
        $userResponse = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user/settings', null, $session);
        $userSettings = $userResponse['data']['settings'] ?? $userResponse['data'] ?? [];
        $username     = (string)($userSettings['username'] ?? 'unknown');
        $userId       = isset($userSettings['user_id']) ? (int)$userSettings['user_id'] : null;
        $isAdmin      = !empty($userSettings['is_admin']);

        $areaId     = (int)($area['id'] ?? 0);
        $uploadPerm = (int)($area['upload_permission'] ?? FileAreaManager::UPLOAD_READ_ONLY);

        if ($uploadPerm === FileAreaManager::UPLOAD_ADMIN_ONLY && !$isAdmin) {
            @unlink($destPath);
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.upload_admin_only', 'Only administrators can upload to this area.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            sleep(2);
            return;
        }

        try {
            $manager = new FileAreaManager();
            $fileId  = $manager->uploadFileFromPath($areaId, $destPath, $shortDesc, '', $username, $userId);

            $uploadedName = basename($destPath);
            $this->server->logAction($state['username'] ?? 'unknown', "Files: upload complete to area " . ($area['tag'] ?? '') . " file_id={$fileId}");
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.upload_done', 'File uploaded successfully (ID: {id}).', ['id' => $fileId], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } catch (\Exception $e) {
            @unlink($destPath);
            $errorMessage = $this->localizeUploadError($e->getMessage(), $locale);
            $this->server->logAction($state['username'] ?? 'unknown', "Files: upload error to area " . ($area['tag'] ?? '') . ": {$errorMessage}");
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.upload_error', 'Upload error: {error}', ['error' => $errorMessage], $locale),
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
    // HELPERS
    // ===========================================================

    /**
     * Format a byte count as a human-readable size string.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }

    /**
     * Translate known backend upload exception text to localized terminal strings.
     */
    private function localizeUploadError(string $error, string $locale): string
    {
        $normalized = strtolower(trim($error));
        if ($normalized === 'this file already exists in this area'
            || $normalized === 'a file with that name already exists in this area') {
            return $this->t(
                'ui.terminalserver.files.upload_duplicate',
                'This file already exists in this area.',
                [],
                $locale
            );
        }
        return $error;
    }

    /**
     * Render one file-area option with cyan number hotkey and blue ")" accent.
     */
    private function renderFileAreaSelectionLine(int $num, string $tag, string $desc, int $count): string
    {
        $tag  = sprintf('%-12s', mb_substr($tag,  0, 12));
        $desc = sprintf('%-36s', mb_substr($desc, 0, 36));
        return ' '
            . TelnetUtils::colorize(sprintf('%2d', $num), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
            . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
            . ' '
            . TelnetUtils::colorize($tag,  TelnetUtils::ANSI_MAGENTA)
            . '  '
            . TelnetUtils::colorize($desc, TelnetUtils::ANSI_GREEN)
            . '  '
            . TelnetUtils::colorize($count . ' file(s)', TelnetUtils::ANSI_RED);
    }

    /**
     * Render one folder entry with cyan number, blue ")", and yellow [DIR] badge.
     */
    private function renderFolderSelectionLine(int $num, string $name, string $desc): string
    {
        $badge = TelnetUtils::colorize('[DIR]', TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD);
        $name  = sprintf('%-22s', mb_substr($name, 0, 22));
        $desc  = sprintf('%-28s', mb_substr($desc, 0, 28));
        return ' '
            . TelnetUtils::colorize(sprintf('%2d', $num), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
            . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
            . ' ' . $badge . ' '
            . TelnetUtils::colorize($name, TelnetUtils::ANSI_CYAN)
            . '  '
            . TelnetUtils::colorize($desc, TelnetUtils::ANSI_GREEN);
    }

    /**
     * Render one file option with cyan number hotkey and blue ")" accent.
     */
    private function renderFileSelectionLine(int $num, string $name, string $desc, string $size): string
    {
        $name   = sprintf('%-22s', mb_substr($name, 0, 22));
        $desc   = sprintf('%-28s', mb_substr($desc, 0, 28));
        $suffix = sprintf(' %-22s  %-28s  %s', $name, $desc, $size);
        return ' '
            . TelnetUtils::colorize(sprintf('%2d', $num), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
            . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
            . $suffix;
    }

    /**
     * Translate a string from the 'terminalserver' catalog namespace via the BbsSession.
     */
    private function t(string $key, string $fallback, array $params = [], string $locale = ''): string
    {
        return $this->server->t($key, $fallback, $params, $locale);
    }
}

