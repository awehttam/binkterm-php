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

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct(bool $useUtcTimezone = true)
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

            if ($useUtcTimezone) {
                $this->pdo->exec("SET TIME ZONE 'UTC'");
            }
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    public static function getInstance(bool $useUtcTimezone = true)
    {
        if (self::$instance === null) {
            self::$instance = new self($useUtcTimezone);
        }
        return self::$instance;
    }

    /**
     * Force a reconnect by resetting the singleton.
     */
    public static function reconnect(bool $useUtcTimezone = true): self
    {
        self::$instance = null;
        return self::getInstance($useUtcTimezone);
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

