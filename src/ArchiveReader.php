<?php

namespace BinktermPHP;

/**
 * Thrown when a ZIP entry exists but cannot be decompressed due to an unsupported
 * legacy compression method (e.g. shrink, reduce, implode).
 */
class ArchiveLegacyCompressionException extends \RuntimeException
{
    public function __construct(public readonly ?int $compMethod = null)
    {
        parent::__construct('legacy_compression');
    }
}

/**
 * Detect and browse archive files by magic-byte signatures.
 *
 * Uses ZipArchive for ZIP archives and the 7z CLI tool for all other
 * supported formats (RAR, 7-Zip, TAR, GZip, BZip2, XZ, LZH, ARJ, CAB).
 *
 * Primary entry points: detectType(), listContents(), extractEntry(), serveContent().
 */
class ArchiveReader
{
    /** Maximum entries returned by listContents(). */
    public const MAX_ENTRIES = 500;

    // -------------------------------------------------------------------------
    // Detection
    // -------------------------------------------------------------------------

    /**
     * Detect the archive type of a file from its magic bytes.
     *
     * @param  string      $path Absolute path to the file on disk.
     * @return string|null Type string, or null if not recognised.
     *                     Possible values: zip, rar, 7z, gz, bz2, xz, lzh, arj, cab, tar.
     */
    public static function detectType(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return null;
        }
        $bytes = fread($fh, 512);
        fclose($fh);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        // ZIP: PK\x03\x04 (standard) or PK\x05\x06 (empty archive)
        if (str_starts_with($bytes, "\x50\x4B\x03\x04") || str_starts_with($bytes, "\x50\x4B\x05\x06")) {
            return 'zip';
        }
        // RAR4: Rar!\x1a\x07\x00
        if (str_starts_with($bytes, "\x52\x61\x72\x21\x1A\x07\x00")) {
            return 'rar';
        }
        // RAR5: Rar!\x1a\x07\x01\x00
        if (str_starts_with($bytes, "\x52\x61\x72\x21\x1A\x07\x01")) {
            return 'rar';
        }
        // 7-Zip: 7z\xbc\xaf\x27\x1c
        if (str_starts_with($bytes, "\x37\x7A\xBC\xAF\x27\x1C")) {
            return '7z';
        }
        // GZip: \x1f\x8b
        if (str_starts_with($bytes, "\x1F\x8B")) {
            return 'gz';
        }
        // BZip2: BZh
        if (str_starts_with($bytes, "\x42\x5A\x68")) {
            return 'bz2';
        }
        // XZ: \xfd7zXZ\x00
        if (str_starts_with($bytes, "\xFD\x37\x7A\x58\x5A\x00")) {
            return 'xz';
        }
        // ARC: \x1a followed by compression method byte 1–9
        if (strlen($bytes) >= 2 && $bytes[0] === "\x1A" && ord($bytes[1]) >= 1 && ord($bytes[1]) <= 9) {
            return 'arc';
        }
        // ARJ: \x60\xea
        if (str_starts_with($bytes, "\x60\xEA")) {
            return 'arj';
        }
        // Cabinet: MSCF
        if (str_starts_with($bytes, "MSCF")) {
            return 'cab';
        }
        // LZH: -lh?- at byte positions 2–6 (e.g. -lh5-)
        if (strlen($bytes) >= 7 &&
            $bytes[2] === '-' && $bytes[3] === 'l' && $bytes[4] === 'h' && $bytes[6] === '-') {
            return 'lzh';
        }
        // TAR (POSIX): "ustar" at byte offset 257
        if (strlen($bytes) >= 262 && str_starts_with(substr($bytes, 257), 'ustar')) {
            return 'tar';
        }

