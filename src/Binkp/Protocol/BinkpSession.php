<?php

namespace BinktermPHP\Binkp\Protocol;

use BinktermPHP\Binkp\Config\BinkpConfig;

class BinkpSession
{
    const STATE_INIT = 0;
    const STATE_ADDR_SENT = 1;
    const STATE_ADDR_RECEIVED = 2;
    const STATE_PWD_SENT = 3;
    const STATE_AUTHENTICATED = 4;
    const STATE_FILE_TRANSFER = 5;
    const STATE_EOB_SENT = 6;
    const STATE_EOB_RECEIVED = 7;
    const STATE_TERMINATED = 8;
    
    private $socket;
    private $state;
    private $isOriginator;
    private $localAddress;
    private $remoteAddress;
    private $password;
    private $config;
    private $logger;
    private $currentFile;
    private $fileHandle;
    private $filesReceived;
    private $filesSent;
    private $uplinkPassword;
    
    public function __construct($socket, $isOriginator = false, $config = null)
    {
        $this->socket = $socket;
        $this->isOriginator = $isOriginator;
        $this->state = self::STATE_INIT;
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->filesReceived = [];
        $this->filesSent = [];
        $this->currentFile = null;
        $this->fileHandle = null;
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    
    public function setUplinkPassword($password)
    {
        $this->uplinkPassword = $password;
        $this->log("Uplink password set: " . (empty($password) ? 'empty' : 'configured'));
    }
    
    public function log($message, $level = 'INFO')
    {
        if ($this->logger) {
            $this->logger->log($level, "[{$this->remoteAddress}] {$message}");
        }
    }
    
    public function handshake()
    {
        try {
            if ($this->isOriginator) {
                $this->sendAddress();
                $this->state = self::STATE_ADDR_SENT;
            }
            
            while ($this->state < self::STATE_AUTHENTICATED) {
                $frame = BinkpFrame::parseFromSocket($this->socket);
                if (!$frame) {
                    throw new \Exception('Failed to read frame during handshake');
                }
                
                $this->log("Received: {$frame}");
                $this->processHandshakeFrame($frame);
            }
            
            $this->log('Handshake completed successfully');
            return true;
            
        } catch (\Exception $e) {
            $this->log("Handshake failed: " . $e->getMessage(), 'ERROR');
            $this->sendError($e->getMessage());
            return false;
        }
    }
    
    public function processSession()
    {
        try {
            $this->state = self::STATE_FILE_TRANSFER;
            $this->log("Entering file transfer phase");
            
            if ($this->isOriginator) {
                $this->log("As originator, checking for outbound files");
                $this->sendFiles();
                
                // Wait for M_GOT responses before sending EOB
                if (!empty($this->filesSent)) {
                    $this->log("Waiting for M_GOT responses for " . count($this->filesSent) . " sent files: " . implode(', ', $this->filesSent));
                    $pendingFiles = array_flip($this->filesSent); // Use filenames as keys for easier lookup
                    $timeout = time() + 120; // Increased timeout to 120 seconds
                    $lastActivity = time();
                    
                    while (!empty($pendingFiles) && time() < $timeout && $this->state < self::STATE_TERMINATED) {
                        $frame = BinkpFrame::parseFromSocket($this->socket);
                        if (!$frame) {
                            // Check for extended timeout without activity
                            if (time() - $lastActivity > 30) {
                                $this->log("No activity for 30 seconds while waiting for M_GOT", 'WARNING');
                                break;
                            }
                            usleep(100000); // 100ms delay to prevent busy waiting
                            continue;
                        }
                        
                        $lastActivity = time();
                        $this->log("Received while waiting for M_GOT: {$frame}");
                        $this->processTransferFrame($frame);
                        
                        // Remove confirmed files from pending list
                        if ($frame->isCommand() && $frame->getCommand() === BinkpFrame::M_GOT) {
                            $gotData = $frame->getData();
                            $this->log("Processing M_GOT: '{$gotData}'");
                            $this->log("Current pending files before M_GOT: " . implode(', ', array_keys($pendingFiles)));
                            
                            // Extract filename from M_GOT data (format: "filename size timestamp")
                            $parts = explode(' ', $gotData);
                            $confirmedFile = $parts[0];
                            
                            // Try exact match first
                            if (isset($pendingFiles[$confirmedFile])) {
                                unset($pendingFiles[$confirmedFile]);
                                $this->log("File confirmed (exact match): {$confirmedFile}");
                            } else {
                                // Try basename match for path issues
                                $baseName = basename($confirmedFile);
                                $foundMatch = false;
                                foreach (array_keys($pendingFiles) as $pendingFile) {
                                    if (basename($pendingFile) === $baseName) {
                                        unset($pendingFiles[$pendingFile]);
                                        $this->log("File confirmed (basename match): {$pendingFile} -> {$confirmedFile}");
                                        $foundMatch = true;
                                        break;
                                    }
                                }
                                if (!$foundMatch) {
                                    $this->log("M_GOT for unknown file: {$confirmedFile}, pending: " . implode(', ', array_keys($pendingFiles)), 'WARNING');
                                }
                            }
                            
                            $this->log("Remaining pending files: " . count($pendingFiles) . " (" . implode(', ', array_keys($pendingFiles)) . ")");
                        }
                    }
                    
                    if (!empty($pendingFiles)) {
                        $this->log("Warning: " . count($pendingFiles) . " files not confirmed after timeout: " . implode(', ', array_keys($pendingFiles)), 'WARNING');
                        $this->log("Proceeding with M_EOB anyway to prevent protocol deadlock", 'WARNING');
                    } else {
                        $this->log("All sent files confirmed by remote");
                    }
                }
            } else {
                $this->log("As answerer, waiting for files");
            }
            
            // Continue processing frames until EOB exchange
            while ($this->state < self::STATE_EOB_SENT) {
                $frame = BinkpFrame::parseFromSocket($this->socket);
                if (!$frame) {
                    break;
                }
                
                $this->log("Received: {$frame}");
                $this->processTransferFrame($frame);
            }
            
            // As originator, wait for remote to potentially send us files before sending EOB
            // Give remote system time to start sending files after they receive ours
            // Only do this if we're not already receiving a file
            if (!$this->currentFile) {
                $this->log("WAIT LOGIC: Starting post-send wait for incoming files");
                $waitStartTime = time();
                $maxWaitTime = 30; // Wait up to 30 seconds for remote to start sending files
            } else {
                $this->log("WAIT LOGIC: Skipping wait - already receiving file: " . $this->currentFile['name']);
                $waitStartTime = time();
                $maxWaitTime = 0; // Skip the wait loop entirely
            }
            
            while ($this->state === self::STATE_FILE_TRANSFER && time() - $waitStartTime < $maxWaitTime) {
                $frame = BinkpFrame::parseFromSocket($this->socket);
                if ($frame) {
                    $this->log("WAIT LOGIC: Received during post-send wait: {$frame}");
                    $this->processTransferFrame($frame);
                    
                    // If remote starts sending a file or sends EOB, exit wait
                    if ($this->currentFile || $this->state !== self::STATE_FILE_TRANSFER) {
                        $this->log("WAIT LOGIC: Exiting wait - currentFile: " . ($this->currentFile ? $this->currentFile['name'] : 'none') . ", state: " . $this->state);
                        break;
                    }
                } else {
                    usleep(100000); // 100ms delay
                }
            }
            $this->log("WAIT LOGIC: Wait completed after " . (time() - $waitStartTime) . " seconds");
            
            // Send EOB if we haven't already and no file is currently being received
            if ($this->state === self::STATE_FILE_TRANSFER && !$this->currentFile) {
                $this->log("Sending EOB (End of Batch) - no active file transfer after wait");
                $this->sendEOB();
                $this->state = self::STATE_EOB_SENT;
            } else if ($this->state === self::STATE_FILE_TRANSFER && $this->currentFile) {
                $this->log("Remote started sending file during wait: " . $this->currentFile['name']);
            }
            
            // Continue until session terminates
            while ($this->state < self::STATE_TERMINATED) {
                $frame = BinkpFrame::parseFromSocket($this->socket);
                if (!$frame) {
                    break;
                }
                
                $this->log("Received: {$frame}");
                $this->processTransferFrame($frame);
            }
            
            $this->cleanup();
            $this->log('Session completed successfully');
            return true;
            
        } catch (\Exception $e) {
            $this->log("Session failed: " . $e->getMessage(), 'ERROR');
            $this->cleanup();
            return false;
        }
    }
    
    private function processHandshakeFrame(BinkpFrame $frame)
    {
        if (!$frame->isCommand()) {
            throw new \Exception('Expected command frame during handshake');
        }
        
        switch ($frame->getCommand()) {
            case BinkpFrame::M_ADR:
                $this->remoteAddress = $frame->getData();
                $this->log("Remote address: {$this->remoteAddress}");
                
                if ($this->state === self::STATE_INIT) {
                    $this->sendAddress();
                    $this->sendPassword();
                    $this->state = self::STATE_PWD_SENT;
                } elseif ($this->state === self::STATE_ADDR_SENT) {
                    $this->sendPassword();
                    $this->state = self::STATE_PWD_SENT;
                } else {
                    $this->state = self::STATE_ADDR_RECEIVED;
                }
                break;
                
            case BinkpFrame::M_PWD:
                if (!$this->validatePassword($frame->getData())) {
                    throw new \Exception('Authentication failed');
                }
                
                if ($this->state === self::STATE_ADDR_RECEIVED) {
                    $this->sendPassword();
                }
                
                $this->sendOK('Authentication successful');
                $this->state = self::STATE_AUTHENTICATED;
                break;
                
            case BinkpFrame::M_OK:
                if ($this->state === self::STATE_PWD_SENT) {
                    $this->state = self::STATE_AUTHENTICATED;
                }
                break;
                
            case BinkpFrame::M_ERR:
                throw new \Exception('Remote error: ' . $frame->getData());
                
            case BinkpFrame::M_BSY:
                throw new \Exception('Remote busy: ' . $frame->getData());
                
            default:
                $this->log("Unexpected command during handshake: " . $frame->getCommand(), 'WARNING');
        }
    }
    
    private function processTransferFrame(BinkpFrame $frame)
    {
        if ($frame->isCommand()) {
            $this->log("Processing command frame: " . $frame->getCommand() . " with data: " . $frame->getData());
            
            switch ($frame->getCommand()) {
                case BinkpFrame::M_FILE:
                    $this->handleFileCommand($frame->getData());
                    break;
                    
                case BinkpFrame::M_EOB:
                    $this->log("Received M_EOB, current state: " . $this->state);
                    if ($this->state === self::STATE_EOB_SENT) {
                        $this->log("Both sides sent EOB, terminating session");
                        $this->state = self::STATE_TERMINATED;
                    } else {
                        $this->log("Sending EOB response");
                        $this->sendEOB();
                        $this->state = self::STATE_EOB_RECEIVED;
                    }
                    break;
                    
                case BinkpFrame::M_GOT:
                    $this->log("Received M_GOT for file: " . $frame->getData());
                    $this->handleGotCommand($frame->getData());
                    break;
                    
                case BinkpFrame::M_GET:
                    $this->handleGetCommand($frame->getData());
                    break;
                    
                case BinkpFrame::M_SKIP:
                    $this->handleSkipCommand($frame->getData());
                    break;
                    
                case BinkpFrame::M_ERR:
                    throw new \Exception('Remote error: ' . $frame->getData());
                    
                default:
                    $this->log("Unexpected command during transfer: " . $frame->getCommand(), 'WARNING');
            }
        } else {
            $this->log("RECEIVING DATA: Processing data frame of " . strlen($frame->getData()) . " bytes");
            $this->handleFileData($frame->getData());
        }
    }
    
    private function sendAddress()
    {
        $address = $this->config->getSystemAddress();
        $frame = BinkpFrame::createCommand(BinkpFrame::M_ADR, $address);
        $frame->writeToSocket($this->socket);
        $this->log("Sent address: {$address}");
    }
    
    private function sendPassword()
    {
        if ($this->isOriginator) {
            // As originator, send the uplink password
            $password = $this->uplinkPassword ?? '';
        } else {
            // As answerer, send our own password (not implemented for server mode)
            $password = '';
        }
        
        $frame = BinkpFrame::createCommand(BinkpFrame::M_PWD, $password);
        $frame->writeToSocket($this->socket);
        $this->log("Sent password: " . (empty($password) ? 'empty' : 'configured'));
    }
    
    private function sendOK($message = '')
    {
        $frame = BinkpFrame::createCommand(BinkpFrame::M_OK, $message);
        $frame->writeToSocket($this->socket);
        $this->log("Sent OK: {$message}");
    }
    
    private function sendError($message)
    {
        $frame = BinkpFrame::createCommand(BinkpFrame::M_ERR, $message);
        $frame->writeToSocket($this->socket);
        $this->log("Sent error: {$message}");
    }
    
    private function sendEOB()
    {
        $frame = BinkpFrame::createCommand(BinkpFrame::M_EOB, '');
        $frame->writeToSocket($this->socket);
        $this->log("Sent EOB");
    }
    
    private function sendFiles()
    {
        $outboundPath = $this->config->getOutboundPath();
        $files = glob($outboundPath . '/*.pkt');
        
        $this->log("Found " . count($files) . " outbound files in {$outboundPath}");
        
        if (empty($files)) {
            $this->log("No outbound files to send");
            return;
        }
        
        foreach ($files as $file) {
            if (is_file($file) && is_readable($file)) {
                $this->log("Preparing to send file: " . basename($file));
                $this->sendFile($file);
            } else {
                $this->log("Skipping unreadable file: " . basename($file), 'WARNING');
            }
        }
        
        $this->log("Finished sending " . count($files) . " files");
    }
    
    private function sendFile($filePath)
    {
        $filename = basename($filePath);
        $fileSize = filesize($filePath);
        $timestamp = filemtime($filePath);
        
        // Format: filename size timestamp [offset]
        // According to binkp spec, format should be: filename size time [offset]
        $fileInfo = "{$filename} {$fileSize} {$timestamp} 0";
        $frame = BinkpFrame::createCommand(BinkpFrame::M_FILE, $fileInfo);
        $frame->writeToSocket($this->socket);
        
        $this->log("Sending M_FILE: {$fileInfo}");
        
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            $this->log("Failed to open file for reading: {$filePath}", 'ERROR');
            return;
        }
        
        $bytesSent = 0;
        while (!feof($handle)) {
            $data = fread($handle, 8192); // Use larger chunks for efficiency
            if ($data === false || strlen($data) === 0) {
                break;
            }
            
            $dataFrame = BinkpFrame::createData($data);
            $dataFrame->writeToSocket($this->socket);
            $bytesSent += strlen($data);
            
            $this->log("Sent {$bytesSent}/{$fileSize} bytes of {$filename}");
        }
        fclose($handle);
        
        if ($bytesSent === $fileSize) {
            $this->filesSent[] = $filename;
            $this->log("File sent successfully: {$filename} ({$bytesSent} bytes) - waiting for M_GOT");
        } else {
            $this->log("File send incomplete: {$filename} ({$bytesSent}/{$fileSize} bytes)", 'ERROR');
        }
    }
    
