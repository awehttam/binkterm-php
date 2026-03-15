<?php

namespace BinktermPHP\Freq;

/**
 * Result of a FREQ resolution attempt.
 */
class FreqResult
{
    /** @var bool Whether the file will be served */
    public bool $served;

    /** @var string|null Absolute filesystem path to the file to serve */
    public ?string $filePath;

    /** @var string|null Filename to present to the requesting node */
    public ?string $servedName;

    /** @var int|null files.id for the served file (null for generated listings) */
    public ?int $fileId;

    /** @var string Reason for denial (empty string if served) */
    public string $denyReason;

    /** @var int File size in bytes (0 if not served) */
    public int $fileSize;

    /** @var bool True if the file was dynamically generated (e.g. ALLFILES.TXT) rather than a real file on disk */
    public bool $isGenerated;

    public function __construct(
        bool    $served,
        ?string $filePath    = null,
        ?string $servedName  = null,
        ?int    $fileId      = null,
        string  $denyReason  = '',
        int     $fileSize    = 0,
        bool    $isGenerated = false
    ) {
        $this->served      = $served;
        $this->filePath    = $filePath;
        $this->servedName  = $servedName;
        $this->fileId      = $fileId;
        $this->denyReason  = $denyReason;
        $this->fileSize    = $fileSize;
        $this->isGenerated = $isGenerated;
    }

    public static function denied(string $reason): self
    {
        return new self(false, null, null, null, $reason, 0);
    }

    public static function served(string $filePath, string $servedName, int $fileId, int $fileSize): self
    {
        return new self(true, $filePath, $servedName, $fileId, '', $fileSize);
    }

    public static function servedGenerated(string $filePath, string $servedName, int $fileSize): self
    {
        return new self(true, $filePath, $servedName, null, '', $fileSize, true);
    }
}
