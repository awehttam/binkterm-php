<?php

namespace BinktermPHP\DoorData;

/**
 * Bounds-checked reader for little-endian, Turbo Pascal style binary records.
 *
 * Wraps an in-memory buffer; callers read the whole file once and parse the
 * buffer so a game writing mid-parse cannot produce torn reads.
 *
 * @package BinktermPHP
 */
class BinaryRecordReader
{
    /** Maximum file size accepted by fromFile(), in bytes. */
    public const MAX_FILE_SIZE = 10485760;

    private string $buffer;

    /**
     * @param string $buffer Raw bytes to read from
     */
    public function __construct(string $buffer)
    {
        $this->buffer = $buffer;
    }

    /**
     * Read a file into memory and wrap it.
     *
     * @param string $path Absolute file path
     * @return self
     * @throws DoorDataParseException When missing, unreadable, or too large
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new DoorDataParseException("Data file not readable: " . basename($path));
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new DoorDataParseException("Data file too large: " . basename($path));
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new DoorDataParseException("Unable to read data file: " . basename($path));
        }
        return new self($data);
    }

    /**
     * @return int Buffer length in bytes
     */
    public function size(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Split the buffer into fixed-size records.
     *
     * @param int $recordSize Record size in bytes
     * @return self[] One reader per record
     * @throws DoorDataParseException When the size is not a multiple of the record size
     */
    public function records(int $recordSize): array
    {
        if ($recordSize < 1 || strlen($this->buffer) % $recordSize !== 0) {
            throw new DoorDataParseException(
                "Data size " . strlen($this->buffer) . " is not a multiple of record size $recordSize"
            );
        }
        $records = [];
        for ($offset = 0, $len = strlen($this->buffer); $offset < $len; $offset += $recordSize) {
            $records[] = new self(substr($this->buffer, $offset, $recordSize));
        }
        return $records;
    }

    /**
     * Read a Pascal string: a length byte followed by a fixed-width field.
     *
     * @param int $offset Offset of the length byte
     * @param int $maxLen Declared maximum length (the Pascal string[N])
     * @return string UTF-8 text decoded from CP437
     */
    public function pascalString(int $offset, int $maxLen): string
    {
        $raw = $this->bytes($offset, 1 + $maxLen);
        $len = min(ord($raw[0]), $maxLen);
        $text = substr($raw, 1, $len);
        $text = rtrim($text, "\0");
        $converted = @iconv('CP437', 'UTF-8//IGNORE', $text);
        return $converted !== false ? $converted : '';
    }

    /**
     * @param int $offset Byte offset
     * @return int Unsigned 8-bit value
     */
    public function uint8(int $offset): int
    {
        return ord($this->bytes($offset, 1));
    }

    /**
     * @param int $offset Byte offset
     * @return int Signed 8-bit value (Pascal shortint)
     */
    public function int8(int $offset): int
    {
        $v = $this->uint8($offset);
        return $v > 127 ? $v - 256 : $v;
    }

    /**
     * @param int $offset Byte offset
     * @return int Unsigned 16-bit little-endian value (Pascal word)
     */
    public function uint16(int $offset): int
    {
        return unpack('v', $this->bytes($offset, 2))[1];
    }

    /**
     * @param int $offset Byte offset
     * @return int Signed 16-bit little-endian value (Pascal integer)
     */
    public function int16(int $offset): int
    {
        $v = $this->uint16($offset);
        return $v > 32767 ? $v - 65536 : $v;
    }

    /**
     * @param int $offset Byte offset
     * @return int Signed 32-bit little-endian value (Pascal longint)
     */
    public function int32(int $offset): int
    {
        $v = unpack('V', $this->bytes($offset, 4))[1];
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    /**
     * @param int $offset Byte offset
     * @return int Unsigned 32-bit little-endian value
     */
    public function uint32(int $offset): int
    {
        return unpack('V', $this->bytes($offset, 4))[1];
    }

    /**
     * Read raw bytes with bounds checking.
     *
     * @param int $offset Byte offset
     * @param int $length Number of bytes
     * @return string
     * @throws DoorDataParseException When the read would pass the buffer end
     */
    public function bytes(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset + $length > strlen($this->buffer)) {
            throw new DoorDataParseException("Read of $length bytes at offset $offset is out of bounds");
        }
        return substr($this->buffer, $offset, $length);
    }
}
