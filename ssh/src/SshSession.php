<?php

namespace BinktermPHP\SshServer;

/**
 * SshSession — SSH-2 wire protocol handler for a single accepted TCP connection.
 *
 * Implements a minimal but standards-compliant SSH-2 server sufficient to run
 * an interactive BBS session.  Uses only ext-openssl and ext-gmp; no Composer
 * SSH library is required.
 *
 * Supported algorithms (chosen for maximum client compatibility):
 *   Key exchange  : diffie-hellman-group14-sha256
 *   Host key      : rsa-sha2-256 (2048-bit RSA, auto-generated if absent)
 *   Cipher C→S   : aes128-ctr
 *   Cipher S→C   : aes128-ctr
 *   MAC C→S      : hmac-sha2-256
 *   MAC S→C      : hmac-sha2-256
 *   Compression   : none
 *   Auth method   : password (verified via the BBS /api/auth/login endpoint)
 *
 * After a successful shell request the caller receives the socket (still in
 * plaintext from its perspective — crypto is transparent) together with the
 * authenticated user data so BbsSession can skip its own login UI.
 *
 * References: RFC 4253, RFC 4252, RFC 4254, RFC 4419, RFC 8332.
 */
class SshSession
{
    // SSH message numbers
    private const MSG_DISCONNECT            = 1;
    private const MSG_IGNORE                = 2;
    private const MSG_SERVICE_REQUEST       = 5;
    private const MSG_SERVICE_ACCEPT        = 6;
    private const MSG_KEXINIT               = 20;
    private const MSG_NEWKEYS               = 21;
    private const MSG_KEXDH_INIT            = 30;
    private const MSG_KEXDH_REPLY           = 31;
    private const MSG_USERAUTH_REQUEST      = 50;
    private const MSG_USERAUTH_FAILURE      = 51;
    private const MSG_USERAUTH_SUCCESS      = 52;
    private const MSG_USERAUTH_BANNER       = 53;
    private const MSG_CHANNEL_OPEN         = 90;
    private const MSG_CHANNEL_OPEN_CONFIRM = 91;
    private const MSG_CHANNEL_OPEN_FAILURE = 92;
    private const MSG_CHANNEL_WINDOW_ADJUST= 93;
    private const MSG_CHANNEL_DATA         = 94;
    private const MSG_CHANNEL_EOF          = 96;
    private const MSG_CHANNEL_CLOSE        = 97;
    private const MSG_CHANNEL_REQUEST      = 98;
    private const MSG_CHANNEL_SUCCESS      = 99;
    private const MSG_CHANNEL_FAILURE      = 100;

    // Disconnect reason codes
    private const DISCONNECT_BY_APPLICATION    = 11;
    private const DISCONNECT_AUTH_CANCELLED    = 13;
    private const DISCONNECT_PROTOCOL_ERROR    = 2;

    // DH Group 14 prime (RFC 3526 §3, 2048-bit MODP group)
    private const DH_GROUP14_P = '0xFFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD1' .
                                  '29024E088A67CC74020BBEA63B139B22514A08798E3404DD' .
                                  'EF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245' .
                                  'E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED' .
                                  'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3D' .
                                  'C2007CB8A163BF0598DA48361C55D39A69163FA8FD24CF5F' .
                                  '83655D23DCA3AD961C62F356208552BB9ED529077096966D' .
                                  '670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B' .
                                  'E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9' .
                                  'DE2BCBF6955817183995497CEA956AE515D2261898FA0510' .
                                  '15728E5A8AACAA68FFFFFFFFFFFFFFFF';
    private const DH_GROUP14_G = '2';

    /** @var resource */
    private $socket;
    private bool $debug;
    private string $apiBase;
    private bool $insecure;
    private string $hostKeyFile;
    private string $hostCertFile;

    // Session state (set after NEWKEYS)
    private bool $encrypted = false;
    private string $sessionId = '';

    // Crypto keys (server→client direction)
    private string $encKeyS2C = '';
    private string $ivS2C     = '';
    private string $macKeyS2C = '';

    // Crypto keys (client→server direction)
    private string $encKeyC2S = '';
    private string $ivC2S     = '';
    private string $macKeyC2S = '';

    // Sequence counters
    private int $seqNoSend = 0;
    private int $seqNoRecv = 0;

    // AES-CTR counters (128-bit big-endian integers as binary strings)
    private string $ctrS2C = '';
    private string $ctrC2S = '';

    // Channel state
    private int $channelId         = 0;
    private int $peerChannelId     = 0;
    private int $windowSize        = 2097152;  // 2 MB
    private int $peerWindowSize    = 2097152;
    private int $maxPacketSize     = 32768;
    private bool $channelOpen      = false;

    // Terminal size reported by client pty-req
    private int $termCols = 80;
    private int $termRows = 24;
    private bool $shellStarted = false;

    // Raw bytes pre-fed by the bridge for non-blocking packet reassembly.
    private string $rawBuf = '';

    // RSA host key (loaded/generated in __construct)
    private \OpenSSLAsymmetricKey $hostKey;
    private string $hostKeyBlob = '';  // wire-format public key blob

    // Versions exchanged during handshake
    private string $clientVersion = '';
    private string $serverVersion = 'SSH-2.0-BinktermPHP';

    // Raw KEXINIT payloads for H computation
    private string $clientKexInitPayload = '';
    private string $serverKexInitPayload = '';

