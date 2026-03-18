<?php

namespace BinktermPHP;

/**
 * Client for the LovlyNet registration/subscription API.
 *
 * Credentials are read from config/lovlynet.json, which is written by the
 * registration process.  Required keys:
 *   api_key       – 64-char hex API key
 *   ftn_address   – our FTN address (e.g. "227:1/400"); node number extracted from this
 *   hub_hostname  – LovlyNet server hostname (e.g. "lovlynet.lovelybits.org")
 */
class LovlyNetClient
{
    private static ?array $config = null;

    private string $baseUrl;
    private string $apiKey;
    private int    $nodeNumber;

    /** Timeout in seconds for outbound HTTP requests */
    private const TIMEOUT     = 15;
    private const CONFIG_PATH = __DIR__ . '/../config/lovlynet.json';

    public function __construct()
    {
        $cfg = self::loadConfig();

        $this->apiKey     = $cfg['api_key']     ?? '';
        $this->nodeNumber = self::extractNodeNumber($cfg['ftn_address'] ?? '');
        $hubHostname      = $cfg['hub_hostname'] ?? '';
        $this->baseUrl    = $hubHostname !== '' ? 'https://' . $hubHostname : '';
    }

    // -------------------------------------------------------------------------
    // Configuration helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when all required credentials are present.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '' && $this->nodeNumber > 0;
    }

    public function getNodeNumber(): int
    {
        return $this->nodeNumber;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getHubAddress(): string
    {
        $cfg = self::loadConfig();
        return (string)($cfg['hub_address'] ?? '');
    }

    public function getAreafixPassword(): string
    {
        $cfg = self::loadConfig();
        return (string)($cfg['areafix_password'] ?? '');
    }

    /**
     * Load and cache the lovlynet.json config.
     */
    private static function loadConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        if (!file_exists(self::CONFIG_PATH)) {
            self::$config = [];
            return [];
        }

        $json = @file_get_contents(self::CONFIG_PATH);
        $data = $json !== false ? json_decode($json, true) : null;
        self::$config = is_array($data) ? $data : [];
        return self::$config;
    }

    /**
     * Extract the node number from an FTN address string like "227:1/400".
     */
    private static function extractNodeNumber(string $ftnAddress): int
    {
        // Format: zone:net/node or zone:net/node.point
        if (preg_match('/\d+:\d+\/(\d+)/', $ftnAddress, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    // -------------------------------------------------------------------------
    // API methods
    // -------------------------------------------------------------------------

    /**
     * Retrieve all echo and file areas for our node, including subscription status.
     *
     * Calls GET /api/areas.php?node_number=N
     *
     * @return array{success:bool, echoareas:array, fileareas:array, ftn_address:string, error?:string}
     */
    public function getAreas(): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        $url      = $this->baseUrl . '/api/areas.php?node_number=' . $this->nodeNumber;
        $response = $this->get($url);

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error'], 'echoareas' => [], 'fileareas' => [], 'ftn_address' => ''];
        }

        $data = $response['data']['data'] ?? [];

        return [
            'success'     => true,
            'echoareas'   => $data['echoareas']  ?? [],
            'fileareas'   => $data['fileareas']  ?? [],
            'ftn_address' => $data['ftn_address'] ?? '',
        ];
    }

    /**
     * Subscribe or unsubscribe our node to/from an area.
     *
     * Calls POST /api/area_subscription.php
     *
     * @param  string $action   'subscribe' or 'unsubscribe'
     * @param  string $areaType 'echo' or 'file'
     * @param  string $areaTag  Area tag (e.g. LVLY_ANNOUNCE)
     * @return array{success:bool, message:string, echoareas?:array, fileareas?:array, error?:string}
     */
    public function setSubscription(string $action, string $areaType, string $areaTag): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        if (!in_array($action, ['subscribe', 'unsubscribe'], true)) {
            return ['success' => false, 'error' => 'Invalid action'];
        }

        if (!in_array($areaType, ['echo', 'file'], true)) {
            return ['success' => false, 'error' => 'Invalid area type'];
        }

        $url     = $this->baseUrl . '/api/area_subscription.php';
        $payload = [
            'node_number' => $this->nodeNumber,
            'action'      => $action,
            'area_type'   => $areaType,
            'area_tag'    => strtoupper($areaTag),
        ];
        $response = $this->post($url, $payload);

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error']];
        }

        $data = $response['data'];

        return [
            'success'   => true,
            'message'   => $data['message'] ?? 'OK',
            'echoareas' => $data['data']['echoareas'] ?? [],
            'fileareas' => $data['data']['fileareas'] ?? [],
        ];
    }

    /**
     * Retrieve the remote AreaFix help text for this node.
     *
     * @return array{success:bool, help?:string, error?:string}
     */
    public function getAreaFixHelp(): array
    {
        return $this->getHelpText('/api/areafix_help.php');
    }

    /**
     * Retrieve the remote FileFix help text for this node.
     *
     * @return array{success:bool, help?:string, error?:string}
     */
    public function getFileFixHelp(): array
    {
        return $this->getHelpText('/api/filefix_help.php');
    }

    // -------------------------------------------------------------------------
    // Internal HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{success:bool, help?:string, error?:string}
     */
    private function getHelpText(string $path): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfigured();
        }

        $url = $this->baseUrl . $path;
        $response = $this->get($url);
        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error']];
        }

        return [
            'success' => true,
            'help' => (string)($response['data']['data']['help'] ?? ''),
        ];
    }

    /**
     * Perform an authenticated GET request.
     *
     * @return array{success:bool, data?:array, error?:string}
     */
    private function get(string $url): array
    {
        return $this->curlRequest('GET', $url);
    }

    /**
     * Perform an authenticated POST request with a JSON body.
     *
     * @param  array $payload
     * @return array{success:bool, data?:array, error?:string}
     */
    private function post(string $url, array $payload): array
    {
        return $this->curlRequest('POST', $url, $payload);
    }

    /**
     * Execute an HTTP request via cURL.
     *
     * @param  string     $method
     * @param  string     $url
     * @param  array|null $payload  JSON-encoded body for POST requests
     * @return array{success:bool, data?:array, error?:string}
     */
    private function curlRequest(string $method, string $url, ?array $payload = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'error' => 'cURL init failed'];
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
        ];

        if ($method === 'POST' && $payload !== null) {
            $json      = json_encode($payload);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body       = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['success' => false, 'error' => 'Network error: ' . ($curlError ?: 'unknown cURL error')];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => "Unexpected response from LovlyNet (HTTP $statusCode)"];
        }

        if ($statusCode >= 400 || !($decoded['success'] ?? false)) {
            $msg = $decoded['message'] ?? "LovlyNet API error (HTTP $statusCode)";
            return ['success' => false, 'error' => $msg];
        }

        return ['success' => true, 'data' => $decoded];
    }

    /**
     * Standard "not configured" response.
     */
    private function notConfigured(): array
    {
        return [
            'success'     => false,
            'error'       => 'LovlyNet credentials not found. Ensure config/lovlynet.json exists and contains api_key, ftn_address, and hub_hostname.',
            'echoareas'   => [],
            'fileareas'   => [],
            'ftn_address' => '',
        ];
    }
}
