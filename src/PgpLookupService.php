<?php

namespace BinktermPHP;

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Nodelist\NodelistManager;

class PgpLookupService
{
    private const TIMEOUT_SECONDS = 3;

    private PgpKeyService $localKeyService;
    private PgpContactKeyService $contactKeyService;
    private NodelistManager $nodelistManager;
    private BinkpConfig $binkpConfig;

    public function __construct(
        ?PgpKeyService $localKeyService = null,
        ?PgpContactKeyService $contactKeyService = null,
        ?NodelistManager $nodelistManager = null,
        ?BinkpConfig $binkpConfig = null
    ) {
        $this->localKeyService = $localKeyService ?? new PgpKeyService();
        $this->contactKeyService = $contactKeyService ?? new PgpContactKeyService();
        $this->nodelistManager = $nodelistManager ?? new NodelistManager();
        $this->binkpConfig = $binkpConfig ?? BinkpConfig::getInstance();
    }

    public function isLocalAddress(string $address): bool
    {
        $address = trim($address);
        if ($address === '') {
            return true;
        }

        return $this->binkpConfig->isMyAddress($address);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchPublicKeysForKeyserverQuery(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return $this->normalizeRows(
                $this->localKeyService->searchPublicKeys('', 100),
                'local',
                false
            );
        }

        $qualified = $this->parseQualifiedKeyserverSearch($search);
        if ($qualified === null) {
            return $this->normalizeRows(
                $this->localKeyService->searchPublicKeys($search, 200),
                'local',
                false
            );
        }

        if ($qualified['type'] === 'ftn') {
            return $this->searchRemotePublicKeys($qualified['identity'], $qualified['network']);
        }

        return $this->searchRemotePublicKeysByHost($qualified['identity'], $qualified['network']);
    }

