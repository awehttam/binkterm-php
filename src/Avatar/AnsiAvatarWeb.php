<?php

declare(strict_types=1);

namespace BinktermPHP\Avatar;

/**
 * A deliberately inert HTML plate. It does not serialize or embed ANSI/SGR escapes.
 *
 * Copyright (c) 2026 Cythu contributors. Adapted for BinktermPHP.
 * BSD-3-Clause. See the repository LICENSE.
 */
final class AnsiAvatarWeb
{
    private const BLOCK = [
        0xdb => 'full',
        0xdc => 'lower',
        0xdf => 'upper',
        0xdd => 'left',
        0xde => 'right',
        0xb0 => 'shade1',
        0xb1 => 'shade2',
        0xb2 => 'shade3',
    ];

    public static function render(AnsiAvatar $avatar, string $ownerName, bool $labelled = false, ?string $ariaLabel = null): string
    {
        $safeName = htmlspecialchars($ownerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = $ariaLabel !== null && $ariaLabel !== ''
            ? htmlspecialchars($ariaLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : 'ANSI avatar of ' . $safeName;
        $out = '<span class="bt-ansi-avatar"'
            . ($labelled
                ? ' role="img" aria-label="' . $label . '"'
                : ' aria-hidden="true"')
            . '>';
        foreach ($avatar->cells() as $row) {
            $out .= '<span class="bt-ansi-row">';
            foreach ($row as $cell) {
                $shape = self::BLOCK[$cell['char']] ?? null;
                $classes = 'bt-ansi-cell bt-ansi-fg-' . $cell['fg'] . ' bt-ansi-bg-' . $cell['bg'];
                if ($shape !== null) {
                    $out .= '<span class="' . $classes . ' bt-ansi-shape bt-ansi-shape-' . $shape . '"></span>';
                } else {
                    $glyph = htmlspecialchars(Cp437::glyph($cell['char']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $out .= '<span class="' . $classes . '">' . $glyph . '</span>';
                }
            }
            $out .= '</span>';
        }
        return $out . '</span>';
    }
}
