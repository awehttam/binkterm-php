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
 *   0: >>> BEGIN marker (ROT47: "mmm qtvx}")
 *   1: Sysop name
 *   2: BBS name
 *   3: Date (MM/DD/YY or YY/MM/DD)
 *   4: Time
 *   5: Location
 *   6: OS
 *   7: host:port
 *   8: >>> END marker
 */
class IbbsLastCallProcessor implements MessageProcessorInterface
{
    /** @var \PDO */
    private \PDO $db;

    /** @var callable|null */
    private $debugCallback = null;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Set a callback to receive debug output lines.
     *
     * @param callable|null $fn  fn(string $line): void
     */
    public function setDebugCallback(?callable $fn): void
    {
        $this->debugCallback = $fn;
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

        // Show raw body start (non-printable chars as hex)
        if ($this->debugCallback !== null) {
            $rawPreview = substr($body, 0, 200);
            $rawDisplay = preg_replace_callback('/[\x00-\x1f\x7f]/', function ($m) {
                return sprintf('[^%02X]', ord($m[0]));
            }, $rawPreview);
            ($this->debugCallback)("    RAW(200): " . $rawDisplay);
        }

        // ROT47 decode the entire body
        $decoded = $this->applyRot47($body);

        // Split into lines, strip carriage returns
        $lines = explode("\n", $decoded);
        $lines = array_map(fn($l) => rtrim($l, "\r"), $lines);

        // Emit decoded lines for debugging
        if ($this->debugCallback !== null) {
            ($this->debugCallback)("    DECODED LINES (" . count($lines) . " total):");
            foreach ($lines as $i => $line) {
                $lineDisplay = preg_replace_callback('/[\x00-\x1f\x7f]/', function ($m) {
                    return sprintf('[^%02X]', ord($m[0]));
                }, $line);
                ($this->debugCallback)("      [{$i}]: {$lineDisplay}");
            }
        }

        // Validate >>> BEGIN marker (ROT47-encoded "mmm qtvx}" = ">>> BEGIN")
        if (trim($lines[0]) !== 'mmm qtvx}') {
            if ($this->debugCallback !== null) {
                ($this->debugCallback)("    SKIP: missing >>> BEGIN marker on line 0 (got: " . trim($lines[0]) . ")");
            }
            return false;
        }

        // Need at least 8 lines: BEGIN, sysop, name, date, time, location, os, host:port
        if (count($lines) < 8) {
            if ($this->debugCallback !== null) {
                ($this->debugCallback)("    SKIP: only " . count($lines) . " lines, need ≥8");
            }
            return false;
        }

        $name     = trim($lines[2]);
        $sysop    = trim($lines[1]);
        $location = trim($lines[5]);
        $os       = trim($lines[6]);
        $hostPort = trim($lines[7]);

        if ($this->debugCallback !== null) {
            ($this->debugCallback)(sprintf(
                "    EXTRACTED: name=%s | sysop=%s | location=%s | os=%s | hostPort=%s",
                json_encode($name), json_encode($sysop),
                json_encode($location), json_encode($os), json_encode($hostPort)
            ));
        }

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
