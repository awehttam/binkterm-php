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
        $perPage = min(max(5, ($state['rows'] ?? 24) - 8), self::FILES_PER_PAGE * 2);
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

            $totalPages = (int)ceil(count($areas) / $perPage);
            $pageAreas  = array_slice($areas, ($page - 1) * $perPage, $perPage);

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.areas_header', 'File Areas (page {page}/{total}):', ['page' => $page, 'total' => $totalPages], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, '');

            $num = ($page - 1) * $perPage + 1;
            foreach ($pageAreas as $area) {
                $tag   = $area['tag'] ?? '';
                $desc  = $area['description'] ?? '';
                $count = (int)($area['file_count'] ?? 0);
                TelnetUtils::writeLine(
                    $conn,
                    $this->renderFileAreaSelectionLine($num, (string)$tag, (string)$desc, $count)
                );
                $num++;
            }

            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.areas_nav', 'Enter #, n/p (next/prev), q (quit)', [], $locale),
                TelnetUtils::ANSI_DIM
            ));

            $input = $this->readNavInput($conn, $state);
            if ($input === null) {
                return; // disconnected / idle timeout
            }

            if ($input === '') {
                continue;
            }
            if ($input === 'q' || $input === 'Q') {
                return;
            }
            if ($input === 'n' || $input === 'N') {
                if ($page < $totalPages) {
                    $page++;
                }
                continue;
            }
            if ($input === 'p' || $input === 'P') {
                if ($page > 1) {
                    $page--;
                }
                continue;
            }
            if (ctype_digit($input)) {
                $idx = (int)$input - 1;
                if ($idx >= 0 && $idx < count($areas)) {
                    $this->showFiles($conn, $state, $session, $areas[$idx]);
                }
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

            $totalPages  = max(1, (int)ceil(count($entries) / $perPage));
            $pageEntries = array_slice($entries, ($page - 1) * $perPage, $perPage);

            // Build path display for header
            $pathDisplay = $areaTag;
            if ($currentSubfolder !== null) {
                foreach (explode('/', $currentSubfolder) as $part) {
                    $pathDisplay .= ' > ' . $part;
                }
            }

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.area_header', 'Files: {area} (page {page}/{total})', [
                    'area'  => $pathDisplay,
                    'page'  => $page,
                    'total' => $totalPages,
                ], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, '');

            if (empty($entries)) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.no_files', 'No files in this area.', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                ));
            } else {
                $num = ($page - 1) * $perPage + 1;
                foreach ($pageEntries as $entry) {
                    if ($entry['type'] === 'folder') {
                        $sfPath      = $entry['data']['subfolder'] ?? '';
                        $sfParts     = explode('/', $sfPath);
                        $displayName = (string)end($sfParts);
                        $desc        = (string)($entry['data']['description'] ?? '');
                        TelnetUtils::writeLine($conn, $this->renderFolderSelectionLine($num, $displayName, $desc));
                    } else {
                        $name = (string)($entry['data']['filename'] ?? '?');
                        $desc = (string)($entry['data']['short_description'] ?? '');
                        $size = $this->formatSize((int)($entry['data']['filesize'] ?? 0));
                        TelnetUtils::writeLine($conn, $this->renderFileSelectionLine($num, $name, $desc, $size));
                    }
                    $num++;
                }
            }

            TelnetUtils::writeLine($conn, '');

            $downloadAvailable  = ZmodemTransfer::canDownload();
            $uploadAvailable    = $canUploadByPolicy && ZmodemTransfer::canUpload();
            $transfersAvailable = $downloadAvailable || $uploadAvailable;
            $inSubfolder        = $currentSubfolder !== null;
            $hasFolders         = !empty($allSubfolders);

            if ($downloadAvailable && $uploadAvailable) {
                $navHint = $this->t('ui.terminalserver.files.files_nav_upload', 'D)ownload  U)pload  n/p (next/prev)  Q)uit', [], $locale);
            } elseif ($downloadAvailable) {
                $navHint = $this->t('ui.terminalserver.files.files_nav', 'D)ownload  n/p (next/prev)  Q)uit', [], $locale);
            } elseif ($uploadAvailable) {
                $navHint = $this->t('ui.terminalserver.files.files_nav_upload_only', 'U)pload  n/p (next/prev)  Q)uit', [], $locale);
            } else {
                $navHint = $this->t('ui.terminalserver.files.files_nav_none', 'n/p (next/prev)  Q)uit', [], $locale);
            }
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($navHint, TelnetUtils::ANSI_DIM));

            if ($hasFolders) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.enter_folder_or_file', 'Enter a folder number to browse, or a file number to view details.', [], $locale),
                    TelnetUtils::ANSI_DIM
                ));
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.files_view_hint', 'Enter a file number to view details.', [], $locale),
                    TelnetUtils::ANSI_DIM
                ));
            }

            if ($inSubfolder) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.files_back_hint', 'B)ack to parent folder', [], $locale),
                    TelnetUtils::ANSI_DIM
                ));
            }

            if (!$transfersAvailable && PHP_OS_FAMILY !== 'Windows') {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.transfer_unavailable', 'ZMODEM disabled: install lrzsz (sz/rz) on the server to enable transfers.', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                ));
            }

            $input = $this->readNavInput($conn, $state);
            if ($input === null) {
                return; // disconnected / idle timeout
            }

            if ($input === '') {
                continue;
            }
            if ($input === 'q' || $input === 'Q') {
                return;
            }
            // B = go up to parent folder (only when inside a subfolder)
            if (($input === 'b' || $input === 'B') && $inSubfolder) {
                $parts            = explode('/', $currentSubfolder);
                array_pop($parts);
                $currentSubfolder = empty($parts) ? null : implode('/', $parts);
                $page             = 1;
                $needsFetch       = true;
                continue;
            }
            if ($input === 'n' || $input === 'N') {
                if ($page < $totalPages) {
                    $page++;
                }
                continue;
            }
            if ($input === 'p' || $input === 'P') {
                if ($page > 1) {
                    $page--;
                }
                continue;
            }
            if (ctype_digit($input) && !empty($pageEntries)) {
                $displayNum = (int)$input;
                $startNum   = ($page - 1) * $perPage + 1;
                $idx        = $displayNum - $startNum;
                if (isset($pageEntries[$idx])) {
                    $entry = $pageEntries[$idx];
                    if ($entry['type'] === 'folder') {
                        $currentSubfolder = (string)($entry['data']['subfolder'] ?? '');
                        $page             = 1;
                        $needsFetch       = true;
                    } else {
                        $this->showFileDetail($conn, $state, $session, $entry['data'], $downloadAvailable);
                        $needsFetch = false;
                    }
                }
                continue;
            }
            if (($input === 'd' || $input === 'D') && $downloadAvailable) {
                $this->zdbg('action=download');
                $startNum = ($page - 1) * $perPage + 1;
                $this->promptDownload($conn, $state, $session, $pageEntries, $startNum, $locale);
                continue;
            }
            if (($input === 'u' || $input === 'U') && $uploadAvailable) {
                $this->zdbg('action=upload');
                $this->promptUpload($conn, $state, $session, $area, $locale);
                $needsFetch = true; // Refresh file list after upload
                continue;
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
    private function showFileDetail($conn, array &$state, string $session, array $file, bool $downloadAvailable): void
    {
        $locale = $state['locale'] ?? '';
        $fileId = (int)($file['id'] ?? 0);
        $cols   = max(40, (int)($state['cols'] ?? 80));

        // Fetch the full record (includes long_description, virus scan, storage_path, etc.)
        $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/files/' . $fileId, null, $session);
        $full     = $response['data']['file'] ?? $file;

        while (true) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");

            $filename = $full['filename'] ?? '?';
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.detail_header', 'File: {name}', ['name' => $filename], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, str_repeat('-', min($cols, 78)));

            $this->writeField($conn,
                $this->t('ui.terminalserver.files.detail_size', 'Size', [], $locale),
                $this->formatSize((int)($full['filesize'] ?? 0))
            );
            $this->writeField($conn,
                $this->t('ui.terminalserver.files.detail_uploaded', 'Uploaded', [], $locale),
                $this->formatDate((string)($full['created_at'] ?? ''))
            );
            $this->writeField($conn,
                $this->t('ui.terminalserver.files.detail_area', 'Area', [], $locale),
                (string)($full['area_tag'] ?? '')
            );

            $from = (string)($full['uploaded_from_address'] ?? '');
            if ($from !== '') {
                $this->writeField($conn,
                    $this->t('ui.terminalserver.files.detail_from', 'From', [], $locale),
                    $from
                );
            }

            // Virus scan status
            $scanLine = $this->t('ui.terminalserver.files.detail_not_scanned', 'Not scanned', [], $locale);
            if (!empty($full['virus_scanned'])) {
                $result = (string)($full['virus_scan_result'] ?? '');
                if ($result === 'clean') {
                    $scanLine = TelnetUtils::colorize(
                        $this->t('ui.terminalserver.files.detail_scan_clean', 'Clean', [], $locale),
                        TelnetUtils::ANSI_GREEN
                    );
                } elseif ($result === 'infected') {
                    $sig = (string)($full['virus_signature'] ?? $this->t('ui.common.unknown', 'Unknown', [], $locale));
                    $scanLine = TelnetUtils::colorize(
                        $this->t('ui.terminalserver.files.detail_scan_infected', 'INFECTED: {sig}', ['sig' => $sig], $locale),
                        TelnetUtils::ANSI_RED
                    );
                } elseif ($result === 'error') {
                    $scanLine = TelnetUtils::colorize(
                        $this->t('ui.terminalserver.files.detail_scan_error', 'Scan error', [], $locale),
                        TelnetUtils::ANSI_YELLOW
                    );
                } elseif ($result === 'skipped') {
                    $scanLine = $this->t('ui.terminalserver.files.detail_scan_skipped', 'Skipped', [], $locale);
                }
            }
            $this->writeField($conn,
                $this->t('ui.terminalserver.files.detail_virus_scan', 'Virus scan', [], $locale),
                $scanLine
            );

            // Short description
            $shortDesc = (string)($full['short_description'] ?? '');
            if ($shortDesc !== '') {
                $this->writeField($conn,
                    $this->t('ui.terminalserver.files.detail_description', 'Description', [], $locale),
                    $shortDesc
                );
            }

            // Long description
            $longDesc = (string)($full['long_description'] ?? '');
            if ($longDesc !== '') {
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.detail_long_description', 'Details:', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                ));
                $wrapWidth = max(40, min($cols - 4, 76));
                foreach (explode("\n", wordwrap($longDesc, $wrapWidth, "\n", true)) as $line) {
                    TelnetUtils::writeLine($conn, '  ' . $line);
                }
            }

            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, str_repeat('-', min($cols, 78)));

            if ($downloadAvailable) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.detail_nav_download', 'D)ownload  B)ack', [], $locale),
                    TelnetUtils::ANSI_DIM
                ));
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.detail_nav', 'B)ack', [], $locale),
                    TelnetUtils::ANSI_DIM
                ));
            }

            $raw   = $this->server->prompt($conn, $state, '', true);
            if ($raw === null) {
                return;
            }
            $input = strtolower(trim($raw));

            if ($input === 'b' || $input === '') {
                return;
            }
            if ($input === 'd' && $downloadAvailable) {
                $this->zdbg('action=download from detail view');
                $this->downloadFile($conn, $state, $session, $full, $locale);
                return; // return to file list after download
            }
        }
    }

    /**
     * Write a label: value line in the detail view.
     *
     * @param resource $conn
     */
    private function writeField($conn, string $label, string $value): void
    {
        TelnetUtils::writeLine($conn,
            TelnetUtils::colorize(sprintf('  %-14s', $label . ':'), TelnetUtils::ANSI_YELLOW)
            . ' ' . $value
        );
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

    // ===========================================================
    // DOWNLOAD
    // ===========================================================

    /**
     * Ask the user for a file number then initiate a ZMODEM download directly.
     * (Quick-download shortcut — the D key from the file list.)
     *
     * @param array $pageEntries Combined folder+file entries for the current page
     * @param int   $startNum    Display number of the first entry on this page
     */
    private function promptDownload($conn, array &$state, string $session, array $pageEntries, int $startNum, string $locale): void
    {
        if (!ZmodemTransfer::canDownload() || empty($pageEntries)) {
            return;
        }

        TelnetUtils::writeLine($conn, '');
        $input = trim($this->server->prompt($conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.download_prompt', 'File # to download (Enter to cancel): ', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ),
            true
        ) ?? '');

        if ($input === '' || !ctype_digit($input)) {
            return;
        }

        $displayNum = (int)$input;
        $idx        = $displayNum - $startNum;

        if (!isset($pageEntries[$idx])) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.invalid_selection', 'Invalid selection.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            sleep(1);
            return;
        }

        $entry = $pageEntries[$idx];
        if ($entry['type'] === 'folder') {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.not_a_file', 'That entry is a folder, not a file.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            sleep(1);
            return;
        }

        // Fetch full record to obtain storage_path
        $fileId         = (int)($entry['data']['id'] ?? 0);
        $detailResponse = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/files/' . $fileId, null, $session);
        $fileRecord     = $detailResponse['data']['file'] ?? null;

        if (!$fileRecord) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.download_error', 'File not found on server.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            sleep(2);
            return;
        }

        $this->downloadFile($conn, $state, $session, $fileRecord, $locale);
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

        $this->zdbg('download resolved path=' . $storagePath . ' name=' . $name . ' source_type=' . ($fileRecord['source_type'] ?? 'n/a'));
        $ok = ZmodemTransfer::send($conn, $storagePath, $name, !$this->isSsh);
        $this->zdbg('download send result=' . ($ok ? 'ok' : 'fail') . ' name=' . $name);

        TelnetUtils::writeLine($conn, '');
        if ($ok) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.download_done', 'Transfer complete.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
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

            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.upload_done', 'File uploaded successfully (ID: {id}).', ['id' => $fileId], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } catch (\Exception $e) {
            @unlink($destPath);
            $errorMessage = $this->localizeUploadError($e->getMessage(), $locale);
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
        $suffix = sprintf(' %-22s  %-28s  %s', $name, $desc, $size);
        return ' '
            . TelnetUtils::colorize(sprintf('%2d', $num), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
            . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
            . $suffix;
    }

    /**
     * Read navigation input immediately for arrow/letter keys, or accumulate
     * digit characters until Enter for area/file number selection.
     *
     * Returns:
     *   null         — disconnected or idle timeout
     *   ''           — Enter with no digits (re-render)
     *   'n'          — next page  (→, Page Down, n, N)
     *   'p'          — prev page  (←, Page Up,   p, P)
     *   single char  — any other letter/symbol (e.g. 'q', 'd', 'u', 'b')
     *   digit string — number confirmed with Enter (e.g. '17')
     *
     * @param resource $conn
     */
    private function readNavInput($conn, array &$state): ?string
    {
        $digits = '';
        while (true) {
            $key = $this->server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) {
                return null;
            }

            // Arrow / page keys → immediate navigation
            if ($key === 'RIGHT' || $key === 'PGDOWN') { return 'n'; }
            if ($key === 'LEFT'  || $key === 'PGUP')   { return 'p'; }

            if ($key === 'ENTER') {
                if ($digits !== '') {
                    TelnetUtils::safeWrite($conn, "\r\n");
                    return $digits;
                }
                return '';
            }

            if ($key === 'BACKSPACE') {
                if ($digits !== '') {
                    $digits = substr($digits, 0, -1);
                    TelnetUtils::safeWrite($conn, "\x08 \x08");
                }
                continue;
            }

            if (str_starts_with($key, 'CHAR:')) {
                $ch = substr($key, 5);
                if (ctype_digit($ch)) {
                    $digits .= $ch;
                    TelnetUtils::safeWrite($conn, $ch);
                    continue;
                }
                // Letter/symbol key: abandon any partial digit input and act immediately
                if ($digits !== '') {
                    TelnetUtils::safeWrite($conn, str_repeat("\x08 \x08", strlen($digits)));
                    $digits = '';
                }
                return $ch;
            }
            // Unrecognized keys (HOME, END, UP, DOWN, etc.): ignore
        }
    }

    /**
     * Translate a string from the 'terminalserver' catalog namespace via the BbsSession.
     */
    private function t(string $key, string $fallback, array $params = [], string $locale = ''): string
    {
        return $this->server->t($key, $fallback, $params, $locale);
    }
}

