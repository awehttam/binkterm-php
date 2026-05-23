<?php

namespace BinktermPHP\Qwk\Transport;

use BinktermPHP\Qwk\QwkUplinkManager;

class FtpTransport implements TransportInterface
{
    private QwkUplinkManager $uplinkManager;

    public function __construct(?QwkUplinkManager $uplinkManager = null)
    {
        $this->uplinkManager = $uplinkManager ?? new QwkUplinkManager();
    }

    public function downloadPacket(array $uplink): ?string
    {
        $conn = $this->connect($uplink);
        try {
            $remotePath = $this->buildRemotePath($uplink, strtoupper((string)$uplink['bbs_id']) . '.QWK');
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

    public function uploadPacket(array $uplink, string $localPacketPath): bool
    {
        $conn = $this->connect($uplink);
        try {
            $remotePath = $this->buildRemotePath($uplink, strtoupper((string)$uplink['bbs_id']) . '.REP');
            return (bool)@ftp_put($conn, $remotePath, $localPacketPath, FTP_BINARY);
        } finally {
            @ftp_close($conn);
        }
    }

    /**
     * @return resource
     */
    private function connect(array $uplink)
    {
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('PHP FTP extension is not available');
        }

        $host = (string)$uplink['host'];
        $port = (int)($uplink['port'] ?? 21);
        $conn = @ftp_connect($host, $port, 20);
        if ($conn === false) {
            throw new \RuntimeException('Failed to connect to FTP host');
        }

        $password = $this->uplinkManager->decryptPassword((string)($uplink['password'] ?? ''));
        if (!@ftp_login($conn, (string)$uplink['username'], $password)) {
            @ftp_close($conn);
            throw new \RuntimeException('FTP login failed');
        }

        @ftp_pasv($conn, true);
        return $conn;
    }

    private function buildRemotePath(array $uplink, string $filename): string
    {
        $base = trim((string)($uplink['ftp_remote_path'] ?? '/'));
        if ($base === '' || $base === '.') {
            return $filename;
        }

        return rtrim(str_replace('\\', '/', $base), '/') . '/' . $filename;
    }
}
