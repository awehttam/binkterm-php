<?php

/**
 * Focused coverage for scripts/logrotate.php, exercised against a disposable
 * --logs-dir so the project's real data/logs is never touched.
 *
 * Proves: a small log is left alone, a large log rotates + compresses, the
 * retained generation count is bounded, writes continue into the active log
 * after rotation, unrelated small logs are untouched, and archive permissions
 * stay sane.
 */

use PHPUnit\Framework\TestCase;

class LogRotateTest extends TestCase
{
    private string $script;
    private string $dir;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 2) . '/scripts/logrotate.php';
        $this->assertFileExists($this->script);

        $this->dir = sys_get_temp_dir() . '/lrtest_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
    }

    private function writeLog(string $name, int $bytes, string $fill = 'a'): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, str_repeat($fill, $bytes));
        chmod($path, 0640);
        return $path;
    }

    private function runRotate(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->script);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>&1';
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    private function generations(string $base): array
    {
        $g = glob($this->dir . '/old/' . $base . '.*.gz') ?: [];
        sort($g);
        return $g;
    }

    public function testSmallLogBelowThresholdIsLeftAlone(): void
    {
        $log = $this->writeLog('binkp_poll.log', 200);

        [$code, $output] = $this->runRotate(['--logs-dir=' . $this->dir, '--max-size=1M', '--keep=5']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(200, filesize($log), 'small log must not be truncated');
        $this->assertSame([], $this->generations('binkp_poll.log'), 'small log must not be rotated');
    }

    public function testLargeLogAboveThresholdRotatesAndCompresses(): void
    {
        $log = $this->writeLog('binkp_poll.log', 1_500_000);

        [$code, $output] = $this->runRotate(['--logs-dir=' . $this->dir, '--max-size=1M', '--keep=5']);

        $this->assertSame(0, $code, $output);
        clearstatcache();
        $this->assertSame(0, filesize($log), 'active log is truncated in place');
        $this->assertTrue(is_file($log), 'active log file still exists');

        $gz = $this->generations('binkp_poll.log');
        $this->assertCount(1, $gz);
        $this->assertStringEndsWith('/old/binkp_poll.log.0.gz', $gz[0]);

        $plain = gzdecode(file_get_contents($gz[0]));
        $this->assertSame(1_500_000, strlen($plain), 'archived content matches the pre-rotation log');
    }

    public function testRetentionCountIsBounded(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->writeLog('binkp_poll.log', 1_500_000);
            [$code, $output] = $this->runRotate(['--logs-dir=' . $this->dir, '--max-size=1M', '--keep=3']);
            $this->assertSame(0, $code, $output);
        }

        $gz = $this->generations('binkp_poll.log');
        $this->assertCount(3, $gz, 'exactly --keep generations retained');
        $this->assertSame(
            ['binkp_poll.log.0.gz', 'binkp_poll.log.1.gz', 'binkp_poll.log.2.gz'],
            array_map('basename', $gz)
        );
    }

    public function testWritesContinueIntoActiveLogAfterRotation(): void
    {
        $log = $this->writeLog('binkp_scheduler.log', 1_500_000);
        $this->runRotate(['--logs-dir=' . $this->dir, '--max-size=1M', '--keep=3']);

        clearstatcache();
        $this->assertSame(0, filesize($log));

        file_put_contents($log, "fresh line after rotation\n", FILE_APPEND | LOCK_EX);
        $this->assertSame("fresh line after rotation\n", file_get_contents($log));
    }

    public function testUnrelatedSmallLogIsUntouched(): void
    {
        $this->writeLog('binkp_poll.log', 1_500_000);
        $other = $this->writeLog('realtime_server.log', 512);
        $otherBefore = file_get_contents($other);

        [$code] = $this->runRotate(['--logs-dir=' . $this->dir, '--max-size=1M', '--keep=3']);

        $this->assertSame(0, $code);
        $this->assertSame($otherBefore, file_get_contents($other), 'unrelated small log unchanged');
        $this->assertSame([], $this->generations('realtime_server.log'));
        $this->assertNotSame([], $this->generations('binkp_poll.log'));
    }

    public function testPermissionsAfterRotation(): void
    {
        $log = $this->writeLog('binkp_server.log', 1_500_000);
        $this->runRotate(['--logs-dir=' . $this->dir, '--max-size=1M', '--keep=3']);

        clearstatcache();
        $this->assertSame('0640', substr(sprintf('%o', fileperms($log)), -4), 'active log keeps its mode');

        $gz = $this->generations('binkp_server.log')[0];
        $perms = fileperms($gz) & 0o777;
        $this->assertSame(0, $perms & 0o002, 'archive must not be world-writable');
        $this->assertSame(0, $perms & 0o020, 'archive must not be group-writable');
    }

    public function testWithoutThresholdEveryLogRotates(): void
    {
        $this->writeLog('binkp_poll.log', 50);
        $this->writeLog('binkp_server.log', 50);

        [$code, $output] = $this->runRotate(['--logs-dir=' . $this->dir, '--keep=5']);

        $this->assertSame(0, $code, $output);
        $this->assertCount(1, $this->generations('binkp_poll.log'));
        $this->assertCount(1, $this->generations('binkp_server.log'));
    }

    public function testInvalidMaxSizeIsRejected(): void
    {
        $this->writeLog('binkp_poll.log', 1_500_000);

        [$code, $output] = $this->runRotate(['--logs-dir=' . $this->dir, '--max-size=banana']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Invalid --max-size', $output);
        $this->assertSame([], $this->generations('binkp_poll.log'), 'nothing rotated on bad input');
    }

    public function testDryRunTouchesNothing(): void
    {
        $log = $this->writeLog('binkp_poll.log', 1_500_000);

        [$code, $output] = $this->runRotate(['--logs-dir=' . $this->dir, '--max-size=1M', '--dry-run']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('[dry-run]', $output);
        $this->assertSame(1_500_000, filesize($log), 'dry-run must not truncate');
        $this->assertFalse(is_dir($this->dir . '/old'), 'dry-run must not create old/');
    }
}
