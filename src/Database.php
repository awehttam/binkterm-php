<?php

namespace BinktermPHP;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        try {
            $config = Config::getDatabaseConfig();
            
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'],
                $config['port'],
                $config['database']
            );
            
            // Add SSL configuration if enabled
            if (isset($config['ssl']) && $config['ssl']['enabled']) {
                $dsn .= ';sslmode=require';
                if (isset($config['ssl']['ca_cert'])) {
                    $dsn .= ';sslrootcert=' . $config['ssl']['ca_cert'];
                }
                if (isset($config['ssl']['client_cert'])) {
                    $dsn .= ';sslcert=' . $config['ssl']['client_cert'];
                }
                if (isset($config['ssl']['client_key'])) {
                    $dsn .= ';sslkey=' . $config['ssl']['client_key'];
                }
            }
            
            $this->pdo = new PDO(
                $dsn, 
                $config['username'], 
                $config['password'], 
                $config['options'] ?? []
            );
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Force a reconnect by resetting the singleton.
     */
    public static function reconnect(): self
    {
        self::$instance = null;
        return self::getInstance();
    }

    public function getPdo()
    {
        return $this->pdo;
    }

    private function initTables()
    {
        $sql = file_get_contents(__DIR__ . '/../database/postgresql_schema.sql');
        $this->pdo->exec($sql);
    }
}
