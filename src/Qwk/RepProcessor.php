<?php

/*
 * Copright "Agent 57951" and BinktermPHP Contributors
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

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use PDO;
use ZipArchive;

/**
 * RepProcessor
 *
 * Parses an uploaded QWK REP packet (BBSID.REP) and imports the replies
 * as echomail or netmail via the existing MessageHandler.
 *
 * REP packet structure:
 *   A ZIP archive containing a single file named <BBSID>.MSG.
 *   That file uses the same 128-byte block structure as MESSAGES.DAT.
 *
 * Block 0 is a reserved header (ignored).
 * Each subsequent message starts at a 128-byte block boundary.  The first
 * block of each message is the header; the block-count field (bytes 116–121)
 * gives the total number of 128-byte blocks the message occupies including
 * the header block itself.  The body follows in the remaining blocks.
 *
 * Conference 0 → netmail (addressed to the uplink address or sysop).
 * Conference N → echomail in the area identified by the conference map
 *               stored at download time in qwk_download_log.
 *
 * QWKE kludge lines (^A-prefixed) at the start of the body are stripped
 * from the imported text and noted for metadata, but BinktermPHP regenerates
 * its own kludges when spooling outbound packets so they are not re-used
 * directly.
 *
 * Security:
 *  - The user must have a prior download on record (conference map required).
 *  - The BBSID in the uploaded MSG filename must match this installation.
 *  - The MSG file size must be a non-zero multiple of 128 bytes.
 *  - No ZIP entry path traversal: extraction targets a controlled temp dir.
 */
class RepProcessor
{
    private const BLOCK_SIZE        = 128;
    private const QWK_LINE_TERM     = "\xE3";
    private const MAX_UPLOAD_BYTES  = 10 * 1024 * 1024;  // 10 MB

    private PDO            $db;
    private MessageHandler $messageHandler;
    private QwkBuilder     $builder;

