<?php

/**
 * Gemini Capsule WebDoor — API
 *
 * Handles CRUD for the current user's Gemini capsule files.
 *
 * Actions (GET ?action=<name>):
 *   file_list    — list user's files with publish status
 *   file_load    — GET ?filename=  — load a file's content
 *   capsule_url  — return the user's public capsule URL
 *
 * Actions (POST ?action=<name>, JSON body):
 *   file_save    — {filename, content}         — upsert a file
 *   file_delete  — {filename}                  — delete a file
 *   file_publish — {filename, is_published}    — toggle published flag
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Config;

header('Content-Type: application/json; charset=utf-8');

// ── Auth ───────────────────────────────────────────────────────────────────────
$user = WebDoorSDK\requireAuth();

// ── Door enabled check ─────────────────────────────────────────────────────────
if (!WebDoorSDK\isDoorEnabled('gemini-capsule')) {
    WebDoorSDK\jsonError('Gemini Capsule is not enabled', 403);
}

$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
$action = $_GET['action'] ?? '';

// ── Route ──────────────────────────────────────────────────────────────────────
switch ($action) {
    case 'file_list':
        handleFileList($userId);
        break;

    case 'file_load':
        handleFileLoad($userId);
        break;

    case 'file_save':
        handleFileSave($userId);
        break;

    case 'file_delete':
        handleFileDelete($userId);
        break;

    case 'file_publish':
        handleFilePublish($userId);
        break;

    case 'capsule_url':
        handleCapsuleUrl($user);
        break;

    default:
        WebDoorSDK\jsonError('Unknown action', 400);
}

// ── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Validate a capsule filename.
 * Allowed: alphanumeric, dash, underscore, with .gmi or .gemini extension.
 */
function isValidFilename(string $filename): bool
{
    return (bool)preg_match('/^[a-zA-Z0-9_\-]+\.(gmi|gemini)$/', $filename)
        && strlen($filename) <= 100;
}

// ── Action: file_list ──────────────────────────────────────────────────────────

/**
 * List all capsule files for the current user.
 */
function handleFileList(int $userId): void
{
    $db   = WebDoorSDK\getDatabase();
    $stmt = $db->prepare(
        'SELECT filename, is_published, updated_at
         FROM gemini_capsule_files
         WHERE user_id = ?
         ORDER BY filename'
    );
    $stmt->execute([$userId]);
    $files = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Normalise boolean from PostgreSQL
    foreach ($files as &$file) {
        $file['is_published'] = ($file['is_published'] === true || $file['is_published'] === 't');
    }
    unset($file);

    WebDoorSDK\jsonResponse(['files' => $files]);
}

// ── Action: file_load ──────────────────────────────────────────────────────────

/**
 * Load a single file's content.
 */
function handleFileLoad(int $userId): void
{
    $filename = trim($_GET['filename'] ?? '');

    if ($filename === '') {
        WebDoorSDK\jsonError('filename parameter is required', 400);
    }

    if (!isValidFilename($filename)) {
        WebDoorSDK\jsonError('Invalid filename', 400);
    }

    $db   = WebDoorSDK\getDatabase();
    $stmt = $db->prepare(
        'SELECT filename, content, is_published
         FROM gemini_capsule_files
         WHERE user_id = ? AND filename = ?'
    );
    $stmt->execute([$userId, $filename]);
    $file = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$file) {
        WebDoorSDK\jsonError('File not found', 404);
    }

    $file['is_published'] = ($file['is_published'] === true || $file['is_published'] === 't');

    WebDoorSDK\jsonResponse($file);
}

// ── Action: file_save ──────────────────────────────────────────────────────────

/**
 * Create or update a capsule file (upsert).
 */
function handleFileSave(int $userId): void
{
    $input    = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $filename = trim((string)($input['filename'] ?? ''));
    $content  = (string)($input['content'] ?? '');

    if ($filename === '') {
        WebDoorSDK\jsonError('filename is required', 400);
    }

    if (!isValidFilename($filename)) {
        WebDoorSDK\jsonError('Invalid filename — use letters, numbers, dashes, underscores with .gmi or .gemini extension', 400);
    }

    if (strlen($content) > 524288) {
        WebDoorSDK\jsonError('File content exceeds the 512 KB limit', 400);
    }

    $db   = WebDoorSDK\getDatabase();
    $stmt = $db->prepare(
        'INSERT INTO gemini_capsule_files (user_id, filename, content, updated_at)
         VALUES (?, ?, ?, NOW())
         ON CONFLICT (user_id, filename)
         DO UPDATE SET content = EXCLUDED.content, updated_at = NOW()'
    );
    $stmt->execute([$userId, $filename, $content]);

    WebDoorSDK\jsonResponse(['success' => true, 'filename' => $filename]);
}

// ── Action: file_delete ────────────────────────────────────────────────────────

/**
 * Delete a capsule file.
 */
function handleFileDelete(int $userId): void
{
    $input    = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $filename = trim((string)($input['filename'] ?? ''));

    if ($filename === '') {
        WebDoorSDK\jsonError('filename is required', 400);
    }

    if (!isValidFilename($filename)) {
        WebDoorSDK\jsonError('Invalid filename', 400);
    }

    $db   = WebDoorSDK\getDatabase();
    $stmt = $db->prepare(
        'DELETE FROM gemini_capsule_files WHERE user_id = ? AND filename = ?'
    );
    $stmt->execute([$userId, $filename]);

    WebDoorSDK\jsonResponse(['success' => true]);
}

// ── Action: file_publish ───────────────────────────────────────────────────────

/**
 * Toggle the published flag for a capsule file.
 */
function handleFilePublish(int $userId): void
{
    $input       = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $filename    = trim((string)($input['filename'] ?? ''));
    $isPublished = (bool)($input['is_published'] ?? false);

    if ($filename === '') {
        WebDoorSDK\jsonError('filename is required', 400);
    }

    if (!isValidFilename($filename)) {
        WebDoorSDK\jsonError('Invalid filename', 400);
    }

    $db   = WebDoorSDK\getDatabase();
    $stmt = $db->prepare(
        'UPDATE gemini_capsule_files
         SET is_published = ?, updated_at = NOW()
         WHERE user_id = ? AND filename = ?'
    );
    $stmt->execute([$isPublished ? 'true' : 'false', $userId, $filename]);

    if ($stmt->rowCount() === 0) {
        WebDoorSDK\jsonError('File not found', 404);
    }

    WebDoorSDK\jsonResponse(['success' => true, 'is_published' => $isPublished]);
}

// ── Action: capsule_url ────────────────────────────────────────────────────────

/**
 * Return the Gemini URL for the current user's capsule.
 */
function handleCapsuleUrl(array $user): void
{
    $host     = 'localhost';
    $siteUrl  = Config::getSiteUrl();
    $parsed   = parse_url($siteUrl);
    if (!empty($parsed['host'])) {
        $host = $parsed['host'];
    }

    $username = $user['username'] ?? '';
    WebDoorSDK\jsonResponse([
        'url' => "gemini://{$host}/home/{$username}/",
    ]);
}
