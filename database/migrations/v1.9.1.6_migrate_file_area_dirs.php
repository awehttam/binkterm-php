<?php
/**
 * Migration: Move file area storage directories to TAG-ID naming.
 *
 * This migration updates files.storage_path and moves files on disk from
 * data/files/TAG to data/files/TAG-ID.
 */

if (!isset($db) || !($db instanceof PDO)) {
    throw new RuntimeException('Database connection ($db) not provided to migration.');
}

$baseDir = realpath(__DIR__ . '/../../');
if ($baseDir === false) {
    throw new RuntimeException('Failed to resolve base directory.');
}

$filesBase = $baseDir . '/data/files';
if (!is_dir($filesBase)) {
    // Nothing to migrate if files directory doesn't exist
    return true;
}

$areas = $db->query("SELECT id, tag FROM file_areas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$areas) {
    return true;
}

foreach ($areas as $area) {
    $areaId = (int)$area['id'];
    $tag = (string)$area['tag'];
    if ($tag === '') {
        continue;
    }

    $oldDir = $filesBase . '/' . $tag;
    $newDir = $filesBase . '/' . $tag . '-' . $areaId;

    if (!is_dir($oldDir)) {
        continue;
    }

    if (!is_dir($newDir)) {
        mkdir($newDir, 0755, true);
    }

    $stmt = $db->prepare("
        SELECT id, filename, storage_path
        FROM files
        WHERE file_area_id = ?
    ");
    $stmt->execute([$areaId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as $file) {
        $fileId = (int)$file['id'];
        $storagePath = (string)$file['storage_path'];
        $filename = (string)$file['filename'];

        if ($storagePath === '') {
            continue;
        }

        // Already migrated
        if (strpos($storagePath, $newDir) === 0) {
            continue;
        }

        // Only migrate paths that point at the old directory
        if (strpos($storagePath, $oldDir) !== 0) {
            continue;
        }

        $sourcePath = $storagePath;
        if (!file_exists($sourcePath)) {
            $candidate = $newDir . '/' . $filename;
            if (file_exists($candidate)) {
                $update = $db->prepare("UPDATE files SET storage_path = ? WHERE id = ?");
                $update->execute([$candidate, $fileId]);
            }
            continue;
        }

        $targetPath = $newDir . '/' . $filename;
        if (file_exists($targetPath)) {
            $pathInfo = pathinfo($filename);
            $counter = 1;
            while (file_exists($targetPath)) {
                $newFilename = $pathInfo['filename'] . '_migrated_' . $counter;
                if (!empty($pathInfo['extension'])) {
                    $newFilename .= '.' . $pathInfo['extension'];
                }
                $targetPath = $newDir . '/' . $newFilename;
                $counter++;
            }
            $filename = basename($targetPath);
        }

        if (!rename($sourcePath, $targetPath)) {
            throw new RuntimeException("Failed to move file {$sourcePath} to {$targetPath}");
        }

        $update = $db->prepare("UPDATE files SET filename = ?, storage_path = ? WHERE id = ?");
        $update->execute([$filename, $targetPath, $fileId]);
    }
}

return true;
