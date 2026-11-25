<?php

namespace BinktermPHP\DoorData\Parsers;

use BinktermPHP\DoorData\BinaryRecordReader;
use BinktermPHP\DoorData\DoorDataParseException;
use BinktermPHP\DoorData\DoorDataParserInterface;

/**
 * Parser for Legend of the Red Dragon (LORD) PLAYER.DAT.
 *
 * The file is a sequence of 236-byte, unaligned Turbo Pascal records. The
 * layout comes from LORDSTRC.PAS shipped with the game (valid for 3.20-4.08).
 *
 * Offsets (name: type):
 *   0 names string[20]        21 real_names string[50]    72 hit_points int
 *  74 bad int                 76 rate int                 78 hit_max int
 *  80 weapon_num int          82 weapon string[20]       103 seen_master int
 * 105 fights_left int        107 human_left int          109 gold long
 * 113 bank long              117 def int                 119 strength int
 * 121 charm int              123 seen_dragon int         125 seen_violet int
 * 127 level int              129 time word               131 arm string[20]
 * 152 arm_num int            154 dead shortint           155 inn shortint
 * 156 gem int                158 exp long                162 sex shortint
 * 163 seen_bard shortint     164 last_alive_time int     166 lays int
 * 168 why int                170 on_now bool             171 m_time int
 * 173 time_on string[5]      179 class shortint          180 extra int
 * 182 love string[25]        208 married int             210 kids int
 * 212 king int               214 skillw/skillm/skillt    217 levelw/levelm/levelt
 * 220 inn_random bool        221 married_to int          223 v1 long
 * 227 v2 int (player kills)  229 v3 int                  231 v4 bool
 * 232 v5 shortint            233-235 new_stat1..3
 *
 * @package BinktermPHP
 */
class LordParser implements DoorDataParserInterface
{
    private const RECORD_SIZE = 236;

    private const CLASSES = [1 => 'Death Knight', 2 => 'Mystical', 3 => 'Thief'];

    /**
     * @return string
     */
    public static function getId(): string
    {
        return 'lord';
    }

    /**
     * @return string
     */
    public function getGameName(): string
    {
        return 'Legend of the Red Dragon';
    }

    /**
     * @return string[]
     */
    public function getRequiredFiles(): array
    {
        return ['PLAYER.DAT'];
    }

    /**
     * Parse PLAYER.DAT into normalized player entries.
     *
     * @param string $dataDir Absolute path to the LORD directory
     * @return array<int, array>
     * @throws DoorDataParseException
     */
    public function parsePlayers(string $dataDir): array
    {
        $path = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'PLAYER.DAT';
        return $this->parseBuffer(BinaryRecordReader::fromFile($path));
    }

    /**
     * Parse an already-loaded PLAYER.DAT buffer.
     *
     * @param BinaryRecordReader $reader Reader over the whole file
     * @return array<int, array>
     * @throws DoorDataParseException
     */
    public function parseBuffer(BinaryRecordReader $reader): array
    {
        $players = [];
        foreach ($reader->records(self::RECORD_SIZE) as $slot => $r) {
            $handle = trim($r->pascalString(0, 20));
            $realName = trim($r->pascalString(21, 50));
            $class = $r->int8(179);

            $players[] = [
                'handle' => $handle,
                'real_name' => $realName !== '' ? $realName : null,
                'score' => $r->int32(158),
                'score_label' => 'Experience',
                'last_played' => null,
                'deleted' => $handle === '',
                'stats' => [
                    'slot' => $slot,
                    'level' => $r->int16(127),
                    'experience' => $r->int32(158),
                    'gold' => $r->int32(109),
                    'bank' => $r->int32(113),
                    'gems' => $r->int16(156),
                    'hit_points' => $r->int16(72),
                    'hit_max' => $r->int16(78),
                    'strength' => $r->int16(119),
                    'defense' => $r->int16(117),
                    'charm' => $r->int16(121),
                    'class' => self::CLASSES[$class] ?? 'Unknown',
                    'sex' => $r->int8(162) === 5 ? 'female' : 'male',
                    'weapon' => trim($r->pascalString(82, 20)),
                    'armor' => trim($r->pascalString(131, 20)),
                    'player_kills' => $r->int16(227),
                    'dragon_kills' => $r->int16(212),
                    'kids' => $r->int16(210),
                    'dead' => $r->int8(154) === 5,
                    'on_now' => $r->uint8(170) !== 0,
                    'last_day' => $r->uint16(129),
                    'time_on' => trim($r->pascalString(173, 5)),
                ],
            ];
        }
        return $players;
    }
}
