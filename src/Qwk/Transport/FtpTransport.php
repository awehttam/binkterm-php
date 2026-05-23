<?php

namespace BinktermPHP\Qwk\Transport;

use BinktermPHP\Qwk\QwkMailboxManager;

class FtpTransport implements TransportInterface
{
    private QwkMailboxManager $mailboxManager;

    public function __construct(?QwkMailboxManager $mailboxManager = null)
    {
        $this->mailboxManager = $mailboxManager ?? new QwkMailboxManager();
    }

    public function downloadPacket(array $mailbox): ?string
    {
        $conn = $this->connect($mailbox);
        try {
            $remotePath = $this->buildRemotePath($mailbox, strtoupper((string)$mailbox['bbs_id']) . '.QWK');
            $tmpPath = tempnam(sys_get_temp_dir(), 'qwkdl_');
            if ($tmpPath === false) {
                throw new \RuntimeException('Failed to allocate temporary download path');
            }

            if (!@ftp_get($conn, $tmpPath, $remotePath, FTP_BINARY)) {
                @unlink($tmpPath);
                return null;
            }

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
            return (bool)@ftp_put($conn, $remotePath, $localPacketPath, FTP_BINARY);
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
        $conn = @ftp_connect($host, $port, 20);
        if ($conn === false) {
            throw new \RuntimeException('Failed to connect to FTP host');
        }

        $password = $this->mailboxManager->decryptPassword((string)($mailbox['password'] ?? ''));
        if (!@ftp_login($conn, (string)$mailbox['username'], $password)) {
            @ftp_close($conn);
            throw new \RuntimeException('FTP login failed');
        }

        @ftp_pasv($conn, true);
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
}
