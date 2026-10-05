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

use BinktermPHP\DatabasePlatform\DatabasePlatformFactory;
use BinktermPHP\DatabasePlatform\DatabasePlatformInterface;
use PDO;
use PDOException;

class Database
{
    /**
     * TEST-ONLY: the sole database name setInstanceForTesting() will ever
     * accept. Duplicated (not shared) from tests/Unit/Support/TestDatabase.php
     * on purpose -- this guard must not trust that helper's own check, so it
     * cannot be bypassed by a caller that skips it.
     */
    private const TEST_ONLY_DATABASE_NAME = 'binktermphp_test';

    private static $instance = null;
    private $pdo;
    private DatabasePlatformInterface $platform;

    private function __construct(bool $useUtcTimezone = true, ?PDO $injectedPdo = null)
    {
        if ($injectedPdo !== null) {
            // TEST-ONLY path: the caller (setInstanceForTesting()) has already
            // fail-closed verified this PDO is connected to the isolated test
            // database. Never touch Config::getDatabaseConfig() or open a
            // second connection here.
            $this->pdo = $injectedPdo;
            $this->platform = self::getPlatform(['driver' => 'pgsql']);
            $this->platform->initializeSession($this->pdo, $useUtcTimezone);

            return;
        }

        try {
            $config = Config::getDatabaseConfig();
            $this->platform = self::getPlatform($config);

            $this->pdo = new PDO(
                $this->platform->createDsn($config),
                $config['username'],
                $config['password'],
                $config['options'] ?? []
            );

            $this->platform->initializeSession($this->pdo, $useUtcTimezone);
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

    /**
     * TEST-ONLY: redirect the singleton at an already-open PDO connection so
     * that every Database::getInstance() call in the process -- whether made
     * directly by a test or internally by production code under test --
     * resolves to the same connection.
     *
     * Independently fail-closed: this does NOT trust that the caller (e.g.
     * tests/Unit/Support/TestDatabase.php) already verified the target
     * database. It re-checks current_database() itself and refuses to
     * install the instance -- self::$instance is left untouched -- unless it
     * is exactly the isolated test database. This is the only database name
     * this method will ever accept; it can never be pointed at production.
     *
     * Never call this outside a test context.
     */
    public static function setInstanceForTesting(PDO $pdo, bool $useUtcTimezone = true): void
    {
        $actual = (string)$pdo->query('SELECT current_database()')->fetchColumn();
        if ($actual !== self::TEST_ONLY_DATABASE_NAME) {
            throw new \RuntimeException(
                'Database::setInstanceForTesting() refuses a PDO connected to '
                . "\"{$actual}\" -- only \"" . self::TEST_ONLY_DATABASE_NAME . '" is accepted.'
            );
        }

        self::$instance = new self($useUtcTimezone, $pdo);
    }

    /**
     * TEST-ONLY: drop the singleton without opening any connection. The next
     * getInstance() call after this reverts to normal production behavior --
     * callers must not call getInstance() again in a test context after
     * calling this unless they intend to reconnect to production.
     */
    public static function resetInstanceForTesting(): void
    {
        self::$instance = null;
    }

    /**
     * Resolve the configured database platform implementation.
     *
     * @param array<string, mixed>|null $config
     */
    public static function getPlatform(?array $config = null): DatabasePlatformInterface
    {
        return DatabasePlatformFactory::create($config ?? Config::getDatabaseConfig());
    }

    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * Return the active database platform.
     */
    public function getPlatformInstance(): DatabasePlatformInterface
    {
        return $this->platform;
    }

    private function initTables()
    {
        $sql = file_get_contents($this->platform->getBaseSchemaPath(dirname(__DIR__)));
        $this->pdo->exec($sql);
    }
}

