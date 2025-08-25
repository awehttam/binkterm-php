<?php

namespace BinktermPHP\Binkp\Queue;

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Binkp\Config\BinkpConfig;

class InboundQueue
{
    private $config;
    private $logger;
    private $binkdProcessor;
    
    public function __construct($config = null, $logger = null)
    {
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->logger = $logger;
        $this->binkdProcessor = new BinkdProcessor();
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    
    public function log($message, $level = 'INFO')
    {
        if ($this->logger) {
            $this->logger->log($level, "[INBOUND] {$message}");
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] [{$level}] [INBOUND] {$message}\n";
        }
    }
    
    public function processInbound()
    {
        $inboundPath = $this->config->getInboundPath();
        $files = glob($inboundPath . '/*.pkt');
        
        if (empty($files)) {
            return [];
        }
        
        $this->log("Processing " . count($files) . " inbound files");
        $results = [];
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            try {
                $this->log("Processing inbound file: {$filename}");
                
                if ($this->processPacketFile($file)) {
                    $results[$filename] = [
                        'success' => true,
                        'processed_at' => date('Y-m-d H:i:s')
                    ];
                    $this->log("Successfully processed: {$filename}");
                    unlink($file);
                } else {
                    throw new \Exception('BinkdProcessor returned false');
                }
                
            } catch (\Exception $e) {
                $error = "Failed to process {$filename}: " . $e->getMessage();
                $this->log($error, 'ERROR');
                
                $results[$filename] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'processed_at' => date('Y-m-d H:i:s')
                ];
                
                $this->moveToError($file);
            }
        }
        
        return $results;
    }
    
    public function processFile($filename)
    {
        $inboundPath = $this->config->getInboundPath();
        $filepath = $inboundPath . '/' . $filename;
        
        if (!file_exists($filepath)) {
            throw new \Exception("Inbound file not found: {$filename}");
        }
        
        $this->log("Processing specific inbound file: {$filename}");
        
        try {
            if ($this->processPacketFile($filepath)) {
                $this->log("Successfully processed: {$filename}");
                unlink($filepath);
                return true;
            } else {
                throw new \Exception('BinkdProcessor returned false');
            }
            
        } catch (\Exception $e) {
            $this->log("Failed to process {$filename}: " . $e->getMessage(), 'ERROR');
            $this->moveToError($filepath);
            throw $e;
        }
    }
    
    private function moveToError($filepath)
    {
        $inboundPath = $this->config->getInboundPath();
        $errorDir = $inboundPath . '/error';
        
        if (!is_dir($errorDir)) {
            mkdir($errorDir, 0755, true);
        }
        
        $filename = basename($filepath);
        $errorPath = $errorDir . '/' . $filename . '.' . time();
        
        if (rename($filepath, $errorPath)) {
            $this->log("Moved failed file to error directory: {$filename}");
        } else {
            $this->log("Failed to move file to error directory: {$filename}", 'WARNING');
        }
    }
    
    public function getInboundFiles()
    {
        $inboundPath = $this->config->getInboundPath();
        $files = glob($inboundPath . '/*.pkt');
        $fileInfo = [];
        
        foreach ($files as $file) {
            $fileInfo[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
                'path' => $file
            ];
        }
        
        return $fileInfo;
    }
    
    public function getErrorFiles()
    {
        $inboundPath = $this->config->getInboundPath();
        $errorDir = $inboundPath . '/error';
        
        if (!is_dir($errorDir)) {
            return [];
        }
        
        $files = glob($errorDir . '/*');
        $fileInfo = [];
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $fileInfo[] = [
                    'filename' => basename($file),
                    'size' => filesize($file),
                    'modified' => date('Y-m-d H:i:s', filemtime($file)),
                    'path' => $file
                ];
            }
        }
        
        return $fileInfo;
    }
    
    public function cleanupOldFiles($maxAgeHours = 24)
    {
        $inboundPath = $this->config->getInboundPath();
        $errorDir = $inboundPath . '/error';
        $cutoff = time() - ($maxAgeHours * 3600);
        $cleaned = [];
        
        if (is_dir($errorDir)) {
            $files = glob($errorDir . '/*');
            
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    $filename = basename($file);
                    if (unlink($file)) {
                        $cleaned[] = $filename;
                        $this->log("Cleaned up old error file: {$filename}");
                    }
                }
            }
        }
        
        return $cleaned;
    }
    
    private function processPacketFile($filepath)
    {
        // Use the existing BinkdProcessor to handle the packet
        return $this->binkdProcessor->processPacket($filepath);
    }

    public function retryErrorFile($filename)
    {
        $inboundPath = $this->config->getInboundPath();
        $errorDir = $inboundPath . '/error';
        $errorPath = $errorDir . '/' . $filename;
        
        if (!file_exists($errorPath)) {
            throw new \Exception("Error file not found: {$filename}");
        }
        
        $originalName = preg_replace('/\.\d+$/', '', $filename);
        $retryPath = $inboundPath . '/' . $originalName;
        
        if (rename($errorPath, $retryPath)) {
            $this->log("Moved error file back to inbound: {$filename} -> {$originalName}");
            return $this->processFile($originalName);
        } else {
            throw new \Exception("Failed to move error file back to inbound");
        }
    }
    
    public function watchInbound($callback = null)
    {
        $inboundPath = $this->config->getInboundPath();
        $this->log("Starting inbound watcher on: {$inboundPath}");
        
        $lastCheck = time();
        $processedFiles = [];
        
        while (true) {
            $files = glob($inboundPath . '/*.pkt');
            $newFiles = [];
            
            foreach ($files as $file) {
                $filename = basename($file);
                $mtime = filemtime($file);
                
                if (!isset($processedFiles[$filename]) && $mtime > $lastCheck) {
                    $newFiles[] = $file;
                    $processedFiles[$filename] = $mtime;
                }
            }
            
            if (!empty($newFiles)) {
                $this->log("Detected " . count($newFiles) . " new inbound files");
                
                foreach ($newFiles as $file) {
                    try {
                        $this->processFile(basename($file));
                        
                        if ($callback && is_callable($callback)) {
                            $callback(basename($file), true, null);
                        }
                        
                    } catch (\Exception $e) {
                        if ($callback && is_callable($callback)) {
                            $callback(basename($file), false, $e->getMessage());
                        }
                    }
                }
            }
            
            $lastCheck = time();
            sleep(5);
        }
    }
    
    public function getStats()
    {
        $inboundPath = $this->config->getInboundPath();
        $errorDir = $inboundPath . '/error';
        
        $inboundFiles = glob($inboundPath . '/*.pkt');
        $errorFiles = is_dir($errorDir) ? glob($errorDir . '/*') : [];
        
        return [
            'pending_files' => count($inboundFiles),
            'error_files' => count($errorFiles),
            'inbound_path' => $inboundPath,
            'last_check' => date('Y-m-d H:i:s')
        ];
    }
}