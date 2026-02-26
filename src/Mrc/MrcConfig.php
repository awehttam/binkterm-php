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

namespace BinktermPHP\Mrc;

use BinktermPHP\Binkp\Config\BinkpConfig;

/**
 * MRC (Multi Relay Chat) Configuration Manager
 *
 * Manages configuration for MRC chat system following MRC Protocol v1.3.
 * Configuration is stored in config/mrc.json and includes server connection,
 * BBS identification, connection timeouts, and room settings.
 */
class MrcConfig
{
    private static $instance;
    private $config;
    private $configPath;

    private function __construct()
    {
        $this->configPath = __DIR__ . '/../../config/mrc.json';
        $this->loadConfig();
    }

    /**
     * Get singleton instance
     *
     * @return MrcConfig
     */
    public static function getInstance(): MrcConfig
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load configuration from file
     * Creates default config if file doesn't exist
     *
     * @throws \Exception If JSON is invalid
     */
    private function loadConfig(): void
    {
        if (!file_exists($this->configPath)) {
            $this->createDefaultConfig();
        }

        $json = file_get_contents($this->configPath);
        $this->config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in MRC configuration file: ' . json_last_error_msg());
        }
    }

    /**
     * Create default configuration file
     */
    private function createDefaultConfig(): void
    {
        $defaultConfig = [
            'enabled' => false,
            'server' => [
                'host' => 'mrc.bottomlessabyss.net',
                'port' => 50000,
                'use_ssl' => true,
                'ssl_port' => 50001
            ],
            'bbs' => [
                'name' => 'BinktermPHP BBS',
                'platform' => 'BINKTERMPHP/Linux64/1.3.0',
                'sysop' => 'Sysop'
            ],
            'connection' => [
                'auto_reconnect' => true,
                'reconnect_delay' => 30,
                'ping_interval' => 60,
                'handshake_timeout' => 1,
                'keepalive_timeout' => 125
            ],
            'rooms' => [
                'default' => 'lobby',
                'auto_join' => ['lobby']
            ],
            'messages' => [
                'max_length' => 140,
                'history_limit' => 1000,
                'prune_after_days' => 30
            ],
            'info' => [
                'website' => '',
                'telnet' => '',
                'ssh' => '',
                'description' => ''
            ]
        ];

        $configDir = dirname($this->configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        file_put_contents($this->configPath, json_encode($defaultConfig, JSON_PRETTY_PRINT));
        $this->config = $defaultConfig;
    }

    /**
     * Save configuration to file
     */
    public function saveConfig(): void
    {
        file_put_contents($this->configPath, json_encode($this->config, JSON_PRETTY_PRINT));
    }

    /**
     * Reload configuration from file
     */
    public function reloadConfig(): void
    {
        $this->loadConfig();
    }

    // ========================================
    // Global Settings
    // ========================================

    /**
     * Check if MRC is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? false;
    }

    /**
     * Set enabled status
     *
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled): void
    {
        $this->config['enabled'] = $enabled;
        $this->saveConfig();
    }

    // ========================================
    // Server Configuration
    // ========================================

    /**
     * Get MRC server host
     *
     * @return string
     */
    public function getServerHost(): string
    {
        return $this->config['server']['host'] ?? 'mrc.bottomlessabyss.net';
    }

    /**
     * Get MRC server port (based on SSL setting)
     *
     * @return int
     */
    public function getServerPort(): int
    {
        if ($this->useSSL()) {
            return $this->config['server']['ssl_port'] ?? 50001;
        }
        return $this->config['server']['port'] ?? 50000;
    }

    /**
     * Check if SSL should be used
     *
     * @return bool
     */
    public function useSSL(): bool
    {
        return $this->config['server']['use_ssl'] ?? true;
    }

    // ========================================
    // BBS Identification
    // ========================================

    /**
     * Get BBS name (max 64 chars)
     *
     * Uses binkp system name as the primary source (the canonical BBS identity),
     * falling back to the mrc.json bbs.name if binkp config is unavailable.
     *
     * @return string
     */
    public function getBbsName(): string
    {
        return substr($this->sanitizeBbsName($this->config['bbs']['name'] ?? 'BinktermPHP BBS'), 0, 64);
    }

    /**
     * Strip characters that MRC servers reject from a BBS name.
     * Removes apostrophes and other punctuation that cause server-side rejections.
     *
     * @param string $name
     * @return string
     */
    private function sanitizeBbsName(string $name): string
    {
        return str_replace(["'", '"', '`'], '', $name);
    }

    /**
     * Get platform information string
     *
     * @return string
     */
    public function getPlatformInfo(): string
    {
        $configured = trim((string)($this->config['bbs']['platform'] ?? ''));
        if ($configured === '') {
            $os = PHP_OS_FAMILY === 'Windows' ? 'Windows' : 'Linux';
            $arch = PHP_INT_SIZE === 8 ? '64' : '32';
            return "BINKTERMPHP/{$os}{$arch}/1.3.0";
        }
        return $configured;
    }

    /**
     * Get sysop name
     *
     * @return string
     */
    public function getSysop(): string
    {
        return $this->config['bbs']['sysop'] ?? 'Sysop';
    }

    /**
     * Get handshake string for MRC server
     * Format: {BBSName}~{Version}
     *
     * @return string
     */
    public function getHandshakeString(): string
    {
        $bbsName = str_replace('~', '', $this->getBbsName());
        $platform = str_replace('~', '', $this->getPlatformInfo());
        return "{$bbsName}~{$platform}";
    }

    // ========================================
    // Connection Settings
    // ========================================

    /**
     * Check if auto-reconnect is enabled
     *
     * @return bool
     */
    public function getAutoReconnect(): bool
    {
        return $this->config['connection']['auto_reconnect'] ?? true;
    }

    /**
     * Get reconnect delay in seconds
     *
     * @return int
     */
    public function getReconnectDelay(): int
    {
        return $this->config['connection']['reconnect_delay'] ?? 30;
    }

    /**
     * Get ping interval in seconds (how often to expect PING from server)
     *
     * @return int
     */
    public function getPingInterval(): int
    {
        return $this->config['connection']['ping_interval'] ?? 60;
    }

    /**
     * Get handshake timeout in seconds
     *
     * @return int
     */
    public function getHandshakeTimeout(): int
    {
        return $this->config['connection']['handshake_timeout'] ?? 1;
    }

    /**
     * Get keepalive timeout in seconds
     *
     * @return int
     */
    public function getKeepaliveTimeout(): int
    {
        return $this->config['connection']['keepalive_timeout'] ?? 125;
    }

    // ========================================
    // Room Settings
    // ========================================

    /**
     * Get default room name
     *
     * @return string
     */
    public function getDefaultRoom(): string
    {
        return $this->config['rooms']['default'] ?? 'lobby';
    }

    /**
     * Get rooms to auto-join on connect
     *
     * @return array
     */
    public function getAutoJoinRooms(): array
    {
        return $this->config['rooms']['auto_join'] ?? ['lobby'];
    }

    // ========================================
    // Message Settings
    // ========================================

    /**
     * Get maximum message length
     *
     * @return int
     */
    public function getMaxMessageLength(): int
    {
        return $this->config['messages']['max_length'] ?? 140;
    }

    /**
     * Get message history limit per room
     *
     * @return int
     */
    public function getHistoryLimit(): int
    {
        return $this->config['messages']['history_limit'] ?? 1000;
    }

    /**
     * Get days after which to prune old messages
     *
     * @return int
     */
    public function getPruneAfterDays(): int
    {
        return $this->config['messages']['prune_after_days'] ?? 30;
    }

    // ========================================
    // BBS Info (for INFO command responses)
    // ========================================

    /**
     * Get BBS website URL
     *
     * @return string
     */
    public function getWebsite(): string
    {
        return $this->config['info']['website'] ?? '';
    }

    /**
     * Get BBS telnet address
     *
     * @return string
     */
    public function getTelnet(): string
    {
        return $this->config['info']['telnet'] ?? '';
    }

    /**
     * Get BBS SSH address
     *
     * @return string
     */
    public function getSSH(): string
    {
        return $this->config['info']['ssh'] ?? '';
    }

    /**
     * Get BBS description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->config['info']['description'] ?? '';
    }

    // ========================================
    // Full Config Access
    // ========================================

    /**
     * Get full configuration array
     *
     * @return array
     */
    public function getFullConfig(): array
    {
        return $this->config;
    }

    /**
     * Set full configuration array
     *
     * @param array $config
     */
    public function setFullConfig(array $config): void
    {
        $this->config = $config;
        $this->saveConfig();
    }

    /**
     * Update server configuration
     *
     * @param array $settings
     */
    public function setServerConfig(array $settings): void
    {
        if (!isset($this->config['server'])) {
            $this->config['server'] = [];
        }
        $this->config['server'] = array_merge($this->config['server'], $settings);
        $this->saveConfig();
    }

    /**
     * Update BBS configuration
     *
     * @param array $settings
     */
    public function setBbsConfig(array $settings): void
    {
        if (!isset($this->config['bbs'])) {
            $this->config['bbs'] = [];
        }
        $this->config['bbs'] = array_merge($this->config['bbs'], $settings);
        $this->saveConfig();
    }

    /**
     * Update connection configuration
     *
     * @param array $settings
     */
    public function setConnectionConfig(array $settings): void
    {
        if (!isset($this->config['connection'])) {
            $this->config['connection'] = [];
        }
        $this->config['connection'] = array_merge($this->config['connection'], $settings);
        $this->saveConfig();
    }

    /**
     * Update room configuration
     *
     * @param array $settings
     */
    public function setRoomConfig(array $settings): void
    {
        if (!isset($this->config['rooms'])) {
            $this->config['rooms'] = [];
        }
        $this->config['rooms'] = array_merge($this->config['rooms'], $settings);
        $this->saveConfig();
    }

    /**
     * Update message configuration
     *
     * @param array $settings
     */
    public function setMessageConfig(array $settings): void
    {
        if (!isset($this->config['messages'])) {
            $this->config['messages'] = [];
        }
        $this->config['messages'] = array_merge($this->config['messages'], $settings);
        $this->saveConfig();
    }

    /**
     * Update BBS info configuration
     *
     * @param array $settings
     */
    public function setInfoConfig(array $settings): void
    {
        if (!isset($this->config['info'])) {
            $this->config['info'] = [];
        }
        $this->config['info'] = array_merge($this->config['info'], $settings);
        $this->saveConfig();
    }
}
