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


namespace BinktermPHP\Binkp\Web;

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Protocol\BinkpClient;
use BinktermPHP\Binkp\Connection\Scheduler;
use BinktermPHP\Binkp\Queue\InboundQueue;
use BinktermPHP\Binkp\Queue\OutboundQueue;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Auth;

class BinkpController
{
    private $config;
    private $logger;
    
    public function __construct()
    {
        $this->config = BinkpConfig::getInstance();
        $this->logger = new Logger(\BinktermPHP\Config::getLogPath('binkp_web.log'));
    }
    
    public function getStatus()
    {
        $client = new BinkpClient($this->config, $this->logger);
        $scheduler = new Scheduler($this->config, $this->logger);
        $inboundQueue = new InboundQueue($this->config, $this->logger);
        $outboundQueue = new OutboundQueue($this->config, $this->logger);
        
        $uplinkStatus = $client->getUplinkStatus();
        $scheduleStatus = $scheduler->getScheduleStatus();
        $inboundStats = $inboundQueue->getStats();
        $outboundStats = $outboundQueue->getStats();
        
        return [
            'system' => [
                'address' => $this->config->getSystemAddress(),
                'sysop' => $this->config->getSystemSysop(),
                'location' => $this->config->getSystemLocation(),
                'hostname' => $this->config->getSystemHostname(),
                'port' => $this->config->getBinkpPort()
            ],
            'uplinks' => $uplinkStatus,
            'schedule' => $scheduleStatus,
            'queues' => [
                'inbound' => $inboundStats,
                'outbound' => $outboundStats
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    public function pollUplink($address)
    {
        try {
            $client = new BinkpClient($this->config, $this->logger);
            $result = $client->pollUplink($address);
            
            return [
                'success' => true,
                'result' => $result
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.uplink.poll_failed', $e->getMessage());
        }
    }
    
    public function pollAllUplinks()
    {
        try {
            $client = new BinkpClient($this->config, $this->logger);
            $results = $client->pollAllUplinks();
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.uplink.poll_all_failed', $e->getMessage());
        }
    }
    
    public function testConnection($hostname, $port = 24554)
    {
        try {
            $client = new BinkpClient($this->config, $this->logger);
            $result = $client->testConnection($hostname, $port);
            
            return [
                'success' => true,
                'result' => $result
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.connection_test_failed', $e->getMessage());
        }
    }
    
    public function getUplinks()
    {
        return [
            'success' => true,
            'uplinks' => $this->config->getUplinks()
        ];
    }
    
    public function addUplink($data)
    {
        try {
            $address = $data['address'] ?? '';
            $hostname = $data['hostname'] ?? '';
            $port = (int) ($data['port'] ?? 24554);
            $password = $data['password'] ?? '';
            
            if (empty($address) || empty($hostname)) {
                return $this->apiErrorResponse(
                    'errors.binkp.uplink.address_hostname_required',
                    'Address and hostname are required'
                );
            }
            
            $options = [
                'enabled' => $data['enabled'] ?? true,
                'compression' => $data['compression'] ?? false,
                'crypt' => $data['crypt'] ?? false,
                'poll_schedule' => $data['poll_schedule'] ?? '0 */4 * * *'
            ];
            
            $this->config->addUplink($address, $hostname, $port, $password, $options);
            
            return [
                'success' => true,
                'message_code' => 'ui.api.binkp.uplink_added'
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.uplink.add_failed', $e->getMessage());
        }
    }
    
    public function updateUplink($address, $data)
    {
        try {
            $this->config->updateUplink($address, $data);
            
            return [
                'success' => true,
                'message_code' => 'ui.api.binkp.uplink_updated'
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.uplink.update_failed', $e->getMessage());
        }
    }
    
    public function removeUplink($address)
    {
        try {
            $this->config->removeUplink($address);
            
            return [
                'success' => true,
                'message_code' => 'ui.api.binkp.uplink_removed'
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.uplink.remove_failed', $e->getMessage());
        }
    }
    
    public function getInboundFiles()
    {
        try {
            $inboundQueue = new InboundQueue($this->config, $this->logger);
            $files = $inboundQueue->getInboundFiles();
            $errorFiles = $inboundQueue->getErrorFiles();
            
            return [
                'success' => true,
                'pending' => $files,
                'errors' => $errorFiles
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.files.inbound_failed', $e->getMessage());
        }
    }
    
    public function getOutboundFiles()
    {
        try {
            $outboundQueue = new OutboundQueue($this->config, $this->logger);
            $files = $outboundQueue->getOutboundFiles();
            
            return [
                'success' => true,
                'files' => $files
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.files.outbound_failed', $e->getMessage());
        }
    }
    
    public function processInbound()
    {
        try {
            $inboundQueue = new InboundQueue($this->config, $this->logger);
            $results = $inboundQueue->processInbound();
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.files.process_inbound_failed', $e->getMessage());
        }
    }
    
    public function processOutbound()
    {
        try {
            $outboundQueue = new OutboundQueue($this->config, $this->logger);
            $results = $outboundQueue->processOutbound();
            
            return [
                'success' => true,
                'results' => $results
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.files.process_outbound_failed', $e->getMessage());
        }
    }
    
    public function deleteOutboundFile($filename)
    {
        try {
            $outboundQueue = new OutboundQueue($this->config, $this->logger);
            $outboundQueue->deleteOutboundFile($filename);
            
            return [
                'success' => true,
                'message_code' => 'ui.api.binkp.file_deleted'
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.files.delete_outbound_failed', $e->getMessage());
        }
    }
    
    public function retryErrorFile($filename)
    {
        try {
            $inboundQueue = new InboundQueue($this->config, $this->logger);
            $inboundQueue->retryErrorFile($filename);
            
            return [
                'success' => true,
                'message_code' => 'ui.api.binkp.file_retry_started'
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.files.retry_error_failed', $e->getMessage());
        }
    }
    
    public function getLogs($lines = 100)
    {
        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $logs = $client->getLogs((int)$lines);
            
            return [
                'success' => true,
                'logs' => $logs
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.logs.failed', $e->getMessage());
        }
    }
    
    /**
     * List kept (preserved) packets from inbound/keep or outbound/keep, grouped by
     * date directory, sorted newest-first.  Requires a valid registered license.
     *
     * @param string $type 'inbound' or 'outbound'
     * @return array
     */
    public function getKeptPackets(string $type): array
    {
        if (!\BinktermPHP\License::isValid()) {
            return [
                'success'    => false,
                'error_code' => 'errors.binkp.kept_packets.license_required',
                'error'      => 'Viewing kept packets requires a registered license',
            ];
        }

        try {
            $basePath = $type === 'inbound'
                ? $this->config->getInboundPath() . DIRECTORY_SEPARATOR . 'keep'
                : $this->config->getOutboundPath() . DIRECTORY_SEPARATOR . 'keep';

            if (!is_dir($basePath)) {
                return ['success' => true, 'groups' => [], 'total' => 0];
            }

            $analyzer = new \BinktermPHP\Binkp\Queue\OutboundQueue($this->config, $this->logger);
            $groups   = [];
            $total    = 0;

            // Collect entries: date subdirs + any loose .pkt files at root
            $entries = array_diff(scandir($basePath), ['.', '..']);

            // Sort newest-first (date dirs are "Mon-DD-YYYY"; string sort works after reverse)
            usort($entries, fn($a, $b) => filemtime($basePath . DIRECTORY_SEPARATOR . $b)
                                        - filemtime($basePath . DIRECTORY_SEPARATOR . $a));

            foreach ($entries as $entry) {
                $entryPath = $basePath . DIRECTORY_SEPARATOR . $entry;
                $packets   = [];

                if (is_dir($entryPath)) {
                    foreach (glob($entryPath . DIRECTORY_SEPARATOR . '*.pkt') ?: [] as $pkt) {
                        $info      = $analyzer->analyzePacket($pkt);
                        $packets[] = $this->buildPacketRecord($pkt, $info);
                    }
                    $label = $entry;
                } elseif (is_file($entryPath) && str_ends_with(strtolower($entry), '.pkt')) {
                    $info      = $analyzer->analyzePacket($entryPath);
                    $packets[] = $this->buildPacketRecord($entryPath, $info);
                    $label     = ''; // loose file — no date group label
                } else {
                    continue;
                }

                if (!empty($packets)) {
                    usort($packets, static fn(array $a, array $b): int => $b['modified_ts'] <=> $a['modified_ts']);

                    $total   += count($packets);
                    $groups[] = [
                        'date'               => $label,
                        'packets'            => $packets,
                        'latest_modified_ts' => $packets[0]['modified_ts'],
                    ];
                }
            }

            usort($groups, static fn(array $a, array $b): int => $b['latest_modified_ts'] <=> $a['latest_modified_ts']);

            return ['success' => true, 'groups' => $groups, 'total' => $total];

        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.kept_packets.failed', $e->getMessage());
        }
    }

    /**
     * Build a normalised packet record from a file path and its analysed metadata.
     *
     * @param string $path Absolute path to the .pkt file
     * @param array  $info Output of OutboundQueue::analyzePacket()
     * @return array
     */
    private function buildPacketRecord(string $path, array $info): array
    {
        $modifiedTs = filemtime($path);

        return [
            'filename'      => basename($path),
            'size'          => filesize($path),
            'modified'      => date('Y-m-d H:i:s', $modifiedTs),
            'modified_ts'   => $modifiedTs,
            'message_count' => $info['message_count'],
            'dest_address'  => $info['dest_address'],
            'orig_address'  => $info['orig_address'],
        ];
    }

    /**
     * Parse a kept packet file and return full header info plus per-message headers
     * (no message body text).  Requires a valid registered license.
     *
     * @param string $type     'inbound' or 'outbound'
     * @param string $date     Date directory name (e.g. "Mar-18-2026") or '' for root
     * @param string $filename Packet filename (e.g. "69ba4f19.pkt")
     * @return array
     */
    public function inspectPacket(string $type, string $date, string $filename): array
    {
        if (!\BinktermPHP\License::isValid()) {
            return [
                'success'    => false,
                'error_code' => 'errors.binkp.kept_packets.license_required',
                'error'      => 'Viewing kept packets requires a registered license',
            ];
        }

        // Strip any path components first — basename() ensures we only ever have
        // a bare filename/directory name regardless of what the caller sent.
        $date     = basename($date);
        $filename = basename($filename);

        // Then enforce strict allowlists — only alphanumeric, hyphens, underscores.
        if (!preg_match('/^[A-Za-z0-9\-]*$/', $date)) {
            return ['success' => false, 'error' => 'Invalid date parameter'];
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+\.pkt$/i', $filename)) {
            return ['success' => false, 'error' => 'Invalid filename parameter'];
        }

        try {
            $filepath = $this->resolveKeptPacketPath($type, $date, $filename);
            if ($filepath === null) {
                return ['success' => false, 'error' => 'File not found'];
            }

            return $this->parsePacketFull($filepath);

        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.kept_packets.inspect_failed', $e->getMessage());
        }
    }

    public function getKeptPacketDownloadPath(string $type, string $date, string $filename): ?string
    {
        return $this->resolveKeptPacketPath($type, $date, $filename);
    }

    /**
     * Parse a live queue packet (inbound or outbound root directory) and return
     * full header info plus per-message headers.  Requires a valid license.
     *
     * @param string $type     'inbound' or 'outbound'
     * @param string $filename Packet filename (e.g. "69ba4f19.pkt")
     * @return array
     */
    public function inspectQueuePacket(string $type, string $filename): array
    {
        if (!\BinktermPHP\License::isValid()) {
            return [
                'success'    => false,
                'error_code' => 'errors.binkp.kept_packets.license_required',
                'error'      => 'Viewing packets requires a registered license',
            ];
        }

        $filepath = $this->resolveQueuePacketPath($type, $filename);
        if ($filepath === null) {
            return ['success' => false, 'error' => 'File not found'];
        }

        try {
            return $this->parsePacketFull($filepath);
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.queue.inspect_failed', $e->getMessage());
        }
    }

    /**
     * Resolve and validate a path to a file in the live inbound or outbound queue directory.
     *
     * @param string $type     'inbound' or 'outbound'
     * @param string $filename Packet filename
     * @return string|null Absolute path on success, null if invalid or not found
     */
    public function resolveQueuePacketPath(string $type, string $filename): ?string
    {
        $filename = basename($filename);
        if (!preg_match('/^[A-Za-z0-9_\-]+\.pkt$/i', $filename)) {
            return null;
        }

        $basePath = $type === 'inbound'
            ? $this->config->getInboundPath()
            : $this->config->getOutboundPath();

        $filepath = $basePath . DIRECTORY_SEPARATOR . $filename;

        $realBase = realpath($basePath);
        $realFile = realpath($filepath);
        if (!$realFile || !$realBase) {
            return null;
        }
        if ($realFile !== $realBase && !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realFile;
    }

    private function resolveKeptPacketPath(string $type, string $date, string $filename): ?string
    {
        $date     = basename($date);
        $filename = basename($filename);

        if (!preg_match('/^[A-Za-z0-9\-]*$/', $date)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+\.pkt$/i', $filename)) {
            return null;
        }

        $basePath = $type === 'inbound'
            ? $this->config->getInboundPath() . DIRECTORY_SEPARATOR . 'keep'
            : $this->config->getOutboundPath() . DIRECTORY_SEPARATOR . 'keep';

        $filepath = $date
            ? $basePath . DIRECTORY_SEPARATOR . $date . DIRECTORY_SEPARATOR . $filename
            : $basePath . DIRECTORY_SEPARATOR . $filename;

        $realBase = realpath($basePath);
        $realFile = realpath($filepath);
        if (!$realFile || !$realBase) {
            return null;
        }

        if ($realFile !== $realBase && !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($realFile) ? $realFile : null;
    }

    /**
     * Parse the FTS-0001 packet binary: full 58-byte packet header plus every
     * message header (from/to names, subject, date, attribute flags).
     * Message body text is skipped entirely.
     *
     * @param string $filepath Absolute, validated path to the .pkt file
     * @return array
     */
    private function parsePacketFull(string $filepath): array
    {
        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            return ['success' => false, 'error' => 'Cannot open packet file'];
        }

        try {
            // ── Packet header (58 bytes, FTS-0001) ───────────────────────────
            $hdr = fread($handle, 60);
            if (strlen($hdr) < 58) {
                fclose($handle);
                return ['success' => false, 'error' => 'File too small to be a valid FTS-0001 packet'];
            }

            $h = unpack(
                'vorigNode/vdestNode/vyear/vmonth/vday/vhour/vminute/vsecond/' .
                'vbaud/vpacketVersion/vorigNet/vdestNet/CprodCodeLo/CrevMajor',
                substr($hdr, 0, 26)
            );

            // FTS-0001: password is 8 bytes (offsets 26–33)
            // origZone/destZone at 34/36, origPoint/destPoint at 50/52
            $password  = rtrim(substr($hdr, 26, 8), "\x00");
            $origZone  = unpack('v', substr($hdr, 34, 2))[1];
            $destZone  = unpack('v', substr($hdr, 36, 2))[1];
            $origPoint = unpack('v', substr($hdr, 50, 2))[1];
            $destPoint = unpack('v', substr($hdr, 52, 2))[1];

            $month = ($h['month'] < 12) ? $h['month'] + 1 : $h['month']; // 0-based in spec
            $created = sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                $h['year'], $month, $h['day'], $h['hour'], $h['minute'], $h['second']);

            $fmtAddr = function(int $zone, int $net, int $node, int $point): string {
                $addr = "{$zone}:{$net}/{$node}";
                if ($point > 0) $addr .= ".{$point}";
                return $addr;
            };

            $packet = [
                'orig_address'   => $fmtAddr($origZone, $h['origNet'], $h['origNode'], $origPoint),
                'dest_address'   => $fmtAddr($destZone, $h['destNet'], $h['destNode'], $destPoint),
                'created'        => $created,
                'has_password'   => $password !== '',
                'packet_version' => $h['packetVersion'],
                'product_code'   => sprintf('%02X', $h['prodCodeLo']),
                'file_size'      => filesize($filepath),
            ];

            // ── Message headers ───────────────────────────────────────────────
            fseek($handle, 58);
            $messages   = [];
            $maxMsgs    = 1000;
            $attrLabels = [
                0  => 'Pvt',  1 => 'Crash', 2 => 'Rcvd', 3 => 'Sent',
                4  => 'Att',  5 => 'Trs',   6 => 'Orphn', 7 => 'K/S',
                8  => 'Local', 9 => 'Hold', 11 => 'FReq', 12 => 'RReq',
                13 => 'RRec', 14 => 'Audit', 15 => 'FUpd',
            ];

            while (!feof($handle) && count($messages) < $maxMsgs) {
                $typeBytes = fread($handle, 2);
                if (strlen($typeBytes) < 2) break;
                $msgType = unpack('v', $typeBytes)[1];
                if ($msgType === 0) break;          // end-of-packet marker
                if ($msgType !== 2) break;           // unexpected type

                // 12-byte message header: origNode destNode origNet destNet attr cost
                $mhBytes = fread($handle, 12);
                if (strlen($mhBytes) < 12) break;
                $mh = unpack('vorigNode/vdestNode/vorigNet/vdestNet/vattr/vcost', $mhBytes);

                $datetime = $this->pktReadString($handle, 20);
                $toName   = $this->pktReadString($handle, 36);
                $fromName = $this->pktReadString($handle, 36);
                $subject  = $this->pktReadString($handle, 72);

                // Skip message body (null-terminated)
                if (!$this->pktSkipBody($handle, 65536)) break;

                $flags = [];
                foreach ($attrLabels as $bit => $label) {
                    if ($mh['attr'] & (1 << $bit)) {
                        $flags[] = $label;
                    }
                }

                $cp437 = fn(string $s): string =>
                    (@iconv('CP437', 'UTF-8//IGNORE', $s) ?: mb_convert_encoding($s, 'UTF-8', 'UTF-8'));

                $messages[] = [
                    'from'      => $cp437($fromName),
                    'to'        => $cp437($toName),
                    'subject'   => $cp437($subject),
                    'date'      => $datetime,
                    'orig_addr' => $mh['origNet'] . ':' . $mh['origNode'],
                    'dest_addr' => $mh['destNet'] . ':' . $mh['destNode'],
                    'flags'     => $flags,
                    'cost'      => $mh['cost'],
                ];
            }

            fclose($handle);

            return [
                'success'  => true,
                'packet'   => $packet,
                'messages' => $messages,
            ];

        } catch (\Exception $e) {
            if (is_resource($handle)) fclose($handle);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Read a null-terminated string from $handle, consuming at most $maxLen bytes.
     */
    private function pktReadString($handle, int $maxLen): string
    {
        $result = '';
        for ($i = 0; $i < $maxLen; $i++) {
            $ch = fread($handle, 1);
            if ($ch === false || $ch === '' || $ch === "\x00") break;
            $result .= $ch;
        }
        return $result;
    }

    /**
     * Skip a null-terminated message body, consuming at most $maxLen bytes.
     * Returns false if the read failed before finding the null terminator.
     */
    private function pktSkipBody($handle, int $maxLen): bool
    {
        for ($i = 0; $i < $maxLen; $i++) {
            $ch = fread($handle, 1);
            if ($ch === false || $ch === '') return false;
            if ($ch === "\x00") return true;
        }
        return true; // Reached limit — treat as terminated
    }

    /**
     * Search all binkp-related log files for the given query, returning matched lines
     * plus all lines sharing the same PID (session context).
     *
     * @param string $query Case-insensitive search term
     * @return array
     */
    public function searchLogs(string $query): array
    {
        try {
            $logFiles = [
                'binkp_poll.log'      => \BinktermPHP\Config::getLogPath('binkp_poll.log'),
                'binkp_server.log'    => \BinktermPHP\Config::getLogPath('binkp_server.log'),
                'binkp_scheduler.log' => \BinktermPHP\Config::getLogPath('binkp_scheduler.log'),
                'admin_daemon.log'    => \BinktermPHP\Config::getLogPath('admin_daemon.log'),
                'mrc_daemon.log'      => \BinktermPHP\Config::getLogPath('mrc_daemon.log'),
                'packets.log'         => \BinktermPHP\Config::getLogPath('packets.log'),
            ];
            $result = $this->logger->searchLogs($query, $logFiles);
            return array_merge(['success' => true], $result);
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.logs.search_failed', $e->getMessage());
        }
    }

    public function getConfig()
    {
        return [
            'success' => true,
            'config' => $this->config->getFullConfig()
        ];
    }
    
    public function updateConfig($section, $data)
    {
        try {
            switch ($section) {
                case 'system':
                    $this->config->setSystemConfig(
                        null,                          // $name (not in form)
                        $data['address'] ?? null,      // $address
                        $data['sysop'] ?? null,        // $sysop
                        $data['location'] ?? null,     // $location
                        $data['hostname'] ?? null,     // $hostname
                        null                           // $origin (not in form)
                    );
                    break;
                    
                case 'binkp':
                    $this->config->setBinkpConfig(
                        isset($data['port']) ? (int) $data['port'] : null,
                        isset($data['timeout']) ? (int) $data['timeout'] : null,
                        isset($data['max_connections']) ? (int) $data['max_connections'] : null,
                        $data['bind_address'] ?? null
                    );
                    break;
                    
                default:
                    return $this->apiErrorResponse(
                        'errors.binkp.config.invalid_section',
                        'Invalid configuration section'
                    );
            }
            
            return [
                'success' => true,
                'message_code' => 'ui.api.binkp.config_updated'
            ];
            
        } catch (\Exception $e) {
            return $this->apiErrorResponse('errors.binkp.config.update_failed', $e->getMessage());
        }
    }

    private function apiErrorResponse(string $errorCode, string $message): array
    {
        $localized = $this->localizedErrorText($errorCode, $message);
        return [
            'success' => false,
            'error_code' => $errorCode,
            'error' => $localized
        ];
    }

    private function localizedErrorText(string $errorCode, string $fallbackMessage): string
    {
        static $translator = null;
        static $localeResolver = null;

        if ($translator === null) {
            $translator = new \BinktermPHP\I18n\Translator();
            $localeResolver = new \BinktermPHP\I18n\LocaleResolver($translator);
        }

        $auth = new Auth();
        $user = $auth->getCurrentUser();
        $preferredLocale = is_array($user) ? (string)($user['locale'] ?? '') : '';
        $resolvedLocale = $localeResolver->resolveLocale($preferredLocale !== '' ? $preferredLocale : null, $user);
        $translated = $translator->translate($errorCode, [], $resolvedLocale, ['errors']);

        return $translated === $errorCode ? $fallbackMessage : $translated;
    }
}

