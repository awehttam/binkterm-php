<?php

namespace BinktermPHP\Pgp;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Minimal OpenPGP public key parser for armored key ingestion.
 *
 * This currently supports version 4 public key packets, which covers the
 * common GnuPG/OpenPGP.js public key formats used by the application.
 */
class ArmoredPublicKeyParser
{
    /**
     * Parse an ASCII-armored public key block and return metadata.
     *
     * @param string $armored
     * @return array<string, mixed>
     */
    public function parse(string $armored): array
    {
        $binary = $this->decodeArmor($armored);
        $packets = $this->parsePackets($binary);

        $publicKeyPacket = null;
        $userIds = [];

        foreach ($packets as $packet) {
            if ($packet['tag'] === 6 && $publicKeyPacket === null) {
                $publicKeyPacket = $packet;
                continue;
            }
            if ($packet['tag'] === 13) {
                $userIds[] = trim($packet['body']);
            }
        }

        if ($publicKeyPacket === null) {
            throw new InvalidArgumentException('No public key packet was found in the armored key.');
        }

        $body = $publicKeyPacket['body'];
        if (strlen($body) < 6) {
            throw new InvalidArgumentException('The public key packet is truncated.');
        }

        $version = ord($body[0]);
        if ($version !== 4) {
            throw new InvalidArgumentException('Only version 4 public keys are supported right now.');
        }

        $createdUnix = unpack('N', substr($body, 1, 4));
        $algorithmId = ord($body[5]);

        $fingerprint = strtoupper(sha1("\x99" . pack('n', strlen($body)) . $body));

        $createdAt = null;
        if (is_array($createdUnix) && isset($createdUnix[1])) {
            $createdAt = (new DateTimeImmutable('@' . (int)$createdUnix[1]))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:sP');
        }

        $userIds = array_values(array_filter(
            array_map(static fn(string $userId): string => trim($userId), $userIds),
            static fn(string $userId): bool => $userId !== ''
        ));

        $primaryUserId = $userIds[0] ?? null;
        $email = $this->extractEmail($userIds);

        return [
            'fingerprint' => $fingerprint,
            'algorithm' => $this->algorithmName($algorithmId),
            'key_created_at' => $createdAt,
            'user_ids' => $userIds,
            'user_id_string' => $primaryUserId,
            'email' => $email,
        ];
    }

    private function decodeArmor(string $armored): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($armored));
        if (!str_contains($normalized, '-----BEGIN PGP PUBLIC KEY BLOCK-----')) {
            throw new InvalidArgumentException('Expected an ASCII-armored public key block.');
        }

        $lines = explode("\n", $normalized);
        $inBody = false;
        $bodyLines = [];
        $headersDone = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '-----BEGIN PGP PUBLIC KEY BLOCK-----') {
                $inBody = true;
                $headersDone = false;
                continue;
            }
            if ($trimmed === '-----END PGP PUBLIC KEY BLOCK-----') {
                break;
            }
            if (!$inBody) {
                continue;
            }
            if (!$headersDone) {
                if ($trimmed === '') {
                    $headersDone = true;
                }
                continue;
            }
            if ($trimmed === '' || str_starts_with($trimmed, '=')) {
                continue;
            }
            $bodyLines[] = $trimmed;
        }

        $decoded = base64_decode(implode('', $bodyLines), true);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('The public key armor could not be decoded.');
        }

        return $decoded;
    }

    /**
     * @return array<int, array{tag:int, body:string}>
     */
    private function parsePackets(string $binary): array
    {
        $packets = [];
        $offset = 0;
        $length = strlen($binary);

        while ($offset < $length) {
            $header = ord($binary[$offset]);
            if (($header & 0x80) === 0) {
                throw new InvalidArgumentException('Invalid OpenPGP packet header.');
            }
            $offset++;

            if (($header & 0x40) !== 0) {
                $tag = $header & 0x3f;
                [$packetLength, $offset] = $this->readNewPacketLength($binary, $offset);
            } else {
                $tag = ($header >> 2) & 0x0f;
                [$packetLength, $offset] = $this->readOldPacketLength($binary, $offset, $header & 0x03);
            }

            if ($packetLength < 0 || ($offset + $packetLength) > $length) {
                throw new InvalidArgumentException('Invalid OpenPGP packet length.');
            }

            $packets[] = [
                'tag' => $tag,
                'body' => substr($binary, $offset, $packetLength),
            ];
            $offset += $packetLength;
        }

        return $packets;
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function readNewPacketLength(string $binary, int $offset): array
    {
        if (!isset($binary[$offset])) {
            throw new InvalidArgumentException('Missing OpenPGP packet length.');
        }

        $first = ord($binary[$offset]);
        $offset++;

        if ($first < 192) {
            return [$first, $offset];
        }

        if ($first <= 223) {
            if (!isset($binary[$offset])) {
                throw new InvalidArgumentException('Truncated OpenPGP packet length.');
            }
            $second = ord($binary[$offset]);
            $offset++;
            $length = (($first - 192) << 8) + $second + 192;
            return [$length, $offset];
        }

        if ($first === 255) {
            if (($offset + 4) > strlen($binary)) {
                throw new InvalidArgumentException('Truncated OpenPGP packet length.');
            }
            $length = unpack('N', substr($binary, $offset, 4));
            $offset += 4;
            return [(int)$length[1], $offset];
        }

        throw new InvalidArgumentException('Partial OpenPGP packet lengths are not supported.');
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function readOldPacketLength(string $binary, int $offset, int $lengthType): array
    {
        return match ($lengthType) {
            0 => [ord($binary[$offset] ?? "\0"), $offset + 1],
            1 => [(int)unpack('n', substr($binary, $offset, 2))[1], $offset + 2],
            2 => [(int)unpack('N', substr($binary, $offset, 4))[1], $offset + 4],
            default => throw new InvalidArgumentException('Indeterminate OpenPGP packet lengths are not supported.'),
        };
    }

    /**
     * @param array<int, string> $userIds
     */
    private function extractEmail(array $userIds): ?string
    {
        foreach ($userIds as $userId) {
            if (preg_match('/<([^>]+)>/', $userId, $matches) === 1) {
                return trim($matches[1]);
            }
            if (filter_var($userId, FILTER_VALIDATE_EMAIL)) {
                return $userId;
            }
        }

        return null;
    }

    private function algorithmName(int $algorithmId): string
    {
        return match ($algorithmId) {
            1, 2, 3 => 'RSA',
            16 => 'ElGamal',
            17 => 'DSA',
            18 => 'ECDH',
            19 => 'ECDSA',
            22 => 'EdDSA',
            default => 'Unknown',
        };
    }
}
