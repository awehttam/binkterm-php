<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Database;
use BinktermPHP\EchoareaSubscriptionManager;

/**
 * SubscriptionsHandler - echo area subscription management for the terminal server.
 *
 * Shows a list of networks (local areas first, then each network domain). Selecting a
 * network opens a list of its echo areas; selecting an area toggles the user's
 * subscription to it. Reads and writes go through EchoareaSubscriptionManager, the same
 * shared service used by the web Subscriptions page, so sysop-only areas stay hidden
 * from non-admin users and reactivation of old subscriptions behaves identically.
 */
class SubscriptionsHandler
{
    private BbsSession $server;
    private string $apiBase;

    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server  = $server;
        $this->apiBase = $apiBase;
    }

    // -- Public entry point ----------------------------------------------------------

    /**
     * Show the network list and handle user interaction.
     *
     * @param resource $conn
     * @param array    $state
     * @param string   $session
     */
    public function show($conn, array &$state, string $session): void
    {
        $shell = TerminalShellFactory::create($this->server, $state);
        $selectedIndex = 0;

        while (true) {
            $locale   = $state['locale'];
            $title    = $this->server->t('ui.terminalserver.subscriptions.title', 'Subscriptions', [], $locale);
            $networks = $this->groupByNetwork($this->fetchAreas((int)($state['user_id'] ?? 0)));

            if (empty($networks)) {
                $shell->showText(
                    $conn,
                    $state,
                    $title,
                    [$this->server->t('ui.terminalserver.subscriptions.none', 'No echo areas are available.', [], $locale)]
                );
                return;
            }

            $items = [];
            foreach ($networks as $network) {
                $subscribed = 0;
                foreach ($network['areas'] as $area) {
                    if ($this->isSubscribed($area)) {
                        $subscribed++;
                    }
                }
                $summary = $this->server->t(
                    'ui.terminalserver.subscriptions.network_summary',
                    '({subscribed}/{total} subscribed)',
                    ['subscribed' => $subscribed, 'total' => count($network['areas'])],
                    $locale
                );
                $items[] = sprintf(
                    '%-20s %s',
                    $this->networkLabel($network['domain'], $locale),
                    TelnetUtils::colorize($summary, TelnetUtils::ANSI_DIM)
                );
            }

            $choice = $shell->chooseFromList($conn, $state, $title, $items, [
                'prompt'         => '> ',
                'selected_index' => $selectedIndex,
            ]);
            if ($choice === null) {
                return;
            }
            if (!isset($networks[$choice])) {
                continue;
            }

            $selectedIndex = $choice;
            $this->showNetwork($conn, $state, $shell, $networks[$choice]['domain']);
        }
    }

    // -- Network screen --------------------------------------------------------------

    /**
     * Show the echo areas of one network and toggle subscriptions on selection.
     *
     * @param resource               $conn
     * @param array                  $state
     * @param TerminalShellInterface $shell
     * @param string                 $domain Network domain, or '' for local areas
     */
    private function showNetwork($conn, array &$state, TerminalShellInterface $shell, string $domain): void
    {
        $selectedIndex = 0;
        $userId        = (int)($state['user_id'] ?? 0);

        while (true) {
            $locale = $state['locale'];
            $areas  = [];
            foreach ($this->groupByNetwork($this->fetchAreas($userId)) as $network) {
                if ($network['domain'] === $domain) {
                    $areas = $network['areas'];
                    break;
                }
            }
            if (empty($areas)) {
                return;
            }

            $items = [];
            foreach ($areas as $area) {
                $badge = $this->isSubscribed($area)
                    ? TelnetUtils::colorize('[+]', TelnetUtils::ANSI_GREEN)
                    : TelnetUtils::colorize('[ ]', TelnetUtils::ANSI_DIM);
                $items[] = sprintf(
                    '%s %-20s %s',
                    $badge,
                    strtoupper((string)($area['tag'] ?? '')),
                    (string)($area['description'] ?? '')
                );
            }

            $choice = $shell->chooseFromList(
                $conn,
                $state,
                $this->server->t(
                    'ui.terminalserver.subscriptions.network_title',
                    'Subscriptions: {network} - Enter to subscribe/unsubscribe',
                    ['network' => $this->networkLabel($domain, $locale)],
                    $locale
                ),
                $items,
                [
                    'prompt'         => '> ',
                    'selected_index' => $selectedIndex,
                ]
            );
            if ($choice === null) {
                return;
            }
            if (!isset($areas[$choice])) {
                continue;
            }

            $selectedIndex = $choice;
            $this->toggle($conn, $state, $shell, $areas[$choice]);
        }
    }

    /**
     * Flip the user's subscription to one echo area, reporting any failure.
     *
     * @param resource               $conn
     * @param array                  $state
     * @param TerminalShellInterface $shell
     * @param array                  $area  Echo area row from fetchAreas()
     */
    private function toggle($conn, array &$state, TerminalShellInterface $shell, array $area): void
    {
        $locale  = $state['locale'];
        $userId  = (int)($state['user_id'] ?? 0);
        $areaId  = (int)($area['id'] ?? 0);
        $label   = strtoupper((string)($area['tag'] ?? ''));
        $domain  = (string)($area['domain'] ?? '');
        if ($domain !== '') {
            $label .= '@' . $domain;
        }

        try {
            Database::getLivePdo();
            $manager = new EchoareaSubscriptionManager();
            if ($this->isSubscribed($area)) {
                $ok  = $manager->unsubscribeUser($userId, $areaId);
                $log = 'unsubscribed from';
            } else {
                $ok  = $manager->subscribeUser($userId, $areaId);
                $log = 'subscribed to';
            }
        } catch (\Throwable $e) {
            Database::heal($e);
            $ok  = false;
            $log = '';
        }

        if ($ok) {
            $this->server->logAction($state['username'] ?? 'unknown', "Subscriptions: {$log} {$label}");
            return;
        }

        $failKey = $this->isSubscribed($area)
            ? ['ui.terminalserver.subscriptions.unsubscribe_failed', 'Failed to unsubscribe from {area}.']
            : ['ui.terminalserver.subscriptions.subscribe_failed', 'Failed to subscribe to {area}.'];
        $shell->showAlert(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.subscriptions.title', 'Subscriptions', [], $locale),
            $this->server->t($failKey[0], $failKey[1], ['area' => $label], $locale),
            'error'
        );
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Fetch every echo area visible to the user with its subscription state.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAreas(int $userId): array
    {
        try {
            Database::getLivePdo();
            return (new EchoareaSubscriptionManager())->getAllEchoareasWithSubscriptionStatus($userId);
        } catch (\Throwable $e) {
            Database::heal($e);
            return [];
        }
    }

    /**
     * Group areas by network, keeping the order the manager returned them in
     * (local areas first). Local areas share the empty-string domain.
     *
     * @param  array<int, array<string, mixed>> $areas
     * @return array<int, array{domain:string, areas:array<int, array<string, mixed>>}>
     */
    private function groupByNetwork(array $areas): array
    {
        $groups = [];
        foreach ($areas as $area) {
            $domain = !empty($area['is_local']) && $area['is_local'] !== 'f'
                ? ''
                : trim((string)($area['domain'] ?? ''));
            if (!isset($groups[$domain])) {
                $groups[$domain] = ['domain' => $domain, 'areas' => []];
            }
            $groups[$domain]['areas'][] = $area;
        }
        return array_values($groups);
    }

    private function networkLabel(string $domain, string $locale): string
    {
        return $domain === ''
            ? $this->server->t('ui.terminalserver.subscriptions.local_network', 'Local', [], $locale)
            : $domain;
    }

    private function isSubscribed(array $area): bool
    {
        $value = $area['subscribed'] ?? false;
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
