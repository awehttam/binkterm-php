<?php

declare(strict_types=1);

use BinktermPHP\AutoFeed\FeedFetch;
use PHPUnit\Framework\TestCase;

/**
 * Auto Feed fetches must verify TLS, accept only http(s) URLs, and check
 * every redirect hop (no non-http(s) targets, no https -> http downgrade).
 *
 * Uses loopback servers forked per test: a plain HTTP server and an HTTPS
 * server with a freshly generated self-signed certificate (which a verifying
 * client must reject). No external network access.
 */
final class AutoFeedFetchTest extends TestCase
{
    private const UA = 'BinktermPHP RSS Poster/1.0';

    /** @var list<int> */
    private array $children = [];
    private ?string $certFile = null;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required for the loopback servers');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        if ($this->certFile !== null) {
            @unlink($this->certFile);
        }
    }

    public function testNonHttpSchemesAreRefusedWithoutFetching(): void
    {
        foreach (['file:///etc/hostname', 'php://filter/resource=/etc/hostname', 'ftp://127.0.0.1/feed.xml', 'not a url'] as $url) {
            try {
                FeedFetch::get($url, self::UA);
                self::fail("must refuse {$url}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('only http:// and https://', $e->getMessage());
            }
        }
    }

    public function testPlainHttpFeedIsFetchedAndRelativeRedirectFollowed(): void
    {
        $port = $this->serve(false, [
            '/old' => "HTTP/1.1 301 Moved\r\nLocation: /feed.xml\r\nContent-Length: 0\r\n\r\n",
            '/feed.xml' => "HTTP/1.1 200 OK\r\nContent-Type: application/rss+xml\r\nContent-Length: 6\r\n\r\n<rss/>",
        ]);

        self::assertSame('<rss/>', FeedFetch::get("http://127.0.0.1:{$port}/old", self::UA));
    }

    public function testRedirectToFileSchemeIsRefused(): void
    {
        $port = $this->serve(false, [
            '/feed.xml' => "HTTP/1.1 302 Found\r\nLocation: file:///etc/hostname\r\nContent-Length: 0\r\n\r\n",
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-http(s) location refused');
        FeedFetch::get("http://127.0.0.1:{$port}/feed.xml", self::UA);
    }

    public function testSelfSignedCertificateIsRejected(): void
    {
        $port = $this->serve(true, [
            '/feed.xml' => "HTTP/1.1 200 OK\r\nContent-Length: 6\r\n\r\n<rss/>",
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/TLS (certificate|host name) verification failed/');
        FeedFetch::get("https://127.0.0.1:{$port}/feed.xml", self::UA);
    }

    public function testFetchRssFeedNoLongerDisablesVerification(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../scripts/rss_poster.php');
        $start = strpos($source, 'function fetchRssFeed(');
        self::assertNotFalse($start);
        $body = substr($source, $start, (int)strpos($source, "\n}\n", $start) - $start);

        self::assertStringContainsString('FeedFetch::get(', $body);
        self::assertStringNotContainsString("'verify_peer' => false", $body);
    }

    /**
     * @param array<string, string> $routes path => raw HTTP response
     */
    private function serve(bool $tls, array $routes): int
    {
        $context = [];
        if ($tls) {
            $this->certFile = $this->selfSignedPem();
            $context = ['ssl' => ['local_cert' => $this->certFile, 'verify_peer' => false]];
        }
        $server = stream_socket_server(
            ($tls ? 'tls' : 'tcp') . '://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create($context)
        );
        self::assertNotFalse($server, $errstr);
        $port = (int)substr((string)stream_socket_get_name($server, false), strrpos((string)stream_socket_get_name($server, false), ':') + 1);

        $pid = pcntl_fork();
        if ($pid === 0) {
            for ($i = 0; $i < 10; $i++) {
                $conn = @stream_socket_accept($server, 10);
                if ($conn === false) {
                    continue;
                }
                $request = (string)fgets($conn);
                $path = explode(' ', $request)[1] ?? '/';
                fwrite($conn, $routes[$path] ?? "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");
                fclose($conn);
            }
            exit(0);
        }
        fclose($server);
        $this->children[] = $pid;

        return $port;
    }

    private function selfSignedPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        $file = tempnam(sys_get_temp_dir(), 'autofeed-cert-');
        file_put_contents($file, $certPem . $keyPem);

        return $file;
    }
}
