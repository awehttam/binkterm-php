<?php

namespace BinktermPHP\TelnetShellPlugins;

use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\TuiShell;

/**
 * Minimal sample plugin shell.
 *
 * This keeps the built-in TUI behavior but swaps in a green monochrome palette
 * so plugin authors can see a visible shell-level customization immediately.
 */
class ExampleGreenScreenShell extends TuiShell
{
    public function __construct(BbsSession $server)
    {
        parent::__construct($server);
    }

    protected function buildStyleProfile(): array
    {
        $profile = parent::buildStyleProfile();

        $greenFrame = "\033[1;32m";
        $greenText = "\033[32m";
        $greenDim = "\033[2;32m";
        $greenBody = "\033[40m\033[32m";
        $greenHilite = "\033[40m\033[1;32m";
        $greenBg = "\033[40m";

        $profile['panel'] = [
            'border' => $greenFrame,
            'divider' => $greenText,
            'title_bar' => $greenFrame,
        ];
        $profile['list']['title'] = $greenFrame;
        $profile['list']['selected_bg'] = $greenHilite;

        $profile['scrollable_panel'] = [
            'border' => $greenFrame,
            'divider' => $greenText,
            'title_bar' => $greenFrame,
            'body' => $greenBody,
            'status_bar_bg' => $greenBg,
        ];
        $profile['dialog'] = [
            'bg' => $greenBg,
            'frame' => $greenFrame,
            'body' => $greenBody,
            'hint' => $greenFrame,
            'choice_key' => $greenFrame,
            'choice_label' => $greenText,
        ];
        $profile['help_overlay'] = [
            'bg' => $greenBg,
            'frame' => $greenFrame,
            'body' => $greenBody,
            'key' => $greenFrame,
            'status_key' => $greenFrame,
            'status_label' => $greenText,
        ];
        $profile['working_overlay'] = [
            'bg' => $greenBg,
            'frame' => $greenFrame,
            'body' => $greenBody,
        ];
        $profile['checkbox_dialog'] = [
            'bg' => $greenBg,
            'frame' => $greenFrame,
            'body' => $greenBody,
            'hilite' => $greenHilite,
            'dim' => $greenDim,
        ];
        $profile['status_bar'] = [
            'bg' => $greenBg,
            'text' => $greenText,
            'fill' => $greenText,
            'key' => $greenFrame,
            'label' => $greenText,
        ];
        $profile['header_box'] = [
            'bg' => $greenBg,
            'frame' => $greenFrame,
            'body' => $greenBody,
        ];
        $profile['selectable_dialog'] = [
            'bg' => $greenBg,
            'frame' => $greenFrame,
            'body' => $greenBody,
            'hilite' => $greenHilite,
            'dim' => $greenDim,
        ];
        $profile['image_prompt'] = [
            'bg' => $greenBg,
            'frame' => $greenFrame,
            'body' => $greenBody,
        ];
        $profile['profile_viewer'] = [
            'bio_label' => $greenFrame,
            'status_key' => $greenFrame,
            'status_label' => $greenText,
        ];
        $profile['file_detail_panel'] = [
            'border' => $greenFrame,
            'divider' => $greenText,
            'title_bar' => $greenFrame,
            'body' => $greenBody,
            'status_bar_bg' => $greenBg,
        ];
        $profile['alert'] = [
            'info' => [
                'bg' => $greenBg,
                'frame' => $greenFrame,
                'body' => $greenBody,
            ],
            'error' => [
                'bg' => $greenBg,
                'frame' => "\033[1;33m",
                'body' => "\033[40m\033[33m",
            ],
        ];

        return $profile;
    }
}
