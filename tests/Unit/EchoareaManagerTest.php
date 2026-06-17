<?php

declare(strict_types=1);

use BinktermPHP\EchoareaManager;
use PHPUnit\Framework\TestCase;

final class EchoareaManagerTest extends TestCase
{
    public function testAllowsCommonFtnTagPunctuation(): void
    {
        self::assertTrue(EchoareaManager::isValidTag('AT&T_CHAT'));
        self::assertTrue(EchoareaManager::isValidTag("SYSOP'S"));
        self::assertTrue(EchoareaManager::isValidTag('FSX_GEN!'));
        self::assertTrue(EchoareaManager::isValidTag('NEWS%DEV'));
    }

    public function testRejectsWhitespaceAndEmptyTags(): void
    {
        self::assertFalse(EchoareaManager::isValidTag(''));
        self::assertFalse(EchoareaManager::isValidTag('AREA TAG'));
        self::assertFalse(EchoareaManager::isValidTag('AREA/TAG'));
    }
}
