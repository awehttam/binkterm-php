<?php

namespace BinktermPHP;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class Template
{
    private $twig;
    private $auth;

    public function __construct()
    {
        // Set up filesystem loader with multiple template paths
        $templatePaths = [
            Config::TEMPLATE_PATH,  // Primary templates directory
            __DIR__ . '/../config'  // Config directory for custom templates
        ];
        
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
        } catch (\Exception $e) {
            // Fall back to static config if BinkP config fails
            $systemName = Config::SYSTEM_NAME;
            $sysopName = Config::SYSOP_NAME;
            $fidonetOrigin = Config::FIDONET_ORIGIN;
        }
        
        $this->twig->addGlobal('current_user', $currentUser);
        $this->twig->addGlobal('system_name', $systemName);
        $this->twig->addGlobal('sysop_name', $sysopName);
        $this->twig->addGlobal('fidonet_origin', $fidonetOrigin);
        $this->twig->addGlobal('system_address', $fidonetOrigin);
        
        // Add version information
        $this->twig->addGlobal('app_version', Version::getVersion());
        $this->twig->addGlobal('app_name', Version::getAppName());
        $this->twig->addGlobal('app_full_version', Version::getFullVersion());
        
        // Add terminal configuration
        $this->twig->addGlobal('terminal_enabled', Config::env('TERMINAL_ENABLED', 'false') === 'true');
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
        $systemNewsPath = __DIR__ . '/../config/systemnews.twig';
        
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
        $sysopName = $variables['sysop_name'] ?? 'Sysop';
        $appVersion = $variables['app_version'] ?? '1.0.0';
        
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
                    <p class="mb-2"><i class="fas fa-cog me-2 text-primary"></i>Running BinktermPHP v' . htmlspecialchars($appVersion) . '</p>
                    <p class="mb-0 small text-muted">
                        To customize this section, copy <code>config/systemnews.twig.example</code> to <code>config/systemnews.twig</code> and edit it.
                    </p>
                </div>
            </div>
        ';
    }
}