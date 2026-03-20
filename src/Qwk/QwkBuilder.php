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

use BinktermPHP\BbsConfig;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Database;
use BinktermPHP\EchoareaSubscriptionManager;
use BinktermPHP\Version;
use PDO;
use ZipArchive;

/**
 * QwkBuilder
 *
 * Assembles a QWK offline mail packet (BBSID.QWK) for a given user.
 *
 * Packet structure:
 *   CONTROL.DAT  — plain-text BBS/conference header
 *   DOOR.ID      — capability declaration (CONTROLTYPE QWKE)
 *   MESSAGES.DAT — binary 128-byte-block message file
 *
 * QWKE extensions (^A-prefixed kludge lines) are written into the message
 * body prefix so that capable offline readers can display full FidoNet metadata,
 * while legacy readers see clean plain text in the body.
 *
 * Conference numbering:
 *   0          → Personal Mail (netmail addressed to this user)
 *   1 … N      → Subscribed echo areas, ordered as returned by
 *                 EchoareaSubscriptionManager::getUserSubscribedEchoareas()
 *
 * The conference map is persisted to qwk_download_log so that RepProcessor
 * can reverse-map conference numbers when a REP packet is later uploaded.
 */
class QwkBuilder
{
    private const QWK_LINE_TERMINATOR = "\xE3";
    private const BLOCK_SIZE          = 128;
    private const ACTIVE_FLAG         = 0xE1;

    private PDO $db;
    private BinkpConfig $binkpConfig;

    public function __construct()
    {
        $this->db          = Database::getInstance()->getPdo();
        $this->binkpConfig = BinkpConfig::getInstance();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Build a complete QWK packet for the given user.
     *
     * @param bool $qwke  When true, includes QWKE extensions (^A kludge lines,
     *                    CONTROLTYPE = QWKE in DOOR.ID, UTF-8 body encoding).
     *                    When false, produces a plain QWK packet with CP437 encoding
     *                    compatible with readers such as MultiMail.
     *
     * Returns the filesystem path to a temporary ZIP file.  The caller is
     * responsible for streaming it to the browser and then deleting it.
     *
     * @throws \RuntimeException on failure to build or write the packet.
     */
    /** Maximum messages fetchable in a single download regardless of $limit. */
    public const MAX_MESSAGES_HARD_CAP = 10000;

    /**
     * Build a complete QWK packet for the given user.
     *
     * @param int  $limit  Maximum messages to include across all conferences.
     *                     Capped server-side at MAX_MESSAGES_HARD_CAP.
     *                     0 means "use the configured default".
     */
    public function buildPacket(int $userId, bool $qwke = false, int $limit = 0): string
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new \RuntimeException('User not found.');
        }

        $configDefault = (int)(BbsConfig::getConfig()['qwk']['max_messages_per_download'] ?? 2500);
        $maxMessages   = $limit > 0 ? $limit : $configDefault;
        $maxMessages   = min($maxMessages, self::MAX_MESSAGES_HARD_CAP);
        $conferences   = $this->buildConferenceList($userId);

        // Fetch new messages per conference, respecting the per-download limit.
        [$conferenceMessages, $lastIds] = $this->fetchConferenceMessages($userId, $conferences, $maxMessages);

        // Build in-memory file contents.
        $controlDat              = $this->buildControlDat($user, $conferences, $conferenceMessages);
        $doorId                  = $this->buildDoorId($qwke);
        [$messagesDat, $messageMap] = $this->buildMessagesDat($conferences, $conferenceMessages, $qwke);

