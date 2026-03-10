<?php

namespace BinktermPHP\Robots\Processors;

use BinktermPHP\BbsDirectory;
use BinktermPHP\Robots\MessageProcessorInterface;

/**
 * Processor for FSXNet ibbslastcall-data messages.
 *
 * These messages appear in the FSX_DAT echo area with the subject
 * "ibbslastcall-data". The message body is ROT47-encoded and contains
 * BBS node announcement data (name, sysop, location, OS, telnet address).
 *
 * Body line format after ROT47 decode (0-indexed):
 *   0: BBS name
 *   1: Sysop name
 *   2: Date (MM/DD/YY)
 *   3: Time
 *   4: Location
 *   5: OS
 *   6: host:port
 */
class IbbsLastCallProcessor implements MessageProcessorInterface
{
    /** @var \PDO */
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /** {@inheritdoc} */
    public static function getProcessorType(): string
    {
        return 'ibbslastcall_rot47';
    }

    /** {@inheritdoc} */
    public static function getDisplayName(): string
    {
        return 'iBBS Last Call (ROT47)';
    }

    /** {@inheritdoc} */
    public static function getDescription(): string
    {
        return 'Decodes ROT47-encoded ibbslastcall-data messages from FSX_DAT and upserts entries into the BBS directory.';
    }

    /** {@inheritdoc} */
    public function processMessage(array $message, array $robotConfig): bool
    {
        $body = $message['message_text'] ?? '';
        if (empty($body)) {
            return false;
        }

        // ROT47 decode the entire body
        $decoded = $this->applyRot47($body);

        // Split into lines, strip carriage returns
        $lines = explode("\n", $decoded);
        $lines = array_map(fn($l) => rtrim($l, "\r"), $lines);

        // Need at least 7 lines: name, sysop, date, time, location, os, host:port
        if (count($lines) < 7) {
            return false;
        }

        $name     = trim($lines[0]);
        $sysop    = trim($lines[1]);
        $location = trim($lines[4]);
        $os       = trim($lines[5]);
        $hostPort = trim($lines[6]);

        if (empty($name)) {
            return false;
        }

        // Parse host:port — split on last colon to handle IPv6-style or plain host:port
        $telnetHost = $hostPort;
        $telnetPort = 23;

        $lastColon = strrpos($hostPort, ':');
        if ($lastColon !== false) {
            $possiblePort = substr($hostPort, $lastColon + 1);
            if (ctype_digit($possiblePort)) {
                $telnetPort = (int)$possiblePort;
                $telnetHost = substr($hostPort, 0, $lastColon);
            }
        }

        $telnetHost = trim($telnetHost);
        if (empty($telnetHost)) {
            $telnetHost = null;
            $telnetPort = 23;
        }

        $directory = new BbsDirectory($this->db);
        $directory->upsertByName([
            'name'        => $name,
            'sysop'       => $sysop ?: null,
            'location'    => $location ?: null,
            'os'          => $os ?: null,
            'telnet_host' => $telnetHost,
            'telnet_port' => $telnetPort,
        ]);

        return true;
    }

    /**
     * Apply ROT47 transformation to a string.
     * Maps printable ASCII characters (33–126) by rotating 47 positions.
     *
     * @param string $text
     * @return string
     */
    public function applyRot47(string $text): string
    {
        $result = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($text[$i]);
            if ($ord >= 33 && $ord <= 126) {
                $result .= chr((($ord - 33 + 47) % 94) + 33);
            } else {
                $result .= $text[$i];
            }
        }
        return $result;
    }
}
