#!/usr/bin/env php
<?php
/**
 * Extract nodelist from ZIP archive
 *
 * This script extracts FidoNet-style nodelist files from ZIP archives.
 * It handles nested archives where a ZIP contains .Znn files (which are
 * themselves compressed in FidoNet format).
 *
 * Usage: php extract_nodelist_zip.php <zip_file>
 *
 * The script outputs the path to the extracted nodelist file to stdout.
 * The last line of output MUST be the file path for the update script to use it.
 *
 * Example (AgoraNet):
 *   Input:  agoranet.zip (outer archive)
 *   Extract: agoranet.z30 (FidoNet compressed, day 30)
 *   Output: /path/to/data/nodelists/agoranet.z30
 *
 *   Then import_nodelist.php extracts agoranet.z30 -> agoranet.030 (uncompressed)
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php extract_nodelist_zip.php <zip_file>\n");
    exit(1);
}

$zipFile = $argv[1];

if (!file_exists($zipFile)) {
    fwrite(STDERR, "Error: ZIP file not found: {$zipFile}\n");
    exit(1);
}

if (!extension_loaded('zip')) {
    fwrite(STDERR, "Error: ZIP extension not available\n");
    exit(1);
}

$zip = new ZipArchive;
if ($zip->open($zipFile) !== TRUE) {
    fwrite(STDERR, "Error: Failed to open ZIP file: {$zipFile}\n");
    exit(1);
}

// Extract directory (same as downloaded file location)
$extractDir = dirname($zipFile);

// Look for nodelist files inside the ZIP
// Patterns to match (in priority order):
// 1. FidoNet compressed: *.Znnn, *.Annn, *.Jnnn, *.Lnnn, *.Rnnn (e.g., agoranet.z30)
// 2. Plain nodelist: *.nnn (e.g., agoranet.030)
// 3. Common names: nodelist*, NODELIST*
$extractedFile = null;
$patterns = [
    '/^[A-Z0-9_-]+\.[ZzAaJjLlRr]\d{1,3}$/i',  // FidoNet compressed (highest priority)
    '/^[A-Z0-9_-]+\.\d{3}$/i',                  // Plain nodelist (3-digit day)
    '/^nodelist/i',                              // Anything starting with "nodelist"
];

fwrite(STDERR, "Searching for nodelist file in ZIP archive...\n");

for ($i = 0; $i < $zip->numFiles; $i++) {
    $filename = $zip->getNameIndex($i);
    $basename = basename($filename);

    // Skip directories
    if (substr($filename, -1) === '/') {
        continue;
    }

    // Check if filename matches any pattern
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $basename)) {
            $extractPath = $extractDir . DIRECTORY_SEPARATOR . $basename;

            // Extract the file
            if ($zip->extractTo($extractDir, $filename)) {
                // Handle nested directories in ZIP
                $actualFile = $extractDir . DIRECTORY_SEPARATOR . $filename;
                if ($actualFile !== $extractPath && file_exists($actualFile)) {
                    rename($actualFile, $extractPath);

                    // Clean up any empty directories created
                    $dirPath = dirname($actualFile);
                    if (is_dir($dirPath) && $dirPath !== $extractDir) {
                        @rmdir($dirPath);
                    }
                }

                $extractedFile = $extractPath;
                fwrite(STDERR, "Extracted: {$basename}\n");
                break 2;
            }
        }
    }
}

$zip->close();

if (!$extractedFile) {
    fwrite(STDERR, "Error: No nodelist file found in ZIP archive\n");
    fwrite(STDERR, "Searched for files matching:\n");
    fwrite(STDERR, "  - FidoNet compressed: *.Znnn, *.Annn, *.Jnnn, *.Lnnn, *.Rnnn\n");
    fwrite(STDERR, "  - Plain nodelist: *.nnn (3-digit day)\n");
    fwrite(STDERR, "  - Common names: nodelist*\n");
    exit(1);
}

if (!file_exists($extractedFile)) {
    fwrite(STDERR, "Error: Extraction failed, file not found: {$extractedFile}\n");
    exit(1);
}

// Output the extracted file path (MUST be last line for update script to parse)
echo $extractedFile;
exit(0);
