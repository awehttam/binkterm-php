<?php
/**
 * Migration: 1.11.0.68 - add url_slug to files for human-readable markdown image URLs
 *
 * Adds a url_slug column to the files table for markdown_image rows.
 * The slug is derived from the original filename and the first 12 characters of the
 * SHA-256 hash: e.g. "retro-screenshot-07e6d7ea3e66.png".
 * Existing rows are backfilled. New rows are populated by FileAreaManager.
 */

return function ($db) {
    $db->exec("
        ALTER TABLE files
            ADD COLUMN IF NOT EXISTS url_slug VARCHAR(320) NULL
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_files_owner_slug
            ON files (owner_id, url_slug)
            WHERE subfolder = 'markdown-images'
    ");

    // Backfill existing markdown image rows
    $stmt = $db->query("
        SELECT id, filename, file_hash
        FROM files
        WHERE subfolder = 'markdown-images'
          AND url_slug IS NULL
    ");
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $update = $db->prepare("UPDATE files SET url_slug = ? WHERE id = ?");

    foreach ($rows as $row) {
        $slug = _mig_slugify_image_filename($row['filename'], $row['file_hash']);
        $update->execute([$slug, $row['id']]);
    }
};

/**
 * Compute a human-readable url_slug from an original filename and SHA-256 hash.
 * Result format: {sanitized-name}-{12char_hash}.{ext}
 */
function _mig_slugify_image_filename(string $filename, string $hash): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);

    $slug = strtolower($base);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 50);
    $slug = rtrim($slug, '-');
    if ($slug === '') {
        $slug = 'image';
    }

    return $slug . '-' . substr($hash, 0, 12);
}
