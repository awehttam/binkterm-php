<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Nodelist\NodelistManager;
use BinktermPHP\Database;

/**
 * NodelistBrowserHandler — Nodelist browser and search for the terminal server.
 *
 * Offers two modes:
 *  - Browse: zone list → net list → paginated node list
 *  - Search: free-text search by system name, sysop, location, or FTN address
 *
 * From any paginated node list the user can enter a list item number or the
 * raw node number within the current net to jump straight to the detail view.
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

    // ── Main menu ─────────────────────────────────────────────────────────────

    public function show($conn, array &$state, string $session): void
    {
        $locale = $state['locale'];

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
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.nodelist.menu.browse', '(B) Browse networks', [], $locale));
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.nodelist.menu.search', '(S) Search', [], $locale));
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.nodelist.menu.quit',   '(Q) Return to main menu', [], $locale));
            TelnetUtils::writeLine($conn, '');

            $choice = $this->server->prompt($conn, $state, '> ', true);
            if ($choice === null) {
                return;
            }
            $choice = strtolower(trim($choice));

            if ($choice === 'q' || $choice === '') {
                return;
            } elseif ($choice === 'b') {
                $this->browseZones($conn, $state);
            } elseif ($choice === 's') {
                $this->searchMode($conn, $state);
            }
        }
    }

    // ── Browse: zone list ─────────────────────────────────────────────────────

    private function browseZones($conn, array &$state): void
    {
        $locale  = $state['locale'];
        $manager = new NodelistManager();
        $zones   = $manager->getZones();

        if (empty($zones)) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.nodelist.no_zones', 'No nodelist data available.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        // Enrich with net counts
        $db = Database::getInstance()->getPdo();
        foreach ($zones as &$z) {
            $stmt = $db->prepare("SELECT COUNT(DISTINCT net) FROM nodelist WHERE zone = ? AND domain = ?");
            $stmt->execute([(int)$z['zone'], (string)($z['domain'] ?? '')]);
            $z['net_count'] = (int)$stmt->fetchColumn();
        }
        unset($z);

        while (true) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.nodelist.zones_title', 'Networks', [], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                str_repeat('-', min(60, ($state['cols'] ?? 80) - 2)),
                TelnetUtils::ANSI_DIM
            ));
            TelnetUtils::writeLine($conn, '');

            foreach ($zones as $idx => $z) {
                $num    = $idx + 1;
                $domain = (string)($z['domain'] ?? 'unknown');
                $zone   = (int)$z['zone'];
                $nets   = (int)$z['net_count'];
                $label  = $nets === 1
                    ? $this->server->t('ui.terminalserver.nodelist.net_count_one', '1 net', [], $locale)
                    : $this->server->t('ui.terminalserver.nodelist.net_count', '{count} nets', ['count' => $nets], $locale);

                TelnetUtils::writeLine($conn, sprintf(
                    ' %3d) %s  Zone %-4d  %s',
                    $num,
                    TelnetUtils::colorize(str_pad($domain, 12), TelnetUtils::ANSI_CYAN),
                    $zone,
                    TelnetUtils::colorize($label, TelnetUtils::ANSI_DIM)
                ));
            }

            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, $this->server->t(
                'ui.terminalserver.nodelist.zones_prompt', '#=select, Q=back', [], $locale
            ));

            $choice = $this->server->prompt($conn, $state, '> ', true);
            if ($choice === null) {
                return;
            }
            $choice = strtolower(trim($choice));

            if ($choice === 'q' || $choice === '') {
                return;
            } elseif (ctype_digit($choice) && (int)$choice >= 1) {
                $idx = (int)$choice - 1;
                if (isset($zones[$idx])) {
                    $this->browseNets($conn, $state, (int)$zones[$idx]['zone'], (string)($zones[$idx]['domain'] ?? ''), $manager);
                }
            }
        }
    }

    // ── Browse: net list ──────────────────────────────────────────────────────

    private function browseNets($conn, array &$state, int $zone, string $domain, NodelistManager $manager): void
    {
        $locale  = $state['locale'];
        $perPage = max(5, ($state['rows'] ?? 24) - 9);
        $db      = Database::getInstance()->getPdo();

        // Fetch nets with node counts and an optional hub name
        $stmt = $db->prepare("
            SELECT net,
                   COUNT(*) AS node_count,
                   MAX(CASE WHEN keyword_type IN ('Host','Hub','Zone','Region','Coordinator') THEN system_name END) AS hub_name
            FROM nodelist
            WHERE zone = ? AND domain = ?
            GROUP BY net
            ORDER BY net
        ");
        $stmt->execute([$zone, $domain]);
        $nets = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($nets)) {
            return;
        }

        $total      = count($nets);
        $totalPages = (int)ceil($total / $perPage);
        $page       = 0;

        while (true) {
            $page  = max(0, min($page, $totalPages - 1));
            $slice = array_slice($nets, $page * $perPage, $perPage);

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            $title = $domain !== '' ? "Zone {$zone} — {$domain}" : "Zone {$zone}";
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($title, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                str_repeat('-', min(60, ($state['cols'] ?? 80) - 2)),
                TelnetUtils::ANSI_DIM
            ));

            $listBase = $page * $perPage;
            foreach ($slice as $idx => $net) {
                $num      = $listBase + $idx + 1;
                $netNum   = (int)$net['net'];
                $count    = (int)$net['node_count'];
                $hub      = $this->truncate((string)($net['hub_name'] ?? ''), 28);
                $nodeLabel = $count === 1
                    ? $this->server->t('ui.terminalserver.nodelist.node_count_one', '1 node', [], $locale)
                    : $this->server->t('ui.terminalserver.nodelist.node_count', '{count} nodes', ['count' => $count], $locale);

                TelnetUtils::writeLine($conn, sprintf(
                    ' %3d) Net %-5d  %s  %s',
                    $num,
                    $netNum,
                    TelnetUtils::colorize(str_pad($nodeLabel, 10), TelnetUtils::ANSI_DIM),
                    TelnetUtils::colorize($hub, TelnetUtils::ANSI_DIM)
                ));
            }

            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.nodelist.page', 'Page {page}/{total}', ['page' => $page + 1, 'total' => $totalPages], $locale),
                TelnetUtils::ANSI_DIM
            ));

            $parts = ['#=select'];
            if ($page > 0)              { $parts[] = 'P=prev'; }
            if ($page < $totalPages -1) { $parts[] = 'N=next'; }
            $parts[] = 'Q=back';
            TelnetUtils::writeLine($conn, implode(', ', $parts));

            $choice = $this->server->prompt($conn, $state, '> ', true);
            if ($choice === null) { return; }
            $choice = strtolower(trim($choice));

            if ($choice === 'q' || $choice === '') {
                return;
            } elseif ($choice === 'n' && $page < $totalPages - 1) {
                $page++;
            } elseif ($choice === 'p' && $page > 0) {
                $page--;
            } elseif (ctype_digit($choice) && (int)$choice >= 1) {
                $idx = (int)$choice - 1;
                if (isset($nets[$idx])) {
                    $nodes = $manager->getNodesByZoneNet($zone, (int)$nets[$idx]['net']);
                    $this->browseNodes($conn, $state, $nodes, "Net {$zone}:{$nets[$idx]['net']}");
                }
            }
        }
    }

    // ── Browse: node list ─────────────────────────────────────────────────────

    /**
     * Display a paginated list of nodes. Used for both browse-by-net and search
     * results. From the pagination prompt the user can enter:
     *   - a list item number (1, 2, 3…) to view that node
     *   - the actual node number within the net (e.g. "5" for .../5)
     *     if it uniquely matches a node and differs from a list item number
     */
    private function browseNodes($conn, array &$state, array $nodes, string $heading): void
    {
        $locale     = $state['locale'];
        $perPage    = max(5, ($state['rows'] ?? 24) - 9);
        $total      = count($nodes);
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        $page       = 0;

        while (true) {
            $page  = max(0, min($page, $totalPages - 1));
            $slice = array_slice($nodes, $page * $perPage, $perPage);

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $heading . ' (' . $total . ' nodes)',
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                str_repeat('-', min(60, ($state['cols'] ?? 80) - 2)),
                TelnetUtils::ANSI_DIM
            ));

            if (empty($nodes)) {
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

            $cols       = max(40, (int)($state['cols'] ?? 80));
            $addrWidth  = 14;
            $nameWidth  = max(16, (int)floor(($cols - $addrWidth - 6) * 0.45));
            $sysopWidth = max(14, (int)floor(($cols - $addrWidth - 6) * 0.35));
            $listBase   = $page * $perPage;

            foreach ($slice as $idx => $node) {
                $num   = $listBase + $idx + 1;
                $addr  = $this->formatAddress($node);
                $name  = $this->truncate((string)($node['system_name'] ?? ''), $nameWidth);
                $sysop = $this->truncate((string)($node['sysop_name'] ?? ''), $sysopWidth);

                TelnetUtils::writeLine($conn, sprintf(
                    ' %3d) %s  %s  %s',
                    $num,
                    TelnetUtils::colorize(str_pad($addr, $addrWidth), TelnetUtils::ANSI_GREEN),
                    TelnetUtils::colorize(str_pad($name, $nameWidth), TelnetUtils::ANSI_CYAN),
                    TelnetUtils::colorize($sysop, TelnetUtils::ANSI_DIM)
                ));
            }

            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.nodelist.page', 'Page {page}/{total}', ['page' => $page + 1, 'total' => $totalPages], $locale),
                TelnetUtils::ANSI_DIM
            ));

            $parts = ['#=view'];
            if ($page > 0)              { $parts[] = 'P=prev'; }
            if ($page < $totalPages -1) { $parts[] = 'N=next'; }
            $parts[] = 'Q=back';
            TelnetUtils::writeLine($conn, implode(', ', $parts));

            $choice = $this->server->prompt($conn, $state, '> ', true);
            if ($choice === null) { return; }
            $choice = strtolower(trim($choice));

            if ($choice === 'q' || $choice === '') {
                return;
            } elseif ($choice === 'n' && $page < $totalPages - 1) {
                $page++;
            } elseif ($choice === 'p' && $page > 0) {
                $page--;
            } elseif (ctype_digit($choice) && (int)$choice >= 1) {
                $entered = (int)$choice;
                // First try as a 1-based list index across all nodes
                $byList = $nodes[$entered - 1] ?? null;
                // Then try matching the raw node number within the net
                $byNode = null;
                foreach ($nodes as $n) {
                    if ((int)($n['node'] ?? -1) === $entered) {
                        $byNode = $n;
                        break;
                    }
                }
                // Prefer list index; fall back to node number match
                $target = $byList ?? $byNode;
                if ($target !== null) {
                    $this->showNodeDetail($conn, $state, $target);
                }
            }
        }
    }

    // ── Search mode ───────────────────────────────────────────────────────────

    private function searchMode($conn, array &$state): void
    {
        $locale = $state['locale'];

        while (true) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.nodelist.search_title', 'Nodelist Search', [], $locale),
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
                'Enter Q to go back.',
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

            if ($query === null) { return; }
            $query = trim($query);
            if (strtolower($query) === 'q' || $query === '') { return; }

            $this->server->logAction($state['username'] ?? 'unknown', "Nodelist: search \"{$query}\"");
            $results = (new NodelistManager())->searchNodes(['search_term' => $query]);
            $this->browseNodes(
                $conn, $state, $results,
                $this->server->t('ui.terminalserver.nodelist.results_for', 'Results for "{query}"', ['query' => $query], $locale)
            );
        }
    }

    // ── Node detail ───────────────────────────────────────────────────────────

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
            if ((string)$value === '') { continue; }
            $label = $this->server->t($key, $defaultLabel, [], $locale);
            TelnetUtils::writeLine($conn, sprintf(
                '  %s %s',
                TelnetUtils::colorize(str_pad($label . ':', $labelWidth + 1), TelnetUtils::ANSI_BOLD),
                $value
            ));
        }

        if (!empty($node['flags']) && is_array($node['flags'])) {
            $flagStr = implode(', ', array_map(
                fn($f) => is_array($f) ? ($f['flag_name'] ?? '') : (string)$f,
                $node['flags']
            ));
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

    // ── Helpers ───────────────────────────────────────────────────────────────

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
