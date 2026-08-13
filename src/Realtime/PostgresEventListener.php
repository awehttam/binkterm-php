<?php

namespace BinktermPHP\Realtime;

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;

/**
 * PostgreSQL LISTEN/NOTIFY listener used by realtime daemons.
 */
class PostgresEventListener implements EventListenerInterface
{
    /** @var array<string, mixed> */
    private array $databaseConfig;
    private Logger $logger;
    private ?string $channel = null;

    /** @var resource|\PgSql\Connection|null */
    private $connection = null;

    /**
     * PHP 8.1+ returns opaque objects (PgSql\Connection, Socket) from these
     * pgsql/sockets APIs instead of resources; accept either representation.
     */
    private static function isValidHandle(mixed $value, string $objectClass): bool
    {
        return is_resource($value) || $value instanceof $objectClass;
    }

    /**
     * @param array<string, mixed> $databaseConfig
     */
    public function __construct(array $databaseConfig, Logger $logger)
    {
        $this->databaseConfig = $databaseConfig;
        $this->logger = $logger;
    }

    /**
     * Build a listener from the configured application database settings.
     */
    public static function fromConfiguredDatabase(Logger $logger): self
    {
        return new self(Config::getDatabaseConfig(), $logger);
    }

    /**
     * Subscribe to a PostgreSQL LISTEN channel.
     */
    public function listen(string $channel): bool
    {
        $this->channel = $channel;

        if (!$this->ensureConnected()) {
            return false;
        }

        return $this->issueListen($channel);
    }

    /**
     * @return list<string>
     */
    public function wait(int $timeoutMs): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        $socket = pg_socket($this->connection);
        if (!self::isValidHandle($socket, \Socket::class)) {
            return [];
        }

        $seconds = intdiv($timeoutMs, 1000);
        $micros = ($timeoutMs % 1000) * 1000;
        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, $seconds, $micros);

        if ($ready === false || $ready === 0) {
            return [];
        }

        $payloads = [];
        while ($notify = pg_get_notify($this->connection, PGSQL_ASSOC)) {
            $payload = (string)($notify['payload'] ?? '');
            if ($payload !== '') {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * Whether the PostgreSQL connection is currently usable.
     */
    public function isHealthy(): bool
    {
        return self::isValidHandle($this->connection, \PgSql\Connection::class)
            && pg_connection_status($this->connection) === PGSQL_CONNECTION_OK;
    }

    /**
     * Reconnect and re-subscribe to the active channel.
     */
    public function reconnect(): bool
    {
        $channel = $this->channel;
        $this->close();

        if (!$this->ensureConnected()) {
            return false;
        }

        return $channel === null ? true : $this->issueListen($channel);
    }

    /**
     * Close the PostgreSQL notification connection.
     */
    public function close(): void
    {
        if (self::isValidHandle($this->connection, \PgSql\Connection::class)) {
            pg_close($this->connection);
        }

        $this->connection = null;
    }

    private function ensureConnected(): bool
    {
        if ($this->isHealthy()) {
            return true;
        }

        if (!function_exists('pg_connect')) {
            $this->logger->error('PostgreSQL event listener requires the pgsql PHP extension (pg_connect not found)');
            return false;
        }

        $connStr = sprintf(
            "host='%s' port='%s' dbname='%s' user='%s' password='%s'",
            addcslashes((string)$this->databaseConfig['host'], "'\\"),
            addcslashes((string)$this->databaseConfig['port'], "'\\"),
            addcslashes((string)$this->databaseConfig['database'], "'\\"),
            addcslashes((string)$this->databaseConfig['username'], "'\\"),
            addcslashes((string)$this->databaseConfig['password'], "'\\")
        );

        $ssl = $this->databaseConfig['ssl'] ?? [];
        if (!empty($ssl['enabled'])) {
            $connStr .= " sslmode=require";
            if (!empty($ssl['ca_cert'])) {
                $connStr .= " sslrootcert='" . addcslashes((string)$ssl['ca_cert'], "'\\") . "'";
            }
            if (!empty($ssl['client_cert'])) {
                $connStr .= " sslcert='" . addcslashes((string)$ssl['client_cert'], "'\\") . "'";
            }
            if (!empty($ssl['client_key'])) {
                $connStr .= " sslkey='" . addcslashes((string)$ssl['client_key'], "'\\") . "'";
            }
        }

        $phpWarning = null;
        set_error_handler(function (int $errno, string $errstr) use (&$phpWarning): bool {
            $phpWarning = $errstr;
            return true;
        });
        try {
            $connection = pg_connect($connStr);
        } finally {
            restore_error_handler();
        }

        if (!self::isValidHandle($connection, \PgSql\Connection::class)) {
            $this->logger->error('PostgreSQL event listener: pg_connect failed', [
                'host' => $this->databaseConfig['host'],
                'port' => $this->databaseConfig['port'],
                'dbname' => $this->databaseConfig['database'],
                'ssl_enabled' => !empty($ssl['enabled']),
                'warning' => $phpWarning,
            ]);
            return false;
        }

        $this->connection = $connection;
        return true;
    }

    private function issueListen(string $channel): bool
    {
        if (!self::isValidHandle($this->connection, \PgSql\Connection::class)) {
            return false;
        }

        $quotedChannel = preg_replace('/[^A-Za-z0-9_]/', '', $channel);
        if ($quotedChannel === null || $quotedChannel === '') {
            $this->logger->error('PostgreSQL event listener: invalid LISTEN channel name', ['channel' => $channel]);
            return false;
        }

        $result = @pg_query($this->connection, 'LISTEN ' . $quotedChannel);
        if ($result === false) {
            $this->logger->error('PostgreSQL event listener: LISTEN failed', ['channel' => $channel]);
            return false;
        }

        return true;
    }
}
