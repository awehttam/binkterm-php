<?php

namespace BinktermPHP;

/**
 * Parser for Commodore 64 D64 disk image files.
 *
 * Reads the directory (track 18) and extracts PRG files by following
 * the track/sector chain links. Supports standard 35-track images
 * (174,848 bytes) and extended 40-track images (~196,608 bytes).
 *
 * D64 sector layout:
 *   - Each sector is 256 bytes.
 *   - Sectors per track: 1–17 → 21, 18–24 → 19, 25–30 → 18, 31–40 → 17.
 *
 * Directory sector layout (track 18, sector 1+):
 *   - Bytes 0–1: T/S link to next directory sector (track 0 = end).
 *   - 8 entries at offsets 2, 34, 66, 98, 130, 162, 194, 226 (stride 32).
 *   - Each entry (30 bytes):
 *       +0  file type (bit 7=closed, bits 0–3: 0=DEL 1=SEQ 2=PRG 3=USR 4=REL)
 *       +1  first track of file
 *       +2  first sector of file
 *       +3  filename, 16 bytes, padded with $A0
 *       +19 side-sector track (REL)
 *       +20 side-sector sector (REL)
 *       +21 record length (REL)
 *       +22 unused (4 bytes)
 *       +26 @-save track/sector (2 bytes)
 *       +28 file size in blocks lo/hi
 */
class D64Parser
{
    private string $data;
    private int    $length;

    /** Sectors per track (1-indexed, tracks 1–40). */
    private const SECTORS_PER_TRACK = [
        1=>21, 2=>21, 3=>21, 4=>21, 5=>21, 6=>21, 7=>21, 8=>21,
        9=>21, 10=>21, 11=>21, 12=>21, 13=>21, 14=>21, 15=>21, 16=>21, 17=>21,
        18=>19, 19=>19, 20=>19, 21=>19, 22=>19, 23=>19, 24=>19,
        25=>18, 26=>18, 27=>18, 28=>18, 29=>18, 30=>18,
        31=>17, 32=>17, 33=>17, 34=>17, 35=>17,
        36=>17, 37=>17, 38=>17, 39=>17, 40=>17,
    ];

    private const DIR_TRACK    = 18;
    private const DIR_SECTOR   =  1;
    private const BAM_SECTOR   =  0;
    private const ENTRY_STRIDE = 32;
    private const ENTRIES_PER_SECTOR = 8;
    private const MAX_CHAIN_SECTORS  = 2000; // cycle / runaway guard

