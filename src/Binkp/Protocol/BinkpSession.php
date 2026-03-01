<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


namespace BinktermPHP\Binkp\Protocol;

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Version;

class BinkpSession
{
    const STATE_INIT = 0;
    const STATE_ADDR_SENT = 1;
    const STATE_ADDR_RECEIVED = 2;
    const STATE_PWD_SENT = 3;
    const STATE_AUTHENTICATED = 4;
    const STATE_FILE_TRANSFER = 5;
    const STATE_EOB_SENT = 6;
    const STATE_EOB_RECEIVED = 7;
    const STATE_TERMINATED = 8;
    
    private $socket;
    private $state;
    private $isOriginator;
    private $localAddress;
    private $remoteAddress;
    private $remoteAddressWithDomain;
    private $password;
    private $config;
    private $logger;
    private $currentFile;
    private $fileHandle;
    private $filesReceived;
    private $filesSent;
    private $uplinkPassword;
    private $currentUplink;

    // Insecure session support
    private $isInsecureSession = false;
    private $insecureReceiveOnly = true;
    private $sessionType = 'secure';  // 'secure', 'insecure', 'crash_outbound'
    private $sessionLogger = null;

    // CRAM-MD5 authentication
    private $cramChallenge = null;        // Challenge sent/received
    private $remoteCramSupported = false; // Remote supports CRAM?
    private $useCramAuth = false;         // Using CRAM for this session?
    private $authMethod = 'plaintext';    // Authentication method used

    public function __construct($socket, $isOriginator = false, $config = null)
    {
        $this->socket = $socket;
        $this->isOriginator = $isOriginator;
        $this->state = self::STATE_INIT;
        $this->config = $config ?: BinkpConfig::getInstance();
        $this->filesReceived = [];
        $this->filesSent = [];
        $this->currentFile = null;
        $this->fileHandle = null;
        $this->currentUplink = null;
    }

    /**
     * Set the current uplink context for this session.
     * This is used to filter outbound packets to only send those
     * destined for networks handled by this uplink.
     *
     * @param array $uplink The uplink configuration array
     */
    public function setCurrentUplink(array $uplink)
    {
        $this->currentUplink = $uplink;
        $this->log("Current uplink set: " . ($uplink['address'] ?? 'unknown') . " (domain: " . ($uplink['domain'] ?? 'unknown') . ")", 'DEBUG');
    }

