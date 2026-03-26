#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Auth;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\DoorSessionManager;
use BinktermPHP\Version;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Queue\InboundQueue;
use BinktermPHP\Binkp\Queue\OutboundQueue;

function showUsage(): void
{
    echo "Usage: php scripts/binktop.php [options]\n";
    echo "Options:\n";
    echo "  --json            Output machine-readable JSON\n";
    echo "  --minutes=N       Online-user activity window in minutes (default: 15)\n";
    echo "  --interval=N      Refresh interval in seconds (default: 2)\n";
    echo "  --once            Render once and exit\n";
    echo "  --help            Show this help message\n";
}

function parseArgs(array $argv): array
{
    $args = [];

    foreach ($argv as $arg) {
        if (strpos($arg, '--') !== 0) {
            continue;
        }

        $option = substr($arg, 2);
        if (strpos($option, '=') !== false) {
            [$key, $value] = explode('=', $option, 2);
            $args[$key] = $value;
            continue;
        }

        $args[$option] = true;
    }

    return $args;
}

function safeSection(callable $callback, mixed $fallback = null): mixed
{
    try {
        return $callback();
    } catch (Throwable $e) {
        return $fallback;
    }
}

function formatBytes(float|int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unitIndex = 0;

    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }

    return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $units[$unitIndex];
}

function formatKb(int $kilobytes): string
{
    return formatBytes($kilobytes * 1024);
}

function formatDuration(int $seconds): string
{
    if ($seconds < 0) {
        $seconds = 0;
    }

    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0 || $days > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0 || $hours > 0 || $days > 0) {
        $parts[] = $minutes . 'm';
    }
    $parts[] = $seconds . 's';

    return implode(' ', $parts);
}

function formatTimestamp(?string $timestamp): string
{
    if ($timestamp === null || $timestamp === '') {
        return '-';
    }

    try {
        return (new DateTime($timestamp))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $timestamp;
    }
}

function formatCompactTimestamp(?string $timestamp): string
{
    if ($timestamp === null || $timestamp === '') {
        return '-';
    }

    try {
        return (new DateTime($timestamp))->format('m-d H:i');
    } catch (Throwable $e) {
        return truncateCell($timestamp, 8);
    }
}

function runCommand(string $command): ?string
{
    $output = @shell_exec($command);
    if (!is_string($output)) {
        return null;
    }

    $trimmed = trim($output);
    return $trimmed === '' ? null : $trimmed;
}

function getSystemUptimeSeconds(): ?int
{
    if (PHP_OS_FAMILY === 'Windows') {
        $output = runCommand('powershell -NoProfile -Command "try { $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop; if ($os -and $os.LastBootUpTime) { $os.LastBootUpTime.ToUniversalTime().ToString(' . "'o'" . ') } } catch {}" 2>$null');
        if ($output === null) {
            return null;
        }

        try {
            $bootTime = new DateTime($output, new DateTimeZone('UTC'));
            return max(0, time() - $bootTime->getTimestamp());
        } catch (Throwable $e) {
            return null;
        }
    }

    $uptimeFile = '/proc/uptime';
    if (is_file($uptimeFile)) {
        $content = trim((string)@file_get_contents($uptimeFile));
        if ($content !== '') {
            $parts = preg_split('/\s+/', $content);
            if (isset($parts[0]) && is_numeric($parts[0])) {
                return (int)floor((float)$parts[0]);
            }
        }
    }

    $output = runCommand('uptime -s');
    if ($output === null) {
        return null;
    }

    try {
        $bootTime = new DateTime($output);
        return max(0, time() - $bootTime->getTimestamp());
    } catch (Throwable $e) {
        return null;
    }
}

