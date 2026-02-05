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


namespace BinktermPHP;

class SystemStatus
{
    public static function getDaemonStatus(): array
    {
        $runDir = __DIR__ . '/../data/run';
        $pidFiles = [
            'admin_daemon' => Config::env('ADMIN_DAEMON_PID_FILE', $runDir . '/admin_daemon.pid'),
            'binkp_scheduler' => Config::env('BINKP_SCHEDULER_PID_FILE', $runDir . '/binkp_scheduler.pid'),
            'binkp_server' => Config::env('BINKP_SERVER_PID_FILE', $runDir . '/binkp_server.pid')
        ];

        $status = [];

        foreach ($pidFiles as $name => $pidFile) {
            $pid = null;
            $running = false;

            if (file_exists($pidFile)) {
                $pid = trim(file_get_contents($pidFile));
                if ($pid !== '' && is_numeric($pid)) {
                    $pidInt = (int)$pid;
                    if (PHP_OS_FAMILY === 'Windows') {
                        $running = self::isProcessRunningWindows($pidInt);
                    } else {
                        if (is_dir('/proc/' . $pidInt)) {
                            $running = true;
                        } elseif (function_exists('posix_kill')) {
                            $running = @posix_kill($pidInt, 0);
                        } else {
                            $running = false;
                        }
                    }
                }
            }

            $status[$name] = [
                'pid_file' => $pidFile,
                'pid' => $pid ?: null,
                'running' => $running
            ];
        }

        return $status;
    }

    private static function isProcessRunningWindows(int $pid): bool
    {
        $output = [];
        $cmd = 'tasklist /FI "PID eq ' . $pid . '" /NH';
        @exec($cmd, $output);
        if (empty($output)) {
            return false;
        }

        foreach ($output as $line) {
            if ($line === '' || stripos($line, 'INFO:') === 0) {
                continue;
            }
            if (preg_match('/\b' . preg_quote((string)$pid, '/') . '\b/', $line)) {
                return true;
            }
        }

        return false;
    }

    public static function getGitCommitHash(): ?string
    {
        $gitDir = __DIR__ . '/../.git';
        if (!is_dir($gitDir)) {
            return null;
        }

        $headFile = $gitDir . '/HEAD';
        if (!is_file($headFile)) {
            return null;
        }

        $head = trim((string)@file_get_contents($headFile));
        if ($head === '') {
            return null;
        }

        if (strpos($head, 'ref:') === 0) {
            $ref = trim(substr($head, 4));
            $refPath = $gitDir . '/' . $ref;
            if (is_file($refPath)) {
                $hash = trim((string)@file_get_contents($refPath));
                return $hash !== '' ? $hash : null;
            }
            $packed = $gitDir . '/packed-refs';
            if (is_file($packed)) {
                $packedContent = @file_get_contents($packed);
                if ($packedContent !== false) {
                    foreach (explode("\n", $packedContent) as $line) {
                        $line = trim($line);
                        if ($line === '' || $line[0] === '#') {
                            continue;
                        }
                        if (strpos($line, ' ') !== false) {
                            [$hash, $packedRef] = explode(' ', $line, 2);
                            if ($packedRef === $ref) {
                                return $hash;
                            }
                        }
                    }
                }
            }

            return null;
        }

        return $head;
    }

    /**
     * Get the current git branch name
     *
     * @return string|null The current branch name or null if not in a git repo
     */
    public static function getGitBranch(): ?string
    {
        $gitDir = __DIR__ . '/../.git';
        if (!is_dir($gitDir)) {
            return null;
        }

        $headFile = $gitDir . '/HEAD';
        if (!is_file($headFile)) {
            return null;
        }

        $head = trim((string)@file_get_contents($headFile));
        if ($head === '') {
            return null;
        }

        // HEAD file contains "ref: refs/heads/branchname"
        if (strpos($head, 'ref: refs/heads/') === 0) {
            return substr($head, 16); // Extract branch name after "ref: refs/heads/"
        }

        // If HEAD is detached (contains a commit hash directly), return null or "HEAD"
        return null;
    }
}

