<?php

namespace BinktermPHP\Freq;

use BinktermPHP\Database;

/**
 * Generates FILES.BBS-compatible text file listings for magic FREQ names.
 */
class MagicFileListGenerator
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Generate a combined listing of all freq_enabled areas.
     * Returns the path to a temporary file, or null on failure.
     *
     * @return string|null Path to generated temp file
     */
    public function generateAllFiles(): ?string
    {
        $stmt = $this->db->query(
            "SELECT fa.id, fa.tag, fa.description
             FROM file_areas fa
             WHERE fa.freq_enabled = TRUE AND fa.is_active = TRUE AND fa.is_private = FALSE
             ORDER BY fa.tag ASC"
        );
        $areas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($areas)) {
            return null;
        }

        $lines = [];
        $lines[] = 'File Areas — Generated ' . date('Y-m-d H:i:s T');
        $lines[] = str_repeat('-', 72);

        foreach ($areas as $area) {
            $areaLines = $this->buildAreaListing((int)$area['id'], (string)$area['tag'], (string)($area['description'] ?? ''));
            if (!empty($areaLines)) {
                $lines[] = '';
                array_push($lines, ...$areaLines);
            }
        }

        return $this->writeTempFile('ALLFILES.TXT', implode("\r\n", $lines) . "\r\n");
    }

    /**
     * Generate a listing for a single freq_enabled area.
     *
     * @param string $tag Area tag (case-insensitive)
     * @return string|null Path to generated temp file, or null if area not found
     */
    public function generateAreaListing(string $tag): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT id, tag, description FROM file_areas
             WHERE UPPER(tag) = UPPER(?) AND freq_enabled = TRUE AND is_active = TRUE AND is_private = FALSE
             LIMIT 1"
        );
        $stmt->execute([$tag]);
        $area = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$area) {
            return null;
        }

        $areaLines = $this->buildAreaListing((int)$area['id'], (string)$area['tag'], (string)($area['description'] ?? ''));
        if (empty($areaLines)) {
            return null;
        }

        $content = implode("\r\n", $areaLines) . "\r\n";
        return $this->writeTempFile(strtoupper($tag) . '.TXT', $content);
    }

    /**
     * @return string[] Lines for one area (no leading blank line)
     */
    private function buildAreaListing(int $areaId, string $tag, string $description): array
    {
        $stmt = $this->db->prepare(
            "SELECT filename, filesize, short_description, created_at
             FROM files
             WHERE file_area_id = ? AND status = 'approved'
             ORDER BY filename ASC"
        );
        $stmt->execute([$areaId]);
        $files = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($files)) {
            return [];
        }

        $header = $description !== '' ? "{$tag} — {$description}" : $tag;
        $lines  = [$header, str_repeat('-', 72)];

        foreach ($files as $f) {
            $name = str_pad((string)$f['filename'], 14);
            $size = str_pad(number_format((int)$f['filesize']), 10, ' ', STR_PAD_LEFT);
            $date = substr((string)($f['created_at'] ?? ''), 0, 10);
            $desc = trim((string)($f['short_description'] ?? ''));
            $lines[] = "{$name} {$size}  {$date}  {$desc}";
        }

        return $lines;
    }

    /**
     * Write content to a temp file and return its path.
     */
    private function writeTempFile(string $name, string $content): ?string
    {
        $dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freq_listings';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($path, $content) === false) {
            return null;
        }
        return $path;
    }
}
