<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
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


namespace BinktermPHP\Binkp\Config;

use BinktermPHP\FtnRouter;

class BinkpConfig
{
    private static $instance;
    private $config;
    private $configPath;
    
    private function __construct()
    {
        $this->configPath = __DIR__ . '/../../../config/binkp.json';
        $this->loadConfig();
    }
    
    private function getProjectRoot()
    {
        return realpath(__DIR__ . '/../../..');
    }
    
    private function resolvePath($path)
    {
        // If path is already absolute, return as-is
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows - check for drive letter
            if (preg_match('/^[A-Za-z]:/', $path)) {
                return $path;
            }
        } else {
            // Unix-like - check for leading slash
            if (strpos($path, '/') === 0) {
                return $path;
            }
        }
        
        // Relative path - resolve relative to project root
        $projectRoot = $this->getProjectRoot();
        return $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function loadConfig()
    {
        if (!file_exists($this->configPath)) {
            $this->createDefaultConfig();
        }
        
        $json = file_get_contents($this->configPath);
        $this->config = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in binkp configuration file');
        }
    }
    
    private function createDefaultConfig()
    {
        $defaultConfig = [
            'system' => [
                'name' => 'BinktermPHP System',
                'address' => '1:123/456',
                'sysop' => 'System Operator',
                'location' => 'Unknown Location',
                'hostname' => 'localhost',
                'origin' => '',
                'timezone' => 'UTC'
            ],
            'binkp' => [
                'port' => 24554,
                'timeout' => 300,
                'max_connections' => 10,
                'bind_address' => '0.0.0.0',
                'inbound_path' => 'data/inbound',
                'outbound_path' => 'data/outbound',
                'preserve_processed_packets' => false
            ],
            'uplinks' => []
        ];
        
        $configDir = dirname($this->configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        file_put_contents($this->configPath, json_encode($defaultConfig, JSON_PRETTY_PRINT));
        $this->config = $defaultConfig;
    }
    
    public function getSystemName()
    {
        return $this->config['system']['name'] ?? 'BinktermPHP System';
    }
    
    public function getSystemAddress()
    {
        return $this->config['system']['address'] ?? '1:999/999';
    }
    
    public function getSystemSysop()
    {
        return $this->config['system']['sysop'] ?? 'System Operator';
    }
    
    public function getSystemLocation()
    {
        return $this->config['system']['location'] ?? 'Unknown Location';
    }
    
    public function getSystemHostname()
    {
        return $this->config['system']['hostname'] ?? 'localhost';
    }
    
    public function getSystemTimezone()
    {
        return $this->config['system']['timezone'] ?? 'UTC';
    }
    
    public function getSystemOrigin()
    {
        return $this->config['system']['origin'] ?? '';
    }
    
    public function getBinkpPort()
    {
        return $this->config['binkp']['port'] ?? 24554;
    }
    
    public function getBinkpTimeout()
    {
        return $this->config['binkp']['timeout'] ?? 300;
    }
    
    public function getMaxConnections()
    {
        return $this->config['binkp']['max_connections'] ?? 10;
    }
    
    public function getBindAddress()
    {
        return $this->config['binkp']['bind_address'] ?? '0.0.0.0';
    }
    
    public function getInboundPath()
    {
        $relativePath = $this->config['binkp']['inbound_path'] ?? 'data/inbound';
        $path = $this->resolvePath($relativePath);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        return $path;
    }
    
    public function getOutboundPath()
    {
        $relativePath = $this->config['binkp']['outbound_path'] ?? 'data/outbound';
        $path = $this->resolvePath($relativePath);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        return $path;
    }
    
    public function getPreserveProcessedPackets()
    {
        return $this->config['binkp']['preserve_processed_packets'] ?? false;
    }
    
    public function getProcessedPacketsPath()
    {
        $inboundPath = $this->getInboundPath();
        $processedPath = $inboundPath . DIRECTORY_SEPARATOR . 'processed';
        if (!is_dir($processedPath)) {
            mkdir($processedPath, 0755, true);
        }
        return $processedPath;
    }

    public function getRoutingTable()
    {
        $rt = new FtnRouter();
        foreach($this->getUplinks() as $uplink) {
            $networks=$uplink['networks'];
            $address=$uplink['address'];
            foreach($networks as $network)
                $rt->addRoute($network, $address);
        }
        return $rt;
    }

    public function getMyAddresses()
    {
        $ret=[];
        foreach($this->getUplinks() as $uplink){
            $me =  $uplink['me'];
            if($me)
                $ret[] = $me;
        }
        return $ret;
    }

    /**
     * Get all addresses with their network domains
     *
     * @return array Array of ['address' => string, 'domain' => string] entries
     */
    public function getMyAddressesWithDomains(): array
    {
        $ret = [];
        foreach ($this->getUplinks() as $uplink) {
            $me = $uplink['me'] ?? null;
            $domain = $uplink['domain'] ?? 'unknown';
            if ($me) {
                $ret[] = [
                    'address' => $me,
                    'domain' => $domain
                ];
            }
        }
        return $ret;
    }

    /**
     * Check if an address belongs to this system
     *
     * @param string $address FTN address to check
     * @return bool True if this is one of our addresses
     */
    public function isMyAddress(string $address): bool
    {
        if (empty($address)) {
            return false;
        }

        // Check system address
        if ($address === $this->getSystemAddress()) {
            return true;
        }

        // Check all "me" addresses from uplinks
        return in_array($address, $this->getMyAddresses());
    }

    public function getMyAddressByDomain($domain)
    {

        foreach($this->getUplinks() as $uplink){
            if(!strcasecmp($uplink['domain'], $domain)){
                return $uplink['me'];
            }
        }
        return false;
    }

    /** Return a domain based on the address.
     * @param string $address
     * @return string|false
     */
    public function getDomainByAddress(string $address)
    {
        $ret=[];
        foreach($this->getUplinks() as $uplink){
            $rt = new FtnRouter();
            $networks=$uplink['networks'];
            foreach($networks as $network)
                $rt->addRoute($network, $address);

            $r = $rt->routeAddress($address,false);
            if($r)
                return $uplink['domain'];

        }
        return false;
    }

    public function getUplinks()
    {
        return $this->config['uplinks'] ?? [];
    }
    
    public function getUplinkByAddress($address)
    {
        foreach ($this->getUplinks() as $uplink) {
            if ($uplink['address'] === $address) {
                return $uplink;
            }
        }
        return null;
    }
    
    public function getEnabledUplinks()
    {
        return array_filter($this->getUplinks(), function($uplink) {
            return $uplink['enabled'] ?? true;
        });
    }
    
    public function getDefaultUplink()
    {
        // First try to find an uplink marked as default
        foreach ($this->getUplinks() as $uplink) {
            if (($uplink['default'] ?? false) && ($uplink['enabled'] ?? true)) {
                return $uplink;
            }
        }
        
        // Fall back to first enabled uplink
        $enabledUplinks = $this->getEnabledUplinks();
        return !empty($enabledUplinks) ? $enabledUplinks[0] : null;
    }
    
    public function getDefaultUplinkAddress()
    {
        $defaultUplink = $this->getDefaultUplink();
        return $defaultUplink ? $defaultUplink['address'] : null;
    }
    
    public function getPasswordForAddress($address)
    {
        $uplink = $this->getUplinkByAddress($address);
        return $uplink['password'] ?? '';
    }

    public function getPktPasswordForAddress($address)
    {
        $uplink = $this->getUplinkByAddress($address);
        return $uplink['pkt_password'] ?? '';
    }
    
    public function addUplink($address, $hostname, $port = 24554, $password = '', $options = [])
    {
        $uplink = array_merge([
            'address' => $address,
            'hostname' => $hostname,
            'port' => $port,
            'password' => $password,
            'enabled' => true,
            'compression' => false,
            'crypt' => false,
            'poll_schedule' => '0 */4 * * *'
        ], $options);
        
        $this->config['uplinks'][] = $uplink;
        $this->saveConfig();
    }
    
    public function removeUplink($address)
    {
        $this->config['uplinks'] = array_filter($this->config['uplinks'], function($uplink) use ($address) {
            return $uplink['address'] !== $address;
        });
        $this->config['uplinks'] = array_values($this->config['uplinks']);
        $this->saveConfig();
    }
    
    public function updateUplink($address, $updates)
    {
        foreach ($this->config['uplinks'] as &$uplink) {
            if ($uplink['address'] === $address) {
                $uplink = array_merge($uplink, $updates);
                break;
            }
        }
        $this->saveConfig();
    }
    
    public function setSystemConfig($name = null, $address = null, $sysop = null, $location = null, $hostname = null, $origin = null, $timezone = null)
    {
        if ($name !== null) $this->config['system']['name'] = $name;
        if ($address !== null) $this->config['system']['address'] = $address;
        if ($sysop !== null) $this->config['system']['sysop'] = $sysop;
        if ($location !== null) $this->config['system']['location'] = $location;
        if ($hostname !== null) $this->config['system']['hostname'] = $hostname;
        if ($origin !== null) $this->config['system']['origin'] = $origin;
        if ($timezone !== null) $this->config['system']['timezone'] = $timezone;
        
        $this->saveConfig();
    }
    
    public function setBinkpConfig($port = null, $timeout = null, $maxConnections = null, $bindAddress = null, $preserveProcessedPackets = null)
    {
        if ($port !== null) $this->config['binkp']['port'] = $port;
        if ($timeout !== null) $this->config['binkp']['timeout'] = $timeout;
        if ($maxConnections !== null) $this->config['binkp']['max_connections'] = $maxConnections;
        if ($bindAddress !== null) $this->config['binkp']['bind_address'] = $bindAddress;
        if ($preserveProcessedPackets !== null) $this->config['binkp']['preserve_processed_packets'] = (bool)$preserveProcessedPackets;
        
        $this->saveConfig();
    }

    public function setPreserveProcessedPackets(?bool $preserve): void
    {
        if ($preserve !== null) {
            $this->config['binkp']['preserve_processed_packets'] = (bool)$preserve;
            $this->saveConfig();
        }
    }
    
    private function saveConfig()
    {
        file_put_contents($this->configPath, json_encode($this->config, JSON_PRETTY_PRINT));
    }
    
    public function getFullConfig()
    {
        return $this->config;
    }

    public function setFullConfig(array $config): void
    {
        $this->config = $config;
        $this->saveConfig();
    }

    public function getBinkpConfig(): array
    {
        return $this->config['binkp'] ?? [];
    }

    public function getSystemConfig(): array
    {
        return $this->config['system'] ?? [];
    }
    
    public function reloadConfig()
    {
        $this->loadConfig();
    }

    public function getUplinkAddressForDomain(mixed $domain)
    {
        foreach ($this->getUplinks() as $uplink) {
            if($uplink['domain'] == $domain){
                return trim($uplink['address']);
            }
        }
        return false;
    }

    /**
     * Get the uplink configuration that should handle a given destination address.
     *
     * Builds a single routing table from all uplinks so that the most specific
     * matching pattern wins regardless of uplink order in the config. A specific
     * route such as "1:123/456.12" on one uplink will correctly override a
     * wildcard "1:*\/*" on another uplink.
     *
     * @param string $destAddr Destination FTN address (e.g., "1:123/456")
     * @return array|null The uplink configuration array, or null if no route found
     */
    public function getUplinkForDestination(string $destAddr): ?array
    {
        // Build one combined router across all uplinks so FtnRouter's
        // specificity ordering (point > node > net > zone > default) applies
        // globally, not just within each uplink's own pattern list.
        $rt = new FtnRouter();
        foreach ($this->getUplinks() as $uplink) {
            foreach ($uplink['networks'] ?? [] as $network) {
                $rt->addRoute($network, $uplink['address']);
            }
        }

        $matchedAddress = $rt->routeAddress($destAddr);
        if ($matchedAddress === null) {
            return null;
        }

        // Return the uplink whose address won the route match
        foreach ($this->getUplinks() as $uplink) {
            if ($uplink['address'] === $matchedAddress) {
                return $uplink;
            }
        }

        return null;
    }

    /**
     * Get our "me" address for the uplink that handles a given destination.
     *
     * @param string $destAddr Destination FTN address
     * @return string|false Our address for the matched uplink, or false if no route
     */
    public function getOriginAddressByDestination(string $destAddr)
    {
        $uplink = $this->getUplinkForDestination($destAddr);
        return $uplink ? ($uplink['me'] ?? false) : false;
    }

    /**
     * Check if a destination address should be routed through a specific uplink.
     *
     * @param string $destAddr Destination FTN address
     * @param array $uplink The uplink configuration to check against
     * @return bool True if the destination should be routed through this uplink
     */
    public function isDestinationForUplink(string $destAddr, array $uplink): bool
    {
        $rt = new FtnRouter();
        $networks = $uplink['networks'] ?? [];
        foreach ($networks as $network) {
            $rt->addRoute($network, $uplink['address']);
        }

        $r = $rt->routeAddress($destAddr, false);
        return $r !== null;
    }

    /**
     * Get uplink by domain name.
     *
     * @param string $domain The network domain (e.g., "fidonet", "testnet")
     * @return array|null The uplink configuration, or null if not found
     */
    public function getUplinkByDomain(string $domain): ?array
    {
        foreach ($this->getUplinks() as $uplink) {
            if (strcasecmp($uplink['domain'] ?? '', $domain) === 0) {
                return $uplink;
            }
        }
        return null;
    }

    /**
     * Check if Markdown is allowed for a given domain.
     */
    public function isMarkdownAllowedForDomain(string $domain): bool
    {
        $uplink = $this->getUplinkByDomain($domain);
        return !empty($uplink['allow_markdown']);
    }

    /**
     * Check if Markdown is allowed for a given destination address.
     */
    public function isMarkdownAllowedForDestination(string $destAddr): bool
    {
        if (empty($destAddr)) {
            return false;
        }

        if ($this->isMyAddress($destAddr)) {
            return true;
        }

        $uplink = $this->getUplinkForDestination($destAddr);
        return $uplink ? !empty($uplink['allow_markdown']) : false;
    }

    /**
     * Get all uplinks for a specific domain
     *
     * @param string $domain Domain name
     * @return array Array of uplink configurations (only enabled uplinks)
     */
    public function getUplinksForDomain(string $domain): array
    {
        $matchingUplinks = [];

        foreach ($this->getUplinks() as $uplink) {
            // Match uplinks by domain (case-insensitive)
            if (strcasecmp($uplink['domain'] ?? '', $domain) === 0) {
                // Only include enabled uplinks
                if (!isset($uplink['enabled']) || $uplink['enabled'] === true) {
                    $matchingUplinks[] = $uplink;
                }
            }
        }

        return $matchingUplinks;
    }

    // ========================================
    // Security Configuration (Insecure Sessions)
    // ========================================

    /**
     * Check if insecure inbound sessions are allowed
     */
    public function getAllowInsecureInbound(): bool
    {
        return $this->config['security']['allow_insecure_inbound'] ?? false;
    }

    /**
     * Check if insecure sessions are receive-only (cannot pick up mail)
     */
    public function getInsecureReceiveOnly(): bool
    {
        return $this->config['security']['insecure_inbound_receive_only'] ?? true;
    }

    /**
     * Check if insecure sessions require node to be in allowlist
     */
    public function getRequireAllowlistForInsecure(): bool
    {
        return $this->config['security']['require_allowlist_for_insecure'] ?? false;
    }

    /**
     * Get maximum insecure sessions per hour per address (rate limiting)
     */
    public function getMaxInsecureSessionsPerHour(): int
    {
        return $this->config['security']['max_insecure_sessions_per_hour'] ?? 10;
    }

    /**
     * Check if plain text password fallback is allowed when CRAM-MD5 is available.
     * When false, remotes must use CRAM-MD5 if we sent a challenge.
     */
    public function getAllowPlaintextFallback(): bool
    {
        return $this->config['security']['allow_plaintext_fallback'] ?? true;
    }

    // ========================================
    // Crashmail Configuration
    // ========================================

    /**
     * Check if crashmail processing is enabled
     */
    public function getCrashmailEnabled(): bool
    {
        return $this->config['crashmail']['enabled'] ?? true;
    }

    /**
     * Get maximum delivery attempts for crashmail
     */
    public function getCrashmailMaxAttempts(): int
    {
        return $this->config['crashmail']['max_attempts'] ?? 3;
    }

    /**
     * Get retry interval in minutes for failed crashmail delivery
     */
    public function getCrashmailRetryInterval(): int
    {
        return $this->config['crashmail']['retry_interval_minutes'] ?? 15;
    }

    /**
     * Check if nodelist should be used for crash routing
     */
    public function getCrashmailUseNodelist(): bool
    {
        return $this->config['crashmail']['use_nodelist_for_routing'] ?? true;
    }

    /**
     * Get fallback port for crash delivery
     */
    public function getCrashmailFallbackPort(): int
    {
        return $this->config['crashmail']['fallback_port'] ?? 24554;
    }

    /**
     * Check if insecure connections are allowed for crash delivery
     */
    public function getCrashmailAllowInsecure(): bool
    {
        return $this->config['crashmail']['allow_insecure_crash_delivery'] ?? true;
    }


    /**
     * Update security configuration
     */
    public function setSecurityConfig(array $settings): void
    {
        if (!isset($this->config['security'])) {
            $this->config['security'] = [];
        }
        $this->config['security'] = array_merge($this->config['security'], $settings);
        $this->saveConfig();
    }

    /**
     * Update crashmail configuration
     */
    public function setCrashmailConfig(array $settings): void
    {
        if (!isset($this->config['crashmail'])) {
            $this->config['crashmail'] = [];
        }
        $this->config['crashmail'] = array_merge($this->config['crashmail'], $settings);
        $this->saveConfig();
    }

}

