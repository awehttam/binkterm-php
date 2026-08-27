<?php

namespace BinktermPHP\SshServer;

/**
 * SshSession — SSH-2 wire protocol handler for a single accepted TCP connection.
 *
 * Implements a minimal but standards-compliant SSH-2 server sufficient to run
 * an interactive BBS session.  Uses only ext-openssl, ext-gmp and ext-sodium
 * (bundled with PHP since 7.2); no Composer SSH library is required.
 *
 * Supported algorithms (chosen for maximum client compatibility):
 *   Key exchange  : curve25519-sha256, curve25519-sha256@libssh.org,
 *                   diffie-hellman-group14-sha256
 *   Host key      : rsa-sha2-256 (2048-bit RSA, auto-generated if absent)
 *   Cipher C→S   : aes128-ctr
 *   Cipher S→C   : aes128-ctr
 *   MAC C→S      : hmac-sha2-256
 *   MAC S→C      : hmac-sha2-256
 *   Compression   : none
 *   Auth method   : password (verified via the BBS /api/auth/login endpoint)
 *
 * curve25519-sha256 is offered first: some clients (e.g. SyncTERM's DeuceSSH
 * library) only implement modern ECDH/group-exchange KEX methods and do not
 * support the fixed diffie-hellman-group14-sha256 group at all, so a server
 * that only offered group14 would have zero KEX overlap with them.
 *
 * After a successful shell request the caller receives the socket (still in
 * plaintext from its perspective — crypto is transparent) together with the
 * authenticated user data so BbsSession can skip its own login UI.
 *
 * References: RFC 4253, RFC 4252, RFC 4254, RFC 4419, RFC 8332, RFC 8731.
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
    private bool $sixelSupported   = false;

    // Terminal size reported by client pty-req or window-change
    private int $termCols = 80;
    private int $termRows = 24;
    private bool $shellStarted = false;

    /** TERM string reported by the client's pty-req, if any (e.g. "xterm-256color"). */
    private ?string $termType = null;

    /** Pending resize from a window-change channel request, not yet consumed. */
    private ?array $pendingResize = null;

    // Raw bytes pre-fed by the bridge for non-blocking packet reassembly.
    private string $rawBuf = '';
    private string $prefetchedChannelData = '';

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
            $this->drainStartupChannelInput();
            $this->sixelSupported = $this->probeSixelSupport();

            return array_merge($authResult, [
                'cols' => $this->termCols,
                'rows' => $this->termRows,
                'term_type' => $this->termType,
                'sixel_supported' => $this->sixelSupported,
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

    /** KEX algorithms this server supports, in our preference order. */
    private const SUPPORTED_KEX_ALGORITHMS = [
        'curve25519-sha256',
        'curve25519-sha256@libssh.org',
        'diffie-hellman-group14-sha256',
    ];

    /**
     * Ciphers this server supports, in our preference order.  aes256-ctr is
     * offered alongside aes128-ctr because some clients (e.g. SyncTERM's
     * DeuceSSH library) implement aes256-ctr/aes128-cbc but not aes128-ctr —
     * with only aes128-ctr offered there was zero cipher overlap with them.
     */
    private const SUPPORTED_CIPHERS = ['aes256-ctr', 'aes128-ctr'];

    // Negotiated cipher name per direction (set in keyExchange()).
    private string $cipherC2S = 'aes128-ctr';
    private string $cipherS2C = 'aes128-ctr';

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

        $lists = $this->parseKexInitNameLists($pkt);

        $negotiatedKex = $this->negotiate($lists[0], self::SUPPORTED_KEX_ALGORITHMS);
        if ($negotiatedKex === null) {
            $this->dbg("No matching KEX algorithm with client");
            $this->sendDisconnect(self::DISCONNECT_PROTOCOL_ERROR, 'No matching key exchange algorithm');
            return false;
        }
        $this->dbg("Negotiated KEX algorithm: {$negotiatedKex}");

        $cipherC2S = $this->negotiate($lists[2], self::SUPPORTED_CIPHERS);
        $cipherS2C = $this->negotiate($lists[3], self::SUPPORTED_CIPHERS);
        if ($cipherC2S === null || $cipherS2C === null) {
            $this->dbg("No matching cipher with client");
            $this->sendDisconnect(self::DISCONNECT_PROTOCOL_ERROR, 'No matching cipher algorithm');
            return false;
        }
        $this->cipherC2S = $cipherC2S;
        $this->cipherS2C = $cipherS2C;
        $this->dbg("Negotiated ciphers: C2S={$cipherC2S} S2C={$cipherS2C}");

        // Read KEX_DH_INIT / KEX_ECDH_INIT (both use message number 30)
        $pkt = $this->recvPacket();
        if ($pkt === null || ord($pkt[0]) !== self::MSG_KEXDH_INIT) { return false; }

        $result = $negotiatedKex === 'diffie-hellman-group14-sha256'
            ? $this->kexGroup14($pkt)
            : $this->kexCurve25519($pkt);
        if ($result === null) { return false; }
        [$kBytes, $H] = $result;

        if ($this->sessionId === '') {
            $this->sessionId = $H;
        }

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

    /**
     * Parse all ten name-lists out of a KEXINIT payload, in wire order:
     * [kex, host_key, enc_c2s, enc_s2c, mac_c2s, mac_s2c, comp_c2s, comp_s2c,
     *  lang_c2s, lang_s2c].
     *
     * @return array<int,array<int,string>>
     */
    private function parseKexInitNameLists(string $payload): array
    {
        $offset = 1 + 16;  // skip msg type + 16-byte cookie
        $lists = [];
        for ($i = 0; $i < 10; $i++) {
            $s = $this->readString($payload, $offset);
            $lists[] = $s === '' ? [] : explode(',', $s);
        }
        return $lists;
    }

    /**
     * Pick the algorithm to use: the first name in the client's offered list
     * (its preference order, per RFC 4253 §7.1) that this server also
     * supports.  Returns null if there is no overlap.
     *
     * @param array<int,string> $clientAlgos
     * @param array<int,string> $supported
     */
    private function negotiate(array $clientAlgos, array $supported): ?string
    {
        foreach ($clientAlgos as $algo) {
            if (in_array($algo, $supported, true)) {
                return $algo;
            }
        }
        return null;
    }

    /**
     * Diffie-Hellman Group 14 key exchange (RFC 4253 §8).
     *
     * @return array{0:string,1:string}|null [kBytes, H] or null on error
     */
    private function kexGroup14(string $initPkt): ?array
    {
        $offset = 1;
        $e = $this->readMpint($initPkt, $offset);  // client's DH public value

        // Generate server DH private value y, compute f = g^y mod p
        $p  = gmp_init(self::DH_GROUP14_P, 16);
        $g  = gmp_init(self::DH_GROUP14_G, 10);
        $y  = gmp_import(random_bytes(32));          // 256-bit random private key
        $f  = gmp_powm($g, $y, $p);
        $K  = gmp_powm($e, $y, $p);                 // shared secret

        // Encode K as SSH mpint
        $kBytes = $this->mpintEncode($K);

        // Build exchange hash H = SHA-256(V_C || V_S || I_C || I_S || K_S || e || f || K)
        $H = $this->computeExchangeHashFfdh($e, $f, $kBytes);

        $this->signAndSendKexReply($this->mpintEncode($f), $H);

        return [$kBytes, $H];
    }

    /**
     * Curve25519 ECDH key exchange (RFC 8731).
     *
     * @return array{0:string,1:string}|null [kBytes, H] or null on error
     */
    private function kexCurve25519(string $initPkt): ?array
    {
        $offset = 1;
        $qC = $this->readString($initPkt, $offset);  // client's ephemeral public key
        if (strlen($qC) !== 32) { return null; }

        $keypair  = sodium_crypto_box_keypair();
        $serverSk = sodium_crypto_box_secretkey($keypair);
        $qS       = sodium_crypto_box_publickey($keypair);

        $shared = sodium_crypto_scalarmult($serverSk, $qC);
        sodium_memzero($serverSk);

        // Reject a degenerate all-zero shared secret (RFC 8731 §4 — the peer
        // supplied a low-order point).
        if ($shared === str_repeat("\x00", 32)) {
            $this->dbg("curve25519 KEX produced all-zero shared secret, rejecting");
            return null;
        }

        $kGmp   = gmp_import($shared, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $kBytes = $this->mpintEncode($kGmp);

        $H = $this->computeExchangeHashEcdh($qC, $qS, $kBytes);

        $this->signAndSendKexReply($this->sshString($qS), $H);

        return [$kBytes, $H];
    }

    /**
     * Sign the exchange hash with the RSA host key and send SSH_MSG_KEXDH_REPLY
     * (shared wire format for both FFDH and ECDH KEX methods).
     *
     * @param string $serverPublicValue Already wire-encoded (mpint for FFDH's f, SSH string for ECDH's Q_S)
     */
    private function signAndSendKexReply(string $serverPublicValue, string $H): void
    {
        $sig = '';
        openssl_sign($H, $sig, $this->hostKey, OPENSSL_ALGO_SHA256);
        $sigBlob = $this->sshString('rsa-sha2-256') . $this->sshString($sig);

        $reply  = chr(self::MSG_KEXDH_REPLY);
        $reply .= $this->sshString($this->hostKeyBlob);
        $reply .= $serverPublicValue;
        $reply .= $this->sshString($sigBlob);
        $this->sendPacket($reply);
    }

    private function buildKexInit(): string
    {
        $pkt  = chr(self::MSG_KEXINIT);
        $pkt .= random_bytes(16);  // cookie

        $nameList = fn(string ...$names) => $this->sshString(implode(',', $names));

        $pkt .= $nameList(...self::SUPPORTED_KEX_ALGORITHMS);  // kex
        $pkt .= $nameList('rsa-sha2-256');                   // server host key
        $pkt .= $nameList(...self::SUPPORTED_CIPHERS);       // enc C→S
        $pkt .= $nameList(...self::SUPPORTED_CIPHERS);       // enc S→C
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

    private function computeExchangeHashFfdh(\GMP $e, \GMP $f, string $kBytes): string
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

    private function computeExchangeHashEcdh(string $qC, string $qS, string $kBytes): string
    {
        $data  = $this->sshString($this->clientVersion);
        $data .= $this->sshString($this->serverVersion);
        $data .= $this->sshString($this->clientKexInitPayload);
        $data .= $this->sshString($this->serverKexInitPayload);
        $data .= $this->sshString($this->hostKeyBlob);
        $data .= $this->sshString($qC);
        $data .= $this->sshString($qS);
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
        $this->encKeyC2S = $derive('C', $this->cipherKeyLength($this->cipherC2S));
        $this->encKeyS2C = $derive('D', $this->cipherKeyLength($this->cipherS2C));
        $this->macKeyC2S = $derive('E', 32);
        $this->macKeyS2C = $derive('F', 32);

        $this->ctrC2S = $this->ivC2S;
        $this->ctrS2C = $this->ivS2C;
    }

    private function cipherKeyLength(string $cipher): int
    {
        return $cipher === 'aes256-ctr' ? 32 : 16;
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
     * Discard any SSH channel-data packets already queued immediately after shell startup.
     *
     * Some SSH clients send early buffered bytes during PTY/shell setup. If we hand those
     * straight to BbsSession they appear as phantom keystrokes and can skip login/menu
     * screens. Drain only what is already readable right now; do not block waiting for
     * user input.
     */
    private function drainStartupChannelInput(): void
    {
        $discarded = 0;
        $sawData = false;

        $prevBlocking = stream_get_meta_data($this->socket)['blocked'] ?? true;
        stream_set_blocking($this->socket, false);

        try {
            while (true) {
                $read = [$this->socket];
                $write = $except = null;
                $ready = @stream_select($read, $write, $except, 0, 0);
                if ($ready === false || $ready === 0) {
                    break;
                }

                $raw = @fread($this->socket, 65536);
                if ($raw === false || $raw === '') {
                    if (feof($this->socket)) {
                        break;
                    }
                    continue;
                }
                $sawData = true;
                $this->feedRawBytes($raw);

                while (true) {
                    $chunk = $this->tryReadChannelData();
                    if ($chunk === false) {
                        break;
                    }
                    if ($chunk === null) {
                        return;
                    }
                    if ($chunk !== '') {
                        $discarded += strlen($chunk);
                    }
                }
            }
        } finally {
            stream_set_blocking($this->socket, (bool)$prevBlocking);
        }

        if ($this->debug && ($discarded > 0 || $sawData)) {
            $this->dbg("Drained {$discarded} bytes of queued SSH startup input before BBS handoff");
        }
    }

    /**
     * Probe the SSH terminal channel for Sixel support using Primary DA (ESC[c).
     *
     * Unlike the telnet-side probe, this runs entirely inside the SSH transport
     * layer before BbsSession starts. Any device-attribute reply is consumed
     * here so it cannot leak into pre-login menu input on Windows or other
     * non-forked SSH paths.
     */
    private function probeSixelSupport(): bool
    {
        if (!$this->channelOpen) {
            return false;
        }

        try {
            $this->sendChannelData("\033[c");
        } catch (\Throwable $e) {
            return false;
        }

        $buf = '';
        $deadline = microtime(true) + 0.75;
        $prevBlocking = stream_get_meta_data($this->socket)['blocked'] ?? true;
        stream_set_blocking($this->socket, false);

        try {
            while (microtime(true) < $deadline) {
                $read = [$this->socket];
                $write = $except = null;
                $remainingUsec = max(0, (int)(($deadline - microtime(true)) * 1_000_000));
                $ready = @stream_select($read, $write, $except, 0, $remainingUsec);
                if ($ready === false || $ready === 0) {
                    break;
                }

                $raw = @fread($this->socket, 65536);
                if ($raw === false || $raw === '') {
                    if (feof($this->socket)) {
                        break;
                    }
                    continue;
                }
                $this->feedRawBytes($raw);

                while (true) {
                    $chunk = $this->tryReadChannelData();
                    if ($chunk === false) {
                        break;
                    }
                    if ($chunk === null) {
                        return false;
                    }
                    if ($chunk !== '') {
                        $buf .= $chunk;
                        if (preg_match('/\033\[\?([0-9;]+)c/', $buf, $matches, PREG_OFFSET_CAPTURE)) {
                            $attrs = explode(';', $matches[1][0]);
                            $full = $matches[0][0];
                            $start = $matches[0][1];
                            $end = $start + strlen($full);
                            $leftover = substr($buf, 0, $start) . substr($buf, $end);
                            if ($leftover !== '') {
                                $this->prefetchedChannelData .= $leftover;
                            }
                            return in_array('4', $attrs, true);
                        }
                    }
                }
            }
        } finally {
            stream_set_blocking($this->socket, (bool)$prevBlocking);
        }

        if ($buf !== '') {
            $leftover = preg_replace('/\033\[[?>=]?[0-9;]*c/', '', $buf) ?? $buf;
            if ($leftover !== '') {
                $this->prefetchedChannelData .= $leftover;
            }
        }

        return false;
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
            $this->termType = $this->readString($pkt, $offset);
            $this->termCols = $this->unpackUint32($pkt, $offset);
            $this->termRows = $this->unpackUint32($pkt, $offset);
            $this->unpackUint32($pkt, $offset);        // px width
            $this->unpackUint32($pkt, $offset);        // px height
            $this->readString($pkt, $offset);          // terminal modes
            $success = true;
            $this->dbg("PTY requested: {$this->termCols}x{$this->termRows}");
        } elseif ($reqType === 'window-change') {
            // cols, rows, px-width, px-height
            $cols = $this->unpackUint32($pkt, $offset);
            $rows = $this->unpackUint32($pkt, $offset);
            // px dimensions ignored
            if ($cols > 0) { $this->termCols = $cols; }
            if ($rows > 0) { $this->termRows = $rows; }
            $this->pendingResize = ['cols' => $this->termCols, 'rows' => $this->termRows];
            $success = true;
            $this->dbg("window-change: {$this->termCols}x{$this->termRows}");
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

    /**
     * Return and clear any pending resize event from a window-change request.
     *
     * Returns ['cols' => int, 'rows' => int] on resize, null if no resize pending.
     * Called by the bridge and stream wrapper to inject a NAWS subneg into the
     * plain data stream so BbsSession can update its terminal dimensions.
     */
    public function consumePendingResize(): ?array
    {
        $r = $this->pendingResize;
        $this->pendingResize = null;
        return $r;
    }

    /**
     * Build a TELNET IAC SB NAWS sequence for the given dimensions.
     *
     * Injected into the plain-socket stream so BbsSession's existing NAWS
     * handler picks up SSH window-change events transparently.
     */
    public static function nawsBytes(int $cols, int $rows): string
    {
        return chr(255) . chr(250) . chr(31)                       // IAC SB NAWS
             . chr(($cols >> 8) & 0xFF) . chr($cols & 0xFF)
             . chr(($rows >> 8) & 0xFF) . chr($rows & 0xFF)
             . chr(255) . chr(240);                                // IAC SE
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
        if ($this->prefetchedChannelData !== '') {
            $chunk = $this->prefetchedChannelData;
            $this->prefetchedChannelData = '';
            return $chunk;
        }

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
            if ($msgType === self::MSG_CHANNEL_REQUEST) {
                $this->handleChannelRequest($pkt);
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
     * Return the TERM string reported by the client's pty-req (e.g. "xterm-256color"),
     * or null if no pty-req has been received yet. SSH conveys this out-of-band from
     * the plain-socket byte stream, unlike telnet's in-band TTYPE negotiation, so
     * BbsSession can't detect it itself — the daemon entrypoint seeds BbsSession's
     * $state['terminal_type'] with this value after the channel is established.
     */
    public function getTermType(): ?string
    {
        return $this->termType;
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
        if ($this->prefetchedChannelData !== '') {
            $chunk = $this->prefetchedChannelData;
            $this->prefetchedChannelData = '';
            return $chunk;
        }

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
            if ($msgType === self::MSG_CHANNEL_REQUEST) {
                $this->handleChannelRequest($pkt);
                continue;
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
        // AES-CTR keystream is generated by ECB-encrypting the counter block;
        // key length (16 vs 32 bytes) selects AES-128 vs AES-256.
        $algo = strlen($key) === 32 ? 'aes-256-ecb' : 'aes-128-ecb';
        $out = '';
        $len = strlen($data);
        $i   = 0;
        while ($i < $len) {
            $keystream = openssl_encrypt($counter, $algo, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
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
            if ($n === false) {
                throw new \RuntimeException('SSH socket write failed');
            }
            if ($n === 0) {
                // Send buffer temporarily full (non-blocking socket).
                // Wait up to 5 s for the socket to become writable before retrying.
                $w = [$this->socket];
                $r = null;
                $e = null;
                $ready = @stream_select($r, $w, $e, 5);
                if ($ready === false || $ready === 0) {
                    throw new \RuntimeException('SSH socket write timed out');
                }
                continue;
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
