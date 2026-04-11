<?php

namespace BinktermPHP\AiBot\Middleware;

use BinktermPHP\AiBot\BotContext;
use BinktermPHP\AiBot\BotMiddlewareInterface;
use BinktermPHP\Binkp\Logger;

/**
 * Fetches a URL and injects the response body into the bot's system prompt.
 *
 * The fetched content is cached in the system temp directory for the
 * configured TTL to avoid an outbound HTTP request on every message.
 *
 * WARNING: Every byte injected into the system prompt is sent to the AI
 * provider on every single request and counted as input tokens. Large fetched
 * documents can dramatically increase API costs — a 50 KB page injected on
 * every message can cost orders of magnitude more than a short prompt alone.
 * Always set `max_bytes` to cap the injected content, and monitor spend via
 * the AI Usage dashboard.
 *
 * Configuration:
 *
 *   {
 *     "class": "UrlPromptInjectorMiddleware",
 *     "config": {
 *       "url":       "https://example.com/mybot-context.md",
 *       "position":  "append",
 *       "separator": "\n\n---\n\n",
 *       "ttl":       3600,
 *       "timeout":   5,
 *       "max_bytes": 4000
 *     }
 *   }
 *
 * Options:
 *   url       — URL to fetch (required)
 *   position  — "append" (default) or "prepend"
 *   separator — string between the existing prompt and the fetched content
 *               (default: two newlines)
 *   ttl       — cache lifetime in seconds (default: 3600); set to 0 to disable
 *   timeout   — HTTP request timeout in seconds (default: 5)
 *   max_bytes — truncate injected content to this many bytes (recommended)
 *
 * If the fetch fails or the URL is empty the middleware passes through without
 * modifying the prompt.
 *
 * Debugging: all fetch attempts, cache hits/misses, and failures are written
 * to ai_bot_daemon.log at debug level when a logger is available.
 */