    private function handleFileCommand($data)
    {
        $parts = explode(' ', $data, 4);
        $filename = $parts[0];
        $size = isset($parts[1]) ? intval($parts[1]) : 0;
        $timestamp = isset($parts[2]) ? intval($parts[2]) : time();
        $offset = isset($parts[3]) ? intval($parts[3]) : 0;
        
        $this->log("Received M_FILE: {$data}");
        
        // Validate filename to prevent directory traversal
        $filename = basename($filename);
        if (empty($filename) || $filename === '.' || $filename === '..') {
            $this->log("Invalid filename in M_FILE: {$data}", 'ERROR');
            return;
        }
        
        $this->currentFile = [
            'name' => $filename,
            'size' => $size,
            'timestamp' => $timestamp,
            'offset' => $offset,
            'received' => 0
        ];
        
        $inboundPath = $this->config->getInboundPath();
        $filepath = $inboundPath . '/' . $filename;
        
        // Handle resume if offset > 0
        if ($offset > 0) {
            $this->fileHandle = fopen($filepath, 'r+b');
            if ($this->fileHandle) {
                fseek($this->fileHandle, $offset);
                $this->currentFile['received'] = $offset;
                $this->log("Resuming file: {$filename} from offset {$offset}");
            }
        } else {
            $this->fileHandle = fopen($filepath, 'wb');
        }
        
        if (!$this->fileHandle) {
            $this->log("Failed to open file for writing: {$filepath}", 'ERROR');
            return;
        }
        
        $this->log("Receiving file: {$filename} ({$size} bytes, offset: {$offset})");
    }
    
