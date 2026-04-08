<?php

namespace BinktermPHP;

/**
 * Defines the set of cards available on the user dashboard, their default placement,
 * and access constraints (admin-only, feature gates, conditional flags).
 */
class DashboardCardRegistry
{
    /**
     * Full card catalogue.
     *
     * Keys:
     *   label_key    — i18n key used in the customize modal
     *   default_zone — 'main' (wide column) or 'sidebar' (narrow column)
     *   required     — if true the card cannot be hidden
     *   feature      — BbsConfig feature flag that must be enabled
     *   admin_only   — if true, only visible to admins
     *   conditional  — name of a boolean condition passed via $conditions
     */
    public static function getAllCards(): array
    {
        return [
            'unread'         => ['label_key' => 'ui.dashboard.card.unread',         'default_zone' => 'main',    'required' => true],
            'system_news'    => ['label_key' => 'ui.dashboard.card.system_news',    'default_zone' => 'main',    'required' => false],
            'shoutbox'       => ['label_key' => 'ui.dashboard.card.shoutbox',       'default_zone' => 'main',    'required' => false, 'feature' => 'shoutbox'],
            'advertising'    => ['label_key' => 'ui.dashboard.card.advertising',    'default_zone' => 'main',    'required' => false, 'feature' => 'advertising'],
            'system_info'    => ['label_key' => 'ui.dashboard.card.system_info',    'default_zone' => 'sidebar', 'required' => false],
            'todays_callers' => ['label_key' => 'ui.dashboard.card.todays_callers', 'default_zone' => 'sidebar', 'required' => false, 'admin_only' => true],
            'voting_booth'   => ['label_key' => 'ui.dashboard.card.voting_booth',   'default_zone' => 'sidebar', 'required' => false, 'feature' => 'voting_booth'],
            'echo_areas'     => ['label_key' => 'ui.dashboard.card.echo_areas',     'default_zone' => 'sidebar', 'required' => false],
            'referral'       => ['label_key' => 'ui.dashboard.card.referral',       'default_zone' => 'sidebar', 'required' => false, 'conditional' => 'referral_enabled'],
        ];
    }

    /**
     * Returns cards available to this user after applying feature/admin gates.
     *
     * @param array $user       Authenticated user row
     * @param array $conditions Map of condition name => bool (e.g. ['referral_enabled' => true])
     * @return array            Subset of getAllCards(), keyed by card id
     */
    public static function getAvailableCards(array $user, array $conditions = []): array
    {
        $result = [];
        foreach (self::getAllCards() as $id => $card) {
            if (!empty($card['admin_only']) && empty($user['is_admin'])) {
                continue;
            }
            if (!empty($card['feature']) && !BbsConfig::isFeatureEnabled($card['feature'])) {
                continue;
            }
            if (!empty($card['conditional']) && empty($conditions[$card['conditional']])) {
                continue;
            }
            $result[$id] = $card;
        }
        return $result;
    }

    /**
     * Builds the default layout for a given set of available cards.
     *
     * If the sysop has configured a default layout via the admin Appearance panel,
     * that layout is used as the base (filtered to cards available to this user).
     * Otherwise the built-in per-card default_zone values are used.
     */
    public static function getDefaultLayout(array $availableCards): array
    {
        $sysopLayout = AppearanceConfig::getDefaultDashboardLayout();
        if ($sysopLayout !== null) {
            return self::mergeLayout($sysopLayout, $availableCards);
        }

        $main = [];
        $sidebar = [];
        foreach ($availableCards as $id => $card) {
            if ($card['default_zone'] === 'main') {
                $main[] = $id;
            } else {
                $sidebar[] = $id;
            }
        }
        return ['main' => $main, 'sidebar' => $sidebar, 'hidden' => []];
    }

    /**
     * Merges a user's saved layout with the current available card set.
     *
     * - Cards no longer available are dropped.
     * - Newly available cards not yet in the saved layout are appended to their default zone.
     */
    public static function mergeLayout(array $saved, array $availableCards): array
    {
        $allIds = array_keys($availableCards);

        $mainIds    = array_values(array_filter($saved['main']    ?? [], fn($id) => in_array($id, $allIds, true)));
        $sidebarIds = array_values(array_filter($saved['sidebar'] ?? [], fn($id) => in_array($id, $allIds, true)));
        $hiddenIds  = array_values(array_filter($saved['hidden']  ?? [], fn($id) => in_array($id, $allIds, true)));

        $placed = array_merge($mainIds, $sidebarIds);
        foreach ($allIds as $id) {
            if (!in_array($id, $placed, true)) {
                if ($availableCards[$id]['default_zone'] === 'main') {
                    $mainIds[] = $id;
                } else {
                    $sidebarIds[] = $id;
                }
            }
        }

        return [
            'main'    => $mainIds,
            'sidebar' => $sidebarIds,
            'hidden'  => $hiddenIds,
        ];
    }

    /**
     * Validates a layout payload from user input.
     * Returns the sanitized layout array or null if invalid.
     *
     * @param mixed $payload       Decoded JSON from request body
     * @param array $availableCards Result of getAvailableCards()
     */
    public static function validateLayout($payload, array $availableCards): ?array
    {
        if (!is_array($payload)) {
            return null;
        }
        if (!isset($payload['main'], $payload['sidebar'])) {
            return null;
        }

        $allIds = array_keys($availableCards);

        $main    = array_values(array_filter((array)$payload['main'],    fn($id) => is_string($id) && in_array($id, $allIds, true)));
        $sidebar = array_values(array_filter((array)$payload['sidebar'], fn($id) => is_string($id) && in_array($id, $allIds, true)));
        $hidden  = array_values(array_filter((array)($payload['hidden'] ?? []), fn($id) => is_string($id) && in_array($id, $allIds, true)));

        // Remove required cards from hidden list
        foreach ($availableCards as $id => $card) {
            if (!empty($card['required'])) {
                $hidden = array_values(array_filter($hidden, fn($h) => $h !== $id));
            }
        }

        // Every available card must appear in exactly one zone
        $placed = array_merge($main, $sidebar);
        foreach ($allIds as $id) {
            if (!in_array($id, $placed, true)) {
                if ($availableCards[$id]['default_zone'] === 'main') {
                    $main[] = $id;
                } else {
                    $sidebar[] = $id;
                }
            }
        }

        return ['main' => $main, 'sidebar' => $sidebar, 'hidden' => $hidden];
    }
}
