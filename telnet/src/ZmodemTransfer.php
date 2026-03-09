<?php

namespace BinktermPHP\TelnetServer;

/**
 * ZmodemTransfer — ZMODEM file transfer protocol (CRC-16 variant).
 *
 * Implements the sender (sz — server sends file to client) and receiver
 * (rz — server receives file from client) sides of the ZMODEM protocol,
 * as documented in ZMODEM.DOC by Chuck Forsberg (1988).
 *
 * Telnet IAC (0xFF) escaping: pass $escapeTelnetIac = true on plain-telnet
 * connections so that 0xFF bytes in binary file data are doubled before
 * transmission (telnet protocol requires 0xFF 0xFF to represent a literal
 * 0xFF in the data stream).  On SSH connections, pass false.
 */
class ZmodemTransfer
{
    // ---- Frame type constants ----
    private const ZRQINIT = 0x00; // Request receiver init (sender → receiver)
    private const ZRINIT  = 0x01; // Receiver ready
    private const ZACK    = 0x03; // Acknowledgement
    private const ZFILE   = 0x04; // File header
    private const ZSKIP   = 0x05; // Skip this file
    private const ZNAK    = 0x06; // Negative acknowledgement
    private const ZABORT  = 0x07; // Abort batch transfer
    private const ZFIN    = 0x08; // Finish session
    private const ZRPOS   = 0x09; // Resume from offset
    private const ZDATA   = 0x0A; // Data subpacket(s)
    private const ZEOF    = 0x0B; // End of file
    private const ZCRC    = 0x0D; // Request/response: file CRC-32

    // ---- Header format bytes (follow ZPAD ZDLE) ----
    private const ZPAD  = 0x2A; // '*'
    private const ZDLE  = 0x18; // ZMODEM data-link escape
    private const ZHEX  = 0x42; // 'B' — hex-encoded header
    private const ZBIN  = 0x41; // 'A' — binary header (CRC-16)
    private const ZBIN32= 0x43; // 'C' — binary header (CRC-32, rx only)

    // ---- Subpacket frame-end bytes (follow ZDLE in a data subpacket) ----
    private const ZCRCE = 0x68; // End of frame; CRC follows
    private const ZCRCG = 0x69; // Frame continues non-stop
    private const ZCRCQ = 0x6A; // Frame continues; ZACK expected
    private const ZCRCW = 0x6B; // End of frame; ZACK expected
    private const ZRUB0 = 0x6C; // Escaped 0x7F
    private const ZRUB1 = 0x6D; // Escaped 0xFF

    // ---- ZRINIT capability flags (byte 0 of 4-byte header data) ----
    private const CANFDX  = 0x01; // Full-duplex capable
    private const CANOVIO = 0x02; // I/O overlap capable

    /** Data chunk size for send subpackets. */
    private const SUBPACKET_SIZE = 1024;

    /** Seconds to wait for a frame before giving up. */
    private const TIMEOUT = 30;

    /** Consecutive CAN (0x18) bytes needed to recognise a Ctrl+X abort. */
    private const CAN_ABORT_COUNT = 5;

    /** Consecutive CAN bytes seen so far in this transfer (reset on each call). */
    private static int $canCount = 0;
    /** Last on-the-wire byte emitted by ZDLE escaping (for CR-after-@ rule). */
    private static int $lastZdleSent = 0;
    /** Lazy-loaded debug toggle. */
    private static ?bool $debugEnabled = null;
    /** Optional cached external binary paths. */
    private static ?string $szPath = null;
    private static ?string $rzPath = null;
    /** Buffered inbound bytes and read cursor. */
    private static string $rxBuffer = '';
    private static int $rxBufferPos = 0;
    /** Whether to un-escape Telnet IAC (0xFF 0xFF → 0xFF) on inbound data. */
    private static bool $unescapeIac = false;

    // ===========================================================
    // PUBLIC API
    // ===========================================================

    /**
     * Send a file to the remote client (server acts as sz).
     *
     * @param resource $conn           Socket connected to the client
     * @param string   $path           Absolute filesystem path of the file
     * @param string   $name           Filename to advertise to the receiver
     * @param bool     $escapeTelnetIac Escape 0xFF bytes for plain-telnet links
     * @return bool true on success
     */
    public static function send($conn, string $path, string $name, bool $escapeTelnetIac = false): bool
    {
        if (!self::isTransfersEnabled()) {
            self::dbg("SEND blocked: TERMINAL_FILE_TRANSFERS disabled");
            return false;
        }
        if (!self::forcePHPImplementation() && self::getSzPath() !== null) {
            self::info("SEND via sz: " . basename($path));
            return self::sendWithSz($conn, $path, $escapeTelnetIac);
        }
        self::info("SEND via PHP: " . basename($path));
        self::$canCount = 0;
        self::$lastZdleSent = 0;
        self::$rxBuffer = '';
        self::$rxBufferPos = 0;
        self::dbg("SEND start name={$name} path={$path} telnetIac=" . ($escapeTelnetIac ? '1' : '0'));

        if (!is_readable($path)) {
            self::dbg("SEND fail unreadable path");
            return false;
        }

        $fileSize = filesize($path);
        if ($fileSize === false) {
            return false;
        }

        // 1. Announce our presence
        self::sendHexHeader($conn, self::ZRQINIT, [0, 0, 0, 0], $escapeTelnetIac);
        self::dbg("TX " . self::frameName(self::ZRQINIT) . " (ZHEX)");

        // 2. Wait for ZRINIT from the receiver
        $header = self::receiveHeader($conn);
        self::dbg("RX " . self::headerToString($header));
        if ($header === null || $header['type'] !== self::ZRINIT) {
            self::dbg("SEND fail expected ZRINIT");
            return false;
        }

        // Some receivers (e.g. ZOC) continue sending ZRINIT retries after the
        // first ZRINIT is received, before their ZMODEM engine is fully ready
        // to accept ZFILE.  A single-shot drain can miss retries that arrive
        // just after the drain window.  Loop-drain until the socket has been
        // quiet for at least one polling interval (60 ms), or 400 ms total.
        // This prevents a stale ZRINIT from being read as the ZRPOS response
        // to ZFILE attempt=1, which would force a needless ZFILE retry and
        // cause the terminal to log spurious CRC / "Garbage count" errors.
        $drainDeadline = microtime(true) + 0.4;
        do {
            $r = [$conn]; $w = null; $e = null;
            if (!@stream_select($r, $w, $e, 0, 60000)) { // quiet for 60 ms → done
                break;
            }
            @fread($conn, 4096);
        } while (microtime(true) < $drainDeadline);
        self::$rxBuffer    = '';
        self::$rxBufferPos = 0;

        // 3-4. Send ZFILE and wait for ZRPOS. Some receivers may resync with
        // ZRINIT/ZNAK; retry a few times before failing.
        $fileInfo = $name . "\0" . $fileSize . " 0 0 0\0";
        $header   = null;
        $zfileOk  = false;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::$lastZdleSent = 0; // reset escape state for each attempt
            self::sendBinHeader($conn, self::ZFILE, [0, 0, 0, 0], $escapeTelnetIac);
            self::sendDataSubpacket($conn, $fileInfo, self::ZCRCW, $escapeTelnetIac);
            self::dbg("TX " . self::frameName(self::ZFILE) . " + fileinfo attempt={$attempt}");

            $header = self::receiveHeader($conn);
            self::dbg("RX " . self::headerToString($header));
            if ($header !== null && $header['type'] === self::ZCRC) {
                // Some receivers (notably SyncTERM) may probe with ZCRC before
                // sending ZRPOS, especially when a same-named local file exists.
                // Reply with CRC-32 and continue waiting for ZRPOS/ZSKIP.
                $crc = self::fileCrc32($path);
                self::sendBinHeader($conn, self::ZCRC, self::int32ToBytes($crc), $escapeTelnetIac);
                self::dbg("TX " . self::frameName(self::ZCRC) . " crc={$crc} attempt={$attempt}");
                $header = self::receiveHeader($conn);
                self::dbg("RX after ZCRC " . self::headerToString($header));
            }
            if ($header === null) {
                continue;
            }
            if ($header['type'] === self::ZSKIP || $header['type'] === self::ZABORT) {
                self::dbg("SEND aborted/skipped by peer");
                return false;
            }
            if ($header['type'] === self::ZRPOS) {
                $zfileOk = true;
                break;
            }
            // ZRINIT/ZNAK/anything else: retry ZFILE.
        }
        if (!$zfileOk || $header === null || $header['type'] !== self::ZRPOS) {
            self::dbg("SEND fail expected ZRPOS after ZFILE retries");
            return false;
        }

