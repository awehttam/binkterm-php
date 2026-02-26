<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */


namespace BinktermPHP;

/**
 * Read-only access to appearance configuration and content files.
 * All writes go through AdminDaemonClient to ensure correct file ownership.
 */
class AppearanceConfig
{
    private static ?array $config = null;
    private static bool $loaded = false;

    /** Default BBS menu items used when none are configured */
    private const DEFAULT_MENU_ITEMS = [
        ['key' => 'M', 'label' => 'Messages', 'icon' => 'envelope', 'url' => '/echomail'],
        ['key' => 'N', 'label' => 'Netmail', 'icon' => 'at', 'url' => '/netmail'],
        ['key' => 'F', 'label' => 'Files', 'icon' => 'folder', 'url' => '/files'],
        ['key' => 'G', 'label' => 'Games & Doors', 'icon' => 'gamepad', 'url' => '/games'],
        ['key' => 'S', 'label' => 'Settings', 'icon' => 'cog', 'url' => '/settings'],
    ];

    private static function getConfigPath(): string
    {
        return __DIR__ . '/../data/appearance.json';
    }

    private static function getSystemNewsPath(): string
    {
        return __DIR__ . '/../data/systemnews.md';
    }

    private static function getHouseRulesPath(): string
    {
        return __DIR__ . '/../data/houserules.md';
    }

    private static function getDefaults(): array
    {
        return [
            'shell' => [
                'active' => 'web',
                'lock_shell' => false,
                'bbs_menu' => [
                    'variant' => 'cards',
                    'menu_items' => self::DEFAULT_MENU_ITEMS,
                    'ansi_file' => '',
                ],
            ],
            'branding' => [
                'accent_color' => '',
                'default_theme' => '',
                'lock_theme' => false,
                'logo_url' => '',
                'footer_text' => '',
            ],
            'content' => [
                'announcement' => [
                    'enabled' => false,
                    'text' => '',
                    'type' => 'info',
                    'expires_at' => null,
                    'dismissible' => true,
                ],
            ],
            'navigation' => [
                'custom_links' => [],
            ],
            'seo' => [
                'description' => '',
                'og_image_url' => '',
                'about_page_enabled' => false,
            ],
            'message_reader' => [
                'scrollable_body' => true,
            ],
        ];
    }

    private static function deepMerge(array $defaults, array $override): array
    {
        $result = $defaults;
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = self::deepMerge($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;
        $path = self::getConfigPath();

        if (!file_exists($path)) {
            self::$config = self::getDefaults();
            return;
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            self::$config = self::getDefaults();
            return;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            self::$config = self::getDefaults();
            return;
        }

        self::$config = self::deepMerge(self::getDefaults(), $data);
    }

    /**
     * Get the full merged appearance config array.
     */
    public static function getConfig(): array
    {
        self::load();
        return self::$config ?? self::getDefaults();
    }

    /**
     * Clear the static cache so the next access re-reads the file.
     * Call this after the admin daemon has written a new appearance.json.
     */
    public static function reload(): void
    {
        self::$loaded = false;
        self::$config = null;
    }

    // -------------------------------------------------------------------------
    // Shell
    // -------------------------------------------------------------------------

    /**
     * The sysop-configured default shell ('web' or 'bbs-menu').
     */
    public static function getActiveShell(): string
    {
        self::load();
        $shell = (string)(self::$config['shell']['active'] ?? 'web');
        return in_array($shell, ['web', 'bbs-menu'], true) ? $shell : 'web';
    }

    /**
     * Whether users are prevented from overriding the shell.
     */
    public static function isShellLocked(): bool
    {
        self::load();
        return !empty(self::$config['shell']['lock_shell']);
    }

    /**
     * BBS menu sub-configuration (variant, items, ansi_file).
     */
    public static function getBbsMenuConfig(): array
    {
        self::load();
        $cfg = self::$config['shell']['bbs_menu'] ?? [];

        $variant = (string)($cfg['variant'] ?? 'cards');
        if (!in_array($variant, ['cards', 'ansi', 'text'], true)) {
            $variant = 'cards';
        }

        $items = $cfg['menu_items'] ?? self::DEFAULT_MENU_ITEMS;
        if (!is_array($items)) {
            $items = self::DEFAULT_MENU_ITEMS;
        }

        return [
            'variant' => $variant,
            'menu_items' => $items,
            'ansi_file' => (string)($cfg['ansi_file'] ?? ''),
        ];
    }

    // -------------------------------------------------------------------------
    // Branding
    // -------------------------------------------------------------------------

    public static function getAccentColor(): string
    {
        self::load();
        return (string)(self::$config['branding']['accent_color'] ?? '');
    }

    public static function getDefaultTheme(): string
    {
        self::load();
        return (string)(self::$config['branding']['default_theme'] ?? '');
    }

    public static function isThemeLocked(): bool
    {
        self::load();
        return !empty(self::$config['branding']['lock_theme']);
    }

    public static function getLogoUrl(): string
    {
        self::load();
        return (string)(self::$config['branding']['logo_url'] ?? '');
    }

    public static function getFooterText(): string
    {
        self::load();
        return (string)(self::$config['branding']['footer_text'] ?? '');
    }

    // -------------------------------------------------------------------------
    // Content
    // -------------------------------------------------------------------------

    /**
     * Announcement config with computed _active and _key fields.
     */
    public static function getAnnouncement(): array
    {
        self::load();
        $ann = self::$config['content']['announcement'] ?? [];

        $enabled = !empty($ann['enabled']);
        $expiresAt = $ann['expires_at'] ?? null;
        $active = $enabled;
        if ($active && $expiresAt) {
            try {
                $active = new \DateTime($expiresAt) > new \DateTime();
            } catch (\Exception $e) {
                $active = false;
            }
        }

        return array_merge($ann, [
            '_active' => $active,
            '_key' => substr(md5($ann['text'] ?? ''), 0, 8),
        ]);
    }

    /**
     * Raw Markdown content of system news, or null if not set.
     */
    public static function getSystemNewsMarkdown(): ?string
    {
        $path = self::getSystemNewsPath();
        if (!file_exists($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return ($content === false) ? null : $content;
    }

    /**
     * Raw Markdown content of house rules, or null if not set.
     */
    public static function getHouseRulesMarkdown(): ?string
    {
        $path = self::getHouseRulesPath();
        if (!file_exists($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return ($content === false) ? null : $content;
    }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    public static function getCustomLinks(): array
    {
        self::load();
        $links = self::$config['navigation']['custom_links'] ?? [];
        return is_array($links) ? $links : [];
    }

    // -------------------------------------------------------------------------
    // SEO
    // -------------------------------------------------------------------------

    public static function getSeoDescription(): string
    {
        self::load();
        return (string)(self::$config['seo']['description'] ?? '');
    }

    public static function getOgImageUrl(): string
    {
        self::load();
        return (string)(self::$config['seo']['og_image_url'] ?? '');
    }

    public static function isAboutPageEnabled(): bool
    {
        self::load();
        return !empty(self::$config['seo']['about_page_enabled']);
    }

    // -------------------------------------------------------------------------
    // Message Reader
    // -------------------------------------------------------------------------

    /**
     * Whether the message reader shows a scrollable body with a fixed header.
     */
    public static function isMessageReaderScrollable(): bool
    {
        self::load();
        return !empty(self::$config['message_reader']['scrollable_body']);
    }
}
