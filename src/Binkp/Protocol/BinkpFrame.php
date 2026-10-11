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
    private static $lastReadDiagnostics = null;

    /**
     * Per-socket resumable parse state for parseFromSocket($socket, true).
     * Keyed by get_resource_id($socket). Holds whatever prefix of the
     * current frame's header/body has been read off the wire so far, so a
     * genuinely non-blocking call that finds a frame only partially
     * delivered can return immediately without losing those bytes, and a
     * later call resumes exactly where the previous one left off instead of
     * misreading the remaining payload bytes as a fresh header.
     *
     * @var array<int,array{header:string,haveHeader:bool,isCommand:bool,length:int,body:string}>
     */
    private static array $pending = [];
    
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
    
    /**
     * Parse one complete frame from $socket.
     *
     * $nonBlocking=false (default): fully blocking, used only during the
     * handshake and other phases that are meant to wait for the peer. This
     * preserves the exact prior behavior of reading straight through
     * readExactly()'s own blocking/timeout/EOF handling.
     *
     * $nonBlocking=true: genuinely non-blocking across the ENTIRE read, not
     * just an upfront readiness check. Every caller of this mode already
     * polls in a loop (parseFromSocket returning null -> usleep -> retry), so
     * a single call must never itself wait for bytes that have not arrived
     * yet — including bytes belonging to a frame that started arriving but
     * is not yet complete (a fragmented/slow-delivered frame). Partial bytes
     * are preserved in $pending, keyed by socket, and a later call resumes
     * from exactly where the previous one left off rather than re-reading a
     * "header" that is really mid-payload.
     */
    public static function parseFromSocket($socket, $nonBlocking = false)
    {
        self::$lastReadDiagnostics = null;
        $key = $nonBlocking ? self::pendingKey($socket) : null;
        $st = ($key !== null ? (self::$pending[$key] ?? null) : null)
            ?? ['header' => '', 'haveHeader' => false, 'isCommand' => false, 'length' => 0, 'body' => ''];

        if (!$st['haveHeader']) {
            $need = 2 - strlen($st['header']);
            $st['header'] .= $nonBlocking
                ? self::readAvailableNonBlocking($socket, $need, 'header')
                : self::readExactly($socket, $need, 'header');

            if (strlen($st['header']) < 2) {
                self::storePending($key, $st, $nonBlocking);
                return null;
            }

            $lengthAndFlags = unpack('n', $st['header'])[1];
            $st['isCommand'] = ($lengthAndFlags & self::COMMAND_FRAME) !== 0;
            $st['length'] = $lengthAndFlags & 0x7FFF;
            $st['haveHeader'] = true;

            if ($st['length'] > self::MAX_FRAME_SIZE) {
                if ($key !== null) {
                    unset(self::$pending[$key]);
                }
                throw new \Exception("Frame too large: {$st['length']}");
            }
        }

        // Command frames carry their mandatory command byte inside the
        // header's length field (length = 1 command byte + payload). A
        // command frame advertising length=0 is malformed per FSP-1011, but
        // a command byte is still read defensively — matching this method's
        // pre-existing behavior for that edge case.
        $bodyNeeded = $st['isCommand'] ? max($st['length'], 1) : $st['length'];

        if ($bodyNeeded > 0) {
            $need = $bodyNeeded - strlen($st['body']);
            if ($need > 0) {
                $st['body'] .= $nonBlocking
                    ? self::readAvailableNonBlocking($socket, $need, $st['isCommand'] ? 'command' : 'payload')
                    : self::readExactly($socket, $need, $st['isCommand'] ? 'command' : 'payload');
            }

            if (strlen($st['body']) < $bodyNeeded) {
                self::storePending($key, $st, $nonBlocking);
                return null;
            }
        }

        if ($key !== null) {
            unset(self::$pending[$key]);
        }

        if ($st['isCommand']) {
            $command = ord($st['body'][0] ?? "\0");
            $payload = substr($st['body'], 1);
            return new self($st['length'], true, $command, $payload);
        }

        return new self($st['length'], false, 0, $st['body']);
    }

    /**
     * Stashes (or discards) resumable parse state for a nonBlocking caller.
     * Blocking callers ($key === null) never persist partial state: a
     * blocking read that comes up short already means a real error/EOF/
     * timeout (readExactly only returns short for one of those reasons), so
     * there is nothing valid left to resume.
     */
    private static function storePending(?int $key, array $st, bool $nonBlocking): void
    {
        if ($key === null) {
            return;
        }

        $reason = self::$lastReadDiagnostics['reason'] ?? null;
        if ($reason === 'eof' || $reason === 'read_error') {
            // The connection is dead; nothing to resume.
            unset(self::$pending[$key]);
            return;
        }

        self::$pending[$key] = $st;
    }

    private static function pendingKey($socket): int
    {
        return get_resource_id($socket);
    }

    /**
     * Forgets any resumable parse state kept for $socket. Callers that reuse
     * PHP resource IDs across sockets within one long-running process (a
     * closed socket's ID can be reassigned to a later, unrelated socket)
     * must call this once a socket is done with, so a stale partial frame
     * from a previous connection can never be misapplied to a new one.
     */
    public static function forgetSocket($socket): void
    {
        if (!is_resource($socket)) {
            return;
        }
        unset(self::$pending[self::pendingKey($socket)]);
    }

    /**
     * Makes at most one genuinely non-blocking read attempt for up to
     * $maxLength bytes and returns immediately with whatever is available
     * now (possibly nothing) - never waiting for more to arrive. This is
     * what actually makes parseFromSocket($socket, true) nonblocking across
     * a fragmented frame: the old implementation only checked stream_select()
     * once up front, then fell into readExactly()'s blocking fread() loop
     * for the rest of the frame, which could block for the full configured
     * socket timeout if the peer paused mid-delivery.
     *
     * Distinguishes "nothing available yet" (returns '', no diagnostics -
     * completely normal for a fragmented/slow delivery) from a genuine EOF
     * (sets $lastReadDiagnostics with reason 'eof', same as readExactly(),
     * so callers' existing EOF-based session-close logic keeps working).
     */
    private static function readAvailableNonBlocking($socket, int $maxLength, string $phase): string
    {
        if ($maxLength <= 0 || !is_resource($socket)) {
            return '';
        }

        // All sockets this codebase hands to parseFromSocket() are kept in
        // blocking mode between calls (BinkpClient/BinkpServer both call
        // stream_set_blocking($socket, true) once when the session starts).
        // Flip to non-blocking for exactly this one read, then flip back
        // immediately so every other caller of this socket (writes, the
        // blocking $nonBlocking=false path, etc.) sees the mode it expects.
        stream_set_blocking($socket, false);
        $chunk = fread($socket, $maxLength);
        stream_set_blocking($socket, true);

        if ($chunk === false) {
            self::$lastReadDiagnostics = self::buildReadDiagnostics($socket, $phase, $maxLength, 0, 'read_error');
            return '';
        }

        if ($chunk === '') {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['eof'])) {
                self::$lastReadDiagnostics = self::buildReadDiagnostics($socket, $phase, $maxLength, 0, 'eof');
            }
            // Otherwise: genuinely nothing available yet. Not an error -
            // this is the normal, expected case for a fragmented or
            // slow-arriving frame and must not be treated as one.
        }

        return $chunk;
    }

    private static function readExactly($socket, $length, string $phase = 'read')
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
                self::$lastReadDiagnostics = self::buildReadDiagnostics($socket, $phase, $length, strlen($data), 'read_error');
                break;
            }

            if (strlen($chunk) === 0) {
                // Empty read - could be temporary or EOF
                // Check stream metadata for timeout/EOF
                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out']) {
                    // Stream timed out - this is a real timeout
                    self::$lastReadDiagnostics = self::buildReadDiagnostics($socket, $phase, $length, strlen($data), 'timeout');
                    break;
                }
                if ($meta['eof']) {
                    // Connection closed
                    self::$lastReadDiagnostics = self::buildReadDiagnostics($socket, $phase, $length, strlen($data), 'eof');
                    break;
                }

                // Temporary empty read - retry a few times
                $retries++;
                if ($retries >= $maxRetries) {
                    self::$lastReadDiagnostics = self::buildReadDiagnostics($socket, $phase, $length, strlen($data), 'empty_read_retries_exhausted');
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

    private static function buildReadDiagnostics($socket, string $phase, int $requested, int $received, string $reason): array
    {
        $meta = is_resource($socket) ? stream_get_meta_data($socket) : [];

        return [
            'phase' => $phase,
            'requested' => $requested,
            'received' => $received,
            'reason' => $reason,
            'timed_out' => !empty($meta['timed_out']),
            'eof' => !empty($meta['eof']),
            'blocked' => !empty($meta['blocked']),
        ];
    }

    public static function getLastReadDiagnostics(): ?array
    {
        return self::$lastReadDiagnostics;
    }
    
    public function writeToSocket($socket)
    {
        if (!is_resource($socket)) {
            return;
        }

        $lengthAndFlags = $this->length;
        if ($this->isCommand) {
            $lengthAndFlags |= self::COMMAND_FRAME;
        }

        $buffer = pack('n', $lengthAndFlags);

        if ($this->isCommand) {
            $buffer .= chr($this->command);
        }

        if ($this->length > 0 && $this->data !== '') {
            $buffer .= $this->data;
        }

        self::writeAll($socket, $buffer);
        
        // Force immediate transmission of frames
        if (is_resource($socket)) {
            fflush($socket);
        }
    }

    private static function writeAll($socket, string $buffer): void
    {
        $offset = 0;
        $length = strlen($buffer);

        while ($offset < $length) {
            $written = @fwrite($socket, substr($buffer, $offset));
            if ($written === false || $written === 0) {
                $meta = stream_get_meta_data($socket);
                $timedOut = !empty($meta['timed_out']);
                $eof = !empty($meta['eof']);
                throw new \Exception(
                    'Failed to write complete frame'
                    . ' (written=' . $offset . '/' . $length
                    . ', timed_out=' . ($timedOut ? 'yes' : 'no')
                    . ', eof=' . ($eof ? 'yes' : 'no') . ')'
                );
            }
            $offset += $written;
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

