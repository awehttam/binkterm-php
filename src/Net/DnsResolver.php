<?php

namespace BinktermPHP\Net;

/**
 * Minimal, timeout-bound, parallelized DNS resolver built on raw UDP sockets.
 *
 * PHP's built-in checkdnsrr()/dns_get_record() offer no per-query timeout and
 * no concurrency, which makes them unsuitable for registration-time RBL/DNSBL
 * and MX checks where several lookups must complete within a tight budget on a
 * synchronous, user-facing request. This resolver issues every outstanding
 * query as a non-blocking UDP socket up front and drives them together with a
 * single stream_select() loop, so total latency is bounded by the single
 * slowest query rather than the sum of all of them.
 *
 * Only A, AAAA and MX question types are supported — the narrow set the
 * registration screening feature needs. Responses in this feature's query
 * shapes (a handful of 127.0.0.x records, or a small MX set) never approach
 * the 512-byte UDP limit, so truncation/TCP fallback is intentionally not
 * implemented; a truncated answer is treated as "no usable answer".
 *
 * @see docs/proposals/NewUserScreeningIPAddress.md
 */
class DnsResolver
{
    private const TYPE_A = 1;
    private const TYPE_AAAA = 28;
    private const TYPE_MX = 15;

    private const TYPE_NAMES = [
        'A' => self::TYPE_A,
        'AAAA' => self::TYPE_AAAA,
        'MX' => self::TYPE_MX,
    ];

    /** @var string[] Resolver IPs to query (first that answers wins per query). */
    private array $resolvers;

    private int $defaultTimeoutMs;

    /**
     * @param string[]|null $resolvers Explicit resolver IPs; when null they are
     *                                 read from the system (resolv.conf) with a
     *                                 public-resolver fallback.
     */
    public function __construct(?array $resolvers = null, int $defaultTimeoutMs = 750)
    {
        $this->resolvers = $resolvers && $resolvers !== []
            ? array_values($resolvers)
            : self::discoverResolvers();
        $this->defaultTimeoutMs = max(50, $defaultTimeoutMs);
    }

    /**
     * Resolve several queries in parallel.
     *
     * @param array<int, array{name: string, type: string, key?: string}> $queries
     * @param int|null $timeoutMs Overall wall-clock budget; defaults to the
     *                            value given to the constructor.
     * @return array<string, array{
     *     name: string, type: string, answered: bool, timed_out: bool,
     *     error: ?string, records: string[]
     * }> Keyed by each query's 'key' (falling back to "type name").
     */
    public function query(array $queries, ?int $timeoutMs = null): array
    {
        $timeoutMs = $timeoutMs !== null ? max(50, $timeoutMs) : $this->defaultTimeoutMs;
        $deadline = microtime(true) + ($timeoutMs / 1000);

        $results = [];
        $pending = [];

        foreach ($queries as $q) {
            $name = trim((string)($q['name'] ?? ''));
            $typeName = strtoupper(trim((string)($q['type'] ?? 'A')));
            $key = (string)($q['key'] ?? ($typeName . ' ' . $name));

            $results[$key] = [
                'name' => $name,
                'type' => $typeName,
                'answered' => false,
                'timed_out' => false,
                'error' => null,
                'records' => [],
            ];

            if ($name === '' || !isset(self::TYPE_NAMES[$typeName])) {
                $results[$key]['error'] = 'invalid_query';
                continue;
            }
            if ($this->resolvers === []) {
                $results[$key]['error'] = 'no_resolver';
                continue;
            }

            $resolver = $this->resolvers[0];
            $txnId = random_int(0, 0xFFFF);
            $packet = $this->buildQueryPacket($txnId, $name, self::TYPE_NAMES[$typeName]);

            $errno = 0;
            $errstr = '';
            $sock = @stream_socket_client(
                'udp://' . $resolver . ':53',
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );

            if ($sock === false) {
                $results[$key]['error'] = 'socket_failed';
                continue;
            }

            stream_set_blocking($sock, false);
            if (@fwrite($sock, $packet) === false) {
                $results[$key]['error'] = 'write_failed';
                @fclose($sock);
                continue;
            }

            $pending[$key] = [
                'sock' => $sock,
                'txn' => $txnId,
                'qtype' => self::TYPE_NAMES[$typeName],
            ];
        }

        while ($pending !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $read = array_map(static fn($p) => $p['sock'], $pending);
            $write = null;
            $except = null;
            $sec = (int)floor($remaining);
            $usec = (int)(($remaining - $sec) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break;
            }

            foreach ($read as $sock) {
                $key = array_search($sock, array_map(static fn($p) => $p['sock'], $pending), true);
                if ($key === false) {
                    continue;
                }
                $info = $pending[$key];
                unset($pending[$key]);

                $raw = @fread($sock, 4096);
                @fclose($sock);

                if ($raw === false || strlen($raw) < 12) {
                    $results[$key]['error'] = 'bad_response';
                    continue;
                }

                try {
                    $records = $this->parseAnswer($raw, $info['txn'], $info['qtype']);
                    $results[$key]['answered'] = true;
                    $results[$key]['records'] = $records;
                } catch (\Throwable $e) {
                    $results[$key]['error'] = 'parse_failed';
                }
            }
        }

        foreach ($pending as $key => $info) {
            @fclose($info['sock']);
            $results[$key]['timed_out'] = true;
        }

        return $results;
    }

