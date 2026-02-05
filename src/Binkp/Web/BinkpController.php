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


namespace BinktermPHP\Binkp\Web;

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Connection\Scheduler;
use BinktermPHP\Binkp\Queue\InboundQueue;
use BinktermPHP\Binkp\Queue\OutboundQueue;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Auth;

class BinkpController
{
    private $config;
    private $logger;
    
    public function __construct()
    {
        $this->config = BinkpConfig::getInstance();
        $this->logger = new Logger(\BinktermPHP\Config::getLogPath('binkp_web.log'));
    }
    
    public function getStatus()
    {
        $client = new BinkpClient($this->config, $this->logger);
        $scheduler = new Scheduler($this->config, $this->logger);
        $inboundQueue = new InboundQueue($this->config, $this->logger);
        $outboundQueue = new OutboundQueue($this->config, $this->logger);
        
        $uplinkStatus = $client->getUplinkStatus();
        $scheduleStatus = $scheduler->getScheduleStatus();
        $inboundStats = $inboundQueue->getStats();
        $outboundStats = $outboundQueue->getStats();
        
        return [
            'system' => [
                'address' => $this->config->getSystemAddress(),
                'sysop' => $this->config->getSystemSysop(),
                'location' => $this->config->getSystemLocation(),
                'hostname' => $this->config->getSystemHostname(),
                'port' => $this->config->getBinkpPort()
            ],
            'uplinks' => $uplinkStatus,
            'schedule' => $scheduleStatus,
            'queues' => [
                'inbound' => $inboundStats,
                'outbound' => $outboundStats
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    public function pollUplink($address)
    {
        try {
            $client = new BinkpClient($this->config, $this->logger);
            $result = $client->pollUplink($address);
            
            return [
                'success' => true,
                'result' => $result
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function pollAllUplinks()
    {
        try {
            $client = new BinkpClient($this->config, $this->logger);
            $results = $client->pollAllUplinks();
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function testConnection($hostname, $port = 24554)
    {
        try {
            $client = new BinkpClient($this->config, $this->logger);
            $result = $client->testConnection($hostname, $port);
            
            return [
                'success' => true,
                'result' => $result
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function getUplinks()
    {
        return [
            'success' => true,
            'uplinks' => $this->config->getUplinks()
        ];
    }
    
    public function addUplink($data)
    {
        try {
            $address = $data['address'] ?? '';
            $hostname = $data['hostname'] ?? '';
            $port = (int) ($data['port'] ?? 24554);
            $password = $data['password'] ?? '';
            
            if (empty($address) || empty($hostname)) {
                throw new \Exception('Address and hostname are required');
            }
            
            $options = [
                'enabled' => $data['enabled'] ?? true,
                'compression' => $data['compression'] ?? false,
                'crypt' => $data['crypt'] ?? false,
                'poll_schedule' => $data['poll_schedule'] ?? '0 */4 * * *'
            ];
            
            $this->config->addUplink($address, $hostname, $port, $password, $options);
            
            return [
                'success' => true,
                'message' => 'Uplink added successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function updateUplink($address, $data)
    {
        try {
            $this->config->updateUplink($address, $data);
            
            return [
                'success' => true,
                'message' => 'Uplink updated successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function removeUplink($address)
    {
        try {
            $this->config->removeUplink($address);
            
            return [
                'success' => true,
                'message' => 'Uplink removed successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function getInboundFiles()
    {
        try {
            $inboundQueue = new InboundQueue($this->config, $this->logger);
            $files = $inboundQueue->getInboundFiles();
            $errorFiles = $inboundQueue->getErrorFiles();
            
            return [
                'success' => true,
                'pending' => $files,
                'errors' => $errorFiles
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function getOutboundFiles()
    {
        try {
            $outboundQueue = new OutboundQueue($this->config, $this->logger);
            $files = $outboundQueue->getOutboundFiles();
            
            return [
                'success' => true,
                'files' => $files
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function processInbound()
    {
        try {
            $inboundQueue = new InboundQueue($this->config, $this->logger);
            $results = $inboundQueue->processInbound();
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function processOutbound()
    {
        try {
            $outboundQueue = new OutboundQueue($this->config, $this->logger);
            $results = $outboundQueue->processOutbound();
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function deleteOutboundFile($filename)
    {
        try {
            $outboundQueue = new OutboundQueue($this->config, $this->logger);
            $outboundQueue->deleteOutboundFile($filename);
            
            return [
                'success' => true,
                'message' => 'File deleted successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function retryErrorFile($filename)
    {
        try {
            $inboundQueue = new InboundQueue($this->config, $this->logger);
            $inboundQueue->retryErrorFile($filename);
            
            return [
                'success' => true,
                'message' => 'File retry initiated successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function getLogs($lines = 100)
    {
        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $logs = $client->getLogs((int)$lines);
            
            return [
                'success' => true,
                'logs' => $logs
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function getConfig()
    {
        return [
            'success' => true,
            'config' => $this->config->getFullConfig()
        ];
    }
    
    public function updateConfig($section, $data)
    {
        try {
            switch ($section) {
                case 'system':
                    $this->config->setSystemConfig(
                        null,                          // $name (not in form)
                        $data['address'] ?? null,      // $address
                        $data['sysop'] ?? null,        // $sysop
                        $data['location'] ?? null,     // $location
                        $data['hostname'] ?? null,     // $hostname
                        null                           // $origin (not in form)
                    );
                    break;
                    
                case 'binkp':
                    $this->config->setBinkpConfig(
                        isset($data['port']) ? (int) $data['port'] : null,
                        isset($data['timeout']) ? (int) $data['timeout'] : null,
                        isset($data['max_connections']) ? (int) $data['max_connections'] : null,
                        $data['bind_address'] ?? null
                    );
                    break;
                    
                default:
                    throw new \Exception('Invalid configuration section');
            }
            
            return [
                'success' => true,
                'message' => 'Configuration updated successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

