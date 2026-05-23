<?php

namespace BinktermPHP\Qwk;

use ZipArchive;

/**
 * Parses a QWK-format packet archive into message objects and conference
 * metadata.
 *
 * Shared by the inter-BBS mailbox import path and other QWK-format parsing
 * helpers that need a normalized `QwkMessage` representation.
 *
 * Used by: Both
 */
class QwkPacketParser
{
    private const BLOCK_SIZE = 128;
    private const LINE_TERM = "\xE3";
    /** @var callable|null */
    private $logger = null;

    public function setLogger(?callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @return array{control:array<string,mixed>,messages:array<int,QwkMessage>}
     */
    public function parsePacket(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Failed to open QWK packet');
        }

        $messagesDat = $zip->getFromName('MESSAGES.DAT');
        $controlDat = $zip->getFromName('CONTROL.DAT');
        $zip->close();

        if ($messagesDat === false) {
            throw new \RuntimeException('MESSAGES.DAT not found in QWK packet');
        }

        $this->log('DEBUG', sprintf(
            'Parsing QWK packet %s (CONTROL.DAT=%s, MESSAGES.DAT=%d bytes)',
            $zipPath,
            $controlDat !== false ? 'present' : 'missing',
            strlen($messagesDat)
        ));

        return [
            'control' => $this->parseControlDat($controlDat !== false ? $controlDat : ''),
            'messages' => $this->parseMessagesDat($messagesDat),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseControlDat(string $contents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $conferenceMap = [];
        $highest = isset($lines[10]) ? (int)trim((string)$lines[10]) : 0;
        $offset = 11;
        for ($i = 0; $i <= $highest; $i++) {
            $number = isset($lines[$offset]) ? (int)trim((string)$lines[$offset]) : null;
            $name = isset($lines[$offset + 1]) ? trim((string)$lines[$offset + 1]) : '';
            if ($number !== null) {
                $conferenceMap[$number] = $name;
            }
            $offset += 2;
        }

        $bbsIdField = (string)($lines[4] ?? '0,');
        $bbsIdParts = explode(',', $bbsIdField);
        $bbsId = strtoupper(trim((string)($bbsIdParts[1] ?? '')));

        $this->log('DEBUG', sprintf(
            'Parsed CONTROL.DAT: bbs_name="%s", bbs_id="%s", conferences=%d',
            trim((string)($lines[0] ?? '')),
            $bbsId,
            count($conferenceMap)
        ));

        return [
            'bbs_name' => trim((string)($lines[0] ?? '')),
            'bbs_id' => $bbsId,
            'conferences' => $conferenceMap,
        ];
    }

    /**
     * @return array<int,QwkMessage>
     */
    private function parseMessagesDat(string $data): array
    {
        $len = strlen($data);
        if ($len === 0 || $len % self::BLOCK_SIZE !== 0) {
            throw new \RuntimeException('MESSAGES.DAT is malformed');
        }

        $this->log('DEBUG', sprintf(
            'Scanning MESSAGES.DAT: %d bytes, %d blocks',
            $len,
            (int)($len / self::BLOCK_SIZE)
        ));

        $messages = [];
        $offset = self::BLOCK_SIZE;
        while ($offset < $len) {
            $message = $this->parseMessage($data, $offset);
            if ($message === null) {
                break;
            }
            $messages[] = $message['message'];
            $offset += $message['blocks'] * self::BLOCK_SIZE;
        }

        $this->log('DEBUG', sprintf('Parsed %d message(s) from MESSAGES.DAT', count($messages)));

        return $messages;
    }

    /**
     * @return array{message:QwkMessage,blocks:int}|null
     */
    private function parseMessage(string $data, int $offset): ?array
    {
        if ($offset + self::BLOCK_SIZE > strlen($data)) {
            return null;
        }

        $header = substr($data, $offset, self::BLOCK_SIZE);
        $blockCount = (int)trim(substr($header, 116, 6));
        if ($blockCount <= 0) {
            return null;
        }

        $messageNumber = (int)trim(substr($header, 1, 7));
        $replyToNumber = (int)trim(substr($header, 108, 8));
        $conferenceNumber = ord($header[123]) | (ord($header[124]) << 8);
        $toName = rtrim(substr($header, 21, 25), "\x00 ");
        $fromName = rtrim(substr($header, 46, 25), "\x00 ");
        $subject = rtrim(substr($header, 71, 25), "\x00 ");

        $bodyLen = max(0, ($blockCount - 1) * self::BLOCK_SIZE);
        $bodyRaw = substr($data, $offset + self::BLOCK_SIZE, $bodyLen);
        $bodyRaw = rtrim($bodyRaw, "\x00");
        $bodyText = str_replace(self::LINE_TERM, "\n", $bodyRaw);
        [$kludges, $bodyText] = $this->splitQwkeBody($bodyText);
        [$headers, $bodyText] = $this->extractQwkePlaintextHeaders($bodyText);
        $charset = $this->detectCharset($kludges);

        $this->log('DEBUG', sprintf(
            'Parsed QWK message #%d conf=%d reply=%d blocks=%d status=%s from="%s" to="%s" subject="%s" body_len=%d charset=%s kludges=%d',
            $messageNumber,
            $conferenceNumber,
            $replyToNumber,
            $blockCount,
            $header[0],
            trim((string)($headers['from'] ?? $fromName)),
            trim((string)($headers['to'] ?? $toName)),
            trim((string)($headers['subject'] ?? $subject)),
            strlen($bodyText),
            $charset ?? 'auto',
            $kludges === '' ? 0 : count(explode("\n", $kludges))
        ));

        $message = new QwkMessage(
            $messageNumber,
            $conferenceNumber,
            $replyToNumber,
            $header[0],
            $this->normaliseEncoding(trim((string)($headers['to'] ?? $toName)), $charset),
            $this->normaliseEncoding(trim((string)($headers['from'] ?? $fromName)), $charset),
            $this->normaliseEncoding(trim((string)($headers['subject'] ?? $subject)), $charset),
            rtrim($this->normaliseEncoding($bodyText, $charset)),
            $kludges,
            $this->extractMsgId($kludges)
        );

        return ['message' => $message, 'blocks' => $blockCount];
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitQwkeBody(string $body): array
    {
        $lines = explode("\n", $body);
        $kludges = [];
        $bodyLines = [];
        $inKludges = true;
        foreach ($lines as $line) {
            if ($inKludges && strlen($line) > 0 && ord($line[0]) === 0x01) {
                $kludges[] = $line;
                continue;
            }

            $inKludges = false;
            $bodyLines[] = $line;
        }

        return [implode("\n", $kludges), implode("\n", $bodyLines)];
    }

    /**
     * @return array{0:array<string,string>,1:string}
     */
    private function extractQwkePlaintextHeaders(string $body): array
    {
        $lines = explode("\n", $body);
        $headers = [];
        $i = 0;
        while ($i < count($lines) && preg_match('/^(Subject|To|From):\s*(.*)/i', $lines[$i], $m)) {
            $headers[strtolower($m[1])] = rtrim($m[2]);
            $i++;
        }

        if ($headers !== [] && isset($lines[$i]) && trim($lines[$i]) === '') {
            $i++;
        }

        return [$headers, implode("\n", array_slice($lines, $i))];
    }

    private function detectCharset(string $kludges): ?string
    {
        if (preg_match('/\x01CHRS:\s+(\S+)/i', $kludges, $m)) {
            return strtoupper(trim($m[1]));
        }
        return null;
    }

    private function extractMsgId(string $kludges): ?string
    {
        if (preg_match('/\x01MSGID:\s*(.+)$/im', $kludges, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function normaliseEncoding(string $text, ?string $charset): string
    {
        if ($text === '') {
            return '';
        }

        if ($charset === 'UTF-8' || ($charset === null && mb_check_encoding($text, 'UTF-8'))) {
            return $text;
        }

        $from = match (strtoupper((string)$charset)) {
            'CP437', 'IBM437', 'PC-8' => 'CP437',
            'CP850', 'IBM850' => 'CP850',
            'ISO-8859-1', 'LATIN1' => 'ISO-8859-1',
            default => 'CP437',
        };

        $converted = @iconv($from, 'UTF-8//TRANSLIT//IGNORE', $text);
        return ($converted !== false && $converted !== '') ? $converted : $text;
    }
}