        return null;
    }

    /**
     * Return a user-visible label for an archive type string.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'zip'  => 'ZIP',
            'rar'  => 'RAR',
            '7z'   => '7-Zip',
            'gz'   => 'GZip',
            'bz2'  => 'BZip2',
            'xz'   => 'XZ',
            'lzh'  => 'LZH',
            'arc'  => 'ARC',
            'arj'  => 'ARJ',
            'cab'  => 'Cabinet',
            'tar'  => 'TAR',
            default => strtoupper($type),
        };
    }

    // -------------------------------------------------------------------------
    // Listing
    // -------------------------------------------------------------------------

    /**
     * List entries in an archive.
     *
     * Returns an associative array:
     *   - entries: list<{path, name, size, comp_method?}>
     *   - total:   total file-entry count (may exceed MAX_ENTRIES)
     *   - tool_unavailable: (bool, non-ZIP only) true when 7z is required but absent
     *
     * @param  string $path Absolute path to the archive.
     * @param  string $type Archive type from detectType().
     * @return array<string, mixed>
     */
    public static function listContents(string $path, string $type): array
    {
        return $type === 'zip' ? self::listZip($path) : self::list7z($path);
    }

    // -------------------------------------------------------------------------
    // Extraction
    // -------------------------------------------------------------------------

    /**
     * Extract a single entry and return its raw bytes.
     *
     * For ZIP uses ZipArchive with a shell-tool fallback for legacy compression.
     * For other formats uses the 7z CLI (extracts to a temp directory).
     *
     * @param  string $archivePath Absolute path to the archive.
     * @param  string $entryPath   Path of the entry within the archive.
     * @param  string $type        Archive type from detectType().
     * @return string|false Bytes on success; false if the entry was not found or
     *                      the archive could not be opened.
     * @throws ArchiveLegacyCompressionException ZIP entry exists but cannot be decompressed.
     * @throws \RuntimeException With message 'tool_unavailable' when 7z is needed but absent.
     */
    public static function extractEntry(string $archivePath, string $entryPath, string $type): string|false
    {
        return $type === 'zip'
            ? self::extractZip($archivePath, $entryPath)
            : self::extract7z($archivePath, $entryPath);
    }

    // -------------------------------------------------------------------------
    // Content serving
    // -------------------------------------------------------------------------

    /**
     * Set response headers and output the content of an archive entry for inline
     * preview, using the same content-type / encoding logic as the /preview endpoint.
     *
     * Handles: images, video, audio, HTML (sandboxed), Markdown, known text
     * extensions (CP437→UTF-8 for art files), raw bytes for PRG/MOD, and a
     * ≥90%-printable heuristic for unknown extensions.
     *
     * @param string $content  Raw bytes of the extracted entry.
     * @param string $filename Basename used for extension detection and headers.
     */
    public static function serveContent(string $content, string $filename): void
    {
        $ext     = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $safe    = addslashes($filename);
        $encoded = rawurlencode($filename);

        // RIPscrip — raw text; browser-side renderer handles it
        if ($ext === 'rip') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: inline; filename="' . $safe . '"; filename*=UTF-8\'\'' . $encoded);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');
            echo $content;
            return;
        }

        // Images
        $imageMimes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
            'gif' => 'image/gif',  'webp' => 'image/webp', 'svg'  => 'image/svg+xml',
            'bmp' => 'image/bmp',  'ico'  => 'image/x-icon', 'avif' => 'image/avif',
        ];
        if (isset($imageMimes[$ext])) {
            header('Content-Type: ' . $imageMimes[$ext]);
            header('Content-Disposition: inline; filename="' . $safe . '"; filename*=UTF-8\'\'' . $encoded);
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: private, max-age=3600');
            echo $content;
            return;
        }

        // Video / Audio
        $mediaMimes = [
            'mp4'  => 'video/mp4',   'webm' => 'video/webm',  'mov'  => 'video/quicktime',
            'ogv'  => 'video/ogg',   'm4v'  => 'video/mp4',
            'mp3'  => 'audio/mpeg',  'wav'  => 'audio/wav',   'ogg'  => 'audio/ogg',
            'flac' => 'audio/flac',  'aac'  => 'audio/aac',   'm4a'  => 'audio/mp4',
            'opus' => 'audio/ogg',
        ];
        if (isset($mediaMimes[$ext])) {
            header('Content-Type: ' . $mediaMimes[$ext]);
            header('Content-Disposition: inline; filename="' . $safe . '"; filename*=UTF-8\'\'' . $encoded);
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: private, max-age=3600');
            echo $content;
            return;
        }

        // Markdown
        if ($ext === 'md') {
            $html = MarkdownRenderer::toHtml($content);
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: inline; filename="' . $safe . '"; filename*=UTF-8\'\'' . $encoded);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');
            echo $html;
            return;
        }

        // HTML — served in a sandboxed iframe
        if (in_array($ext, ['htm', 'html'], true)) {
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: inline; filename="' . $safe . '"; filename*=UTF-8\'\'' . $encoded);
            header('Content-Security-Policy: default-src \'none\'; img-src data: blob: http: https:; style-src \'unsafe-inline\'; font-src data: http: https:; media-src data: blob: http: https:; frame-ancestors \'self\'; base-uri \'none\'; form-action \'none\'');
            header('Referrer-Policy: no-referrer');
            header('Cross-Origin-Resource-Policy: same-origin');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');
            echo $content;
            return;
        }

        // Known text extensions (CP437→UTF-8 for legacy art formats)
        $textExts = [
            'txt', 'log', 'nfo', 'diz', 'asc', 'cfg', 'ini', 'conf', 'lsm',
            'json', 'xml', 'bat', 'sh', 'readme', 'ans', 'bbs',
        ];
        if (in_array($ext, $textExts, true)) {
            if (in_array($ext, ['nfo', 'diz', 'ans', 'bbs'], true)) {
                $converted = @iconv('CP437', 'UTF-8//IGNORE', $content);
                if ($converted !== false && strlen($converted) > 0) {
                    $content = $converted;
                }
            }
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: inline; filename="' . $safe . '"; filename*=UTF-8\'\'' . $encoded);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=3600');
            echo $content;
            return;
        }

        // PRG / MOD — raw bytes for client-side rendering
        if ($ext === 'prg' || $ext === 'mod') {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: inline; filename="' . $safe . '"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: private, max-age=3600');
            echo $content;
            return;
        }

        // Unknown extension — heuristic: ≥90% printable bytes → serve as text
        $sample = substr($content, 0, 4096);
        $isText = false;
        if ($sample !== '' && !str_contains($sample, "\x00")) {
            $len       = strlen($sample);
            $printable = 0;
            for ($i = 0; $i < $len; $i++) {
                $b = ord($sample[$i]);
                if (($b >= 0x20 && $b <= 0x7E) || $b === 0x09 || $b === 0x0A || $b === 0x0D || $b >= 0x80) {
                    $printable++;
                }
            }
            $isText = ($printable / $len) >= 0.90;
        }

        if ($isText) {
            if (!mb_check_encoding($content, 'UTF-8')) {
                $converted = @iconv('CP437', 'UTF-8//IGNORE', $content);
                if ($converted !== false && strlen($converted) > 0) {
                    $content = $converted;
                }
            }
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: inline; filename="' . $safe . '"; filename*=UTF-8\'\'' . $encoded);
            header('X-Content-Type-Options: nosniff');
            header('X-Binkterm-Heuristic: text');
            header('Cache-Control: private, max-age=3600');
            echo $content;
            return;
        }

        // Binary — offer as download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . $encoded);
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, must-revalidate');
        echo $content;
    }

    // -------------------------------------------------------------------------
    // ZIP (ZipArchive)
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function listZip(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return ['entries' => [], 'total' => 0];
        }

        $entries = [];
        $total   = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = $stat['name'];
            if (str_ends_with($name, '/')) {
                continue; // directory entry
            }
            $total++;
            if (count($entries) < self::MAX_ENTRIES) {
                $entries[] = [
                    'path'        => str_replace('\\', '/', $name),
                    'name'        => basename($name),
                    'size'        => (int)$stat['size'],
                    'comp_method' => (int)($stat['comp_method'] ?? 0),
                ];
            }
        }
        $zip->close();

        usort($entries, fn($a, $b) => strcmp($a['path'], $b['path']));
        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * @throws ArchiveLegacyCompressionException When the entry exists but cannot be decompressed.
     */
    private static function extractZip(string $archivePath, string $entryPath): string|false
    {
        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return false;
        }

        $lowerTarget  = strtolower(str_replace('\\', '/', $entryPath));
        $exactName    = null;
        $compMethod   = null;
        $expectedSize = -1;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false &&
                strtolower(str_replace('\\', '/', $stat['name'])) === $lowerTarget) {
                $exactName    = $stat['name'];
                $compMethod   = (int)($stat['comp_method'] ?? 0);
                $expectedSize = (int)($stat['size'] ?? -1);
                break;
            }
        }

        $content = $exactName !== null
            ? $zip->getFromName($exactName)
            : $zip->getFromName($entryPath, 0, \ZipArchive::FL_NOCASE);

        if ($content !== false && $expectedSize >= 0 && strlen($content) !== $expectedSize) {
            $content = false; // partial extraction — libzip limitation
        }

        $zip->close();

        // Shell fallback for legacy compression methods (shrink, reduce, implode…)
        if ($content === false && $exactName !== null && function_exists('shell_exec')) {
            $content = self::extractZipViaShell($archivePath, $exactName);
            if ($content !== false && $expectedSize >= 0 && strlen($content) !== $expectedSize) {
                $content = false;
            }
        }

        // Entry was found but could not be decompressed by any method
        if ($content === false && $exactName !== null) {
            throw new ArchiveLegacyCompressionException($compMethod);
        }

        return $content; // false = entry not found; string = success
    }

    private static function extractZipViaShell(string $archivePath, string $entryName): string|false
    {
        $null       = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bink_arc_' . bin2hex(random_bytes(8));
        if (!@mkdir($extractDir, 0700, true)) {
            return false;
        }

        $zipArg     = escapeshellarg($archivePath);
        $nameArg    = escapeshellarg($entryName);
        $destArg    = escapeshellarg($extractDir);
        $sevenZDest = '-o' . escapeshellarg($extractDir);

        $commands = [
            "unzip -o $zipArg $nameArg -d $destArg 2>$null",
            "7z x -y $sevenZDest $zipArg $nameArg 2>$null",
            "7za x -y $sevenZDest $zipArg $nameArg 2>$null",
        ];

        $content = false;
        try {
            foreach ($commands as $cmd) {
                @shell_exec($cmd);
                $extracted = $extractDir . DIRECTORY_SEPARATOR
                    . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $entryName);
                if (is_file($extracted)) {
                    $result = @file_get_contents($extracted);
                    if ($result !== false) {
                        $content = $result;
                        break;
                    }
                }
            }
        } finally {
            self::deleteTree($extractDir);
        }

        return $content;
    }

    // -------------------------------------------------------------------------
    // 7z CLI (all non-ZIP formats)
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list7z(string $path): array
    {
        $sevenZ = self::find7z();
        if ($sevenZ === null) {
            return ['entries' => [], 'total' => 0, 'tool_unavailable' => true];
        }

        $null   = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $output = @shell_exec($sevenZ . ' l -ba ' . escapeshellarg($path) . " 2>$null");
        if ($output === null || $output === '') {
            return ['entries' => [], 'total' => 0];
        }

        $all     = self::parse7zBareList($output);
        $entries = array_slice($all, 0, self::MAX_ENTRIES);
        return ['entries' => $entries, 'total' => count($all)];
    }

    /**
     * Parse the output of `7z l -ba` (bare list) into an entries array.
     *
     * The bare format is fixed-column, one file per line:
     *   Col 0-9:   date (YYYY-MM-DD)
     *   Col 11-18: time (HH:MM:SS)
     *   Col 20-24: attributes (e.g. "....A", "D....")
     *   Col 26-37: uncompressed size (right-aligned, 12 chars)
     *   Col 39-50: compressed size (right-aligned, 12 chars)
     *   Col 53+:   file name/path
     *
     * Using `-ba` instead of `-slt` avoids the block-separator parsing that
     * breaks on Windows due to CRLF line endings.
     *
     * @return list<array{path:string, name:string, size:int}>
     */
    private static function parse7zBareList(string $output): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $output) as $line) {
            // Lines shorter than 54 chars cannot contain a valid entry
            if (strlen($line) < 54) {
                continue;
            }
            // Only process data lines that start with a date (YYYY-MM-DD)
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $line)) {
                continue;
            }
            $attr    = substr($line, 20, 5);
            $sizeStr = trim(substr($line, 26, 12));
            $name    = rtrim(substr($line, 53));

            // Skip directory entries (attribute field contains 'D')
            if (str_contains($attr, 'D')) {
                continue;
            }
            if (!is_numeric($sizeStr) || $name === '') {
                continue;
            }

            $size = (int)$sizeStr;
            $name = str_replace('\\', '/', $name);

            // Filter LZH self-referencing artifact: some DOS LHA archivers embed
            // a 0-byte header entry containing the original absolute disk path
            // (e.g. "d:/path/to/archive.lzh"). Skip it.
            $isAbsPath = (bool)preg_match('/^[A-Za-z]:[\/\\\\]/', $name)
                      || str_starts_with($name, '/');
            if ($size === 0 && $isAbsPath) {
                continue;
            }

            $entries[] = [
                'path' => $name,
                'name' => basename($name),
                'size' => $size,
            ];
        }

        usort($entries, fn($a, $b) => strcmp($a['path'], $b['path']));
        return $entries;
    }

    /**
     * @throws \RuntimeException With message 'tool_unavailable' when 7z is not found.
     */
    private static function extract7z(string $archivePath, string $entryPath): string|false
    {
        $sevenZ = self::find7z();
        if ($sevenZ === null) {
            throw new \RuntimeException('tool_unavailable');
        }

        $null       = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bink_arc_' . bin2hex(random_bytes(8));
        if (!@mkdir($extractDir, 0700, true)) {
            return false;
        }

        // Use 'x' to preserve directory structure within the extract dir
        $destArg = '-o' . escapeshellarg($extractDir);
        $cmd     = $sevenZ . ' x -y ' . $destArg
                 . ' ' . escapeshellarg($archivePath)
                 . ' ' . escapeshellarg($entryPath)
                 . " 2>$null";

        $content = false;
        try {
            @shell_exec($cmd);
            $extractedPath = $extractDir . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $entryPath);
            if (is_file($extractedPath)) {
                $result = @file_get_contents($extractedPath);
                if ($result !== false) {
                    $content = $result;
                }
            }
        } finally {
            self::deleteTree($extractDir);
        }

        return $content;
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Locate the 7z executable on the system PATH.
     * Result is cached for the lifetime of the PHP process/worker.
     *
     * @return string|null Absolute path, or null if not found.
     */
    public static function find7z(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        foreach (['7z', '7za', '7zz'] as $bin) {
            $which  = PHP_OS_FAMILY === 'Windows' ? "where $bin 2>NUL" : "which $bin 2>$null";
            $result = @shell_exec($which);
            if ($result !== null && trim($result) !== '') {
                $cached = trim(explode("\n", trim($result))[0]);
                return $cached;
            }
        }

        $cached = '';
        return null;
    }

    /**
     * Recursively delete a directory tree (used to clean up temp extraction dirs).
     */
    public static function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($child) ? self::deleteTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
