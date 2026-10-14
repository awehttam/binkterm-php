<?php

declare(strict_types=1);

namespace BinktermPHP\Avatar;

/**
 * Synchronet-compatible 10x6 BIN avatar: sixty [CP437 byte, PC attribute byte]
 * pairs in row-major order. The BIN is inert data, never a terminal escape stream.
 *
 * Copyright (c) 2026 Cythu contributors. Adapted for BinktermPHP.
 * BSD-3-Clause. See the repository LICENSE.
 */
final class AnsiAvatar
{
    public const COLS = 10;
    public const ROWS = 6;
    public const BYTES = 120;

    private function __construct(private readonly string $bytes) {}

    public static function fromBin(string $bytes): self
    {
        if (strlen($bytes) !== self::BYTES) {
            throw new \InvalidArgumentException('errors.avatar.dimensions');
        }
        $avatar = new self($bytes);
        if ($avatar->isBlank()) {
            throw new \InvalidArgumentException('errors.avatar.blank');
        }
        return $avatar;
    }

    public static function fromBase64(string $encoded): self
    {
        if (strlen($encoded) !== 160 || preg_match('/^[A-Za-z0-9+\/]{160}$/D', $encoded) !== 1) {
            throw new \InvalidArgumentException('errors.avatar.malformed');
        }
        $bin = base64_decode($encoded, true);
        if ($bin === false || strlen($bin) !== self::BYTES || base64_encode($bin) !== $encoded) {
            throw new \InvalidArgumentException('errors.avatar.malformed');
        }
        return self::fromBin($bin);
    }

    /**
     * @param list<list<array{0:int,1:int,2:int}>> $rows Six rows of ten [CP437, fg, bg] cells.
     */
    public static function fromCells(array $rows): self
    {
        if (count($rows) !== self::ROWS) {
            throw new \InvalidArgumentException('errors.avatar.invalid');
        }
        $bytes = '';
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== self::COLS) {
                throw new \InvalidArgumentException('errors.avatar.invalid');
            }
            foreach ($row as $cell) {
                if (!is_array($cell) || count($cell) !== 3 || !is_int($cell[0]) || !is_int($cell[1]) || !is_int($cell[2])) {
                    throw new \InvalidArgumentException('errors.avatar.invalid');
                }
                [$char, $fg, $bg] = $cell;
                if ($char < 0 || $char > 255 || $fg < 0 || $fg > 15 || $bg < 0 || $bg > 7) {
                    throw new \InvalidArgumentException('errors.avatar.invalid');
                }
                $bytes .= chr($char) . chr(($bg << 4) | $fg);
            }
        }
        return self::fromBin($bytes);
    }

    public function toBin(): string
    {
        return $this->bytes;
    }

    public function toBase64(): string
    {
        return base64_encode($this->bytes);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->bytes, $other->bytes);
    }

    /**
     * @return list<list<array{char:int,fg:int,bg:int,blink:bool}>>
     */
    public function cells(): array
    {
        $out = [];
        for ($row = 0; $row < self::ROWS; $row++) {
            $line = [];
            for ($col = 0; $col < self::COLS; $col++) {
                $offset = ($row * self::COLS + $col) * 2;
                $attr = ord($this->bytes[$offset + 1]);
                $line[] = [
                    'char' => ord($this->bytes[$offset]),
                    'fg' => $attr & 15,
                    'bg' => ($attr >> 4) & 7,
                    'blink' => (bool)($attr & 128),
                ];
            }
            $out[] = $line;
        }
        return $out;
    }

    private function isBlank(): bool
    {
        for ($i = 0; $i < self::BYTES; $i += 2) {
            $char = ord($this->bytes[$i]);
            $bg = (ord($this->bytes[$i + 1]) >> 4) & 7;
            if (!in_array($char, [0x00, 0x20, 0xff], true) || $bg !== 0) {
                return false;
            }
        }
        return true;
    }
}
