# Customizing BinktermPHP

BinktermPHP is designed to be customized without touching core files, so your changes survive upgrades. This document covers the full customization system, from the point-and-click Appearance admin panel to low-level template overrides and custom shells.

---

## Table of Contents

- [Appearance System](#appearance-system)
  - [Shells](#shells)
  - [BBS Menu Shell](#bbs-menu-shell)
  - [ANSI Art Display](#ansi-art-display)
  - [Branding](#branding)
  - [Announcements](#announcements)
  - [System News](#system-news)
  - [House Rules](#house-rules)
  - [Navigation Links](#navigation-links)
  - [SEO Settings](#seo-settings)
- [Themes](#themes)
- [Template Overrides](#template-overrides)
- [Template Resolution Order](#template-resolution-order)
- [Custom Shells](#custom-shells)
- [Custom Routes](#custom-routes)
- [Header Insertions](#header-insertions)
- [Welcome Messages](#welcome-messages)
- [Twig Global Variables](#twig-global-variables)
- [Configuration File Reference](#configuration-file-reference)

---

## Appearance System

The Appearance system is the primary way to customize your BBS. All settings are managed through **Admin → Appearance** in the web interface and are stored in `data/appearance.json`. Changes take effect immediately — no restart required.

### Shells

A *shell* is the overall UI chrome that wraps every page. BinktermPHP ships with two shells:

| Shell | Description |
|-------|-------------|
| `web` | Modern Bootstrap 5 responsive interface. The default. |
| `bbs-menu` | Retro bulletin board menu interface with three display variants. |

**Shell selection priority** (highest to lowest):
1. User's personal preference — stored in the `UserMeta` table under the key `shell`, only honoured when the shell is not locked.
2. Sysop default — the `shell.active` value in `data/appearance.json`.
3. Built-in fallback — `web`.

To lock all users to a single shell, enable **Lock shell** in Admin → Appearance → Shell. When locked, the per-user preference is ignored.

Shell templates live in `templates/shells/<shell-name>/`. The active shell's directory is added to the Twig loader search path ahead of the core `templates/` directory, so any template placed there overrides its core equivalent.

---

### BBS Menu Shell

The `bbs-menu` shell replaces the standard navbar with a classic bulletin board main menu. Three variants are available:

#### `cards` (default)
A Bootstrap card grid. Each menu item shows an icon, label, and keyboard shortcut. Good for mouse and touch users.

#### `text`
A terminal-style numbered/lettered text menu rendered in a monospace font with cyan, yellow, and green colours. Feels like a traditional BBS.

#### `ansi`
Displays a full-screen ANSI art image (uploaded via Admin → Appearance → ANSI Art) above or instead of the menu. Uses the Perfect DOS VGA 437 web font (`public_html/fonts/PerfectDOSVGA437.ttf`) and the `public_html/css/ansisys.css` stylesheet for authentic CP437 rendering.

#### Customising menu items

The menu items shown in all three BBS menu variants are configurable through the Appearance admin panel. Each item has:

| Field | Description |
|-------|-------------|
| `key` | Single letter or number shown as the keyboard shortcut |
| `label` | Display name |
| `icon` | Font Awesome icon name (without the `fa-` prefix) |
| `url` | Destination URL |

Items can be reordered via drag-and-drop in the admin UI. The list is stored in `data/appearance.json` under `shell.bbs_menu.menu_items`.

---

### ANSI Art Display

When the `bbs-menu` shell is set to the `ansi` variant, BinktermPHP displays an ANSI art file on the dashboard.

**Uploading art files:**
1. Go to **Admin → Appearance → ANSI Art**.
2. Upload a `.ans`, `.asc`, or `.txt` file (CP437 encoded DOS ANSI art).
3. Select it from the dropdown in the Shell settings.

Art files are stored in `data/shell_art/`. BinktermPHP strips the SAUCE record (EOF marker `\x1A`) and converts CP437 bytes to UTF-8 before rendering, so standard ANSI editors (TheDraw, PabloDraw, etc.) produce compatible files.

The `public_html/css/ansisys.css` stylesheet provides:
- Fixed-width character cells (`.ansi-c`) to prevent glyph misalignment
- Full CGA/EGA colour class set (`.ansi-red`, `.ansi-bg-cyan`, `.ansi-bright-yellow`, etc.)
- Text attribute classes (`.ansi-bold`, `.ansi-blink`, `.ansi-reverse`)
- Responsive scaling (8 px on mobile, 6 px on very small screens)

---

### Branding

| Setting | Description |
|---------|-------------|
| Accent colour | CSS hex colour applied as the primary button and header colour via a `--bbs-accent` CSS variable. Leave blank to use the theme default. |
| Logo URL | URL of an image to replace the default system name text in the navbar. |
| Default theme | Force a specific theme stylesheet for all users. |
| Lock theme | When enabled, users cannot change their theme in Settings. |
| Footer text | Custom HTML or plain text shown in the page footer. |

---

### Announcements

Announcements appear as a dismissible banner at the top of every page (below the navbar). Settings:

| Field | Description |
|-------|-------------|
| Enabled | Show or hide the banner. |
| Text | The announcement message. HTML is allowed. |
| Type | Bootstrap alert type: `info`, `warning`, or `danger`. |
| Expires at | Optional date after which the banner stops showing automatically. |
| Dismissible | When enabled, users can close the banner. It stays closed for their session. |

Announcement state is stored in `data/appearance.json` under `content.announcement`.

---

### System News

System news is the content shown on the dashboard after login. Priority order:

1. **`data/systemnews.md`** — Managed via Admin → Appearance → System News. Write in Markdown; BinktermPHP renders it to HTML automatically.
2. **`templates/custom/systemnews.twig`** — Legacy Twig template override. See `templates/custom/systemnews.twig.example`.
3. **Built-in default** — A generic welcome message with the sysop's name and BinktermPHP version.

Using `data/systemnews.md` is recommended. It is upgrade-safe and editable through the admin UI.

---

### House Rules

House rules are displayed on the `/houserules` page when enabled. Write them in Markdown and save to `data/houserules.md` via Admin → Appearance → House Rules. If the file does not exist, the `/houserules` route is not shown.

---

### Navigation Links

Custom links can be added to the navigation bar under **Admin → Appearance → Navigation**. Each link has:

| Field | Description |
|-------|-------------|
| Label | Link text |
| URL | Destination (absolute or relative) |
| Open in new tab | When enabled, opens in a new browser tab |

Links are appended after the built-in navigation items and stored in `data/appearance.json` under `navigation.custom_links`.

---

### SEO Settings

| Setting | Description |
|---------|-------------|
| Site description | Meta description tag used by search engines. |
| OG image URL | Open Graph image for social sharing previews. |
| About page enabled | Enable the `/about` page, which displays the site description publicly. |

---

## Themes

Themes are CSS files that control the colour palette and overall visual style. Available themes are defined in `config/themes.json`:

```json
{
    "Amber":      "/css/amber.css",
    "Cyberpunk":  "/css/cyberpunk.css",
    "Dark":       "/css/dark.css",
    "Green Term": "/css/greenterm.css",
    "Regular":    "/css/style.css"
}
```

To add a custom theme:
1. Create a CSS file in `public_html/css/`.
2. Add an entry to `config/themes.json`.
3. The theme will appear in user Settings and in Admin → Appearance → Branding.

**Theme selection priority:**
1. If theme is locked by sysop → sysop default theme always wins.
2. User's theme preference (stored in `user_settings` table).
3. `STYLESHEET` environment variable in `.env`.
4. `/css/style.css` (built-in fallback).

You can also set the default stylesheet via `.env`:
```
STYLESHEET=/css/dark.css
```

---

## Template Overrides

Any core template can be overridden by placing a file with the same name in `templates/custom/`. The custom directory is checked first, so your file wins without modifying anything in `templates/`.

Example — override the dashboard:
```
cp templates/dashboard.twig templates/custom/dashboard.twig
# edit templates/custom/dashboard.twig
```

Files in `templates/custom/` are never touched by BinktermPHP updates.

---

## Template Resolution Order

When Twig resolves a template name, it searches these paths in order:

1. `templates/custom/` — Local overrides (highest priority)
2. `templates/shells/<active-shell>/` — Shell-specific templates
3. `templates/` — Core templates (lowest priority)

The first matching file wins. This means:
- A file in `templates/custom/` overrides both the shell and the core.
- A file in the active shell directory overrides only the core.
- Core templates are the fallback and should never be edited directly.

---

## Custom Shells

You can create a completely new shell without modifying any core file.

1. Create the directory `templates/shells/<your-shell-name>/`.
2. Add a `base.twig` that defines the page layout. Use the existing `templates/shells/web/base.twig` as a starting point.
3. Place any additional shell-specific templates in the same directory.
4. Set `shell.active` to `"<your-shell-name>"` in `data/appearance.json`, or select it via Admin → Appearance.

Shell templates have access to all [Twig global variables](#twig-global-variables). The `active_shell` global contains the current shell name, and `appearance` contains the full appearance configuration array.

---

## Custom Routes

To add new pages or API endpoints without touching core files, create the file `routes/web-routes.local.php`. It is loaded automatically if it exists and is never overwritten by updates.

Example:
```php
<?php
// routes/web-routes.local.php

$router->get('/hello', function() use ($template) {
    echo $template->render('custom/hello.twig', ['message' => 'Hello, world!']);
});
```

Place the corresponding template in `templates/custom/hello.twig`.

---

## Header Insertions

To inject HTML into the `<head>` of every page (analytics, custom CSS, external fonts, etc.), create:

```
templates/custom/header.insert.twig
```

See `templates/custom/header.insert.twig.example` for a reference with Google Analytics and other examples.

---

## Welcome Messages

Several plain-text files in `config/` control messages shown to users:

| File | Where it appears |
|------|-----------------|
| `config/welcome.txt` | Main page or login screen general welcome |
| `config/terminal_welcome.txt` | Replaces the default "SSH Connection to host:port" message on the terminal login page |
| `config/newuser_welcome.txt` | Email body sent to newly approved users |

Create the file if it does not exist. If it does not exist, the built-in default is shown.

---

## Twig Global Variables

These variables are available in every template:

| Variable | Type | Description |
|----------|------|-------------|
| `current_user` | array\|null | Authenticated user record (password_hash removed) |
| `system_name` | string | BBS name from `config/binkp.json` |
| `sysop_name` | string | Sysop name from `config/binkp.json` |
| `fidonet_origin` | string | Primary FTN address |
| `network_addresses` | array | All configured FTN addresses with domains |
| `csrf_token` | string | Per-user CSRF token for form submissions |
| `active_shell` | string | Currently active shell (`web` or `bbs-menu`) |
| `appearance` | array | Full `data/appearance.json` contents (merged with defaults) |
| `appearance_houserules_html` | string\|null | Rendered house rules HTML, or null if not set |
| `available_themes` | array | Map of theme name → CSS path from `config/themes.json` |
| `stylesheet` | string | Active theme CSS path |
| `app_version` | string | BinktermPHP version string |
| `app_name` | string | Application name |
| `app_full_version` | string | Full version string including name |
| `terminal_enabled` | bool | Whether the web terminal door is enabled |
| `webdoors_active` | bool | Whether the WebDoors game system is enabled |
| `credits_enabled` | bool | Whether the credits system is enabled |
| `credits_symbol` | string | Credits currency symbol |
| `credit_balance` | int | Current user's credit balance |
| `referral_enabled` | bool | Whether referrals are enabled |
| `default_echo_list` | string | User's preferred echo interface (`echolist` or `echoarea`) |
| `csrf_token` | string | CSRF token for POST forms |
| `favicon_svg` | string | Favicon SVG path |
| `favicon_ico` | string | Favicon ICO path |
| `favicon_png` | string | Favicon PNG path |

---

## Configuration File Reference

### `data/appearance.json`

Full schema with defaults:

```json
{
  "shell": {
    "active": "web",
    "lock_shell": false,
    "bbs_menu": {
      "variant": "cards",
      "menu_items": [
        { "key": "M", "label": "Messages",    "icon": "envelope", "url": "/echomail" },
        { "key": "N", "label": "Netmail",     "icon": "at",       "url": "/netmail"  },
        { "key": "F", "label": "Files",       "icon": "folder",   "url": "/files"    },
        { "key": "G", "label": "Games & Doors","icon": "gamepad", "url": "/games"    },
        { "key": "S", "label": "Settings",    "icon": "cog",      "url": "/settings" }
      ],
      "ansi_file": ""
    }
  },
  "branding": {
    "accent_color": "",
    "default_theme": "",
    "lock_theme": false,
    "logo_url": "",
    "footer_text": ""
  },
  "content": {
    "announcement": {
      "enabled": false,
      "text": "",
      "type": "info",
      "expires_at": null,
      "dismissible": true
    }
  },
  "navigation": {
    "custom_links": [
      { "label": "Example", "url": "https://example.com", "new_tab": false }
    ]
  },
  "seo": {
    "description": "",
    "og_image_url": "",
    "about_page_enabled": false
  }
}
```

All fields are optional. Missing fields fall back to the defaults shown above. Edit this file directly or use the Admin → Appearance UI — changes take effect immediately.
