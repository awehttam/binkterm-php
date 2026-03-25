<?php

namespace BinktermPHP\TelnetServer;

/**
 * InterestsHandler — Interests browsing and subscription management for the telnet server.
 *
 * Presents active interests as a numbered list. Users can subscribe, unsubscribe,
 * and view the echo areas included in each interest. All business logic is
 * delegated to the existing /api/interests/* endpoints.
 */
class InterestsHandler
{
    private BbsSession $server;
    private string $apiBase;

    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server  = $server;
        $this->apiBase = $apiBase;
    }

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Show the interests list and handle user interaction.
     */
    public function show($conn, array &$state, string $session): void
    {
        while (true) {
            $interests = $this->fetchInterests($session);
            $locale    = $state['locale'];

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.interests.title', 'Interests', [], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, '');

            if (empty($interests)) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.interests.none', 'No interests are available.', [], $locale),
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

            foreach ($interests as $idx => $interest) {
                $num        = $idx + 1;
                $subscribed = !empty($interest['subscribed']);
                $badge      = $subscribed
                    ? TelnetUtils::colorize('[+]', TelnetUtils::ANSI_GREEN)
                    : TelnetUtils::colorize('[ ]', TelnetUtils::ANSI_DIM);
                $areaCount  = (int)($interest['echoarea_count'] ?? 0);
                $name       = (string)($interest['name'] ?? '');
                $countLabel = $this->server->t(
                    'ui.terminalserver.interests.area_count',
                    '({count} areas)',
                    ['count' => $areaCount],
                    $locale
                );

                TelnetUtils::writeLine($conn, sprintf(
                    ' %2d) %s %s %s',
                    $num,
                    $badge,
                    $name,
                    TelnetUtils::colorize($countLabel, TelnetUtils::ANSI_DIM)
                ));
            }

            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, $this->server->t(
                'ui.terminalserver.interests.prompt',
                'Enter # to view, Q to return:',
                [],
                $locale
            ));

            $choice = $this->server->prompt($conn, $state, '> ', true);
            if ($choice === null || strtolower(trim($choice)) === 'q' || trim($choice) === '') {
                return;
            }

            $idx = (int)trim($choice) - 1;
            if (!isset($interests[$idx])) {
                continue;
            }

            $this->server->logAction($state['username'] ?? 'unknown', 'Interests: viewed "' . ($interests[$idx]['name'] ?? '') . '"');
            $this->showDetail($conn, $state, $session, $interests[$idx]);
        }
    }

    // ── Detail screen ─────────────────────────────────────────────────────────

    private function showDetail($conn, array &$state, string $session, array $interest): void
    {
        $locale = $state['locale'];
        $id     = (int)($interest['id'] ?? 0);

        while (true) {
            // Refresh subscription status on each loop
            $fresh = $this->fetchInterest($session, $id);
            if ($fresh === null) {
                return;
            }

            $subscribed = !empty($fresh['subscribed']);
            $cols       = max(40, (int)($state['cols'] ?? 80));

            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                (string)($fresh['name'] ?? ''),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, '');

            if (!empty($fresh['description'])) {
                TelnetUtils::writeWrapped($conn, (string)$fresh['description'], $cols - 2);
                TelnetUtils::writeLine($conn, '');
            }

            $statusLabel = $subscribed
                ? TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.interests.subscribed', 'Subscribed', [], $locale),
                    TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD
                )
                : TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.interests.not_subscribed', 'Not subscribed', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                );
            TelnetUtils::writeLine($conn, $this->server->t(
                'ui.terminalserver.interests.status_label',
                'Status: {status}',
                ['status' => ''],
                $locale
            ) . $statusLabel);
            TelnetUtils::writeLine($conn, '');

            // Echo areas inline
            $resp  = TelnetUtils::apiRequest($this->apiBase, 'GET', "/api/interests/{$id}/echoareas", null, $session);
            $areas = $resp['data']['echoareas'] ?? [];

            if (!empty($areas)) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.interests.areas_heading', 'Echo Areas:', [], $locale),
                    TelnetUtils::ANSI_BOLD
                ));
                foreach ($areas as $area) {
                    $tag  = strtoupper((string)($area['tag'] ?? ''));
                    $desc = (string)($area['description'] ?? '');
                    $line = sprintf('  %-20s %s', $tag, $desc);
                    TelnetUtils::writeLine($conn, mb_substr($line, 0, $cols - 1));
                }
                TelnetUtils::writeLine($conn, '');
            }

            // Actions
            if ($subscribed) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.interests.action_unsubscribe', 'U) Unsubscribe', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                ));
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.interests.action_subscribe', 'S) Subscribe', [], $locale),
                    TelnetUtils::ANSI_GREEN
                ));
            }
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.interests.action_quit', 'Q) Back', [], $locale));
            TelnetUtils::writeLine($conn, '');

            $choice = $this->server->prompt($conn, $state, '> ', true);
            if ($choice === null) {
                return;
            }
            $choice = strtolower(trim($choice));

            if ($choice === 'q' || $choice === '') {
                return;
            } elseif ($choice === 's' && !$subscribed) {
                $this->subscribe($conn, $state, $session, $fresh);
            } elseif ($choice === 'u' && $subscribed) {
                $this->unsubscribe($conn, $state, $session, $fresh);
            }
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    private function subscribe($conn, array &$state, string $session, array $interest): void
    {
        $locale = $state['locale'];
        $id     = (int)($interest['id'] ?? 0);

        $resp = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', "/api/interests/{$id}/subscribe",
            [], $session, 3, $state['csrf_token'] ?? null
        );

        TelnetUtils::writeLine($conn, '');
        if (($resp['data']['success'] ?? false) === true) {
            $this->server->logAction($state['username'] ?? 'unknown', 'Interests: subscribed to "' . ($interest['name'] ?? '') . '"');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.interests.subscribe_ok', 'Subscribed successfully.', [], $locale),
                TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD
            ));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.interests.subscribe_failed', 'Failed to subscribe.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
        }
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_continue', 'Press any key to continue...', [], $locale),
            TelnetUtils::ANSI_YELLOW
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    private function unsubscribe($conn, array &$state, string $session, array $interest): void
    {
        $locale = $state['locale'];
        $id     = (int)($interest['id'] ?? 0);

        $resp = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', "/api/interests/{$id}/unsubscribe",
            [], $session, 3, $state['csrf_token'] ?? null
        );

        TelnetUtils::writeLine($conn, '');
        if (($resp['data']['success'] ?? false) === true) {
            $this->server->logAction($state['username'] ?? 'unknown', 'Interests: unsubscribed from "' . ($interest['name'] ?? '') . '"');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.interests.unsubscribe_ok', 'Unsubscribed successfully.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.interests.unsubscribe_failed', 'Failed to unsubscribe.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
        }
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_continue', 'Press any key to continue...', [], $locale),
            TelnetUtils::ANSI_YELLOW
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    // ── API helpers ───────────────────────────────────────────────────────────

    private function fetchInterests(string $session): array
    {
        $resp = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/interests', null, $session);
        return $resp['data']['interests'] ?? [];
    }

    private function fetchInterest(string $session, int $id): ?array
    {
        $interests = $this->fetchInterests($session);
        foreach ($interests as $interest) {
            if ((int)($interest['id'] ?? 0) === $id) {
                return $interest;
            }
        }
        return null;
    }

    // ── Onboarding hint ───────────────────────────────────────────────────────

    /**
     * Show a one-time hint if the user has no interest subscriptions.
     * Call this once after login when ENABLE_INTERESTS is true.
     */
    public function showOnboardingHintIfNeeded($conn, array &$state, string $session): void
    {
        $locale    = $state['locale'];
        $interests = $this->fetchInterests($session);
        if (empty($interests)) {
            return;
        }

        $hasAny = false;
        foreach ($interests as $i) {
            if (!empty($i['subscribed'])) {
                $hasAny = true;
                break;
            }
        }

        if ($hasAny) {
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t(
                'ui.terminalserver.interests.onboarding_hint',
                'Tip: Use the Interests menu (I) to subscribe to topic groups and discover echo areas.',
                [],
                $locale
            ),
            TelnetUtils::ANSI_YELLOW
        ));
        TelnetUtils::writeLine($conn, '');
    }
}