    /**
     * @return array<string, mixed>
     */
    public function describeLookupTarget(string $address): array
    {
        $address = trim($address);
        if ($this->isLocalAddress($address)) {
            return [
                'type' => 'local',
                'address' => $address,
                'base_url' => null,
                'source' => 'local',
            ];
        }

        $endpoint = $this->resolveRemoteKeyserverEndpoint($address);
        return [
            'type' => 'remote',
            'address' => $address,
            'base_url' => $endpoint['base_url'] ?? null,
            'source' => $endpoint['source'] ?? 'remote_unresolved',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchPublicKeysForDestination(string $search, string $address, ?int $userId = null): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        if ($this->isLocalAddress($address)) {
            return $this->normalizeRows(
                $this->localKeyService->searchPublicKeys($search, 200),
                'local',
                false
            );
        }

        if ($userId !== null && $userId > 0) {
            $savedKeys = $this->contactKeyService->searchPublicKeysForUser($userId, $search, $address, 200);
            if ($savedKeys !== []) {
                return $this->normalizeRows($savedKeys, 'saved_contact', true);
            }
        }

        return $this->searchRemotePublicKeys($search, $address);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublicKeyForDestination(string $search, string $address, ?int $userId = null): ?array
    {
        $search = trim($search);
        if ($search === '') {
            return null;
        }

        if ($this->isLocalAddress($address)) {
            $row = $this->localKeyService->findPublicKey($search);
            return $row ? $this->normalizeRow($row, 'local', true) : null;
        }

        if ($userId !== null && $userId > 0) {
            $savedKey = $this->contactKeyService->findPublicKeyForUser($userId, $search, $address);
            if ($savedKey !== null) {
                return $this->normalizeRow($savedKey, 'saved_contact', true);
            }
        }

        return $this->findRemotePublicKey($search, $address);
    }

    /**
     * Verification lookup prefers the authenticated user's saved correspondent keys
     * and never performs remote network requests.
     *
     * @return array<string, mixed>|null
     */
    public function findPublicKeyForVerification(string $search, string $address = '', ?int $userId = null): ?array
    {
        $search = trim($search);
        $address = trim($address);
        if ($search === '') {
            return null;
        }

        if ($userId !== null && $userId > 0) {
            $savedKey = $this->contactKeyService->findPublicKeyForUser($userId, $search, $address);
            if ($savedKey !== null) {
                return $this->normalizeRow($savedKey, 'saved_contact', true);
            }
        }

        if ($this->isLocalAddress($address)) {
            $row = $this->localKeyService->findPublicKey($search);
            return $row ? $this->normalizeRow($row, 'local', true) : null;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchRemotePublicKeys(string $search, string $address): array
    {
        $endpoint = $this->resolveRemoteKeyserverEndpoint($address);
        if ($endpoint === null) {
            return [];
        }

        $url = $endpoint['base_url'] . '?op=index&search=' . rawurlencode($search);
        $response = $this->httpGet($url, $endpoint);
        if ($response === null || $response === '') {
            return [];
        }

        return $this->parseRemoteIndexResponse($response, $endpoint['source']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchRemotePublicKeysByHost(string $search, string $hostname): array
    {
        $endpoint = $this->resolveHostKeyserverEndpoint($hostname);
        if ($endpoint === null) {
            return [];
        }

        $url = $endpoint['base_url'] . '?op=index&search=' . rawurlencode($search);
        $response = $this->httpGet($url, $endpoint);
        if ($response === null || $response === '') {
            return [];
        }

        return $this->parseRemoteIndexResponse($response, $endpoint['source']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRemotePublicKey(string $search, string $address): ?array
    {
        $endpoint = $this->resolveRemoteKeyserverEndpoint($address);
        if ($endpoint === null) {
            return null;
        }

        $url = $endpoint['base_url'] . '?op=get&search=' . rawurlencode($search);
        $response = $this->httpGet($url, $endpoint);
        if ($response === null || stripos($response, 'BEGIN PGP PUBLIC KEY BLOCK') === false) {
            return null;
        }

        try {
            $parsed = (new Pgp\ArmoredPublicKeyParser())->parse(trim($response));
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'fingerprint' => strtoupper((string)($parsed['fingerprint'] ?? '')),
            'armored_public_key' => trim($response),
            'user_id_string' => $parsed['user_id_string'] ?? null,
            'email' => $parsed['email'] ?? null,
            'key_algorithm' => $parsed['key_algorithm'] ?? null,
            'key_created_at' => $parsed['key_created_at'] ?? null,
            'username' => null,
            'real_name' => null,
            'lookup_source' => $endpoint['source'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRemoteKeyserverEndpoint(string $address): ?array
    {
        $routeInfo = $this->resolveRouteInfo($address);
        if ($routeInfo === null || empty($routeInfo['hostname'])) {
            return null;
        }

        $hostname = trim((string)$routeInfo['hostname']);
        if ($hostname === '') {
            return null;
        }

        $srvEndpoint = $this->resolveSrvEndpoint($hostname);
        if ($srvEndpoint !== null) {
            return $srvEndpoint;
        }

        return [
            'base_url' => 'https://' . $this->formatHostForUrl($hostname) . '/pks/lookup',
            'host_header' => null,
            'verify_tls' => true,
            'source' => 'remote_host',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveHostKeyserverEndpoint(string $hostname): ?array
    {
        $hostname = trim($hostname);
        if ($hostname === '') {
            return null;
        }

        $srvEndpoint = $this->resolveSrvEndpoint($hostname);
        if ($srvEndpoint !== null) {
            return $srvEndpoint;
        }

        return [
            'base_url' => 'https://' . $this->formatHostForUrl($hostname) . '/pks/lookup',
            'host_header' => null,
            'verify_tls' => true,
            'source' => 'remote_host',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRouteInfo(string $address): ?array
    {
        $routeInfo = $this->nodelistManager->getCrashRouteInfo($address);
        if ($routeInfo !== null) {
            return $routeInfo;
        }

        if (strpos($address, '.') !== false) {
            $parentAddress = preg_replace('/\.\d+$/', '', $address);
            if (is_string($parentAddress) && $parentAddress !== '') {
                return $this->nodelistManager->getCrashRouteInfo($parentAddress);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSrvEndpoint(string $hostname): ?array
    {
        if (!function_exists('dns_get_record') || filter_var($hostname, FILTER_VALIDATE_IP)) {
            return null;
        }

        $records = @dns_get_record('_hkps._tcp.' . rtrim($hostname, '.'), DNS_SRV);
        if (!is_array($records) || $records === []) {
            return null;
        }

        usort($records, static function(array $a, array $b): int {
            $priorityA = (int)($a['pri'] ?? 0);
            $priorityB = (int)($b['pri'] ?? 0);
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            $weightA = (int)($a['weight'] ?? 0);
            $weightB = (int)($b['weight'] ?? 0);
            return $weightB <=> $weightA;
        });

        foreach ($records as $record) {
            $target = rtrim((string)($record['target'] ?? ''), '.');
            if ($target === '') {
                continue;
            }

            $port = (int)($record['port'] ?? 443);
            if ($port <= 0) {
                $port = 443;
            }

            return [
                'base_url' => 'https://' . $this->formatHostForUrl($target) . ($port === 443 ? '' : ':' . $port) . '/pks/lookup',
                'host_header' => null,
                'verify_tls' => true,
                'source' => 'remote_srv',
            ];
        }

        return null;
    }

    private function formatHostForUrl(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $host . ']'
            : $host;
    }

    /**
     * @param array<string, mixed> $endpoint
     */
    private function httpGet(string $url, array $endpoint): ?string
    {
        $headers = [
            'Accept: text/plain',
            'User-Agent: BinktermPHP PGP Lookup/1.0',
        ];
        if (!empty($endpoint['host_header'])) {
            $headers[] = 'Host: ' . $endpoint['host_header'];
        }

        $verifyTls = !empty($endpoint['verify_tls']);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
                CURLOPT_SSL_VERIFYPEER => $verifyTls,
            ]);

            $raw = curl_exec($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!is_string($raw) || $httpStatus < 200 || $httpStatus >= 300) {
                return null;
            }

            return $raw;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $verifyTls,
                'verify_peer_name' => $verifyTls,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $httpStatus = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                    $httpStatus = (int)$matches[1];
                    break;
                }
            }
        }

        return ($httpStatus >= 200 && $httpStatus < 300) ? $raw : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows, string $lookupSource, bool $includeArmor): array
    {
        return array_map(function(array $row) use ($lookupSource, $includeArmor): array {
            return $this->normalizeRow($row, $lookupSource, $includeArmor);
        }, $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, string $lookupSource, bool $includeArmor): array
    {
        $normalized = [
            'fingerprint' => strtoupper((string)($row['fingerprint'] ?? '')),
            'user_id_string' => $row['user_id_string'] ?? null,
            'username' => $row['username'] ?? null,
            'key_algorithm' => $row['key_algorithm'] ?? null,
            'key_created_at' => $row['key_created_at'] ?? null,
            'lookup_source' => $lookupSource,
        ];

        if ($includeArmor) {
            $normalized['armored_public_key'] = $row['armored_public_key'] ?? null;
            $normalized['email'] = $row['email'] ?? null;
        }

        if (array_key_exists('address_book_entry_id', $row)) {
            $normalized['address_book_entry_id'] = $row['address_book_entry_id'];
        }
        if (array_key_exists('address_book_name', $row)) {
            $normalized['address_book_name'] = $row['address_book_name'];
        }
        if (array_key_exists('address_book_node_address', $row)) {
            $normalized['address_book_node_address'] = $row['address_book_node_address'];
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseRemoteIndexResponse(string $text, string $lookupSource): array
    {
        $results = [];
        $current = null;

        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $rawLine) {
            $line = trim((string)$rawLine);
            if ($line === '') {
                continue;
            }

            if (strpos($line, 'pub:') === 0) {
                $parts = explode(':', $line);
                $current = [
                    'fingerprint' => strtoupper((string)($parts[1] ?? '')),
                    'key_algorithm' => $parts[2] ?? '',
                    'key_created_at' => $parts[3] ?? '',
                    'username' => $parts[4] ?? '',
                    'real_name' => null,
                    'email' => null,
                    'label' => null,
                    'is_primary' => false,
                    'user_id_string' => '',
                    'armored_public_key' => null,
                    'lookup_source' => $lookupSource,
                ];
                if ($current['fingerprint'] !== '') {
                    $results[] = $current;
                }
                continue;
            }

            if (strpos($line, 'uid:') === 0 && !empty($results)) {
                $uid = trim(substr($line, 4));
                $index = array_key_last($results);
                $results[$index]['user_id_string'] = $uid;
                $parsedUid = $this->parseUidString($uid);
                if ($parsedUid['real_name'] !== null) {
                    $results[$index]['real_name'] = $parsedUid['real_name'];
                }
                if ($parsedUid['email'] !== null) {
                    $results[$index]['email'] = $parsedUid['email'];
                }
            }
        }

        return $results;
    }

    /**
     * @return array{identity:string,network:string,type:string}|null
     */
    private function parseQualifiedKeyserverSearch(string $search): ?array
    {
        $search = trim($search);
        $atPos = strrpos($search, '@');
        if ($search === '' || $atPos === false || $atPos === 0 || $atPos === strlen($search) - 1) {
            return null;
        }

        $identity = trim(substr($search, 0, $atPos));
        $network = trim(substr($search, $atPos + 1));
        if ($identity === '' || $network === '') {
            return null;
        }

        if (preg_match('/^[0-9]+:[0-9]+\/[0-9]+(?:\.[0-9]+)?$/', $network) === 1) {
            return [
                'identity' => $identity,
                'network' => $network,
                'type' => 'ftn',
            ];
        }

        if (filter_var($network, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            || filter_var($network, FILTER_VALIDATE_IP) !== false) {
            return [
                'identity' => $identity,
                'network' => strtolower($network),
                'type' => 'host',
            ];
        }

        return null;
    }

    /**
     * @return array{real_name:?string,email:?string}
     */
    private function parseUidString(string $uid): array
    {
        $uid = trim($uid);
        if ($uid === '') {
            return ['real_name' => null, 'email' => null];
        }

        $realName = $uid;
        $email = null;
        if (preg_match('/^(.*?)\s*<([^>]+)>$/', $uid, $matches)) {
            $realName = trim((string)$matches[1]);
            $email = trim((string)$matches[2]);
        }

        if ($realName === '') {
            $realName = null;
        }
        if ($email === '') {
            $email = null;
        }

        return [
            'real_name' => $realName,
            'email' => $email,
        ];
    }
}