    /**
     * Get the current uplink context.
     *
     * @return array|null The current uplink configuration
     */
    public function getCurrentUplink(): ?array
    {
        return $this->currentUplink;
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    
    public function setUplinkPassword($password)
    {
        $this->uplinkPassword = $password;
        $this->log("setUplinkPassword: length=" . strlen($password), 'DEBUG');
    }
    
    public function log($message, $level = 'INFO')
    {
        if ($this->logger) {
            $this->logger->log($level, "[{$this->remoteAddress}] {$message}");
        }
    }
    
    public function handshake()
    {
        try {
            // Both sides send system info and address at start of session
            $this->sendSystemInfo();
            $this->sendAddress();
            $this->state = self::STATE_ADDR_SENT;

            while ($this->state < self::STATE_AUTHENTICATED) {
                $frame = BinkpFrame::parseFromSocket($this->socket);
                if (!$frame) {
                    // Get stream metadata for diagnostic info
                    $meta = stream_get_meta_data($this->socket);
                    $timedOut = $meta['timed_out'] ? 'yes' : 'no';
                    $eof = $meta['eof'] ? 'yes' : 'no';
                    $blocked = $meta['blocked'] ? 'yes' : 'no';
                    throw new \Exception("Failed to read frame during handshake (state={$this->state}, timed_out={$timedOut}, eof={$eof}, blocked={$blocked})");
                }

                $this->log("Received: {$frame}", 'DEBUG');
                $this->processHandshakeFrame($frame);
            }

            $this->log('Handshake completed successfully');
            return true;

        } catch (\Exception $e) {
            $this->log("Handshake failed: " . $e->getMessage(), 'ERROR');
            $this->sendError($e->getMessage());
            // Re-throw with the actual error message so caller can see it
            throw new \Exception('Handshake failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function sendSystemInfo()
    {
        $systemName = $this->config->getSystemName();
        $sysopName = $this->config->getSystemSysop();
        $location = $this->config->getSystemLocation();

        // Send M_NUL frames with system information
        $this->sendNul("SYS {$systemName}");
        $this->sendNul("ZYZ {$sysopName}");
        $this->sendNul("LOC {$location}");
        $this->sendNul("VER BinktermPHP/".Version::getVersion()." binkp/1.0");
        $this->sendNul("TIME " . gmdate('D, d M Y H:i:s') . " UTC");

        // As answerer, send CRAM-MD5 challenge if any uplink has crypt enabled
        if (!$this->isOriginator && $this->hasAnyCramEnabledUplink()) {
            $this->cramChallenge = $this->generateCramChallenge();
            $this->sendNul("OPT CRAM-MD5-{$this->cramChallenge}");
            $this->log("Sent CRAM-MD5 challenge", 'DEBUG');
        }
    }

    private function sendNul($data)
    {
        $frame = BinkpFrame::createCommand(BinkpFrame::M_NUL, $data);
        $frame->writeToSocket($this->socket);
        $this->log("Sent NUL: {$data}", 'DEBUG');
    }

    public function processSession()
    {
        try {
            $this->state = self::STATE_FILE_TRANSFER;
            $this->log("Entering file transfer phase", 'DEBUG');

            if ($this->isOriginator) {
                $this->log("As originator, checking for outbound files", 'DEBUG');
                $this->sendFiles();
                
                // Wait for M_GOT responses before sending EOB
                if (!empty($this->filesSent)) {
                    $this->log("Waiting for M_GOT responses for " . count($this->filesSent) . " sent files", 'DEBUG');
                    $pendingFiles = array_flip($this->filesSent); // Use filenames as keys for easier lookup
                    $timeout = time() + 120; // Increased timeout to 120 seconds
                    $lastActivity = time();

                    while (!empty($pendingFiles) && time() < $timeout) {
                        $frame = BinkpFrame::parseFromSocket($this->socket);
                        if (!$frame) {
                            // Check for extended timeout without activity
                            if (time() - $lastActivity > 30) {
                                $this->log("No activity for 30 seconds while waiting for M_GOT", 'WARNING');
                                break;
                            }
                            usleep(100000); // 100ms delay to prevent busy waiting
                            continue;
                        }

                        $lastActivity = time();
                        $this->log("Received while waiting for M_GOT: {$frame}", 'DEBUG');
                        $this->processTransferFrame($frame);

                        // Remove confirmed files from pending list
                        if ($frame->isCommand() && $frame->getCommand() === BinkpFrame::M_GOT) {
                            $gotData = $frame->getData();
                            $this->log("Processing M_GOT: '{$gotData}'", 'DEBUG');

                            // Extract filename from M_GOT data (format: "filename size timestamp")
                            $parts = explode(' ', $gotData);
                            $confirmedFile = $parts[0];

                            // Try exact match first
                            if (isset($pendingFiles[$confirmedFile])) {
                                unset($pendingFiles[$confirmedFile]);
                                $this->log("File confirmed: {$confirmedFile}", 'DEBUG');
                            } else {
                                // Try basename match for path issues
                                $baseName = basename($confirmedFile);
                                $foundMatch = false;
                                foreach (array_keys($pendingFiles) as $pendingFile) {
                                    if (basename($pendingFile) === $baseName) {
                                        unset($pendingFiles[$pendingFile]);
                                        $this->log("File confirmed (basename match): {$confirmedFile}", 'DEBUG');
                                        $foundMatch = true;
                                        break;
                                    }
                                }
                                if (!$foundMatch) {
                                    $this->log("M_GOT for unknown file: {$confirmedFile}", 'WARNING');
                                }
                            }
                        }
                    }

                    if (!empty($pendingFiles)) {
                        $this->log(count($pendingFiles) . " files not confirmed after timeout", 'WARNING');
                    } else {
                        $this->log("All sent files confirmed by remote", 'DEBUG');
                    }
                }
            } else {
                $this->log("As answerer, waiting for files", 'DEBUG');
            }

            // As originator with no files sent, proceed directly to waiting for remote files
            // As answerer, or as originator after sending files, process incoming frames
            $shouldWaitForFrames = !$this->isOriginator || !empty($this->filesSent);

            if ($shouldWaitForFrames) {
                $this->log("Processing incoming frames before EOB exchange", 'DEBUG');
                // Continue processing frames until EOB exchange, but with a timeout
                $frameWaitStart = time();
                $frameWaitTimeout = 5; // 5 second timeout for this phase

                while ($this->state < self::STATE_EOB_SENT && (time() - $frameWaitStart) < $frameWaitTimeout) {
                    $frame = BinkpFrame::parseFromSocket($this->socket, true); // Use non-blocking mode
                    if (!$frame) {
                        usleep(100000); // 100ms delay
                        continue;
                    }

                    $this->log("Received: {$frame}", 'DEBUG');
                    $this->processTransferFrame($frame);
                }
                $this->log("Frame processing phase complete", 'DEBUG');
            } else {
                $this->log("Originator with no files - proceeding to EOB", 'DEBUG');
            }

            // As originator, wait for remote to potentially send us files before sending EOB
            if (!$this->currentFile) {
                $hasSentFiles = !empty($this->filesSent);
                $maxWaitTime = $hasSentFiles ? 5 : 2;
                $waitStartTime = time();
            } else {
                $this->log("Already receiving file: " . $this->currentFile['name'], 'DEBUG');
                $waitStartTime = time();
                $maxWaitTime = 0;
            }

            while ($this->state === self::STATE_FILE_TRANSFER && time() - $waitStartTime < $maxWaitTime) {
                $frame = BinkpFrame::parseFromSocket($this->socket, true);
                if ($frame) {
                    $this->log("Received during wait: {$frame}", 'DEBUG');
                    $this->processTransferFrame($frame);
                    if ($this->currentFile || $this->state !== self::STATE_FILE_TRANSFER) {
                        break;
                    }
                } else {
                    usleep(100000);
                }
            }

            // Send EOB if we haven't already and no file is currently being received
            if ($this->state === self::STATE_FILE_TRANSFER && !$this->currentFile) {
                $this->log("Sending EOB", 'DEBUG');
                $this->sendEOB();
                $this->state = self::STATE_EOB_SENT;
            }

            // Continue until session terminates with timeout protection
            $eobWaitStart = time();
            $eobTimeout = 60; // 60 second timeout for EOB exchange
            $lastActivity = time();
            $activityTimeout = 5; // 30 seconds without any frames

            $this->log("Waiting for session termination (state: {$this->state})", 'DEBUG');

            while ($this->state < self::STATE_TERMINATED) {
                $elapsed = time() - $eobWaitStart;
                $inactivity = time() - $lastActivity;

                // Check for overall timeout
                if ($elapsed >= $eobTimeout) {
                    $this->log("EOB exchange timeout after {$elapsed} seconds (state: {$this->state})", 'WARNING');
                    break;
                }

                // Check for inactivity timeout
                if ($inactivity >= $activityTimeout) {
                    $this->log("No activity for {$inactivity} seconds during EOB exchange (state: {$this->state})", 'WARNING');
                    break;
                }

                // Check if we should send EOB (file completed, nothing left to send/receive)
                if ($this->state === self::STATE_FILE_TRANSFER && !$this->currentFile) {
                    $this->log("No active file transfer, sending EOB", 'DEBUG');
                    $this->sendEOB();
                    $this->state = self::STATE_EOB_SENT;
                }

                // Use non-blocking mode with short timeout to prevent indefinite blocking
                $frame = BinkpFrame::parseFromSocket($this->socket, true);
                if (!$frame) {
                    usleep(100000); // 100ms delay
                    continue;
                }

                $lastActivity = time();
                $this->log("Received: {$frame}", 'DEBUG');
                $this->processTransferFrame($frame);
            }

            $this->cleanup();
            if ($this->state === self::STATE_TERMINATED) {
                $this->log('Session completed successfully', 'INFO');
            } else {
                $this->log("Session ended (final state: {$this->state})", 'WARNING');
            }
            return true;
            
        } catch (\Exception $e) {
            $this->log("Session failed: " . $e->getMessage(), 'ERROR');
            $this->cleanup();
            return false;
        }
    }
    
    private function processHandshakeFrame(BinkpFrame $frame)
    {
        if (!$frame->isCommand()) {
            throw new \Exception('Expected command frame during handshake');
        }
        
        switch ($frame->getCommand()) {
            case BinkpFrame::M_ADR:
                $addressData = $frame->getData();
                $this->log("Remote address: {$addressData}", 'DEBUG');

                // Handle multiple addresses - remote may send "1:153/149 1:153/149.1 1:153/149.2"
                // Trim whitespace first to handle leading/trailing spaces
                $addresses = array_values(array_filter(explode(' ', trim($addressData)), 'strlen'));

                // Try to find a matching address in our uplinks
                $matchedAddress = null;
                $matchedAddressWithDomain = null;
                foreach ($addresses as $addr) {
                    $addr = trim($addr);
                    $addrWithDomain = $addr;
                    $domain = null;

                    if (strpos($addr, '@') !== false) {
                        list($addrOnly, $domain) = explode('@', $addr, 2);
                        $addr = $addrOnly;
                    }

                    // Strip .0 point suffix - it represents the boss node, not a real point
                    if (substr($addr, -2) === '.0') {
                        $addr = substr($addr, 0, -2);
                    }

                    if (!empty($addr) && $this->config->getUplinkByAddress($addr)) {
                        $matchedAddress = $addr;
                        $matchedAddressWithDomain = $addrWithDomain;
                        break;
                    }
                }

                // Use first address if no match found (fallback)
                $fallbackAddress = !empty($addresses) ? $addresses[0] : '';
                $fallbackAddressWithDomain = $fallbackAddress;
                if (strpos($fallbackAddress, '@') !== false) {
                    $fallbackAddress = substr($fallbackAddress, 0, strpos($fallbackAddress, '@'));
                }
                // Strip .0 point suffix from fallback address too
                if (substr($fallbackAddress, -2) === '.0') {
                    $fallbackAddress = substr($fallbackAddress, 0, -2);
                }
                $this->remoteAddress = $matchedAddress ?: $fallbackAddress;
                $this->remoteAddressWithDomain = $matchedAddressWithDomain ?: $fallbackAddressWithDomain;
                $this->log("Using remote address: {$this->remoteAddress}", 'DEBUG');

                if ($this->state === self::STATE_INIT) {
                    // Answerer hasn't sent ADR yet (rare path)
                    $this->sendAddress();
                    $this->state = self::STATE_ADDR_RECEIVED;
                } elseif ($this->state === self::STATE_ADDR_SENT) {
                    if ($this->isOriginator) {
                        // Originator sends M_PWD after receiving answerer's ADR
                        // (NUL frames with CRAM challenge have been processed by now)
                        $this->sendPassword();
                        $this->state = self::STATE_PWD_SENT;
                    } else {
                        // Answerer waits for originator's M_PWD
                        $this->state = self::STATE_ADDR_RECEIVED;
                    }
                }
                break;

            case BinkpFrame::M_PWD:
                $this->log("M_PWD received", 'DEBUG');
                if (!$this->validatePassword($frame->getData())) {
                    throw new \Exception('Authentication failed');
                }

                // Only answerer should send M_OK; originator waits for M_OK
                if (!$this->isOriginator) {
                    // Indicate session type in OK message
                    $okMessage = $this->isInsecureSession
                        ? 'insecure'
                        : 'secure';
                    $this->sendOK($okMessage);
                    $this->state = self::STATE_AUTHENTICATED;
                }
                // else: Stay in PWD_SENT state, wait for M_OK
                break;

            case BinkpFrame::M_OK:
                $okData = $frame->getData();
                $this->log("M_OK received: {$okData}", 'DEBUG');

                if ($this->state === self::STATE_PWD_SENT) {
                    // Parse session type from M_OK response
                    $okLower = strtolower(trim($okData));
                    $isSecureResponse = (strpos($okLower, 'non-secure') === false
                        && strpos($okLower, 'insecure') === false);

                    if ($isSecureResponse) {
                        $this->sessionType = 'secure';
                    } else {
                        $this->sessionType = 'non-secure';
                    }

                    // If we sent a password but server accepted as non-secure,
                    // the password was not recognized by the remote
                    if ($this->isOriginator && !empty($this->uplinkPassword)
                        && $this->sessionType === 'non-secure') {
                        throw new \Exception(
                            'Password rejected: sent password but remote accepted as non-secure. '
                            . 'Verify password configuration on both ends for node '
                            . $this->remoteAddress
                        );
                    }

                    $this->state = self::STATE_AUTHENTICATED;
                }
                break;

            case BinkpFrame::M_NUL:
                $nulData = $frame->getData();
                $this->log("M_NUL: " . $nulData, 'DEBUG');

                // Check for CRAM-MD5 challenge in OPT frame
                $challenge = $this->parseCramChallenge($nulData);
                if ($challenge !== null) {
                    $this->cramChallenge = $challenge;
                    $this->remoteCramSupported = true;
                    $this->log("Received CRAM-MD5 challenge from remote", 'DEBUG');

                    // As originator, use CRAM-MD5 only if uplink config enables it
                    // (allows plaintext fallback when crypt is disabled)
                    if ($this->isOriginator && $this->isCramEnabledForUplink()) {
                        $this->useCramAuth = true;
                        $this->log("Will use CRAM-MD5 authentication", 'DEBUG');
                    }
                }
                break;

            case BinkpFrame::M_ERR:
                throw new \Exception('Remote error: ' . $frame->getData());

            case BinkpFrame::M_BSY:
                throw new \Exception('Remote busy: ' . $frame->getData());

            default:
                $this->log("Unexpected command during handshake: " . $frame->getCommand(), 'WARNING');
        }
    }

    private function processTransferFrame(BinkpFrame $frame)
    {
        if ($frame->isCommand()) {
            $this->log("Transfer command: " . $frame->getCommand(), 'DEBUG');

            switch ($frame->getCommand()) {
                case BinkpFrame::M_NUL:
                    // M_NUL can be sent during transfer for informational purposes
                    $this->log("M_NUL during transfer: " . $frame->getData(), 'DEBUG');
                    break;

                case BinkpFrame::M_FILE:
                    $this->handleFileCommand($frame->getData());
                    break;

                case BinkpFrame::M_EOB:
                    $this->log("Received M_EOB (current state: {$this->state})", 'DEBUG');
                    if ($this->state === self::STATE_EOB_SENT) {
                        $this->log("Both sides sent EOB - terminating session", 'DEBUG');
                        $this->state = self::STATE_TERMINATED;
                    } elseif ($this->state === self::STATE_EOB_RECEIVED) {
                        $this->log("EOB already received - terminating session", 'DEBUG');
                        $this->state = self::STATE_TERMINATED;
                    } else {
                        $this->log("Received EOB first - sending our EOB", 'DEBUG');
                        $this->sendEOB();
                        // Only terminate if we have no files waiting for confirmation
                        if (empty($this->filesSent)) {
                            $this->state = self::STATE_TERMINATED;
                        } else {
                            $this->state = self::STATE_EOB_RECEIVED;
                        }
                    }
                    break;

                case BinkpFrame::M_GOT:
                    $this->log("Received M_GOT: " . $frame->getData(), 'DEBUG');
                    $this->handleGotCommand($frame->getData());
                    break;

                case BinkpFrame::M_GET:
                    $this->handleGetCommand($frame->getData());
                    break;

                case BinkpFrame::M_SKIP:
                    $this->handleSkipCommand($frame->getData());
                    break;

                case BinkpFrame::M_OK:
                    // M_OK can be sent during transfer by some implementations
                    $this->log("Received M_OK: " . $frame->getData(), 'DEBUG');
                    break;

                case BinkpFrame::M_ERR:
                    throw new \Exception('Remote error: ' . $frame->getData());

                default:
                    $this->log("Unexpected transfer command: " . $frame->getCommand(), 'WARNING');
            }
        } else {
            $this->log("Receiving data: " . strlen($frame->getData()) . " bytes", 'DEBUG');
            $this->handleFileData($frame->getData());
        }
    }

    private function sendAddress()
    {
        // If we have a current uplink context, only send that uplink's address
        // Otherwise fall back to sending all addresses
        if ($this->currentUplink && !empty($this->currentUplink['me'])) {
            $address = $this->currentUplink['me'];
            $sendDomain = !empty($this->currentUplink['send_domain_in_addr']);
            $domain = trim($this->currentUplink['domain'] ?? '');
            if ($sendDomain && $domain !== '' && strpos($address, '@') === false) {
                $address .= '@' . $domain;
            }
        } else {
            $address = trim(implode(" ", $this->config->getMyAddresses()));
        }
        $frame = BinkpFrame::createCommand(BinkpFrame::M_ADR, $address);
        $frame->writeToSocket($this->socket);
        $this->log("Sent address: {$address}", 'DEBUG');
    }

    private function sendPassword()
    {
        if ($this->isOriginator) {
            $password = $this->uplinkPassword ?? '';
        } else {
            $password = $this->getPasswordForRemote();
        }

        // Use CRAM-MD5 authentication if enabled and we have a challenge
        if ($this->useCramAuth && $this->cramChallenge !== null) {
            $digest = $this->computeCramDigest($this->cramChallenge, $password);
            $cramPassword = "CRAM-MD5-{$digest}";
            $this->authMethod = 'cram-md5';
            $this->log("Sending CRAM-MD5 digest", 'DEBUG');
            $frame = BinkpFrame::createCommand(BinkpFrame::M_PWD, $cramPassword);
        } else {
            // Plain text password
            $this->authMethod = 'plaintext';
            $this->log("Sent password (length=" . strlen($password) . ")", 'DEBUG');
            $frame = BinkpFrame::createCommand(BinkpFrame::M_PWD, $password);
        }

        $frame->writeToSocket($this->socket);
    }

    private function sendOK($message = '')
    {
        $frame = BinkpFrame::createCommand(BinkpFrame::M_OK, $message);
        $frame->writeToSocket($this->socket);
        $this->log("Sent OK", 'DEBUG');
    }

    private function sendError($message)
    {
        $frame = BinkpFrame::createCommand(BinkpFrame::M_ERR, $message);
        $frame->writeToSocket($this->socket);
        $this->log("Sent error: {$message}", 'DEBUG');
    }

    private function sendEOB()
    {
        $frame = BinkpFrame::createCommand(BinkpFrame::M_EOB, '');
        $frame->writeToSocket($this->socket);
        $this->log("Sent EOB", 'DEBUG');
    }

    private function sendFiles()
    {
        $outboundPath = $this->config->getOutboundPath();

        // Get both packet files and TIC files
        $pktFiles = glob($outboundPath . '/*.pkt');
        $ticFiles = glob($outboundPath . '/*.tic');

        // For each TIC file, we need to send both the TIC and its data file
        $ticPairs = [];
        foreach ($ticFiles as $ticFile) {
            $dataFile = $this->getDataFileForTic($ticFile);
            if ($dataFile && file_exists($dataFile)) {
                $ticPairs[] = ['tic' => $ticFile, 'data' => $dataFile];
            } else {
                $this->log("TIC file without data file: " . basename($ticFile), 'WARNING');
            }
        }

        $files = $pktFiles;

        $this->log("Found " . count($pktFiles) . " packet files and " . count($ticPairs) . " TIC pairs", 'DEBUG');

        if (empty($files) && empty($ticPairs)) {
            $this->log("No outbound files to send", 'DEBUG');
            return;
        }

        $filesToSend = [];
        $filesSkipped = 0;

        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                $this->log("Skipping unreadable file: " . basename($file), 'WARNING');
                continue;
            }

            // If we have a current uplink context, filter packets by destination
            if ($this->currentUplink !== null) {
                $destAddr = $this->getPacketDestination($file);
                if ($destAddr === null) {
                    $this->log("Could not determine destination for packet: " . basename($file) . ", skipping", 'WARNING');
                    $filesSkipped++;
                    continue;
                }

                // Check if this packet's destination should be routed through the current uplink
                if (!$this->config->isDestinationForUplink($destAddr, $this->currentUplink)) {
                    $this->log("Packet " . basename($file) . " destined for {$destAddr} not routed through this uplink (" .
                        ($this->currentUplink['domain'] ?? 'unknown') . "), skipping");
                    $filesSkipped++;
                    continue;
                }

                $this->log("Packet " . basename($file) . " destined for {$destAddr} matches uplink " .
                    ($this->currentUplink['domain'] ?? $this->currentUplink['address']));
            }

            $filesToSend[] = $file;
        }

        if ($filesSkipped > 0) {
            $this->log("Skipped {$filesSkipped} packets not for this uplink", 'DEBUG');
        }

        // Filter TIC pairs for current uplink if applicable
        $ticPairsToSend = [];
        $ticPairsSkipped = 0;
        if ($this->currentUplink !== null) {
            foreach ($ticPairs as $pair) {
                // Parse TIC file to get destination address
                $destAddr = $this->getTicDestination($pair['tic']);
                if ($destAddr === null) {
                    $this->log("Could not determine destination for TIC: " . basename($pair['tic']) . ", skipping", 'WARNING');
                    $ticPairsSkipped++;
                    continue;
                }

                // Check if this TIC's destination should be routed through the current uplink
                if (!$this->config->isDestinationForUplink($destAddr, $this->currentUplink)) {
                    $this->log("TIC " . basename($pair['tic']) . " destined for {$destAddr} not routed through this uplink (" .
                        ($this->currentUplink['domain'] ?? 'unknown') . "), skipping");
                    $ticPairsSkipped++;
                    continue;
                }

                $this->log("TIC " . basename($pair['tic']) . " destined for {$destAddr} matches uplink " .
                    ($this->currentUplink['domain'] ?? $this->currentUplink['address']));
                $ticPairsToSend[] = $pair;
            }
        } else {
            $ticPairsToSend = $ticPairs;
        }

        if ($ticPairsSkipped > 0) {
            $this->log("Skipped {$ticPairsSkipped} TIC pairs not for this uplink", 'DEBUG');
        }

        $totalFiles = count($filesToSend) + count($ticPairsToSend);

        if ($totalFiles === 0) {
            $this->log("No outbound files for this uplink", 'DEBUG');
            return;
        }

        // Send packet files
        foreach ($filesToSend as $file) {
            $this->log("Preparing to send packet: " . basename($file));
            $this->sendFile($file);
        }

        // Send TIC pairs (data file first, then TIC file)
        foreach ($ticPairsToSend as $pair) {
            $this->log("Preparing to send TIC pair: " . basename($pair['data']) . " + " . basename($pair['tic']));
            $this->sendFile($pair['data']);  // Send data file first
            $this->sendFile($pair['tic']);   // Then send TIC file
        }

        $this->log("Finished sending {$totalFiles} files (" . count($filesToSend) . " packets, " . count($ticPairsToSend) . " TIC pairs)");
    }

    /**
     * Read packet header to determine destination address.
     *
     * @param string $filePath Path to the .pkt file
     * @return string|null Destination address in zone:net/node format, or null on error
     */
    private function getPacketDestination(string $filePath): ?string
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            $this->log("getPacketDestination: Cannot open file: " . basename($filePath), 'WARNING');
            return null;
        }

        // Read packet header (58 bytes minimum)
        $header = fread($handle, 58);
        fclose($handle);

        if (strlen($header) < 58) {
            $this->log("getPacketDestination: Header too short (" . strlen($header) . " bytes): " . basename($filePath), 'WARNING');
            return null;
        }

        // Parse FTS-0001 packet header
        // Bytes 0-1: origNode, 2-3: destNode, 20-21: origNet, 22-23: destNet
        $data = unpack('vorigNode/vdestNode', substr($header, 0, 4));
        $netData = unpack('vorigNet/vdestNet', substr($header, 20, 4));

        if (!$data || !$netData) {
            $this->log("getPacketDestination: Failed to unpack header: " . basename($filePath), 'WARNING');
            return null;
        }

        // Get zone information from FSC-39 (Type-2e) format: offset 34-37
        // origZone at 34-35, destZone at 36-37
        $destZone = 1; // Default to zone 1
        $origZone = 1;
        if (strlen($header) >= 38) {
            $zoneData = unpack('vorigZone/vdestZone', substr($header, 34, 4));
            if ($zoneData) {
                $origZone = $zoneData['origZone'];
                if ($zoneData['destZone'] > 0) {
                    $destZone = $zoneData['destZone'];
                }
            }
        }

        $destAddr = $destZone . ':' . $netData['destNet'] . '/' . $data['destNode'];
        $origAddr = $origZone . ':' . $netData['origNet'] . '/' . $data['origNode'];

        $this->log("getPacketDestination: " . basename($filePath) .
            " - orig: {$origAddr}, dest: {$destAddr}" .
            " (raw zones: orig={$origZone}, dest={$destZone})");

        return $destAddr;
    }