        $offset = (int)$header['pos'];

        $complete = false;
        for ($pass = 1; $pass <= 8; $pass++) {
            if ($offset < 0) {
                $offset = 0;
            }
            if ($offset > $fileSize) {
                $offset = $fileSize;
            }

            // 5. Send file data (or resend from requested offset).
            $fh = fopen($path, 'rb');
            if (!$fh) {
                return false;
            }
            if ($offset > 0) {
                fseek($fh, $offset);
            }

            self::sendBinHeader($conn, self::ZDATA, self::int32ToBytes($offset), $escapeTelnetIac);
            self::dbg("TX " . self::frameName(self::ZDATA) . " pos={$offset} pass={$pass}");

            // One-chunk lookahead: always know whether the current chunk is last.
            $current = fread($fh, self::SUBPACKET_SIZE);
            while ($current !== false && $current !== '') {
                $next   = fread($fh, self::SUBPACKET_SIZE);
                $isLast = ($next === false || $next === '');
                self::sendDataSubpacket($conn, $current, $isLast ? self::ZCRCE : self::ZCRCG, $escapeTelnetIac);
                if ($isLast) {
                    break;
                }
                $current = $next;
            }
            // Edge case: empty file; close ZDATA frame.
            if ($current === false || $current === '') {
                self::sendDataSubpacket($conn, '', self::ZCRCE, $escapeTelnetIac);
            }
            fclose($fh);

            // 6. ZEOF
            self::sendBinHeader($conn, self::ZEOF, self::int32ToBytes($fileSize), $escapeTelnetIac);
            self::dbg("TX " . self::frameName(self::ZEOF) . " pos={$fileSize} pass={$pass}");

            // 7. Wait for ZRINIT (done) or ZRPOS (retry from offset).
            $header = self::receiveHeader($conn);
            self::dbg("RX post-EOF " . self::headerToString($header) . " pass={$pass}");
            if ($header === null) {
                continue;
            }
            if ($header['type'] === self::ZRINIT) {
                $complete = true;
                break;
            }
            if ($header['type'] === self::ZRPOS) {
                $offset = (int)$header['pos'];
                continue;
            }
            if ($header['type'] === self::ZSKIP || $header['type'] === self::ZABORT) {
                return false;
            }
            if ($header['type'] === self::ZFIN) {
                // Client ended the session directly (common in lrzsz rz).
                $complete = true;
                break;
            }
        }
        if (!$complete) {
            self::dbg("SEND fail: did not receive ZRINIT after EOF/retries");
            return false;
        }

        // 8. ZFIN — end the session
        self::sendHexHeader($conn, self::ZFIN, [0, 0, 0, 0], $escapeTelnetIac);
        self::dbg("TX " . self::frameName(self::ZFIN));

        // 9. Drain "OO" (over-and-out) and any telnet negotiation that buffered
        //    during the transfer.  Two reads with a short timeout cover OO plus
        //    any slightly-delayed bytes.  stream_select is intentionally avoided
        //    here because it is unreliable with SSL streams on Windows.
        @stream_set_timeout($conn, 1);
        @fread($conn, 512); // first pass: "OO" + anything that arrived promptly
        @fread($conn, 512); // second pass: catch any slightly-delayed bytes
        @stream_set_timeout($conn, 300); // restore normal session timeout

