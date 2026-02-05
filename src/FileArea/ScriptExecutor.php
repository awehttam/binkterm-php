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


namespace BinktermPHP\FileArea;

/**
 * ScriptExecutor - Execute shell commands with timeout and capture output.
 */
class ScriptExecutor
{
    private string $stdout = '';
    private string $stderr = '';
    private int $exitCode = 0;

    /**
     * Execute a command with timeout.
     *
     * @param string $command
     * @param int $timeout
     * @return array
     */
    public function execute(string $command, int $timeout = 600): array
    {
        $this->stdout = '';
        $this->stderr = '';
        $this->exitCode = 0;

        $command = trim($command);
        if ($command === '') {
            return $this->buildResult(false, false);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            $this->exitCode = 1;
            $this->stderr = 'Failed to start process';
            return $this->buildResult(false, false);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $timedOut = false;
        $start = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            $this->stdout .= stream_get_contents($pipes[1]);
            $this->stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $this->exitCode = $status['exitcode'];
                break;
            }

            if ((microtime(true) - $start) > $timeout) {
                $timedOut = true;
                $this->exitCode = -1;
                $this->killOnTimeout($process);
                break;
            }

            usleep(100000);
        }

        $this->stdout .= stream_get_contents($pipes[1]);
        $this->stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $closeCode = proc_close($process);
        if (!$timedOut && $closeCode !== -1) {
            $this->exitCode = $closeCode;
        }

        return $this->buildResult($timedOut, true);
    }

    /**
     * @return string
     */
    public function getStdout(): string
    {
        return $this->stdout;
    }

    /**
     * @return string
     */
    public function getStderr(): string
    {
        return $this->stderr;
    }

    /**
     * @return int
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * @param resource $process
     * @return void
     */
    private function killOnTimeout($process): void
    {
        proc_terminate($process);
        $start = microtime(true);
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $start) > 2) {
                proc_terminate($process, 9);
                break;
            }
            usleep(100000);
        }
    }

    /**
     * @param bool $timedOut
     * @param bool $ran
     * @return array
     */
    private function buildResult(bool $timedOut, bool $ran): array
    {
        return [
            'exit_code' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'timed_out' => $timedOut,
            'ran' => $ran
        ];
    }
}