    /**
     * @param resource    $socket       Accepted plain TCP socket
     * @param string      $apiBase      BBS API base URL for password verification
     * @param bool        $debug        Enable verbose debug output
     * @param bool        $insecure     Skip SSL cert verification on API calls
     * @param string      $hostKeyFile  Path to PEM RSA private key (auto-generated if absent)
     * @param string      $hostCertFile Path to PEM certificate (auto-generated if absent)
     */
    public function __construct(
        $socket,
        string $apiBase,
        bool $debug,
        bool $insecure,
        string $hostKeyFile,
        string $hostCertFile
    ) {
        $this->socket        = $socket;
        $this->apiBase       = rtrim($apiBase, '/');
        $this->serverVersion = 'SSH-2.0-BinktermPHP_' . \BinktermPHP\Version::getVersion();
        $this->debug        = $debug;
        $this->insecure     = $insecure;
        $this->hostKeyFile  = $hostKeyFile;
        $this->hostCertFile = $hostCertFile;

        $this->hostKey     = $this->loadOrGenerateHostKey();
        $this->hostKeyBlob = $this->buildRsaPublicKeyBlob();
    }

    /**
     * Run the full SSH handshake and authentication.
     *
     * Returns an array on success:
     *   [
     *     'session'    => string  (BBS session cookie),
     *     'username'   => string,
     *     'csrf_token' => string|null,
     *     'cols'       => int,
     *     'rows'       => int,
     *   ]
     * Returns null on failure (caller should close the socket).
     *
     * @return array|null
     */
    public function handshake(): ?array
    {
        try {
            if (!$this->exchangeVersions())    { return null; }
            if (!$this->keyExchange())         { return null; }
            $authResult = $this->authenticate();
            if ($authResult === null)          { return null; }
            if (!$this->openChannel())         { return null; }

            return array_merge($authResult, [
                'cols' => $this->termCols,
                'rows' => $this->termRows,
            ]);
        } catch (\Throwable $e) {
            if ($this->debug) {
                $this->dbg("SSH exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            return null;
        }
    }

    // =========================================================================
    // PHASE 1: VERSION EXCHANGE
    // =========================================================================

    private function exchangeVersions(): bool
    {
        // Send our version
        $this->rawWrite($this->serverVersion . "\r\n");

        // Read client version (may be preceded by lines starting with anything but "SSH-")
        $deadline = time() + 30;
        while (time() < $deadline) {
            $line = $this->readLine(30);
            if ($line === null) { return false; }
            if (str_starts_with($line, 'SSH-2.0-') || str_starts_with($line, 'SSH-1.99-')) {
                $this->clientVersion = rtrim($line);
                $this->dbg("Client version: {$this->clientVersion}");
                return true;
            }
            if (str_starts_with($line, 'SSH-1.')) {
                $this->sendDisconnect(self::DISCONNECT_PROTOCOL_ERROR, 'SSH-1 not supported');
                return false;
            }
            // Ignore non-SSH lines (RFC 4253 §4.2)
        }
        return false;
    }

    // =========================================================================
    // PHASE 2: KEY EXCHANGE
    // =========================================================================

    private function keyExchange(): bool
    {
        // Send our KEXINIT
        $serverKexInit = $this->buildKexInit();
        $this->serverKexInitPayload = $serverKexInit;
        $this->sendPacket($serverKexInit);

        // Read client KEXINIT
        $pkt = $this->recvPacket();
        if ($pkt === null || ord($pkt[0]) !== self::MSG_KEXINIT) { return false; }
        $this->clientKexInitPayload = $pkt;

        // Read KEX DH INIT (client sends e = g^x mod p)
        $pkt = $this->recvPacket();
        if ($pkt === null || ord($pkt[0]) !== self::MSG_KEXDH_INIT) { return false; }

        $offset = 1;
        $e = $this->readMpint($pkt, $offset);  // client's DH public value

        // Generate server DH private value y, compute f = g^y mod p
        $p  = gmp_init(self::DH_GROUP14_P, 16);
        $g  = gmp_init(self::DH_GROUP14_G, 10);
        $y  = gmp_import(random_bytes(32));          // 256-bit random private key
        $f  = gmp_powm($g, $y, $p);
        $K  = gmp_powm($e, $y, $p);                 // shared secret

        // Encode K as SSH mpint
        $kBytes   = $this->mpintEncode($K);

        // Build exchange hash H = SHA-256(V_C || V_S || I_C || I_S || K_S || e || f || K)
        $H = $this->computeExchangeHash($e, $f, $kBytes);

        if ($this->sessionId === '') {
            $this->sessionId = $H;
        }

        // Sign H with host RSA key (rsa-sha2-256)
        $sig = '';
        openssl_sign($H, $sig, $this->hostKey, OPENSSL_ALGO_SHA256);
        $sigBlob = $this->sshString('rsa-sha2-256') . $this->sshString($sig);

        // Send KEXDH_REPLY: host key blob, f, signature
        $reply  = chr(self::MSG_KEXDH_REPLY);
        $reply .= $this->sshString($this->hostKeyBlob);
        $reply .= $this->mpintEncode($f);
        $reply .= $this->sshString($sigBlob);
        $this->sendPacket($reply);

        // Send NEWKEYS
        $this->sendPacket(chr(self::MSG_NEWKEYS));

        // Derive session keys
        $this->deriveKeys($kBytes, $H);

        // Wait for client NEWKEYS
        $pkt = $this->recvPacket();
        if ($pkt === null || ord($pkt[0]) !== self::MSG_NEWKEYS) { return false; }

        // From now on all traffic is encrypted
        $this->encrypted = true;
        $this->dbg("Key exchange complete, encryption active");
        return true;
    }

    private function buildKexInit(): string
    {
        $pkt  = chr(self::MSG_KEXINIT);
        $pkt .= random_bytes(16);  // cookie

        $nameList = fn(string ...$names) => $this->sshString(implode(',', $names));

        $pkt .= $nameList('diffie-hellman-group14-sha256');  // kex
        $pkt .= $nameList('rsa-sha2-256');                   // server host key
        $pkt .= $nameList('aes128-ctr');                     // enc C→S
        $pkt .= $nameList('aes128-ctr');                     // enc S→C
        $pkt .= $nameList('hmac-sha2-256');                  // mac C→S
        $pkt .= $nameList('hmac-sha2-256');                  // mac S→C
        $pkt .= $nameList('none');                           // compress C→S
        $pkt .= $nameList('none');                           // compress S→C
        $pkt .= $nameList();                                 // languages C→S
        $pkt .= $nameList();                                 // languages S→C
        $pkt .= chr(0);                                      // first_kex_packet_follows
        $pkt .= pack('N', 0);                               // reserved
        return $pkt;
    }

    private function computeExchangeHash(\GMP $e, \GMP $f, string $kBytes): string
    {
        $data  = $this->sshString($this->clientVersion);
        $data .= $this->sshString($this->serverVersion);
        $data .= $this->sshString($this->clientKexInitPayload);
        $data .= $this->sshString($this->serverKexInitPayload);
        $data .= $this->sshString($this->hostKeyBlob);
        $data .= $this->mpintEncode($e);
        $data .= $this->mpintEncode($f);
        $data .= $kBytes;
        return hash('sha256', $data, true);
    }

    private function deriveKeys(string $kBytes, string $H): void
    {
        // RFC 4253 §7.2 key derivation: hash(K || H || letter || session_id)
        $derive = function(string $letter, int $needed) use ($kBytes, $H): string {
            $out = hash('sha256', $kBytes . $H . $letter . $this->sessionId, true);
            while (strlen($out) < $needed) {
                $out .= hash('sha256', $kBytes . $H . $out, true);
            }
            return substr($out, 0, $needed);
        };

        $this->ivC2S     = $derive('A', 16);
        $this->ivS2C     = $derive('B', 16);
        $this->encKeyC2S = $derive('C', 16);
        $this->encKeyS2C = $derive('D', 16);
        $this->macKeyC2S = $derive('E', 32);
        $this->macKeyS2C = $derive('F', 32);

        $this->ctrC2S = $this->ivC2S;
        $this->ctrS2C = $this->ivS2C;
    }

    // =========================================================================
    // PHASE 3: AUTHENTICATION
    // =========================================================================

    private function authenticate(): ?array
    {
        // Expect SSH_MSG_SERVICE_REQUEST for "ssh-userauth"
        $pkt = $this->recvPacket();
        if ($pkt === null || ord($pkt[0]) !== self::MSG_SERVICE_REQUEST) { return null; }
        $offset = 1;
        $service = $this->readString($pkt, $offset);
        if ($service !== 'ssh-userauth') { return null; }

        $accept  = chr(self::MSG_SERVICE_ACCEPT) . $this->sshString('ssh-userauth');
        $this->sendPacket($accept);
        $this->sendPreAuthBanner();

        // Auth loop — allow up to 6 attempts
        $maxAttempts = 6;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $pkt = $this->recvPacket();
            if ($pkt === null) { return null; }

            $msgType = ord($pkt[0]);
            if ($msgType !== self::MSG_USERAUTH_REQUEST) { return null; }

            $offset   = 1;
            $username = $this->readString($pkt, $offset);
            $service  = $this->readString($pkt, $offset);
            $method   = $this->readString($pkt, $offset);

            if ($method === 'none') {
                // Respond with the list of allowed methods
                $fail  = chr(self::MSG_USERAUTH_FAILURE);
                $fail .= $this->sshString('password');
                $fail .= chr(0);  // partial success = false
                $this->sendPacket($fail);
                continue;
            }

            if ($method === 'password') {
                $changeFlag = ord($pkt[$offset]); $offset++;
                $password   = $this->readString($pkt, $offset);

                $loginResult = $this->verifyPassword($username, $password);
                if ($loginResult !== null) {
                    $this->sendPacket(chr(self::MSG_USERAUTH_SUCCESS));
                    $this->dbg("Auth success: {$username}");
                    return $loginResult;
                }

                // Wrong password — accept the client anyway and let BbsSession
                // show its own login screen.  Sending FAILURE causes most clients
                // to disconnect immediately, which defeats the fallback UX.
                $this->dbg("Auth failed for {$username}, falling through to BBS login");
                $this->sendPacket(chr(self::MSG_USERAUTH_SUCCESS));
                return ['authenticated' => false];
            }

            // Unknown method
            $fail  = chr(self::MSG_USERAUTH_FAILURE);
            $fail .= $this->sshString('password');
            $fail .= chr(0);
            $this->sendPacket($fail);
        }

        // Max attempts exhausted — let the client proceed to the BBS login screen
        // instead of disconnecting.  We send USERAUTH_SUCCESS so the client opens
        // a channel, but flag the result so BbsSession shows its own login UI.
        $this->sendPacket(chr(self::MSG_USERAUTH_SUCCESS));
        return ['authenticated' => false];
    }

    /**
     * Send an SSH userauth banner (issue.net-style) before password auth begins.
     */
    private function sendPreAuthBanner(): void
    {
        $systemName = 'this BBS';
        try {
            $cfgName = (string)\BinktermPHP\Binkp\Config\BinkpConfig::getInstance()->getSystemName();
            if (trim($cfgName) !== '') {
                $systemName = $cfgName;
            }
        } catch (\Throwable $e) {
            // Non-fatal: keep generic banner text.
        }

        $locale = (string)\BinktermPHP\Config::env('I18N_DEFAULT_LOCALE', 'en');
        $translator = new \BinktermPHP\I18n\Translator();
        $t = function (string $key, string $fallback, array $params = []) use ($translator, $locale): string {
            $value = $translator->translate($key, $params, $locale, ['terminalserver']);
            if ($value === $key) {
                foreach ($params as $k => $v) {
                    $fallback = str_replace('{' . $k . '}', (string)$v, $fallback);
                }
                return $fallback;
            }
            return $value;
        };

        $message =
            $t('ui.terminalserver.server.ssh_banner.welcome', 'Welcome to {system}.', ['system' => $systemName]) . "\r\n" .
            $t('ui.terminalserver.server.ssh_banner.line2', 'Log in with your account credentials, or enter any username/password') . "\r\n" .
            $t('ui.terminalserver.server.ssh_banner.line3', 'to continue to the main BBS login screen.') . "\r\n";

        $pkt  = chr(self::MSG_USERAUTH_BANNER);
        $pkt .= $this->sshString($message);
        $pkt .= $this->sshString($locale);
        $this->sendPacket($pkt);
    }

    /**
     * Verify username + password against the BBS API.
     *
     * @return array|null ['session'=>..., 'username'=>..., 'csrf_token'=>...] or null
     */
    private function verifyPassword(string $username, string $password): ?array
    {
        if (!function_exists('curl_init')) { return null; }

        $url  = $this->apiBase . '/api/auth/login';
        $ch   = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => json_encode(['username' => $username, 'password' => $password, 'service' => 'ssh']),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        if ($this->insecure) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $cookie = null;
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$cookie) {
            $prefix = 'Set-Cookie: binktermphp_session=';
            if (stripos($header, $prefix) === 0) {
                $cookie = strtok(trim(substr($header, strlen($prefix))), ';');
            }
            return strlen($header);
        });

