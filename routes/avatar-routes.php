<?php

declare(strict_types=1);

/**
 * Loaded inside the existing /api SimpleRouter group in api-routes.php.
 *
 * Reads require a signed-in caller. Writes are bound to that same session:
 * Auth::requireAuth() (via RouteHelper::requireAuth()) checks the X-CSRF-Token
 * header against users_meta.csrf_token for POST, PUT, PATCH, and DELETE.
 * A user id in the body is never the write subject.
 *
 * Copyright (c) 2026 Cythu contributors. Adapted for BinktermPHP.
 * BSD-3-Clause. See the repository LICENSE.
 */

use BinktermPHP\Avatar\AnsiAvatarApi;
use BinktermPHP\Database;
use BinktermPHP\RouteHelper;
use Pecee\SimpleRouter\SimpleRouter;

/**
 * @return array{0:int,1:array<string,mixed>}
 */
function avatarSessionUser(): array
{
    $user = RouteHelper::requireAuth();
    $uid = (int)($user['user_id'] ?? 0);
    if ($uid <= 0) {
        apiError('errors.auth.authentication_required', apiLocalizedText('errors.auth.authentication_required', 'Authentication required', $user), 401);
    }
    return [$uid, $user];
}

function avatarApiError(\InvalidArgumentException $e, array $user): void
{
    $code = $e->getMessage();
    if (preg_match('/^errors\.avatar\.[a-z0-9_.]+$/', $code) !== 1) {
        $code = 'errors.avatar.invalid';
    }
    apiError($code, apiLocalizedText($code, 'ANSI avatar data is not valid', $user), 400);
}

SimpleRouter::get('/user/ansi-avatar', function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    [$uid] = avatarSessionUser();
    $avatar = (new AnsiAvatarApi(Database::getInstance()->getPdo()))->read($uid);
    echo json_encode(['success' => true, 'avatar' => $avatar]);
});

SimpleRouter::get('/user/ansi-avatar/{id}', function ($id): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    [, $user] = avatarSessionUser();
    $target = (int)$id;
    $api = new AnsiAvatarApi(Database::getInstance()->getPdo());
    if (!$api->userIsActive($target)) {
        apiError(
            'errors.user.stats.user_not_found',
            apiLocalizedText('errors.user.stats.user_not_found', 'User not found', $user),
            404
        );
    }
    echo json_encode(['success' => true, 'avatar' => $api->read($target)]);
})->where(['id' => '[0-9]+']);

SimpleRouter::post('/user/ansi-avatar', function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    [$uid, $user] = avatarSessionUser();
    try {
        $avatar = (new AnsiAvatarApi(Database::getInstance()->getPdo()))->save($uid, (string)file_get_contents('php://input'));
    } catch (\InvalidArgumentException $e) {
        avatarApiError($e, $user);
    }
    if (function_exists('getServerLogger')) {
        getServerLogger()->info('ANSI avatar saved for user ' . $uid);
    }
    echo json_encode(['success' => true, 'avatar' => $avatar]);
});

SimpleRouter::delete('/user/ansi-avatar', function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    [$uid, $user] = avatarSessionUser();
    try {
        (new AnsiAvatarApi(Database::getInstance()->getPdo()))->remove($uid);
    } catch (\InvalidArgumentException $e) {
        avatarApiError($e, $user);
    }
    if (function_exists('getServerLogger')) {
        getServerLogger()->info('ANSI avatar removed for user ' . $uid);
    }
    echo json_encode(['success' => true, 'avatar' => null]);
});
