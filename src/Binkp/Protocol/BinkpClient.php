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
use BinktermPHP\Config;
use BinktermPHP\Nodelist\NodelistManager;
use BinktermPHP\Crashmail\CrashmailService;

class BinkpClient
{
    private $config;
    private $logger;
    /** @var array<int,array{filename:string,password:?string}> FREQ requests to send in next session */
    private array $pendingFreqRequests = [];
    /** @var string[] Extra files to transmit in the next session (e.g. .req files) */
    private array $extraOutboundFiles = [];
    
    public function __construct($config = null, $logger = null)
    {
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->logger = $logger;
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Queue an outbound FREQ request to be sent as M_GET during the next connect() session.
     *
     * @param string      $filename Filename or magic name to request from the remote
     * @param string|null $password Optional area password required by the remote
     */
    public function addFreqRequest(string $filename, ?string $password = null): void
    {
        $this->pendingFreqRequests[] = ['filename' => $filename, 'password' => $password];
    }

    /**
     * Queue a file to be sent during the next connect() session, bypassing the
     * outbound directory and uplink-destination filtering.  Used for .req files.
     *
     * @param string $path Absolute path to the file to send
     */
    public function addExtraFile(string $path): void
    {
        $this->extraOutboundFiles[] = $path;
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
            // Not a configured uplink — resolve via nodelist or binkp_zone DNS
            $resolved = $this->resolveNodeHostname($address);
            if ($resolved === null) {
                throw new \Exception(
                    "Cannot resolve hostname for {$address}: not a configured uplink and not found in nodelist or binkp_zone DNS"
                );
            }
            $hostname = $resolved['hostname'];
            $port     = $resolved['port'];
            // Anonymous session — no password
            $password = '';
        }

        $hostname = $hostname ?: $uplink['hostname'];
        $port = $port ?: ($uplink['port'] ?? 24554);
        $password = $password !== null ? $password : ($uplink['password'] ?? '');

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
        $this->configureTcpNoDelay($socket);

        $result = socket_connect($socket, $hostname, $port);
        if ($result === false) {
            $error = socket_strerror(socket_last_error($socket));
            if (is_resource($socket)) {
                socket_close($socket);
            }
            throw new \Exception("Failed to connect to {$hostname}:{$port}: {$error}");
        }

        $this->log("Connected to {$hostname}:{$port}");
        $peerIp = null;
        if (@socket_getpeername($socket, $connectedIp) && filter_var($connectedIp, FILTER_VALIDATE_IP)) {
            $peerIp = $connectedIp;
        }

        try {
            $stream = $this->socketToStream($socket);
            $session = new BinkpSession($stream, true, $this->config);
            $session->setLogger($this->logger);
            $sessionLogger = new \BinktermPHP\Binkp\SessionLogger();
            $sessionLogger->startSession($address, $peerIp, 'secure', false);
            $session->setSessionLogger($sessionLogger);

            // Set the uplink password for this session
            $session->setUplinkPassword($password);

            // Pass any queued FREQ requests into the session
            foreach ($this->pendingFreqRequests as $req) {
                $session->addFreqRequest($req['filename'], $req['password']);
            }

            // Pass any extra outbound files into the session
            foreach ($this->extraOutboundFiles as $path) {
                $session->addExtraFile($path);  // also registers in extraOutboundFilesByName
            }

            // Set the current uplink context for packet filtering and address advertisement.
            // If no exact uplink match exists (e.g. connecting to an arbitrary node via
            // freq_pickup), find the uplink whose network covers the destination so we
            // advertise the correct "me" address rather than the primary/first AKA.
            if ($uplink) {
                $session->setCurrentUplink($uplink);
            } else {
                $routedUplink = $this->config->getUplinkForDestination($address);
                if ($routedUplink) {
                    // Use routed uplink only for AKA/domain selection, not authentication.
                    // Clear crypt so CRAM-MD5 is not attempted against a non-uplink node.
                    $routedUplink['crypt'] = false;
                    $session->setCurrentUplink($routedUplink);
                }
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

            $sessionLogger->endSession('success');

            $session->close();
            // Don't close socket here - it's already handled by session->close() via the stream

            return $result;

        } catch (\Exception $e) {
            $this->log("Session failed with {$address}: " . $e->getMessage(), 'ERROR');
            if (isset($sessionLogger)) {
                $sessionLogger->endSession('failed', $e->getMessage());
            }
            // Don't close socket here either - let the session handle cleanup
            throw $e;
        }
    }

    /**
     * Authenticate with a remote node without entering the file transfer phase.
     *
     * @param string      $address  FTN address of the remote node
     * @param string|null $hostname Optional hostname override
     * @param int|null    $port     Optional port override
     * @param string|null $password Optional password override
     *
     * @return array{success:bool,remote_address:mixed,auth_method:string}
     */
    public function authTest($address, $hostname = null, $port = null, $password = null)
    {
        $uplink = $this->config->getUplinkByAddress($address);

        if (!$uplink && !$hostname) {
            $resolved = $this->resolveNodeHostname($address);
            if ($resolved === null) {
                throw new \Exception(
                    "Cannot resolve hostname for {$address}: not a configured uplink and not found in nodelist or binkp_zone DNS"
                );
            }
            $hostname = $resolved['hostname'];
            $port = $resolved['port'];
            $password = '';
        }

        $hostname = $hostname ?: $uplink['hostname'];
        $port = $port ?: ($uplink['port'] ?? 24554);
        $password = $password !== null ? $password : ($uplink['password'] ?? '');

        $this->log("Running auth-only test against {$hostname}:{$port} ({$address})");

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new \Exception('Failed to create client socket: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->config->getBinkpTimeout(), 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->config->getBinkpTimeout(), 'usec' => 0]);
        $this->configureTcpNoDelay($socket);

        $result = socket_connect($socket, $hostname, $port);
        if ($result === false) {
            $error = socket_strerror(socket_last_error($socket));
            if (is_resource($socket)) {
                socket_close($socket);
            }
            throw new \Exception("Failed to connect to {$hostname}:{$port}: {$error}");
        }

        $session = null;
        $peerIp = null;
        if (@socket_getpeername($socket, $connectedIp) && filter_var($connectedIp, FILTER_VALIDATE_IP)) {
            $peerIp = $connectedIp;
        }

        try {
            $stream = $this->socketToStream($socket);
            $session = new BinkpSession($stream, true, $this->config);
            $session->setLogger($this->logger);
            $sessionLogger = new \BinktermPHP\Binkp\SessionLogger();
            $sessionLogger->startSession($address, $peerIp, 'secure', false);
            $session->setSessionLogger($sessionLogger);
            $session->setUplinkPassword($password);

            if ($uplink) {
                $session->setCurrentUplink($uplink);
            } else {
                $routedUplink = $this->config->getUplinkForDestination($address);
                if ($routedUplink) {
                    $routedUplink['crypt'] = false;
                    $session->setCurrentUplink($routedUplink);
                }
            }

            $session->handshake();
            $sessionLogger->endSession('success');

            return [
                'success' => true,
                'remote_address' => $session->getRemoteAddress(),
                'auth_method' => $session->getAuthMethod(),
            ];
        } catch (\Exception $e) {
            $this->log("Auth-only test failed with {$address}: " . $e->getMessage(), 'ERROR');
            if (isset($sessionLogger)) {
                $sessionLogger->endSession('failed', $e->getMessage());
            }
            throw $e;
        } finally {
            if ($session) {
                $session->close();
            } elseif (is_resource($socket)) {
                socket_close($socket);
            }
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
                    'error_code' => 'errors.binkp.uplink.poll_failed',
                    'error' => 'Failed to poll BinkP uplink'
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
        $this->configureTcpNoDelay($socket);
        
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
                'error_code' => 'errors.binkp.connection_test_failed',
                'error' => 'Failed to test BinkP connection',
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
    
    /**
     * Resolve an FTN address to a hostname and port for a non-uplink node.
     *
     * Resolution order:
     *   1. Nodelist IBN/INA flags via NodelistManager
     *   2. binkp_zone DNS lookup via the uplink that routes this destination
     *
     * @param string $address FTN address (e.g. "1:123/456" or "3:770/220")
     * @return array{hostname:string,port:int,source:string}|null  Connection info, or null if unresolvable
     */
    private function resolveNodeHostname(string $address): ?array
    {
        // Step 1: nodelist lookup
        try {
            $nodelistManager = new NodelistManager();
            $info = $nodelistManager->getCrashRouteInfo($address);
            if ($info && !empty($info['hostname'])) {
                $this->log("Resolved {$address} via nodelist: {$info['hostname']}:{$info['port']}", 'DEBUG');
                return ['hostname' => $info['hostname'], 'port' => $info['port'], 'source' => 'nodelist'];
            }
        } catch (\Exception $e) {
            $this->log("Nodelist lookup failed for {$address}: " . $e->getMessage(), 'DEBUG');
        }

        // Step 2: binkp_zone DNS via the uplink that would route this address
        $routedUplink = $this->config->getUplinkForDestination($address);
        if ($routedUplink && !empty($routedUplink['binkp_zone'])) {
            try {
                $crashmail = new CrashmailService();
                $resolved  = $crashmail->resolveDestination($address);
                if (!empty($resolved['hostname'])) {
                    $this->log(
                        "Resolved {$address} via binkp_zone ({$routedUplink['binkp_zone']}): {$resolved['hostname']}:{$resolved['port']}",
                        'DEBUG'
                    );
                    return ['hostname' => $resolved['hostname'], 'port' => $resolved['port'], 'source' => 'binkp_zone'];
                }
            } catch (\Exception $e) {
                $this->log("binkp_zone lookup failed for {$address}: " . $e->getMessage(), 'DEBUG');
            }
        }

        return null;
    }

    private function socketToStream($socket)
    {
        // Once the session uses fread()/fwrite() on the exported stream, rely on
        // stream_set_timeout() only. Mixing SO_RCVTIMEO/SO_SNDTIMEO with stream
        // I/O can produce false handshake timeouts even when a full frame is
        // already waiting in the socket buffer.
        $this->clearSocketTimeouts($socket);

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

    private function clearSocketTimeouts($socket): void
    {
        @socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 0]);
        @socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 0, 'usec' => 0]);
    }

    private function configureTcpNoDelay($socket): void
    {
        if (!defined('TCP_NODELAY')) {
            return;
        }

        $raw = strtolower(trim((string)Config::env('BINKP_TCP_NODELAY', 'true')));
        $enabled = !in_array($raw, ['0', 'false', 'no', 'off'], true);
        @socket_set_option($socket, SOL_TCP, TCP_NODELAY, $enabled ? 1 : 0);
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
                    'error_code' => 'errors.binkp.status_failed',
                    'error' => 'Failed to load BinkP status',
                    'last_test' => date('Y-m-d H:i:s')
                ]);
            }
        }
        
        return $status;
    }
}
