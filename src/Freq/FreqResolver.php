<?php

namespace BinktermPHP\Freq;

use BinktermPHP\Database;

/**
 * Resolves inbound FREQ requests against freq-enabled file areas and shared files.
 * Logs every attempt to freq_log and queues fulfilled files in freq_outbound.
 */
class FreqResolver
{
    private \PDO $db;

    /** Magic names that trigger file-listing generation */
    private const MAGIC_ALL = ['ALLFILES', 'FILES'];

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Resolve a single FREQ request from a binkp M_GET command.
     *
     * @param string      $filename      Requested filename or magic name
     * @param string|null $password      Password supplied by requester
     * @param int         $sizeLimit     Max bytes requester will accept (0 = unlimited)
     * @param int         $newerThan     Unix timestamp lower bound (0 = any)
     * @param string      $callerAddr    FTN address of the requesting node
     * @param string      $source        'binkp' or 'netmail'
     * @param string|null $sessionId     Optional session correlation string
     * @return FreqResult
     */
    public function resolve(
        string  $filename,
        ?string $password,
        int     $sizeLimit,
        int     $newerThan,
        string  $callerAddr,
        string  $source    = 'binkp',
        ?string $sessionId = null
    ): FreqResult {
        // Magic names first
        $magic = $this->resolveMagic($filename, $callerAddr);
        if ($magic !== null) {
            $this->logRequest($callerAddr, $filename, $magic, $source, $sessionId);
            return $magic;
        }

        // Look up in freq_enabled areas
        $result = $this->resolveFromArea($filename, $password, $sizeLimit, $newerThan);
        if ($result === null) {
            // Try shared_files with freq_accessible
            $result = $this->resolveFromShare($filename, $sizeLimit, $newerThan);
        }
        if ($result === null) {
            $result = FreqResult::denied('not_found');
        }

        $this->logRequest($callerAddr, $filename, $result, $source, $sessionId);
        return $result;
    }

