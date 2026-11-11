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
            // Not chained on purpose: the PDOException trace carries the DSN credentials.
            throw new DatabaseUnavailableException('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the shared connection, opening it on first use.
     *
     * Under a web SAPI a failed connection ends the request with a message, as
     * it always has. Under the CLI (daemons, cron scripts) a
     * DatabaseUnavailableException is thrown instead so long-running callers
     * can back off and retry rather than dying.
     *
     * @throws DatabaseUnavailableException CLI only
     */
    public static function getInstance(bool $useUtcTimezone = true)
    {
        if (self::$instance === null) {
            try {
                self::$instance = new self($useUtcTimezone);
            } catch (DatabaseUnavailableException $e) {
                if (PHP_SAPI === 'cli') {
                    throw $e;
                }
                die($e->getMessage());
            }
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
     * Reconnect without throwing. Returns the new PDO, or null while the
     * database is still unreachable (the singleton is left empty so the next
     * getInstance() tries again).
     */
    public static function tryReconnect(bool $useUtcTimezone = true): ?PDO
    {
        self::$instance = null;
        try {
            self::$instance = new self($useUtcTimezone);
        } catch (DatabaseUnavailableException $e) {
            return null;
        }
        return self::$instance->getPdo();
    }

    /**
     * Return a PDO known to be working, reconnecting first if the shared one
     * went stale (e.g. Postgres restarted under a long-lived process).
     *
     * Costs one `SELECT 1` round trip, so use it at the start of an operation
     * in a long-lived process, not in hot loops. Throws if the database is
     * still unreachable after the retries.
     *
     * @param array<int, int> $delays Seconds to wait before each reconnect attempt
     * @throws \Throwable The last connection error once retries are exhausted
     */
    public static function getLivePdo(array $delays = [0, 1]): PDO
    {
        return self::withRetry(static function (PDO $pdo): PDO {
            $pdo->query('SELECT 1');
            return $pdo;
        }, $delays);
    }

    /**
     * Try to recover from a swallowed database error.
     *
     * For catch blocks that log-and-continue (best-effort bookkeeping in
     * long-lived sessions): if $e is a lost-connection error, replace the
     * shared connection and return the fresh PDO, otherwise return null.
     * Does nothing and returns null for ordinary SQL or logic errors.
     */
    public static function heal(\Throwable $e): ?PDO
    {
        if (!self::isConnectionError($e)) {
            return null;
        }

        return self::tryReconnect();
    }

    /**
     * Whether a throwable means the database connection was lost or could not
     * be established (as opposed to a SQL or logic error).
     *
     * Matches SQLSTATE class 08 and the PostgreSQL shutdown/startup states
     * (57P01 admin_shutdown, 57P02 crash_shutdown, 57P03 cannot_connect_now),
     * plus the libpq message texts that pdo_pgsql surfaces under a generic
     * SQLSTATE when the socket drops mid-query.
     */
    public static function isConnectionError(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof DatabaseUnavailableException) {
                return true;
            }

            $state = $cur instanceof PDOException
                ? (string)($cur->errorInfo[0] ?? $cur->getCode())
                : '';
            if ($state !== '' && (str_starts_with($state, '08') || in_array($state, ['57P01', '57P02', '57P03'], true))) {
                return true;
            }

            if (preg_match(
                '/server closed the connection unexpectedly|no connection to the server|terminating connection|'
                . 'could not (connect to|receive data from|send data to) server|connection to server .* failed|'
                . 'SSL SYSCALL error|SSL connection has been closed|connection pointer is NULL|'
                . 'the database system is (starting up|shutting down|in recovery mode)|connection (refused|timed out|reset)/i',
                $cur->getMessage()
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run a database operation, reconnecting and retrying if the connection
     * was lost. Intended for long-running daemon loops.
     *
     * The callable receives the current PDO. If a transaction was open when the
     * connection dropped, the error is rethrown without retrying (the
     * transaction is gone, so replaying part of it would be wrong). Non
     * connection errors are always rethrown untouched. Blocks while backing off.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @param array<int, int> $delays Seconds to wait before each reconnect attempt; its length is the retry count
     * @param callable(PDO): void|null $onReconnect Called with the new PDO after each successful reconnect, so callers can rebuild objects that cached the old handle
     * @param callable(string): void|null $log Receives a one-line message per failure/recovery
     * @return T
     */
    public static function withRetry(callable $fn, array $delays = [1, 2, 5], ?callable $onReconnect = null, ?callable $log = null)
    {
        $attempt = 0;
        $pdo = null;

        while (true) {
            // Sampled before $fn runs: on a dead pdo_pgsql handle inTransaction()
            // reports true (libpq status UNKNOWN), so it cannot be asked afterwards.
            $outerTransaction = false;
            try {
                if ($pdo === null) {
                    $pdo = self::getInstance()->getPdo();
                }
                $outerTransaction = self::inTransactionSafe($pdo);
                return $fn($pdo);
            } catch (\Throwable $e) {
                if (!self::isConnectionError($e) || !isset($delays[$attempt]) || $outerTransaction) {
                    throw $e;
                }

                if ($log !== null) {
                    $log('Database connection error: ' . $e->getMessage() . " (retry " . ($attempt + 1) . '/' . count($delays) . ')');
                }
                sleep(max(0, (int)$delays[$attempt]));
                $attempt++;

                $pdo = self::tryReconnect();
                if ($pdo !== null) {
                    if ($log !== null) {
                        $log('Database connection re-established');
                    }
                    if ($onReconnect !== null) {
                        $onReconnect($pdo);
                    }
                }
            }
        }
    }

    private static function inTransactionSafe(PDO $pdo): bool
    {
        try {
            return $pdo->inTransaction();
        } catch (\Throwable $e) {
            return false;
        }
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
     * is exactly Config::getTestDatabaseName() (`DB_DEVNAME`, falling back to
     * `DB_NAME`). Set `DB_DEVNAME` to keep tests off the live database.
     *
     * Never call this outside a test context.
     */
    public static function setInstanceForTesting(PDO $pdo, bool $useUtcTimezone = true): void
    {
        $expected = Config::getTestDatabaseName();
        $actual = (string)$pdo->query('SELECT current_database()')->fetchColumn();
        if ($expected === '' || $actual !== $expected) {
            throw new \RuntimeException(
                'Database::setInstanceForTesting() refuses a PDO connected to '
                . "\"{$actual}\" -- only \"{$expected}\" is accepted."
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

