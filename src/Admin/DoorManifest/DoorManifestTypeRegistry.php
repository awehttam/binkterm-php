<?php

namespace BinktermPHP\Admin\DoorManifest;

/**
 * Factory and lookup for the four built-in door type adapters.
 */
class DoorManifestTypeRegistry
{
    /** @var array<string, DoorManifestTypeDefinition> */
    private static array $types = [];
    private static bool $initialized = false;

    public static function getType(string $typeKey): ?DoorManifestTypeDefinition
    {
        self::init();
        return self::$types[$typeKey] ?? null;
    }

    /** @return array<string, DoorManifestTypeDefinition> */
    public static function getAllTypes(): array
    {
        self::init();
        return self::$types;
    }

    public static function isValidType(string $typeKey): bool
    {
        self::init();
        return isset(self::$types[$typeKey]);
    }

    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        self::$types = [
            'dos'    => new DosDoorManifestDefinition(),
            'native' => new NativeDoorManifestDefinition(),
            'jsdos'  => new JsdosDoorManifestDefinition(),
            'web'    => new WebDoorManifestDefinition(),
        ];
    }
}