    /**
     * Extract the data filename from a TIC file
     *
     * @param string $ticPath Path to TIC file
     * @return string|null Path to data file or null if not found
     */
    private function getDataFileForTic(string $ticPath): ?string
    {
        $ticContent = @file_get_contents($ticPath);
        if ($ticContent === false) {
            return null;
        }

        // Parse TIC file to find the "File" field
        $lines = explode("\n", $ticContent);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^File\s+(.+)$/i', $line, $matches)) {
                $dataFilename = trim($matches[1]);
                $dataPath = dirname($ticPath) . '/' . $dataFilename;
                return $dataPath;
            }
        }

        return null;
    }

    /**
     * Extract the destination address from a TIC file
     *
     * @param string $ticPath Path to TIC file
     * @return string|null Destination address in zone:net/node format, or null if not found
     */
    private function getTicDestination(string $ticPath): ?string
    {
        $ticContent = @file_get_contents($ticPath);
        if ($ticContent === false) {
            return null;
        }

        // Parse TIC file to find the "To" field
        $lines = explode("\n", $ticContent);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^To\s+(.+)$/i', $line, $matches)) {
                $destAddr = trim($matches[1]);
                return $destAddr;
            }
        }

        return null;
    }

    private function sendFile($filePath)
    {
        $filename = basename($filePath);
        $fileSize = filesize($filePath);
        $timestamp = filemtime($filePath);

        // Get packet destination for logging (only for .pkt files, not TIC or data files)
        $uplinkAddr = $this->currentUplink['address'] ?? 'unknown';
        $destAddr = null;

        // Only try to parse packet destination for actual FidoNet packet files
        if (preg_match('/\.pkt$/i', $filename)) {
            $destAddr = $this->getPacketDestination($filePath);
        }

        // Format: filename size timestamp [offset]
        // According to binkp spec, format should be: filename size time [offset]
        $fileInfo = "{$filename} {$fileSize} {$timestamp} 0";
        $frame = BinkpFrame::createCommand(BinkpFrame::M_FILE, $fileInfo);
        $frame->writeToSocket($this->socket);

        if ($destAddr) {
            $this->log("Sending packet {$filename} ({$fileSize} bytes) to uplink {$uplinkAddr}, packet dest: {$destAddr}", 'INFO');
        } else {
            $this->log("Sending file {$filename} ({$fileSize} bytes) to uplink {$uplinkAddr}", 'INFO');
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            $this->log("Failed to open file: {$filePath}", 'ERROR');
            return;
        }

        $bytesSent = 0;
        while (!feof($handle)) {
            $data = fread($handle, 8192);
            if ($data === false || strlen($data) === 0) {
                break;
            }
            $dataFrame = BinkpFrame::createData($data);
            $dataFrame->writeToSocket($this->socket);
            $bytesSent += strlen($data);
        }
        fclose($handle);

        if ($bytesSent === $fileSize) {
            $this->filesSent[] = $filename;
            $this->log("Delivered packet {$filename} ({$bytesSent} bytes) to {$uplinkAddr}", 'INFO');
        } else {
            $this->log("Packet send incomplete: {$filename} ({$bytesSent}/{$fileSize} bytes)", 'ERROR');
        }
    }

    private function handleFileCommand($data)
    {
        $parts = explode(' ', $data, 4);
        $filename = $parts[0];
        $size = isset($parts[1]) ? intval($parts[1]) : 0;
        $timestamp = isset($parts[2]) ? intval($parts[2]) : time();
        $offset = isset($parts[3]) ? intval($parts[3]) : 0;

        // Validate filename to prevent directory traversal
        $filename = basename($filename);
        if (empty($filename) || $filename === '.' || $filename === '..') {
            $this->log("Invalid filename in M_FILE: {$data}", 'ERROR');
            return;
        }

        $this->currentFile = [
            'name' => $filename,
            'size' => $size,
            'timestamp' => $timestamp,
            'offset' => $offset,
            'received' => 0
        ];

        $inboundPath = $this->config->getInboundPath();
        $filepath = $inboundPath . '/' . $filename;

        // Handle resume if offset > 0
        if ($offset > 0) {
            $this->fileHandle = fopen($filepath, 'r+b');
            if ($this->fileHandle) {
                fseek($this->fileHandle, $offset);
                $this->currentFile['received'] = $offset;
            }
        } else {
            $this->fileHandle = fopen($filepath, 'wb');
        }

        if (!$this->fileHandle) {
            $this->log("Failed to open file for writing: {$filepath}", 'ERROR');
            return;
        }

        $this->log("Receiving file: {$filename} ({$size} bytes)", 'INFO');
    }

    private function handleFileData($data)
    {
        if ($this->currentFile && $this->fileHandle) {
            $bytesWritten = fwrite($this->fileHandle, $data);
            if ($bytesWritten === false) {
                $this->log("Failed to write file data: " . $this->currentFile['name'], 'ERROR');
                return;
            }

            $this->currentFile['received'] += $bytesWritten;
            
            if ($this->currentFile['received'] >= $this->currentFile['size']) {
                fclose($this->fileHandle);
                $this->fileHandle = null;
                
                $this->filesReceived[] = $this->currentFile['name'];
                $this->log("File received: " . $this->currentFile['name'] . " ({$this->currentFile['received']} bytes)", 'INFO');

                // Create metadata file for packets received in insecure sessions
                if ($this->isInsecureSession && preg_match('/\.pkt$/i', $this->currentFile['name'])) {
                    $inboundPath = $this->config->getInboundPath();
                    $metadataFile = $inboundPath . '/' . $this->currentFile['name'] . '.meta';
                    $metadata = [
                        'insecure_session' => true,
                        'remote_address' => $this->remoteAddress ?? 'unknown',
                        'received_at' => time()
                    ];

                    $jsonData = json_encode($metadata, JSON_PRETTY_PRINT);
                    if ($jsonData === false) {
                        $jsonError = json_last_error_msg();
                        $this->log("ERROR: Failed to encode metadata for packet '{$this->currentFile['name']}': {$jsonError}", 'ERROR');
                    } else {
                        $result = file_put_contents($metadataFile, $jsonData);
                        if ($result === false) {
                            $lastError = error_get_last();
                            $errorMsg = $lastError ? $lastError['message'] : 'unknown error';
                            $this->log("ERROR: Failed to write metadata file '{$metadataFile}' for packet '{$this->currentFile['name']}': {$errorMsg}", 'ERROR');
                        } else {
                            $this->log("Created metadata file for insecure packet: " . $this->currentFile['name'], 'DEBUG');
                        }
                    }
                }

                // Send M_GOT
                $gotData = $this->currentFile['name'] . ' ' . $this->currentFile['size'] . ' ' . $this->currentFile['timestamp'];
                $frame = BinkpFrame::createCommand(BinkpFrame::M_GOT, $gotData);
                $frame->writeToSocket($this->socket);
                $this->log("Sent M_GOT: " . $this->currentFile['name'], 'DEBUG');

                $this->currentFile = null;
            }
        } else {
            $this->log("Received file data but no active file transfer", 'WARNING');
        }
    }

    private function handleGotCommand($data)
    {
        $this->log("Remote confirmed: {$data}", 'DEBUG');

        $parts = explode(' ', $data);
        $filename = basename($parts[0]);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $this->log("Invalid filename in M_GOT: {$data}", 'WARNING');
            return;
        }

        $outboundPath = $this->config->getOutboundPath();
        $filepath = $outboundPath . '/' . $filename;
        if (file_exists($filepath)) {
            if (unlink($filepath)) {
                $this->log("Deleted sent file: {$filename}", 'DEBUG');
            } else {
                $this->log("Failed to delete sent file: {$filename}", 'ERROR');
            }
        } else {
            $this->log("Sent file not found: {$filepath}", 'WARNING');
        }
    }

    private function handleGetCommand($data)
    {
        $this->log("Remote requested file: {$data}", 'DEBUG');
    }

    private function handleSkipCommand($data)
    {
        $this->log("Remote skipped file: {$data}", 'DEBUG');
    }

    private function validatePassword($password)
    {
        // Check for empty password (insecure session request)
        if (empty($password) || $password === '-') {
            return $this->handleInsecureAuth();
        }

        $expectedPassword = $this->getPasswordForRemote();

        // Check if this is a CRAM-MD5 response
        if (preg_match('/^CRAM-MD5-([0-9a-fA-F]{32})$/i', $password, $matches)) {
            $receivedDigest = strtolower($matches[1]);

            // We must have sent a challenge to accept CRAM auth
            if ($this->cramChallenge === null) {
                $this->log("Received CRAM-MD5 response but no challenge was sent", 'WARNING');
                return false;
            }

            // Compute expected digest
            $expectedDigest = $this->computeCramDigest($this->cramChallenge, $expectedPassword);

            // Use timing-safe comparison
            $match = hash_equals($expectedDigest, $receivedDigest);

            $this->log("CRAM-MD5 validation: " . ($match ? 'OK' : 'FAILED'), $match ? 'DEBUG' : 'WARNING');

            if ($match) {
                // Check if we matched on empty or dash password (insecure session indicators)
                if ($expectedPassword === '' || $expectedPassword === '-') {
                    $this->log("CRAM-MD5 matched with empty/dash password - checking insecure policy", 'DEBUG');
                    return $this->handleInsecureAuth();
                }

                $this->authMethod = 'cram-md5';
                $this->sessionType = 'secure';
                return true;
            }

            // Check if they're trying to authenticate with "-" (insecure session request)
            // Compute what the digest would be if password was "-"
            $dashDigest = $this->computeCramDigest($this->cramChallenge, '-');
            if (hash_equals($dashDigest, $receivedDigest)) {
                $this->log("CRAM-MD5 with '-' password detected - checking insecure policy", 'DEBUG');
                return $this->handleInsecureAuth();
            }

            // Also check for empty password
            $emptyDigest = $this->computeCramDigest($this->cramChallenge, '');
            if (hash_equals($emptyDigest, $receivedDigest)) {
                $this->log("CRAM-MD5 with empty password detected - checking insecure policy", 'DEBUG');
                return $this->handleInsecureAuth();
            }

            return false;
        }

        // Plain text password validation
        // If we sent a challenge and got a plain password, check if fallback is allowed
        if ($this->cramChallenge !== null) {
            if (!$this->config->getAllowPlaintextFallback()) {
                $this->log("Plain text password rejected - CRAM-MD5 required", 'WARNING');
                return false;
            }
            $this->log("Accepting plain text password fallback", 'DEBUG');
        }

        $match = hash_equals($expectedPassword, $password);

        // Log details for debugging authentication issues
        $receivedLen = strlen($password);
        $expectedLen = strlen($expectedPassword);
        $receivedPreview = $receivedLen > 0 ? substr($password, 0, 3) . '...' : '(empty)';
        $expectedPreview = $expectedLen > 0 ? substr($expectedPassword, 0, 3) . '...' : '(empty)';

        $this->log("Password validation: received={$receivedPreview} (len={$receivedLen}), expected={$expectedPreview} (len={$expectedLen})", 'DEBUG');
        $this->log("Password validation: " . ($match ? 'OK' : 'FAILED'), $match ? 'DEBUG' : 'WARNING');

        if ($match) {
            $this->authMethod = 'plaintext';
            $this->sessionType = 'secure';
        }

        return $match;
    }

    /**
     * Handle authentication for insecure session request
     *
     * @return bool True if insecure session is allowed
     */
    private function handleInsecureAuth(): bool
    {
        // Check if insecure sessions are allowed
        if (!$this->config->getAllowInsecureInbound()) {
            $this->log("Insecure session rejected - disabled in configuration", 'WARNING');
            return false;
        }

        // Check allowlist if required
        if ($this->config->getRequireAllowlistForInsecure()) {
            if (!$this->isNodeInInsecureAllowlist($this->remoteAddress)) {
                $this->log("Insecure session rejected - node not in allowlist: {$this->remoteAddress}", 'WARNING');
                return false;
            }
        }

        // Check rate limits
        if (!$this->checkInsecureRateLimit()) {
            $this->log("Insecure session rejected - rate limit exceeded for {$this->remoteAddress}", 'WARNING');
            return false;
        }

        $this->isInsecureSession = true;
        $this->insecureReceiveOnly = $this->config->getInsecureReceiveOnly();
        $this->sessionType = 'insecure';
        $this->log("Insecure session accepted for {$this->remoteAddress}", 'INFO');
        return true;
    }

    /**
     * Check if node is in insecure allowlist
     */
    private function isNodeInInsecureAllowlist(string $address): bool
    {
        try {
            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                SELECT id FROM binkp_insecure_nodes
                WHERE address = ? AND is_active = TRUE
            ");
            $stmt->execute([$address]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            $this->log("Error checking insecure allowlist: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Check rate limit for insecure sessions
     */
    private function checkInsecureRateLimit(): bool
    {
        $maxPerHour = $this->config->getMaxInsecureSessionsPerHour();
        if ($maxPerHour <= 0) {
            return true; // No limit configured
        }

        $count = \BinktermPHP\Binkp\SessionLogger::countRecentInsecureSessions(
            $this->remoteAddress,
            60 // 60 minutes
        );

        return $count < $maxPerHour;
    }

    /**
     * Check if this is an insecure (unauthenticated) session
     */
    public function isInsecureSession(): bool
    {
        return $this->isInsecureSession;
    }

    /**
     * Get the session type for logging
     */
    public function getSessionType(): string
    {
        return $this->sessionType;
    }

    /**
     * Set session type (for outbound crash sessions)
     */
    public function setSessionType(string $type): void
    {
        $this->sessionType = $type;
    }

    /**
     * Check if this insecure session is receive-only
     */
    public function isInsecureReceiveOnly(): bool
    {
        return $this->isInsecureSession && $this->insecureReceiveOnly;
    }

    private function getPasswordForRemote()
    {
        if ($this->remoteAddress) {
            $uplink = $this->config->getUplinkByAddress($this->remoteAddress);
            if ($uplink) {
                $this->log("Found uplink config for {$this->remoteAddress}", 'DEBUG');
                return $uplink['password'] ?? '';
            } else {
                $this->log("No uplink config found for {$this->remoteAddress}", 'WARNING');
                return '';
            }
        }
        return '';
    }

    private function cleanup()
    {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }

        if ($this->currentFile) {
            $inboundPath = $this->config->getInboundPath();
            $filepath = $inboundPath . '/' . $this->currentFile['name'];
            if (file_exists($filepath) && $this->currentFile['received'] < $this->currentFile['size']) {
                unlink($filepath);
                $this->log("Deleted incomplete file: " . $this->currentFile['name'], 'DEBUG');
            }
        }
    }

    // ========================================
    // CRAM-MD5 Authentication Methods
    // ========================================

    /**
     * Generate a CRAM-MD5 challenge (16 random bytes as 32 hex chars).
     *
     * @return string 32-character hex string
     */
    private function generateCramChallenge(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Compute CRAM-MD5 digest per FTS-1026 specification.
     * Uses HMAC-MD5 as defined in RFC 2104:
     *   HMAC(K, M) = H((K XOR opad) || H((K XOR ipad) || M))
     * Where K=password (the key) and M=challenge (the message)
     *
     * @param string $challenge Hex challenge (variable length)
     * @param string $password Plain text password (the HMAC key)
     * @return string 32-character hex MD5 digest
     */
    private function computeCramDigest(string $challenge, string $password): string
    {
        $binaryChallenge = hex2bin($challenge);

        // Use HMAC-MD5: key=password, message=challenge
        $digest = hash_hmac('md5', $binaryChallenge, $password);

        $this->log("CRAM-MD5 HMAC digest: challenge_len=" . strlen($challenge) .
            ", password_len=" . strlen($password) . ", digest=" . $digest, 'DEBUG');
        return $digest;
    }

    /**
     * Parse CRAM-MD5 challenge from M_NUL OPT data.
     * Format: "OPT CRAM-MD5-<hex chars>" (variable length)
     *
     * @param string $nulData The M_NUL frame data
     * @return string|null The challenge hex string, or null if not found
     */
    private function parseCramChallenge(string $nulData): ?string
    {
        // Match variable-length hex challenge (at least 16 chars, typically 32+)
        if (preg_match('/CRAM-MD5-([0-9a-fA-F]{16,})/', $nulData, $matches)) {
            $challenge = $matches[1];
            $this->log("Parsed CRAM-MD5 challenge: " . $challenge . " (len=" . strlen($challenge) . ")", 'DEBUG');
            return $challenge;
        }
        return null;
    }

    /**
     * Check if the current uplink has CRAM enabled (crypt: true).
     *
     * @return bool
     */
    private function isCramEnabledForUplink(): bool
    {
        if ($this->currentUplink === null) {
            return false;
        }
        return !empty($this->currentUplink['crypt']);
    }

    /**
     * Check if any configured uplink has CRAM enabled.
     * Used by answerer to decide whether to send challenge.
     *
     * @return bool
     */
    private function hasAnyCramEnabledUplink(): bool
    {
        foreach ($this->config->getUplinks() as $uplink) {
            if (!empty($uplink['crypt'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the authentication method used for this session.
     *
     * @return string 'plaintext' or 'cram-md5'
     */
    public function getAuthMethod(): string
    {
        return $this->authMethod;
    }

    public function getFilesReceived()
    {
        return $this->filesReceived;
    }
    
    public function getFilesSent()
    {
        return $this->filesSent;
    }
    
    public function getRemoteAddress()
    {
        return $this->remoteAddress;
    }

    /**
     * Get the remote address including domain suffix if provided.
     *
     * @return string|null The remote address with domain (e.g., "1:153/149@fidonet")
     */
    public function getRemoteAddressWithDomain()
    {
        return $this->remoteAddressWithDomain;
    }

    public function close()
    {
        $this->cleanup();
        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
}