        $response  = curl_exec($ch);
        $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || empty($cookie)) { return null; }

        $data = is_string($response) ? json_decode($response, true) : null;
        return [
            'session'    => $cookie,
            'username'   => $username,
            'csrf_token' => $data['csrf_token'] ?? null,
        ];
    }

    // =========================================================================
    // PHASE 4: CHANNEL SETUP
    // =========================================================================

    private function openChannel(): bool
    {
        // Expect channel open
        $pkt = $this->recvPacket();
        if ($pkt === null || ord($pkt[0]) !== self::MSG_CHANNEL_OPEN) { return false; }

        $offset       = 1;
        $chanType     = $this->readString($pkt, $offset);
        $senderChanId = $this->unpackUint32($pkt, $offset);
        $initWindow   = $this->unpackUint32($pkt, $offset);
        $maxPkt       = $this->unpackUint32($pkt, $offset);

        if ($chanType !== 'session') {
            $fail  = chr(self::MSG_CHANNEL_OPEN_FAILURE);
            $fail .= pack('N', $senderChanId);
            $fail .= pack('N', 3);  // OPEN_UNKNOWN_CHANNEL_TYPE
            $fail .= $this->sshString('Only session channels supported');
            $fail .= $this->sshString('en');
            $this->sendPacket($fail);
            return false;
        }

        $this->peerChannelId  = $senderChanId;
        $this->peerWindowSize = $initWindow;
        $this->channelId      = 0;

        $confirm  = chr(self::MSG_CHANNEL_OPEN_CONFIRM);
        $confirm .= pack('N', $this->peerChannelId);  // recipient channel
        $confirm .= pack('N', $this->channelId);       // sender channel
        $confirm .= pack('N', $this->windowSize);      // initial window
        $confirm .= pack('N', $this->maxPacketSize);   // max packet
        $this->sendPacket($confirm);
        $this->channelOpen = true;

        // Process channel requests until we get a shell request
        $deadline = time() + 30;
        while (time() < $deadline) {
            $pkt = $this->recvPacket();
            if ($pkt === null) { return false; }
            $msgType = ord($pkt[0]);

            if ($msgType === self::MSG_CHANNEL_REQUEST) {
                if (!$this->handleChannelRequest($pkt)) { return false; }
                if ($this->shellStarted) { return true; }
                continue;
            }
            if ($msgType === self::MSG_CHANNEL_WINDOW_ADJUST) {
                $offset = 1;
                $this->unpackUint32($pkt, $offset);  // recipient channel
                $bytes = $this->unpackUint32($pkt, $offset);
                $this->peerWindowSize += $bytes;
                continue;
            }
            // Shell channel is now active — BbsSession takes over I/O
            if ($msgType === self::MSG_CHANNEL_DATA) {
                // Put this packet back conceptually — it will be read by BbsSession
                // via the normal channel data path.  For simplicity, just return true;
                // first data will be consumed by BbsSession's first read.
                break;
            }
            if ($msgType === self::MSG_CHANNEL_EOF || $msgType === self::MSG_CHANNEL_CLOSE) {
                return false;
            }
        }
        return true;
    }

    /**
     * Handle an SSH_MSG_CHANNEL_REQUEST packet during channel setup.
     *
     * @return bool False if the channel should be torn down
     */
    private function handleChannelRequest(string $pkt): bool
    {
        $offset   = 1;
        $chanId   = $this->unpackUint32($pkt, $offset);
        $reqType  = $this->readString($pkt, $offset);
        $wantReply= ord($pkt[$offset]); $offset++;

        $success = false;

        if ($reqType === 'pty-req') {
            // term, cols, rows, px-width, px-height, terminal-modes
            $this->readString($pkt, $offset);          // term (ignored)
            $this->termCols = $this->unpackUint32($pkt, $offset);
            $this->termRows = $this->unpackUint32($pkt, $offset);
            $this->unpackUint32($pkt, $offset);        // px width
            $this->unpackUint32($pkt, $offset);        // px height
            $this->readString($pkt, $offset);          // terminal modes
            $success = true;
            $this->dbg("PTY requested: {$this->termCols}x{$this->termRows}");
        } elseif ($reqType === 'shell') {
            $success = true;
            $this->shellStarted = true;
            $this->dbg("Shell requested");
        } elseif ($reqType === 'env') {
            // Accept but ignore env vars
            $success = true;
        }

        if ($wantReply) {
            $reply = chr($success ? self::MSG_CHANNEL_SUCCESS : self::MSG_CHANNEL_FAILURE);
            $reply .= pack('N', $this->peerChannelId);
            $this->sendPacket($reply);
        }

        return true;
    }

    // =========================================================================
    // PACKET I/O
    // =========================================================================

    /**
     * Send an SSH binary packet, encrypting and MACing if NEWKEYS has been exchanged.
     */
    public function sendPacket(string $payload): void
    {
        $blockSize  = 16;  // AES-128 block size
        $macLen     = $this->encrypted ? 32 : 0;  // HMAC-SHA2-256
        $minPad     = 4;
        // Total packet = 4 (length field) + 1 (pad_length) + payloadLen + padLen
        // must be a multiple of blockSize (RFC 4253 §6).
        $padLen = $blockSize - ((5 + strlen($payload)) % $blockSize);
        if ($padLen < $minPad) { $padLen += $blockSize; }

        $packet  = chr($padLen) . $payload . random_bytes($padLen);
        $pktLen  = strlen($packet);
        $raw     = pack('N', $pktLen) . $packet;

        if ($this->encrypted) {
            // Compute MAC over sequence_number || unencrypted packet
            $mac = hash_hmac('sha256', pack('N', $this->seqNoSend) . $raw, $this->macKeyS2C, true);
            // Encrypt
            $raw = $this->aesCtrEncrypt($raw, $this->encKeyS2C, $this->ctrS2C);
            $raw .= $mac;
        }

        $this->seqNoSend++;
        $this->rawWrite($raw);
    }    /**
     * Send data on the open session channel (wraps in SSH_MSG_CHANNEL_DATA).
     */
    public function sendChannelData(string $data): void
    {
        if (!$this->channelOpen || strlen($data) === 0) { return; }

        while (strlen($data) > 0) {
            if ($this->peerWindowSize <= 0) {
                if (!$this->waitForPeerWindowAdjust(30)) {
                    break;
                }
            }

            $chunkLen = min(strlen($data), $this->maxPacketSize, $this->peerWindowSize);
            if ($chunkLen <= 0) { break; }

            $chunk = substr($data, 0, $chunkLen);
            $msg  = chr(self::MSG_CHANNEL_DATA);
            $msg .= pack('N', $this->peerChannelId);
            $msg .= $this->sshString($chunk);
            $this->sendPacket($msg);
            $this->peerWindowSize -= strlen($chunk);
            $data = substr($data, strlen($chunk));
        }
    }

    /**
     * Wait for SSH_MSG_CHANNEL_WINDOW_ADJUST so we can continue sending.
     */
    private function waitForPeerWindowAdjust(int $timeoutSecs): bool
    {
        $deadline = time() + max(1, $timeoutSecs);
        while ($this->channelOpen && $this->peerWindowSize <= 0 && time() < $deadline) {
            $pkt = $this->recvPacket();
            if ($pkt === null || $pkt === '') {
                return false;
            }

            $msgType = ord($pkt[0]);
            if ($msgType === self::MSG_CHANNEL_WINDOW_ADJUST) {
                $offset = 1;
                $this->unpackUint32($pkt, $offset); // channel id
                $this->peerWindowSize += $this->unpackUint32($pkt, $offset);
                continue;
            }
            if ($msgType === self::MSG_CHANNEL_EOF || $msgType === self::MSG_CHANNEL_CLOSE) {
                $this->channelOpen = false;
                return false;
            }
            // Ignore unrelated packet types while waiting for window credit.
        }

        return $this->channelOpen && $this->peerWindowSize > 0;
    }

    /**
     * Receive and decrypt one SSH packet.
     * Returns the decrypted payload, or null on error/disconnect.
     */
    public function recvPacket(): ?string
    {
        $blockSize = 16;
        $macLen    = $this->encrypted ? 32 : 0;

        // Read the first block (contains packet_length and padding_length)
        $firstBlock = $this->rawRead($blockSize);
        if ($firstBlock === null) { return null; }

        if ($this->encrypted) {
            $firstBlock = $this->aesCtrDecrypt($firstBlock, $this->encKeyC2S, $this->ctrC2S);
        }

        $pktLen = unpack('N', substr($firstBlock, 0, 4))[1];
        if ($pktLen < 1 || $pktLen > 65536) { return null; }

        $remaining = $pktLen - ($blockSize - 4);
        $rest      = '';
        if ($remaining > 0) {
            $rest = $this->rawRead($remaining);
            if ($rest === null) { return null; }
            if ($this->encrypted) {
                $rest = $this->aesCtrDecrypt($rest, $this->encKeyC2S, $this->ctrC2S);
            }
        }

        $full   = $firstBlock . $rest;
        $padLen = ord($full[4]);
        $payload= substr($full, 5, $pktLen - $padLen - 1);

        if ($macLen > 0) {
            $receivedMac = $this->rawRead($macLen);
            if ($receivedMac === null) { return null; }
            $expectedMac = hash_hmac('sha256', pack('N', $this->seqNoRecv) . substr($full, 0, 4 + $pktLen), $this->macKeyC2S, true);
            if (!hash_equals($expectedMac, $receivedMac)) {
                $this->dbg("MAC verification failed");
                return null;
            }
        }

        $this->seqNoRecv++;

        // Handle transparent messages
        $msgType = strlen($payload) > 0 ? ord($payload[0]) : 0;
        if ($msgType === self::MSG_IGNORE)     { return $this->recvPacket(); }
        if ($msgType === self::MSG_DISCONNECT) { return null; }

        // Update our window if channel is open
        if ($msgType === self::MSG_CHANNEL_DATA && $this->channelOpen) {
            $offset = 1;
            $this->unpackUint32($payload, $offset);  // channel id
            $dataLen = $this->unpackUint32($payload, $offset);
            $this->windowSize -= $dataLen;
            if ($this->windowSize < 524288) {
                // Send window adjust
                $adj  = chr(self::MSG_CHANNEL_WINDOW_ADJUST);
                $adj .= pack('N', $this->peerChannelId);
                $adj .= pack('N', 1048576);
                $this->sendPacket($adj);
                $this->windowSize += 1048576;
            }
        }

        return $payload;
    }

    /**
     * Read raw application data from the channel (for BbsSession to use via the socket).
     * Blocks until channel data arrives, handles window/EOF transparently.
     * Returns null on channel close.
     */
    public function readChannelData(): ?string
    {
        while (true) {
            $pkt = $this->recvPacket();
            if ($pkt === null) { return null; }
            $msgType = ord($pkt[0]);

            if ($msgType === self::MSG_CHANNEL_DATA) {
                $offset = 1;
                $this->unpackUint32($pkt, $offset);  // channel id
                return $this->readString($pkt, $offset);
            }
            if ($msgType === self::MSG_CHANNEL_WINDOW_ADJUST) {
                $offset = 1;
                $this->unpackUint32($pkt, $offset);
                $this->peerWindowSize += $this->unpackUint32($pkt, $offset);
                continue;
            }
            if ($msgType === self::MSG_CHANNEL_EOF || $msgType === self::MSG_CHANNEL_CLOSE) {
                $this->sendChannelClose();
                return null;
            }
        }
    }

    /**
     * Send SSH_MSG_CHANNEL_EOF and SSH_MSG_CHANNEL_CLOSE.
     */
    public function sendChannelClose(): void
    {
        if (!$this->channelOpen) { return; }
        $this->channelOpen = false;
        $eof   = chr(self::MSG_CHANNEL_EOF)   . pack('N', $this->peerChannelId);
        $close = chr(self::MSG_CHANNEL_CLOSE) . pack('N', $this->peerChannelId);
        try { $this->sendPacket($eof); } catch (\Throwable $e) {}
        try { $this->sendPacket($close); } catch (\Throwable $e) {}
    }

    // =========================================================================
    // NON-BLOCKING BRIDGE INTERFACE
    // =========================================================================

    /**
     * Feed raw bytes from the network into the reassembly buffer.
     * Called by the bridge process after a non-blocking fread() on the SSH socket.
     */
    public function feedRawBytes(string $data): void
    {
        $this->rawBuf .= $data;
    }

    /**
     * Return the current peer (client) SSH window size.
     * The bridge uses this to decide whether it can call trySendChannelData().
     */
    public function getPeerWindowSize(): int
    {
        return $this->peerWindowSize;
    }

    /**
     * Non-blocking counterpart to readChannelData().
     *
     * Reads from the pre-fed rawBuf rather than blocking on the socket.
     * The bridge should call feedRawBytes() with any freshly read network data
     * before calling this, then drain all complete messages by calling it in a
     * loop until it returns false.
     *
     * Returns:
     *   string  — channel data payload (may be empty after a WINDOW_ADJUST)
     *   false   — not enough buffered bytes to complete a packet; call again after
     *             feeding more data with feedRawBytes()
     *   null    — channel closed or protocol error
     */
    public function tryReadChannelData(): string|false|null
    {
        while (true) {
            $pkt = $this->tryRecvPacket();
            if ($pkt === false) { return false; }
            if ($pkt === null)  { return null; }

            $msgType = strlen($pkt) > 0 ? ord($pkt[0]) : 0;

            if ($msgType === self::MSG_CHANNEL_DATA) {
                $offset = 1;
                $this->unpackUint32($pkt, $offset); // channel id
                return $this->readString($pkt, $offset);
            }
            if ($msgType === self::MSG_CHANNEL_WINDOW_ADJUST) {
                $offset = 1;
                $this->unpackUint32($pkt, $offset); // channel id
                $this->peerWindowSize += $this->unpackUint32($pkt, $offset);
                continue; // process next buffered packet
            }
            if ($msgType === self::MSG_CHANNEL_EOF || $msgType === self::MSG_CHANNEL_CLOSE) {
                $this->sendChannelClose();
                return null;
            }
            if ($msgType === self::MSG_IGNORE)     { continue; }
            if ($msgType === self::MSG_DISCONNECT) { return null; }
            // Unknown packet type — skip and continue draining.
        }
    }

    /**
     * Send channel data without blocking on SSH flow-control window.
     *
     * Sends as much data as the current window allows, then returns.  Any bytes
     * that could not be sent (window exhausted or channel closed) remain in $data
     * so the bridge can retry once tryReadChannelData() processes a WINDOW_ADJUST.
     *
     * @param string $data Modified in-place; holds unsent remainder on return.
     */
    public function trySendChannelData(string &$data): void
    {
        if (!$this->channelOpen) {
            return;
        }
        while (strlen($data) > 0 && $this->peerWindowSize > 0) {
            $chunkLen = min(strlen($data), $this->maxPacketSize, $this->peerWindowSize);
            if ($chunkLen <= 0) { break; }
            $chunk = substr($data, 0, $chunkLen);
            $msg   = chr(self::MSG_CHANNEL_DATA);
            $msg  .= pack('N', $this->peerChannelId);
            $msg  .= $this->sshString($chunk);
            $this->sendPacket($msg);
            $this->peerWindowSize -= strlen($chunk);
            $data = substr($data, strlen($chunk));
        }
    }

    /**
     * Try to receive and parse one SSH packet from the pre-fed rawBuf.
     *
     * Returns:
     *   string  — decrypted packet payload
     *   false   — not enough buffered bytes yet (cipher state is not advanced)
     *   null    — protocol error or disconnect
     *
     * AES-CTR state safety: the counter is saved before decrypting the first
     * block.  If the full packet is not yet buffered, the counter is restored so
     * that the same bytes will decrypt identically when called again after more
     * data has been fed.
     */
    private function tryRecvPacket(): string|false|null
    {
        $blockSize = 16;
        $macLen    = $this->encrypted ? 32 : 0;

        if (strlen($this->rawBuf) < $blockSize) {
            return false;
        }

        // Save AES-CTR counter before decrypting — allows a no-op rollback if
        // the full packet is not yet available.
        $savedCtr   = $this->ctrC2S;
        $firstBlock = substr($this->rawBuf, 0, $blockSize);
        if ($this->encrypted) {
            $firstBlock = $this->aesCtrDecrypt($firstBlock, $this->encKeyC2S, $this->ctrC2S);
        }

        $pktLen = unpack('N', substr($firstBlock, 0, 4))[1];
        if ($pktLen < 1 || $pktLen > 65536) {
            return null;
        }

        $remaining   = $pktLen - ($blockSize - 4);
        $totalNeeded = $blockSize + max(0, $remaining) + $macLen;

        if (strlen($this->rawBuf) < $totalNeeded) {
            // Not enough data — roll back counter and wait for more bytes.
            $this->ctrC2S = $savedCtr;
            return false;
        }

        // Commit: consume the first block and decrypt the remainder.
        $this->rawBuf = substr($this->rawBuf, $blockSize);
        $rest = '';
        if ($remaining > 0) {
            $rest = substr($this->rawBuf, 0, $remaining);
            $this->rawBuf = substr($this->rawBuf, $remaining);
            if ($this->encrypted) {
                $rest = $this->aesCtrDecrypt($rest, $this->encKeyC2S, $this->ctrC2S);
            }
        }

        $full = $firstBlock . $rest;

        if ($macLen > 0) {
            $receivedMac = substr($this->rawBuf, 0, $macLen);
            $this->rawBuf = substr($this->rawBuf, $macLen);
            $expectedMac = hash_hmac(
                'sha256',
                pack('N', $this->seqNoRecv) . substr($full, 0, 4 + $pktLen),
                $this->macKeyC2S,
                true
            );
            if (!hash_equals($expectedMac, $receivedMac)) {
                $this->dbg("tryRecvPacket: MAC verification failed seq={$this->seqNoRecv}");
                return null;
            }
        }

        $this->seqNoRecv++;

        $padLen  = ord($full[4]);
        $payload = substr($full, 5, $pktLen - $padLen - 1);

        // Transparent message handling (mirrors recvPacket).
        $msgType = strlen($payload) > 0 ? ord($payload[0]) : 0;
        if ($msgType === self::MSG_IGNORE)     { return $this->tryRecvPacket(); }
        if ($msgType === self::MSG_DISCONNECT) { return null; }

        // Update receive window for channel data packets.
        if ($msgType === self::MSG_CHANNEL_DATA && $this->channelOpen) {
            $offset  = 1;
            $this->unpackUint32($payload, $offset); // channel id
            $dataLen = $this->unpackUint32($payload, $offset);
            $this->windowSize -= $dataLen;
            if ($this->windowSize < 524288) {
                $adj  = chr(self::MSG_CHANNEL_WINDOW_ADJUST);
                $adj .= pack('N', $this->peerChannelId);
                $adj .= pack('N', 1048576);
                $this->sendPacket($adj);
                $this->windowSize += 1048576;
            }
        }

        return $payload;
    }

    // =========================================================================
    // AES-128-CTR
    // =========================================================================

    private function aesCtrEncrypt(string $data, string $key, string &$counter): string
    {
        $out = '';
        $len = strlen($data);
        $i   = 0;
        while ($i < $len) {
            $keystream = openssl_encrypt($counter, 'aes-128-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            $block     = min(16, $len - $i);
            for ($j = 0; $j < $block; $j++) {
                $out .= chr(ord($data[$i + $j]) ^ ord($keystream[$j]));
            }
            $counter = $this->incrementCounter($counter);
            $i += $block;
        }
        return $out;
    }

    private function aesCtrDecrypt(string $data, string $key, string &$counter): string
    {
        // CTR mode is symmetric
        return $this->aesCtrEncrypt($data, $key, $counter);
    }

    /**
     * Increment a 128-bit big-endian binary counter by 1.
     */
    private function incrementCounter(string $ctr): string
    {
        $bytes = array_values(unpack('C*', $ctr));
        for ($i = 15; $i >= 0; $i--) {
            $bytes[$i]++;
            if ($bytes[$i] < 256) { break; }
            $bytes[$i] = 0;
        }
        return pack('C*', ...$bytes);
    }

    // =========================================================================
    // SSH WIRE FORMAT HELPERS
    // =========================================================================

    /**
     * Encode a string as SSH uint32-prefixed bytes.
     */
    private function sshString(string $s): string
    {
        return pack('N', strlen($s)) . $s;
    }

    /**
     * Read a uint32-prefixed string from $data at $offset (pass by ref).
     */
    private function readString(string $data, int &$offset): string
    {
        $len     = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        $s       = substr($data, $offset, $len);
        $offset += $len;
        return $s;
    }

    /**
     * Read an SSH mpint from $data at $offset.
     */
    private function readMpint(string $data, int &$offset): \GMP
    {
        $bytes   = $this->readString($data, $offset);
        if ($bytes === '') { return gmp_init(0); }
        return gmp_import($bytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
    }

    /**
     * Encode a GMP integer as an SSH mpint (big-endian, MSB, with leading 0x00 if high bit set).
     */
    private function mpintEncode(\GMP $n): string
    {
        if (gmp_sign($n) === 0) { return $this->sshString(''); }
        $bytes = gmp_export($n, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return $this->sshString($bytes);
    }

    private function unpackUint32(string $data, int &$offset): int
    {
        $v       = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        return $v;
    }

    // =========================================================================
    // RSA HOST KEY
    // =========================================================================

    private function loadOrGenerateHostKey(): \OpenSSLAsymmetricKey
    {
        if (file_exists($this->hostKeyFile)) {
            $key = openssl_pkey_get_private(file_get_contents($this->hostKeyFile));
            if ($key !== false) { return $key; }
        }

        // Generate a new 3072-bit RSA host key
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('Failed to generate SSH host key: ' . openssl_error_string());
        }

        $dir = dirname($this->hostKeyFile);
        if (!is_dir($dir)) { mkdir($dir, 0700, true); }

        openssl_pkey_export($key, $pem);
        file_put_contents($this->hostKeyFile, $pem);
        chmod($this->hostKeyFile, 0600);

        return $key;
    }

    /**
     * Build the SSH wire-format RSA public key blob (ssh-rsa format, RFC 4253 §6.6).
     * Used in KEXDH_REPLY and for fingerprint display.
     */
    private function buildRsaPublicKeyBlob(): string
    {
        $details = openssl_pkey_get_details($this->hostKey);
        $rsa     = $details['rsa'];

        $blob  = $this->sshString('ssh-rsa');
        $blob .= $this->mpintEncode(gmp_import($rsa['e'], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN));
        $blob .= $this->mpintEncode(gmp_import($rsa['n'], 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN));
        return $blob;
    }

    // =========================================================================
    // RAW SOCKET I/O
    // =========================================================================

    private function rawWrite(string $data): void
    {
        $total = strlen($data);
        $sent  = 0;
        while ($sent < $total) {
            $n = @fwrite($this->socket, substr($data, $sent));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('SSH socket write failed');
            }
            $sent += $n;
        }
    }

    private function rawRead(int $length): ?string
    {
        $buf = '';
        while (strlen($buf) < $length) {
            $chunk = fread($this->socket, $length - strlen($buf));
            if ($chunk === false || $chunk === '') {
                if (feof($this->socket)) { return null; }
                usleep(1000);
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * Read a CRLF-terminated line from the raw socket (for version exchange).
     */
    private function readLine(int $timeoutSecs): ?string
    {
        stream_set_timeout($this->socket, $timeoutSecs);
        $line = fgets($this->socket, 256);
        if ($line === false) { return null; }
        return rtrim($line, "\r\n");
    }

    private function sendDisconnect(int $reason, string $message): void
    {
        $pkt  = chr(self::MSG_DISCONNECT);
        $pkt .= pack('N', $reason);
        $pkt .= $this->sshString($message);
        $pkt .= $this->sshString('en');
        try { $this->sendPacket($pkt); } catch (\Throwable $e) {}
    }

    // =========================================================================
    // TRANSPARENT SOCKET WRAPPER (for BbsSession)
    // =========================================================================

    /**
     * Return the underlying socket resource.
     * BbsSession reads/writes this socket directly; SshServer installs stream
     * filters so the SSH crypto layer remains transparent.
     *
     * For simplicity this implementation uses a socket pair:
     *   - SshServer reads from the SSH socket, decrypts, writes to the pair
     *   - BbsSession reads/writes the other end of the pair normally
     * The bridging happens in a forked process.  See SshServer::bridgeSession().
     */
    public function getSocket()
    {
        return $this->socket;
    }

    // =========================================================================
    // DEBUG
    // =========================================================================

    private function dbg(string $msg): void
    {
        if ($this->debug) {
            echo '[' . date('Y-m-d H:i:s') . "] [SSH] {$msg}\n";
        }
    }
}
