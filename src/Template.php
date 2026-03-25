<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


namespace BinktermPHP;

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\AppearanceConfig;
use BinktermPHP\BbsConfig;
use BinktermPHP\I18n\LocaleResolver;
use BinktermPHP\I18n\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class Template
{
    private $twig;
    private $auth;
    private Translator $translator;
    private LocaleResolver $localeResolver;
    private string $activeShell = 'web';

    public function __construct()
    {
        $this->auth = new Auth();
        $this->translator = new Translator();
        $this->localeResolver = new LocaleResolver($this->translator);
        $currentUser = $this->auth->getCurrentUser() ?: null;

        $this->activeShell = $this->resolveActiveShell($currentUser);

        // Set up filesystem loader with multiple template paths.
        // Order matters: first path found wins, allowing custom overrides.
        $templatePaths = [
            Config::TEMPLATE_PATH . '/custom',                        // Custom overrides (highest priority)
            Config::TEMPLATE_PATH . '/shells/' . $this->activeShell,  // Active shell chrome
            Config::TEMPLATE_PATH,                                     // Original templates (never modified)
        ];

        // Filter out non-existent paths to avoid Twig warnings
        $templatePaths = array_values(array_filter($templatePaths, 'is_dir'));

        $loader = new FilesystemLoader($templatePaths);
        $this->twig = new Environment($loader, [
            'cache' => false, // Disable for development
            'debug' => true
        ]);

        $this->addGlobalVariables($currentUser);
    }

    /**
     * Determine which shell to use for the current request.
     * Priority: user preference (when not locked) → sysop default → 'web'.
     */
    private function resolveActiveShell(?array $currentUser): string
    {
        if (!AppearanceConfig::isShellLocked() && $currentUser) {
            try {
                $userId = (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0);
                if ($userId > 0) {
                    $meta = new UserMeta();
                    $userShell = $meta->getValue($userId, 'shell');
                    if ($userShell && in_array($userShell, ['web', 'bbs-menu'], true)) {
                        return $userShell;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to sysop default
            }
        }

        return AppearanceConfig::getActiveShell();
    }

    private function addGlobalVariables(?array $currentUser = null)
    {
        if ($currentUser === null) {
            $currentUser = $this->auth->getCurrentUser() ?: null;
        }

        $currentUserId = (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0);
        $userSettings = null;
        if ($currentUserId > 0) {
            try {
                $handler = new MessageHandler();
                $userSettings = $handler->getUserSettings($currentUserId);
            } catch (\Throwable $e) {
                $userSettings = null;
            }
        }

        $preferredLocale = is_array($userSettings) ? (string)($userSettings['locale'] ?? '') : '';

        // ?locale= query param overrides everything else for this request.
        $queryLocale = isset($_GET['locale']) ? trim((string)$_GET['locale']) : '';
        $explicitLocale = $queryLocale !== '' ? $queryLocale : ($preferredLocale !== '' ? $preferredLocale : null);

        $locale = $this->localeResolver->resolveLocale($explicitLocale, $currentUser);
        $this->localeResolver->persistLocale($locale);

        // Get dynamic system info from BinkP config
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            //$systemName = $binkpConfig->getSystemSysop() . "'s System";
            $systemName = $binkpConfig->getSystemName();
            $sysopName = $binkpConfig->getSystemSysop();
            $fidonetOrigin = $binkpConfig->getSystemAddress();
            $networkAddresses = $binkpConfig->getMyAddressesWithDomains();
        } catch (\Exception $e) {
            // Fall back to static config if BinkP config fails
            $systemName = Config::SYSTEM_NAME;
            $sysopName = Config::SYSOP_NAME;
            $fidonetOrigin = Config::FIDONET_ORIGIN;
            $networkAddresses = [];
        }

        $favicon_svg = Config::env('FAVICONSVG') ?? "/favicon.svg";
        $favicon_ico = Config::env('FAVICONICO') ?? "/favicon.ico";
        $favicon_png = Config::env('FAVICONPNG') ?? "/favicon.png";

        $this->twig->addGlobal("favicon_svg", $favicon_svg);
        $this->twig->addGlobal("favicon_ico", $favicon_ico);
        $this->twig->addGlobal("favicon_png", $favicon_png);
        $this->twig->addGlobal('locale', $locale);
        $this->twig->addGlobal('default_locale', $this->translator->getDefaultLocale());
        $this->twig->addGlobal('supported_locales', $this->translator->getSupportedLocales());
        $this->twig->addGlobal('i18n_namespaces', ['common', 'errors']);

        // CSRF token — stored per-user in UserMeta so it is shared across web
        // sessions and the telnet daemon.  Generated lazily for users who were
        // already logged in before this feature was deployed.
        $csrfToken = '';
        if ($currentUser) {
            $userId = (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0);
            if ($userId > 0) {
                try {
                    $meta      = new UserMeta();
                    $csrfToken = $meta->getValue($userId, 'csrf_token') ?? '';
                    if ($csrfToken === '') {
                        $csrfToken = bin2hex(random_bytes(32));
                        $meta->setValue($userId, 'csrf_token', $csrfToken);
                    }
                } catch (\Throwable $e) {
                    // Non-fatal: page renders without CSRF; next POST will 403
                }
            }
        }
        $this->twig->addGlobal('csrf_token', $csrfToken);

        if (is_array($currentUser)) {
            unset($currentUser['password_hash']);
        }
        $this->twig->addGlobal('current_user', $currentUser);
        $this->twig->addGlobal('system_name', $systemName);
        $this->twig->addGlobal('sysop_name', $sysopName);
        $this->twig->addGlobal('fidonet_origin', $fidonetOrigin);
        $this->twig->addGlobal('system_address', $fidonetOrigin);
        $this->twig->addGlobal('network_addresses', $networkAddresses);

        // Add version information
        $this->twig->addGlobal('app_version', Version::getVersion());
        $this->twig->addGlobal('app_name', Version::getAppName());
        $this->twig->addGlobal('app_full_version', Version::getFullVersion());
        $this->twig->addGlobal('app_base_dir', dirname(__DIR__));

        // Expose whether an upgrade notes page exists for the current version
        $upgradingFile = __DIR__ . '/../docs/UPGRADING_' . Version::getVersion() . '.md';
        $this->twig->addGlobal('has_upgrading_doc', file_exists($upgradingFile));

        // Add terminal configuration
        $this->twig->addGlobal('terminal_enabled', Config::env('TERMINAL_ENABLED', 'false') === 'true');

        // Is the game system enabled
        $this->twig->addGlobal('webdoors_active', GameConfig::isGameSystemEnabled());
        $this->twig->addGlobal('mrc_webdoor_enabled', GameConfig::isEnabled('mrc'));

        $this->twig->addGlobal('bbs_directory_enabled', BbsConfig::isFeatureEnabled('bbs_directory'));
        $this->twig->addGlobal('site_url', Config::getSiteUrl());
        $this->twig->addGlobal('freq_experimental_enabled', Config::env('ENABLE_FREQ_EXPERIMENTAL', 'false') === 'true');
        $interestsEnabled = Config::env('ENABLE_INTERESTS', 'true') === 'true';
        $this->twig->addGlobal('interests_enabled', $interestsEnabled);
        $hasActiveInterests = false;
        if ($interestsEnabled) {
            $im = new InterestManager();
            $hasActiveInterests = count($im->getInterests(true)) > 0;
        }
        $this->twig->addGlobal('has_active_interests', $hasActiveInterests);
        $this->twig->addGlobal('is_dev', Config::env('IS_DEV', 'false') === 'true');
        $this->twig->addGlobal('debug_ansi_not_perfect', Config::env('DEBUG_ANSI_NOT_PERFECT', 'false') === 'true');
        $this->twig->addGlobal('debug_ansi_use_consolas', Config::env('DEBUG_ANSI_USE_CONSOLAS', 'false') === 'true');
        $configuredSseTransportMode = strtolower(trim((string)Config::env('SSE_TRANSPORT_MODE', 'auto')));
        if (!in_array($configuredSseTransportMode, ['auto', 'sse'], true)) {
            $configuredSseTransportMode = 'auto';
        }
        $this->twig->addGlobal('configured_sse_transport_mode', $configuredSseTransportMode);
        $this->twig->addGlobal('effective_sse_transport_mode', $configuredSseTransportMode);
        // ANSI_RENDERER_MODE: 'grouped' (default, merges same-styled chars into one span,
        // enables URL hyperlinking) or 'perchar' (one span per character, original behavior).
        $ansiRendererMode = Config::env('ANSI_RENDERER_MODE', 'grouped');
        $this->twig->addGlobal('ansi_renderer_mode', in_array($ansiRendererMode, ['grouped', 'perchar'], true) ? $ansiRendererMode : 'grouped');

        $creditsConfig = BbsConfig::getConfig()['credits'] ?? [];
        $creditsEnabled = !empty($creditsConfig['enabled']);
        $creditsSymbol = trim((string)($creditsConfig['symbol'] ?? '$'));
        $referralEnabled = $creditsEnabled && !empty($creditsConfig['referral_enabled']);
        $creditBalance = 0;
        if ($currentUser && $creditsEnabled) {
            try {
                $creditBalance = UserCredit::getBalance((int)($currentUser['user_id'] ?? $currentUser['id']));
            } catch (\Throwable $e) {
                $creditBalance = 0;
            }
        }
        $this->twig->addGlobal('credits_enabled', $creditsEnabled);
        $this->twig->addGlobal('credits_symbol', $creditsSymbol);
        $this->twig->addGlobal('credit_balance', $creditBalance);
        $this->twig->addGlobal('referral_enabled', $referralEnabled);

        // License state — verified once per request, cached in memory.
        // Failure is always safe; community tier is the fallback.
        try {
            $licenseStatus = License::getStatus();
        } catch (\Throwable $e) {
            $licenseStatus = ['valid' => false, 'tier' => 'community', 'reason' => 'error', 'features' => []];
        }
        $this->twig->addGlobal('license_valid', (bool)($licenseStatus['valid'] ?? false));
        $this->twig->addGlobal('license_tier', (string)($licenseStatus['tier'] ?? 'community'));
        $this->twig->addGlobal('supported_charsets', \BinktermPHP\Binkp\Config\BinkpConfig::getSupportedCharsets());
        $this->twig->addGlobal('license_licensee', $licenseStatus['licensee'] ?? null);
        $this->twig->addGlobal('license_system_name', $licenseStatus['system_name'] ?? null);
        $this->twig->addGlobal('license_features', (array)($licenseStatus['features'] ?? []));

        // Add available themes
        $availableThemes = Config::getThemes();
        $this->twig->addGlobal('available_themes', $availableThemes);

        // Appearance configuration
        $appearanceConfig = AppearanceConfig::getConfig();
        $bbsMenuItems = $this->buildBbsMenuItems($appearanceConfig, $locale);

        // Resolve announcement active state (Twig has no datetime comparison)
        $ann = $appearanceConfig['content']['announcement'] ?? [];
        $annEnabled = !empty($ann['enabled']);
        $annExpiresAt = $ann['expires_at'] ?? null;
        $annActive = $annEnabled;
        if ($annActive && $annExpiresAt) {
            try {
                $annActive = new \DateTime($annExpiresAt) > new \DateTime();
            } catch (\Throwable $e) {
                $annActive = false;
            }
        }
        $ann['_active'] = $annActive;
        $ann['_key'] = substr(md5($ann['text'] ?? ''), 0, 8);
        $appearanceConfig['content']['announcement'] = $ann;

        // Pre-render house rules if data file exists
        $houseRulesMd = AppearanceConfig::getHouseRulesMarkdown();
        $houseRulesHtml = $houseRulesMd !== null ? MarkdownRenderer::toHtml($houseRulesMd) : null;

        $this->twig->addGlobal('appearance', $appearanceConfig);
        $this->twig->addGlobal('bbs_menu_items', $bbsMenuItems);
        $this->twig->addGlobal('appearance_houserules_html', $houseRulesHtml);
        $this->twig->addGlobal('active_shell', $this->activeShell);

        // Determine stylesheet to use - check user's theme preference if logged in
        // When theme is locked by sysop, always use the sysop's default theme.
        $defaultTheme = AppearanceConfig::getDefaultTheme();
        $stylesheet = ($defaultTheme !== '' && in_array($defaultTheme, $availableThemes, true))
            ? $defaultTheme
            : Config::getStylesheet();

        // Get BBS-wide default echo interface (default to 'echolist')
        $bbsConfig = BbsConfig::getConfig();
        $systemDefaultEchoInterface = $bbsConfig['default_echo_interface'] ?? 'echolist';
        $defaultEchoList = $systemDefaultEchoInterface;

        if ($currentUserId > 0) {
            try {
                $settings = is_array($userSettings) ? $userSettings : [];
                if (!empty($settings['theme']) && !AppearanceConfig::isThemeLocked()) {
                    // Validate that the user's theme is in the available themes
                    if (in_array($settings['theme'], $availableThemes, true)) {
                        $stylesheet = $settings['theme'];
                    }
                }
                // Get default echo list preference
                if (!empty($settings['default_echo_list'])) {
                    // If user chose 'system_choice', use BBS-wide default
                    // Otherwise, use their explicit choice
                    if ($settings['default_echo_list'] === 'system_choice') {
                        $defaultEchoList = $systemDefaultEchoInterface;
                    } else {
                        $defaultEchoList = $settings['default_echo_list'];
                    }
                }
            } catch (\Exception $e) {
                // Fall back to defaults on error
            }
        }
        $this->twig->addGlobal('stylesheet', $stylesheet);
        $this->twig->addGlobal('default_echo_list', $defaultEchoList);

        $this->twig->addFunction(new TwigFunction('bbs_feature_enabled', function(string $feature): bool {
            return BbsConfig::isFeatureEnabled($feature);
        }));
        $this->twig->addFunction(new TwigFunction('t', function(string $key, array $params = [], ...$args) use ($locale): string {
            $resolvedLocale = $locale;
            $namespaces = ['common'];
            $fallback = null;

            if (isset($args[0])) {
                $third = $args[0];
                if (is_string($third) && $third !== '') {
                    $looksLikeLocale = (bool)preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $third);
                    if ($this->translator->isSupportedLocale($third) || $looksLikeLocale) {
                        $resolvedLocale = $third;
                    } elseif ((bool)preg_match('/^[a-z0-9_]+$/i', $third)) {
                        // Legacy namespace style: t(key, params, 'errors')
                        if ($third !== 'common') {
                            array_unshift($namespaces, $third);
                        }
                    } elseif ($third !== 'common') {
                        // Legacy fallback style: t(key, params, 'English fallback')
                        $fallback = $third;
                    }
                } elseif (is_array($third)) {
                    $namespaces = $third;
                }
            }

            if (isset($args[1]) && is_array($args[1])) {
                $namespaces = $args[1];
            }

            $translated = $this->translator->translate($key, $params, $resolvedLocale, $namespaces);
            if ($fallback !== null && $translated === $key) {
                return $fallback;
            }
            return $translated;
        }));
    }

    /**
     * Build the menu item list used by the BBS menu shell.
     * Feature pages are injected at render time so existing saved appearance
     * config remains unchanged.
     */
    private function buildBbsMenuItems(array $appearanceConfig, string $locale = ''): array
    {
        $items = $appearanceConfig['shell']['bbs_menu']['menu_items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $defaultLabelKeys = [
            '/echomail' => 'ui.admin.appearance.default_menu.messages',
            '/netmail' => 'ui.admin.appearance.default_menu.netmail',
            '/files' => 'ui.admin.appearance.default_menu.files',
            '/games' => 'ui.admin.appearance.default_menu.games_doors',
            '/settings' => 'ui.admin.appearance.default_menu.settings',
        ];

        $normalizedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = strtoupper(trim((string)($item['key'] ?? '')));
            $label = trim((string)($item['label'] ?? ''));
            $url = trim((string)($item['url'] ?? ''));
            $labelKey = trim((string)($item['label_key'] ?? ''));

            if ($key === '' || $label === '' || $url === '') {
                continue;
            }

            // Translate built-in menu labels per locale while preserving custom labels.
            $mappedKey = $labelKey !== '' ? $labelKey : ($defaultLabelKeys[$url] ?? '');
            if ($mappedKey !== '') {
                $defaultEnglish = $this->translator->translate($mappedKey, [], 'en');
                if ($labelKey !== '' || strcasecmp($label, $defaultEnglish) === 0) {
                    $label = $this->translator->translate($mappedKey, [], $locale ?: null);
                }
            }

            $normalizedItems[] = [
                'key' => $key,
                'label' => $label,
                'icon' => trim((string)($item['icon'] ?? 'circle')),
                'url' => $url,
            ];
        }

        if (BbsConfig::isFeatureEnabled('voting_booth')) {
            $normalizedItems = $this->appendBbsMenuFeatureItem(
                $normalizedItems,
                '/polls',
                $this->translator->translate('ui.polls.title', [], $locale ?: null),
                'vote-yea',
                ['P', 'O', 'L']
            );
        }

        if (BbsConfig::isFeatureEnabled('shoutbox')) {
            $normalizedItems = $this->appendBbsMenuFeatureItem(
                $normalizedItems,
                '/shoutbox',
                $this->translator->translate('ui.shoutbox.title', [], $locale ?: null),
                'bullhorn',
                ['H', 'U', 'B', 'X']
            );
        }

        return $normalizedItems;
    }

    private function appendBbsMenuFeatureItem(array $items, string $url, string $label, string $icon, array $preferredKeys): array
    {
        foreach ($items as $item) {
            if (($item['url'] ?? '') === $url) {
                return $items;
            }
        }

        $items[] = [
            'key' => $this->selectBbsMenuKey($items, $preferredKeys),
            'label' => $label,
            'icon' => $icon,
            'url' => $url,
        ];

        return $items;
    }

    private function selectBbsMenuKey(array $items, array $preferredKeys): string
    {
        $usedKeys = [];
        foreach ($items as $item) {
            $key = strtoupper((string)($item['key'] ?? ''));
            if ($key !== '') {
                $usedKeys[$key] = true;
            }
        }

        foreach ($preferredKeys as $key) {
            $candidate = strtoupper((string)$key);
            if ($candidate !== '' && !isset($usedKeys[$candidate])) {
                return $candidate;
            }
        }

        foreach (range('A', 'Z') as $candidate) {
            if (!isset($usedKeys[$candidate])) {
                return $candidate;
            }
        }

        return '1';
    }

    public function render($template, $variables = [])
    {
        return $this->twig->render($template, $variables);
    }

    public function renderResponse($template, $variables = [])
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->render($template, $variables);
    }
    
    /**
     * Render system news: checks data/systemnews.md first, then
     * templates/custom/systemnews.twig, then built-in default.
     */
    public function renderSystemNews($variables = [])
    {
        // Priority 1: Markdown file managed via admin UI
        $systemNewsMd = AppearanceConfig::getSystemNewsMarkdown();
        if ($systemNewsMd !== null && trim($systemNewsMd) !== '') {
            return MarkdownRenderer::toHtml($systemNewsMd);
        }

        // Priority 2: Custom Twig template (backward compat)
        $systemNewsPath = Config::TEMPLATE_PATH . '/custom/systemnews.twig';
        if (file_exists($systemNewsPath)) {
            try {
                return $this->render('systemnews.twig', $variables);
            } catch (\Exception $e) {
                error_log("Failed to render systemnews.twig: " . $e->getMessage());
            }
        }

        // Fallback: built-in default
        return $this->getDefaultSystemNews($variables);
    }
    
    /**
     * Generate default system news content
     */
    private function getDefaultSystemNews($variables = [])
    {
        $binkcfg =  BinkpConfig::getInstance();
        $sysopName = $binkcfg->getSystemSysop();
        //$sysopName = $variables['sysop_name'] ?? 'Sysop';
        $appVersion = Version::getVersion();//$variables['app_version'] ?? '1.0.0';
        
        return '
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Welcome to ' . htmlspecialchars($sysopName) . '\'s BBS!</strong>
            </div>
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-newspaper me-2"></i>
                    System News
                </div>
                <div class="card-body">
                    <p class="mb-2"><i class="fas fa-cog me-2 text-primary"></i>     We are building a new bulletin board powered by BinktermPHP v' . htmlspecialchars($appVersion) . '</p>
                    <p class="mb-0 small text-muted">
                     
<P>
                        As a first step you may want to review <a href="/subscriptions">your echomail subscriptions</a>.
</P>
<P>
If you need a hand, reach out to '.$sysopName.'
</P>
<P>
                        <!-- Sysop: To customize this section, copy <code>templates/custom/systemnews.twig.example</code> to <code>templates/custom/systemnews.twig</code> and edit it. -->
                        </P>
                    </p>
                </div>
            </div>
        ';
    }
}

