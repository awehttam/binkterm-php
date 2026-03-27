<?php

namespace BinktermPHP\Ftp;

use BinktermPHP\Auth;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Config;
use BinktermPHP\Realtime\LoopServiceInterface;

class FtpServer implements LoopServiceInterface
{
    private string $bindHost;
    private int $port;
    private string $publicHost;
    private int $passivePortStart;
    private int $passivePortEnd;
    private Logger $logger;
    private FtpVirtualFilesystem $vfs;
    /** @var resource|null */
    private $server = null;
    /** @var array<int, array<string, mixed>> */
    private array $clients = [];

    public function __construct(
        string $bindHost,
        int $port,
        string $publicHost,
        int $passivePortStart,
        int $passivePortEnd,
        Logger $logger
    ) {
        $this->bindHost = $bindHost;
        $this->port = $port;
        $this->publicHost = $publicHost;
        $this->passivePortStart = $passivePortStart;
        $this->passivePortEnd = $passivePortEnd;
        $this->logger = $logger;
        $this->vfs = new FtpVirtualFilesystem();
    }

    public function getName(): string
    {
        return 'ftp';
    }

    public function start(): void
    {
        $this->server = $this->createListeningSocket(true);
    }

    public function stop(): void
    {
        foreach (array_keys($this->clients) as $clientId) {
            $this->closeClient($clientId);
        }

        if (is_resource($this->server)) {
            @fclose($this->server);
            $this->server = null;
        }
    }

    public function tick(): void
    {
        foreach (array_keys($this->clients) as $clientId) {
            if (!isset($this->clients[$clientId])) {
                continue;
            }

            if ((time() - (int)$this->clients[$clientId]['last_activity']) > 600) {
                $this->sendResponse($clientId, 421, 'Idle timeout');
                $this->closeClient($clientId);
            }
        }
    }

    public function getReadSockets(): array
    {
        $sockets = [];
        if (is_resource($this->server)) {
            $sockets[] = $this->server;
        }

        foreach ($this->clients as $client) {
            $sockets[] = $client['socket'];
            if (isset($client['passive_listener']) && is_resource($client['passive_listener'])) {
                $sockets[] = $client['passive_listener'];
            }
            if (($client['transfer']['mode'] ?? null) === 'receive' && isset($client['data_socket']) && is_resource($client['data_socket'])) {
                $sockets[] = $client['data_socket'];
            }
        }

        return $sockets;
    }

    public function getWriteSockets(): array
    {
        $sockets = [];
        foreach ($this->clients as $client) {
            if (($client['transfer']['mode'] ?? null) === 'send' && isset($client['data_socket']) && is_resource($client['data_socket'])) {
                $sockets[] = $client['data_socket'];
            }
        }
        return $sockets;
    }

    public function handleReadableSocket($socket): void
    {
        if ($socket === $this->server) {
            $this->acceptClient();
            return;
        }

        foreach ($this->clients as $clientId => $client) {
            if ($socket === $client['socket']) {
                $this->readControlSocket($clientId);
                return;
            }

            if (isset($client['passive_listener']) && $socket === $client['passive_listener']) {
                $this->acceptPassiveDataSocket($clientId);
                return;
            }

            if (isset($client['data_socket']) && $socket === $client['data_socket']) {
                $this->readDataSocket($clientId);
                return;
            }
        }
    }

    public function handleWritableSocket($socket): void
    {
        foreach ($this->clients as $clientId => $client) {
            if (isset($client['data_socket']) && $socket === $client['data_socket']) {
                $this->writeDataSocket($clientId);
                return;
            }
        }
    }

    /**
     * @return resource
     */
    public function createListeningSocket(bool $nonBlocking = true)
    {
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(
            "tcp://{$this->bindHost}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!$server) {
            throw new \RuntimeException("Failed to bind FTP server: {$errstr} ({$errno})");
        }

        stream_set_blocking($server, !$nonBlocking ? true : false);
        $this->logger->info('FTP server listening', [
            'bind_host' => $this->bindHost,
            'port' => $this->port,
            'passive_start' => $this->passivePortStart,
            'passive_end' => $this->passivePortEnd,
            'mode' => $nonBlocking ? 'nonblocking' : 'blocking',
        ]);

        return $server;
    }

