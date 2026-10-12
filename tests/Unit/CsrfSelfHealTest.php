<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\TelnetUtils;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';

/**
 * Stale-CSRF self-heal for the terminal server's API client and the
 * read-only token endpoint it uses.
 *
 * The per-user CSRF token is rotated by every login of the same user, which
 * leaves a running Telnet/SSH session holding a stale copy. A mutating
 * TelnetUtils::apiRequest() that is rejected with
 * errors.auth.invalid_csrf_token now re-syncs the token once from
 * GET /api/auth/csrf-token and retries. Exercised against a forked loopback
 * HTTP server.
 */
final class CsrfSelfHealTest extends TestCase
{
    private ?int $child = null;
    private string $log = '';

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('curl_init')) {
            self::markTestSkipped('pcntl and curl required');
        }
        TelnetUtils::setCsrfToken(null);
        $this->log = tempnam(sys_get_temp_dir(), 'csrf-heal-');
    }

    protected function tearDown(): void
    {
        if ($this->child !== null) {
            posix_kill($this->child, SIGKILL);
            pcntl_waitpid($this->child, $status);
        }
        @unlink($this->log);
        TelnetUtils::setCsrfToken(null);
    }

    public function testStaleTokenIsResyncedOnceAndTheRequestRetried(): void
    {
        $base = $this->server('fresh-token');
        TelnetUtils::setCsrfToken('stale-token');

        $resp = TelnetUtils::apiRequest($base, 'POST', '/api/thing', ['x' => 1], 'sess', 3, 'stale-token');

        self::assertSame(200, $resp['status']);
        self::assertSame('fresh-token', TelnetUtils::getCsrfToken());
        self::assertSame(['POST /api/thing stale-token', 'GET /api/auth/csrf-token -', 'POST /api/thing fresh-token'], $this->requests());
    }

    public function testResyncedTokenIsUsedForLaterRequests(): void
    {
        $base = $this->server('fresh-token');
        TelnetUtils::setCsrfToken('fresh-token');

        $resp = TelnetUtils::apiRequest($base, 'POST', '/api/thing', null, 'sess', 3, 'stale-token-from-caller');

        self::assertSame(200, $resp['status']);
        self::assertSame(['POST /api/thing fresh-token'], $this->requests());
    }

    public function testOnlyStaleCsrfRejectionsOfMutatingRequestsAreHealed(): void
    {
        self::assertTrue(TelnetUtils::shouldHealStaleCsrf(true, 403, ['error_code' => 'errors.auth.invalid_csrf_token'], false, 's', 't'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(false, 403, ['error_code' => 'errors.auth.invalid_csrf_token'], false, 's', 't'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, ['error_code' => 'errors.auth.forbidden'], false, 's', 't'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 400, ['error_code' => 'errors.auth.invalid_csrf_token'], false, 's', 't'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, ['error_code' => 'errors.auth.invalid_csrf_token'], true, 's', 't'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, ['error_code' => 'errors.auth.invalid_csrf_token'], false, null, 't'));
        self::assertFalse(TelnetUtils::shouldHealStaleCsrf(true, 403, ['error_code' => 'errors.auth.invalid_csrf_token'], false, 's', null));
    }

    public function testTokenEndpointIsAuthenticatedReadOnlyGet(): void
    {
        $routes = (string)file_get_contents(__DIR__ . '/../../routes/api-routes.php');
        $start = strpos($routes, "SimpleRouter::get('/auth/csrf-token'");
        self::assertNotFalse($start, 'GET /api/auth/csrf-token route exists');
        $body = substr($routes, $start, (int)strpos($routes, '});', $start) - $start);

        self::assertStringContainsString('RouteHelper::requireAuth()', $body);
        self::assertStringContainsString("->getValue(\$userId, 'csrf_token')", $body);
        self::assertStringNotContainsString('setValue', $body, 'endpoint must not rotate the token');
    }

    public function testTerminalLoginSeedsTheApiClientToken(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/BbsSession.php');
        self::assertStringContainsString("TelnetUtils::setCsrfToken(\$state['csrf_token']);", $source);
    }

    /** @return list<string> "METHOD path token" lines the server saw */
    private function requests(): array
    {
        return array_values(array_filter(explode("\n", (string)file_get_contents($this->log))));
    }

    private function server(string $currentToken): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $name = (string)stream_socket_get_name($server, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        $log = $this->log;

        $pid = pcntl_fork();
        if ($pid === 0) {
            while ($conn = @stream_socket_accept($server, 10)) {
                $head = '';
                while (($line = fgets($conn)) !== false && $line !== "\r\n") {
                    $head .= $line;
                }
                if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m) && (int)$m[1] > 0) {
                    fread($conn, (int)$m[1]);
                }
                [$method, $path] = explode(' ', $head);
                $token = preg_match('/X-CSRF-Token:\s*(\S+)/i', $head, $t) ? $t[1] : '-';
                file_put_contents($log, "{$method} {$path} {$token}\n", FILE_APPEND);
                if ($path === '/api/auth/csrf-token') {
                    $body = json_encode(['success' => true, 'csrf_token' => $currentToken]);
                    $status = '200 OK';
                } elseif ($token === $currentToken) {
                    $body = json_encode(['success' => true]);
                    $status = '200 OK';
                } else {
                    $body = json_encode(['success' => false, 'error_code' => 'errors.auth.invalid_csrf_token']);
                    $status = '403 Forbidden';
                }
                fwrite($conn, "HTTP/1.1 {$status}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n{$body}");
                fclose($conn);
            }
            exit(0);
        }
        fclose($server);
        $this->child = $pid;

        return "http://127.0.0.1:{$port}";
    }
}
