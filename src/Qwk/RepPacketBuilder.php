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
        $msgData = str_pad('Produced by BinktermPHP v' . Version::getVersion(), self::BLOCK_SIZE, "\x00");
        $logical = 1;
        foreach ($messages as $message) {
            $msgData .= $this->encodeMessage($message, $logical);
            $logical++;
        }

        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . strtoupper($bbsId) . '_' . bin2hex(random_bytes(8)) . '.rep';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create REP archive');
        }
        $zip->addFromString(strtoupper($bbsId) . '.MSG', $msgData);
        $zip->close();

        return $zipPath;
    }

    /**
     * @param array<string,mixed> $message
     */
    private function encodeMessage(array $message, int $logicalNumber): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], self::LINE_TERM, rtrim((string)$message['body'])) . self::LINE_TERM;
        $cp437 = @iconv('UTF-8', 'CP437//TRANSLIT//IGNORE', $body);
        $body = ($cp437 !== false && $cp437 !== '') ? $cp437 : $body;

        $bodyBlockCount = max(1, (int)ceil(strlen($body) / self::BLOCK_SIZE));
        $totalBlocks = $bodyBlockCount + 1;
        $date = new \DateTime('now', new \DateTimeZone('UTC'));

        $header = '+';
        $header .= str_pad((string)$logicalNumber, 7, ' ', STR_PAD_LEFT);
        $header .= str_pad($date->format('m-d-y'), 8, "\x00");
        $header .= str_pad($date->format('H:i'), 5, "\x00");
        $header .= str_pad(substr((string)$message['to_name'], 0, 25), 25, "\x00");
        $header .= str_pad(substr((string)$message['from_name'], 0, 25), 25, "\x00");
        $header .= str_pad(substr((string)$message['subject'], 0, 25), 25, "\x00");
        $header .= str_pad('', 12, "\x00");
        $header .= str_pad((string)($message['reply_to_num'] ?? 0), 8, ' ', STR_PAD_LEFT);
        $header .= str_pad((string)$totalBlocks, 6, ' ', STR_PAD_LEFT);
        $header .= chr(0xE1);
        $confNumber = (int)$message['conference_number'];
        $header .= chr($confNumber & 0xFF);
        $header .= chr(($confNumber >> 8) & 0xFF);
        $header .= "\x00\x00\x00";

        $paddedBody = str_pad($body, $bodyBlockCount * self::BLOCK_SIZE, "\x00");
        return $header . $paddedBody;
    }
}