    /**
     * @param resource $socket
     */
    public function serveAcceptedClient($socket): void
    {
        $clientId = $this->registerAcceptedClient($socket);
        if ($clientId === null) {
            return;
        }

        while (isset($this->clients[$clientId])) {
            $read = $this->getReadSockets();
            $write = $this->getWriteSockets();
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 200000);
            if ($changed === false) {
                usleep(200000);
            } else {
                foreach ($read as $readySocket) {
                    $this->handleReadableSocket($readySocket);
                }
                foreach ($write as $readySocket) {
                    $this->handleWritableSocket($readySocket);
                }
            }

            $this->tick();
        }
    }

    private function acceptClient(): void
    {
        $socket = @stream_socket_accept($this->server, 0);
        if (!$socket) {
            return;
        }

        $this->registerAcceptedClient($socket);
    }

    /**
     * @param resource $socket
     */
    private function registerAcceptedClient($socket): ?int
    {
        if (!is_resource($socket)) {
            return null;
        }

        stream_set_blocking($socket, false);
        $clientId = (int)$socket;
        $this->clients[$clientId] = [
            'socket' => $socket,
            'buffer' => '',
            'cwd' => '/',
            'authenticated' => false,
            'username' => '',
            'user' => null,
            'session_id' => null,
            'remote_ip' => $this->extractSocketIp($socket),
            'passive_listener' => null,
            'data_socket' => null,
            'transfer' => null,
            'last_activity' => time(),
        ];

        $this->sendResponse($clientId, 220, $this->buildWelcomeBanner());
        return $clientId;
    }

    private function readControlSocket(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $chunk = @fread($this->clients[$clientId]['socket'], 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($this->clients[$clientId]['socket'])) {
                $this->closeClient($clientId);
            }
            return;
        }

        $this->clients[$clientId]['buffer'] .= $chunk;
        $this->clients[$clientId]['last_activity'] = time();

        while (isset($this->clients[$clientId]) && ($lineEnd = strpos($this->clients[$clientId]['buffer'], "\n")) !== false) {
            $line = substr($this->clients[$clientId]['buffer'], 0, $lineEnd + 1);
            $this->clients[$clientId]['buffer'] = substr($this->clients[$clientId]['buffer'], $lineEnd + 1);
            $this->handleCommand($clientId, rtrim($line, "\r\n"));
        }
    }

    private function handleCommand(int $clientId, string $line): void
    {
        if ($line === '') {
            return;
        }

        [$command, $argument] = array_pad(preg_split('/\s+/', $line, 2), 2, '');
        $command = strtoupper(trim((string)$command));
        $argument = trim((string)$argument);

        if ($command === 'USER') {
            $this->clients[$clientId]['username'] = $argument;
            $this->sendResponse($clientId, 331, 'Password required');
            return;
        }

        if ($command === 'PASS') {
            $this->handlePass($clientId, $argument);
            return;
        }

        if (!$this->clients[$clientId]['authenticated']) {
            $this->sendResponse($clientId, 530, 'Please log in');
            return;
        }

        $sessionId = (string)($this->clients[$clientId]['session_id'] ?? '');
        if ($sessionId !== '') {
            (new Auth())->updateSessionActivity($sessionId, 'FTP ' . (string)$this->clients[$clientId]['cwd']);
        }

        switch ($command) {
            case 'QUIT':
                $this->sendResponse($clientId, 221, 'Goodbye');
                $this->closeClient($clientId);
                return;
            case 'NOOP':
                $this->sendResponse($clientId, 200, 'OK');
                return;
            case 'SYST':
                $this->sendResponse($clientId, 215, 'UNIX Type: L8');
                return;
            case 'FEAT':
                $this->sendMultilineResponse($clientId, 211, [' UTF8', ' SIZE', ' MDTM', ' EPSV', ' PASV']);
                return;
            case 'OPTS':
            case 'TYPE':
            case 'MODE':
            case 'STRU':
                $this->sendResponse($clientId, 200, 'OK');
                return;
            case 'PWD':
            case 'XPWD':
                $this->sendResponse($clientId, 257, '"' . $this->clients[$clientId]['cwd'] . '" is the current directory');
                return;
            case 'CWD':
                $this->handleCwd($clientId, $argument);
                return;
            case 'CDUP':
                $this->handleCwd($clientId, '..');
                return;
            case 'PASV':
                $this->enterPassiveMode($clientId, false);
                return;
            case 'EPSV':
                $this->enterPassiveMode($clientId, true);
                return;
            case 'PORT':
            case 'EPRT':
                $this->sendResponse($clientId, 502, 'Active mode is not supported');
                return;
            case 'LIST':
            case 'NLST':
                $this->prepareDirectoryTransfer($clientId, $argument, $command === 'NLST');
                return;
            case 'SIZE':
                $this->handleSize($clientId, $argument);
                return;
            case 'MDTM':
                $this->handleMdtm($clientId, $argument);
                return;
            case 'RETR':
                $this->prepareRetr($clientId, $argument);
                return;
            case 'STOR':
                $this->prepareStor($clientId, $argument);
                return;
            default:
                $this->sendResponse($clientId, 550, 'Operation not permitted');
        }
    }

    private function handlePass(int $clientId, string $password): void
    {
        $username = trim((string)($this->clients[$clientId]['username'] ?? ''));
        if ($username === '') {
            $this->sendResponse($clientId, 503, 'Send USER first');
            return;
        }

        if ($this->isAnonymousUsername($username)) {
            $this->authenticateAnonymousClient($clientId, $password);
            return;
        }

        $auth = new Auth();
        $user = $auth->authenticateCredentials($username, $password);
        if ($user === false) {
            $this->sendResponse($clientId, 530, 'Login incorrect');
            return;
        }

        $sessionId = $auth->createSessionForConnection(
            (int)$user['id'],
            'ftp',
            (string)$this->clients[$clientId]['remote_ip'],
            'BinktermPHP FTP'
        );

        $this->clients[$clientId]['authenticated'] = true;
        $this->clients[$clientId]['user'] = $user;
        $this->clients[$clientId]['session_id'] = $sessionId;
        $this->clients[$clientId]['cwd'] = '/';

        $this->logger->info(sprintf(
            'FTP client authenticated: ip=%s username=%s user_id=%d client_id=%d',
            (string)$this->clients[$clientId]['remote_ip'],
            (string)$user['username'],
            (int)$user['id'],
            $clientId
        ));

        $this->sendResponse($clientId, 230, 'Login successful');
    }

    private function handleCwd(int $clientId, string $argument): void
    {
        $target = $this->resolvePath($clientId, $argument);
        if (!$this->vfs->isDirectory((array)$this->clients[$clientId]['user'], $this->clients[$clientId], $target)) {
            $this->sendResponse($clientId, 550, 'Directory not found');
            return;
        }

        $this->clients[$clientId]['cwd'] = $target;
        $description = $this->vfs->getDirectoryDescription((array)$this->clients[$clientId]['user'], $this->clients[$clientId], $target);
        $message = 'Directory changed';
        if ($description !== null) {
            $message .= ' - ' . $description;
        }
        $this->sendResponse($clientId, 250, $message);
    }

    private function handleSize(int $clientId, string $argument): void
    {
        $target = $this->resolvePath($clientId, $argument);
        $info = $this->vfs->getFileInfo((array)$this->clients[$clientId]['user'], $this->clients[$clientId], $target);
        if ($info === null || $info['type'] !== 'file') {
            $this->sendResponse($clientId, 550, 'File not found');
            return;
        }

        $this->sendResponse($clientId, 213, (string)$info['size']);
    }

    private function handleMdtm(int $clientId, string $argument): void
    {
        $target = $this->resolvePath($clientId, $argument);
        $info = $this->vfs->getFileInfo((array)$this->clients[$clientId]['user'], $this->clients[$clientId], $target);
        if ($info === null || $info['type'] !== 'file') {
            $this->sendResponse($clientId, 550, 'File not found');
            return;
        }

        $this->sendResponse($clientId, 213, gmdate('YmdHis', (int)$info['mtime']));
    }

    private function prepareDirectoryTransfer(int $clientId, string $argument, bool $namesOnly): void
    {
        if (!$this->ensurePassiveReady($clientId)) {
            return;
        }

        $targetArgument = $this->extractListPathArgument($argument);
        $target = $targetArgument !== '' ? $this->resolvePath($clientId, $targetArgument) : (string)$this->clients[$clientId]['cwd'];
        if (!$this->vfs->isDirectory((array)$this->clients[$clientId]['user'], $this->clients[$clientId], $target)) {
            $this->sendResponse($clientId, 550, 'Directory not found');
            return;
        }

        $entries = $this->vfs->listDirectory((array)$this->clients[$clientId]['user'], $this->clients[$clientId], $target);
        $listing = '';
        foreach ($entries as $entry) {
            $listing .= $namesOnly ? $entry['name'] . "\r\n" : $this->formatListLine($entry) . "\r\n";
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $listing);
        rewind($stream);

        $this->startPendingTransfer($clientId, [
            'mode' => 'send',
            'stream' => $stream,
            'buffer' => '',
            'audit_action' => null,
            'audit_path' => $target,
            'bytes_transferred' => 0,
            'cleanup' => static function () use ($stream): void {
                @fclose($stream);
            },
        ], 'Directory transfer starting');
    }

    private function prepareRetr(int $clientId, string $argument): void
    {
        if (!$this->ensurePassiveReady($clientId)) {
            return;
        }

        $resource = $this->vfs->openReadStream(
            (array)$this->clients[$clientId]['user'],
            $this->clients[$clientId],
            $this->resolvePath($clientId, $argument)
        );
        if ($resource === null) {
            $this->sendResponse($clientId, 550, 'File not found');
            return;
        }

        $this->startPendingTransfer($clientId, [
            'mode' => 'send',
            'stream' => $resource['stream'],
            'buffer' => '',
            'audit_action' => 'download',
            'audit_path' => $this->resolvePath($clientId, $argument),
            'bytes_transferred' => 0,
            'cleanup' => $resource['cleanup'],
        ], 'Opening data connection');
    }

    private function prepareStor(int $clientId, string $argument): void
    {
        if (!$this->ensurePassiveReady($clientId)) {
            return;
        }

        $targetPath = $this->resolvePath($clientId, $argument);
        if (!$this->isWritableUploadPath($targetPath)) {
            $this->sendResponse($clientId, 550, 'Uploads are only permitted inside /incoming or /qwk/upload');
            return;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'ftp_rep_');
        if ($tempPath === false) {
            $this->sendResponse($clientId, 451, 'Failed to allocate upload storage');
            return;
        }

        $tempHandle = fopen($tempPath, 'wb');
        if ($tempHandle === false) {
            @unlink($tempPath);
            $this->sendResponse($clientId, 451, 'Failed to open upload storage');
            return;
        }

        $this->startPendingTransfer($clientId, [
            'mode' => 'receive',
            'target_path' => $targetPath,
            'temp_path' => $tempPath,
            'temp_handle' => $tempHandle,
            'audit_action' => str_starts_with($targetPath, '/qwk/upload/') ? 'upload_qwk' : 'upload_file',
            'audit_path' => $targetPath,
            'bytes_transferred' => 0,
        ], 'Ready to receive data');
    }

    private function startPendingTransfer(int $clientId, array $transfer, string $message): void
    {
        $this->clients[$clientId]['transfer'] = $transfer;
        $this->sendResponse($clientId, 150, $message);
    }

    private function ensurePassiveReady(int $clientId): bool
    {
        $hasPassiveListener = isset($this->clients[$clientId]['passive_listener']) && is_resource($this->clients[$clientId]['passive_listener']);
        $hasDataSocket = isset($this->clients[$clientId]['data_socket']) && is_resource($this->clients[$clientId]['data_socket']);

        if (!$hasPassiveListener && !$hasDataSocket) {
            $this->sendResponse($clientId, 425, 'Use PASV or EPSV first');
            return false;
        }

        if (!empty($this->clients[$clientId]['transfer'])) {
            $this->sendResponse($clientId, 425, 'Transfer already in progress');
            return false;
        }

        return true;
    }

    private function enterPassiveMode(int $clientId, bool $extended): void
    {
        $this->closePassiveSockets($clientId);

        $listener = null;
        $port = 0;
        for ($candidate = $this->passivePortStart; $candidate <= $this->passivePortEnd; $candidate++) {
            $errno = 0;
            $errstr = '';
            $listener = @stream_socket_server(
                "tcp://{$this->bindHost}:{$candidate}",
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            if ($listener !== false) {
                $port = $candidate;
                break;
            }
        }

        if (!is_resource($listener) || $port === 0) {
            $this->sendResponse($clientId, 421, 'No passive ports available');
            return;
        }

        stream_set_blocking($listener, false);
        $this->clients[$clientId]['passive_listener'] = $listener;

        if ($extended) {
            $this->sendResponse($clientId, 229, sprintf('Entering Extended Passive Mode (|||%d|)', $port));
            return;
        }

        $host = $this->resolvePassiveHost($clientId);
        $ip = explode('.', $host);
        if (count($ip) !== 4) {
            $this->sendResponse($clientId, 522, 'PASV requires an IPv4 public host; use EPSV');
            return;
        }

        $this->sendResponse($clientId, 227, sprintf(
            'Entering Passive Mode (%d,%d,%d,%d,%d,%d)',
            (int)$ip[0],
            (int)$ip[1],
            (int)$ip[2],
            (int)$ip[3],
            intdiv($port, 256),
            $port % 256
        ));
    }

    private function acceptPassiveDataSocket(int $clientId): void
    {
        $listener = $this->clients[$clientId]['passive_listener'] ?? null;
        if (!is_resource($listener)) {
            return;
        }

        $dataSocket = @stream_socket_accept($listener, 0);
        if ($dataSocket === false) {
            return;
        }

        stream_set_blocking($dataSocket, false);
        $this->clients[$clientId]['data_socket'] = $dataSocket;
        @fclose($listener);
        $this->clients[$clientId]['passive_listener'] = null;
    }

    private function readDataSocket(int $clientId): void
    {
        $transfer = $this->clients[$clientId]['transfer'] ?? null;
        if (!is_array($transfer) || ($transfer['mode'] ?? null) !== 'receive') {
            return;
        }

        $chunk = @fread($this->clients[$clientId]['data_socket'], 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($this->clients[$clientId]['data_socket'])) {
                $this->finalizeReceiveTransfer($clientId);
            }
            return;
        }

        fwrite($transfer['temp_handle'], $chunk);
        $this->clients[$clientId]['transfer']['bytes_transferred'] = (int)($transfer['bytes_transferred'] ?? 0) + strlen($chunk);
    }

    private function writeDataSocket(int $clientId): void
    {
        $transfer = $this->clients[$clientId]['transfer'] ?? null;
        if (!is_array($transfer) || ($transfer['mode'] ?? null) !== 'send') {
            return;
        }

        $buffer = (string)($transfer['buffer'] ?? '');
        if ($buffer === '') {
            $buffer = (string)fread($transfer['stream'], 8192);
        }

        if ($buffer === '') {
            if (feof($transfer['stream'])) {
                $this->finishTransfer($clientId, 226, 'Transfer complete');
            }
            return;
        }

        $written = @fwrite($this->clients[$clientId]['data_socket'], $buffer);
        if ($written === false) {
            $this->finishTransfer($clientId, 426, 'Data connection error');
            return;
        }

        $this->clients[$clientId]['transfer']['bytes_transferred'] = (int)($transfer['bytes_transferred'] ?? 0) + $written;

        $this->clients[$clientId]['transfer']['buffer'] = $written < strlen($buffer)
            ? substr($buffer, $written)
            : '';
    }

    private function finalizeReceiveTransfer(int $clientId): void
    {
        $transfer = $this->clients[$clientId]['transfer'] ?? null;
        if (!is_array($transfer) || ($transfer['mode'] ?? null) !== 'receive') {
            return;
        }

        @fclose($transfer['temp_handle']);
        try {
            $targetPath = (string)$transfer['target_path'];
            if (str_starts_with($targetPath, '/qwk/upload/')) {
                $result = $this->vfs->importUploadedRep(
                    (array)$this->clients[$clientId]['user'],
                    $targetPath,
                    (string)$transfer['temp_path']
                );
            } else {
                $result = $this->vfs->storeIncomingUpload(
                    (array)$this->clients[$clientId]['user'],
                    $targetPath,
                    (string)$transfer['temp_path']
                );
            }
        } catch (\Throwable $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
        @unlink((string)$transfer['temp_path']);

        $this->finishTransfer($clientId, $result['success'] ? 226 : 550, $result['message']);
    }

    private function finishTransfer(int $clientId, int $code, string $message): void
    {
        $transfer = $this->clients[$clientId]['transfer'] ?? null;
        $this->logTransferIfNeeded($clientId, $transfer, $code, $message);
        if (is_array($transfer) && isset($transfer['cleanup']) && is_callable($transfer['cleanup'])) {
            try {
                ($transfer['cleanup'])();
            } catch (\Throwable $e) {
            }
        }

        if (isset($this->clients[$clientId]['data_socket']) && is_resource($this->clients[$clientId]['data_socket'])) {
            @fclose($this->clients[$clientId]['data_socket']);
        }

        $this->clients[$clientId]['data_socket'] = null;
        $this->clients[$clientId]['transfer'] = null;
        $this->sendResponse($clientId, $code, $message);
    }

    /**
     * @param array<string, mixed>|mixed $transfer
     */
    private function logTransferIfNeeded(int $clientId, $transfer, int $code, string $message): void
    {
        if (!is_array($transfer)) {
            return;
        }

        $action = (string)($transfer['audit_action'] ?? '');
        if ($action === '') {
            return;
        }

        $client = $this->clients[$clientId] ?? null;
        $user = is_array($client) ? ($client['user'] ?? null) : null;
        if (!is_array($user)) {
            return;
        }

        $username = (string)($user['username'] ?? '');
        $userId = (int)($user['id'] ?? $user['user_id'] ?? 0);
        $remoteIp = is_array($client) ? (string)($client['remote_ip'] ?? '') : '';
        $path = (string)($transfer['audit_path'] ?? '');
        $bytes = (int)($transfer['bytes_transferred'] ?? 0);
        $status = $code >= 200 && $code < 300 ? 'success' : 'failure';

        $this->logger->info(sprintf(
            'FTP transfer: action=%s status=%s ip=%s username=%s user_id=%d path=%s bytes=%d message=%s',
            $action,
            $status,
            $remoteIp !== '' ? $remoteIp : '-',
            $username !== '' ? $username : '-',
            $userId,
            $path !== '' ? $path : '-',
            $bytes,
            $message !== '' ? $message : '-'
        ));
    }

    private function closePassiveSockets(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        if (isset($this->clients[$clientId]['passive_listener']) && is_resource($this->clients[$clientId]['passive_listener'])) {
            @fclose($this->clients[$clientId]['passive_listener']);
        }
        if (isset($this->clients[$clientId]['data_socket']) && is_resource($this->clients[$clientId]['data_socket'])) {
            @fclose($this->clients[$clientId]['data_socket']);
        }

        $this->clients[$clientId]['passive_listener'] = null;
        $this->clients[$clientId]['data_socket'] = null;
    }

    private function closeClient(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $client = $this->clients[$clientId];
        $this->closePassiveSockets($clientId);
        $this->vfs->cleanupSession($this->clients[$clientId]);

        $transfer = $client['transfer'] ?? null;
        if (is_array($transfer)) {
            if (isset($transfer['temp_handle']) && is_resource($transfer['temp_handle'])) {
                @fclose($transfer['temp_handle']);
            }
            if (!empty($transfer['temp_path']) && is_string($transfer['temp_path']) && file_exists($transfer['temp_path'])) {
                @unlink($transfer['temp_path']);
            }
            if (isset($transfer['cleanup']) && is_callable($transfer['cleanup'])) {
                try {
                    ($transfer['cleanup'])();
                } catch (\Throwable $e) {
                }
            }
        }

        if (!empty($client['session_id'])) {
            (new Auth())->logout((string)$client['session_id']);
        }

        if (is_resource($client['socket'])) {
            @fclose($client['socket']);
        }

        if (!empty($client['authenticated']) && is_array($client['user'])) {
            if (!empty($client['user']['is_anonymous'])) {
                $this->logger->info(sprintf(
                    'FTP anonymous client disconnected: ip=%s client_id=%d',
                    (string)($client['remote_ip'] ?? ''),
                    $clientId
                ));
            } else {
                $this->logger->info(sprintf(
                    'FTP client disconnected: ip=%s username=%s user_id=%d client_id=%d',
                    (string)($client['remote_ip'] ?? ''),
                    (string)($client['user']['username'] ?? ''),
                    (int)($client['user']['id'] ?? 0),
                    $clientId
                ));
            }
        }

        unset($this->clients[$clientId]);
    }

    private function resolvePath(int $clientId, string $argument): string
    {
        return $this->vfs->normalizePath((string)$this->clients[$clientId]['cwd'], $argument);
    }

    private function isWritableUploadPath(string $path): bool
    {
        return str_starts_with($path, '/qwk/upload/') || str_starts_with($path, '/incoming/');
    }

    private function extractListPathArgument(string $argument): string
    {
        $argument = trim($argument);
        if ($argument === '' || $argument[0] !== '-') {
            return $argument;
        }

        $path = '';
        foreach (preg_split('/\s+/', $argument) as $token) {
            if ($token !== '' && $token[0] !== '-') {
                $path = $token;
            }
        }

        return $path;
    }

    private function sendResponse(int $clientId, int $code, string $message): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        @fwrite($this->clients[$clientId]['socket'], sprintf("%d %s\r\n", $code, $message));
    }

    /**
     * @param array<int, string> $lines
     */
    private function sendMultilineResponse(int $clientId, int $code, array $lines): void
    {
        @fwrite($this->clients[$clientId]['socket'], sprintf("%d-Features\r\n", $code));
        foreach ($lines as $line) {
            @fwrite($this->clients[$clientId]['socket'], $line . "\r\n");
        }
        @fwrite($this->clients[$clientId]['socket'], sprintf("%d End\r\n", $code));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function formatListLine(array $entry): string
    {
        $isDir = ($entry['type'] ?? '') === 'dir';
        $line = sprintf(
            '%s 1 binkterm binkterm %12d %s %s',
            $isDir ? 'drwxr-xr-x' : '-rw-r--r--',
            (int)($entry['size'] ?? 0),
            gmdate('M d H:i', (int)($entry['mtime'] ?? time())),
            (string)$entry['name']
        );

        $description = trim((string)($entry['description'] ?? ''));
        if ($description !== '') {
            $line .= ' - ' . $description;
        }

        return $line;
    }

    private function resolvePassiveHost(int $clientId): string
    {
        $configured = trim($this->publicHost);
        if ($configured !== '') {
            return $configured;
        }

        $local = @stream_socket_get_name($this->clients[$clientId]['socket'], false);
        if (is_string($local) && preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $local, $matches)) {
            if ($matches[1] !== '0.0.0.0') {
                return $matches[1];
            }
        }

        return $this->bindHost;
    }

    /**
     * @param resource $socket
     */
    private function extractSocketIp($socket): string
    {
        $peer = @stream_socket_get_name($socket, true);
        if (!is_string($peer) || $peer === '') {
            return '';
        }

        if ($peer[0] === '[') {
            $end = strpos($peer, ']');
            return $end === false ? $peer : substr($peer, 1, $end - 1);
        }

        $pos = strrpos($peer, ':');
        if ($pos === false) {
            return $peer;
        }

        if (substr_count($peer, ':') > 1) {
            return $peer;
        }

        return substr($peer, 0, $pos);
    }

    private function isAnonymousUsername(string $username): bool
    {
        $username = trim($username);
        return strcasecmp($username, 'anonymous') === 0 || strcasecmp($username, 'ftp') === 0;
    }

    private function authenticateAnonymousClient(int $clientId, string $password): void
    {
        $alreadyAuthenticated = !empty($this->clients[$clientId]['authenticated'])
            && is_array($this->clients[$clientId]['user'] ?? null)
            && !empty($this->clients[$clientId]['user']['is_anonymous']);

        $this->clients[$clientId]['authenticated'] = true;
        $this->clients[$clientId]['user'] = [
            'id' => 0,
            'username' => 'anonymous',
            'is_admin' => false,
            'is_anonymous' => true,
        ];
        $this->clients[$clientId]['session_id'] = null;
        $this->clients[$clientId]['cwd'] = '/';

        if (!$alreadyAuthenticated) {
            $this->logger->info(sprintf(
                'FTP anonymous client connected: ip=%s client_id=%d password=%s',
                (string)$this->clients[$clientId]['remote_ip'],
                $clientId,
                $password !== '' ? $password : '-'
            ));
        }

        $this->sendResponse($clientId, 230, 'Anonymous login ok, access restricted to public file areas');
    }

    private function buildWelcomeBanner(): string
    {
        try {
            $bbsName = trim((string)BinkpConfig::getInstance()->getSystemName());
        } catch (\Throwable $e) {
            $bbsName = '';
        }

        if ($bbsName === '') {
            $bbsName = 'BinktermPHP BBS';
        }

        try {
            $siteUrl = trim((string)Config::getSiteUrl());
        } catch (\Throwable $e) {
            $siteUrl = '';
        }

        if ($siteUrl === '') {
            return sprintf('Welcome to %s', $bbsName);
        }

        return sprintf('Welcome to %s, visit us on the web at %s', $bbsName, $siteUrl);
    }
}
