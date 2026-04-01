<?php
/**
 * Migration: 1.11.0.69 - fix markdown image url_slug values that include a file extension
 *
 * Migration v1.11.0.68 backfilled url_slug values using a version of the slugifier that
 * appended the file extension (e.g. "my-image-9f40540a41c3.jpg"). The slugifier was
 * subsequently corrected to omit the extension so that SimpleRouter can match the slug
 * segment without a custom regex. This migration recomputes any existing url_slug that
 * contains a dot, replacing it with the extension-free form.
 */

return function ($db) {
    $stmt = $db->query("
        SELECT id, filename, file_hash
        FROM files
        WHERE subfolder = 'markdown-images'
          AND url_slug LIKE '%.%'
    ");
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return;
    }

    $update = $db->prepare("UPDATE files SET url_slug = ? WHERE id = ?");

    foreach ($rows as $row) {
        $slug = _mig69_slugify($row['filename'], $row['file_hash']);
        $update->execute([$slug, $row['id']]);
    }
};

/**
 * Compute an extension-free url_slug from original filename and SHA-256 hash.
 * Matches FileAreaManager::slugifyMarkdownImageFilename().
 */
function _mig69_slugify(string $filename, string $hash): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);

    $slug = strtolower($base);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = (string)substr($slug, 0, 50);
    $slug = rtrim($slug, '-');
    if ($slug === '') {
        $slug = 'image';
    }

    return $slug . '-' . substr($hash, 0, 12);
}