function getSystemMemorySummary(): ?array
{
    if (PHP_OS_FAMILY === 'Windows') {
        $output = runCommand('powershell -NoProfile -Command "try { $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop; if ($os) { $total = [int64]$os.TotalVisibleMemorySize * 1024; $free = [int64]$os.FreePhysicalMemory * 1024; if ($total -gt 0) { [pscustomobject]@{total=$total; free=$free; used=($total-$free)} | ConvertTo-Json -Compress } } } catch {}" 2>$null');
        if ($output === null) {
            return null;
        }

        $data = json_decode($output, true);
        if (!is_array($data) || !isset($data['total'], $data['used'], $data['free'])) {
            return null;
        }

        return [
            'total_bytes' => (int)$data['total'],
            'used_bytes' => (int)$data['used'],
            'free_bytes' => (int)$data['free'],
        ];
    }

    $meminfoFile = '/proc/meminfo';
    if (!is_file($meminfoFile)) {
        return null;
    }

    $meminfo = (string)@file_get_contents($meminfoFile);
    if ($meminfo === '') {
        return null;
    }

    if (!preg_match('/^MemTotal:\s+(\d+)\s+kB$/mi', $meminfo, $totalMatch)) {
        return null;
    }

    $totalKb = (int)$totalMatch[1];
    $availableKb = 0;

    if (preg_match('/^MemAvailable:\s+(\d+)\s+kB$/mi', $meminfo, $availableMatch)) {
        $availableKb = (int)$availableMatch[1];
    } elseif (preg_match('/^MemFree:\s+(\d+)\s+kB$/mi', $meminfo, $freeMatch)) {
        $availableKb = (int)$freeMatch[1];
    }

    $usedKb = max(0, $totalKb - $availableKb);

    return [
        'total_bytes' => $totalKb * 1024,
        'used_bytes' => $usedKb * 1024,
        'free_bytes' => $availableKb * 1024,
    ];
}

function getLoadAverage(): ?array
{
    if (!function_exists('sys_getloadavg')) {
        return null;
    }

    $loads = @sys_getloadavg();
    if (!is_array($loads) || count($loads) < 3) {
        return null;
    }

    return [
        '1m' => (float)$loads[0],
        '5m' => (float)$loads[1],
        '15m' => (float)$loads[2],
    ];
}

function getDiskSummary(string $path): ?array
{
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if (!is_numeric($total) || !is_numeric($free)) {
        return null;
    }

    return [
        'path' => $path,
        'total_bytes' => (int)$total,
        'used_bytes' => (int)$total - (int)$free,
        'free_bytes' => (int)$free,
    ];
}

function getRamUsageReport(): ?array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return null;
    }

    $scriptPath = realpath(__DIR__ . '/ram_usage.sh');
    if ($scriptPath === false || !is_file($scriptPath)) {
        return null;
    }

    $output = runCommand('bash ' . escapeshellarg($scriptPath) . ' --json 2>&1');
    if ($output === null) {
        return null;
    }

    $data = json_decode($output, true);
    return is_array($data) ? $data : null;
}

function isPidRunning(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $output = runCommand('powershell -NoProfile -Command "Get-Process -Id ' . $pid . ' -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id" 2>$null');
        return $output !== null && trim($output) === (string)$pid;
    }

    if (is_dir('/proc/' . $pid)) {
        return true;
    }

    return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
}

function getProcessMemoryKb(?string $pid): ?int
{
    if ($pid === null || !ctype_digit($pid)) {
        return null;
    }

    $pidInt = (int)$pid;
    if ($pidInt <= 0) {
        return null;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $output = runCommand('powershell -NoProfile -Command "Get-Process -Id ' . $pidInt . ' -ErrorAction SilentlyContinue | Select-Object -ExpandProperty WorkingSet64" 2>$null');
        if ($output === null || !is_numeric($output)) {
            return null;
        }
        return (int)round(((float)$output) / 1024);
    }

    $statusFile = '/proc/' . $pidInt . '/status';
    if (!is_file($statusFile)) {
        return null;
    }

    $content = (string)@file_get_contents($statusFile);
    if ($content === '') {
        return null;
    }

    if (preg_match('/^VmRSS:\s+(\d+)\s+kB$/mi', $content, $matches)) {
        return (int)$matches[1];
    }

    return null;
}

