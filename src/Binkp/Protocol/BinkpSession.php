<?php

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

                    while (!empty($pendingFiles) && time() < $timeout && $this->state < self::STATE_TERMINATED) {
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

            // Continue until session terminates
            while ($this->state < self::STATE_TERMINATED) {
                $frame = BinkpFrame::parseFromSocket($this->socket);
                if (!$frame) {
                    break;
                }
                $this->log("Received: {$frame}", 'DEBUG');
                $this->processTransferFrame($frame);
            }

            $this->cleanup();
            $this->log('Session completed successfully');
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
                $addresses = explode(' ', $addressData);

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
                $fallbackAddress = $addresses[0];
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
                    $this->sendAddress();
                    $this->sendPassword();
                    $this->state = self::STATE_PWD_SENT;
                } elseif ($this->state === self::STATE_ADDR_SENT) {
                    $this->sendPassword();
                    $this->state = self::STATE_PWD_SENT;
                } else {
                    $this->state = self::STATE_ADDR_RECEIVED;
                }
                break;

            case BinkpFrame::M_PWD:
                $this->log("M_PWD received", 'DEBUG');
                if (!$this->validatePassword($frame->getData())) {
                    throw new \Exception('Authentication failed');
                }

                if ($this->state === self::STATE_ADDR_RECEIVED) {
                    $this->sendPassword();
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
                $this->log("M_OK received", 'DEBUG');
                if ($this->state === self::STATE_PWD_SENT) {
                    $this->state = self::STATE_AUTHENTICATED;
                }
                break;

            case BinkpFrame::M_NUL:
                // System info frames - just log them
                $this->log("M_NUL: " . $frame->getData(), 'DEBUG');
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
                case BinkpFrame::M_FILE:
                    $this->handleFileCommand($frame->getData());
                    break;

                case BinkpFrame::M_EOB:
                    $this->log("Received M_EOB", 'DEBUG');
                    if ($this->state === self::STATE_EOB_SENT) {
                        $this->state = self::STATE_TERMINATED;
                    } elseif ($this->state === self::STATE_EOB_RECEIVED) {
                        $this->state = self::STATE_TERMINATED;
                    } else {
                        $this->sendEOB();
                        $this->state = self::STATE_EOB_RECEIVED;
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
        $this->log("Sent password (length=" . strlen($password) . ")", 'DEBUG');
        $frame = BinkpFrame::createCommand(BinkpFrame::M_PWD, $password);
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
        $files = glob($outboundPath . '/*.pkt');

        $this->log("Found " . count($files) . " outbound files", 'DEBUG');

        if (empty($files)) {
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

        if (empty($filesToSend)) {
            $this->log("No outbound files for this uplink", 'DEBUG');
            return;
        }

        foreach ($filesToSend as $file) {
            $this->log("Preparing to send file: " . basename($file));
            $this->sendFile($file);
        }

        $this->log("Finished sending " . count($filesToSend) . " files");
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
    
    private function sendFile($filePath)
    {
        $filename = basename($filePath);
        $fileSize = filesize($filePath);
        $timestamp = filemtime($filePath);
        
        // Format: filename size timestamp [offset]
        // According to binkp spec, format should be: filename size time [offset]
        $fileInfo = "{$filename} {$fileSize} {$timestamp} 0";
        $frame = BinkpFrame::createCommand(BinkpFrame::M_FILE, $fileInfo);
        $frame->writeToSocket($this->socket);
        
        $this->log("Sending file: {$filename} ({$fileSize} bytes)", 'DEBUG');

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
            $this->log("Sent file: {$filename} ({$bytesSent} bytes)", 'INFO');
        } else {
            $this->log("File send incomplete: {$filename} ({$bytesSent}/{$fileSize} bytes)", 'ERROR');
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

                // Send M_GOT
                $gotData = $this->currentFile['name'] . ' ' . $this->currentFile['size'] . ' ' . time();
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
        $filename = $parts[0];

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
        $match = $password === $expectedPassword;

        // Log details for debugging authentication issues
        $receivedLen = strlen($password);
        $expectedLen = strlen($expectedPassword);
        $receivedPreview = $receivedLen > 0 ? substr($password, 0, 3) . '...' : '(empty)';
        $expectedPreview = $expectedLen > 0 ? substr($expectedPassword, 0, 3) . '...' : '(empty)';

        $this->log("Password validation: received={$receivedPreview} (len={$receivedLen}), expected={$expectedPreview} (len={$expectedLen})", 'DEBUG');
        $this->log("Password validation: " . ($match ? 'OK' : 'FAILED'), $match ? 'DEBUG' : 'WARNING');

        if ($match) {
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