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

/**
 * Gathers PostgreSQL database statistics from pg_stat_* system views.
 */
class DatabaseStats
{
    private \PDO $db;

    /** @var int|null PostgreSQL server version number (e.g. 140005) */
    private ?int $pgVersion = null;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Lightweight summary for the admin dashboard widget.
     *
     * @return array{db_size: string, db_size_bytes: int}
     */
    public function getDashboardSummary(): array
    {
        try {
            $row = $this->db->query(
                "SELECT pg_size_pretty(pg_database_size(current_database())) AS db_size,
                        pg_database_size(current_database()) AS db_size_bytes"
            )->fetch(\PDO::FETCH_ASSOC);

            return [
                'db_size'       => $row['db_size'],
                'db_size_bytes' => (int)$row['db_size_bytes'],
            ];
        } catch (\Throwable $e) {
            return ['db_size' => 'N/A', 'db_size_bytes' => 0];
        }
    }

    /**
     * Size & Growth: database size, top table sizes, index sizes, bloat estimates.
     */
    public function getSizeAndGrowth(): array
    {
        $result = [];

        try {
            $row = $this->db->query(
                "SELECT pg_size_pretty(pg_database_size(current_database())) AS db_size,
                        pg_database_size(current_database()) AS db_size_bytes"
            )->fetch(\PDO::FETCH_ASSOC);
            $result['db_size']       = $row['db_size'];
            $result['db_size_bytes'] = (int)$row['db_size_bytes'];
        } catch (\Throwable $e) {
            $result['db_size']       = 'N/A';
            $result['db_size_bytes'] = 0;
        }

        // Top tables by total size (table + indexes)
        try {
            $stmt = $this->db->query(
                "SELECT relname AS table_name,
                        pg_size_pretty(pg_total_relation_size(oid)) AS total_size,
                        pg_size_pretty(pg_relation_size(oid))       AS table_size,
                        pg_size_pretty(pg_total_relation_size(oid) - pg_relation_size(oid)) AS index_size,
                        pg_total_relation_size(oid) AS total_bytes
                 FROM pg_class
                 WHERE relkind = 'r'
                   AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
                 ORDER BY pg_total_relation_size(oid) DESC
                 LIMIT 20"
            );
            $result['table_sizes'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['table_sizes'] = [];
        }

        // Index sizes
        try {
            $stmt = $this->db->query(
                "SELECT indexrelname AS indexname, relname AS tablename,
                        pg_size_pretty(pg_relation_size(indexrelid)) AS index_size,
                        pg_relation_size(indexrelid) AS size_bytes
                 FROM pg_stat_user_indexes
                 ORDER BY pg_relation_size(indexrelid) DESC
                 LIMIT 20"
            );
            $result['index_sizes'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['index_sizes'] = [];
        }

        // Bloat estimates via dead tuple counts
        try {
            $stmt = $this->db->query(
                "SELECT relname AS table_name,
                        n_dead_tup AS dead_tuples,
                        n_live_tup AS live_tuples,
                        CASE WHEN n_live_tup + n_dead_tup > 0
                             THEN ROUND(100.0 * n_dead_tup / (n_live_tup + n_dead_tup), 1)
                             ELSE 0 END AS dead_pct
                 FROM pg_stat_user_tables
                 WHERE n_dead_tup > 0
                 ORDER BY n_dead_tup DESC
                 LIMIT 20"
            );
            $result['bloat'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['bloat'] = [];
        }

        return $result;
    }

    /**
     * Activity: connections, transactions, tuples, cache hit ratio.
     */
    public function getActivity(): array
    {
        $result = [];

        // Active connections vs max
        try {
            $current = (int)$this->db->query(
                "SELECT count(*) AS cnt FROM pg_stat_activity WHERE state IS NOT NULL"
            )->fetch(\PDO::FETCH_ASSOC)['cnt'];

            $max = (int)$this->db->query("SHOW max_connections")->fetch(\PDO::FETCH_ASSOC)['max_connections'];

            $result['connections_current'] = $current;
            $result['connections_max']     = $max;
            $result['connections_pct']     = $max > 0 ? round(100.0 * $current / $max, 1) : 0;
        } catch (\Throwable $e) {
            $result['connections_current'] = null;
            $result['connections_max']     = null;
            $result['connections_pct']     = null;
        }

        // Transaction counts and cache hit ratio from pg_stat_database
        try {
            $row = $this->db->query(
                "SELECT xact_commit, xact_rollback, blks_hit, blks_read,
                        temp_files, temp_bytes,
                        CASE WHEN blks_hit + blks_read > 0
                             THEN ROUND(100.0 * blks_hit / (blks_hit + blks_read), 2)
                             ELSE 100 END AS cache_hit_ratio
                 FROM pg_stat_database WHERE datname = current_database()"
            )->fetch(\PDO::FETCH_ASSOC);

            $result['xact_commit']     = (int)$row['xact_commit'];
            $result['xact_rollback']   = (int)$row['xact_rollback'];
            $result['blocks_hit']      = (int)$row['blks_hit'];
            $result['blocks_read']     = (int)$row['blks_read'];
            $result['temp_files']      = (int)$row['temp_files'];
            $result['temp_bytes']      = $row['temp_bytes'];
            $result['cache_hit_ratio'] = (float)$row['cache_hit_ratio'];
        } catch (\Throwable $e) {
            $result['xact_commit']     = null;
            $result['xact_rollback']   = null;
            $result['cache_hit_ratio'] = null;
        }

        // Tuple activity aggregated across all user tables
        try {
            $row = $this->db->query(
                "SELECT SUM(n_tup_ins)::bigint  AS ins,
                        SUM(n_tup_upd)::bigint  AS upd,
                        SUM(n_tup_del)::bigint  AS del,
                        SUM(n_tup_hot_upd)::bigint AS hot_upd,
                        SUM(seq_tup_read)::bigint  AS seq_read
                 FROM pg_stat_user_tables"
            )->fetch(\PDO::FETCH_ASSOC);

            $result['tup_inserted']   = (int)($row['ins']      ?? 0);
            $result['tup_updated']    = (int)($row['upd']      ?? 0);
            $result['tup_deleted']    = (int)($row['del']      ?? 0);
            $result['tup_hot_upd']    = (int)($row['hot_upd']  ?? 0);
            $result['tup_seq_read']   = (int)($row['seq_read'] ?? 0);
        } catch (\Throwable $e) {
            $result['tup_inserted'] = null;
        }

        // Active connection breakdown by state
        try {
            $stmt = $this->db->query(
                "SELECT state, count(*) AS cnt
                 FROM pg_stat_activity
                 WHERE state IS NOT NULL
                 GROUP BY state
                 ORDER BY cnt DESC"
            );
            $result['connection_states'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['connection_states'] = [];
        }

        return $result;
    }

    /**
     * Query Performance: slow queries, most-called, long-running, lock waits.
     */
    public function getQueryPerformance(): array
    {
        $result = [];

        // Check if pg_stat_statements is available
        try {
            $ext = $this->db->query(
                "SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'"
            )->fetch();
            $result['pg_stat_statements_available'] = (bool)$ext;
        } catch (\Throwable $e) {
            $result['pg_stat_statements_available'] = false;
        }

        if ($result['pg_stat_statements_available']) {
            // Column names differ between PG < 13 and PG >= 13
            $execTimeCol = $this->pgVersion() >= 130000 ? 'mean_exec_time' : 'mean_time';
            $totalTimeCol = $this->pgVersion() >= 130000 ? 'total_exec_time' : 'total_time';

            try {
                $stmt = $this->db->query(
                    "SELECT LEFT(query, 200) AS query, calls,
                            ROUND({$execTimeCol}::numeric, 2)  AS mean_ms,
                            ROUND({$totalTimeCol}::numeric, 2) AS total_ms,
                            rows
                     FROM pg_stat_statements
                     ORDER BY {$execTimeCol} DESC
                     LIMIT 10"
                );
                $result['slowest_queries'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $result['slowest_queries'] = [];
            }

            try {
                $stmt = $this->db->query(
                    "SELECT LEFT(query, 200) AS query, calls,
                            ROUND({$execTimeCol}::numeric, 2)  AS mean_ms,
                            ROUND({$totalTimeCol}::numeric, 2) AS total_ms
                     FROM pg_stat_statements
                     ORDER BY calls DESC
                     LIMIT 10"
                );
                $result['most_called'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $result['most_called'] = [];
            }
        } else {
            $result['slowest_queries'] = [];
            $result['most_called']     = [];
        }

        // Long-running queries (active > 5 seconds)
        try {
            $stmt = $this->db->query(
                "SELECT pid, usename, state,
                        EXTRACT(EPOCH FROM (now() - query_start))::int AS duration_sec,
                        LEFT(query, 300) AS query
                 FROM pg_stat_activity
                 WHERE state = 'active'
                   AND query_start < now() - interval '5 seconds'
                   AND query NOT LIKE '%pg_stat_activity%'
                 ORDER BY query_start ASC"
            );
            $result['long_running'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['long_running'] = [];
        }

        // Lock waits
        try {
            $result['lock_waits'] = (int)$this->db->query(
                "SELECT count(*) AS cnt FROM pg_locks WHERE NOT granted"
            )->fetch(\PDO::FETCH_ASSOC)['cnt'];
        } catch (\Throwable $e) {
            $result['lock_waits'] = null;
        }

        // Deadlock count
        try {
            $result['deadlocks'] = (int)$this->db->query(
                "SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()"
            )->fetch(\PDO::FETCH_ASSOC)['deadlocks'];
        } catch (\Throwable $e) {
            $result['deadlocks'] = null;
        }

        return $result;
    }

    /**
     * Replication: sender status and WAL receiver info if this is a replica.
     */
    public function getReplication(): array
    {
        $result = [];

        try {
            $stmt = $this->db->query(
                "SELECT client_addr, state, sent_lsn, write_lsn, flush_lsn, replay_lsn,
                        (sent_lsn - replay_lsn) AS lag_bytes
                 FROM pg_stat_replication"
            );
            $result['senders'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['senders'] = [];
        }

        try {
            $row = $this->db->query("SELECT * FROM pg_stat_wal_receiver LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            $result['receiver'] = $row ?: null;
        } catch (\Throwable $e) {
            $result['receiver'] = null;
        }

        return $result;
    }

    /**
     * Maintenance Health: last vacuum/analyze, dead tuples, tables needing work.
     */
    public function getMaintenanceHealth(): array
    {
        $result = [];

        try {
            $stmt = $this->db->query(
                "SELECT relname AS table_name,
                        last_vacuum, last_autovacuum,
                        last_analyze, last_autoanalyze,
                        n_dead_tup  AS dead_tuples,
                        n_live_tup  AS live_tuples,
                        CASE WHEN n_live_tup + n_dead_tup > 0
                             THEN ROUND(100.0 * n_dead_tup / (n_live_tup + n_dead_tup), 1)
                             ELSE 0 END AS dead_pct
                 FROM pg_stat_user_tables
                 ORDER BY n_dead_tup DESC"
            );
            $result['tables'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['tables'] = [];
        }

        // Tables that may need a vacuum (dead_tuples > 10000 or >5%)
        $result['needs_vacuum'] = array_values(array_filter(
            $result['tables'],
            fn($t) => ((int)$t['dead_tuples'] > 10000 || (float)$t['dead_pct'] > 5)
        ));

        // Active autovacuum workers
        try {
            $stmt = $this->db->query(
                "SELECT pid, query, EXTRACT(EPOCH FROM (now() - query_start))::int AS duration_sec
                 FROM pg_stat_activity
                 WHERE query LIKE 'autovacuum:%'
                 ORDER BY query_start ASC"
            );
            $result['autovacuum_workers'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['autovacuum_workers'] = [];
        }

        return $result;
    }

    /**
     * Index Health: unused indexes, scan ratios, potentially redundant indexes.
     */
    public function getIndexHealth(): array
    {
        $result = [];

        // Unused indexes (zero scans since last stats reset)
        try {
            $stmt = $this->db->query(
                "SELECT schemaname, tablename, indexname, idx_scan,
                        pg_size_pretty(pg_relation_size(indexrelid)) AS index_size,
                        pg_relation_size(indexrelid) AS size_bytes
                 FROM pg_stat_user_indexes
                 WHERE idx_scan = 0
                 ORDER BY pg_relation_size(indexrelid) DESC"
            );
            $result['unused'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['unused'] = [];
        }

        // Index scan vs sequential scan ratio per table
        try {
            $stmt = $this->db->query(
                "SELECT relname AS table_name,
                        seq_scan, seq_tup_read,
                        idx_scan, idx_tup_fetch,
                        CASE WHEN seq_scan + COALESCE(idx_scan, 0) > 0
                             THEN ROUND(100.0 * COALESCE(idx_scan, 0) / (seq_scan + COALESCE(idx_scan, 0)), 1)
                             ELSE NULL END AS idx_scan_pct
                 FROM pg_stat_user_tables
                 WHERE seq_scan + COALESCE(idx_scan, 0) > 0
                 ORDER BY seq_scan DESC
                 LIMIT 25"
            );
            $result['scan_ratios'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['scan_ratios'] = [];
        }

        // Potentially redundant indexes (same table + same column set)
        try {
            $stmt = $this->db->query(
                "SELECT t.relname AS table_name,
                        ia.relname AS index1,
                        ib.relname AS index2,
                        LEFT(pg_get_indexdef(a.indexrelid), 200) AS def1,
                        LEFT(pg_get_indexdef(b.indexrelid), 200) AS def2
                 FROM pg_index a
                 JOIN pg_index b
                   ON a.indrelid = b.indrelid
                  AND a.indexrelid < b.indexrelid
                  AND a.indkey::text = b.indkey::text
                 JOIN pg_class t  ON t.oid  = a.indrelid
                 JOIN pg_class ia ON ia.oid = a.indexrelid
                 JOIN pg_class ib ON ib.oid = b.indexrelid
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 WHERE n.nspname = 'public'
                 LIMIT 20"
            );
            $result['duplicates'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $result['duplicates'] = [];
        }

        return $result;
    }

    /**
     * i18n catalog stats: file sizes, key counts, and serialized memory footprint
     * for each locale and namespace file under config/i18n/.
     *
     * @return array List of per-locale entries, each with a 'files' sub-array.
     */
    public function getI18nCatalogStats(): array
    {
        $i18nDir = dirname(__DIR__) . '/config/i18n';
        $locales = [];

        if (!is_dir($i18nDir)) {
            return $locales;
        }

        foreach (scandir($i18nDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $localeDir = $i18nDir . '/' . $entry;
            if (!is_dir($localeDir)) {
                continue;
            }

            $files = [];
            $totalKeys   = 0;
            $totalBytes  = 0;
            $totalMemory = 0;

            foreach (glob($localeDir . '/*.php') as $filePath) {
                $fileBytes = (int)filesize($filePath);
                $catalog   = include $filePath;
                $keyCount  = is_array($catalog) ? count($catalog) : 0;
                $memBytes  = strlen(serialize($catalog));

                $files[] = [
                    'filename'     => basename($filePath),
                    'file_bytes'   => $fileBytes,
                    'key_count'    => $keyCount,
                    'memory_bytes' => $memBytes,
                ];

                $totalKeys   += $keyCount;
                $totalBytes  += $fileBytes;
                $totalMemory += $memBytes;
            }

            usort($files, fn($a, $b) => strcmp($a['filename'], $b['filename']));

            $locales[] = [
                'locale'        => $entry,
                'files'         => $files,
                'total_keys'    => $totalKeys,
                'total_bytes'   => $totalBytes,
                'total_memory'  => $totalMemory,
            ];
        }

        usort($locales, fn($a, $b) => strcmp($a['locale'], $b['locale']));

        return $locales;
    }

    /**
     * Returns the PostgreSQL server version number (e.g. 140005).
     */
    private function pgVersion(): int
    {
        if ($this->pgVersion === null) {
            try {
                $this->pgVersion = (int)$this->db->query(
                    "SELECT current_setting('server_version_num')::int AS ver"
                )->fetch(\PDO::FETCH_ASSOC)['ver'];
            } catch (\Throwable $e) {
                $this->pgVersion = 0;
            }
        }
        return $this->pgVersion;
    }
}