function getDaemonStatusSnapshot(): array
{
    $runDir = __DIR__ . '/../data/run';

    $coreDaemons = [
        'admin_daemon' => Config::env('ADMIN_DAEMON_PID_FILE', $runDir . '/admin_daemon.pid'),
        'binkp_scheduler' => Config::env('BINKP_SCHEDULER_PID_FILE', $runDir . '/binkp_scheduler.pid'),
        'binkp_server' => Config::env('BINKP_SERVER_PID_FILE', $runDir . '/binkp_server.pid'),
        'realtime_server' => Config::env('BINKSTREAM_WS_PID_FILE', Config::env('REALTIME_WS_PID_FILE', $runDir . '/realtime_server.pid')),
    ];

    $optionalDaemons = [
        'telnetd' => Config::env('TELNETD_PID_FILE', $runDir . '/telnetd.pid'),
        'ssh_daemon' => Config::env('SSHD_PID_FILE', $runDir . '/sshd.pid'),
        'gemini_daemon' => Config::env('GEMINI_PID_FILE', $runDir . '/gemini_daemon.pid'),
        'mrc_daemon' => Config::env('MRC_PID_FILE', $runDir . '/mrc_daemon.pid'),
        'multiplexing_server' => Config::env('MULTIPLEX_PID_FILE', $runDir . '/multiplexing-server.pid'),
        'mcp_server' => Config::env('MCP_SERVER_PID_FILE', $runDir . '/mcp-server.pid'),
    ];

    $status = [];

    foreach ($coreDaemons as $name => $pidFile) {
        $status[$name] = buildDaemonStatusRow($pidFile, false);
    }

    foreach ($optionalDaemons as $name => $pidFile) {
        $status[$name] = buildDaemonStatusRow($pidFile, true);
    }

    return $status;
}

function buildDaemonStatusRow(string $pidFile, bool $optional): array
{
    $configured = file_exists($pidFile);
    $pid = null;
    $running = false;

    if ($configured) {
        $rawPid = trim((string)@file_get_contents($pidFile));
        if ($rawPid !== '' && ctype_digit($rawPid)) {
            $pid = $rawPid;
            $running = isPidRunning((int)$rawPid);
        }
    }

    return [
        'pid_file' => $pidFile,
        'pid' => $pid,
        'running' => $running,
        'optional' => $optional,
        'configured' => $configured || !$optional,
    ];
}

function getSessionSummary(\PDO $db): array
{
    $summary = [
        'valid_user_sessions' => null,
        'total_sessions' => null,
    ];

    try {
        $row = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE u.is_active = TRUE AND COALESCE(u.is_system, FALSE) = FALSE) AS valid_user_sessions,
                COUNT(*) FILTER (WHERE u.is_active = TRUE) AS total_sessions
            FROM user_sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.expires_at > NOW()
        ")->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $summary['valid_user_sessions'] = (int)$row['valid_user_sessions'];
            $summary['total_sessions'] = (int)$row['total_sessions'];
        }
    } catch (Throwable $e) {
        try {
            $count = $db->query("SELECT COUNT(*) AS total_sessions FROM user_sessions WHERE expires_at > NOW()")->fetch(PDO::FETCH_ASSOC);
            $summary['valid_user_sessions'] = isset($count['total_sessions']) ? (int)$count['total_sessions'] : null;
            $summary['total_sessions'] = isset($count['total_sessions']) ? (int)$count['total_sessions'] : null;
        } catch (Throwable $inner) {
        }
    }

    return $summary;
}

function getPostgresConnectionSummary(\PDO $db): array
{
    $summary = [
        'total' => null,
        'active' => null,
        'idle' => null,
    ];

    $row = $db->query("
        SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE state = 'active') AS active,
            COUNT(*) FILTER (WHERE state = 'idle') AS idle
        FROM pg_stat_activity
        WHERE datname = current_database()
    ")->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $summary['total'] = (int)$row['total'];
        $summary['active'] = (int)$row['active'];
        $summary['idle'] = (int)$row['idle'];
    }

    return $summary;
}

function getTerminalSize(): array
{
    $columns = 120;
    $lines = 40;

    if (PHP_OS_FAMILY === 'Windows') {
        $output = runCommand('powershell -NoProfile -Command "$h = $Host.UI.RawUI.WindowSize.Height; $w = $Host.UI.RawUI.WindowSize.Width; Write-Output ($w.ToString() + ' . "'x'" . ' + $h.ToString())" 2>$null');
        if ($output !== null && preg_match('/^(\d+)x(\d+)$/', trim($output), $matches)) {
            return [
                'columns' => max(40, (int)$matches[1]),
                'lines' => max(20, (int)$matches[2]),
            ];
        }
    } else {
        $output = runCommand('stty size 2>/dev/null');
        if ($output !== null && preg_match('/^(\d+)\s+(\d+)$/', trim($output), $matches)) {
            return [
                'columns' => max(40, (int)$matches[2]),
                'lines' => max(20, (int)$matches[1]),
            ];
        }
    }

    return ['columns' => $columns, 'lines' => $lines];
}

function truncateCell(string $value, int $width): string
{
    if ($width < 1) {
        return '';
    }
    if (strlen($value) <= $width) {
        return $value;
    }
    if ($width <= 3) {
        return substr($value, 0, $width);
    }
    return substr($value, 0, $width - 3) . '...';
}

