<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Nodelist\NodelistManager;

/**
 * NodelistBrowserHandler — Nodelist browser and search for the terminal server.
 *
 * Allows users to search the FTN nodelist by system name, sysop name, location,
 * or FTN address. Results are displayed in a paginated list with the option to
 * view full node details. Data is read directly via NodelistManager.
 */
class NodelistBrowserHandler
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
        $perPage = max(5, ($state['rows'] ?? 24) - 10);

        while (true) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.nodelist.title', 'Nodelist Browser', [], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                str_repeat('-', min(60, ($state['cols'] ?? 80) - 2)),
                TelnetUtils::ANSI_DIM
            ));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, $this->server->t(
                'ui.terminalserver.nodelist.search_hint',
                'Search by system name, sysop, location, or FTN address (e.g. 1:234/5)',
                [],
                $locale
            ));
            TelnetUtils::writeLine($conn, $this->server->t(
                'ui.terminalserver.nodelist.quit_hint',
                'Enter Q to return to main menu.',
                [],
                $locale
            ));
            TelnetUtils::writeLine($conn, '');

            $query = $this->server->prompt(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.nodelist.search_prompt', 'Search: ', [], $locale),
                true
            );

            if ($query === null) {
                return;
            }
            $query = trim($query);

            if (strtolower($query) === 'q' || $query === '') {
                return;
            }

            $this->server->logAction($state['username'] ?? 'unknown', "Nodelist: search \"{$query}\"");
            $results = $this->search($query);
            $this->showResults($conn, $state, $results, $query, $perPage);
        }
    }

    private function showResults($conn, array &$state, array $results, string $query, int $perPage): void
    {
        $locale     = $state['locale'];
        $total      = count($results);
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        $page       = 0;

        while (true) {
            $page  = max(0, min($page, $totalPages - 1));
            $slice = array_slice($results, $page * $perPage, $perPage);

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.nodelist.results_title',
                    'Results for "{query}" ({total} found)',
                    ['query' => $query, 'total' => $total],
                    $locale
                ),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                str_repeat('-', min(60, ($state['cols'] ?? 80) - 2)),
                TelnetUtils::ANSI_DIM
            ));

            if (empty($results)) {
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.nodelist.no_results', 'No nodes found.', [], $locale),
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
            $addrWidth = 14;
            $nameWidth = max(16, (int)floor(($cols - $addrWidth - 6) * 0.45));
            $sysopWidth = max(14, (int)floor(($cols - $addrWidth - 6) * 0.35));

            foreach ($slice as $idx => $node) {
                $num    = $page * $perPage + $idx + 1;
                $addr   = $this->formatAddress($node);
                $name   = $this->truncate((string)($node['system_name'] ?? ''), $nameWidth);
                $sysop  = $this->truncate((string)($node['sysop_name'] ?? ''), $sysopWidth);

                TelnetUtils::writeLine($conn, sprintf(
                    ' %3d) %s  %s  %s',
                    $num,
                    TelnetUtils::colorize(str_pad($addr, $addrWidth), TelnetUtils::ANSI_GREEN),
                    TelnetUtils::colorize(str_pad($name, $nameWidth), TelnetUtils::ANSI_CYAN),
                    TelnetUtils::colorize($sysop, TelnetUtils::ANSI_DIM)
                ));
            }

            TelnetUtils::writeLine($conn, '');
            $pageLabel = $this->server->t(
                'ui.terminalserver.nodelist.page',
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
            $promptParts[] = 'Q=back';

            TelnetUtils::writeLine($conn, implode(', ', $promptParts));

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
                $nodeIndex = (int)$choice - 1;
                if (isset($results[$nodeIndex])) {
                    $this->showNodeDetail($conn, $state, $results[$nodeIndex]);
                }
            }
        }
    }

    private function showNodeDetail($conn, array &$state, array $node): void
    {
        $locale = $state['locale'];
        $cols   = max(40, (int)($state['cols'] ?? 80));

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            (string)($node['system_name'] ?? $this->formatAddress($node)),
            TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            str_repeat('-', min(60, $cols - 2)),
            TelnetUtils::ANSI_DIM
        ));
        TelnetUtils::writeLine($conn, '');

        $fields = [
            ['ui.terminalserver.nodelist.detail.address',  'Address',  $this->formatAddress($node)],
            ['ui.terminalserver.nodelist.detail.sysop',    'Sysop',    $node['sysop_name'] ?? ''],
            ['ui.terminalserver.nodelist.detail.location', 'Location', $node['location'] ?? ''],
            ['ui.terminalserver.nodelist.detail.phone',    'Phone',    $node['phone'] ?? ''],
            ['ui.terminalserver.nodelist.detail.speed',    'Speed',    isset($node['baud_rate']) && $node['baud_rate'] ? (string)$node['baud_rate'] : ''],
            ['ui.terminalserver.nodelist.detail.type',     'Type',     $node['keyword_type'] ?? ''],
        ];

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

        if (!empty($node['flags']) && is_array($node['flags'])) {
            $flagStr = implode(', ', array_map(fn($f) => is_array($f) ? ($f['flag_name'] ?? '') : $f, $node['flags']));
            if ($flagStr !== '') {
                $label = $this->server->t('ui.terminalserver.nodelist.detail.flags', 'Flags', [], $locale);
                TelnetUtils::writeLine($conn, sprintf(
                    '  %s %s',
                    TelnetUtils::colorize(str_pad($label . ':', $labelWidth + 1), TelnetUtils::ANSI_BOLD),
                    $flagStr
                ));
            }
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_YELLOW
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    private function search(string $query): array
    {
        $manager = new NodelistManager();
        return $manager->searchNodes(['search_term' => $query]);
    }

    private function formatAddress(array $node): string
    {
        $zone  = (int)($node['zone'] ?? 0);
        $net   = (int)($node['net'] ?? 0);
        $n     = (int)($node['node'] ?? 0);
        $point = (int)($node['point'] ?? 0);
        $base  = "{$zone}:{$net}/{$n}";
        return $point > 0 ? "{$base}.{$point}" : $base;
    }

    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) <= $max) {
            return $str;
        }
        return mb_substr($str, 0, $max - 1) . '…';
    }
}