        self::dbg("SEND success");
        self::info("SEND via PHP: " . basename($path) . " — ok");
        return true;
    }

    /**
     * Receive a file from the remote client (server acts as rz).
     *
     * @param resource $conn    Socket connected to the client
     * @param string   $destDir Directory to write the received file into
     * @param bool     $escapeTelnetIac Escape 0xFF bytes for plain-telnet links
     * @return string|null Absolute path of the saved file, or null on failure/abort
     */
    public static function receive($conn, string $destDir, bool $escapeTelnetIac = false): ?string
    {
        if (!self::isTransfersEnabled()) {
            self::dbg("RECV blocked: TERMINAL_FILE_TRANSFERS disabled");
            return null;
        }
        if (!self::forcePHPImplementation() && self::getRzPath() !== null) {
            self::info("RECV via rz: dir={$destDir}");
            return self::receiveWithRz($conn, $destDir, $escapeTelnetIac);
        }
        self::info("RECV via PHP: dir={$destDir}");
        self::$canCount = 0;
        self::$lastZdleSent = 0;
        self::$rxBuffer = '';
        self::$rxBufferPos = 0;
        self::$unescapeIac = $escapeTelnetIac;
        self::dbg("RECV start dir={$destDir} telnetIac=" . ($escapeTelnetIac ? '1' : '0'));

        // Compatibility trigger: many terminal clients expect "rz" prompt text
        // from the remote before they begin ZMODEM upload.
        self::writeRaw($conn, "rz\r", $escapeTelnetIac);

        // 1. Send ZRINIT and retry every 3 seconds until the sender responds
        //    (up to ~30 seconds total).  The sender may need a moment to open
        //    its file-picker before it can send ZRQINIT / ZFILE.
        $header = null;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            self::sendHexHeader($conn, self::ZRINIT, self::buildZrinitData(), $escapeTelnetIac);
            self::dbg("TX ZRINIT attempt={$attempt}");
            $header = self::receiveHeader($conn, 3);
            self::dbg("RX after ZRINIT attempt={$attempt} " . self::headerToString($header));
            if ($header !== null) {
                break;
            }
        }
        @stream_set_timeout($conn, self::TIMEOUT);

        // 2. Wait for ZFILE (sender may send ZRQINIT first)
        if ($header === null) {
            self::dbg("RECV fail waiting initial header");
            return null;
        }
        if ($header['type'] === self::ZRQINIT) {
            // Re-send ZRINIT and wait for ZFILE
            self::sendHexHeader($conn, self::ZRINIT, self::buildZrinitData(), $escapeTelnetIac);
            $header = self::receiveHeader($conn);
        }
        if ($header === null || $header['type'] !== self::ZFILE) {
            self::dbg("RECV fail expected ZFILE, got " . self::headerToString($header));
            return null;
        }

        // 3. Read the ZFILE data subpacket to get filename + size
        $fileData = self::receiveDataSubpacket($conn);
        if ($fileData === null) {
            self::sendHexHeader($conn, self::ZNAK, [0, 0, 0, 0], $escapeTelnetIac);
            self::dbg("RECV fail reading ZFILE data");
            return null;
        }

        $nullPos  = strpos($fileData, "\0");
        $fileName = $nullPos !== false ? substr($fileData, 0, $nullPos) : 'upload_' . time();
        $fileName = basename($fileName);
        if ($fileName === '') {
            $fileName = 'upload_' . time();
        }

        $destPath = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

        // 4. Accept at offset 0
        self::sendHexHeader($conn, self::ZRPOS, [0, 0, 0, 0], $escapeTelnetIac);

        // 5. Open the destination file
        $fh = fopen($destPath, 'wb');
        if (!$fh) {
            self::dbg("RECV fail opening dest file");
            return null;
        }

        $success = false;
        $done    = false;
        $sawZfin = false;

        while (!$done) {
            $header = self::receiveHeader($conn);
            if ($header === null) {
                break;
            }

            switch ($header['type']) {
                case self::ZDATA:
                    if (!self::receiveFileData($conn, $fh, $escapeTelnetIac)) {
                        $done = true;
                    }
                    break;

                case self::ZEOF:
                    $success = true;
                    $done    = true;
                    // Acknowledge: ready for next file (or ZFIN)
                    self::sendHexHeader($conn, self::ZRINIT, self::buildZrinitData(), $escapeTelnetIac);
                    self::dbg("RECV got ZEOF");
                    break;

                case self::ZFIN:
                    $sawZfin = true;
                    $done = true;
                    self::sendHexHeader($conn, self::ZFIN, [0, 0, 0, 0], $escapeTelnetIac);
                    self::writeRaw($conn, "OO", $escapeTelnetIac);
                    self::dbg("RECV got ZFIN, sent ZFIN+OO");
                    break;

                case self::ZABORT:
                    $done = true;
                    break;

                default:
                    self::sendHexHeader($conn, self::ZNAK, [0, 0, 0, 0], $escapeTelnetIac);
                    break;
            }
        }

        fclose($fh);

        if (!$success) {
            @unlink($destPath);
            self::dbg("RECV fail transfer not successful");
            return null;
        }

        // Finalize promptly after successful receive to avoid hanging in closeout.
        if (!$sawZfin) {
            self::sendHexHeader($conn, self::ZFIN, [0, 0, 0, 0], $escapeTelnetIac);
            self::writeRaw($conn, "OO", $escapeTelnetIac);
            self::dbg("RECV finalize sent ZFIN+OO");
        }

        // Drain "OO" (over-and-out) sent by the client after ZFIN, plus any
        // telnet negotiation that may have buffered during the transfer.
        // Without this the BBS session reads those bytes as user input and
        // echoes "OO" to the prompt.
        @stream_set_timeout($conn, 1);
        @fread($conn, 512);
        @fread($conn, 512);
        @stream_set_timeout($conn, 300);

        self::dbg("RECV success path={$destPath}");
        self::info("RECV via PHP: " . basename($destPath) . " — ok");
        return $destPath;
    }

    /**
     * True when download transfers are available for this runtime.
     * The internal PHP implementation is always available as a fallback, so this
     * returns true whenever file transfers are enabled.
     */
    public static function canDownload(): bool
    {
        return self::isTransfersEnabled();
    }

    /**
     * True when upload transfers are available for this runtime.
     * The internal PHP implementation is always available as a fallback, so this
     * returns true whenever file transfers are enabled.
     */
    public static function canUpload(): bool
    {
        return self::isTransfersEnabled();
    }

    private static function isTransfersEnabled(): bool
    {
        $val = (string)\BinktermPHP\Config::env('TERMINAL_FILE_TRANSFERS', 'false');
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Return true when the internal PHP Z-modem implementation should be used
     * exclusively, bypassing any installed sz/rz binaries.
     * Set TELNET_ZMODEM_FORCE_PHP=true in .env to force this behaviour.
     */
    private static function forcePHPImplementation(): bool
    {
        $forcePhp = (string)\BinktermPHP\Config::env('TELNET_ZMODEM_FORCE_PHP', 'false');
        return in_array(strtolower($forcePhp), ['1', 'true', 'yes', 'on'], true);
    }

    private static function sendWithSz($conn, string $path, bool $escapeTelnetIac): bool
    {
        $sz = self::getSzPath();
        if ($sz === null || !is_readable($path)) {
            self::dbg("SEND external unavailable (sz/path)");
            return false;
        }
        $cmd = escapeshellarg($sz) . ' --zmodem --binary --quiet -- ' . escapeshellarg($path);
        $ok = self::runExternalTransfer($conn, $cmd, dirname($path), $escapeTelnetIac, 300);
        self::dbg("SEND external result=" . ($ok ? 'ok' : 'fail'));
        self::info("SEND via sz: " . basename($path) . " — " . ($ok ? 'ok' : 'FAILED'));
        return $ok;
    }

    private static function receiveWithRz($conn, string $destDir, bool $escapeTelnetIac): ?string
    {
        $rz = self::getRzPath();
        if ($rz === null) {
            self::dbg("RECV external unavailable (rz missing)");
            return null;
        }
        if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) {
            self::dbg("RECV external unable to create dest dir");
            return null;
        }

        $before = self::snapshotFiles($destDir);
        $cmd = escapeshellarg($rz) . ' --zmodem --binary --overwrite --quiet';
        $ok = self::runExternalTransfer($conn, $cmd, $destDir, $escapeTelnetIac, 300);
        if (!$ok) {
            self::dbg("RECV external transfer failed");
            return null;
        }

        $after = self::snapshotFiles($destDir);
        $file = self::findNewestChangedFile($before, $after);
        self::dbg("RECV external result=" . ($file !== null ? $file : 'none'));
        self::info("RECV via rz: " . ($file !== null ? basename($file) . ' — ok' : 'no file received'));
        return $file;
    }

    /**
     * Bridge client socket <-> external sz/rz process stdio.
     *
     * When the system supports PTY descriptors (Linux/Unix with PHP PTY support),
     * fd 0 and fd 1 of the child process are connected to a PTY slave.  This
     * causes lrzsz to detect a real terminal via isatty(), put it into raw mode,
     * and stream data without internal stdio buffering — which is the main source
     * of download timeouts and the reason upload support was unreliable when using
     * plain pipes.  On systems without PTY support the old pipe path is used as a
     * fallback.
     */
    private static function runExternalTransfer($conn, string $cmd, string $cwd, bool $escapeTelnetIac, int $idleTimeout): bool
    {
        $usePty = self::hasPtySupport();

        if ($usePty) {
            // fd 0 and fd 1 share the same PTY slave; PHP returns the master handle
            // in $pipes[0] (and an equivalent handle in $pipes[1] — we only need one).
            $descriptors = [
                0 => ['pty'],
                1 => ['pty'],
                2 => ['pipe', 'w'],
            ];
        } else {
            // Use binary mode ('rb'/'wb') to prevent Windows text-mode pipe
            // translation of \n → \r\n which corrupts the binary Z-modem stream.
            $descriptors = [
                0 => ['pipe', 'rb'],
                1 => ['pipe', 'wb'],
                2 => ['pipe', 'w'],
            ];
        }

        $pipes = [];
        $proc  = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            self::dbg("external proc_open failed: {$cmd}");
            return false;
        }

        if ($usePty) {
            $pty    = $pipes[0] ?? null;  // PTY master: read=process-stdout, write=process-stdin
            $stdin  = null;
            $stdout = null;
            $stderr = $pipes[2] ?? null;
            if (!is_resource($pty)) {
                @proc_terminate($proc);
                @proc_close($proc);
                return false;
            }
            @stream_set_blocking($pty, false);
        } else {
            $pty    = null;
            $stdin  = $pipes[0] ?? null;
            $stdout = $pipes[1] ?? null;
            $stderr = $pipes[2] ?? null;
            if (!is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
                @proc_terminate($proc);
                @proc_close($proc);
                return false;
            }
            @stream_set_blocking($stdin,  false);
            @stream_set_blocking($stdout, false);
        }

        if (is_resource($stderr)) {
            @stream_set_blocking($stderr, false);
        }

        // On the PTY path (Linux) $conn is a plain Unix socket, so it is safe
        // to set it non-blocking and buffer writes through the select loop.
        // This prevents a deadlock where writeRaw() blocks on $conn while we
        // cannot drain the PTY master, causing sz to time out:
        //   sz writes burst → PTY master readable → writeRaw($conn) blocks
        //   (bridge stalled on SSH window) → PTY buffer full → sz stalls → timeout.
        //
        // On the pipe path (Windows) $conn may be an SshStreamWrapper stream.
        // Making that non-blocking causes rawWrite() to throw when the SSH send
        // buffer fills; the wrapper swallows the exception and silently drops
        // data, producing CRC errors.  Keep $conn blocking on the pipe path.
        if ($usePty) {
            @stream_set_blocking($conn, false);
        }

        $toProc       = '';
        $toConn       = '';   // buffered output to $conn (PTY path only)
        $lastActivity = time();
        $stderrLog    = '';
        $ptyEof       = false;

        while (true) {
            $status  = @proc_get_status($proc);
            $running = is_array($status) ? !empty($status['running']) : false;

            $read = [];
            if ($running && is_resource($conn)) { $read[] = $conn; }
            if ($usePty  && is_resource($pty)    && !$ptyEof) { $read[] = $pty; }
            if (!$usePty && is_resource($stdout)) { $read[] = $stdout; }
            if (is_resource($stderr)) { $read[] = $stderr; }

            $write = [];
            if ($toProc !== '') {
                if ($usePty  && is_resource($pty))   { $write[] = $pty; }
                if (!$usePty && is_resource($stdin))  { $write[] = $stdin; }
            }
            // PTY path: gate writes to $conn through select to avoid blocking.
            if ($usePty && $toConn !== '' && is_resource($conn)) {
                $write[] = $conn;
            }

            if (empty($read) && empty($write)) {
                break;
            }

            $except = null;
            $ready  = @stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                break;
            }

            foreach ($read as $r) {
                if ($r === $conn) {
                    $chunk = @fread($conn, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $toProc .= $chunk;
                        $lastActivity = time();
                    }
                } elseif ($r === $pty || $r === $stdout) {
                    $chunk = @fread($r, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        if ($usePty) {
                            // Buffer for non-blocking write; apply telnet escaping here.
                            $toConn .= $escapeTelnetIac ? str_replace("\xFF", "\xFF\xFF", $chunk) : $chunk;
                        } else {
                            self::writeRaw($conn, $chunk, $escapeTelnetIac);
                        }
                        $lastActivity = time();
                    } elseif (@feof($r)) {
                        if ($usePty) {
                            $ptyEof = true; // process closed its slave end; drain remaining data below
                        } else {
                            @fclose($stdout);
                            $stdout = null;
                        }
                    }
                } elseif ($r === $stderr) {
                    $chunk = @fread($stderr, 4096);
                    if ($chunk !== false && $chunk !== '') {
                        $stderrLog .= $chunk;
                        if (strlen($stderrLog) > 4096) {
                            $stderrLog = substr($stderrLog, -4096);
                        }
                        $lastActivity = time();
                    } elseif (@feof($stderr)) {
                        @fclose($stderr);
                        $stderr = null;
                    }
                }
            }

            foreach ($write as $w) {
                if ($w === $conn) {
                    // Non-blocking write to $conn on PTY path.
                    $n = @fwrite($conn, $toConn);
                    if ($n === false) { $n = 0; }
                    if ($n > 0) {
                        $toConn = (string)substr($toConn, $n);
                        $lastActivity = time();
                    }
                } elseif ($toProc !== '') {
                    $n = @fwrite($w, $toProc);
                    if ($n === false) { $n = 0; }
                    if ($n > 0) {
                        $toProc = (string)substr($toProc, $n);
                        $lastActivity = time();
                    }
                }
            }

            if ((time() - $lastActivity) > $idleTimeout) {
                self::dbg("external transfer idle timeout");
                @proc_terminate($proc);
                break;
            }

            if (!$running && $toProc === '' && $toConn === '') {
                break;
            }
        }

        // Restore $conn to blocking mode.  The PTY path set it non-blocking to
        // prevent writeRaw() deadlocks; callers (BbsSession) expect blocking I/O.
        if ($usePty) {
            @stream_set_blocking($conn, true);
        }

        if ($usePty  && is_resource($pty))    { @fclose($pty); }
        if (!$usePty && is_resource($stdin))  { @fclose($stdin); }
        if (!$usePty && is_resource($stdout)) { @fclose($stdout); }
        if (is_resource($stderr)) { @fclose($stderr); }

        $exit = @proc_close($proc);
        if ($exit !== 0) {
            $trimmed = trim($stderrLog);
            if ($trimmed !== '') {
                self::dbg("external exit={$exit} stderr=" . $trimmed);
            } else {
                self::dbg("external exit={$exit}");
            }
        }
        return $exit === 0;
    }

    /**
     * Return true if proc_open supports PTY descriptors on this system.
     *
     * PTY support requires a Unix-like OS and PHP compiled with PTY support
     * (the default on Linux).  The result is cached after the first call.
     */
    private static function hasPtySupport(): bool
    {
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return $supported = false;
        }
        // Probe with a no-op process to confirm PHP was built with PTY support.
        $desc  = [0 => ['pty'], 1 => ['pty'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc  = @proc_open('true', $desc, $pipes, '/tmp');
        if (is_resource($proc)) {
            foreach ($pipes as $p) {
                if (is_resource($p)) { @fclose($p); }
            }
            @proc_close($proc);
            $supported = true;
        } else {
            $supported = false;
        }
        self::dbg('PTY support: ' . ($supported ? 'yes' : 'no'));
        return $supported;
    }

    private static function getSzPath(): ?string
    {
        if (self::$szPath !== null) {
            return self::$szPath;
        }
        $override = trim((string)\BinktermPHP\Config::env('TELNET_SZ_BIN', ''));
        self::$szPath = self::resolveBinaryPath($override !== '' ? $override : 'sz');
        return self::$szPath;
    }

    private static function getRzPath(): ?string
    {
        if (self::$rzPath !== null) {
            return self::$rzPath;
        }
        $override = trim((string)\BinktermPHP\Config::env('TELNET_RZ_BIN', ''));
        self::$rzPath = self::resolveBinaryPath($override !== '' ? $override : 'rz');
        return self::$rzPath;
    }

    private static function resolveBinaryPath(string $nameOrPath): ?string
    {
        $candidate = trim($nameOrPath);
        if ($candidate === '') {
            return null;
        }
        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            return (is_file($candidate) && is_executable($candidate)) ? $candidate : null;
        }
        // On Windows also search with .exe suffix if not already present.
        $candidates = [$candidate];
        if (PHP_OS_FAMILY === 'Windows' && !str_ends_with(strtolower($candidate), '.exe')) {
            $candidates[] = $candidate . '.exe';
        }

        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }
            foreach ($candidates as $name) {
                $full = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
                if (is_file($full) && is_executable($full)) {
                    return $full;
                }
            }
        }
        return null;
    }

    /**
     * Snapshot files in a directory for upload result detection.
     *
     * @return array<string, array{mtime:int,size:int}>
     */
    private static function snapshotFiles(string $dir): array
    {
        $out = [];
        $files = @scandir($dir);
        if (!is_array($files)) {
            return $out;
        }
        foreach ($files as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }
            $mtime = @filemtime($path);
            $size = @filesize($path);
            $out[$path] = [
                'mtime' => is_int($mtime) ? $mtime : 0,
                'size' => is_int($size) ? $size : 0,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array{mtime:int,size:int}> $before
     * @param array<string, array{mtime:int,size:int}> $after
     */
    private static function findNewestChangedFile(array $before, array $after): ?string
    {
        $candidates = [];
        foreach ($after as $path => $meta) {
            if (!isset($before[$path])) {
                $candidates[$path] = $meta['mtime'];
                continue;
            }
            if ($before[$path]['mtime'] !== $meta['mtime'] || $before[$path]['size'] !== $meta['size']) {
                $candidates[$path] = $meta['mtime'];
            }
        }
        if (empty($candidates)) {
            return null;
        }
        arsort($candidates, SORT_NUMERIC);
        $path = (string)array_key_first($candidates);
        return $path !== '' ? $path : null;
    }

    // ===========================================================
    // HEADER SENDING
    // ===========================================================

    /**
     * Send a ZHEX header.
     * Format: ZPAD ZPAD ZDLE 'B' <10 hex chars type+data> <4 hex chars CRC-16> CR LF XON
     */
    private static function sendHexHeader($conn, int $type, array $data4, bool $escapeTelnetIac): void
    {
        $headerBytes = [$type, $data4[0], $data4[1], $data4[2], $data4[3]];
        $hexStr = '';
        foreach ($headerBytes as $b) {
            $hexStr .= sprintf('%02x', $b);
        }
        $crc    = self::crc16(pack('C*', ...$headerBytes));
        $packet = chr(self::ZPAD) . chr(self::ZPAD) . chr(self::ZDLE) . chr(self::ZHEX)
                . $hexStr . sprintf('%04x', $crc)
                . "\r\n" . chr(0x11); // CR LF XON
        self::writeRaw($conn, $packet, $escapeTelnetIac);
    }

    /**
     * Send a ZBIN header (CRC-16).
     * Format: ZPAD ZDLE 'A' <5 ZDLE-escaped bytes> <2 ZDLE-escaped CRC bytes>
     */
    private static function sendBinHeader($conn, int $type, array $data4, bool $escapeTelnetIac): void
    {
        $headerBytes = [$type, $data4[0], $data4[1], $data4[2], $data4[3]];
        $raw  = chr(self::ZPAD) . chr(self::ZDLE) . chr(self::ZBIN);
        foreach ($headerBytes as $b) {
            $raw .= self::zdleEscapeStr($b);
        }
        $crc  = self::crc16(pack('C*', ...$headerBytes));
        $raw .= self::zdleEscapeStr(($crc >> 8) & 0xFF);
        $raw .= self::zdleEscapeStr($crc & 0xFF);
        self::writeRaw($conn, $raw, $escapeTelnetIac);
    }

    // ===========================================================
    // DATA SUBPACKET SENDING
    // ===========================================================

    /**
     * Send a data subpacket.
     * Format: ZDLE-escaped data, then ZDLE + frameEnd, then ZDLE-escaped CRC-16 of (data + frameEnd).
     */
    private static function sendDataSubpacket($conn, string $data, int $frameEnd, bool $escapeTelnetIac): void
    {
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $out .= self::zdleEscapeStr(ord($data[$i]));
        }
        $out .= chr(self::ZDLE) . chr($frameEnd);
        $crc  = self::crc16($data . chr($frameEnd));
        $out .= self::zdleEscapeStr(($crc >> 8) & 0xFF);
        $out .= self::zdleEscapeStr($crc & 0xFF);
        self::writeRaw($conn, $out, $escapeTelnetIac);
    }

    // ===========================================================
    // DATA SUBPACKET / STREAM RECEIVING
    // ===========================================================

    /**
     * Read one data subpacket (used for ZFILE info).
     * Returns raw unescaped data, or null on error.
     */
    private static function receiveDataSubpacket($conn): ?string
    {
        @stream_set_timeout($conn, self::TIMEOUT);
        $data = '';
        while (true) {
            $b = self::readByte($conn);
            if ($b === null) {
                return null;
            }
            if ($b === self::ZDLE) {
                $b2 = self::readByte($conn);
                if ($b2 === null) {
                    return null;
                }
                if (self::isFrameEnd($b2)) {
                    // Read and discard CRC (2 ZDLE-escaped bytes)
                    self::readEscapedByte($conn);
                    self::readEscapedByte($conn);
                    return $data;
                }
                $data .= chr(self::decodeZdleEscaped($b2));
            } else {
                $data .= chr($b);
            }
        }
    }

    /**
     * Read a stream of data subpackets from a ZDATA frame into $fh.
     * Returns true when ZCRCE (end of frame) is seen, false on error.
     */
    private static function receiveFileData($conn, $fh, bool $escapeTelnetIac): bool
    {
        @stream_set_timeout($conn, self::TIMEOUT);
        while (true) {
            $chunk = '';
            while (true) {
                $b = self::readByte($conn);
                if ($b === null) {
                    return false;
                }
                if ($b === self::ZDLE) {
                    $b2 = self::readByte($conn);
                    if ($b2 === null) {
                        return false;
                    }
                    if (self::isFrameEnd($b2)) {
                        // Discard 2-byte CRC
                        self::readEscapedByte($conn);
                        self::readEscapedByte($conn);
                        fwrite($fh, $chunk);
                        $pos = ftell($fh);
                        if ($pos === false) {
                            $pos = 0;
                        }
                        if ($b2 === self::ZCRCQ || $b2 === self::ZCRCW) {
                            self::sendHexHeader($conn, self::ZACK, self::int32ToBytes((int)$pos), $escapeTelnetIac);
                        }
                        if ($b2 === self::ZCRCE || $b2 === self::ZCRCW) {
                            return true; // End of this ZDATA frame
                        }
                        // ZCRCG / ZCRCQ: more subpackets in this frame
                        break;
                    }
                    $chunk .= chr(self::decodeZdleEscaped($b2));
                } else {
                    $chunk .= chr($b);
                }
            }
        }
    }

    // ===========================================================
    // HEADER RECEIVING
    // ===========================================================

    /**
     * Block until a ZMODEM header is received, then parse and return it.
     * Returns ['type' => int, 'pos' => int] or null on timeout/error.
     *
     * @param int $timeout Seconds to wait (defaults to TIMEOUT).
     */
    private static function receiveHeader($conn, int $timeout = self::TIMEOUT): ?array
    {
        @stream_set_timeout($conn, $timeout);
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            $b = self::readByte($conn);
            if ($b === null) {
                return null;
            }
            if ($b !== self::ZPAD) {
                continue;
            }

            // May see ZPAD ZPAD ZDLE or ZPAD ZDLE
            $b = self::readByte($conn);
            if ($b === null) {
                return null;
            }
            if ($b === self::ZPAD) {
                $b = self::readByte($conn);
                if ($b === null) {
                    return null;
                }
            }
            if ($b !== self::ZDLE) {
                continue;
            }

            $format = self::readByte($conn);
            if ($format === null) {
                return null;
            }

            if ($format === self::ZHEX) {
                return self::parseHexHeader($conn);
            }
            if ($format === self::ZBIN) {
                return self::parseBinHeader($conn, false);
            }
            if ($format === self::ZBIN32) {
                return self::parseBinHeader($conn, true);
            }
        }

        return null;
    }

    /**
     * Parse a ZHEX header (ZPAD ZPAD ZDLE 'B' already consumed).
     */
    private static function parseHexHeader($conn): ?array
    {
        // 14 hex characters: 10 for type+data, 4 for CRC
        $hexStr = '';
        for ($i = 0; $i < 14; $i++) {
            $b = self::readByte($conn);
            if ($b === null) {
                return null;
            }
            $hexStr .= chr($b);
        }

        // Drain trailing CR / LF / XON
        @stream_set_timeout($conn, 1);
        for ($i = 0; $i < 3; $i++) {
            $b = self::readByte($conn);
            if ($b === null) {
                break;
            }
            if (!in_array($b, [0x0D, 0x0A, 0x11], true)) {
                self::unreadByte($b);
                break;
            }
        }
        @stream_set_timeout($conn, self::TIMEOUT);

        if (strlen($hexStr) < 14) {
            return null;
        }

        $type = hexdec(substr($hexStr, 0, 2));
        $d0   = hexdec(substr($hexStr, 2, 2));
        $d1   = hexdec(substr($hexStr, 4, 2));
        $d2   = hexdec(substr($hexStr, 6, 2));
        $d3   = hexdec(substr($hexStr, 8, 2));
        $pos  = ($d3 << 24) | ($d2 << 16) | ($d1 << 8) | $d0;

        return ['type' => (int)$type, 'pos' => $pos];
    }

    /**
     * Parse a ZBIN or ZBIN32 header (ZPAD ZDLE 'A'/'C' already consumed).
     *
     * @param bool $is32 true for ZBIN32 (4-byte CRC), false for ZBIN (2-byte CRC)
     */
    private static function parseBinHeader($conn, bool $is32): ?array
    {
        $bytes = [];
        for ($i = 0; $i < 5; $i++) {
            $b = self::readEscapedByte($conn);
            if ($b === null) {
                return null;
            }
            $bytes[] = $b;
        }
        // Discard CRC bytes
        $crcBytes = $is32 ? 4 : 2;
        for ($i = 0; $i < $crcBytes; $i++) {
            self::readEscapedByte($conn);
        }

        $type = $bytes[0];
        $pos  = ($bytes[4] << 24) | ($bytes[3] << 16) | ($bytes[2] << 8) | $bytes[1];

        return ['type' => $type, 'pos' => $pos];
    }

    // ===========================================================
    // LOW-LEVEL I/O HELPERS
    // ===========================================================

    /**
     * Read one raw byte from the connection.  Returns null on EOF/timeout.
     * Tracks consecutive CAN (0x18) bytes; returns null when CAN_ABORT_COUNT
     * are seen in a row (Ctrl+X abort from remote).
     */
    private static function readByte($conn): ?int
    {
        // Refill the buffer in bulk when empty (much faster than fgetc() per byte,
        // especially over TLS where each syscall has high overhead).
        if (self::$rxBufferPos >= strlen(self::$rxBuffer)) {
            self::$rxBuffer    = '';
            self::$rxBufferPos = 0;

            $chunk = @fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                return null;
            }

            // Un-escape Telnet IAC sequences (0xFF 0xFF → 0xFF) on the inbound
            // stream when operating over plain (non-binary) telnet.  Without this
            // any 0xFF byte in the uploaded file would be doubled by the client,
            // corrupting the ZMODEM data stream.
            if (self::$unescapeIac) {
                $chunk = str_replace("\xFF\xFF", "\xFF", $chunk);
            }

            self::$rxBuffer = $chunk;
        }

        $val = ord(self::$rxBuffer[self::$rxBufferPos++]);
        if (self::$rxBufferPos >= strlen(self::$rxBuffer)) {
            self::$rxBuffer    = '';
            self::$rxBufferPos = 0;
        }

        if ($val === 0x18) {
            self::$canCount++;
            if (self::$canCount >= self::CAN_ABORT_COUNT) {
                return null;
            }
        } else {
            self::$canCount = 0;
        }
        return $val;
    }

    /**
     * Non-blocking poll used while streaming file data.
     * Buffers incoming bytes so receiveHeader() can consume them later.
     * Returns true if Ctrl-X/CAN abort sequence is detected.
     */
    private static function pollIncomingDuringSend($conn): bool
    {
        $read = [$conn];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, 0);
        if ($ready === false || $ready === 0) {
            return false;
        }

        while (true) {
            $chunk = @fread($conn, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            if (self::$rxBufferPos >= strlen(self::$rxBuffer)) {
                self::$rxBuffer = $chunk;
                self::$rxBufferPos = 0;
            } else {
                // Preserve unread bytes and append polled bytes.
                self::$rxBuffer = substr(self::$rxBuffer, self::$rxBufferPos) . $chunk;
                self::$rxBufferPos = 0;
            }
            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $c = $chunk[$i];
                if (ord($c) === 0x18) {
                    self::$canCount++;
                    if (self::$canCount >= self::CAN_ABORT_COUNT) {
                        return true;
                    }
                } else {
                    self::$canCount = 0;
                }
            }

            $read = [$conn];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 0);
            if ($ready === false || $ready === 0) {
                break;
            }
        }

        return false;
    }

    /**
     * Emit CAN abort burst to terminate transfer promptly.
     */
    private static function sendAbort($conn, bool $escapeTelnetIac): void
    {
        self::writeRaw($conn, str_repeat(chr(0x18), 8), $escapeTelnetIac);
    }

    /**
     * Push one raw byte back into the buffered reader.
     */
    private static function unreadByte(int $b): void
    {
        $b &= 0xFF;
        if (self::$rxBufferPos >= strlen(self::$rxBuffer)) {
            self::$rxBuffer = chr($b);
            self::$rxBufferPos = 0;
            return;
        }
        self::$rxBuffer = chr($b) . substr(self::$rxBuffer, self::$rxBufferPos);
        self::$rxBufferPos = 0;
    }

    /**
     * Read one ZDLE-escaped byte. If the next byte is ZDLE, the byte after is
     * XOR'd with 0x40 to recover the original.  Returns null on error.
     */
    private static function readEscapedByte($conn): ?int
    {
        $b = self::readByte($conn);
        if ($b === null) {
            return null;
        }
        if ($b === self::ZDLE) {
            $b2 = self::readByte($conn);
            if ($b2 === null) {
                return null;
            }
            return self::decodeZdleEscaped($b2);
        }
        return $b;
    }

    /**
     * Decode a ZDLE-escaped data byte.
     */
    private static function decodeZdleEscaped(int $b): int
    {
        if ($b === self::ZRUB0) {
            return 0x7F;
        }
        if ($b === self::ZRUB1) {
            return 0xFF;
        }
        return $b ^ 0x40;
    }

    /**
     * Write data to the connection, optionally doubling 0xFF for telnet links.
     */
    private static function writeRaw($conn, string $data, bool $escapeTelnetIac): void
    {
        if ($escapeTelnetIac) {
            $data = str_replace("\xFF", "\xFF\xFF", $data);
        }
        $total = strlen($data);
        $sent  = 0;
        while ($sent < $total) {
            $n = @fwrite($conn, substr($data, $sent));
            if ($n === false || $n === 0) {
                break;
            }
            $sent += $n;
        }
        @fflush($conn);
    }

    /**
     * ZDLE-escape one byte using classic ZMODEM rules.
     *
     * Escapes: ZDLE, DLE, XON, XOFF, and high-bit variants.
     * Escapes CR only when previous transmitted byte was '@' (0x40) to avoid
     * legacy Telenet escapes.
     */
    private static function zdleEscapeStr(int $b): string
    {
        $b &= 0xFF;

        $mustEscape = false;
        switch ($b) {
            case self::ZDLE: // Ctrl-X
            case 0x10:       // DLE
            case 0x11:       // XON
            case 0x13:       // XOFF
            case 0x90:       // DLE | 0x80
            case 0x91:       // XON | 0x80
            case 0x93:       // XOFF | 0x80
                $mustEscape = true;
                break;
            case 0x0D:       // CR
            case 0x8D:       // CR | 0x80
                $mustEscape = ((self::$lastZdleSent & 0x7F) === 0x40);
                break;
        }

        if ($mustEscape) {
            $encoded = $b ^ 0x40;
            self::$lastZdleSent = $encoded & 0xFF;
            return chr(self::ZDLE) . chr($encoded);
        }

        self::$lastZdleSent = $b;
        return chr($b);
    }

    /**
     * Return true if $b is one of the four frame-end bytes that may follow
     * a ZDLE in a data subpacket.
     */
    private static function isFrameEnd(int $b): bool
    {
        return $b === self::ZCRCE || $b === self::ZCRCG
            || $b === self::ZCRCQ || $b === self::ZCRCW;
    }

    /**
     * Convert a 32-bit integer to a 4-byte little-endian array.
     */
    private static function int32ToBytes(int $val): array
    {
        return [
            $val & 0xFF,
            ($val >> 8) & 0xFF,
            ($val >> 16) & 0xFF,
            ($val >> 24) & 0xFF,
        ];
    }

    /**
     * Build ZRINIT data bytes.
     * Byte layout for compatibility:
     *  - ZP0/ZP1: receive buffer length (little-endian), advertise 8192 bytes
     *  - ZP2: reserved
     *  - ZP3: capability flags
     */
    private static function buildZrinitData(): array
    {
        $rxBuf = 8192; // 0x2000
        return [
            $rxBuf & 0xFF,
            ($rxBuf >> 8) & 0xFF,
            0x00,
            self::CANFDX | self::CANOVIO,
        ];
    }

    /**
     * Compute CRC-16/CCITT (polynomial 0x1021, init 0x0000).
     */
    private static function crc16(string $data): int
    {
        $crc = 0x0000;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000)
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }
        return $crc;
    }

    private static function isDebugEnabled(): bool
    {
        if (self::$debugEnabled !== null) {
            return self::$debugEnabled;
        }
        $val = (string)\BinktermPHP\Config::env('TELNET_ZMODEM_DEBUG', 'false');
        self::$debugEnabled = in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
        return self::$debugEnabled;
    }

    private static function dbg(string $message): void
    {
        if (!self::isDebugEnabled()) {
            return;
        }
        $file = dirname(__DIR__, 2) . '/data/logs/zmodem.log';
        @error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, 3, $file);
    }

    /**
     * Always-on informational log: writes to stderr (visible on the daemon
     * console in foreground mode) and appends to zmodem.log.
     */
    private static function info(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ZMODEM ' . $message . PHP_EOL;
        // Only write to STDERR when it is still open (foreground mode).
        // In daemon mode STDERR is closed and the resource is invalid.
        if (is_resource(STDERR)) { @fwrite(STDERR, $line); }
        $file = dirname(__DIR__, 2) . '/data/logs/zmodem.log';
        @error_log($line, 3, $file);
    }

    private static function headerToString(?array $header): string
    {
        if ($header === null) {
            return 'null';
        }
        $type = (int)($header['type'] ?? -1);
        $pos  = (int)($header['pos'] ?? 0);
        return self::frameName($type) . " pos={$pos}";
    }

    private static function frameName(int $type): string
    {
        return match ($type) {
            self::ZRQINIT => 'ZRQINIT',
            self::ZRINIT  => 'ZRINIT',
            self::ZACK    => 'ZACK',
            self::ZFILE   => 'ZFILE',
            self::ZSKIP   => 'ZSKIP',
            self::ZNAK    => 'ZNAK',
            self::ZABORT  => 'ZABORT',
            self::ZFIN    => 'ZFIN',
            self::ZRPOS   => 'ZRPOS',
            self::ZDATA   => 'ZDATA',
            self::ZEOF    => 'ZEOF',
            self::ZCRC    => 'ZCRC',
            default       => 'TYPE_' . $type,
        };
    }

    /**
     * Compute unsigned CRC-32 of a file for ZCRC negotiation.
     */
    private static function fileCrc32(string $path): int
    {
        $crc = @hash_file('crc32b', $path);
        if ($crc === false) {
            return 0;
        }
        $value = hexdec($crc);
        if (!is_int($value)) {
            $value = (int)$value;
        }
        return $value & 0xFFFFFFFF;
    }
}