function buildTableLines(array $rows, array $widthHints = [], ?int $maxRows = null): array
{
    if (empty($rows)) {
        return ['(none)'];
    }

    if ($maxRows !== null) {
        $rows = array_slice($rows, 0, max(0, $maxRows));
    }

    $headers = array_keys($rows[0]);
    $widths = [];
    foreach ($headers as $header) {
        $widths[$header] = max(strlen($header), (int)($widthHints[$header] ?? 0));
    }

    foreach ($rows as $row) {
        foreach ($headers as $header) {
            $widths[$header] = max($widths[$header], strlen((string)($row[$header] ?? '')));
        }
    }

    $lines = [];
    $headerLine = '';
    $separatorLine = '';

    foreach ($headers as $header) {
        $headerLine .= str_pad($header, $widths[$header] + 2);
        $separatorLine .= str_repeat('-', $widths[$header]) . '  ';
    }
    $lines[] = rtrim($headerLine);
    $lines[] = rtrim($separatorLine);

    foreach ($rows as $row) {
        $line = '';
        foreach ($headers as $header) {
            $line .= str_pad(truncateCell((string)($row[$header] ?? ''), $widths[$header]), $widths[$header] + 2);
        }
        $lines[] = rtrim($line);
    }

    return $lines;
}

function wrapSection(string $title, array $bodyLines): array
{
    return array_merge([$title], $bodyLines, ['']);
}

function fitLineToWidth(string $line, int $width): string
{
    return truncateCell($line, max(1, $width));
}

function fitTableToHeight(array $rows, array $widthHints, int $availableHeight): array
{
    if ($availableHeight <= 0) {
        return [];
    }

    if ($availableHeight === 1) {
        return ['(truncated)'];
    }

    if (empty($rows)) {
        return ['(none)'];
    }

    $needsOverflowLine = count($rows) > max(0, $availableHeight - 2);
    $maxRows = $needsOverflowLine
        ? max(0, $availableHeight - 3)
        : max(0, $availableHeight - 2);
    $visibleRows = array_slice($rows, 0, $maxRows);
    $lines = buildTableLines($visibleRows, $widthHints);

    if (count($rows) > $maxRows) {
        $remaining = count($rows) - $maxRows;
        $lines[] = '(' . $remaining . ' more)';
    }

    return array_slice($lines, 0, $availableHeight);
}

function buildTwoColumnTableLines(array $rows, array $widthHints, int $availableHeight, int $terminalWidth): array
{
    if ($availableHeight <= 0) {
        return [];
    }

    if (empty($rows)) {
        return ['(none)'];
    }

    $perColumn = (int)ceil(count($rows) / 2);
    $leftRows = array_slice($rows, 0, $perColumn);
    $rightRows = array_slice($rows, $perColumn);

    $leftLines = buildTableLines($leftRows, $widthHints);
    $rightLines = buildTableLines($rightRows, $widthHints);

    $leftWidth = 0;
    foreach ($leftLines as $line) {
        $leftWidth = max($leftWidth, strlen($line));
    }

    $gap = 4;
    if ($leftWidth + $gap + 20 > $terminalWidth) {
        return fitTableToHeight($rows, $widthHints, $availableHeight);
    }

    $maxLineCount = max(count($leftLines), count($rightLines));
    $combined = [];
    for ($i = 0; $i < $maxLineCount; $i++) {
        $left = $leftLines[$i] ?? '';
        $right = $rightLines[$i] ?? '';
        if ($right === '') {
            $combined[] = rtrim($left);
            continue;
        }
        $combined[] = rtrim(str_pad($left, $leftWidth + $gap) . $right);
    }

    if (count($combined) > $availableHeight) {
        return array_slice($combined, 0, $availableHeight - 1) + ['(truncated)'];
    }

    return $combined;
}

function clearScreen(): void
{
    echo "\033[H\033[2J";
}

function buildDaemonRows(array $daemonStatus): array
{
    $rows = [];
    foreach ($daemonStatus as $name => $info) {
        $status = 'stopped';
        if (!($info['configured'] ?? true)) {
            $status = 'n/a';
        } elseif (!empty($info['running'])) {
            $status = 'run';
        }

        $rows[] = [
            'daemon' => $name,
            'state' => $status,
            'pid' => (string)($info['pid'] ?? '-'),
            'rss' => isset($info['rss_kb']) && $info['rss_kb'] !== null ? formatKb((int)$info['rss_kb']) : '-',
        ];
    }

    return $rows;
}