    public function __construct()
    {
        $this->db             = Database::getInstance()->getPdo();
        $this->messageHandler = new MessageHandler();
        $this->builder        = new QwkBuilder();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Process an uploaded REP ZIP file path (e.g. $_FILES['rep']['tmp_name']).
     *
     * Returns:
     *   ['imported' => int, 'skipped' => int, 'errors' => string[]]
     */
    public function processRepPacket(string $zipPath, int $userId): array
    {
        $result  = ['imported' => 0, 'skipped' => 0, 'errors' => []];
        $tempDir = null;

        // Pre-flight: require a prior download so we have a conference map.
        [$conferenceMap, $messageMap] = $this->getLatestDownloadMaps($userId);
        if ($conferenceMap === null) {
            $result['errors'][] = 'No prior QWK download found for this account. '
                . 'Download a packet first before uploading replies.';
            return $result;
        }

        // Validate file size.
        $size = filesize($zipPath);
        if ($size === false || $size === 0) {
            $result['errors'][] = 'Uploaded file is empty or unreadable.';
            return $result;
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            $result['errors'][] = 'Uploaded file exceeds the maximum allowed size of '
                . (self::MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.';
            return $result;
        }

        // Hashes are kept indefinitely to prevent re-import of old REP packets.
        // Uncomment the line below and schedule it via cron if you want periodic cleanup.
        // $this->pruneImportedHashes($userId);

        try {
            $tempDir  = $this->createTempDir();
            $msgPath  = $this->extractMsgFile($zipPath, $tempDir);
            $messages = $this->parseMsgFile($msgPath);

            foreach ($messages as $index => $msg) {
                $confNumber = $msg['conference_number'];
                $conf       = $conferenceMap[(string)$confNumber] ?? null;

                if ($conf === null) {
                    $result['errors'][] = sprintf(
                        'Message %d: conference %d not found in download map — skipped.',
                        $index + 1, $confNumber
                    );
                    $result['skipped']++;
                    continue;
                }

                // Deduplicate: skip messages whose content hash we have already
                // recorded for this user (same REP re-uploaded, or amended REP
                // that still contains previously imported replies).
                $hash = $this->computeMessageHash($userId, $msg);
                if ($this->hashAlreadyImported($userId, $hash)) {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $imported = $this->importReply($msg, $conf, $userId, $messageMap);
                    if ($imported) {
                        $this->recordImportedHash($userId, $hash);
                        $result['imported']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = sprintf(
                        'Message %d ("%s"): %s',
                        $index + 1,
                        substr($msg['subject'], 0, 40),
                        $e->getMessage()
                    );
                    $result['skipped']++;
                }
            }
        } finally {
            if ($tempDir !== null && is_dir($tempDir)) {
                $this->removeTempDir($tempDir);
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // ZIP extraction
    // -------------------------------------------------------------------------

    /**
     * Open the REP ZIP, locate the BBSID.MSG entry, and extract it to
     * a controlled temp directory.
     *
     * @throws \RuntimeException if the archive is invalid or the MSG file
     *                           cannot be located or validated.
     */
    private function extractMsgFile(string $zipPath, string $tempDir): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Failed to open uploaded file as a ZIP archive.');
        }

        $expectedBbsId = strtoupper($this->builder->getBbsId());
        $msgEntry      = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat     = $zip->statIndex($i);
            $entryName = $stat['name'] ?? '';

            // Guard against path traversal entries.
            if (strpbrk($entryName, '/\\') !== false) {
                continue;
            }

            $upper = strtoupper($entryName);
            if (str_ends_with($upper, '.MSG')) {
                $msgEntry = $entryName;
                break;
            }
        }

        if ($msgEntry === null) {
            $zip->close();
            throw new \RuntimeException(
                'No .MSG file found inside the uploaded archive. '
                . 'Expected a file named ' . $expectedBbsId . '.MSG.'
            );
        }

        // Validate BBSID in the filename.
        $baseName     = strtoupper(pathinfo($msgEntry, PATHINFO_FILENAME));
        if ($baseName !== $expectedBbsId) {
            $zip->close();
            throw new \RuntimeException(
                "MSG file BBSID mismatch: found \"{$baseName}\", expected \"{$expectedBbsId}\". "
                . 'Make sure you are uploading a reply packet created for this BBS.'
            );
        }

        $destPath = $tempDir . DIRECTORY_SEPARATOR . basename($msgEntry);
        if ($zip->extractTo($tempDir, $msgEntry) !== true) {
            $zip->close();
            throw new \RuntimeException('Failed to extract MSG file from archive.');
        }
        $zip->close();

        if (!file_exists($destPath)) {
            throw new \RuntimeException('Extracted MSG file not found at expected path.');
        }

        return $destPath;
    }

    // -------------------------------------------------------------------------
    // MSG file parsing
    // -------------------------------------------------------------------------

    /**
     * Parse a BBSID.MSG file into an array of message arrays.
     *
     * Block 0 is the reserved header — skipped.
     * Each subsequent message occupies (block-count) × 128 bytes.
     *
     * @return array  Array of parsed message arrays.
     * @throws \RuntimeException if the file cannot be read or is malformed.
     */
    private function parseMsgFile(string $msgPath): array
    {
        $data = file_get_contents($msgPath);
        if ($data === false) {
            throw new \RuntimeException('Cannot read MSG file.');
        }

        $len = strlen($data);
        if ($len === 0 || $len % self::BLOCK_SIZE !== 0) {
            throw new \RuntimeException(
                "MSG file size ({$len} bytes) is not a multiple of 128 — file is corrupt."
            );
        }

        $messages = [];
        $offset   = self::BLOCK_SIZE;  // Skip block 0 (reserved header).

        while ($offset < $len) {
            $msg = $this->parseMessage($data, $offset);
            if ($msg === null) {
                break;
            }
            $messages[] = $msg;
            $offset     += $msg['_total_blocks'] * self::BLOCK_SIZE;
        }

        return $messages;
    }

    /**
     * Parse one message starting at $offset within $data.
     *
     * Message header block layout:
     *   Offset  Len  Field
     *   0       1    Status byte
     *   1       7    Message number (ASCII)
     *   8       8    Date  (MM-DD-YY)
     *   16      5    Time  (HH:MM)
     *   21      25   To name
     *   46      25   From name
     *   71      25   Subject
     *   96      12   Password (null/space padded)
     *   108     8    Reply-to message number (ASCII)
     *   116     6    Total block count (ASCII, includes this header block)
     *   122     1    Activity flag
     *   123     1    Conference number LSB
     *   124     1    Conference number MSB
     *   125     3    Reserved
     */
    private function parseMessage(string $data, int $offset): ?array
    {
        if ($offset + self::BLOCK_SIZE > strlen($data)) {
            return null;
        }

        $header = substr($data, $offset, self::BLOCK_SIZE);

        $statusByte       = $header[0];
        $msgNumberRaw     = trim(substr($header, 1, 7));
        $dateRaw          = trim(substr($header, 8, 8));
        $timeRaw          = trim(substr($header, 16, 5));
        $toName           = rtrim(substr($header, 21, 25), "\x00");
        $fromName         = rtrim(substr($header, 46, 25), "\x00");
        $subject          = rtrim(substr($header, 71, 25), "\x00");
        $replyToRaw       = trim(substr($header, 108, 8));
        $blockCountRaw    = trim(substr($header, 116, 6));
        $activityFlag     = ord($header[122]);
        $conferenceNumber = ord($header[123]) | (ord($header[124]) << 8);

        // Skip inactive messages.
        if ($activityFlag === 0x00) {
            // If block count is also 0 or 1, treat as end-of-file padding.
            $blockCount = (int)$blockCountRaw;
            if ($blockCount <= 1) {
                return null;
            }
        }

        $blockCount = (int)$blockCountRaw;
        if ($blockCount < 1) {
            // Malformed entry — skip one block and continue.
            return ['_total_blocks' => 1, '_skip' => true, 'conference_number' => $conferenceNumber,
                    'to_name' => '', 'from_name' => '', 'subject' => '', 'body' => '', 'status' => $statusByte];
        }

        // Extract body blocks.
        $bodyBlockCount = $blockCount - 1;
        $bodyOffset     = $offset + self::BLOCK_SIZE;
        $bodyLength     = $bodyBlockCount * self::BLOCK_SIZE;

        if ($bodyOffset + $bodyLength > strlen($data)) {
            // Truncated file — take whatever is left.
            $bodyLength = strlen($data) - $bodyOffset;
        }

        $rawBody = substr($data, $bodyOffset, $bodyLength);
        // Trim trailing null bytes and replace QWK line terminator with \n.
        $rawBody = rtrim($rawBody, "\x00");
        $body    = str_replace(self::QWK_LINE_TERM, "\n", $rawBody);

        // Split QWKE kludge prefix from the body text.
        [$kludgeLines, $cleanBody] = $this->splitQwkeBody($body);

        // Extract plain-text QWKE extended headers (Subject:, To:, From:) written
        // by clients like MultiMail that don't use ^A prefixes for these fields.
        [$extHeaders, $cleanBody] = $this->extractQwkePlaintextHeaders($cleanBody);

        // Attempt to recover charset from QWKE kludges; fall back to CP437→UTF-8.
        $charset   = $this->detectCharset($kludgeLines);
        $cleanBody = $this->normaliseEncoding($cleanBody, $charset);

        // QWKE plain-text Subject: overrides the 25-char fixed header field.
        $subject = $extHeaders['subject'] ?? $subject;

        return [
            '_total_blocks'     => $blockCount,
            '_skip'             => false,
            'status'            => $statusByte,
            'msg_number'        => (int)$msgNumberRaw,
            'date'              => $dateRaw,
            'time'              => $timeRaw,
            'to_name'           => $this->normaliseEncoding(trim($toName), $charset),
            'from_name'         => $this->normaliseEncoding(trim($fromName), $charset),
            'subject'           => $this->normaliseEncoding(trim($subject), $charset),
            'reply_to_num'      => (int)$replyToRaw,
            'conference_number' => $conferenceNumber,
            'kludge_lines'      => $kludgeLines,
            'body'              => $cleanBody,
        ];
    }

    /**
     * Split QWKE ^A-prefixed kludge lines from the top of the message body.
     *
     * Returns [kludge_lines_string, body_without_kludges].
     */
    private function splitQwkeBody(string $body): array
    {
        $lines    = explode("\n", $body);
        $kludges  = [];
        $bodyLines = [];
        $inKludges = true;

        foreach ($lines as $line) {
            if ($inKludges && strlen($line) > 0 && ord($line[0]) === 0x01) {
                $kludges[] = $line;
            } else {
                $inKludges = false;
                $bodyLines[] = $line;
            }
        }

        return [implode("\n", $kludges), implode("\n", $bodyLines)];
    }

    /**
     * Extract plain-text QWKE extended headers (Subject:, To:, From:) from the
     * top of the message body, as written by clients like MultiMail.
     *
     * These headers have no ^A prefix and are followed by a blank line separator.
     * Returns [headers_array, body_with_headers_removed].
     */
    private function extractQwkePlaintextHeaders(string $body): array
    {
        $lines   = explode("\n", $body);
        $headers = [];
        $i       = 0;

        while ($i < count($lines)) {
            if (preg_match('/^(Subject|To|From):\s*(.*)/i', $lines[$i], $m)) {
                $headers[strtolower($m[1])] = rtrim($m[2]);
                $i++;
            } else {
                break;
            }
        }

        // Skip the blank line separator that follows the extended headers.
        if (!empty($headers) && $i < count($lines) && trim($lines[$i]) === '') {
            $i++;
        }

        return [$headers, implode("\n", array_slice($lines, $i))];
    }

    /**
     * Extract the charset label from a QWKE ^ACHRS kludge if present.
     */
    private function detectCharset(string $kludgeLines): ?string
    {
        if (preg_match('/\x01CHRS:\s+(\S+)/i', $kludgeLines, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    /**
     * Normalise $text to UTF-8.
     *
     * If the detected charset is UTF-8 (or null with valid UTF-8), return as-is.
     * Otherwise attempt conversion from CP437 (the traditional QWK encoding).
     */
    private function normaliseEncoding(string $text, ?string $charset): string
    {
        if ($charset === 'UTF-8' || $charset === null) {
            if (mb_check_encoding($text, 'UTF-8')) {
                return $text;
            }
            // Fall through to CP437 conversion.
        }

        // Map QWK charset labels to iconv encoding names.
        // mb_convert_encoding() does not support CP437/CP850; iconv() is required.
        $from = match (strtoupper((string)$charset)) {
            'CP437', 'IBM437', 'PC-8' => 'CP437',
            'CP850', 'IBM850'         => 'CP850',
            'ISO-8859-1', 'LATIN1'   => 'ISO-8859-1',
            default                   => 'CP437',
        };

        $converted = @iconv($from, 'UTF-8//TRANSLIT//IGNORE', $text);
        return ($converted !== false && $converted !== '') ? $converted : $text;
    }

    // -------------------------------------------------------------------------
    // Import
    // -------------------------------------------------------------------------

    /**
     * Import one parsed reply message into BinktermPHP.
     *
     * Conference 0 → sendNetmail via MessageHandler.
     * Conference N → postEchomail via MessageHandler.
     *
     * Returns true if the message was imported, false if it was silently skipped.
     */
    private function importReply(array $msg, array $conf, int $userId, array $messageMap): bool
    {
        if (!empty($msg['_skip'])) {
            return false;
        }

        $subject = $msg['subject'] ?: '(no subject)';
        $body    = rtrim($msg['body']);
        if ($body === '') {
            return false;
        }

        if (!empty($conf['is_netmail'])) {
            return $this->importNetmailReply($msg, $subject, $body, $userId, $messageMap);
        } else {
            return $this->importEchomailReply($msg, $conf, $subject, $body, $userId, $messageMap);
        }
    }

    private function importNetmailReply(array $msg, string $subject, string $body, int $userId, array $messageMap): bool
    {
        $toName = trim($msg['to_name']);
        if ($toName === '') {
            $toName = 'Sysop';
        }

        // Allow "User Name@zone:net/node[.point]" in the To field as a way to
        // specify the FTN destination address when composing new netmail via QWK.
        $embeddedAddress = null;
        if (preg_match('/^(.*?)@(\d+:\d+\/\d+(?:\.\d+)?)$/', $toName, $m)) {
            $embeddedAddress = $m[2];
            $toName          = $m[1] !== '' ? trim($m[1]) : 'Sysop';
        }

        // Resolve to-address: embedded address takes priority, then message map
        // (for replies to received netmail), then fall back to system address.
        $toAddress = $embeddedAddress
            ?? $this->resolveNetmailToAddress($toName, (int)$msg['reply_to_num'], $messageMap);

        // Find the internal reply-to message ID via the message map.
        $replyToId = $this->resolveReplyToId((int)$msg['reply_to_num'], 'netmail', $messageMap);

        $this->messageHandler->sendNetmail(
            $userId,
            $toAddress,
            $toName,
            $subject,
            $body,
            null,        // fromName — resolved by MessageHandler from user record
            $replyToId,  // replyToId
            false,       // crashmail
            null         // tagline
        );

        return true;
    }

    private function importEchomailReply(array $msg, array $conf, string $subject, string $body, int $userId, array $messageMap): bool
    {
        $tag    = (string)($conf['tag']    ?? '');
        $domain = (string)($conf['domain'] ?? '');
        $toName = trim($msg['to_name']) ?: 'All';

        if ($tag === '') {
            throw new \RuntimeException('Conference has no echo area tag — cannot post.');
        }

        // Find the internal reply-to message ID via the message map.
        $replyToId = $this->resolveReplyToId((int)$msg['reply_to_num'], 'echomail', $messageMap);

        $this->messageHandler->postEchomail(
            $userId,
            $tag,
            $domain,
            $toName,
            $subject,
            $body,
            $replyToId,  // replyToId
            null         // tagline
        );

        return true;
    }

    /**
     * Resolve a QWK logical message number to a BinktermPHP DB id for reply
     * threading.  Looks up the per-user qwk_message_index table.
     *
     * Returns null if the number is 0, not found, or is for a different type.
     */
    private function resolveReplyToId(int $logicalNumber, string $type, array $messageIndex): ?int
    {
        if ($logicalNumber === 0) {
            return null;
        }

        $entry = $messageIndex[$logicalNumber] ?? null;
        if ($entry === null || $entry['type'] !== $type) {
            return null;
        }

        $id = (int)$entry['db_id'];
        return $id > 0 ? $id : null;
    }

    /**
     * Resolve the To FTN address for an outbound netmail reply.
     *
     * Looks up reply_to_num in the per-user qwk_message_index to find the
     * from_address of the original message.  Falls back to the system address
     * for new netmails or when no matching index entry exists.
     */
    private function resolveNetmailToAddress(string $toName, int $replyToNum, array $messageIndex): string
    {
        if ($replyToNum > 0) {
            $entry = $messageIndex[$replyToNum] ?? null;
            if ($entry !== null
                && $entry['type'] === 'netmail'
                && !empty($entry['from_address'])
            ) {
                return $entry['from_address'];
            }
        }

        try {
            return \BinktermPHP\Binkp\Config\BinkpConfig::getInstance()->getSystemAddress();
        } catch (\Exception $e) {
            return '1:999/999';
        }
    }

    // -------------------------------------------------------------------------
    // Download log / message index
    // -------------------------------------------------------------------------

    /**
     * Load the conference map from the most recent download log entry and the
     * per-user message index from qwk_message_index.
     *
     * Returns [conferenceMap, messageIndex] where conferenceMap is null if no
     * prior download exists.  messageIndex is keyed by integer qwk_msg_num.
     */
    private function getLatestDownloadMaps(int $userId): array
    {
        // Conference map — most recent download.
        $stmt = $this->db->prepare("
            SELECT conference_map
            FROM qwk_download_log
            WHERE user_id = ?
            ORDER BY downloaded_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['conference_map'])) {
            return [null, []];
        }

        $conferenceMap = json_decode($row['conference_map'], true);
        if (!is_array($conferenceMap)) {
            return [null, []];
        }

        // Message index — single table, always current for this user.
        $stmt = $this->db->prepare("
            SELECT qwk_msg_num, type, db_id, from_address
            FROM qwk_message_index
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $messageIndex = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $messageIndex[(int)$r['qwk_msg_num']] = [
                'type'         => $r['type'],
                'db_id'        => (int)$r['db_id'],
                'from_address' => $r['from_address'],
            ];
        }

        return [$conferenceMap, $messageIndex];
    }

    // -------------------------------------------------------------------------
    // Deduplication helpers
    // -------------------------------------------------------------------------

    /**
     * Compute a SHA-256 content hash for a parsed REP message.
     *
     * The hash covers the fields the user authored — conference number,
     * recipient, subject, and body — so identical replies are detected
     * regardless of when or how many times the REP is uploaded.
     */
    private function computeMessageHash(int $userId, array $msg): string
    {
        $parts = implode('|', [
            $userId,
            $msg['conference_number'],
            mb_strtolower(trim($msg['to_name'])),
            mb_strtolower(trim($msg['subject'])),
            trim($msg['body']),
        ]);
        return hash('sha256', $parts);
    }

    private function hashAlreadyImported(int $userId, string $hash): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM qwk_imported_hashes WHERE user_id = ? AND msg_hash = ? LIMIT 1"
        );
        $stmt->execute([$userId, $hash]);
        return (bool)$stmt->fetchColumn();
    }

    private function recordImportedHash(int $userId, string $hash): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO qwk_imported_hashes (user_id, msg_hash, imported_at)
             VALUES (?, ?, NOW())
             ON CONFLICT (user_id, msg_hash) DO NOTHING"
        );
        $stmt->execute([$userId, $hash]);
    }

    /**
     * Remove hash entries older than 1 year for this user.
     * Called once per upload to keep the table from growing indefinitely.
     */
    private function pruneImportedHashes(int $userId): void
    {
        $this->db->prepare(
            "DELETE FROM qwk_imported_hashes
              WHERE user_id = ? AND imported_at < NOW() - INTERVAL '1 year'"
        )->execute([$userId]);
    }

    // -------------------------------------------------------------------------
    // Filesystem helpers
    // -------------------------------------------------------------------------

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'binkterm_rep_' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Cannot create temporary directory for REP extraction.');
        }
        return $dir;
    }

    private function removeTempDir(string $dir): void
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($files as $file) {
            is_file($file) ? @unlink($file) : $this->removeTempDir($file);
        }
        @rmdir($dir);
    }
}