class UrlPromptInjectorMiddleware implements BotMiddlewareInterface
{
    private string   $url;
    private string   $position;
    private string   $separator;
    private int      $ttl;
    private int      $timeout;
    private int      $maxBytes;
    private ?Logger  $logger;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [], ?Logger $logger = null)
    {
        $this->url       = (string)($config['url']       ?? '');
        $this->position  = (string)($config['position']  ?? 'append');
        $this->separator = (string)($config['separator'] ?? "\n\n");
        $this->ttl       = max(0, (int)($config['ttl']     ?? 3600));
        $this->timeout   = max(1, (int)($config['timeout'] ?? 5));
        $this->maxBytes  = isset($config['max_bytes']) ? max(1, (int)$config['max_bytes']) : 0;
        $this->logger    = $logger;
    }

    public function handle(BotContext $ctx, callable $next): void
    {
        if ($this->url === '') {
            $this->logger?->warning('[UrlPromptInjector] No URL configured — skipping');
            $next();
            return;
        }

        $this->logger?->debug('[UrlPromptInjector] Fetching prompt content', [
            'url'      => $this->url,
            'ttl'      => $this->ttl,
            'position' => $this->position,
            'timeout'  => $this->timeout,
        ]);

        $content = $this->fetchCached($this->url);

        if ($content === null) {
            $this->logger?->warning('[UrlPromptInjector] Fetch returned null — system prompt unchanged', [
                'url' => $this->url,
            ]);
            $next();
            return;
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            $this->logger?->warning('[UrlPromptInjector] Fetched content is empty — system prompt unchanged', [
                'url' => $this->url,
            ]);
            $next();
            return;
        }

        if ($this->maxBytes > 0 && strlen($trimmed) > $this->maxBytes) {
            $this->logger?->debug('[UrlPromptInjector] Content truncated to max_bytes', [
                'url'       => $this->url,
                'original'  => strlen($trimmed),
                'max_bytes' => $this->maxBytes,
            ]);
            $trimmed = substr($trimmed, 0, $this->maxBytes);
        }

        $before = strlen($ctx->systemPrompt);

        if ($this->position === 'prepend') {
            $ctx->systemPrompt = $trimmed . $this->separator . $ctx->systemPrompt;
        } else {
            $ctx->systemPrompt = $ctx->systemPrompt . $this->separator . $trimmed;
        }

        $this->logger?->debug('[UrlPromptInjector] System prompt updated', [
            'url'            => $this->url,
            'injected_bytes' => strlen($trimmed),
            'prompt_before'  => $before,
            'prompt_after'   => strlen($ctx->systemPrompt),
            'position'       => $this->position,
        ]);

        $next();
    }

    /**
     * Fetch the URL, serving from a file cache when the entry is still fresh.
     */
    private function fetchCached(string $url): ?string
    {
        $cacheDir  = sys_get_temp_dir() . '/binkterm_bot_cache';
        $cacheFile = $cacheDir . '/' . md5($url) . '.txt';

        if ($this->ttl > 0 && is_readable($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age < $this->ttl) {
                $data = file_get_contents($cacheFile);
                if ($data !== false) {
                    $this->logger?->debug('[UrlPromptInjector] Cache hit', [
                        'url'        => $url,
                        'cache_file' => $cacheFile,
                        'age_secs'   => $age,
                        'ttl'        => $this->ttl,
                        'bytes'      => strlen($data),
                    ]);
                    return $data;
                }
            } else {
                $this->logger?->debug('[UrlPromptInjector] Cache expired, will re-fetch', [
                    'url'      => $url,
                    'age_secs' => $age,
                    'ttl'      => $this->ttl,
                ]);
            }
        } elseif ($this->ttl === 0) {
            $this->logger?->debug('[UrlPromptInjector] Cache disabled (ttl=0), fetching directly', [
                'url' => $url,
            ]);
        }

        $this->logger?->debug('[UrlPromptInjector] Sending HTTP request', [
            'url'     => $url,
            'timeout' => $this->timeout,
        ]);

        $content = $this->fetchUrl($url);

        if ($content === null) {
            $this->logger?->warning('[UrlPromptInjector] HTTP request failed', ['url' => $url]);
            return null;
        }

        $this->logger?->debug('[UrlPromptInjector] HTTP request succeeded', [
            'url'   => $url,
            'bytes' => strlen($content),
        ]);

        if ($this->ttl > 0) {
            if (!is_dir($cacheDir)) {
                if (!@mkdir($cacheDir, 0700, true)) {
                    $this->logger?->warning('[UrlPromptInjector] Could not create cache directory', [
                        'cache_dir' => $cacheDir,
                    ]);
                }
            }
            if (@file_put_contents($cacheFile, $content) === false) {
                $this->logger?->warning('[UrlPromptInjector] Could not write cache file', [
                    'cache_file' => $cacheFile,
                ]);
            } else {
                $this->logger?->debug('[UrlPromptInjector] Content cached', [
                    'cache_file' => $cacheFile,
                    'ttl'        => $this->ttl,
                ]);
            }
        }

        return $content;
    }

    /**
     * Perform the HTTP GET request via curl (preferred) or file_get_contents.
     */
    private function fetchUrl(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'BinktermPHP-BotMiddleware/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body  = curl_exec($ch);
            $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error !== '') {
                $this->logger?->warning('[UrlPromptInjector] curl error', [
                    'url'   => $url,
                    'error' => $error,
                ]);
                return null;
            }

            if ($body === false || $code < 200 || $code >= 300) {
                $this->logger?->warning('[UrlPromptInjector] HTTP error response', [
                    'url'  => $url,
                    'code' => $code,
                ]);
                return null;
            }

            return (string)$body;
        }

        // curl unavailable — fall back to file_get_contents.
        $this->logger?->debug('[UrlPromptInjector] curl not available, using file_get_contents');
        $streamCtx = stream_context_create([
            'http' => [
                'timeout'    => $this->timeout,
                'user_agent' => 'BinktermPHP-BotMiddleware/1.0',
            ],
        ]);
        $result = @file_get_contents($url, false, $streamCtx);
        if ($result === false) {
            $this->logger?->warning('[UrlPromptInjector] file_get_contents failed', ['url' => $url]);
            return null;
        }
        return $result;
    }
}
