<?php

namespace BinktermPHP\Binkp\Config;

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
        return $this->config['system']['address'] ?? '1:1/0';
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
    
    public function setSystemConfig($name = null, $address = null, $sysop = null, $location = null, $hostname = null, $origin = null)
    {
        if ($name !== null) $this->config['system']['name'] = $name;
        if ($address !== null) $this->config['system']['address'] = $address;
        if ($sysop !== null) $this->config['system']['sysop'] = $sysop;
        if ($location !== null) $this->config['system']['location'] = $location;
        if ($hostname !== null) $this->config['system']['hostname'] = $hostname;
        if ($origin !== null) $this->config['system']['origin'] = $origin;
        
        $this->saveConfig();
    }
    
    public function setBinkpConfig($port = null, $timeout = null, $maxConnections = null, $bindAddress = null)
    {
        if ($port !== null) $this->config['binkp']['port'] = $port;
        if ($timeout !== null) $this->config['binkp']['timeout'] = $timeout;
        if ($maxConnections !== null) $this->config['binkp']['max_connections'] = $maxConnections;
        if ($bindAddress !== null) $this->config['binkp']['bind_address'] = $bindAddress;
        
        $this->saveConfig();
    }
    
    private function saveConfig()
    {
        file_put_contents($this->configPath, json_encode($this->config, JSON_PRETTY_PRINT));
    }
    
    public function getFullConfig()
    {
        return $this->config;
    }
    
    public function reloadConfig()
    {
        $this->loadConfig();
    }
}