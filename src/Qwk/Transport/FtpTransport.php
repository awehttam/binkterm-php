<?php

namespace BinktermPHP\Qwk\Transport;

use BinktermPHP\Qwk\QwkMailboxManager;

class FtpTransport implements TransportInterface
{
    private QwkMailboxManager $mailboxManager;
    /** @var callable|null */
    private $logger = null;

    public function __construct(?QwkMailboxManager $mailboxManager = null)
    {
        $this->mailboxManager = $mailboxManager ?? new QwkMailboxManager();
    }

    public function setLogger(?callable $logger): void
    {
        $this->logger = $logger;
    }

    public function downloadPacket(array $mailbox): ?string
    {
        $conn = $this->connect($mailbox);
        try {
            $remotePath = $this->buildRemotePath($mailbox, strtoupper((string)$mailbox['bbs_id']) . '.QWK');
            $this->log('DEBUG', sprintf('FTP RETR %s', $remotePath));
            $tmpPath = tempnam(sys_get_temp_dir(), 'qwkdl_');
            if ($tmpPath === false) {
                throw new \RuntimeException('Failed to allocate temporary download path');
            }

            $remoteSize = @ftp_size($conn, $remotePath);
            if ($remoteSize > -1) {
                $this->log('DEBUG', sprintf('FTP SIZE %s => %d bytes', $remotePath, $remoteSize));
            } else {
                $this->log('DEBUG', sprintf('FTP SIZE %s => unavailable', $remotePath));
            }

            $status = @ftp_nb_get($conn, $tmpPath, $remotePath, FTP_BINARY);
            if ($status === FTP_FAILED) {
                $this->log('DEBUG', sprintf('FTP RETR %s => no file or transfer failed', $remotePath));
                @unlink($tmpPath);
                return null;
            }

            $this->logTransferProgress($conn, $status, $tmpPath, $remotePath, $remoteSize, false);
            return $tmpPath;
        } finally {
            @ftp_close($conn);
        }
    }

    public function uploadPacket(array $mailbox, string $localPacketPath): bool
    {
        $conn = $this->connect($mailbox);
        try {
            $remotePath = $this->buildRemotePath($mailbox, strtoupper((string)$mailbox['bbs_id']) . '.REP');
            $localSize = @filesize($localPacketPath);
            $this->log('DEBUG', sprintf(
                'FTP STOR %s%s',
                $remotePath,
                $localSize !== false ? sprintf(' (%d bytes)', $localSize) : ''
            ));

            $status = @ftp_nb_put($conn, $remotePath, $localPacketPath, FTP_BINARY);
            if ($status === FTP_FAILED) {
                $this->log('DEBUG', sprintf('FTP STOR %s => failed to start transfer', $remotePath));
                return false;
            }

            return $this->logTransferProgress($conn, $status, $localPacketPath, $remotePath, $localSize === false ? -1 : $localSize, true);
        } finally {
            @ftp_close($conn);
        }
    }

    /**
     * @return resource
     */
    private function connect(array $mailbox)
    {
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('PHP FTP extension is not available');
        }

        $host = (string)$mailbox['host'];
        $port = (int)($mailbox['port'] ?? 21);
        $this->log('DEBUG', sprintf('FTP CONNECT %s:%d', $host, $port));
        $conn = @ftp_connect($host, $port, 20);
        if ($conn === false) {
            throw new \RuntimeException('Failed to connect to FTP host');
        }

        $password = $this->mailboxManager->decryptPassword((string)($mailbox['password'] ?? ''));
        $this->log('DEBUG', sprintf('FTP LOGIN %s', (string)$mailbox['username']));
        if (!@ftp_login($conn, (string)$mailbox['username'], $password)) {
            @ftp_close($conn);
            throw new \RuntimeException('FTP login failed');
        }

        $pwd = @ftp_pwd($conn);
        if (is_string($pwd) && $pwd !== '') {
            $this->log('DEBUG', sprintf('FTP PWD => %s', $pwd));
        }

        $passiveMode = !array_key_exists('passive_mode', $mailbox)
            ? true
            : filter_var($mailbox['passive_mode'], FILTER_VALIDATE_BOOLEAN);
        @ftp_pasv($conn, $passiveMode);
        $this->log('DEBUG', 'FTP PASV ' . ($passiveMode ? 'enabled' : 'disabled'));
        return $conn;
    }

    private function buildRemotePath(array $mailbox, string $filename): string
    {
        $base = trim((string)($mailbox['ftp_remote_path'] ?? '/'));
        if ($base === '' || $base === '.') {
            return $filename;
        }

        return rtrim(str_replace('\\', '/', $base), '/') . '/' . $filename;
    }

    /**
     * @param resource $conn
     */
    private function logTransferProgress($conn, int $status, string $localPath, string $remotePath, int $knownSize, bool $upload): bool
    {
        $lastBucket = -1;
        while ($status === FTP_MOREDATA) {
            clearstatcache(true, $localPath);
            $currentSize = @filesize($localPath);
            $bucket = $this->progressBucket($currentSize === false ? 0 : (int)$currentSize, $knownSize);
            if ($bucket !== $lastBucket) {
                $direction = $upload ? 'upload' : 'download';
                $this->log('DEBUG', sprintf(
                    'FTP %s progress %s: %s',
                    $upload ? 'STOR' : 'RETR',
                    $remotePath,
                    $this->formatProgress($currentSize === false ? 0 : (int)$currentSize, $knownSize, $direction)
                ));
                $lastBucket = $bucket;
            }
            $status = @ftp_nb_continue($conn);
        }

        if ($status === FTP_FINISHED) {
            clearstatcache(true, $localPath);
            $finalSize = @filesize($localPath);
            $direction = $upload ? 'uploaded' : 'downloaded';
            $this->log('DEBUG', sprintf(
                'FTP %s %s complete: %s',
                $upload ? 'STOR' : 'RETR',
                $remotePath,
                $this->formatProgress($finalSize === false ? 0 : (int)$finalSize, $knownSize, $direction)
            ));
            return true;
        }

        $this->log('DEBUG', sprintf('FTP %s %s failed during transfer', $upload ? 'STOR' : 'RETR', $remotePath));
        return false;
    }

    private function progressBucket(int $currentSize, int $knownSize): int
    {
        if ($knownSize > 0) {
            return (int)floor(($currentSize / max(1, $knownSize)) * 10);
        }

        return (int)floor($currentSize / (256 * 1024));
    }

    private function formatProgress(int $currentSize, int $knownSize, string $verb): string
    {
        if ($knownSize > 0) {
            $percent = min(100, (int)floor(($currentSize / max(1, $knownSize)) * 100));
            return sprintf('%s %d/%d bytes (%d%%)', $verb, $currentSize, $knownSize, $percent);
        }

        return sprintf('%s %d bytes', $verb, $currentSize);
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }
}
