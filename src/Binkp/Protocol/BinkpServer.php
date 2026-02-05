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
use BinktermPHP\Admin\AdminDaemonClient;

class BinkpServer
{
    private $config;
    private $logger;
    private $serverSocket;
    private $isRunning;
    private $connections;
    private $childPids;
    private $useFork;

    public function __construct($config = null, $logger = null)
    {
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->logger = $logger;
        $this->connections = [];
        $this->childPids = [];
        $this->isRunning = false;
        // Use forking if pcntl is available (Linux/Unix)
        $this->useFork = function_exists('pcntl_fork');
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    
    public function log($message, $level = 'INFO')
    {
        if ($this->logger) {
            $this->logger->log($level, "[SERVER] {$message}");
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] [{$level}] [SERVER] {$message}\n";
        }
    }
    
    public function start()
    {
        $bindAddress = $this->config->getBindAddress();
        $port = $this->config->getBinkpPort();
        
        $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->serverSocket === false) {
            throw new \Exception('Failed to create server socket: ' . socket_strerror(socket_last_error()));
        }
        
        socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        if (!socket_bind($this->serverSocket, $bindAddress, $port)) {
            throw new \Exception('Failed to bind server socket: ' . socket_strerror(socket_last_error($this->serverSocket)));
        }
        
        if (!socket_listen($this->serverSocket, 5)) {
            throw new \Exception('Failed to listen on server socket: ' . socket_strerror(socket_last_error($this->serverSocket)));
        }
        
        $this->isRunning = true;
        $this->log("Binkp server started on {$bindAddress}:{$port}");
        
        $this->run();
    }
    
    private function run()
    {
        // Install signal handler for child processes if using fork
        if ($this->useFork && function_exists('pcntl_signal')) {
            pcntl_signal(SIGCHLD, SIG_IGN); // Auto-reap children
        }

        while ($this->isRunning) {
            $readSockets = [$this->serverSocket];
            $writeSockets = [];
            $exceptSockets = [];

            $result = socket_select($readSockets, $writeSockets, $exceptSockets, 1);

            if ($result === false) {
                $error = socket_last_error();
                // EINTR (4) is expected when signals interrupt select
                if ($error !== 4) {
                    $this->log('Socket select failed: ' . socket_strerror($error), 'ERROR');
                    break;
                }
                continue;
            }

            // Reap finished child processes
            $this->reapChildren();

            if ($result === 0) {
                continue;
            }

            foreach ($readSockets as $socket) {
                if ($socket === $this->serverSocket) {
                    $this->acceptConnection();
                }
            }
        }

        $this->cleanup();
    }
    
    private function acceptConnection()
    {
        $clientSocket = socket_accept($this->serverSocket);
        if ($clientSocket === false) {
            $this->log('Failed to accept connection: ' . socket_strerror(socket_last_error($this->serverSocket)), 'ERROR');
            return;
        }

        // Check max connections (count active child processes)
        $this->reapChildren();
        if (count($this->childPids) >= $this->config->getMaxConnections()) {
            $this->log('Maximum connections reached, rejecting new connection', 'WARNING');
            $this->sendBusy($clientSocket, 'Maximum connections reached');
            socket_close($clientSocket);
            return;
        }

        socket_getpeername($clientSocket, $clientIP);
        $connectionId = uniqid();

        $this->log("New connection from {$clientIP} (ID: {$connectionId})");

        if ($this->useFork) {
            // Fork a child process to handle this connection
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed, handle synchronously
                $this->log("Fork failed, handling connection synchronously", 'WARNING');
                $this->handleConnectionSync($clientSocket, $connectionId, $clientIP);
            } elseif ($pid === 0) {
                // Child process
                // Close the server socket in child - we don't need it
                socket_close($this->serverSocket);

                // Handle the connection
                $this->handleConnectionSync($clientSocket, $connectionId, $clientIP);

                // Exit child process
                exit(0);
            } else {
                // Parent process
                // Close the client socket in parent - child owns it now
                socket_close($clientSocket);
                $this->childPids[$pid] = $connectionId;
                $this->log("Forked child PID {$pid} for connection {$connectionId}", 'DEBUG');
            }
        } else {
            // No fork available (Windows), handle synchronously
            $this->handleConnectionSync($clientSocket, $connectionId, $clientIP);
        }
    }

    /**
     * Handle a connection synchronously (used by child process or when fork unavailable)
     */
    private function handleConnectionSync($clientSocket, $connectionId, $clientIP)
    {
        try {
            $stream = $this->socketToStream($clientSocket);
            $session = new BinkpSession($stream, false, $this->config);
            $session->setLogger($this->logger);

            if ($session->handshake()) {
                $this->log("Handshake completed for {$clientIP} ({$connectionId})");

                if ($session->processSession()) {
                    $this->log("Session completed for {$clientIP} ({$connectionId})");
                    try {
                        $client = new AdminDaemonClient();
                        $client->processPackets();
                    } catch (\Exception $e) {
                        $this->log("Failed to trigger packet processing: " . $e->getMessage(), 'ERROR');
                    }
                } else {
                    $this->log("Session failed for {$clientIP} ({$connectionId})", 'ERROR');
                }
            } else {
                $this->log("Handshake failed for {$clientIP} ({$connectionId})", 'ERROR');
            }

            $session->close();
        } catch (\Exception $e) {
            $this->log("Connection error for {$clientIP} ({$connectionId}): " . $e->getMessage(), 'ERROR');
        }

        if (is_resource($clientSocket)) {
            socket_close($clientSocket);
        }
    }

    /**
     * Reap any finished child processes
     */
    private function reapChildren()
    {
        if (!$this->useFork) {
            return;
        }

        foreach ($this->childPids as $pid => $connectionId) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid) {
                // Child has exited
                unset($this->childPids[$pid]);
                $this->log("Child PID {$pid} ({$connectionId}) exited", 'DEBUG');
            } elseif ($result === -1) {
                // Error or child doesn't exist
                unset($this->childPids[$pid]);
            }
        }
    }
    
    private function sendBusy($socket, $message)
    {
        try {
            $stream = $this->socketToStream($socket);
            $frame = BinkpFrame::createCommand(BinkpFrame::M_BSY, $message);
            $frame->writeToSocket($stream);
        } catch (\Exception $e) {
            $this->log("Failed to send busy message: " . $e->getMessage(), 'ERROR');
        }
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
    
    public function stop()
    {
        $this->isRunning = false;
        $this->log('Server stop requested');
    }
    
    private function cleanup()
    {
        // Terminate any remaining child processes
        if ($this->useFork) {
            foreach ($this->childPids as $pid => $connectionId) {
                $this->log("Terminating child PID {$pid}", 'DEBUG');
                posix_kill($pid, SIGTERM);
            }
            // Give children time to exit gracefully
            usleep(100000);
            // Force kill any remaining
            foreach ($this->childPids as $pid => $connectionId) {
                posix_kill($pid, SIGKILL);
            }
            $this->childPids = [];
        }

        if ($this->serverSocket) {
            socket_close($this->serverSocket);
            $this->serverSocket = null;
        }

        $this->log('Server stopped');
    }

    public function getConnectionCount()
    {
        $this->reapChildren();
        return count($this->childPids);
    }
}

