<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Config;
use BinktermPHP\Qwk\QwkBuilder;
use BinktermPHP\Qwk\RepProcessor;

/**
 * QwkMenuHandler — QWK offline mail for the telnet server.
 *
 * Shows QWK status and lets users download a QWK packet via ZMODEM.
 * The HTTP download URL is shown as an alternative for reader software that
 * supports scripted HTTP Basic Auth downloads. Format changes and conference
 * listing are also available. Business logic is delegated to QwkBuilder and
 * the /api/qwk/* endpoints.
 */
class QwkMenuHandler
{
    private BbsSession $server;
    private string $apiBase;
    private bool $isSsh;

    public function __construct(BbsSession $server, string $apiBase, bool $isSsh = false)
    {
        $this->server  = $server;
        $this->apiBase = $apiBase;
        $this->isSsh   = $isSsh;
    }

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Show the QWK menu and handle user interaction.
     */
    public function show($conn, array &$state, string $session, bool $logoutOnQuit = false): bool
    {
        $locale = $state['locale'];

        while (true) {
            $status = $this->fetchStatus($session);
            if ($status === null) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.qwk.error', 'Failed to retrieve QWK status.', [], $locale),
                    TelnetUtils::ANSI_RED
                ));
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                ));
                $this->server->readKeyWithIdleCheck($conn, $state);
                return false;
            }

            $cols           = max(40, (int)($state['cols'] ?? 80));
            $format         = strtolower((string)($status['format'] ?? 'qwk'));
            $formatDisplay  = strtoupper($format);
            $total          = (int)($status['total_new_messages'] ?? 0);
            $lastDl         = $status['last_download'] ?? null;
            $canZmodem      = ZmodemTransfer::canDownload();
            $canUpload      = ZmodemTransfer::canUpload();

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.title', 'QWK Offline Mail', [], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, '');

            // Status summary
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.status_heading', 'Status', [], $locale),
                TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, '  ' . $this->server->t(
                'ui.terminalserver.qwk.format_label', 'Format: {format}', ['format' => $formatDisplay], $locale
            ));
            TelnetUtils::writeLine($conn, '  ' . TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.pending_messages', 'Pending messages: {count}', ['count' => $total], $locale),
                $total > 0 ? TelnetUtils::ANSI_GREEN : TelnetUtils::ANSI_DIM
            ));

            if ($lastDl !== null) {
                TelnetUtils::writeLine($conn, '  ' . $this->server->t(
                    'ui.terminalserver.qwk.last_download', 'Last download: {date}',
                    ['date' => TelnetUtils::formatUserDate($lastDl, $state)], $locale
                ));
            } else {
                TelnetUtils::writeLine($conn, '  ' . TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.qwk.never_downloaded', 'Never downloaded', [], $locale),
                    TelnetUtils::ANSI_DIM
                ));
            }

            $confCount = count($status['conferences'] ?? []);
            TelnetUtils::writeLine($conn, '  ' . $this->server->t(
                'ui.terminalserver.qwk.conference_count', 'Conferences: {count}', ['count' => $confCount], $locale
            ));
            TelnetUtils::writeLine($conn, '');

            // HTTP alternative note
            $siteUrl = Config::getSiteUrl();
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.qwk.http_note',
                    'Tip: readers that support HTTP Basic Auth can also use {url}',
                    ['url' => $siteUrl . '/qwk/download'],
                    $locale
                ),
                TelnetUtils::ANSI_DIM
            ));
            TelnetUtils::writeLine($conn, '');

            // Actions
            if ($canZmodem) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.qwk.action_download', 'D) Download QWK packet (ZMODEM)', [], $locale),
                    TelnetUtils::ANSI_GREEN
                ));
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.qwk.action_download_unavailable', 'D) Download (ZMODEM unavailable — install lrzsz)', [], $locale),
                    TelnetUtils::ANSI_DIM
                ));
            }
            if ($canUpload) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.qwk.action_upload', 'U) Upload REP packet (ZMODEM)', [], $locale),
                    TelnetUtils::ANSI_GREEN
                ));
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.qwk.action_upload_unavailable', 'U) Upload (ZMODEM unavailable — install lrzsz)', [], $locale),
                    TelnetUtils::ANSI_DIM
                ));
            }
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.qwk.action_list_conferences', 'C) List conferences', [], $locale));
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.qwk.action_change_format', 'F) Change format', [], $locale));
            $quitKey = $logoutOnQuit
                ? 'ui.terminalserver.qwk.action_logout'
                : 'ui.terminalserver.qwk.action_quit';
            $quitFallback = $logoutOnQuit ? 'Q) Log out' : 'Q) Back';
            TelnetUtils::writeLine($conn, $this->server->t($quitKey, $quitFallback, [], $locale));
            TelnetUtils::writeLine($conn, '');

            $choice = $this->server->prompt($conn, $state, '> ', true);
            if ($choice === null) {
                return false;
            }
            $choice = strtolower(trim($choice));

            if ($choice === 'q' || $choice === '') {
                return $logoutOnQuit;
            } elseif ($choice === 'd') {
                $this->downloadPacket($conn, $state, $format);
            } elseif ($choice === 'u') {
                $this->uploadPacket($conn, $state);
            } elseif ($choice === 'c') {
                $this->showConferences($conn, $state, $status);
            } elseif ($choice === 'f') {
                $this->changeFormat($conn, $state, $session, $status);
            }
        }
    }

    // ── Download ──────────────────────────────────────────────────────────────

    /**
     * Build a QWK packet and send it to the client via ZMODEM.
     */
    private function downloadPacket($conn, array &$state, string $format): void
    {
        $locale = $state['locale'];
        $userId = (int)($state['user_id'] ?? 0);

        if ($userId === 0) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.download_error', 'Download failed: user session error.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            return;
        }

        if (!ZmodemTransfer::canDownload()) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.zmodem_unavailable', 'ZMODEM unavailable. Install lrzsz on the server.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.qwk.building_packet', 'Building QWK packet...', [], $locale),
            TelnetUtils::ANSI_CYAN
        ));

        $zipPath = null;
        try {
            $builder  = new QwkBuilder();
            $qwke     = ($format === 'qwke');
            $zipPath  = $builder->buildPacket($userId, $qwke);
            $filename = strtoupper($builder->getBbsId()) . '.QWK';
        } catch (\Throwable $e) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.build_failed', 'Failed to build QWK packet: {error}', ['error' => $e->getMessage()], $locale),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.qwk.download_starting', 'Starting ZMODEM transfer: {name}', ['name' => $filename], $locale),
            TelnetUtils::ANSI_CYAN
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.qwk.download_hint', 'Start ZMODEM receive in your terminal now...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        sleep(1);

        $this->server->logAction($state['username'] ?? 'unknown', "QWK: download started {$filename}");
        $ok = ZmodemTransfer::send($conn, $zipPath, $filename, !$this->isSsh);

        @unlink($zipPath);

        TelnetUtils::writeLine($conn, '');
        if ($ok) {
            $this->server->logAction($state['username'] ?? 'unknown', "QWK: download complete {$filename}");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.download_done', 'Transfer complete.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            $this->server->logAction($state['username'] ?? 'unknown', "QWK: download failed/cancelled {$filename}");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.download_failed', 'Transfer failed or was cancelled.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_YELLOW
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    /**
     * Receive a REP packet from the client via ZMODEM and process it.
     */
    private function uploadPacket($conn, array &$state): void
    {
        $locale = $state['locale'];
        $userId = (int)($state['user_id'] ?? 0);

        if ($userId === 0) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.upload_error', 'Upload failed: user session error.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            return;
        }

        if (!ZmodemTransfer::canUpload()) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.zmodem_unavailable', 'ZMODEM unavailable. Install lrzsz on the server.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.qwk.upload_ready', 'Ready to receive REP packet via ZMODEM.', [], $locale),
            TelnetUtils::ANSI_CYAN
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.qwk.upload_hint', 'Start ZMODEM send in your terminal now...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));

        $tmpDir   = sys_get_temp_dir();
        $repPath  = ZmodemTransfer::receive($conn, $tmpDir, !$this->isSsh);

        TelnetUtils::writeLine($conn, '');

        if ($repPath === null) {
            $this->server->logAction($state['username'] ?? 'unknown', 'QWK: REP upload failed/cancelled');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.upload_failed', 'Transfer failed or was cancelled.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $this->server->logAction($state['username'] ?? 'unknown', 'QWK: REP received, processing');

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.qwk.processing_rep', 'Processing REP packet...', [], $locale),
            TelnetUtils::ANSI_CYAN
        ));

        try {
            $processor = new RepProcessor();
            $result    = $processor->processRepPacket($repPath, $userId);
        } catch (\Throwable $e) {
            @unlink($repPath);
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.process_failed', 'Failed to process REP packet: {error}', ['error' => $e->getMessage()], $locale),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        @unlink($repPath);

        $imported = (int)($result['imported'] ?? 0);
        $skipped  = (int)($result['skipped'] ?? 0);
        $errors   = (array)($result['errors'] ?? []);

        $this->server->logAction(
            $state['username'] ?? 'unknown',
            "QWK: REP processed — imported={$imported} skipped={$skipped} errors=" . count($errors)
        );

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t(
                'ui.terminalserver.qwk.rep_imported',
                '{imported} message(s) imported, {skipped} skipped.',
                ['imported' => $imported, 'skipped' => $skipped],
                $locale
            ),
            $imported > 0 ? TelnetUtils::ANSI_GREEN : TelnetUtils::ANSI_YELLOW
        ));

        foreach ($errors as $err) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('  ' . $err, TelnetUtils::ANSI_RED));
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_YELLOW
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    // ── Other screens ─────────────────────────────────────────────────────────

    /**
     * Show the list of subscribed conferences with pending message counts.
     */
    private function showConferences($conn, array &$state, array $status): void
    {
        $locale      = $state['locale'];
        $conferences = $status['conferences'] ?? [];
        $cols        = max(40, (int)($state['cols'] ?? 80));

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.qwk.conferences_title', 'QWK Conferences', [], $locale),
            TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
        ));
        TelnetUtils::writeLine($conn, '');

        if (empty($conferences)) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.no_conferences', 'No conferences. Subscribe to echo areas first.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        } else {
            $numW  = 4;
            $nameW = max(20, $cols - $numW - 8 - 4);
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                sprintf(' %' . $numW . 's  %-' . $nameW . 's  %6s', '#', 'Name', 'New'),
                TelnetUtils::ANSI_DIM
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                str_repeat('-', min($cols - 1, $numW + $nameW + 12)),
                TelnetUtils::ANSI_DIM
            ));

            foreach ($conferences as $conf) {
                $num     = (int)($conf['number'] ?? 0);
                $name    = (string)($conf['name'] ?? '');
                $new     = (int)($conf['new_messages'] ?? 0);
                $numStr  = sprintf('%' . $numW . 'd', $num);
                $nameStr = mb_substr($name, 0, $nameW);
                $newStr  = $new > 0
                    ? TelnetUtils::colorize(sprintf('%6d', $new), TelnetUtils::ANSI_GREEN)
                    : TelnetUtils::colorize(sprintf('%6d', 0), TelnetUtils::ANSI_DIM);
                TelnetUtils::writeLine($conn, sprintf(' %s  %-' . $nameW . 's  %s', $numStr, $nameStr, $newStr));
            }
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_YELLOW
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    /**
     * Prompt the user to choose between qwk and qwke format.
     */
    private function changeFormat($conn, array &$state, string $session, array $status): void
    {
        $locale    = $state['locale'];
        $curFormat = strtolower((string)($status['format'] ?? 'qwk'));

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, $this->server->t(
            'ui.terminalserver.qwk.current_format', 'Current format: {format}',
            ['format' => strtoupper($curFormat)], $locale
        ));
        TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.qwk.format_choice_qwk',  '  1) QWK  (standard, widest compatibility)', [], $locale));
        TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.qwk.format_choice_qwke', '  2) QWKE (extended, larger messages)', [], $locale));
        TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.qwk.format_cancel',      '  Q) Cancel', [], $locale));
        TelnetUtils::writeLine($conn, '');

        $choice = $this->server->prompt($conn, $state, '> ', true);
        if ($choice === null) {
            return;
        }
        $choice = strtolower(trim($choice));

        if ($choice === 'q' || $choice === '') {
            return;
        }

        if ($choice === '1') {
            $newFormat = 'qwk';
        } elseif ($choice === '2') {
            $newFormat = 'qwke';
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.format_invalid', 'Invalid selection.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            return;
        }

        if ($newFormat === $curFormat) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.format_unchanged', 'Format unchanged.', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            return;
        }

        $resp = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', '/api/qwk/format',
            ['format' => $newFormat], $session, 3, $state['csrf_token'] ?? null
        );

        if ($resp['error'] !== null || $resp['status'] >= 400) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.qwk.format_failed', 'Failed to change format.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
        } else {
            $this->server->logAction($state['username'] ?? 'unknown', 'QWK: changed format to ' . strtoupper($newFormat));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.qwk.format_saved', 'Format changed to {format}.',
                    ['format' => strtoupper($newFormat)], $locale
                ),
                TelnetUtils::ANSI_GREEN
            ));
        }
    }

    // ── API helpers ───────────────────────────────────────────────────────────

    /**
     * Fetch the current QWK status for the session user.
     *
     * @return array|null Parsed status data or null on failure
     */
    private function fetchStatus(string $session): ?array
    {
        $resp = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/qwk/status', null, $session);
        if ($resp['error'] !== null || $resp['status'] >= 400) {
            return null;
        }
        $data = $resp['data'] ?? [];
        if (!isset($data['total_new_messages'])) {
            return null;
        }
        return $data;
    }
}