    private function handleFileData($data)
    {
        if ($this->currentFile && $this->fileHandle) {
            $bytesWritten = fwrite($this->fileHandle, $data);
            if ($bytesWritten === false) {
                $this->log("Failed to write file data for: " . $this->currentFile['name'], 'ERROR');
                return;
            }
            
            $this->currentFile['received'] += $bytesWritten;
            
            $this->log("Received {$this->currentFile['received']}/{$this->currentFile['size']} bytes of {$this->currentFile['name']}");
            
            if ($this->currentFile['received'] >= $this->currentFile['size']) {
                fclose($this->fileHandle);
                $this->fileHandle = null;
                
                $this->filesReceived[] = $this->currentFile['name'];
                $this->log("File received completely: " . $this->currentFile['name'] . " ({$this->currentFile['received']} bytes)");
                
                // Send M_GOT with filename, size, and timestamp for better compatibility
                $gotData = $this->currentFile['name'] . ' ' . $this->currentFile['size'] . ' ' . time();
                $this->log("SENDING M_GOT: Preparing to send M_GOT for: " . $gotData);
                $frame = BinkpFrame::createCommand(BinkpFrame::M_GOT, $gotData);
                $this->log("SENDING M_GOT: Frame created, writing to socket");
                $frame->writeToSocket($this->socket);
                $this->log("SENDING M_GOT: Successfully sent M_GOT for: " . $gotData);
                
                $this->currentFile = null;
            }
        } else {
            $this->log("Received file data but no active file transfer", 'WARNING');
        }
    }
    
