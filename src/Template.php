<?php

namespace Binktest;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class Template
{
    private $twig;
    private $auth;

    public function __construct()
    {
        $loader = new FilesystemLoader(Config::TEMPLATE_PATH);
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
            $binkpConfig = \Binktest\Binkp\Config\BinkpConfig::getInstance();
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
}