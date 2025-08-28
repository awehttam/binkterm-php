<?php

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
}