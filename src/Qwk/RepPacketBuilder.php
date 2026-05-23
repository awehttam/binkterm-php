<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Version;
use ZipArchive;

/**
 * Serializes outbound inter-BBS QWK replies into a mailbox `.REP` archive.
 *
 * This is distinct from `RepProcessor`, which imports a user's offline-reader
 * REP upload back into this same BBS.
 *
 * Used by: Inter-BBS
 */
class RepPacketBuilder
{
    private const BLOCK_SIZE = 128;
    private const LINE_TERM = "\xE3";
    private const BODY_CHARSET = 'CP437';

    /**
     * @param array<int,array<string,mixed>> $messages
     */
    public function build(string $bbsId, array $messages): string
    {
        $normalizedBbsId = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $bbsId), 0, 8));
        if ($normalizedBbsId === '') {
            throw new \InvalidArgumentException('REP packet BBS ID is required');
        }

        // REP block 0 must begin with the destination BBSID and be space-padded.
        $msgData = str_pad($normalizedBbsId, self::BLOCK_SIZE, ' ');
        $headersDat = [];
        $logical = 1;
        $conferenceLogicalNumbers = [];
        foreach ($messages as $message) {
            $confNumber = (int)($message['conference_number'] ?? 0);
            $conferenceLogicalNumbers[$confNumber] = ($conferenceLogicalNumbers[$confNumber] ?? 0) + 1;
            $headerOffset = strlen($msgData);
            $headersDat[$headerOffset] = $this->buildHeadersDatSection($message, $confNumber);
            $msgData .= $this->encodeMessage($message, $logical, $conferenceLogicalNumbers[$confNumber]);
            $logical++;
        }

        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $normalizedBbsId . '_' . bin2hex(random_bytes(8)) . '.rep';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create REP archive');
        }
        $zip->addFromString($normalizedBbsId . '.MSG', $msgData);
        if ($headersDat !== []) {
            $zip->addFromString('HEADERS.DAT', $this->buildHeadersDat($headersDat));
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * @param array<string,mixed> $message
     */
    private function encodeMessage(array $message, int $logicalNumber, int $conferenceLogicalNumber): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], self::LINE_TERM, rtrim((string)$message['body'])) . self::LINE_TERM;
        $cp437 = @iconv('UTF-8', self::BODY_CHARSET . '//TRANSLIT//IGNORE', $body);
        $body = ($cp437 !== false && $cp437 !== '') ? $cp437 : $body;

        $bodyBlockCount = max(1, (int)ceil(strlen($body) / self::BLOCK_SIZE));
        $totalBlocks = $bodyBlockCount + 1;
        $date = new \DateTime('now', new \DateTimeZone('UTC'));
        $confNumber = (int)$message['conference_number'];
        $status = $confNumber === 0 ? '+' : ' ';

        // REP uses the same 128-byte header layout as QWK MESSAGES.DAT:
        // 8-byte password, 8-byte reply reference, 6-byte block count,
        // then activity/conf/logical-in-conf/net-tag bytes.
        $header = $status;
        // Synchronet parses the 7-byte ASCII conference field with atol(),
        // so the number must be left-justified with trailing spaces.
        $header .= str_pad((string)$confNumber, 7, ' ', STR_PAD_RIGHT);
        $header .= str_pad($date->format('m-d-y'), 8, "\x00");
        $header .= str_pad($date->format('H:i'), 5, "\x00");
        $header .= str_pad(substr((string)$message['to_name'], 0, 25), 25, "\x00");
        $header .= str_pad(substr((string)$message['from_name'], 0, 25), 25, "\x00");
        $header .= str_pad(substr((string)$message['subject'], 0, 25), 25, "\x00");
        $header .= str_pad('', 8, "\x00");
        $header .= str_pad((string)($message['reply_to_num'] ?? 0), 8, ' ', STR_PAD_LEFT);
        $header .= str_pad((string)$totalBlocks, 6, ' ', STR_PAD_LEFT);
        $header .= chr(0xE1);
        $header .= pack('v', $confNumber);
        $header .= pack('v', $conferenceLogicalNumber);
        $header .= "\x00";
        $header .= "\x00\x00\x00\x00";

        if (strlen($header) !== self::BLOCK_SIZE) {
            throw new \LogicException('REP header block is ' . strlen($header) . ' bytes, expected 128.');
        }

        $paddedBody = str_pad($body, $bodyBlockCount * self::BLOCK_SIZE, "\x00");
        return $header . $paddedBody;
    }

    /**
     * @param array<string,mixed> $message
     */
    private function buildHeadersDatSection(array $message, int $conferenceNumber): string
    {
        $lines = [];
        $lines[] = 'Conference: ' . $conferenceNumber;
        $lines[] = 'Sender: ' . $this->encodeHeaderValue((string)($message['from_name'] ?? 'Unknown'));
        $lines[] = 'To: ' . $this->encodeHeaderValue((string)($message['to_name'] ?? 'All'));
        $lines[] = 'Subject: ' . $this->encodeHeaderValue((string)($message['subject'] ?? '(no subject)'));

        $fromAddress = trim((string)($message['from_address'] ?? ''));
        if ($fromAddress !== '') {
            $lines[] = 'SenderNetAddr: ' . $this->encodeHeaderValue($fromAddress);
        }

        $messageId = trim((string)($message['message_id'] ?? ''));
        if ($messageId !== '') {
            $lines[] = 'X-FTN-MSGID: ' . $this->encodeHeaderValue($messageId);
        }

        $replyMessageId = trim((string)($message['reply_message_id'] ?? ''));
        if ($replyMessageId !== '') {
            $lines[] = 'X-FTN-REPLY: ' . $this->encodeHeaderValue($replyMessageId);
        }

        $lines[] = 'X-FTN-CHRS: ' . self::BODY_CHARSET;

        return implode("\r\n", $lines);
    }

    /**
     * @param array<int,string> $sections
     */
    private function buildHeadersDat(array $sections): string
    {
        $contents = [];
        foreach ($sections as $offset => $sectionBody) {
            $contents[] = '[' . strtolower(dechex($offset)) . ']';
            $contents[] = $sectionBody;
            $contents[] = '';
        }

        return implode("\r\n", $contents);
    }

    private function encodeHeaderValue(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));
        $encoded = @iconv('UTF-8', self::BODY_CHARSET . '//TRANSLIT//IGNORE', $value);
        return ($encoded !== false && $encoded !== '') ? $encoded : $value;
    }
}
