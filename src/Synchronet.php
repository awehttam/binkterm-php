<?php
/**
 * Synchronet Service Client
 *
 * Talks to the companion Synchronet-side services.ini service
 * (binktermphp-synchronet, https://github.com/awehttam/binktermphp-synchronet)
 * over a one-shot JSON-over-TCP protocol to provision/sync a Synchronet user
 * account and to list installed doors before an RLogin door session is
 * launched or imported.
 *
 * Wire protocol (one connection = one request/response, then the connection
 * closes). Every request carries an "action" field ("provision" or
 * "list_doors"); it may be omitted, defaulting to "provision".
 *
 * The connection is wrapped in TLS by default (controlled by the "tls" key
 * in the config file / constructor arg -- omit it, or set it explicitly to
 * true, to stay on TLS; set it to false only for a deliberately plaintext
 * setup). This matches the Synchronet-side `Options = TLS` flag on the
 * `services.ini` section -- both sides must agree, since a TLS client
 * talking to a plaintext-only service (or vice versa) will simply fail to
 * connect. Peer certificate verification defaults to off
 * ("tls_verify_peer": false) since this service typically runs on a
 * LAN/localhost link between two systems the same sysop controls, using a
 * self-signed certificate; set "tls_verify_peer": true (and optionally
 * "tls_cafile") once a CA-signed or pinned certificate is in place.
 *
 *   Request (one line of JSON), provision:
 *     {"action":"provision","api_key":"<shared secret>","username":"...","real_name":"...","location":"..."}
 *
 *   Response, provision success:
 *     {"success":true,"username":"...","user_number":42,"created":true}
 *
 *   Request, list doors:
 *     {"action":"list_doors","api_key":"<shared secret>"}
 *
 *   Response, list_doors success:
 *     {"success":true,"doors":[{"code":"...","name":"...","sec_code":"...","sec_name":"...","description":"...","author":"...","categories":["..."]}]}
 *
 *   "description", "author", and "categories" are best-effort -- the
 *   Synchronet-side service opportunistically reads them from the door's
 *   install-xtrn.ini (see binkterm_sync_service.js) and omits any that
 *   aren't available.
 *
 *   Response, any action, failure:
 *     {"success":false,"error":"reason"}
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class Synchronet
{
    private string $host;
    private int $port;
    private string $secret;
    private float $timeout;
    private bool $tls;
    private bool $tlsVerifyPeer;
    private ?string $tlsCafile;

    public function __construct(
        string $host,
        int $port,
        string $secret,
        float $timeout = 5.0,
        bool $tls = true,
        bool $tlsVerifyPeer = false,
        ?string $tlsCafile = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->secret = $secret;
        $this->timeout = $timeout;
        $this->tls = $tls;
        $this->tlsVerifyPeer = $tlsVerifyPeer;
        $this->tlsCafile = $tlsCafile;
    }

    /**
     * Build a client from a rlogin_synchronet_service.json-shaped config file.
     *
     * @param string $configPath
     * @return self
     * @throws \RuntimeException If the file is missing or malformed
     */
    public static function fromConfigFile(string $configPath): self
    {
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Service config not found: $configPath");
        }

        $config = json_decode((string)file_get_contents($configPath), true);
        if (!is_array($config) || empty($config['host']) || empty($config['port'])) {
            throw new \RuntimeException("Invalid service config: $configPath");
        }

        return new self(
            (string)$config['host'],
            (int)$config['port'],
            (string)($config['secret'] ?? ''),
            (float)($config['timeout'] ?? 5),
            (bool)($config['tls'] ?? true),
            !empty($config['tls_verify_peer']),
            !empty($config['tls_cafile']) ? (string)$config['tls_cafile'] : null
        );
    }

    /**
     * Provision or sync a Synchronet user account.
     *
     * A protocol-level rejection (e.g. "username invalid") is a normal
     * return with `success` => false — the caller decides how to handle that.
     *
     * @param string $username
     * @param string|null $realName Optional; applied to the account on creation and sync
     * @param string|null $location Optional; applied to the account on creation and sync
     * @return array{success: bool, username: ?string, user_number: ?int, created: ?bool, error: ?string}
     * @throws \RuntimeException On connection or protocol transport failure
     */
    public function provision(string $username, ?string $realName = null, ?string $location = null): array
    {
        $response = $this->sendRequest([
            'action' => 'provision',
            'username' => $username,
            'real_name' => $realName,
            'location' => $location,
        ]);

        return [
            'success' => !empty($response['success']),
            'username' => isset($response['username']) ? (string)$response['username'] : null,
            'user_number' => isset($response['user_number']) ? (int)$response['user_number'] : null,
            'created' => isset($response['created']) ? (bool)$response['created'] : null,
            'error' => isset($response['error']) ? (string)$response['error'] : null,
        ];
    }

    /**
     * List installed external programs (doors) on the remote Synchronet system.
     *
     * @return array{success: bool, doors: array, error: ?string} `doors` is a
     *   list of ['code' => string, 'name' => string, 'sec_code' => ?string, 'sec_name' => ?string,
     *   'description' => ?string, 'author' => ?string, 'categories' => string[]]
     * @throws \RuntimeException On connection or protocol transport failure
     */
    public function listDoors(): array
    {
        $response = $this->sendRequest(['action' => 'list_doors']);

        $doors = [];
        if (!empty($response['success']) && is_array($response['doors'] ?? null)) {
            foreach ($response['doors'] as $door) {
                if (!is_array($door) || empty($door['code'])) {
                    continue;
                }
                $categories = is_array($door['categories'] ?? null)
                    ? array_values(array_filter(array_map('strval', $door['categories'])))
                    : [];
                $doors[] = [
                    'code' => (string)$door['code'],
                    'name' => isset($door['name']) ? (string)$door['name'] : (string)$door['code'],
                    'sec_code' => isset($door['sec_code']) ? (string)$door['sec_code'] : null,
                    'sec_name' => isset($door['sec_name']) ? (string)$door['sec_name'] : null,
                    'description' => !empty($door['description']) ? (string)$door['description'] : null,
                    'author' => !empty($door['author']) ? (string)$door['author'] : null,
                    'categories' => $categories,
                ];
            }
        }

        return [
            'success' => !empty($response['success']),
            'doors' => $doors,
            'error' => isset($response['error']) ? (string)$response['error'] : null,
        ];
    }

    /**
     * Connect, send one JSON request line (with the shared secret attached),
     * read one JSON response line, and close the connection.
     *
     * @param array $payload Request fields other than api_key
     * @return array Decoded JSON response
     * @throws \RuntimeException On connection or protocol transport failure
     */
    private function sendRequest(array $payload): array
    {
        $scheme = $this->tls ? 'tls' : 'tcp';
        $target = "{$scheme}://{$this->host}:{$this->port}";

        $context = null;
        if ($this->tls) {
            $sslOptions = [
                'verify_peer' => $this->tlsVerifyPeer,
                'verify_peer_name' => $this->tlsVerifyPeer,
                'allow_self_signed' => !$this->tlsVerifyPeer,
            ];
            if ($this->tlsCafile !== null) {
                $sslOptions['cafile'] = $this->tlsCafile;
            }
            $context = stream_context_create(['ssl' => $sslOptions]);
        }

        $socket = $context !== null
            ? @stream_socket_client($target, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context)
            : @stream_socket_client($target, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new \RuntimeException("Could not connect to {$this->host}:{$this->port} - $errstr ($errno)");
        }

        stream_set_timeout($socket, (int)ceil($this->timeout));

        $request = json_encode(array_merge(['api_key' => $this->secret], $payload));

        if ($request === false || fwrite($socket, $request . "\n") === false) {
            fclose($socket);
            throw new \RuntimeException('Failed to write request to service');
        }

        $responseLine = fgets($socket);
        fclose($socket);

        if ($responseLine === false) {
            throw new \RuntimeException('No response from service (timeout or connection closed)');
        }

        $response = json_decode(trim($responseLine), true);
        if (!is_array($response)) {
            throw new \RuntimeException("Invalid response from service: $responseLine");
        }

        return $response;
    }
}
