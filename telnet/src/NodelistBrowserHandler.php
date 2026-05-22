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
 * The browse lists, top-level menu, search input, and detail viewer now route
 * through the terminal shell abstraction.
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
        $shell = TerminalShellFactory::create($this->server, $state);
        $this->browseZones($conn, $state, $shell);
    }

    // ── Browse: zone list ─────────────────────────────────────────────────────

    private function browseZones($conn, array &$state, TerminalShellInterface $shell): void
    {
        $locale  = $state['locale'];
        $manager = new NodelistManager();
        $zones   = $manager->getZones();

        if (empty($zones)) {
            $shell->showText(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.nodelist.zones_title', 'Networks', [], $locale),
                [$this->server->t('ui.terminalserver.nodelist.no_zones', 'No nodelist data available.', [], $locale)]
            );
            return;
        }

        // Enrich with net and node counts for the top-level network list.
        $db = Database::getInstance()->getPdo();
        foreach ($zones as &$z) {
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT net) AS net_count, COUNT(*) AS node_count
                FROM nodelist
                WHERE zone = ? AND domain = ?
            ");
            $stmt->execute([(int)$z['zone'], (string)($z['domain'] ?? '')]);
            $counts = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $z['net_count'] = (int)($counts['net_count'] ?? 0);
            $z['node_count'] = (int)($counts['node_count'] ?? 0);
        }
        unset($z);

        $statusBar = [
            ['text' => 'U/D',      'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter',    'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Select  ','color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',        'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Back',    'color' => TelnetUtils::ANSI_BLUE],
        ];

        $searchIndex   = count($zones);
        $selectedIndex = 0;

        while (true) {
            $rows = [];
            foreach ($zones as $z) {
                $domain = (string)($z['domain'] ?? 'unknown');
                $zone   = (int)$z['zone'];
                $nets   = (int)$z['net_count'];
                $nodes  = (int)($z['node_count'] ?? 0);
                $netLabel  = $nets === 1
                    ? $this->server->t('ui.terminalserver.nodelist.net_count_one', '1 net', [], $locale)
                    : $this->server->t('ui.terminalserver.nodelist.net_count', '{count} nets', ['count' => $nets], $locale);
                $nodeLabel = $nodes === 1
                    ? $this->server->t('ui.terminalserver.nodelist.node_count_one', '1 node', [], $locale)
                    : $this->server->t('ui.terminalserver.nodelist.node_count', '{count} nodes', ['count' => $nodes], $locale);
                $label = $netLabel . ', ' . $nodeLabel;

                $rows[] = sprintf(
                    ' %s  Zone %-4d  %s',
                    TelnetUtils::colorize(str_pad($domain, 12), TelnetUtils::ANSI_CYAN),
                    $zone,
                    TelnetUtils::colorize($label, TelnetUtils::ANSI_DIM)
                );
            }

            $rows[] = TelnetUtils::colorize(
                '  ' . $this->server->t('ui.terminalserver.nodelist.search_title', 'Nodelist Search', [], $locale),
                TelnetUtils::ANSI_DIM
            );

            $title = TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.nodelist.zones_title', 'Networks', [], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );

            $result        = $shell->showSelectableList($conn, $state, $title, $rows, 1, 1, $selectedIndex, $statusBar);
            $selectedIndex = $result['selectedIndex'];

            switch ($result['action']) {
                case 'disconnect':
                    return;
                case 'quit':
                    return;
                case 'select':
                    $idx = $result['index'];
                    if ($idx === $searchIndex) {
                        $this->searchMode($conn, $state, $shell);
                    } elseif (isset($zones[$idx])) {
                        $this->browseNets($conn, $state, (int)$zones[$idx]['zone'], (string)($zones[$idx]['domain'] ?? ''), $manager, $shell);
                    }
                    break;
            }
        }
    }

    // ── Browse: net list ──────────────────────────────────────────────────────

    private function browseNets($conn, array &$state, int $zone, string $domain, NodelistManager $manager, TerminalShellInterface $shell): void
    {
        $locale  = $state['locale'];
        $perPage = max(5, ($state['rows'] ?? 24) - 3);
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
        $page       = 1;

        $statusBar = [
            ['text' => 'U/D',      'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'L/R',      'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Page  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter',    'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Select  ','color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',        'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Back',    'color' => TelnetUtils::ANSI_BLUE],
        ];

        $selectedIndex = 0;

        while (true) {
            $page  = max(1, min($page, $totalPages));
            $slice = array_slice($nets, ($page - 1) * $perPage, $perPage);

            $rows = [];
            foreach ($slice as $net) {
                $netNum    = (int)$net['net'];
                $count     = (int)$net['node_count'];
                $hub       = $this->truncate((string)($net['hub_name'] ?? ''), 28);
                $nodeLabel = $count === 1
                    ? $this->server->t('ui.terminalserver.nodelist.node_count_one', '1 node', [], $locale)
                    : $this->server->t('ui.terminalserver.nodelist.node_count', '{count} nodes', ['count' => $count], $locale);

                $rows[] = sprintf(
                    ' Net %-5d  %s  %s',
                    $netNum,
                    TelnetUtils::colorize(str_pad($nodeLabel, 10), TelnetUtils::ANSI_DIM),
                    TelnetUtils::colorize($hub, TelnetUtils::ANSI_DIM)
                );
            }

            $titleText = $domain !== '' ? "Zone {$zone} — {$domain}" : "Zone {$zone}";
            $title     = TelnetUtils::colorize($titleText, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD);

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
                    $idx = ($page - 1) * $perPage + $result['index'];
                    if (isset($nets[$idx])) {
                        $nodes = $manager->getNodesByZoneNet($zone, (int)$nets[$idx]['net']);
                        $this->browseNodes($conn, $state, $nodes, "Net {$zone}:{$nets[$idx]['net']}", $shell);
                    }
                    break;
            }
        }
    }

    // ── Browse: node list ─────────────────────────────────────────────────────

    /**
     * Display a paginated list of nodes. Used for both browse-by-net and search
     * results. From the list the user can navigate with arrow keys, type a number,
     * or press Enter on the highlighted row to view the node detail.
     */
    private function browseNodes($conn, array &$state, array $nodes, string $heading, TerminalShellInterface $shell): void
    {
        $locale     = $state['locale'];
        $perPage    = max(5, ($state['rows'] ?? 24) - 3);
        $total      = count($nodes);
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        $page       = 1;

        if (empty($nodes)) {
            $shell->showText(
                $conn,
                $state,
                $heading,
                [$this->server->t('ui.terminalserver.nodelist.no_results', 'No nodes found.', [], $locale)]
            );
            return;
        }

        $statusBar = [
            ['text' => 'U/D',      'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'L/R',      'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Page  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter',    'color' => TelnetUtils::ANSI_RED],
            ['text' => ' View  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',        'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Back',    'color' => TelnetUtils::ANSI_BLUE],
        ];

        $selectedIndex = 0;

        while (true) {
            $page  = max(1, min($page, $totalPages));
            $slice = array_slice($nodes, ($page - 1) * $perPage, $perPage);

            $cols       = max(40, (int)($state['cols'] ?? 80));
            $addrWidth  = 14;
            $nameWidth  = max(16, (int)floor(($cols - $addrWidth - 6) * 0.45));
            $sysopWidth = max(14, (int)floor(($cols - $addrWidth - 6) * 0.35));

            $rows = [];
            foreach ($slice as $node) {
                $addr  = $this->formatAddress($node);
                $name  = $this->truncate((string)($node['system_name'] ?? ''), $nameWidth);
                $sysop = $this->truncate((string)($node['sysop_name'] ?? ''), $sysopWidth);

                $rows[] = sprintf(
                    ' %s  %s  %s',
                    TelnetUtils::colorize(str_pad($addr, $addrWidth), TelnetUtils::ANSI_GREEN),
                    TelnetUtils::colorize(str_pad($name, $nameWidth), TelnetUtils::ANSI_CYAN),
                    TelnetUtils::colorize($sysop, TelnetUtils::ANSI_DIM)
                );
            }

            $title = TelnetUtils::colorize(
                $heading . ' (' . $total . ' nodes)',
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
                    $listIdx = ($page - 1) * $perPage + $result['index'];
                    $target  = $nodes[$listIdx] ?? null;
                    if ($target !== null) {
                        $this->showNodeDetail($conn, $state, $target, $shell);
                    }
                    break;
            }
        }
    }

    // ── Search mode ───────────────────────────────────────────────────────────

    private function searchMode($conn, array &$state, TerminalShellInterface $shell): void
    {
        $locale = $state['locale'];
        $query = $shell->promptText(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.nodelist.search_title', 'Nodelist Search', [], $locale),
            $this->server->t(
                'ui.terminalserver.nodelist.search_hint',
                'Search by system name, sysop, location, or FTN address (e.g. 1:234/5)',
                [],
                $locale
            ),
            [
                'max_length' => 120,
            ]
        );

        if ($query === null) {
            return;
        }
        $query = trim($query);
        if (strtolower($query) === 'q' || $query === '') {
            return;
        }

        $this->server->logAction($state['username'] ?? 'unknown', "Nodelist: search \"{$query}\"");
        $results = (new NodelistManager())->searchNodes(['search_term' => $query]);
        $this->browseNodes(
            $conn,
            $state,
            $results,
            $this->server->t('ui.terminalserver.nodelist.results_for', 'Results for "{query}"', ['query' => $query], $locale),
            $shell
        );
    }

    // ── Node detail ───────────────────────────────────────────────────────────

    private function showNodeDetail($conn, array &$state, array $node, TerminalShellInterface $shell): void
    {
        $locale = $state['locale'];
        $lines = [];

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
            $lines[] = sprintf(
                '  %s %s',
                TelnetUtils::colorize(str_pad($label . ':', $labelWidth + 1), TelnetUtils::ANSI_BOLD),
                $value
            );
        }

        if (!empty($node['flags']) && is_array($node['flags'])) {
            $flagStr = implode(', ', array_map(
                fn($f) => is_array($f) ? ($f['flag_name'] ?? '') : (string)$f,
                $node['flags']
            ));
            if ($flagStr !== '') {
                $label = $this->server->t('ui.terminalserver.nodelist.detail.flags', 'Flags', [], $locale);
                $lines[] = sprintf(
                    '  %s %s',
                    TelnetUtils::colorize(str_pad($label . ':', $labelWidth + 1), TelnetUtils::ANSI_BOLD),
                    $flagStr
                );
            }
        }

        $shell->showText(
            $conn,
            $state,
            (string)($node['system_name'] ?? $this->formatAddress($node)),
            $lines
        );
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
