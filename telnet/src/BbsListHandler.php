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
        $perPage = max(5, ($state['rows'] ?? 24) - 8);
        $page    = 0;

        $entries = $this->fetchEntries();

        while (true) {
            $total     = count($entries);
            $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
            $page       = max(0, min($page, $totalPages - 1));
            $slice      = array_slice($entries, $page * $perPage, $perPage);

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.bbslist.title',
                    'BBS Directory ({total} systems)',
                    ['total' => $total],
                    $locale
                ),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                str_repeat('-', min(60, ($state['cols'] ?? 80) - 2)),
                TelnetUtils::ANSI_DIM
            ));

            if (empty($entries)) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.bbslist.empty', 'No BBS listings available.', [], $locale),
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

            $cols      = max(40, (int)($state['cols'] ?? 80));
            $nameWidth = max(20, (int)floor($cols * 0.35));
            $hostWidth = max(20, (int)floor($cols * 0.30));

            foreach ($slice as $idx => $entry) {
                $num      = $page * $perPage + $idx + 1;
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
                TelnetUtils::writeLine($conn, $line);
            }

            TelnetUtils::writeLine($conn, '');
            $pageLabel = $this->server->t(
                'ui.terminalserver.bbslist.page',
                'Page {page}/{total}',
                ['page' => $page + 1, 'total' => $totalPages],
                $locale
            );
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($pageLabel, TelnetUtils::ANSI_DIM));

            $promptParts = ['#=view'];
            if ($page > 0) {
                $promptParts[] = 'P=prev';
            }
            if ($page < $totalPages - 1) {
                $promptParts[] = 'N=next';
            }
            $promptParts[] = 'Q=quit';

            TelnetUtils::writeLine($conn, $this->server->t(
                'ui.terminalserver.bbslist.prompt',
                implode(', ', $promptParts),
                [],
                $locale
            ));

            $choice = $this->server->prompt($conn, $state, '> ', true);
            if ($choice === null) {
                return;
            }
            $choice = strtolower(trim($choice));

            if ($choice === 'q' || $choice === '') {
                return;
            } elseif ($choice === 'n' && $page < $totalPages - 1) {
                $page++;
            } elseif ($choice === 'p' && $page > 0) {
                $page--;
            } elseif (ctype_digit($choice) && (int)$choice >= 1) {
                $entryIndex = (int)$choice - 1;
                if (isset($entries[$entryIndex])) {
                    $this->server->logAction($state['username'] ?? 'unknown', 'BBS List: viewed "' . ($entries[$entryIndex]['name'] ?? '') . '"');
                    $this->showDetail($conn, $state, $entries[$entryIndex]);
                }
            }
        }
    }

    private function showDetail($conn, array &$state, array $entry): void
    {
        $locale = $state['locale'];
        $cols   = max(40, (int)($state['cols'] ?? 80));

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            (string)($entry['name'] ?? ''),
            TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            str_repeat('-', min(60, $cols - 2)),
            TelnetUtils::ANSI_DIM
        ));
        TelnetUtils::writeLine($conn, '');

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
            TelnetUtils::writeLine($conn, sprintf(
                '  %s %s',
                TelnetUtils::colorize(str_pad($label . ':', $labelWidth + 1), TelnetUtils::ANSI_BOLD),
                $value
            ));
        }

        if (!empty($entry['notes'])) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeWrapped($conn, (string)$entry['notes'], $cols - 4);
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_YELLOW
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
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
