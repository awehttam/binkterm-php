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
        if ($logFile) {
            $this->logFile = $logFile;
        } else {
            // Use absolute path from Config
            $this->logFile = \BinktermPHP\Config::getLogPath('binkp.log');
        }
        // Convert string log level to numeric (e.g., 'DEBUG' -> 0)
        $this->logLevel = is_string($logLevel) ? $this->getLevelValue($logLevel) : $logLevel;
        $this->logToConsole = $logToConsole;
        $this->dateFormat = 'Y-m-d H:i:s';

        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
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
        
        if ($this->logToConsole && php_sapi_name() === 'cli') {
            // Use STDERR for console output in CLI mode
            if (defined('STDERR')) {
                fwrite(\STDERR, $logMessage . "\n");
            } else {
                error_log($logMessage);
            }
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
    
    public function getRecentLogs($lines = 100, array $logFiles = [])
    {
        if (empty($logFiles)) {
            $logFiles = [
                basename($this->logFile) => $this->logFile
            ];
        }

        $combined = [];

        foreach ($logFiles as $label => $path) {
            if (!file_exists($path)) {
                continue;
            }

            $handle = fopen($path, 'r');
            if (!$handle) {
                continue;
            }

            $logLines = [];
            $buffer = '';
            $pos = filesize($path);

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

            $logLines = array_slice(array_filter($logLines), -$lines);
            foreach ($logLines as $line) {
                $combined[] = "{$label}: {$line}";
            }
        }

        return $combined;
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

