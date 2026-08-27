<?php

namespace BinktermPHP\Net;

use BinktermPHP\ScreeningListRefresh;

/**
 * Base class for the registration-screening cache-list refreshers (Tor exit
 * list, disposable email domain list).
 *
 * Each concrete updater downloads a plain-text list (one entry per line,
 * `#` comments ignored), upserts every entry into a `(value, last_seen)` table
 * with `last_seen = NOW()`, then prunes rows not touched by this run so
 * departed entries age out. Progress and status are recorded in
 * `screening_list_refresh` so the binkp scheduler can gate the work to a fixed
 * interval.
 *
 * @see docs/proposals/NewUserScreeningIPAddress.md
 */
abstract class CachedListUpdater
{
    protected \PDO $db;

    /** @var callable(string):void */
    private $log;

    /**
     * @param callable(string):void|null $log Optional line logger for progress output.
     */
    public function __construct(\PDO $db, ?callable $log = null)
    {
        $this->db = $db;
        $this->log = $log ?? static function (string $m): void {};
    }

    /** The `screening_list_refresh.list_name` / cache table name. */
    abstract public function listName(): string;

    /** Column holding the cached value in the cache table. */
    abstract protected function valueColumn(): string;

    /** Download URL for the source list (typically resolved from an env var). */
    abstract protected function sourceUrl(): string;

    /**
     * Normalize and validate one non-comment line, returning the value to store
     * or null to skip the line.
     */
    abstract protected function parseLine(string $line): ?string;

    /**
     * SQL placeholder expression for an inserted value, e.g. `?` or `?::inet`.
     */
    protected function valuePlaceholder(): string
    {
        return '?';
    }

    /** Human-readable plural noun for the stored entries, used in log output. */
    protected function entryNoun(): string
    {
        return 'entries';
    }

    /**
     * @return array{status: string, total: int, pruned: int, error: ?string}
     */
    public function run(): array
    {
        $name = $this->listName();
        ScreeningListRefresh::markStarted($this->db, $name);

        $url = $this->sourceUrl();
        ($this->log)("Fetching {$url}");

        $ctx = stream_context_create([
            'http' => ['timeout' => 30, 'user_agent' => 'BinktermPHP/registration-screening'],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);

        if ($body === false || trim($body) === '') {
            ScreeningListRefresh::markFinished($this->db, $name, 'error', 'download failed');
            return ['status' => 'error', 'total' => 0, 'pruned' => 0, 'error' => 'download failed'];
        }

        $values = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parsed = $this->parseLine($line);
            if ($parsed !== null && $parsed !== '') {
                $values[$parsed] = true;
            }
        }
        $values = array_keys($values);

        if ($values === []) {
            ScreeningListRefresh::markFinished($this->db, $name, 'error', 'parsed zero entries');
            return ['status' => 'error', 'total' => 0, 'pruned' => 0, 'error' => 'parsed zero entries'];
        }

        ($this->log)(sprintf('Parsed %d %s', count($values), $this->entryNoun()));

        $col = $this->valueColumn();
        $ph = $this->valuePlaceholder();

        $this->db->beginTransaction();
        try {
            $runStart = $this->db->query('SELECT NOW()')->fetchColumn();

            $insert = $this->db->prepare("
                INSERT INTO {$name} ({$col}, last_seen)
                VALUES ({$ph}, NOW())
                ON CONFLICT ({$col}) DO UPDATE SET last_seen = EXCLUDED.last_seen
            ");
            foreach ($values as $value) {
                $insert->execute([$value]);
            }

            $prune = $this->db->prepare("DELETE FROM {$name} WHERE last_seen < ?");
            $prune->execute([$runStart]);
            $pruned = $prune->rowCount();

            $total = (int)$this->db->query("SELECT COUNT(*) FROM {$name}")->fetchColumn();
            $this->db->commit();

            ScreeningListRefresh::markFinished($this->db, $name, 'ok', null, $total);
            ($this->log)(sprintf('Done: %d %s cached, %d pruned', $total, $this->entryNoun(), $pruned));

            return ['status' => 'ok', 'total' => $total, 'pruned' => $pruned, 'error' => null];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            ScreeningListRefresh::markFinished($this->db, $name, 'error', $e->getMessage());
            return ['status' => 'error', 'total' => 0, 'pruned' => 0, 'error' => $e->getMessage()];
        }
    }
}