    /**
     * Convenience: does the given name have at least one A/AAAA/MX record?
     */
    public function hasAnyRecord(string $name, array $types = ['A'], ?int $timeoutMs = null): bool
    {
        $queries = [];
        foreach ($types as $t) {
            $queries[] = ['name' => $name, 'type' => $t, 'key' => strtoupper($t)];
        }
        foreach ($this->query($queries, $timeoutMs) as $res) {
            if ($res['answered'] && $res['records'] !== []) {
                return true;
            }
        }
        return false;
    }

    private function buildQueryPacket(int $txnId, string $name, int $qtype): string
    {
        $header = pack('n6', $txnId, 0x0100, 1, 0, 0, 0); // RD set, 1 question
        $qname = '';
        foreach (explode('.', rtrim($name, '.')) as $label) {
            $qname .= chr(strlen($label)) . $label;
        }
        $qname .= "\x00";
        return $header . $qname . pack('n2', $qtype, 1); // class IN
    }

    /**
     * @return string[] Parsed answer payloads (IPs for A/AAAA, exchange
     *                  hostnames for MX).
     */
    private function parseAnswer(string $msg, int $expectedTxn, int $qtype): array
    {
        $header = unpack('ntxn/nflags/nqd/nan/nns/nar', substr($msg, 0, 12));
        if ($header['txn'] !== $expectedTxn) {
            return [];
        }
        if (($header['flags'] & 0x000F) !== 0) { // RCODE != 0 (NXDOMAIN etc.)
            return [];
        }
        if (($header['flags'] & 0x0200) !== 0) { // TC (truncated) — treat as no answer
            return [];
        }

        $offset = 12;
        for ($i = 0; $i < $header['qd']; $i++) {
            $offset = $this->skipName($msg, $offset);
            $offset += 4; // QTYPE + QCLASS
        }

        $records = [];
        for ($i = 0; $i < $header['an']; $i++) {
            $offset = $this->skipName($msg, $offset);
            if ($offset + 10 > strlen($msg)) {
                break;
            }
            $rr = unpack('ntype/nclass/Nttl/nrdlen', substr($msg, $offset, 10));
            $offset += 10;
            $rdata = substr($msg, $offset, $rr['rdlen']);
            $rdataOffset = $offset;
            $offset += $rr['rdlen'];

            if ($rr['type'] !== $qtype) {
                continue;
            }

            if ($qtype === self::TYPE_A && strlen($rdata) === 4) {
                $records[] = inet_ntop($rdata);
            } elseif ($qtype === self::TYPE_AAAA && strlen($rdata) === 16) {
                $records[] = inet_ntop($rdata);
            } elseif ($qtype === self::TYPE_MX && strlen($rdata) >= 3) {
                // 2-byte preference, then a (possibly compressed) exchange name
                [$exchange] = $this->readName($msg, $rdataOffset + 2);
                if ($exchange !== '') {
                    $records[] = $exchange;
                }
            }
        }

        return $records;
    }

    private function skipName(string $msg, int $offset): int
    {
        while ($offset < strlen($msg)) {
            $len = ord($msg[$offset]);
            if ($len === 0) {
                return $offset + 1;
            }
            if (($len & 0xC0) === 0xC0) {
                return $offset + 2; // compression pointer ends the name
            }
            $offset += $len + 1;
        }
        return $offset;
    }

    /**
     * Read a (possibly compression-pointer-using) domain name.
     *
     * @return array{0: string, 1: int} The dotted name and the offset just
     *                                  past the name in the *original* stream.
     */
    private function readName(string $msg, int $offset): array
    {
        $labels = [];
        $endOffset = null;
        $jumps = 0;

        while ($offset < strlen($msg)) {
            $len = ord($msg[$offset]);
            if ($len === 0) {
                $offset++;
                break;
            }
            if (($len & 0xC0) === 0xC0) {
                if ($offset + 1 >= strlen($msg)) {
                    break;
                }
                $pointer = (($len & 0x3F) << 8) | ord($msg[$offset + 1]);
                if ($endOffset === null) {
                    $endOffset = $offset + 2;
                }
                $offset = $pointer;
                if (++$jumps > 20) {
                    break; // guard against pointer loops
                }
                continue;
            }
            $labels[] = substr($msg, $offset + 1, $len);
            $offset += $len + 1;
        }

        return [implode('.', $labels), $endOffset ?? $offset];
    }

    /**
     * @return string[]
     */
    private static function discoverResolvers(): array
    {
        $found = [];

        $paths = ['/etc/resolv.conf'];
        foreach ($paths as $path) {
            if (!@is_readable($path)) {
                continue;
            }
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (stripos($line, 'nameserver') !== 0) {
                    continue;
                }
                $ip = trim(substr($line, strlen('nameserver')));
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $found[] = $ip;
                }
            }
        }

        foreach (['1.1.1.1', '8.8.8.8', '9.9.9.9'] as $fallback) {
            if (!in_array($fallback, $found, true)) {
                $found[] = $fallback;
            }
        }

        return $found;
    }
}
