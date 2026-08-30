<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
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

namespace BinktermPHP\Nntp;

/**
 * Behaviour configuration for the NNTP server, stored in config/nntp.json.
 *
 * Transport settings (bind host, ports, TLS cert paths) are NOT here — those live
 * in .env and are read directly by scripts/nntp_server.php, because the web process
 * cannot write .env and a daemon restart is required to change them anyway.
 *
 * This file is written only by the admin daemon (get_nntp_config / set_nntp_config),
 * never directly by a web route.
 */
class NntpConfig
{
    private static ?NntpConfig $instance = null;

    /** @var array<string,mixed> */
    private array $config;

    private string $configPath;

    private function __construct()
    {
        $this->configPath = __DIR__ . '/../../config/nntp.json';
        $this->loadConfig();
    }

    public static function getInstance(): NntpConfig
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Build a detached instance from an array without reading or writing any file.
     * Intended for tests and for callers that already hold a config array.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config): NntpConfig
    {
        $instance = new self();
        $instance->config = $instance->sanitize($config);
        // Detach from disk: saveConfig() would still target the real path, so
        // callers of fromArray() must not call setFullConfig()/saveConfig().
        return $instance;
    }

    /**
     * Built-in defaults, also used as the shape for config/nntp.json.example.
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            // Master switch. Disabled by default; the daemon refuses to serve when false.
            'enabled' => false,
            // Phase 2. When false the daemon advertises no POST capability and rejects POST.
            'allow_posting' => false,
            // How the newsgroup hierarchy prefix is derived: "network_name" (from the
            // networks table display name) or "domain" (the raw echoareas.domain slug).
            'newsgroup_prefix_mode' => 'network_name',
            // Per-authenticated-user posting rate limits (Phase 2).
            'posts_per_minute' => 10,
            'posts_per_hour' => 60,
            // Max echoareas a single NNTP post may target via a multi-group Newsgroups:
            // header. Over-limit posts are rejected (441), not truncated (Phase 2).
            'max_cross_post_areas' => 5,
            // Max concurrent connections from one source IP, enforced pre-fork.
            'max_connections_per_ip' => 3,
            // When false, AUTHINFO/POST on the plaintext port require STARTTLS first
            // (483 otherwise). Enabling this exposes account passwords in cleartext.
            'allow_plaintext_auth' => false,
            // Quote-style conversion at the gateway boundary (see NntpQuoteStyle):
            //   "off"      - serve/store bodies verbatim
            //   "outbound" - FSC-0032 " XX> " -> "> " on served articles only (default)
            //   "both"     - also convert "> " -> " XX> " on inbound POSTs
            'quote_style_conversion' => 'outbound',
        ];
    }

    private function loadConfig(): void
    {
        $defaults = $this->loadExample() ?? self::defaults();

        if (!file_exists($this->configPath)) {
            $this->config = $defaults;
            return;
        }

        $json = file_get_contents($this->configPath);
        $decoded = $json === false ? null : json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON in NNTP configuration file: ' . json_last_error_msg());
        }

        $this->config = array_replace($defaults, $decoded);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadExample(): ?array
    {
        $examplePath = dirname($this->configPath) . '/nntp.json.example';
        if (!file_exists($examplePath)) {
            return null;
        }

        $json = file_get_contents($examplePath);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    public function reloadConfig(): void
    {
        $this->loadConfig();
    }

    public function saveConfig(): void
    {
        file_put_contents($this->configPath, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * @return array<string,mixed>
     */
    public function getFullConfig(): array
    {
        return $this->config;
    }

    /**
     * Replace the full config (used by the admin daemon). Unknown keys are dropped
     * and missing keys fall back to defaults so the file stays well-formed.
     *
     * @param array<string,mixed> $config
     */
    public function setFullConfig(array $config): void
    {
        $this->config = $this->sanitize($config);
        $this->saveConfig();
    }

    /**
     * Coerce an incoming config array to known keys and types.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function sanitize(array $config): array
    {
        $defaults = self::defaults();
        $merged = array_replace($defaults, array_intersect_key($config, $defaults));

        $merged['enabled'] = (bool)$merged['enabled'];
        $merged['allow_posting'] = (bool)$merged['allow_posting'];
        $merged['allow_plaintext_auth'] = (bool)$merged['allow_plaintext_auth'];
        $merged['newsgroup_prefix_mode'] = in_array($merged['newsgroup_prefix_mode'], ['network_name', 'domain'], true)
            ? $merged['newsgroup_prefix_mode']
            : 'network_name';
        $merged['posts_per_minute'] = max(0, (int)$merged['posts_per_minute']);
        $merged['posts_per_hour'] = max(0, (int)$merged['posts_per_hour']);
        $merged['max_cross_post_areas'] = max(1, (int)$merged['max_cross_post_areas']);
        $merged['max_connections_per_ip'] = max(1, (int)$merged['max_connections_per_ip']);
        $merged['quote_style_conversion'] = in_array($merged['quote_style_conversion'], ['off', 'outbound', 'both'], true)
            ? $merged['quote_style_conversion']
            : 'outbound';

        return $merged;
    }

    // ── Typed getters ────────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return (bool)($this->config['enabled'] ?? false);
    }

    public function isPostingAllowed(): bool
    {
        return (bool)($this->config['allow_posting'] ?? false);
    }

    public function getNewsgroupPrefixMode(): string
    {
        $mode = $this->config['newsgroup_prefix_mode'] ?? 'network_name';
        return $mode === 'domain' ? 'domain' : 'network_name';
    }

    public function getPostsPerMinute(): int
    {
        return max(0, (int)($this->config['posts_per_minute'] ?? 10));
    }

    public function getPostsPerHour(): int
    {
        return max(0, (int)($this->config['posts_per_hour'] ?? 60));
    }

    public function getMaxCrossPostAreas(): int
    {
        return max(1, (int)($this->config['max_cross_post_areas'] ?? 5));
    }

    public function getMaxConnectionsPerIp(): int
    {
        return max(1, (int)($this->config['max_connections_per_ip'] ?? 3));
    }

    public function isPlaintextAuthAllowed(): bool
    {
        return (bool)($this->config['allow_plaintext_auth'] ?? false);
    }

    /**
     * One of "off", "outbound", "both".
     */
    public function getQuoteStyleConversion(): string
    {
        $mode = $this->config['quote_style_conversion'] ?? 'outbound';
        return in_array($mode, ['off', 'outbound', 'both'], true) ? $mode : 'outbound';
    }

    /**
     * True when served articles should have FSC-0032 quoting rewritten to `> `.
     */
    public function shouldConvertOutboundQuotes(): bool
    {
        return in_array($this->getQuoteStyleConversion(), ['outbound', 'both'], true);
    }

    /**
     * True when inbound POSTed articles should have `> ` quoting rewritten to FSC-0032.
     */
    public function shouldConvertInboundQuotes(): bool
    {
        return $this->getQuoteStyleConversion() === 'both';
    }
}