    /**
     * Process all filenames from a netmail FREQ subject line.
     * Queues served files in freq_outbound for delivery to the requesting node.
     *
     * @param string $subject    Subject line (space-separated filenames)
     * @param string $body       Message body (may contain password on first line)
     * @param string $fromAddr   FTN address of the requesting node
     * @return int Number of files queued
     */
    public function processNetmailFreq(string $subject, string $body, string $fromAddr): int
    {
        // Password may appear on the first non-blank body line
        $password = null;
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line !== '' && strpos($line, "\x01") !== 0) {
                $password = $line;
                break;
            }
        }

        $filenames = preg_split('/\s+/', trim($subject), -1, PREG_SPLIT_NO_EMPTY);
        $queued = 0;

        foreach ($filenames as $filename) {
            if ($filename === '') {
                continue;
            }
            $result = $this->resolve($filename, $password, 0, 0, $fromAddr, 'netmail');
            if ($result->served) {
                $this->queueForDelivery($fromAddr, $result);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Check whether $filename is a magic name and return a result if so.
     */
    private function resolveMagic(string $filename, string $callerAddr): ?FreqResult
    {
        $upper = strtoupper($filename);

        if (in_array($upper, self::MAGIC_ALL, true)) {
            $generator = new MagicFileListGenerator();
            $path = $generator->generateAllFiles();
            if ($path === null) {
                return FreqResult::denied('not_found');
            }
            return FreqResult::servedGenerated($path, 'ALLFILES.TXT', (int)filesize($path));
        }

        // Check if the magic name matches a freq_enabled area tag
        $stmt = $this->db->prepare(
            "SELECT id, tag FROM file_areas
             WHERE UPPER(tag) = ? AND freq_enabled = TRUE AND is_active = TRUE AND is_private = FALSE
             LIMIT 1"
        );
        $stmt->execute([$upper]);
        $area = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($area) {
            $generator = new MagicFileListGenerator();
            $path = $generator->generateAreaListing((string)$area['tag']);
            if ($path === null) {
                return FreqResult::denied('not_found');
            }
            return FreqResult::servedGenerated($path, strtoupper((string)$area['tag']) . '.TXT', (int)filesize($path));
        }

        return null;
    }

    /**
     * Look up $filename in freq_enabled file areas.
     */
    private function resolveFromArea(string $filename, ?string $password, int $sizeLimit, int $newerThan): ?FreqResult
    {
        $stmt = $this->db->prepare(
            "SELECT f.id, f.filename, f.storage_path, f.filesize, f.created_at,
                    fa.freq_password
             FROM files f
             JOIN file_areas fa ON f.file_area_id = fa.id
             WHERE LOWER(f.filename) = LOWER(?)
               AND f.status = 'approved'
               AND fa.freq_enabled = TRUE
               AND fa.is_active = TRUE
               AND fa.is_private = FALSE
             LIMIT 1"
        );
        $stmt->execute([$filename]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Password check
        if ($row['freq_password'] !== null && $row['freq_password'] !== '') {
            if ($password !== $row['freq_password']) {
                return FreqResult::denied('password');
            }
        }

        return $this->applyFilters($row, $sizeLimit, $newerThan);
    }

    /**
     * Look up $filename in shared_files with freq_accessible = TRUE.
     */
    private function resolveFromShare(string $filename, int $sizeLimit, int $newerThan): ?FreqResult
    {
        $stmt = $this->db->prepare(
            "SELECT f.id, f.filename, f.storage_path, f.filesize, f.created_at
             FROM files f
             JOIN shared_files sf ON sf.file_id = f.id
             JOIN file_areas fa ON f.file_area_id = fa.id
             WHERE LOWER(f.filename) = LOWER(?)
               AND f.status = 'approved'
               AND fa.is_private = FALSE
               AND sf.freq_accessible = TRUE
               AND sf.is_active = TRUE
               AND (sf.expires_at IS NULL OR sf.expires_at > NOW())
             LIMIT 1"
        );
        $stmt->execute([$filename]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->applyFilters($row, $sizeLimit, $newerThan);
    }

    /**
     * Apply size and timestamp filters and build a served FreqResult.
     *
     * @param array $row DB row with id, filename, storage_path, filesize, created_at
     */
    private function applyFilters(array $row, int $sizeLimit, int $newerThan): FreqResult
    {
        $size = (int)$row['filesize'];

        if ($sizeLimit > 0 && $size > $sizeLimit) {
            return FreqResult::denied('size_limit');
        }

        if ($newerThan > 0) {
            $fileTs = strtotime((string)$row['created_at']);
            if ($fileTs !== false && $fileTs <= $newerThan) {
                // Not newer than requested — silent skip per protocol convention
                return FreqResult::denied('timestamp');
            }
        }

        $path = (string)$row['storage_path'];
        if (!file_exists($path) || !is_readable($path)) {
            return FreqResult::denied('not_available');
        }

        return FreqResult::served($path, (string)$row['filename'], (int)$row['id'], $size);
    }

    /**
     * Insert a row into freq_outbound so the file is delivered on the next session.
     */
    private function queueForDelivery(string $toAddress, FreqResult $result): void
    {
        if (!$result->served || $result->filePath === null) {
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO freq_outbound (to_address, file_path, original_filename, file_size, created_at, status)
             VALUES (?, ?, ?, ?, NOW(), 'pending')"
        );
        $stmt->execute([
            $toAddress,
            $result->filePath,
            $result->servedName ?? basename($result->filePath),
            $result->fileSize,
        ]);
    }

    /**
     * Log a FREQ attempt to freq_log.
     */
    private function logRequest(
        string     $callerAddr,
        string     $filename,
        FreqResult $result,
        string     $source,
        ?string    $sessionId
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO freq_log
                (requesting_node, filename, resolved_file_id, served, deny_reason, file_size, source, session_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $callerAddr,
            $filename,
            $result->fileId,
            $result->served ? 'true' : 'false',
            $result->denyReason !== '' ? $result->denyReason : null,
            $result->served ? $result->fileSize : null,
            $source,
            $sessionId,
        ]);
    }
}