    private function handleGotCommand($data)
    {
        $this->log("Remote confirmed receipt of: {$data}");
        
        // M_GOT format can be "filename" or "filename size timestamp"
        // Extract just the filename part
        $parts = explode(' ', $data);
        $filename = $parts[0];
        
        $outboundPath = $this->config->getOutboundPath();
        $filepath = $outboundPath . '/' . $filename;
        if (file_exists($filepath)) {
            if (unlink($filepath)) {
                $this->log("Successfully deleted sent file: {$filename}");
            } else {
                $this->log("Failed to delete sent file: {$filename}", 'ERROR');
            }
        } else {
            $this->log("Sent file not found for deletion: {$filepath}", 'WARNING');
        }
    }
    
    private function handleGetCommand($data)
    {
        $this->log("Remote requested file: {$data}");
    }
    
    private function handleSkipCommand($data)
    {
        $this->log("Remote skipped file: {$data}");
    }
    
    private function validatePassword($password)
    {
        if ($this->isOriginator) {
            // As originator, we validate the remote's password against what we expect for them
            $expectedPassword = $this->getPasswordForRemote();
            $this->log("Validating remote password (originator mode)");
            return $password === $expectedPassword;
        } else {
            // As answerer, we validate the remote's password against our uplink config
            $expectedPassword = $this->getPasswordForRemote();
            $this->log("Validating remote password (answerer mode)");
            return $password === $expectedPassword;
        }
    }
    
    private function getPasswordForRemote()
    {
        if ($this->remoteAddress) {
            $password = $this->config->getPasswordForAddress($this->remoteAddress);
            $this->log("Retrieved password for {$this->remoteAddress}: " . (empty($password) ? 'none' : 'configured'));
            return $password;
        }
        return '';
    }
    
    private function cleanup()
    {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
        
        if ($this->currentFile) {
            $inboundPath = $this->config->getInboundPath();
            $filepath = $inboundPath . '/' . $this->currentFile['name'];
            if (file_exists($filepath) && $this->currentFile['received'] < $this->currentFile['size']) {
                unlink($filepath);
                $this->log("Deleted incomplete file: " . $this->currentFile['name']);
            }
        }
    }
    
    public function getFilesReceived()
    {
        return $this->filesReceived;
    }
    
    public function getFilesSent()
    {
        return $this->filesSent;
    }
    
    public function getRemoteAddress()
    {
        return $this->remoteAddress;
    }
    
    public function close()
    {
        $this->cleanup();
        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
}