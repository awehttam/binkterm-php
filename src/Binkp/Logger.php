<?php

namespace BinktermPHP\Binkp;

class Logger
{
    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_ERROR = 3;
    const LEVEL_CRITICAL = 4;
    
    private $logLevel;
    private $logFile;
    private $logToConsole;
    private $dateFormat;
    
    public function __construct($logFile = null, $logLevel = self::LEVEL_INFO, $logToConsole = true)
    {
        $this->logFile = $logFile ?: 'data/logs/binkp.log';
        $this->logLevel = $logLevel;
        $this->logToConsole = $logToConsole;
        $this->dateFormat = 'Y-m-d H:i:s';
        
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    public function log($level, $message, $context = [])
    {
        $numericLevel = $this->getLevelValue($level);
        
        if ($numericLevel < $this->logLevel) {
            return;
        }
        
        $timestamp = date($this->dateFormat);
        $levelStr = is_string($level) ? strtoupper($level) : $this->getLevelName($level);
        
        $logMessage = "[{$timestamp}] [{$levelStr}] {$message}";
        
        if (!empty($context)) {
            $logMessage .= " " . json_encode($context);
        }
        
        if ($this->logToConsole) {
            echo $logMessage . "\n";
        }
        
        if ($this->logFile) {
            file_put_contents($this->logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    
    public function debug($message, $context = [])
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }
    
    public function info($message, $context = [])
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }
    
    public function warning($message, $context = [])
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }
    
    public function error($message, $context = [])
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }
    
    public function critical($message, $context = [])
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }
    
    private function getLevelValue($level)
    {
        if (is_numeric($level)) {
            return (int) $level;
        }
        
        $levels = [
            'DEBUG' => self::LEVEL_DEBUG,
            'INFO' => self::LEVEL_INFO,
            'WARNING' => self::LEVEL_WARNING,
            'ERROR' => self::LEVEL_ERROR,
            'CRITICAL' => self::LEVEL_CRITICAL
        ];
        
        return $levels[strtoupper($level)] ?? self::LEVEL_INFO;
    }
    
    private function getLevelName($level)
    {
        $names = [
            self::LEVEL_DEBUG => 'DEBUG',
            self::LEVEL_INFO => 'INFO',
            self::LEVEL_WARNING => 'WARNING',
            self::LEVEL_ERROR => 'ERROR',
            self::LEVEL_CRITICAL => 'CRITICAL'
        ];
        
        return $names[$level] ?? 'INFO';
    }
    
    public function setLogLevel($level)
    {
        $this->logLevel = is_string($level) ? $this->getLevelValue($level) : $level;
    }
    
    public function setLogFile($logFile)
    {
        $this->logFile = $logFile;
        
        if ($logFile) {
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
        }
    }
    
    public function setLogToConsole($enabled)
    {
        $this->logToConsole = $enabled;
    }
    
    public function getLogFile()
    {
        return $this->logFile;
    }
    
    public function getRecentLogs($lines = 100)
    {
        if (!file_exists($this->logFile)) {
            return [];
        }
        
        $handle = fopen($this->logFile, 'r');
        if (!$handle) {
            return [];
        }
        
        $logLines = [];
        $buffer = '';
        $pos = filesize($this->logFile);
        
        while ($pos > 0 && count($logLines) < $lines) {
            $chunkSize = min(8192, $pos);
            $pos -= $chunkSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $chunkSize);
            $buffer = $chunk . $buffer;
            
            $newLines = explode("\n", $buffer);
            if (count($newLines) > 1) {
                $buffer = array_shift($newLines);
                $logLines = array_merge($newLines, $logLines);
            }
        }
        
        fclose($handle);
        
        return array_slice(array_filter($logLines), -$lines);
    }
    
    public function clearLog()
    {
        if (file_exists($this->logFile)) {
            return unlink($this->logFile);
        }
        return true;
    }
    
    public function rotateLogs($maxSize = 10485760)
    {
        if (!file_exists($this->logFile) || filesize($this->logFile) < $maxSize) {
            return false;
        }
        
        $rotatedFile = $this->logFile . '.' . date('Y-m-d_H-i-s');
        
        if (rename($this->logFile, $rotatedFile)) {
            $this->log(self::LEVEL_INFO, "Log rotated to: " . basename($rotatedFile));
            return true;
        }
        
        return false;
    }
}