<?php

namespace BinktermPHP\TelnetServer;

/**
 * InterestsHandler — Interests browsing and subscription management for the telnet server.
 *
 * Presents active interests as a numbered list. Users can subscribe, unsubscribe,
 * and view the echo areas included in each interest. Interest lists and subscription
 * status are read directly from the database via InterestManager (more reliable than
 * HTTP API cookie auth from a server process). Write operations (subscribe/unsubscribe)
 * are still delegated to the /api/interests/* endpoints.
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
        $shell = TerminalShellFactory::create($this->server, $state);

        while (true) {
            $userId    = (int)($state['user_id'] ?? 0);
            $interests = $this->fetchInterests($userId);
            $locale    = $state['locale'];

            if (empty($interests)) {
                $shell->showText(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.interests.title', 'Interests', [], $locale),
                    [$this->server->t('ui.terminalserver.interests.none', 'No interests are available.', [], $locale)]
                );
                return;
            }

            $items = [];
            foreach ($interests as $idx => $interest) {
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

                $items[] = sprintf(
                    '%s %s %s',
                    $badge,
                    $name,
                    TelnetUtils::colorize($countLabel, TelnetUtils::ANSI_DIM)
                );
            }

            $choice = $shell->chooseFromList(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.interests.title', 'Interests', [], $locale),
                $items,
                [
                    'prompt' => '> ',
                ]
            );
            if ($choice === null) {
                return;
            }

            if (!isset($interests[$choice])) {
                continue;
            }

            $this->server->logAction($state['username'] ?? 'unknown', 'Interests: viewed "' . ($interests[$choice]['name'] ?? '') . '"');
            $this->showDetail($conn, $state, $session, $interests[$choice], $shell);
        }
    }

    // ── Detail screen ─────────────────────────────────────────────────────────

    private function showDetail($conn, array &$state, string $session, array $interest, TerminalShellInterface $shell): void
    {
        $locale = $state['locale'];
        $id     = (int)($interest['id'] ?? 0);
        $userId = (int)($state['user_id'] ?? 0);

        while (true) {
            // Refresh subscription status on each loop
            $fresh = $this->fetchInterest($userId, $id);
            if ($fresh === null) {
                return;
            }

            $subscribed = !empty($fresh['subscribed']);
            $resp  = TelnetUtils::apiRequest($this->apiBase, 'GET', "/api/interests/{$id}/echoareas", null, $session);
            $areas = $resp['data']['echoareas'] ?? [];
            $detailLines = $this->buildDetailLines($fresh, $subscribed, $areas, $locale);
            $shell->renderPanel($conn, $state, (string)($fresh['name'] ?? ''), $detailLines);
            $actionPrompt = $subscribed
                ? $this->server->t('ui.terminalserver.interests.current_status_subscribed', 'Current status: Subscribed', [], $locale)
                : $this->server->t('ui.terminalserver.interests.current_status_unsubscribed', 'Current status: Not subscribed', [], $locale);
            $allowedKeys = $subscribed ? ['u', 'q'] : ['s', 'q'];

            $choice = $shell->promptKey(
                $conn,
                $state,
                (string)($fresh['name'] ?? ''),
                $actionPrompt,
                $allowedKeys,
                [
                    'redraw_fn' => function () use ($conn, &$state, $shell, $fresh, $subscribed, $areas, $locale): void {
                        $shell->renderPanel($conn, $state, (string)($fresh['name'] ?? ''), $this->buildDetailLines($fresh, $subscribed, $areas, $locale));
                    },
                    'labels' => $subscribed
                        ? ['u' => $this->server->t('ui.terminalserver.interests.action_unsubscribe_label', 'Unsubscribe', [], $locale), 'q' => $this->server->t('ui.terminalserver.interests.action_quit_label', 'Back', [], $locale)]
                        : ['s' => $this->server->t('ui.terminalserver.interests.action_subscribe_label', 'Subscribe', [], $locale), 'q' => $this->server->t('ui.terminalserver.interests.action_quit_label', 'Back', [], $locale)],
                    'default' => 'q',
                ]
            );
            if ($choice === null) {
                return;
            }

            if ($choice === 'q' || $choice === '') {
                return;
            } elseif ($choice === 's' && !$subscribed) {
                $this->subscribe($conn, $state, $session, $fresh, $shell);
            } elseif ($choice === 'u' && $subscribed) {
                $this->unsubscribe($conn, $state, $session, $fresh, $shell);
            }
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    private function subscribe($conn, array &$state, string $session, array $interest, TerminalShellInterface $shell): void
    {
        $locale = $state['locale'];
        $id     = (int)($interest['id'] ?? 0);

        $resp = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', "/api/interests/{$id}/subscribe",
            [], $session, 3, $state['csrf_token'] ?? null
        );

        if (($resp['data']['success'] ?? false) === true) {
            $this->server->logAction($state['username'] ?? 'unknown', 'Interests: subscribed to "' . ($interest['name'] ?? '') . '"');
            $shell->showAlert(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.interests.title', 'Interests', [], $locale),
                $this->server->t('ui.terminalserver.interests.subscribe_ok', 'Subscribed successfully.', [], $locale),
                'info'
            );
        } else {
            $shell->showAlert(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.interests.title', 'Interests', [], $locale),
                $this->server->t('ui.terminalserver.interests.subscribe_failed', 'Failed to subscribe.', [], $locale),
                'error'
            );
        }
    }

    private function unsubscribe($conn, array &$state, string $session, array $interest, TerminalShellInterface $shell): void
    {
        $locale = $state['locale'];
        $id     = (int)($interest['id'] ?? 0);

        $resp = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', "/api/interests/{$id}/unsubscribe",
            [], $session, 3, $state['csrf_token'] ?? null
        );

        if (($resp['data']['success'] ?? false) === true) {
            $this->server->logAction($state['username'] ?? 'unknown', 'Interests: unsubscribed from "' . ($interest['name'] ?? '') . '"');
            $shell->showAlert(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.interests.title', 'Interests', [], $locale),
                $this->server->t('ui.terminalserver.interests.unsubscribe_ok', 'Unsubscribed successfully.', [], $locale),
                'info'
            );
        } else {
            $shell->showAlert(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.interests.title', 'Interests', [], $locale),
                $this->server->t('ui.terminalserver.interests.unsubscribe_failed', 'Failed to unsubscribe.', [], $locale),
                'error'
            );
        }
    }

    private function renderDetailScreen($conn, array &$state, array $fresh, bool $subscribed, array $areas, string $locale): void
    {
        $shell = TerminalShellFactory::create($this->server, $state);
        $shell->renderPanel($conn, $state, (string)($fresh['name'] ?? ''), $this->buildDetailLines($fresh, $subscribed, $areas, $locale));
    }

    /**
     * @param array<int, array<string, mixed>> $areas
     * @return string[]
     */
    private function buildDetailLines(array $fresh, bool $subscribed, array $areas, string $locale): array
    {
        $lines = [];

        if (!empty($fresh['description'])) {
            $lines[] = (string)$fresh['description'];
            $lines[] = '';
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
        $lines[] = $this->server->t(
            'ui.terminalserver.interests.status_label',
            'Status: {status}',
            ['status' => ''],
            $locale
        ) . $statusLabel;
        $lines[] = '';

        if (!empty($areas)) {
            $lines[] = $this->server->t('ui.terminalserver.interests.areas_heading', 'Echo Areas:', [], $locale);
            foreach ($areas as $area) {
                $tag  = strtoupper((string)($area['tag'] ?? ''));
                $desc = (string)($area['description'] ?? '');
                $lines[] = sprintf('  %-20s %s', $tag, $desc);
            }
        }

        return $lines;
    }

    // ── DB helpers ────────────────────────────────────────────────────────────

    /**
     * Fetch all active interests with the `subscribed` flag for the given user.
     * Queries the database directly to avoid HTTP cookie-auth fragility.
     */
    private function fetchInterests(int $userId): array
    {
        $manager       = new \BinktermPHP\InterestManager();
        $interests     = $manager->getInterests(true);
        $subscribedIds = $userId > 0
            ? array_flip($manager->getUserSubscribedInterestIds($userId))
            : [];
        foreach ($interests as &$i) {
            $i['subscribed'] = isset($subscribedIds[(int)$i['id']]);
        }
        unset($i);
        return $interests;
    }

    /**
     * Return a single interest by ID with the `subscribed` flag set for $userId.
     */
    private function fetchInterest(int $userId, int $id): ?array
    {
        foreach ($this->fetchInterests($userId) as $interest) {
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
        $userId    = (int)($state['user_id'] ?? 0);
        $interests = $this->fetchInterests($userId);
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