function buildUserRows(array $onlineUsers): array
{
    return array_map(static function (array $user): array {
        return [
            'user' => (string)($user['username'] ?? '-'),
            'svc' => (string)($user['service'] ?? '-'),
            'activity' => (string)($user['activity'] ?? '-'),
            'ip' => (string)($user['ip_address'] ?? '-'),
            'last' => formatCompactTimestamp($user['last_activity'] ?? null),
        ];
    }, $onlineUsers);
}

function buildDoorRows(array $doorSessions): array
{
    return array_map(static function (array $session): array {
        return [
            'door' => (string)($session['door_name'] ?? $session['door_id'] ?? '-'),
            'uid' => (string)($session['user_id'] ?? '-'),
            'node' => (string)($session['node'] ?? '-'),
            'ws' => (string)($session['ws_port'] ?? '-'),
            'bridge' => (string)($session['bridge_pid'] ?? '-'),
            'proc' => (string)($session['dosbox_pid'] ?? '-'),
        ];
    }, $doorSessions);
}

function buildHeaderLines(array $snapshot, int $interval, int $columns): array
{
    $loadAverage = $snapshot['load_average'];
    $memory = $snapshot['memory'];
    $disk = $snapshot['disk'];
    $sessions = $snapshot['sessions'];
    $postgres = $snapshot['postgres'];
    $queues = $snapshot['queues'];

    return [
        fitLineToWidth(sprintf(
            '%s  %s  host:%s  now:%s',
            $snapshot['app_version'],
            PHP_OS_FAMILY,
            $snapshot['host'],
            date('Y-m-d H:i:s')
        ), $columns),
        fitLineToWidth(sprintf(
            'up:%s  load:%s  ram:%s  disk:%s  ref:%ss',
            $snapshot['uptime_seconds'] !== null ? formatDuration((int)$snapshot['uptime_seconds']) : 'n/a',
            $loadAverage !== null ? number_format($loadAverage['1m'], 2) . ' ' . number_format($loadAverage['5m'], 2) . ' ' . number_format($loadAverage['15m'], 2) : 'n/a',
            $memory !== null ? formatBytes($memory['used_bytes']) . '/' . formatBytes($memory['total_bytes']) : 'n/a',
            $disk !== null ? formatBytes($disk['used_bytes']) . '/' . formatBytes($disk['total_bytes']) : 'n/a',
            $interval
        ), $columns),
        fitLineToWidth(sprintf(
            'users:%d  sess:%s  doors:%d  pg:%s  in:%s  out:%s/%s',
            count($snapshot['online_users']),
            ($sessions['valid_user_sessions'] ?? null) !== null && ($sessions['total_sessions'] ?? null) !== null
                ? $sessions['valid_user_sessions'] . '/' . $sessions['total_sessions']
                : 'n/a',
            count($snapshot['door_sessions']),
            $postgres['total'] !== null ? $postgres['total'] . ' (' . $postgres['active'] . 'a/' . $postgres['idle'] . 'i)' : 'n/a',
            (string)($queues['inbound']['pending_files'] ?? 'n/a'),
            (string)($queues['outbound']['pending_files'] ?? 'n/a'),
            isset($queues['outbound']['total_size']) ? formatBytes((int)$queues['outbound']['total_size']) : 'n/a'
        ), $columns),
    ];
}

