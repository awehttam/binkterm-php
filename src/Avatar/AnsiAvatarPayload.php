<?php

declare(strict_types=1);

namespace BinktermPHP\Avatar;

/**
 * Stateless input and output boundary for account-owned ANSI avatars.
 *
 * Copyright (c) 2026 Cythu contributors. Adapted for BinktermPHP.
 * BSD-3-Clause. See the repository LICENSE.
 */
final class AnsiAvatarPayload
{
    public static function parseBody(string $json): AnsiAvatar
    {
        if (strlen($json) > 1024) {
            throw new \InvalidArgumentException('errors.avatar.too_large');
        }
        $body = json_decode($json, true);
        if (!is_array($body) || !isset($body['ansi']) || !is_string($body['ansi'])) {
            throw new \InvalidArgumentException('errors.avatar.invalid');
        }
        // user_id and id, if a caller sends them, are not read. The route
        // binds the write to the authenticated session instead.
        return AnsiAvatar::fromBase64($body['ansi']);
    }

    /**
     * Length-safe comparison. hash_equals() throws when the lengths differ.
     */
    public static function csrfMatches(?string $expected, ?string $provided): bool
    {
        if (!is_string($expected) || strlen($expected) < 16 || !is_string($provided) || $provided === '') {
            return false;
        }
        if (strlen($expected) !== strlen($provided)) {
            return false;
        }
        return hash_equals($expected, $provided);
    }

    /**
     * @return array{ansi:string,cells:list<list<array{char:int,fg:int,bg:int,blink:bool,glyph:string}>>}
     */
    public static function present(AnsiAvatar $avatar): array
    {
        $rows = [];
        foreach ($avatar->cells() as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cell['glyph'] = Cp437::glyph($cell['char']);
                $cells[] = $cell;
            }
            $rows[] = $cells;
        }
        return ['ansi' => $avatar->toBase64(), 'cells' => $rows];
    }
}
