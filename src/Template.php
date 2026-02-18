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
use BinktermPHP\BbsConfig;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class Template
{
    private $twig;
    private $auth;

    public function __construct()
    {
        // Set up filesystem loader with multiple template paths
        // Order matters: first path found wins, allowing custom overrides
        $templatePaths = [
            Config::TEMPLATE_PATH . '/custom',  // Custom overrides (checked first)
            Config::TEMPLATE_PATH,              // Primary templates directory
        ];

        // Filter out non-existent paths to avoid Twig warnings
        $templatePaths = array_filter($templatePaths, 'is_dir');

        $loader = new FilesystemLoader($templatePaths);
        $this->twig = new Environment($loader, [
            'cache' => false, // Disable for development
            'debug' => true
        ]);
        
        $this->auth = new Auth();
        $this->addGlobalVariables();
    }

    private function addGlobalVariables()
    {
        $currentUser = $this->auth->getCurrentUser();

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

        // Expose whether an upgrade notes page exists for the current version
        $upgradingFile = __DIR__ . '/../docs/UPGRADING_' . Version::getVersion() . '.md';
        $this->twig->addGlobal('has_upgrading_doc', file_exists($upgradingFile));

        // Add terminal configuration
        $this->twig->addGlobal('terminal_enabled', Config::env('TERMINAL_ENABLED', 'false') === 'true');

        // Is the game system enabled
        $this->twig->addGlobal('webdoors_active', GameConfig::isGameSystemEnabled());

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

        // Add available themes
        $availableThemes = Config::getThemes();
        $this->twig->addGlobal('available_themes', $availableThemes);

        // Determine stylesheet to use - check user's theme preference if logged in
        $stylesheet = Config::getStylesheet();

        // Get BBS-wide default echo interface (default to 'echolist')
        $bbsConfig = BbsConfig::getConfig();
        $systemDefaultEchoInterface = $bbsConfig['default_echo_interface'] ?? 'echolist';
        $defaultEchoList = $systemDefaultEchoInterface;

        if ($currentUser && !empty($currentUser['user_id'])) {
            try {
                $handler = new MessageHandler();
                $settings = $handler->getUserSettings($currentUser['user_id']);
                if (!empty($settings['theme'])) {
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
     * Render system news template if it exists, otherwise return default content
     */
    public function renderSystemNews($variables = [])
    {
        $systemNewsPath = Config::TEMPLATE_PATH . '/custom/systemnews.twig';

        if (file_exists($systemNewsPath)) {
            try {
                return $this->render('systemnews.twig', $variables);
            } catch (\Exception $e) {
                // Log error but don't break the page
                error_log("Failed to render systemnews.twig: " . $e->getMessage());
            }
        }

        // Fallback content if template doesn't exist or fails to render
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

