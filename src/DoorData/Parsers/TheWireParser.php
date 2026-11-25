<?php

namespace BinktermPHP\DoorData\Parsers;

use BinktermPHP\DoorData\BinaryRecordReader;
use BinktermPHP\DoorData\DoorDataParseException;
use BinktermPHP\DoorData\DoorDataParserInterface;

/**
 * Parser for The Wire (WIRE.DAT).
 *
 * The file is a sequence of 347-byte records. No structure file is available;
 * the layout was worked out from hexdumps and checked against the in-game
 * leaderboard, so fields not listed below are unidentified.
 *
 * Offsets (name: type):
 *   0 handle string[30], uppercase operator handle
 *  66 street_cred u32      70 street_cred_2 u32 (equal to street_cred in every sample)
 *  74 level u16 (assumed)  92 bbs_name string[22], BBS username in original case
 * 115 message, NUL-terminated text (not read)
 *
 * A record with no handle or no BBS name is an unfinished account and is
 * reported as deleted.
 *
 * The level at offset 74 is a guess. It is the first of a run of u16 values
 * that read 1 for every player seen (offsets 74, 76, 78, and for some players
 * 80 and 82), all with Street Cred at 450. Revisit once players have differing
 * values, by changing one thing in the game and diffing WIRE.DAT. Alternatives:
 *  - one of several per-player attributes or skill ratings that start at 1,
 *    with 74 being just the first (the values after 78 differ between players)
 *  - the tier index behind the title shown in game (Script Kiddie), which would
 *    make it the rank rather than a level
 *  - a flag, such as account enabled, ANSI on or a completed-setup marker
 *  - a count, such as logins or sessions, that happens to be 1 so far
 * If it is confirmed as the tier, map it to title text here.
 *
 * @package BinktermPHP
 */
class TheWireParser implements DoorDataParserInterface
{
    private const RECORD_SIZE = 347;

    /**
     * @return string
     */
    public static function getId(): string
    {
        return 'thewire';
    }

    /**
     * @return string
     */
    public function getGameName(): string
    {
        return 'The Wire';
    }

    /**
     * @return string[]
     */
    public function getRequiredFiles(): array
    {
        return ['WIRE.DAT'];
    }

    /**
     * Parse WIRE.DAT into normalized player entries.
     *
     * @param string $dataDir Absolute path to The Wire directory
     * @return array<int, array>
     * @throws DoorDataParseException
     */
    public function parsePlayers(string $dataDir): array
    {
        $path = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'WIRE.DAT';
        return $this->parseBuffer(BinaryRecordReader::fromFile($path));
    }

    /**
     * Parse an already-loaded WIRE.DAT buffer.
     *
     * @param BinaryRecordReader $reader Reader over the whole file
     * @return array<int, array>
     * @throws DoorDataParseException
     */
    public function parseBuffer(BinaryRecordReader $reader): array
    {
        $players = [];
        foreach ($reader->records(self::RECORD_SIZE) as $slot => $r) {
            $handle = trim($r->pascalString(0, 30));
            $bbsName = trim($r->pascalString(92, 22));
            $streetCred = $r->uint32(66);

            $players[] = [
                'handle' => $handle,
                'real_name' => $bbsName !== '' ? $bbsName : null,
                'score' => $streetCred,
                'score_label' => 'Street Cred',
                'last_played' => null,
                'deleted' => $handle === '' || $bbsName === '',
                'stats' => [
                    'slot' => $slot,
                    'street_cred' => $streetCred,
                    'street_cred_2' => $r->uint32(70),
                    // Unconfirmed; see the class phpDoc for what else this could be
                    'level' => $r->uint16(74),
                ],
            ];
        }
        return $players;
    }
}
