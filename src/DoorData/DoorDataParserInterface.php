<?php

namespace BinktermPHP\DoorData;

/**
 * Contract for a per-game parser that reads a door game's data files.
 *
 * Implementations must be read-only and must not accept paths from users;
 * they receive an already-validated data directory.
 *
 * Each player entry returned by parsePlayers() has the shape:
 *  - handle      (string)       in-game name
 *  - real_name   (string|null)  real name stored by the game
 *  - score       (int)          primary ranking value
 *  - score_label (string)       what score represents
 *  - last_played (string|null)  ISO 8601 UTC timestamp when available
 *  - deleted     (bool)         true for vacated or dead record slots to be hidden
 *  - stats       (array)        game-specific snake_case fields
 *
 * @package BinktermPHP
 */
interface DoorDataParserInterface
{
    /**
     * Stable lowercase id referenced from dosdoor.jsn (e.g. "lord").
     *
     * @return string
     */
    public static function getId(): string;

    /**
     * Human-readable game name.
     *
     * @return string
     */
    public function getGameName(): string;

    /**
     * Files the parser needs, relative to the data directory.
     *
     * @return string[]
     */
    public function getRequiredFiles(): array;

    /**
     * Parse player records from the game's data directory.
     *
     * @param string $dataDir Absolute path to the game's data directory
     * @return array<int, array> Normalized player entries
     * @throws DoorDataParseException When files are missing or malformed
     */
    public function parsePlayers(string $dataDir): array;
}