function assembleScreen(array $snapshot, int $interval): string
{
    $terminal = getTerminalSize();
    $lines = $terminal['lines'];
    $columns = $terminal['columns'];

    $headerLines = buildHeaderLines($snapshot, $interval, $columns);
    $userRows = buildUserRows($snapshot['online_users']);
    $daemonRows = buildDaemonRows($snapshot['daemons']);
    $doorRows = buildDoorRows($snapshot['door_sessions']);

    $remaining = max(6, $lines - count($headerLines) - 1);
    $daemonSectionHeight = min(count($daemonRows) + 3, max(5, min(14, $remaining - 4)));
    $remaining -= $daemonSectionHeight;

    $userSectionHeight = max(4, (int)floor($remaining / 2));
    $doorSectionHeight = max(4, $remaining - $userSectionHeight);

    $daemonWidthHints = $columns <= 80
        ? ['daemon' => 14, 'state' => 4, 'pid' => 5, 'rss' => 8]
        : ['daemon' => 20, 'state' => 5, 'pid' => 6, 'rss' => 10];
    $userWidthHints = $columns <= 80
        ? ['user' => 10, 'svc' => 4, 'activity' => 14, 'ip' => 10, 'last' => 8]
        : ['user' => 14, 'svc' => 6, 'activity' => 24, 'ip' => 18, 'last' => 19];
    $doorWidthHints = $columns <= 80
        ? ['door' => 12, 'uid' => 4, 'node' => 4, 'ws' => 4, 'bridge' => 6, 'proc' => 6]
        : ['door' => 20, 'uid' => 5, 'node' => 4, 'ws' => 5, 'bridge' => 8, 'proc' => 8];

    $daemonLines = ($columns >= 96 && count($daemonRows) >= 6)
        ? buildTwoColumnTableLines($daemonRows, $daemonWidthHints, $daemonSectionHeight, $columns)
        : fitTableToHeight($daemonRows, $daemonWidthHints, $daemonSectionHeight);

    $screenLines = $headerLines;
    $screenLines[] = '';
    $screenLines = array_merge($screenLines, wrapSection('Current Users', fitTableToHeight($userRows, $userWidthHints, $userSectionHeight)));
    $screenLines = array_merge($screenLines, wrapSection('Daemons', $daemonLines));
    $screenLines = array_merge($screenLines, wrapSection('Door Sessions', fitTableToHeight($doorRows, $doorWidthHints, $doorSectionHeight)));

    return implode("\n", array_slice($screenLines, 0, $lines - 1)) . "\n";
}

$args = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

$json = isset($args['json']);
$onlineMinutes = isset($args['minutes']) && ctype_digit((string)$args['minutes']) ? max(1, (int)$args['minutes']) : 15;
$interval = isset($args['interval']) && is_numeric((string)$args['interval']) ? max(1, (int)$args['interval']) : 2;
$renderOnce = isset($args['once']) || $json;

do {
    $db = Database::reconnect()->getPdo();
    $auth = new Auth();

    $onlineUsers = safeSection(static fn() => $auth->getOnlineUsers($onlineMinutes), []);
    $doorSessions = array_values(array_filter(
        safeSection(static fn() => (new DoorSessionManager())->getActiveSessions(), []),
        static function (array $session): bool {
            $expiresAt = $session['expires_at'] ?? null;
            if (!is_string($expiresAt) || $expiresAt === '') {
                return true;
            }

            try {
                return (new DateTime($expiresAt)) > new DateTime('now', new DateTimeZone('UTC'));
            } catch (Throwable $e) {
                return true;
            }
        }
    ));

    $daemonStatus = getDaemonStatusSnapshot();
    foreach ($daemonStatus as $name => $info) {
        $daemonStatus[$name]['rss_kb'] = !empty($info['running'])
            ? getProcessMemoryKb($info['pid'] ?? null)
            : null;
    }

    $snapshot = [
        'generated_at' => gmdate('c'),
        'host' => php_uname('n'),
        'app_version' => Version::getFullVersion(),
        'os' => [
            'family' => PHP_OS_FAMILY,
            'description' => php_uname(),
        ],
        'uptime_seconds' => getSystemUptimeSeconds(),
        'load_average' => getLoadAverage(),
        'memory' => getSystemMemorySummary(),
        'disk' => getDiskSummary(realpath(__DIR__ . '/..') ?: __DIR__ . '/..'),
        'sessions' => safeSection(static fn() => getSessionSummary($db), ['valid_user_sessions' => null, 'total_sessions' => null]),
        'online_window_minutes' => $onlineMinutes,
        'online_users' => $onlineUsers,
        'door_sessions' => $doorSessions,
        'daemons' => $daemonStatus,
        'queues' => safeSection(static function (): array {
            $config = BinkpConfig::getInstance();
            return [
                'inbound' => (new InboundQueue($config))->getStats(),
                'outbound' => (new OutboundQueue($config))->getStats(),
            ];
        }, ['inbound' => null, 'outbound' => null]),
        'postgres' => safeSection(static fn() => getPostgresConnectionSummary($db), ['total' => null, 'active' => null, 'idle' => null]),
    ];

    if ($json) {
        echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    clearScreen();
    echo assembleScreen($snapshot, $interval);

    if ($renderOnce) {
        break;
    }

    sleep($interval);
} while (true);