    public function __construct(string $data)
    {
        $this->data   = $data;
        $this->length = strlen($data);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the disk name from the BAM sector (track 18, sector 0), or ''.
     */
    public function diskName(): string
    {
        $bam = $this->readSector(self::DIR_TRACK, self::BAM_SECTOR);
        if ($bam === null) {
            return '';
        }
        return $this->petsciiToAscii(substr($bam, 144, 16));
    }

    /**
     * Extract all closed PRG files from the directory.
     *
     * @return array<int, array{name: string, load_address: int, data_b64: string}>
     */
    public function extractPrgs(): array
    {
        $prgs    = [];
        $track   = self::DIR_TRACK;
        $sector  = self::DIR_SECTOR;
        $visited = [];

        while ($track !== 0) {
            $key = "{$track}/{$sector}";
            if (isset($visited[$key])) {
                break; // cycle protection
            }
            $visited[$key] = true;

            $sec = $this->readSector($track, $sector);
            if ($sec === null) {
                break;
            }

            $nextTrack  = ord($sec[0]);
            $nextSector = ord($sec[1]);

            for ($i = 0; $i < self::ENTRIES_PER_SECTOR; $i++) {
                $off = 2 + $i * self::ENTRY_STRIDE;
                if ($off >= 256) {
                    break;
                }

                $fileType = ord($sec[$off]);

                // Bit 7: file is properly closed/valid.
                if (!($fileType & 0x80)) {
                    continue;
                }
                // Bits 0–3: file type must be PRG (2).
                if (($fileType & 0x0F) !== 0x02) {
                    continue;
                }

                $fileTrack  = ($off + 1 < 256) ? ord($sec[$off + 1]) : 0;
                $fileSector = ($off + 2 < 256) ? ord($sec[$off + 2]) : 0;
                if ($fileTrack === 0) {
                    continue;
                }

                $rawName = ($off + 19 <= 256) ? substr($sec, $off + 3, 16) : '';
                $name    = $this->petsciiToAscii($rawName);
                if ($name === '') {
                    continue;
                }

                $fileData = $this->readChain($fileTrack, $fileSector);
                if (strlen($fileData) < 3) {
                    continue; // too short to be a valid PRG (load address + 1 byte)
                }

                $loadAddress = ord($fileData[0]) | (ord($fileData[1]) << 8);
                $prgs[] = [
                    'name'         => $name . '.prg',
                    'load_address' => $loadAddress,
                    'data_b64'     => base64_encode(substr($fileData, 2)),
                ];
            }

            $track  = $nextTrack;
            $sector = $nextSector;
        }

        return $prgs;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Byte offset of the given track/sector within the image.
     */
    private function trackSectorOffset(int $track, int $sector): int
    {
        $totalSectors = 0;
        for ($t = 1; $t < $track; $t++) {
            $totalSectors += self::SECTORS_PER_TRACK[$t] ?? 17;
        }
        return ($totalSectors + $sector) * 256;
    }

    /**
     * Read a 256-byte sector, or null if out of bounds.
     */
    private function readSector(int $track, int $sector): ?string
    {
        if ($track < 1 || $track > 40) {
            return null;
        }
        $off = $this->trackSectorOffset($track, $sector);
        if ($off + 256 > $this->length) {
            return null;
        }
        return substr($this->data, $off, 256);
    }

    /**
     * Follow a T/S chain and return the concatenated file data.
     *
     * The first 2 bytes of each sector are the next-sector link.
     * If link track = 0, link sector = last valid byte index (inclusive, 1-based).
     * Non-last sectors contribute 254 bytes (bytes 2–255).
     */
    private function readChain(int $track, int $sector): string
    {
        $buf     = '';
        $visited = [];
        $guard   = 0;

        while ($track !== 0 && $guard++ < self::MAX_CHAIN_SECTORS) {
            $key = "{$track}/{$sector}";
            if (isset($visited[$key])) {
                break; // cycle
            }
            $visited[$key] = true;

            $sec = $this->readSector($track, $sector);
            if ($sec === null) {
                break;
            }

            $nextTrack  = ord($sec[0]);
            $nextSector = ord($sec[1]);

            if ($nextTrack === 0) {
                // Last sector: nextSector is the 1-based index of the last byte used.
                // Data bytes are at positions 2 .. nextSector (inclusive).
                $dataLen = max(0, $nextSector - 1);
                $buf    .= substr($sec, 2, $dataLen);
                break;
            } else {
                // Full sector: bytes 2–255 are data (254 bytes).
                $buf .= substr($sec, 2, 254);
            }

            $track  = $nextTrack;
            $sector = $nextSector;
        }

        return $buf;
    }

    /**
     * Convert a raw PETSCII string (with $A0 padding) to plain ASCII.
     * Stops at the first $A0 or null byte.
     */
    private function petsciiToAscii(string $raw): string
    {
        $out = '';
        for ($i = 0, $len = strlen($raw); $i < $len; $i++) {
            $b = ord($raw[$i]);
            if ($b === 0xA0 || $b === 0x00) {
                break;
            }
            // PETSCII printable range is the same as ASCII for 0x20–0x7E.
            $out .= ($b >= 0x20 && $b < 0x80) ? chr($b) : '?';
        }
        return rtrim($out);
    }
}
