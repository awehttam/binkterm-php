<?php

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
                    if (function_exists('posix_kill')) {
                        $running = @posix_kill($pidInt, 0);
                    } else {
                        $running = self::isProcessRunningWindows($pidInt);
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
}
