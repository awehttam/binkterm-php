<?php

namespace BinktermPHP\DoorData;

use BinktermPHP\DoorData\Parsers\LordParser;
use BinktermPHP\DoorData\Parsers\TheWireParser;

/**
 * Maps parser ids (as named in dosdoor.jsn "data_parser") to parser classes.
 *
 * Built-in parsers are registered on first use. To add a game, create a
 * DoorDataParserInterface implementation and add it to registerBuiltins().
 *
 * @package BinktermPHP
 */
class DoorDataParserRegistry
{
    /** @var array<string, class-string<DoorDataParserInterface>> */
    private static array $parsers = [];

    private static bool $builtinsLoaded = false;

    /**
     * Register a parser class.
     *
     * @param string $class Class implementing DoorDataParserInterface
     * @throws \InvalidArgumentException On invalid class or duplicate id
     */
    public static function register(string $class): void
    {
        self::loadBuiltins();
        self::add($class);
    }

    /**
     * Get a new parser instance by id.
     *
     * @param string $id Parser id
     * @return DoorDataParserInterface|null Null when the id is unknown
     */
    public static function get(string $id): ?DoorDataParserInterface
    {
        self::loadBuiltins();
        $class = self::$parsers[strtolower($id)] ?? null;
        return $class !== null ? new $class() : null;
    }

    /**
     * List registered parser ids.
     *
     * @return string[]
     */
    public static function ids(): array
    {
        self::loadBuiltins();
        return array_keys(self::$parsers);
    }

    /**
     * Clear all registrations (for tests). Built-ins reload on next use.
     */
    public static function reset(): void
    {
        self::$parsers = [];
        self::$builtinsLoaded = false;
    }

    private static function loadBuiltins(): void
    {
        if (self::$builtinsLoaded) {
            return;
        }
        self::$builtinsLoaded = true;
        self::add(LordParser::class);
        self::add(TheWireParser::class);
    }

    private static function add(string $class): void
    {
        if (!is_subclass_of($class, DoorDataParserInterface::class)) {
            throw new \InvalidArgumentException("$class must implement DoorDataParserInterface");
        }
        $id = strtolower($class::getId());
        if (isset(self::$parsers[$id])) {
            throw new \InvalidArgumentException("Door data parser '$id' is already registered");
        }
        self::$parsers[$id] = $class;
    }
}
