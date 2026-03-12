<?php

namespace BinktermPHP\Robots\Processors;

use BinktermPHP\BbsDirectory;
use BinktermPHP\Robots\MessageProcessorInterface;

/**
 * Processor for SYNCDATA echomail BBS list messages.
 *
 * Looks for messages addressed To: SBL in the SYNCDATA echo area.
 * The message body contains a JSON object wrapped in json-begin / json-end
 * markers describing a BBS entry.  The JSON is parsed and upserted into
 * the BBS directory via BbsDirectory::upsertByName().
 *
 * Example message body:
 *
 *   json-begin
 *   {
 *     "name": "Tardis BBS",
 *     "sysop": [{"name": "The Doctor"}],
 *     "service": [{"protocol": "telnet", "address": "bbs.example.com", "port": "23"}],
 *     "location": "Lancashire UK",
 *     "software": "Quarkware BBS",
 *     "bbs_website": "bbs.example.com",
 *     ...
 *   }
 *   json-end
 */
class SyncdataProcessor implements MessageProcessorInterface
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
        return 'syncdata_sbl';
    }

    /** {@inheritdoc} */
    public static function getDisplayName(): string
    {
        return 'SYNCDATA BBS List (SBL)';
    }

    /** {@inheritdoc} */
    public static function getDescription(): string
    {
        return 'Imports BBS directory entries from SYNCDATA echomail messages addressed To: SBL. Parses the JSON block between json-begin / json-end markers.';
    }

    /** {@inheritdoc} */
    public function processMessage(array $message, array $robotConfig): bool
    {
        // Only process messages addressed to SBL
        $toName = trim($message['to_name'] ?? '');
        if (strcasecmp($toName, 'SBL') !== 0) {
            if ($this->debugCallback) {
                ($this->debugCallback)("    SKIP: to_name=" . json_encode($toName) . " (not SBL)");
            }
            return false;
        }

        $body = $message['message_text'] ?? '';
        if (empty($body)) {
            return false;
        }

        // Extract JSON block between json-begin and json-end markers
        if (!preg_match('/json-begin\s*\n(.*?)\njson-end/si', $body, $matches)) {
            if ($this->debugCallback) {
                ($this->debugCallback)("    SKIP: no json-begin/json-end block found");
            }
            return false;
        }

        $jsonText = trim($matches[1]);
        $data     = json_decode($jsonText, true);

        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            if ($this->debugCallback) {
                ($this->debugCallback)("    SKIP: JSON parse error: " . json_last_error_msg());
            }
            return false;
        }

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            if ($this->debugCallback) {
                ($this->debugCallback)("    SKIP: empty name field");
            }
            return false;
        }

        // Extract sysop name from first entry in sysop array
        $sysop = null;
        if (!empty($data['sysop']) && is_array($data['sysop'])) {
            $sysop = trim($data['sysop'][0]['name'] ?? '') ?: null;
        }

        // Extract telnet service (first telnet entry wins)
        $telnetHost = null;
        $telnetPort = 23;
        $sshPort    = null;
        if (!empty($data['service']) && is_array($data['service'])) {
            foreach ($data['service'] as $svc) {
                $proto = strtolower(trim($svc['protocol'] ?? ''));
                $addr  = trim($svc['address'] ?? '');
                $port  = isset($svc['port']) ? (int)$svc['port'] : null;

                if ($proto === 'telnet' && $addr !== '' && $telnetHost === null) {
                    $telnetHost = $addr;
                    if ($port > 0) {
                        $telnetPort = $port;
                    }
                }
                if (($proto === 'ssh' || $proto === 'secure shell') && $port > 0 && $sshPort === null) {
                    $sshPort = $port;
                }
            }
        }

        // Combine description array into a single string if present
        $notes = null;
        if (!empty($data['description']) && is_array($data['description'])) {
            $notes = trim(implode('', $data['description'])) ?: null;
        } elseif (!empty($data['description']) && is_string($data['description'])) {
            $notes = trim($data['description']) ?: null;
        }

        $upsertData = [
            'name'        => $name,
            'sysop'       => $sysop,
            'location'    => !empty($data['location'])    ? trim($data['location'])    : null,
            'software'    => !empty($data['software'])    ? trim($data['software'])    : null,
            'website'     => !empty($data['bbs_website']) ? trim($data['bbs_website']) : (!empty($data['web_site']) ? trim($data['web_site']) : null),
            'telnet_host' => $telnetHost,
            'telnet_port' => $telnetPort,
            'ssh_port'    => $sshPort,
            'notes'       => $notes,
        ];

        if ($this->debugCallback) {
            ($this->debugCallback)(sprintf(
                "    UPSERT: name=%s sysop=%s telnet=%s:%d software=%s",
                json_encode($name),
                json_encode($sysop),
                json_encode($telnetHost),
                $telnetPort,
                json_encode($upsertData['software'])
            ));
        }

        $directory = new BbsDirectory($this->db);
        $directory->upsertByName($upsertData);

        return true;
    }
}
