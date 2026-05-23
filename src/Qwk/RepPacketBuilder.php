<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Version;
use ZipArchive;

class RepPacketBuilder
{
    private const BLOCK_SIZE = 128;
    private const LINE_TERM = "\xE3";

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
        $logical = 1;
        $conferenceLogicalNumbers = [];
        foreach ($messages as $message) {
            $confNumber = (int)($message['conference_number'] ?? 0);
            $conferenceLogicalNumbers[$confNumber] = ($conferenceLogicalNumbers[$confNumber] ?? 0) + 1;
            $msgData .= $this->encodeMessage($message, $logical, $conferenceLogicalNumbers[$confNumber]);
            $logical++;
        }

        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $normalizedBbsId . '_' . bin2hex(random_bytes(8)) . '.rep';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create REP archive');
        }
        $zip->addFromString($normalizedBbsId . '.MSG', $msgData);
        $zip->close();

        return $zipPath;
    }

    /**
     * @param array<string,mixed> $message
     */
    private function encodeMessage(array $message, int $logicalNumber, int $conferenceLogicalNumber): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], self::LINE_TERM, rtrim((string)$message['body'])) . self::LINE_TERM;
        $cp437 = @iconv('UTF-8', 'CP437//TRANSLIT//IGNORE', $body);
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
        $header .= str_pad((string)$logicalNumber, 7, ' ', STR_PAD_LEFT);
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
}
