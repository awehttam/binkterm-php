<?php

namespace BinktermPHP;

use BinktermPHP\Net\DnsResolver;

/**
 * Computes a small set of risk signals from a registration's IP address and
 * email address and decides whether the signup should be forced through manual
 * review even when the BBS is configured to auto-approve registrations.
 *
 * All checks fail open on availability: any error or timeout in a signal is
 * treated as "not triggered" and logged, never as a registration failure.
 *
 * Gated entirely by BbsConfig::getRegistrationScreeningConfig()['enabled'].
 * The 'mode' value ('enforce' vs 'observe') is surfaced in the result but not
 * acted on here — the caller decides whether to honour force_manual_review.
 *
 * @see docs/proposals/NewUserScreeningIPAddress.md
 */
class RegistrationScreening
{
    private \PDO $db;

    private array $config;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->config = BbsConfig::getRegistrationScreeningConfig();
    }

    public function isEnabled(): bool
    {
        return (bool)$this->config['enabled'];
    }

    public function getMode(): string
    {
        return (string)$this->config['mode'];
    }

    /**
     * Run all enabled checks.
     *
     * @param string      $ipAddress The registrant's IP.
     * @param string|null $email     The submitted email (may be null on web).
     * @return array{
     *     risk_score: int,
     *     flags: array<int, array{type: string, detail: string, weight: int}>,
     *     force_manual_review: bool,
     *     mode: string,
     *     enabled: bool
     * }
     */
    public function screen(string $ipAddress, ?string $email = null): array
    {
        $result = [
            'risk_score' => 0,
            'flags' => [],
            'force_manual_review' => false,
            'mode' => $this->config['mode'],
            'enabled' => (bool)$this->config['enabled'],
        ];

        if (!$this->config['enabled']) {
            return $result;
        }

        $signals = $this->config['signals'];
        $ipAddress = trim($ipAddress);
        $emailDomain = $this->extractEmailDomain($email);

        try {
            $flags = array_merge(
                $this->runDnsSignals($ipAddress, $emailDomain, $signals),
                $this->checkTorExit($ipAddress, $signals['tor_exit'] ?? []),
                $this->checkVelocity($ipAddress, $signals['velocity'] ?? []),
                $this->checkDisposableEmail($emailDomain, $signals['disposable_email'] ?? [])
            );
        } catch (\Throwable $e) {
            getServerLogger()->error('RegistrationScreening: unexpected failure: ' . $e->getMessage());
            return $result;
        }

        $score = 0;
        foreach ($flags as $flag) {
            $score += (int)$flag['weight'];
        }

        $result['risk_score'] = $score;
        $result['flags'] = array_values($flags);
        $result['force_manual_review'] = $this->config['mode'] === 'enforce'
            && $score >= (int)$this->config['threshold'];

        return $result;
    }

    /**
     * RBL zone lookups and email-domain MX/A/AAAA validation, all issued as one
     * parallel DNS batch so the added latency is the single slowest query.
     *
     * @return array<int, array{type: string, detail: string, weight: int}>
     */
    private function runDnsSignals(string $ipAddress, ?string $emailDomain, array $signals): array
    {
        $rblCfg = $signals['rbl'] ?? [];
        $mxCfg = $signals['email_mx'] ?? [];

        $rblEnabled = !empty($rblCfg['enabled']);
        $mxEnabled = !empty($mxCfg['enabled']) && $emailDomain !== null;

        $reversed = $rblEnabled ? $this->reverseIpForDnsbl($ipAddress) : null;
        $zones = [];
        if ($rblEnabled && $reversed !== null && is_array($rblCfg['zones'] ?? null)) {
            $zones = $rblCfg['zones'];
        }

        $queries = [];
        foreach ($zones as $i => $zone) {
            $host = trim((string)($zone['zone'] ?? ''));
            if ($host === '') {
                continue;
            }
            $queries[] = [
                'name' => $reversed . '.' . $host,
                'type' => 'A',
                'key' => 'rbl:' . $i,
            ];
        }
        if ($mxEnabled) {
            $queries[] = ['name' => $emailDomain, 'type' => 'MX', 'key' => 'mx:MX'];
            $queries[] = ['name' => $emailDomain, 'type' => 'A', 'key' => 'mx:A'];
            $queries[] = ['name' => $emailDomain, 'type' => 'AAAA', 'key' => 'mx:AAAA'];
        }

        if ($queries === []) {
            return [];
        }

        $resolver = new DnsResolver(null, (int)$this->config['dns_timeout_ms']);
        $responses = $resolver->query($queries, (int)$this->config['dns_timeout_ms']);

        $flags = [];

        // RBL hits (with per-zone response-code filtering).
        foreach ($zones as $i => $zone) {
            $res = $responses['rbl:' . $i] ?? null;
            if ($res === null || !$res['answered'] || $res['records'] === []) {
                continue;
            }
            $host = trim((string)($zone['zone'] ?? ''));
            $accept = is_array($zone['accept_codes'] ?? null) ? $zone['accept_codes'] : ['*'];
            $matched = $this->matchRblCodes($res['records'], $accept);
            if ($matched === null) {
                continue;
            }
            $weight = (int)($zone['weight'] ?? $rblCfg['weight'] ?? 25);
            $flags[] = [
                'type' => 'rbl',
                'detail' => sprintf('Listed on %s (%s)', $host, $matched),
                'weight' => $weight,
            ];
        }

        // Email domain deliverability.
        if ($mxEnabled) {
            $hasRecord = false;
            foreach (['mx:MX', 'mx:A', 'mx:AAAA'] as $key) {
                $res = $responses[$key] ?? null;
                if ($res !== null && $res['answered'] && $res['records'] !== []) {
                    $hasRecord = true;
                    break;
                }
            }
            $anyTimeout = false;
            foreach (['mx:MX', 'mx:A', 'mx:AAAA'] as $key) {
                if (!empty($responses[$key]['timed_out'])) {
                    $anyTimeout = true;
                }
            }
            if (!$hasRecord && !$anyTimeout) {
                $flags[] = [
                    'type' => 'invalid_email_mx',
                    'detail' => sprintf('No MX/A record for %s', $emailDomain),
                    'weight' => (int)($mxCfg['weight'] ?? 10),
                ];
            }
        }

        return $flags;
    }

    /**
     * @param string[] $records  127.0.0.x answers from the RBL zone
     * @param string[] $accept   Accepted codes, or ['*'] for any
     * @return string|null The matched code(s) description, or null if filtered out
     */
    private function matchRblCodes(array $records, array $accept): ?string
    {
        if (in_array('*', $accept, true)) {
            return implode(', ', $records);
        }
        $matched = array_values(array_intersect($records, $accept));
        return $matched !== [] ? implode(', ', $matched) : null;
    }

    /**
     * @return array<int, array{type: string, detail: string, weight: int}>
     */
    private function checkTorExit(string $ipAddress, array $cfg): array
    {
        if (empty($cfg['enabled']) || $ipAddress === '') {
            return [];
        }
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM tor_exit_nodes WHERE ip_address = ?::inet LIMIT 1');
            $stmt->execute([$ipAddress]);
            if ($stmt->fetchColumn() !== false) {
                return [[
                    'type' => 'tor_exit',
                    'detail' => 'IP is a known Tor exit node',
                    'weight' => (int)($cfg['weight'] ?? 15),
                ]];
            }
        } catch (\Throwable $e) {
            getServerLogger()->warning('RegistrationScreening: tor_exit check failed: ' . $e->getMessage());
        }
        return [];
    }

    /**
     * @return array<int, array{type: string, detail: string, weight: int}>
     */
    private function checkVelocity(string $ipAddress, array $cfg): array
    {
        if (empty($cfg['enabled']) || $ipAddress === '') {
            return [];
        }
        $windowHours = max(1, (int)($cfg['window_hours'] ?? 24));
        $prefix = (int)($cfg['subnet_prefix'] ?? 24);
        $countThreshold = max(1, (int)($cfg['count_threshold'] ?? 3));
        $isV6 = strpos($ipAddress, ':') !== false;
        if ($prefix < 1 || $prefix > ($isV6 ? 128 : 32)) {
            $prefix = $isV6 ? 64 : 24;
        }

        try {
            $sql = "
                SELECT COUNT(*)
                FROM registration_attempts
                WHERE attempt_time >= NOW() - (? || ' hours')::interval
                  AND ip_address ~ '^[0-9A-Fa-f:.]+$'
                  AND family(ip_address::inet) = family(?::inet)
                  AND set_masklen(ip_address::inet, ?) = set_masklen(?::inet, ?)
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$windowHours, $ipAddress, $prefix, $ipAddress, $prefix]);
            $count = (int)$stmt->fetchColumn();
            if ($count >= $countThreshold) {
                return [[
                    'type' => 'velocity',
                    'detail' => sprintf(
                        '%d registration attempts from /%d in the last %dh',
                        $count,
                        $prefix,
                        $windowHours
                    ),
                    'weight' => (int)($cfg['weight'] ?? 20),
                ]];
            }
        } catch (\Throwable $e) {
            getServerLogger()->warning('RegistrationScreening: velocity check failed: ' . $e->getMessage());
        }
        return [];
    }

    /**
     * @return array<int, array{type: string, detail: string, weight: int}>
     */
    private function checkDisposableEmail(?string $emailDomain, array $cfg): array
    {
        if (empty($cfg['enabled']) || $emailDomain === null) {
            return [];
        }
        // Match the domain and each of its registrable parents, so an address at
        // a subdomain of a listed throwaway provider (e.g. x.mailinator.com when
        // only mailinator.com is listed) is still caught.
        $candidates = $this->domainAndParents($emailDomain);
        if ($candidates === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($candidates), '?'));
            $stmt = $this->db->prepare(
                "SELECT domain FROM disposable_email_domains WHERE domain IN ($placeholders) LIMIT 1"
            );
            $stmt->execute($candidates);
            $matched = $stmt->fetchColumn();
            if ($matched !== false) {
                return [[
                    'type' => 'disposable_email',
                    'detail' => sprintf('%s is a known disposable email provider', $matched),
                    'weight' => (int)($cfg['weight'] ?? 15),
                ]];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet — disposable list is optional infrastructure.
            getServerLogger()->debug('RegistrationScreening: disposable_email check skipped: ' . $e->getMessage());
        }
        return [];
    }

    /**
     * Return the domain plus each parent down to (but not including) the bare
     * TLD, e.g. "a.b.example.com" -> ["a.b.example.com", "b.example.com",
     * "example.com"]. Capped to avoid an unbounded IN list.
     *
     * @return string[]
     */
    private function domainAndParents(string $domain): array
    {
        $labels = explode('.', $domain);
        $out = [];
        while (count($labels) >= 2 && count($out) < 6) {
            $out[] = implode('.', $labels);
            array_shift($labels);
        }
        return $out;
    }

    private function extractEmailDomain(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === strlen($email) - 1) {
            return null;
        }
        $domain = strtolower(substr($email, $at + 1));
        $domain = rtrim($domain, '.');
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return null;
        }
        return $domain;
    }

    /**
     * Reverse an IPv4/IPv6 address into the label ordering a DNSBL query expects.
     */
    private function reverseIpForDnsbl(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return implode('.', array_reverse(explode('.', $ip)));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return null;
            }
            $hex = bin2hex($packed); // 32 nibbles
            return implode('.', array_reverse(str_split($hex)));
        }
        return null;
    }
}
