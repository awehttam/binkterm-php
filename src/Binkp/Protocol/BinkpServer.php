<?php

namespace Binktest\Binkp\Protocol;

use Binktest\Binkp\Config\BinkpConfig;

class BinkpServer
{
    private $config;
    private $logger;
    private $serverSocket;
    private $isRunning;
    private $connections;
    
    public function __construct($config = null, $logger = null)
    {
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->logger = $logger;
        $this->connections = [];
        $this->isRunning = false;
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
        $timeout = $this->config->getBinkpTimeout();
        
        while ($this->isRunning) {
            $readSockets = [$this->serverSocket];
            $writeSockets = [];
            $exceptSockets = [];
            
            foreach ($this->connections as $connection) {
                if ($connection['socket']) {
                    $readSockets[] = $connection['socket'];
                }
            }
            
            $result = socket_select($readSockets, $writeSockets, $exceptSockets, 1);
            
            if ($result === false) {
                $this->log('Socket select failed: ' . socket_strerror(socket_last_error()), 'ERROR');
                break;
            }
            
            if ($result === 0) {
                $this->cleanupExpiredConnections();
                continue;
            }
            
            foreach ($readSockets as $socket) {
                if ($socket === $this->serverSocket) {
                    $this->acceptConnection();
                } else {
                    $this->handleConnection($socket);
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
        
        if (count($this->connections) >= $this->config->getMaxConnections()) {
            $this->log('Maximum connections reached, rejecting new connection', 'WARNING');
            $this->sendBusy($clientSocket, 'Maximum connections reached');
            socket_close($clientSocket);
            return;
        }
        
        socket_getpeername($clientSocket, $clientIP);
        $connectionId = uniqid();
        
        $this->log("New connection from {$clientIP} (ID: {$connectionId})");
        
        $this->connections[$connectionId] = [
            'socket' => $clientSocket,
            'ip' => $clientIP,
            'connected_at' => time(),
            'session' => null,
            'state' => 'handshake'
        ];
        
        $this->initializeSession($connectionId);
    }
    
    private function initializeSession($connectionId)
    {
        $connection = &$this->connections[$connectionId];
        
        try {
            $socket = $this->socketToStream($connection['socket']);
            $session = new BinkpSession($socket, false, $this->config);
            $session->setLogger($this->logger);
            
            $connection['session'] = $session;
            $connection['state'] = 'handshaking';
            
            if ($session->handshake()) {
                $connection['state'] = 'authenticated';
                $this->log("Handshake completed for connection {$connectionId}");
            } else {
                $this->log("Handshake failed for connection {$connectionId}", 'ERROR');
                $this->closeConnection($connectionId);
            }
            
        } catch (\Exception $e) {
            $this->log("Session initialization failed for connection {$connectionId}: " . $e->getMessage(), 'ERROR');
            $this->closeConnection($connectionId);
        }
    }
    
    private function handleConnection($socket)
    {
        $connectionId = $this->findConnectionBySocket($socket);
        if (!$connectionId) {
            return;
        }
        
        $connection = &$this->connections[$connectionId];
        
        if (!$connection['session']) {
            $this->closeConnection($connectionId);
            return;
        }
        
        try {
            switch ($connection['state']) {
                case 'authenticated':
                    if ($connection['session']->processSession()) {
                        $connection['state'] = 'completed';
                        $this->log("Session completed for connection {$connectionId}");
                    } else {
                        $this->log("Session failed for connection {$connectionId}", 'ERROR');
                    }
                    $this->closeConnection($connectionId);
                    break;
                    
                default:
                    $this->log("Unexpected state for connection {$connectionId}: " . $connection['state'], 'WARNING');
                    $this->closeConnection($connectionId);
                    break;
            }
            
        } catch (\Exception $e) {
            $this->log("Error handling connection {$connectionId}: " . $e->getMessage(), 'ERROR');
            $this->closeConnection($connectionId);
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
    
    private function findConnectionBySocket($socket)
    {
        foreach ($this->connections as $id => $connection) {
            if ($connection['socket'] === $socket) {
                return $id;
            }
        }
        return null;
    }
    
    private function closeConnection($connectionId)
    {
        if (!isset($this->connections[$connectionId])) {
            return;
        }
        
        $connection = $this->connections[$connectionId];
        
        if ($connection['session']) {
            $connection['session']->close();
        }
        
        if ($connection['socket']) {
            socket_close($connection['socket']);
        }
        
        unset($this->connections[$connectionId]);
        $this->log("Closed connection {$connectionId}");
    }
    
    private function cleanupExpiredConnections()
    {
        $timeout = $this->config->getBinkpTimeout();
        $now = time();
        
        foreach ($this->connections as $id => $connection) {
            if ($now - $connection['connected_at'] > $timeout) {
                $this->log("Connection {$id} timed out", 'WARNING');
                $this->closeConnection($id);
            }
        }
    }
    
    private function socketToStream($socket)
    {
        $socketResource = socket_export_stream($socket);
        if ($socketResource === false) {
            throw new \Exception('Failed to convert socket to stream');
        }
        return $socketResource;
    }
    
    public function stop()
    {
        $this->isRunning = false;
        $this->log('Server stop requested');
    }
    
    private function cleanup()
    {
        foreach ($this->connections as $id => $connection) {
            $this->closeConnection($id);
        }
        
        if ($this->serverSocket) {
            socket_close($this->serverSocket);
            $this->serverSocket = null;
        }
        
        $this->log('Server stopped');
    }
    
    public function getConnections()
    {
        return $this->connections;
    }
    
    public function getConnectionCount()
    {
        return count($this->connections);
    }
}