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

class Config
{
    private static $databaseConfig = null;
    private static $loaded = false;
    
    // Default constants
    const TEMPLATE_PATH = __DIR__ . '/../templates';
    const BINKD_INBOUND = __DIR__ . '/../data/inbound';
    const BINKD_OUTBOUND = __DIR__ . '/../data/outbound';
    const LOG_PATH = __DIR__ . '/../data/logs';
    
    const SESSION_LIFETIME = 86400 * 30; // 30 days

    const DEFAULT_STYLESHEET = '/css/style.css';

    const FIDONET_ORIGIN = '1:1/0';
    const SYSTEM_NAME = 'BinktermPHP System';
    const SYSOP_NAME = 'System Operator';
    
    /**
     * Load environment variables and configuration
     */
    private static function loadConfig()
    {
        if (self::$loaded) {
            return;
        }
        
        // Load .env file if it exists
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            self::loadEnvFile($envFile);
        }
        
        self::$loaded = true;
    }
    
    /**
     * Load environment variables from .env file
     */
    private static function loadEnvFile($path)
    {
        if (!is_readable($path)) {
            return;
        }
        
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }
        }
    }
    
    /**
     * Get database configuration
     */
    public static function getDatabaseConfig()
    {
        if (self::$databaseConfig !== null) {
            return self::$databaseConfig;
        }
        
        self::loadConfig();
        
        // Build configuration from environment variables with defaults
        self::$databaseConfig = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? '5432',
            'database' => $_ENV['DB_NAME'] ?? 'binktest',
            'username' => $_ENV['DB_USER'] ?? 'binktest',
            'password' => $_ENV['DB_PASS'] ?? 'binktest',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
            'ssl' => [
                'enabled' => ($_ENV['DB_SSL'] ?? 'false') === 'true',
                'ca_cert' => $_ENV['DB_SSL_CA'] ?? null,
                'client_cert' => $_ENV['DB_SSL_CERT'] ?? null,
                'client_key' => $_ENV['DB_SSL_KEY'] ?? null,
            ],
        ];
        
        return self::$databaseConfig;
    }
    
    /**
     * Get environment variable with fallback
     */
    public static function env($key, $default = null)
    {
        self::loadConfig();
        return $_ENV[$key] ?? $default;
    }
    
    /**
     * Get the full path to a log file
     * @param string $filename Log filename (e.g., 'binkp_server.log')
     * @return string Full absolute path to log file
     */
    public static function getLogPath($filename)
    {
        return self::LOG_PATH . '/' . $filename;
    }

    /**
     * Get the configured stylesheet path
     * @return string Path to the stylesheet
     */
    public static function getStylesheet()
    {
        return self::env('STYLESHEET', self::DEFAULT_STYLESHEET);
    }

    /**
     * Cache for loaded themes
     */
    private static $themes = null;

    /**
     * Get available themes from config file
     * @return array Theme name => stylesheet path mapping
     */
    public static function getThemes(): array
    {
        if (self::$themes !== null) {
            return self::$themes;
        }

        $themesFile = __DIR__ . '/../config/themes.json';

        if (file_exists($themesFile)) {
            $content = file_get_contents($themesFile);
            $themes = json_decode($content, true);

            if (is_array($themes) && !empty($themes)) {
                self::$themes = $themes;
                return self::$themes;
            }
        }

        // Default themes if file missing or invalid
        self::$themes = [
            'Regular' => '/css/style.css',
            'Dark' => '/css/dark.css'
        ];

        return self::$themes;
    }

    /**
     * Get the base site URL (without trailing slash)
     *
     * Uses SITE_URL environment variable first (important for apps behind HTTPS proxies),
     * falls back to protocol detection from $_SERVER variables.
     *
     * @return string Base site URL (e.g., "https://example.com" or "http://localhost:8080")
     */
    public static function getSiteUrl(): string
    {
        $siteUrl = self::env('SITE_URL');

        if ($siteUrl) {
            // Use configured SITE_URL (handles proxies correctly)
            return rtrim($siteUrl, '/');
        }

        // Fallback to protocol detection method if SITE_URL not configured
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host;
    }
}
