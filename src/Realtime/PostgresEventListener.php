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

    /** @var resource|null */
    private $connection = null;

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
        if (!is_resource($socket)) {
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
        return is_resource($this->connection)
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
        if (is_resource($this->connection)) {
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
            'host=%s port=%s dbname=%s user=%s password=%s',
            $this->databaseConfig['host'],
            $this->databaseConfig['port'],
            $this->databaseConfig['database'],
            $this->databaseConfig['username'],
            $this->databaseConfig['password']
        );

        $connection = @pg_connect($connStr);
        if (!is_resource($connection)) {
            $this->logger->error('PostgreSQL event listener: pg_connect failed');
            return false;
        }

        $this->connection = $connection;
        return true;
    }

    private function issueListen(string $channel): bool
    {
        if (!is_resource($this->connection)) {
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
