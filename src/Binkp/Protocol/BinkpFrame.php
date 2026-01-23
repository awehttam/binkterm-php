<?php

namespace BinktermPHP\Binkp\Protocol;

class BinkpFrame
{
    const MAX_FRAME_SIZE = 32767;
    const COMMAND_FRAME = 0x8000;
    const DATA_FRAME = 0x0000;
    
    const M_NUL = 0;
    const M_ADR = 1;
    const M_PWD = 2;
    const M_FILE = 3;
    const M_OK = 4;
    const M_EOB = 5;
    const M_GOT = 6;
    const M_ERR = 7;
    const M_BSY = 8;
    const M_GET = 9;
    const M_SKIP = 10;
    
    private $length;
    private $isCommand;
    private $command;
    private $data;
    
    public function __construct($length = 0, $isCommand = false, $command = 0, $data = '')
    {
        $this->length = $length;
        $this->isCommand = $isCommand;
        $this->command = $command;
        $this->data = $data;
    }
    
    public static function createCommand($command, $data = '')
    {
        $length = strlen($data) + 1;
        return new self($length, true, $command, $data);
    }
    
    public static function createData($data)
    {
        $length = strlen($data);
        return new self($length, false, 0, $data);
    }
    
    public static function parseFromSocket($socket, $nonBlocking = false)
    {
        if ($nonBlocking) {
            // Check if data is available before attempting to read
            $read = [$socket];
            $write = null;
            $except = null;
            $result = stream_select($read, $write, $except, 0, 100000); // 100ms timeout
            if ($result === 0) {
                // No data available
                return null;
            }
        }

        $header = self::readExactly($socket, 2);
        if (strlen($header) < 2) {
            return null;
        }
        
        $lengthAndFlags = unpack('n', $header)[1];
        $isCommand = ($lengthAndFlags & self::COMMAND_FRAME) !== 0;
        $length = $lengthAndFlags & 0x7FFF;
        
        if ($length > self::MAX_FRAME_SIZE) {
            throw new \Exception("Frame too large: {$length}");
        }
        
        $payload = '';
        if ($length > 0) {
            if ($isCommand) {
                $commandByte = self::readExactly($socket, 1);
                if (strlen($commandByte) < 1) {
                    return null;
                }
                $command = ord($commandByte);
                $length--;
                
                if ($length > 0) {
                    $payload = self::readExactly($socket, $length);
                    if (strlen($payload) < $length) {
                        return null;
                    }
                }
                
                return new self($length + 1, true, $command, $payload);
            } else {
                $payload = self::readExactly($socket, $length);
                if (strlen($payload) < $length) {
                    return null;
                }
                return new self($length, false, 0, $payload);
            }
        }
        
        if ($isCommand) {
            $commandByte = self::readExactly($socket, 1);
            if (strlen($commandByte) < 1) {
                return null;
            }
            return new self($length, $isCommand, ord($commandByte), '');
        }
        
        return new self($length, $isCommand, 0, '');
    }
    
    private static function readExactly($socket, $length)
    {
        $data = '';
        $remaining = $length;
        $retries = 0;
        $maxRetries = 3; // Allow a few retries for temporary empty reads

        while ($remaining > 0) {
            $chunk = fread($socket, $remaining);

            // Check for stream errors or EOF
            if ($chunk === false) {
                // Actual error occurred
                break;
            }

            if (strlen($chunk) === 0) {
                // Empty read - could be temporary or EOF
                // Check stream metadata for timeout/EOF
                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out']) {
                    // Stream timed out - this is a real timeout
                    break;
                }
                if ($meta['eof']) {
                    // Connection closed
                    break;
                }

                // Temporary empty read - retry a few times
                $retries++;
                if ($retries >= $maxRetries) {
                    break;
                }
                usleep(10000); // 10ms delay before retry
                continue;
            }

            // Got data, reset retry counter
            $retries = 0;
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }
    
    public function writeToSocket($socket)
    {
        $lengthAndFlags = $this->length;
        if ($this->isCommand) {
            $lengthAndFlags |= self::COMMAND_FRAME;
        }
        
        $header = pack('n', $lengthAndFlags);
        fwrite($socket, $header);
        
        if ($this->isCommand && $this->length > 0) {
            fwrite($socket, chr($this->command));
            if (strlen($this->data) > 0) {
                fwrite($socket, $this->data);
            }
        } elseif (!$this->isCommand && $this->length > 0) {
            fwrite($socket, $this->data);
        }
        
        // Force immediate transmission of frames
        if (is_resource($socket)) {
            fflush($socket);
        }
    }
    
    public function isCommand()
    {
        return $this->isCommand;
    }
    
    public function getCommand()
    {
        return $this->command;
    }
    
    public function getData()
    {
        return $this->data;
    }
    
    public function getLength()
    {
        return $this->length;
    }
    
    public function __toString()
    {
        if ($this->isCommand) {
            $commandName = $this->getCommandName($this->command);
            return "CMD {$commandName}({$this->command}): {$this->data}";
        } else {
            return "DATA: " . substr($this->data, 0, 50) . (strlen($this->data) > 50 ? '...' : '');
        }
    }
    
    private function getCommandName($command)
    {
        $commands = [
            self::M_NUL => 'M_NUL',
            self::M_ADR => 'M_ADR',
            self::M_PWD => 'M_PWD',
            self::M_FILE => 'M_FILE',
            self::M_OK => 'M_OK',
            self::M_EOB => 'M_EOB',
            self::M_GOT => 'M_GOT',
            self::M_ERR => 'M_ERR',
            self::M_BSY => 'M_BSY',
            self::M_GET => 'M_GET',
            self::M_SKIP => 'M_SKIP'
        ];
        
        return $commands[$command] ?? "UNKNOWN({$command})";
    }
}