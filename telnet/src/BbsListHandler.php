<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\BbsDirectory;
use BinktermPHP\Database;

/**
 * BbsListHandler — BBS directory browser for the terminal server.
 *
 * Displays the active BBS directory entries in a paginated list. Users can
 * browse entries, view details, and return to the main menu. Data is read
 * directly from the database (same approach as InterestsHandler) to avoid
 * HTTP cookie-auth fragility from the server process.
 */
class BbsListHandler
{
    private BbsSession $server;
    private string $apiBase;

    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server  = $server;
        $this->apiBase = $apiBase;
    }

    public function show($conn, array &$state, string $session): void
    {
        $locale  = $state['locale'];
        $shell   = TerminalShellFactory::create($this->server, $state);
        $perPage = max(5, ($state['rows'] ?? 24) - 3);
        $page    = 1;

        $entries = $this->fetchEntries();

        if (empty($entries)) {
            $shell->showText(
                $conn,
                $state,
                $this->server->t(
                    'ui.terminalserver.bbslist.title',
                    'BBS Directory ({total} systems)',
                    ['total' => 0],
                    $locale
                ),
                [$this->server->t('ui.terminalserver.bbslist.empty', 'No BBS listings available.', [], $locale)]
            );
            return;
        }

        $total      = count($entries);
        $totalPages = (int)ceil($total / $perPage);

        $statusBar = [
            ['text' => 'U/D',       'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',   'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'L/R',       'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Page  ',   'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter',     'color' => TelnetUtils::ANSI_RED],
            ['text' => ' View  ',   'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',         'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Quit',     'color' => TelnetUtils::ANSI_BLUE],
        ];

        $selectedIndex = 0;

        while (true) {
            $page  = max(1, min($page, $totalPages));
            $slice = array_slice($entries, ($page - 1) * $perPage, $perPage);

            $cols      = max(40, (int)($state['cols'] ?? 80));
            $nameWidth = max(20, (int)floor($cols * 0.35));
            $hostWidth = max(20, (int)floor($cols * 0.30));

            $rows = [];
            foreach ($slice as $idx => $entry) {
                $num      = ($page - 1) * $perPage + $idx + 1;
                $name     = $this->truncate((string)($entry['name'] ?? ''), $nameWidth);
                $location = (string)($entry['location'] ?? '');
                $host     = (string)($entry['telnet_host'] ?? '');
                $port     = (int)($entry['telnet_port'] ?? 23);
                $address  = $host !== '' ? ($port !== 23 ? "{$host}:{$port}" : $host) : '';
                $address  = $this->truncate($address, $hostWidth);

                $line = sprintf(
                    ' %3d) %s  %s',
                    $num,
                    TelnetUtils::colorize(str_pad($name, $nameWidth), TelnetUtils::ANSI_CYAN),
                    TelnetUtils::colorize($address, TelnetUtils::ANSI_DIM)
                );
                if ($location !== '') {
                    $line .= '  ' . TelnetUtils::colorize($location, TelnetUtils::ANSI_DIM);
                }
                $rows[] = $line;
            }

            $title = TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.bbslist.title',
                    'BBS Directory ({total} systems)',
                    ['total' => $total],
                    $locale
                ),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );

            $result        = $shell->showSelectableList($conn, $state, $title, $rows, $page, $totalPages, $selectedIndex, $statusBar);
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
                case 'select':
                    $entryIndex = ($page - 1) * $perPage + $result['index'];
                    if (isset($entries[$entryIndex])) {
                        $this->server->logAction($state['username'] ?? 'unknown', 'BBS List: viewed "' . ($entries[$entryIndex]['name'] ?? '') . '"');
                        $this->showDetail($conn, $state, $entries[$entryIndex], $shell);
                    }
                    break;
            }
        }
    }

    private function showDetail($conn, array &$state, array $entry, TerminalShellInterface $shell): void
    {
        $locale = $state['locale'];
        $cols   = max(40, (int)($state['cols'] ?? 80));
        $lines  = [];

        $fields = [
            ['ui.terminalserver.bbslist.detail.sysop',    'Sysop',    $entry['sysop'] ?? ''],
            ['ui.terminalserver.bbslist.detail.location',  'Location', $entry['location'] ?? ''],
            ['ui.terminalserver.bbslist.detail.os',        'OS',       $entry['os'] ?? ''],
        ];

        $host = (string)($entry['telnet_host'] ?? '');
        $port = (int)($entry['telnet_port'] ?? 23);
        if ($host !== '') {
            $address = $port !== 23 ? "{$host}:{$port}" : $host;
            $fields[] = ['ui.terminalserver.bbslist.detail.telnet', 'Telnet', $address];
        }
        if (!empty($entry['website'])) {
            $fields[] = ['ui.terminalserver.bbslist.detail.website', 'Website', $entry['website']];
        }

        $labelWidth = 10;
        foreach ($fields as [$key, $defaultLabel, $value]) {
            if ((string)$value === '') {
                continue;
            }
            $label = $this->server->t($key, $defaultLabel, [], $locale);
            $lines[] = sprintf(
                '  %s %s',
                TelnetUtils::colorize(str_pad($label . ':', $labelWidth + 1), TelnetUtils::ANSI_BOLD),
                $value
            );
        }

        if (!empty($entry['notes'])) {
            $lines[] = '';
            foreach (TelnetUtils::wrapTextLines((string)$entry['notes'], max(20, $cols - 4)) as $line) {
                $lines[] = $line;
            }
        }

        $shell->showText(
            $conn,
            $state,
            (string)($entry['name'] ?? ''),
            $lines
        );
    }

    private function fetchEntries(): array
    {
        $db        = Database::getInstance()->getPdo();
        $directory = new BbsDirectory($db);
        return $directory->getActiveEntries();
    }

    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) <= $max) {
            return $str;
        }
        return mb_substr($str, 0, $max - 1) . '…';
    }
}
