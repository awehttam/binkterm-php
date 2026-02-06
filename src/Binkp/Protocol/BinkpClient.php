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


namespace BinktermPHP\Binkp\Protocol;

use BinktermPHP\Binkp\Config\BinkpConfig;

class BinkpClient
{
    private $config;
    private $logger;
    
    public function __construct($config = null, $logger = null)
    {
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->logger = $logger;
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    
    public function log($message, $level = 'INFO')
    {
        if ($this->logger) {
            $this->logger->log($level, "[CLIENT] {$message}");
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] [{$level}] [CLIENT] {$message}\n";
        }
    }
    
    public function connect($address, $hostname = null, $port = null, $password = null)
    {
        $uplink = $this->config->getUplinkByAddress($address);
        if (!$uplink && !$hostname) {
            throw new \Exception("No uplink configuration found for address: {$address}");
        }

        $hostname = $hostname ?: $uplink['hostname'];
        $port = $port ?: ($uplink['port'] ?? 24554);
        $password = $password ?: ($uplink['password'] ?? '');

        $this->log("Connecting to {$hostname}:{$port} ({$address})");
        if ($uplink) {
            $this->log("Uplink domain: " . ($uplink['domain'] ?? 'unknown') .
                ", networks: " . implode(', ', $uplink['networks'] ?? []));
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new \Exception('Failed to create client socket: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->config->getBinkpTimeout(), 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->config->getBinkpTimeout(), 'usec' => 0]);

        $result = socket_connect($socket, $hostname, $port);
        if ($result === false) {
            $error = socket_strerror(socket_last_error($socket));
            if (is_resource($socket)) {
                socket_close($socket);
            }
            throw new \Exception("Failed to connect to {$hostname}:{$port}: {$error}");
        }

        $this->log("Connected to {$hostname}:{$port}");

        try {
            $stream = $this->socketToStream($socket);
            $session = new BinkpSession($stream, true, $this->config);
            $session->setLogger($this->logger);

            // Set the uplink password for this session
            $session->setUplinkPassword($password);

            // Set the current uplink context for packet filtering
            // This ensures only packets destined for this uplink's networks are sent
            if ($uplink) {
                $session->setCurrentUplink($uplink);
            }

            $session->handshake();

            //$this->log("Handshake completed with {$address}");

            if (!$session->processSession()) {
                throw new \Exception('Session processing failed');
            }

            $this->log("Session completed successfully with {$address}");

            $result = [
                'success' => true,
                'remote_address' => $session->getRemoteAddress(),
                'files_sent' => $session->getFilesSent(),
                'files_received' => $session->getFilesReceived(),
                'auth_method' => $session->getAuthMethod()
            ];

            $session->close();
            // Don't close socket here - it's already handled by session->close() via the stream

            return $result;

        } catch (\Exception $e) {
            $this->log("Session failed with {$address}: " . $e->getMessage(), 'ERROR');
            // Don't close socket here either - let the session handle cleanup
            throw $e;
        }
    }
    
    public function pollUplink($address)
    {
        $uplink = $this->config->getUplinkByAddress($address);
        if (!$uplink) {
            throw new \Exception("Uplink not found: {$address}");
        }
        
        if (!($uplink['enabled'] ?? true)) {
            throw new \Exception("Uplink disabled: {$address}");
        }
        
        return $this->connect(
            $address,
            $uplink['hostname'],
            $uplink['port'] ?? 24554,
            $uplink['password'] ?? ''
        );
    }
    
    public function pollAllUplinks($queued_only=false)
    {
        $uplinks = $this->config->getEnabledUplinks();
        $results = [];
        
        foreach ($uplinks as $uplink) {
            $address = $uplink['address'];
            $outboundPath = $this->config->getOutboundPath();
            $files = glob($outboundPath . '/*.pkt');
            if($queued_only && empty($files)){
                $this->log("No spooled packets founnd, skipping polling for $address", 'DEBUG');
                continue;
            }
            try {
                $this->log("Polling uplink: {$address}");
                $result = $this->pollUplink($address);
                $results[$address] = $result;
                $this->log("Successfully polled: {$address}");
                
            } catch (\Exception $e) {
                $this->log("Failed to poll {$address}: " . $e->getMessage(), 'ERROR');
                $results[$address] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    public function testConnection($hostname, $port = 24554, $timeout = 30)
    {
        $this->log("Testing connection to {$hostname}:{$port}");
        
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new \Exception('Failed to create test socket: ' . socket_strerror(socket_last_error()));
        }
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeout, 'usec' => 0]);
        
        $startTime = microtime(true);
        $result = socket_connect($socket, $hostname, $port);
        $connectTime = microtime(true) - $startTime;
        
        if ($result === false) {
            $error = socket_strerror(socket_last_error($socket));
            if (is_resource($socket)) {
                socket_close($socket);
            }
            return [
                'success' => false,
                'error' => $error,
                'connect_time' => $connectTime
            ];
        }
        
        if (is_resource($socket)) {
            socket_close($socket);
        }
        
        return [
            'success' => true,
            'connect_time' => $connectTime
        ];
    }
    
    private function socketToStream($socket)
    {
        $socketResource = socket_export_stream($socket);
        if ($socketResource === false) {
            throw new \Exception('Failed to convert socket to stream');
        }

        // Set stream timeout explicitly - socket options may not carry over on Linux
        $timeout = $this->config->getBinkpTimeout();
        stream_set_timeout($socketResource, $timeout);

        // Ensure blocking mode is set consistently across platforms
        stream_set_blocking($socketResource, true);

        return $socketResource;
    }
    
    public function sendFile($address, $filePath)
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }
        
        $outboundPath = $this->config->getOutboundPath();
        $filename = basename($filePath);
        $destPath = $outboundPath . '/' . $filename;
        
        if ($filePath !== $destPath) {
            if (!copy($filePath, $destPath)) {
                throw new \Exception("Failed to copy file to outbound: {$filePath}");
            }
        }
        
        $this->log("File queued for sending: {$filename}");
        
        return $this->pollUplink($address);
    }
    
    public function getUplinkStatus()
    {
        $uplinks = $this->config->getUplinks();
        $status = [];
        
        foreach ($uplinks as $uplink) {
            $address = $uplink['address'];
            $hostname = $uplink['hostname'];
            $port = $uplink['port'] ?? 24554;
            
            try {
                $testResult = $this->testConnection($hostname, $port, 10);
                $status[$address] = array_merge($uplink, $testResult, [
                    'last_test' => date('Y-m-d H:i:s')
                ]);
                
            } catch (\Exception $e) {
                $status[$address] = array_merge($uplink, [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'last_test' => date('Y-m-d H:i:s')
                ]);
            }
        }
        
        return $status;
    }
}