        // Write to a temp ZIP.
        $bbsId    = $this->getBbsId();
        $zipPath  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $bbsId . '_' . bin2hex(random_bytes(8)) . '.qwk';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create QWK archive at: ' . $zipPath);
        }

        $zip->addFromString('CONTROL.DAT',  $controlDat);
        $zip->addFromString('DOOR.ID',      $doorId);
        $zip->addFromString('MESSAGES.DAT', $messagesDat);
        $zip->close();

        // Persist state so RepProcessor can resolve conference numbers later.
        $totalMessages = 0;
        foreach ($conferenceMessages as $msgs) {
            $totalMessages += count($msgs);
        }

        $conferenceMap = $this->buildConferenceMapJson($conferences);
        $this->persistDownloadLog($userId, $totalMessages, filesize($zipPath), $conferenceMap);
        $this->persistMessageIndex($userId, $messageMap);
        $this->updateConferenceStateWithList($userId, $conferences, $lastIds);

        return $zipPath;
    }

    /**
     * Derive the 8-character BBSID from the system name.
     *
     * Strips everything that is not an ASCII letter or digit, uppercases,
     * and truncates to 8 characters.  Falls back to "BINKTERM" if the
     * system name contains no usable characters.
     */
    public function getBbsId(): string
    {
        $name = $this->binkpConfig->getSystemName();
        $id   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
        $id   = substr($id, 0, 8);
        return $id !== '' ? $id : 'BINKTERM';
    }

    // -------------------------------------------------------------------------
    // Conference list
    // -------------------------------------------------------------------------

    /**
     * Build the ordered conference list for this user.
     *
     * Index 0 is always the personal mail (netmail) conference.
     * Indices 1–N are the user's subscribed echo areas.
     *
     * Each element is an array:
     *   ['number' => int, 'name' => string, 'echoarea_id' => int|null,
     *    'tag' => string|null, 'domain' => string|null, 'is_netmail' => bool]
     */
    private function buildConferenceList(int $userId): array
    {
        $conferences = [];

        // Conference 0: personal mail / netmail.
        $conferences[] = [
            'number'      => 0,
            'name'        => 'Personal Mail',
            'echoarea_id' => null,
            'tag'         => null,
            'domain'      => null,
            'is_netmail'  => true,
        ];

        // Conferences 1–N: subscribed echo areas.
        $subscriptionManager = new EchoareaSubscriptionManager();
        $echoareas           = $subscriptionManager->getUserSubscribedEchoareas($userId);
        $conferenceNumbers   = $this->getOrCreateConferenceNumbers($userId, $echoareas);

        usort($echoareas, function(array $a, array $b) use ($conferenceNumbers) {
            return ($conferenceNumbers[(int)$a['id']] ?? PHP_INT_MAX)
                <=> ($conferenceNumbers[(int)$b['id']] ?? PHP_INT_MAX);
        });

        foreach ($echoareas as $area) {
            $number = $conferenceNumbers[(int)$area['id']] ?? null;
            if ($number === null) {
                continue;
            }

            $name = strtoupper($area['tag']);
            if (!empty($area['domain'])) {
                $name .= '@' . strtoupper($area['domain']);
            }
            // QWK conference names are limited to 13 characters in some readers;
            // we truncate to 13 but keep the full name available internally.
            $conferences[] = [
                'number'      => $number,
                'name'        => substr($name, 0, 13),
                'echoarea_id' => (int)$area['id'],
                'tag'         => $area['tag'],
                'domain'      => $area['domain'] ?? '',
                'is_netmail'  => false,
            ];
        }

        return $conferences;
    }

    /**
     * Return persistent conference numbers for this user's subscribed
     * echoareas, allocating new numbers only for areas not yet mapped.
     *
     * @param array $echoareas
     * @return array<int,int>
     */
    private function getOrCreateConferenceNumbers(int $userId, array $echoareas): array
    {
        $stmt = $this->db->prepare("
            SELECT echoarea_id, conference_number
            FROM qwk_user_conference_map
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);

        $numbersByArea = [];
        $usedNumbers   = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $echoareaId = (int)$row['echoarea_id'];
            $conferenceNumber = (int)$row['conference_number'];
            $numbersByArea[$echoareaId] = $conferenceNumber;
            $usedNumbers[$conferenceNumber] = true;
        }

        if (empty($echoareas)) {
            return $numbersByArea;
        }

        $insertStmt = $this->db->prepare("
            INSERT INTO qwk_user_conference_map (user_id, echoarea_id, conference_number, created_at, updated_at)
            VALUES (:user_id, :echoarea_id, :conference_number, NOW(), NOW())
            ON CONFLICT (user_id, echoarea_id)
            DO UPDATE SET updated_at = NOW()
        ");

        foreach ($echoareas as $area) {
            $echoareaId = (int)$area['id'];
            if (isset($numbersByArea[$echoareaId])) {
                continue;
            }

            $conferenceNumber = 1;
            while (isset($usedNumbers[$conferenceNumber])) {
                $conferenceNumber++;
            }

            $insertStmt->execute([
                ':user_id' => $userId,
                ':echoarea_id' => $echoareaId,
                ':conference_number' => $conferenceNumber,
            ]);

            $numbersByArea[$echoareaId] = $conferenceNumber;
            $usedNumbers[$conferenceNumber] = true;
        }

        return $numbersByArea;
    }

    // -------------------------------------------------------------------------
    // CONTROL.DAT
    // -------------------------------------------------------------------------

    /**
     * Generate CONTROL.DAT content.
     *
     * Line layout (0-indexed):
     *   0  BBS name
     *   1  City, State (or Location)
     *   2  Phone / hostname
     *   3  Sysop name
     *   4  0,<BBSID>
     *   5  Packet creation date/time  (MM-DD-YY,HH:MM:SS)
     *   6  User's real name
     *   7  Menu filename (blank)
     *   8  FidoNet netmail conference number (always "0")
     *   9  Total new messages in packet
     *   10 Highest conference number (= total conferences minus 1)
     *   11… Two lines per conference: conference number, then conference name.
     *
     * All lines are terminated with \r\n per the QWK specification.
     */
    private function buildControlDat(array $user, array $conferences, array $conferenceMessages): string
    {
        $bbsName  = $this->binkpConfig->getSystemName();
        $location = $this->binkpConfig->getSystemLocation() ?: 'Unknown';
        $hostname = $this->binkpConfig->getSystemHostname() ?: 'localhost';
        $sysop    = $this->binkpConfig->getSystemSysop();
        $bbsId    = $this->getBbsId();
        $userName = $user['real_name'] ?: $user['username'];
        $now      = new \DateTime('now', new \DateTimeZone('UTC'));
        $dateStr  = $now->format('m-d-y,H:i:s');  // QWK spec: MM-DD-YY,HH:MM:SS

        $totalMessages = 0;
        foreach ($conferenceMessages as $msgs) {
            $totalMessages += count($msgs);
        }

        $lines   = [];
        $lines[] = $bbsName;
        $lines[] = $location;
        $lines[] = $hostname;
        $lines[] = $sysop;
        $lines[] = '0,' . $bbsId;
        $lines[] = $dateStr;
        $lines[] = $userName;
        $lines[] = '';        // line 8: menu filename (blank)
        $lines[] = '0';       // line 9: FidoNet netmail conference number (required, always 0)
        $lines[] = (string)$totalMessages;
        $lines[] = (string)(count($conferences) - 1);

        // All conferences are listed, including conference 0 (Personal Mail).
        // Each conference occupies two lines: the conference number then the name.
        foreach ($conferences as $conf) {
            $lines[] = (string)$conf['number'];
            $lines[] = $conf['name'];
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    // -------------------------------------------------------------------------
    // DOOR.ID
    // -------------------------------------------------------------------------

    /**
     * Generate DOOR.ID content.
     *
     * CONTROLTYPE = QWKE is only included when $qwke is true; plain QWK readers
     * such as MultiMail do not understand this line and may misbehave when present.
     */
    private function buildDoorId(bool $qwke): string
    {
        $name    = $this->binkpConfig->getSystemName();
        $version = Version::getVersion();

        $lines = [
            'DOOR = BinktermPHP',
            'VERSION = ' . $version,
            'SYSTEM = ' . $name,
            'NETWORK = FidoNet',
            'CONTROLNAME = CONTROL.DAT',
        ];

        if ($qwke) {
            $lines[] = 'CONTROLTYPE = QWKE';
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    // -------------------------------------------------------------------------
    // MESSAGES.DAT
    // -------------------------------------------------------------------------

    /**
     * Build the MESSAGES.DAT binary content.
     *
     * Block 0 is the reserved header block.  Subsequent blocks contain
     * the encoded messages in conference order.
     */
    /**
     * Build the MESSAGES.DAT binary content.
     *
     * Block 0 is the reserved header block.  Subsequent blocks contain
     * the encoded messages in conference order.
     *
     * Returns [string $dat, array $messageMap] where $messageMap is keyed by
     * the 1-based logical QWK message number and each value contains:
     *   'type'         => 'netmail'|'echomail'
     *   'id'           => (int) database primary key
     *   'from_address' => (string|null) FTN address of the original sender
     */
    private function buildMessagesDat(array $conferences, array $conferenceMessages, bool $qwke): array
    {
        // Block 0: reserved header.
        $header = str_pad('Produced by BinktermPHP v' . Version::getVersion(), self::BLOCK_SIZE, "\x00");
        $data   = substr($header, 0, self::BLOCK_SIZE);

        // Global logical message counter (1-based, sequential across all conferences).
        $logicalNumber = 1;
        $messageMap    = [];

        foreach ($conferences as $conf) {
            $confNumber = $conf['number'];
            $messages   = $conferenceMessages[$confNumber] ?? [];

            foreach ($messages as $msg) {
                $data .= $this->encodeMessage($msg, $confNumber, $logicalNumber, ' ', $qwke);

                $messageMap[$logicalNumber] = [
                    'type'         => !empty($msg['_is_netmail']) ? 'netmail' : 'echomail',
                    'id'           => (int)($msg['id'] ?? 0),
                    'from_address' => $msg['from_address'] ?? null,
                ];

                $logicalNumber++;
            }
        }

        return [$data, $messageMap];
    }

    /**
     * Encode one message into one or more 128-byte blocks.
     *
     * Message header block layout (128 bytes total):
     *   Offset  Len  Field
     *   0       1    Status byte (' ' = public, '+' = private/netmail)
     *   1       7    Message number (ASCII, space-padded)
     *   8       8    Date  (MM-DD-YY)
     *   16      5    Time  (HH:MM)
     *   21      25   To name (null-padded)
     *   46      25   From name (null-padded)
     *   71      25   Subject (null-padded)
     *   96      12   Password (null-padded, blank)
     *   108     8    Reply-to message number (ASCII, '0' if none)
     *   116     6    Block count — number of 128-byte blocks this message
     *                occupies INCLUDING this header block (ASCII)
     *   122     1    Activity flag (0xE1 = active)
     *   123     1    Conference number LSB
     *   124     1    Conference number MSB
     *   125     3    Reserved (null)
     *
     * Body blocks: raw text with \xE3 as the line separator, packed into
     * 128-byte blocks, final block null-padded.
     *
     * QWKE kludge lines (^A-prefixed) are prepended to the body text before
     * encoding so that capable readers can extract extended header fields.
     */
    private function encodeMessage(
        array  $message,
        int    $conferenceNumber,
        int    $logicalNumber,
        string $statusByte = ' ',
        bool   $qwke = false
    ): string {
        // --- Derive fields ---
        $toName      = substr((string)($message['to_name']   ?? 'All'),   0, 25);
        $fromName    = substr((string)($message['from_name'] ?? 'Unknown'), 0, 25);
        $subject     = substr((string)($message['subject']   ?? ''),       0, 25);
        $isNetmail   = !empty($message['_is_netmail']);
        $statusByte  = $isNetmail ? '+' : ' ';
        $replyToNum  = (string)($message['_reply_logical'] ?? 0);

        // Date/time — prefer date_written, fall back to date_received.
        $dateField = $message['date_written'] ?? $message['date_received'] ?? null;
        if ($dateField) {
            try {
                $dt = new \DateTime($dateField, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                $dt = new \DateTime('now', new \DateTimeZone('UTC'));
            }
        } else {
            $dt = new \DateTime('now', new \DateTimeZone('UTC'));
        }
        $dateStr = $dt->format('m-d-y');   // MM-DD-YY
        $timeStr = $dt->format('H:i');     // HH:MM

        // --- Build body ---
        $bodyText = (string)($message['message_text'] ?? '');

        // Strip any embedded kludge lines from the stored body.
        $bodyText = $this->stripKludgeLines($bodyText);

        if ($qwke) {
            // Prepend QWKE kludge lines and keep body as UTF-8.
            $combined = $this->buildQwkePrefix($message) . $bodyText;
        } else {
            // Plain QWK: convert body to CP437 via iconv (mbstring does not support CP437).
            // TRANSLIT replaces unmappable characters with nearest equivalents; IGNORE drops the rest.
            $cp437 = @iconv('UTF-8', 'CP437//TRANSLIT//IGNORE', $bodyText);
            $combined = ($cp437 !== false && $cp437 !== '') ? $cp437 : $bodyText;
        }

        // Normalise line endings then replace with QWK line terminator.
        $combined = str_replace(["\r\n", "\r", "\n"], "\xE3", rtrim($combined));
        $combined .= "\xE3";  // trailing terminator

        // --- Pack body into 128-byte blocks ---
        $bodyBlockCount = (int)ceil(strlen($combined) / self::BLOCK_SIZE);
        if ($bodyBlockCount < 1) {
            $bodyBlockCount = 1;
        }
        $totalBlocks = 1 + $bodyBlockCount;  // 1 header block + body blocks

        // --- Assemble 128-byte header block ---
        // Field layout (must match qwkmsg_header struct in MultiMail and QWK spec):
        //   status(1) msgnum(7) date(8) time(5) to(25) from(25) subject(25)
        //   password(12) refnum(8) chunks(6) alive(1) confLSB(1) confMSB(1) res(3)
        $header  = $statusByte;
        $header .= str_pad((string)$logicalNumber, 7, ' ', STR_PAD_LEFT);
        $header .= str_pad($dateStr, 8, "\x00");
        $header .= str_pad($timeStr, 5, "\x00");
        $header .= str_pad($toName,   25, "\x00");
        $header .= str_pad($fromName, 25, "\x00");
        $header .= str_pad($subject,  25, "\x00");
        $header .= str_pad('',        12, "\x00");   // password (12 bytes per QWK spec)
        $header .= str_pad($replyToNum, 8, ' ', STR_PAD_LEFT);
        $header .= str_pad((string)$totalBlocks, 6, ' ', STR_PAD_LEFT);
        $header .= chr(self::ACTIVE_FLAG);           // alive byte
        $header .= chr($conferenceNumber & 0xFF);    // confLSB
        $header .= chr(($conferenceNumber >> 8) & 0xFF); // confMSB
        $header .= "\x00\x00\x00";                  // res[3]

        // Sanity-check: header block must be exactly 128 bytes.
        if (strlen($header) !== self::BLOCK_SIZE) {
            throw new \LogicException(
                'QWK header block is ' . strlen($header) . ' bytes, expected 128.'
            );
        }

        // --- Pad and assemble body blocks ---
        $paddedBody = str_pad($combined, $bodyBlockCount * self::BLOCK_SIZE, "\x00");

        return $header . $paddedBody;
    }

    /**
     * Build the QWKE kludge prefix lines for one message.
     *
     * Lines are emitted as ^A (0x01) + field-name + ': ' + value + \n.
     * The ^ACHRS line is always present to signal UTF-8 encoding.
     */
    private function buildQwkePrefix(array $message): string
    {
        $lines = [];

        // Character set — always UTF-8.
        $lines[] = "\x01CHRS: UTF-8 4";

        // Emit stored kludge lines verbatim (they already contain ^A prefixes
        // and cover MSGID, REPLY, TZUTC, INTL, etc.).
        $storedKludges = trim((string)($message['kludge_lines'] ?? ''));
        if ($storedKludges !== '') {
            // Normalise line endings and re-emit each kludge line.
            foreach (preg_split('/\r\n|\r|\n/', $storedKludges) as $kludgeLine) {
                $kludgeLine = rtrim($kludgeLine);
                if ($kludgeLine === '') {
                    continue;
                }
                // Ensure the ^A prefix is present (it should already be, but be defensive).
                if (ord($kludgeLine[0]) !== 0x01) {
                    $kludgeLine = "\x01" . $kludgeLine;
                }
                $lines[] = $kludgeLine;
            }
        }

        // QWKE extended FROM / TO lines carrying the FidoNet addresses.
        if (!empty($message['from_address'])) {
            $lines[] = "\x01FROM: " . ($message['from_name'] ?? '') . ' <' . $message['from_address'] . '>';
        }
        if (!empty($message['to_address'])) {
            $lines[] = "\x01TO: " . ($message['to_name'] ?? '') . ' <' . $message['to_address'] . '>';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Strip ^A-prefixed kludge lines from stored message text.
     *
     * BinktermPHP stores kludges separately in kludge_lines, but older
     * imported messages may have them embedded in message_text as well.
     */
    private function stripKludgeLines(string $text): string
    {
        $lines  = preg_split('/\r\n|\r|\n/', $text);
        $result = [];
        foreach ($lines as $line) {
            if (strlen($line) > 0 && ord($line[0]) === 0x01) {
                continue;
            }
            $result[] = $line;
        }
        return implode("\n", $result);
    }

    // -------------------------------------------------------------------------
    // Message fetching
    // -------------------------------------------------------------------------

    /**
     * Fetch new messages for all conferences since the user's last download.
     *
     * Returns:
     *   [0] array  keyed by conference number → array of message rows
     *   [1] array  keyed by conference number → highest id fetched (for state update)
     */
    private function fetchConferenceMessages(int $userId, array $conferences, int $maxMessages): array
    {
        $conferenceMessages = [];
        $lastIds            = [];
        $remaining          = $maxMessages;

        foreach ($conferences as $conf) {
            if ($remaining <= 0) {
                $conferenceMessages[$conf['number']] = [];
                $lastIds[$conf['number']]            = $this->getLastId($userId, $conf);
                continue;
            }

            if ($conf['is_netmail']) {
                [$msgs, $lastId] = $this->fetchNetmail($userId, $remaining);
            } else {
                [$msgs, $lastId] = $this->fetchEchomail($userId, (int)$conf['echoarea_id'], $remaining);
            }

            $conferenceMessages[$conf['number']] = $msgs;
            $lastIds[$conf['number']]            = $lastId;
            $remaining                          -= count($msgs);
        }

        return [$conferenceMessages, $lastIds];
    }

    /**
     * Fetch new netmail rows addressed to the user since last download.
     */
    private function fetchNetmail(int $userId, int $limit): array
    {
        $user   = $this->getUserById($userId);
        $lastId = $this->getNetmailLastId($userId);

        try {
            $myAddresses   = $this->binkpConfig->getMyAddresses();
            $myAddresses[] = $this->binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            $myAddresses = [];
        }

        if (empty($myAddresses)) {
            return [[], $lastId];
        }

        $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
        $params              = [(int)$lastId, $user['username'], $user['real_name']];
        $params              = array_merge($params, $myAddresses, [$limit]);

        $stmt = $this->db->prepare("
            SELECT n.id, n.from_name, n.from_address, n.to_name, n.to_address,
                   n.subject, n.date_written, n.date_received, n.message_text,
                   n.kludge_lines, n.message_id, n.reply_to_id
            FROM netmail n
            WHERE n.id > ?
              AND (
                    LOWER(n.to_name) = LOWER(?)
                 OR LOWER(n.to_name) = LOWER(?)
              )
              AND n.to_address IN ({$addressPlaceholders})
              AND n.deleted_by_recipient IS NOT TRUE
            ORDER BY n.id ASC
            LIMIT ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['_is_netmail'] = true;
        }
        unset($row);

        $newLastId = !empty($rows) ? (int)end($rows)['id'] : $lastId;
        return [$rows, $newLastId];
    }

    /**
     * Fetch new echomail rows for one area since last download.
     */
    private function fetchEchomail(int $userId, int $echoareaId, int $limit): array
    {
        $lastId = $this->getEchomailLastId($userId, $echoareaId);

        $stmt = $this->db->prepare("
            SELECT em.id, em.from_name, em.from_address, em.to_name,
                   em.subject, em.date_written, em.date_received, em.message_text,
                   em.kludge_lines, em.message_id, em.reply_to_id
            FROM echomail em
            WHERE em.echoarea_id = ?
              AND em.id > ?
            ORDER BY em.id ASC
            LIMIT ?
        ");
        $stmt->execute([$echoareaId, (int)$lastId, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['_is_netmail'] = false;
        }
        unset($row);

        $newLastId = !empty($rows) ? (int)end($rows)['id'] : $lastId;
        return [$rows, $newLastId];
    }

    // -------------------------------------------------------------------------
    // State management
    // -------------------------------------------------------------------------

    private function getLastId(int $userId, array $conf): int
    {
        return $conf['is_netmail']
            ? $this->getNetmailLastId($userId)
            : $this->getEchomailLastId($userId, (int)$conf['echoarea_id']);
    }

    private function getNetmailLastId(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT last_msg_id FROM qwk_conference_state
            WHERE user_id = ? AND is_netmail = TRUE
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['last_msg_id'] : 0;
    }

    private function getEchomailLastId(int $userId, int $echoareaId): int
    {
        $stmt = $this->db->prepare("
            SELECT last_msg_id FROM qwk_conference_state
            WHERE user_id = ? AND echoarea_id = ? AND is_netmail = FALSE
        ");
        $stmt->execute([$userId, $echoareaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['last_msg_id'] : 0;
    }

    /**
     * Update state using the concrete conference list so we can map conf
     * numbers → echoarea_ids without relying on any cache.
     */
    private function updateConferenceStateWithList(int $userId, array $conferences, array $lastIds): void
    {
        $upsertNetmail = $this->db->prepare("
            INSERT INTO qwk_conference_state (user_id, echoarea_id, is_netmail, last_msg_id, updated_at)
            VALUES (:user_id, NULL, TRUE, :last_msg_id, NOW())
            ON CONFLICT (user_id, is_netmail) WHERE is_netmail = TRUE
            DO UPDATE SET last_msg_id = GREATEST(qwk_conference_state.last_msg_id, EXCLUDED.last_msg_id),
                          updated_at  = NOW()
        ");

        $upsertEchomail = $this->db->prepare("
            INSERT INTO qwk_conference_state (user_id, echoarea_id, is_netmail, last_msg_id, updated_at)
            VALUES (:user_id, :echoarea_id, FALSE, :last_msg_id, NOW())
            ON CONFLICT (user_id, echoarea_id) WHERE is_netmail = FALSE AND echoarea_id IS NOT NULL
            DO UPDATE SET last_msg_id = GREATEST(qwk_conference_state.last_msg_id, EXCLUDED.last_msg_id),
                          updated_at  = NOW()
        ");

        // Build a quick lookup: confNumber → conference entry.
        $confByNumber = [];
        foreach ($conferences as $conf) {
            $confByNumber[$conf['number']] = $conf;
        }

        foreach ($lastIds as $confNumber => $lastId) {
            $conf = $confByNumber[$confNumber] ?? null;
            if (!$conf) {
                continue;
            }

            if ($conf['is_netmail']) {
                $upsertNetmail->execute([
                    ':user_id'     => $userId,
                    ':last_msg_id' => $lastId,
                ]);
            } else {
                $upsertEchomail->execute([
                    ':user_id'     => $userId,
                    ':echoarea_id' => $conf['echoarea_id'],
                    ':last_msg_id' => $lastId,
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Download log
    // -------------------------------------------------------------------------

    /**
     * Build a JSON object mapping conference numbers to their conference metadata.
     * This is persisted in qwk_download_log so RepProcessor can reverse-map.
     */
    private function buildConferenceMapJson(array $conferences): string
    {
        $map = [];
        foreach ($conferences as $conf) {
            $map[(string)$conf['number']] = [
                'name'        => $conf['name'],
                'is_netmail'  => $conf['is_netmail'],
                'echoarea_id' => $conf['echoarea_id'],
                'tag'         => $conf['tag'],
                'domain'      => $conf['domain'],
            ];
        }
        return json_encode($map, JSON_UNESCAPED_UNICODE);
    }

    private function persistDownloadLog(
        int    $userId,
        int    $messageCount,
        int    $packetSize,
        string $conferenceMap
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO qwk_download_log (user_id, downloaded_at, message_count, packet_size, conference_map)
            VALUES (:user_id, NOW(), :message_count, :packet_size, :conference_map::jsonb)
        ");
        $stmt->execute([
            ':user_id'        => $userId,
            ':message_count'  => $messageCount,
            ':packet_size'    => $packetSize,
            ':conference_map' => $conferenceMap,
        ]);
    }

    /**
     * Replace the per-user message index with the entries from the current
     * download.  Keyed by 1-based QWK logical message number.
     *
     * @param array $messageMap  [qwk_msg_num => ['type', 'id', 'from_address']]
     */
    private function persistMessageIndex(int $userId, array $messageMap): void
    {
        $this->db->prepare(
            "DELETE FROM qwk_message_index WHERE user_id = ?"
        )->execute([$userId]);

        if (empty($messageMap)) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO qwk_message_index (user_id, qwk_msg_num, type, db_id, from_address)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($messageMap as $num => $entry) {
            $stmt->execute([
                $userId,
                (int)$num,
                $entry['type'],
                $entry['id'],
                $entry['from_address'] ?? null,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, username, real_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
