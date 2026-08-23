<?php
/**
 * Synchronet Service Client
 *
 * Talks to the companion Synchronet-side services.ini service
 * (binktermphp-synchronet, https://github.com/awehttam/binktermphp-synchronet)
 * over a one-shot JSON-over-TCP protocol to provision or sync a Synchronet
 * user account before an RLogin door session is launched.
 *
 * Wire protocol (one connection = one request/response, then the connection
 * closes):
 *
 *   Request (one line of JSON):
 *     {"api_key":"<shared secret>","username":"...","real_name":"...","location":"..."}
 *
 *   Response (one line of JSON), success:
 *     {"success":true,"username":"...","user_number":42,"created":true}
 *
 *   Response, failure:
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

    public function __construct(string $host, int $port, string $secret, float $timeout = 5.0)
    {
        $this->host = $host;
        $this->port = $port;
        $this->secret = $secret;
        $this->timeout = $timeout;
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
            (float)($config['timeout'] ?? 5)
        );
    }

    /**
     * Provision or sync a Synchronet user account.
     *
     * Connects, sends one JSON request line, reads one JSON response line,
     * and closes the connection. Transport-level failures (can't connect, no
     * response, malformed response) throw. A protocol-level rejection (e.g.
     * "username invalid") is a normal return with `success` => false — the
     * caller decides how to handle that.
     *
     * @param string $username
     * @param string|null $realName Optional; applied to the account on creation and sync
     * @param string|null $location Optional; applied to the account on creation and sync
     * @return array{success: bool, username: ?string, user_number: ?int, created: ?bool, error: ?string}
     * @throws \RuntimeException On connection or protocol transport failure
     */
    public function provision(string $username, ?string $realName = null, ?string $location = null): array
    {
        $target = "tcp://{$this->host}:{$this->port}";
        $socket = @stream_socket_client($target, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new \RuntimeException("Could not connect to {$this->host}:{$this->port} - $errstr ($errno)");
        }

        stream_set_timeout($socket, (int)ceil($this->timeout));

        $request = json_encode([
            'api_key' => $this->secret,
            'username' => $username,
            'real_name' => $realName,
            'location' => $location,
        ]);

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

        return [
            'success' => !empty($response['success']),
            'username' => isset($response['username']) ? (string)$response['username'] : null,
            'user_number' => isset($response['user_number']) ? (int)$response['user_number'] : null,
            'created' => isset($response['created']) ? (bool)$response['created'] : null,
            'error' => isset($response['error']) ? (string)$response['error'] : null,
        ];
    }
}
