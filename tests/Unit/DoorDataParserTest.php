<?php

declare(strict_types=1);

use BinktermPHP\DoorData\BinaryRecordReader;
use BinktermPHP\DoorData\DoorDataParseException;
use BinktermPHP\DoorData\DoorDataParserRegistry;
use BinktermPHP\DoorData\Parsers\LordParser;
use BinktermPHP\DoorData\Parsers\TheWireParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the door data parser registry, binary reader, and LORD parser.
 */
class DoorDataParserTest extends TestCase
{
    /**
     * Build a 236-byte LORD record with fictitious data.
     */
    private function lordRecord(string $handle, int $exp, int $level, int $class = 1): string
    {
        $rec = str_repeat("\0", 236);
        $put = static function (string &$rec, int $off, string $bytes): void {
            $rec = substr_replace($rec, $bytes, $off, strlen($bytes));
        };
        $put($rec, 0, chr(strlen($handle)) . $handle);
        $put($rec, 21, chr(4) . 'TEST');
        $put($rec, 82, chr(5) . 'Stick');
        $put($rec, 109, pack('V', 1234));
        $put($rec, 127, pack('v', $level));
        $put($rec, 131, chr(4) . 'Coat');
        $put($rec, 158, pack('V', $exp));
        $put($rec, 179, chr($class));
        $put($rec, 227, pack('v', 3));
        return $rec;
    }

    public function testLordParsesRecords(): void
    {
        $buf = $this->lordRecord('Alpha', 900, 5) . $this->lordRecord('Beta', 70000, 12, 3);
        $players = (new LordParser())->parseBuffer(new BinaryRecordReader($buf));

        $this->assertCount(2, $players);
        $this->assertSame('Alpha', $players[0]['handle']);
        $this->assertSame(900, $players[0]['score']);
        $this->assertSame('TEST', $players[0]['real_name']);
        $this->assertSame(5, $players[0]['stats']['level']);
        $this->assertSame(1234, $players[0]['stats']['gold']);
        $this->assertSame('Stick', $players[0]['stats']['weapon']);
        $this->assertSame('Coat', $players[0]['stats']['armor']);
        $this->assertSame(3, $players[0]['stats']['player_kills']);
        $this->assertSame('Thief', $players[1]['stats']['class']);
        $this->assertSame(70000, $players[1]['score']);
    }

    public function testLordBlankHandleIsDeleted(): void
    {
        $players = (new LordParser())->parseBuffer(new BinaryRecordReader($this->lordRecord('', 0, 1)));
        $this->assertTrue($players[0]['deleted']);
    }

    public function testLordRejectsPartialRecord(): void
    {
        $this->expectException(DoorDataParseException::class);
        (new LordParser())->parseBuffer(new BinaryRecordReader(str_repeat("\0", 237)));
    }

    /**
     * Build a 347-byte The Wire record with fictitious data.
     */
    private function wireRecord(string $handle, string $bbsName, int $cred, int $level = 1): string
    {
        $rec = str_repeat("\0", 347);
        $put = static function (string &$rec, int $off, string $bytes): void {
            $rec = substr_replace($rec, $bytes, $off, strlen($bytes));
        };
        $put($rec, 0, chr(strlen($handle)) . $handle);
        $put($rec, 66, pack('V', $cred) . pack('V', $cred));
        $put($rec, 74, pack('v', $level));
        $put($rec, 92, chr(strlen($bbsName)) . $bbsName);
        return $rec;
    }

    public function testTheWireParsesRecords(): void
    {
        $buf = $this->wireRecord('ALPHA', 'alpha', 450) . $this->wireRecord('BETA', 'Beta_Two', 1200, 3);
        $players = (new TheWireParser())->parseBuffer(new BinaryRecordReader($buf));

        $this->assertCount(2, $players);
        $this->assertSame('ALPHA', $players[0]['handle']);
        $this->assertSame('alpha', $players[0]['real_name']);
        $this->assertSame(450, $players[0]['score']);
        $this->assertSame('Street Cred', $players[0]['score_label']);
        $this->assertFalse($players[0]['deleted']);
        $this->assertSame(3, $players[1]['stats']['level']);
        $this->assertSame(1200, $players[1]['score']);
    }

    public function testTheWireUnfinishedAccountIsDeleted(): void
    {
        $buf = $this->wireRecord('', '', 0) . $this->wireRecord('GAMMA', '', 0);
        $players = (new TheWireParser())->parseBuffer(new BinaryRecordReader($buf));
        $this->assertTrue($players[0]['deleted']);
        $this->assertTrue($players[1]['deleted']);
        $this->assertNull($players[1]['real_name']);
    }

    public function testTheWireRejectsPartialRecord(): void
    {
        $this->expectException(DoorDataParseException::class);
        (new TheWireParser())->parseBuffer(new BinaryRecordReader(str_repeat("\0", 348)));
    }

    public function testReaderBoundsChecked(): void
    {
        $this->expectException(DoorDataParseException::class);
        (new BinaryRecordReader("\0\0"))->int32(0);
    }

    public function testReaderSignedValues(): void
    {
        $r = new BinaryRecordReader("\xFF\xFF\xFF\xFF\xFF");
        $this->assertSame(-1, $r->int16(0));
        $this->assertSame(65535, $r->uint16(0));
        $this->assertSame(-1, $r->int32(0));
        $this->assertSame(-1, $r->int8(4));
    }

    public function testPascalStringTruncatesToLengthByte(): void
    {
        $r = new BinaryRecordReader(chr(2) . "abcde");
        $this->assertSame('ab', $r->pascalString(0, 5));
    }

    public function testRegistryLookupAndDuplicates(): void
    {
        DoorDataParserRegistry::reset();
        $this->assertContains('lord', DoorDataParserRegistry::ids());
        $this->assertContains('thewire', DoorDataParserRegistry::ids());
        $this->assertInstanceOf(LordParser::class, DoorDataParserRegistry::get('LORD'));
        $this->assertNull(DoorDataParserRegistry::get('nope'));

        $this->expectException(InvalidArgumentException::class);
        DoorDataParserRegistry::register(LordParser::class);
    }
}
