<?php

/**
 * Authenticated HTTP checks against a disposable BinktermPHP server.
 * Usage: php tests/avatar_http_smoke.php http://127.0.0.1:8097
 *
 * Creates two temporary users and sessions, then deletes them.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use BinktermPHP\Auth;
use BinktermPHP\Avatar\AnsiAvatar;
use BinktermPHP\Avatar\AnsiAvatarStore;
use BinktermPHP\Database;

$base = rtrim($argv[1] ?? '', '/');
if ($base === '' || !str_starts_with($base, 'http://127.0.0.1:')) {
    fwrite(STDERR, "Refusing to call a non-local base URL\n");
    exit(2);
}

$db = Database::getInstance()->getPdo();
$created = [];

function makeUser(PDO $db, string $label, bool $active = true): int
{
    global $created;
    $suffix = bin2hex(random_bytes(4));
    $username = 'http' . $label . $suffix;
    $stmt = $db->prepare(
        'INSERT INTO users (username, real_name, password_hash, email, location, about_me, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $stmt->execute([
        $username,
        'Http ' . $label . ' ' . $suffix,
        password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
        $username . '@example.test',
        'Lab',
        'Keep this biography',
        $active ? 'true' : 'false',
    ]);
    $id = (int)$stmt->fetchColumn();
    $created[] = $id;
    return $id;
}

function sessionFor(int $userId): array
{
    return (new Auth())->createAuthenticatedSession($userId, 'web');
}

function request(string $base, string $method, string $path, ?string $session, ?string $csrf, ?string $body): array
{
    $headers = ["Accept: application/json"];
    if ($session !== null) {
        $headers[] = 'Cookie: binktermphp_session=' . $session;
    }
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $text = substr($raw, $headerSize);
    $json = json_decode($text, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'text' => $text];
}

function check(bool $ok, string $label, ?array $response = null): void
{
    if (!$ok) {
        $detail = $response === null ? '' : (' status=' . $response['status'] . ' body=' . substr((string)$response['text'], 0, 500));
        throw new RuntimeException($label . $detail);
    }
    echo "ok {$label}\n";
}

try {
    $owner = makeUser($db, 'owner');
    $other = makeUser($db, 'other');
    $inactive = makeUser($db, 'inactive', false);
    $ownerSession = sessionFor($owner);
    $otherSession = sessionFor($other);

    $rows = array_fill(0, 6, array_fill(0, 10, [0x41, 14, 1]));
    $rows[0][0] = [0x1B, 14, 1];
    $avatar = AnsiAvatar::fromCells($rows);
    $body = json_encode(['ansi' => $avatar->toBase64(), 'user_id' => $other], JSON_THROW_ON_ERROR);

    $missing = request($base, 'POST', '/api/user/ansi-avatar', $ownerSession['session_id'], null, $body);
    check($missing['status'] === 403 && ($missing['json']['error_code'] ?? '') === 'errors.auth.invalid_csrf_token', 'missing CSRF is 403');

    $sameLengthWrong = request($base, 'POST', '/api/user/ansi-avatar', $ownerSession['session_id'], str_repeat('ab', 32), $body);
    check($sameLengthWrong['status'] === 403, 'wrong same-length CSRF is 403');

    $shortToken = request($base, 'POST', '/api/user/ansi-avatar', $ownerSession['session_id'], 'short-token', $body);
    check(
        $shortToken['status'] === 403 && ($shortToken['json']['error_code'] ?? '') === 'errors.auth.invalid_csrf_token',
        'wrong-length CSRF is rejected'
    );

    $saved = request($base, 'POST', '/api/user/ansi-avatar', $ownerSession['session_id'], $ownerSession['csrf_token'], $body);
    check($saved['status'] === 200 && ($saved['json']['avatar']['ansi'] ?? '') === $avatar->toBase64(), 'owner save');

    $stored = (new AnsiAvatarStore($db))->find($owner);
    $otherCount = $db->prepare('SELECT COUNT(*) FROM user_ansi_avatars WHERE user_id = ?');
    $otherCount->execute([$other]);
    check($stored !== null && $stored->toBase64() === $avatar->toBase64(), 'BIN stored for the session user');
    check((int)$otherCount->fetchColumn() === 0, 'body user_id did not create a row');

    $profile = $db->prepare('SELECT email, location, about_me FROM users WHERE id = ?');
    $profile->execute([$owner]);
    $fields = $profile->fetch(PDO::FETCH_ASSOC);
    check($fields['about_me'] === 'Keep this biography' && $fields['location'] === 'Lab', 'profile text unchanged');

    $blank = str_repeat(" \x07", 60);
    $blankBody = json_encode(['ansi' => base64_encode($blank)], JSON_THROW_ON_ERROR);
    $blankRes = request($base, 'POST', '/api/user/ansi-avatar', $ownerSession['session_id'], $ownerSession['csrf_token'], $blankBody);
    check($blankRes['status'] === 400 && ($blankRes['json']['error_code'] ?? '') === 'errors.avatar.blank', 'blank rejected');

    $bad = request($base, 'POST', '/api/user/ansi-avatar', $ownerSession['session_id'], $ownerSession['csrf_token'], '{"ansi":"AAAA"}');
    check($bad['status'] === 400 && ($bad['json']['error_code'] ?? '') === 'errors.avatar.malformed', 'short base64 rejected');

    $own = request($base, 'GET', '/api/user/ansi-avatar', $ownerSession['session_id'], null, null);
    check($own['status'] === 200 && ($own['json']['avatar']['cells'][0][0]['char'] ?? null) === 0x1B, 'owner read');

    $public = request($base, 'GET', '/api/user/public-profile/' . $owner, $otherSession['session_id'], null, null);
    check($public['status'] === 200 && ($public['json']['profile']['ansi_avatar'] ?? '') === $avatar->toBase64(), 'public profile includes avatar');
    check(($public['json']['profile']['about_me'] ?? '') === 'Keep this biography', 'public biography intact');

    $hidden = request($base, 'GET', '/api/user/ansi-avatar/' . $inactive, $ownerSession['session_id'], null, null);
    check($hidden['status'] === 404, 'inactive public read is 404');

    $removed = request($base, 'DELETE', '/api/user/ansi-avatar', $ownerSession['session_id'], $ownerSession['csrf_token'], null);
    check($removed['status'] === 200 && array_key_exists('avatar', $removed['json'] ?? []) && $removed['json']['avatar'] === null, 'delete', $removed);
    $after = request($base, 'GET', '/api/user/ansi-avatar', $ownerSession['session_id'], null, null);
    check(
        $after['status'] === 200 && array_key_exists('avatar', $after['json'] ?? []) && $after['json']['avatar'] === null,
        'reload after delete',
        $after
    );

    $username = $db->query('SELECT username FROM users WHERE id = ' . (int)$owner)->fetchColumn();
    $page = request($base, 'GET', '/profile/' . rawurlencode((string)$username), $otherSession['session_id'], null, null);
    check($page['status'] === 200 && str_contains($page['text'], 'bt-ansi-fallback'), 'public page falls back to initials');

    echo "PASS http avatar checks\n";
} finally {
    if ($created !== []) {
        $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
        foreach ($created as $id) {
            $stmt->execute([$id]);
        }
    }
}
