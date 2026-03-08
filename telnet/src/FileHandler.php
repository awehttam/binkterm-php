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
        $perPage = max(5, ($state['rows'] ?? 24) - 8);
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
                $line  = sprintf(' %2d) %-12s  %-36s  %d file(s)', $num, $tag, $desc, $count);
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($line, TelnetUtils::ANSI_GREEN));
                $num++;
            }

            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.areas_nav', 'Enter #, n/p (next/prev), q (quit)', [], $locale),
                TelnetUtils::ANSI_DIM
            ));

            $raw = $this->server->prompt($conn, $state, '', true);
            if ($raw === null) {
                return; // disconnected / idle timeout
            }
            $input = trim($raw);

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
        $page    = 1;
        $perPage = self::FILES_PER_PAGE;
        $locale  = $state['locale'] ?? '';
        $areaId  = (int)($area['id'] ?? 0);
        $areaTag = $area['tag'] ?? '';

        $uploadPerm = (int)($area['upload_permission'] ?? FileAreaManager::UPLOAD_READ_ONLY);
        $canUploadByPolicy  = $uploadPerm !== FileAreaManager::UPLOAD_READ_ONLY;

        $allFiles   = null; // lazy-loaded; set to null to trigger initial fetch
        $needsFetch = true;

        while (true) {
            if ($needsFetch) {
                $allResponse = TelnetUtils::apiRequest(
                    $this->apiBase, 'GET',
                    '/api/files?area_id=' . $areaId,
                    null, $session
                );
                $allFiles   = $allResponse['data']['files'] ?? [];
                $needsFetch = false;
            }
            $totalPages = max(1, (int)ceil(count($allFiles) / $perPage));
            $files      = array_slice($allFiles, ($page - 1) * $perPage, $perPage);

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.area_header', 'Files: {area} (page {page}/{total})', [
                    'area'  => $areaTag,
                    'page'  => $page,
                    'total' => $totalPages,
                ], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, '');

            if (empty($files)) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.no_files', 'No files in this area.', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                ));
            } else {
                $num = ($page - 1) * $perPage + 1;
                foreach ($files as $file) {
                    $name = $file['filename'] ?? '?';
                    $desc = $file['short_description'] ?? '';
                    $size = $this->formatSize((int)($file['filesize'] ?? 0));
                    $line = sprintf(' %2d) %-22s  %-28s  %s', $num, $name, $desc, $size);
                    TelnetUtils::writeLine($conn, TelnetUtils::colorize($line, TelnetUtils::ANSI_GREEN));
                    $num++;
                }
            }

            TelnetUtils::writeLine($conn, '');

            $downloadAvailable = ZmodemTransfer::canDownload();
            $uploadAvailable = $canUploadByPolicy && ZmodemTransfer::canUpload();
            $transfersAvailable = $downloadAvailable || $uploadAvailable;

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
            if (!$transfersAvailable && PHP_OS_FAMILY !== 'Windows') {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.files.transfer_unavailable', 'ZMODEM disabled: install lrzsz (sz/rz) on the server to enable transfers.', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                ));
            }

            $raw = $this->server->prompt($conn, $state, '', true);
            if ($raw === null) {
                return; // disconnected / idle timeout
            }
            $input = trim($raw);

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
        if (($input === 'd' || $input === 'D') && $downloadAvailable) {
            $this->zdbg('action=download');
            $this->promptDownload($conn, $state, $session, $files, $page, $perPage, $locale);
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
    // DOWNLOAD
    // ===========================================================

    /**
     * Ask the user for a file number then initiate a ZMODEM download.
     */
    private function promptDownload($conn, array &$state, string $session, array $files, int $page, int $perPage, string $locale): void
    {
        if (!ZmodemTransfer::canDownload()) {
            return;
        }
        if (empty($files)) {
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

        // Convert displayed number to the index within the current page slice
        $displayNum = (int)$input;
        $startNum   = ($page - 1) * $perPage + 1;
        $idx        = $displayNum - $startNum;

        if (!isset($files[$idx])) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.invalid_selection', 'Invalid selection.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            sleep(1);
            return;
        }

        $file   = $files[$idx];
        $fileId = (int)($file['id'] ?? 0);
        $name   = $file['filename'] ?? 'file';

        // Fetch full record (includes storage_path for server-side ZMODEM send)
        $detailResponse = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/files/' . $fileId, null, $session);
        $fileRecord     = $detailResponse['data']['file'] ?? null;

        if (!$fileRecord || empty($fileRecord['storage_path'])) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.download_error', 'File not found on server.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            sleep(2);
            return;
        }

        $storagePath = $fileRecord['storage_path'];

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
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.files.upload_error', 'Upload error: {error}', ['error' => $e->getMessage()], $locale),
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
     * Translate a string from the 'terminalserver' catalog namespace via the BbsSession.
     */
    private function t(string $key, string $fallback, array $params = [], string $locale = ''): string
    {
        return $this->server->t($key, $fallback, $params, $locale);
    }
}

