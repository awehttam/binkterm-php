# Upgrading to 1.8.3

Make sure you've made a backup of your database and files before upgrading.

## New Features

### Gemini Browser: Built-in Start Page

The Gemini Browser WebDoor now opens to a built-in start page (`about:home`) with curated links to popular Geminispace destinations (search engines, aggregators, community spaces, and software). Previously it opened directly to an external Gemini capsule.

The start page can be overridden per-installation: set `home_url` to any `gemini://` URL in Admin → WebDoors → Gemini Browser to use an external page instead.

### Gemini Capsule Hosting

BBS users can now publish personal Gemini capsules, accessible at
`gemini://your-bbs-host/home/username/`. A built-in directory page at
`gemini://your-bbs-host/` lists all users who have published capsules.

The new **Gemini Capsule** WebDoor provides a browser-based gemtext editor
with live preview, publish/draft controls, and per-file management.

**This feature is opt-in.** The capsule server is a separate daemon that
operators start only if they want to expose Gemini.

#### Setup (optional)

1. Add to `.env`:
   ```
   GEMINI_PORT=1965
   GEMINI_BIND_HOST=0.0.0.0
   ```
2. Enable the **Gemini Capsule** WebDoor in Admin → WebDoors.
3. Start the daemon:
   ```bash
   php scripts/gemini_daemon.php --daemon
   ```
See [docs/GeminiCapsule.md](GeminiCapsule.md) for full setup instructions,
including using a Let's Encrypt certificate instead of the default self-signed one.

### Gemini Capsule: Echo Area Exposure

Echo areas can now be exposed as read-only, publicly-accessible Gemini content. Each area can be opted in individually via the **Public Gemini Access** checkbox in Admin → Area Management → Echo Areas.

When enabled, the Gemini capsule home page lists the area under an **Echo Areas** section (with a link inviting visitors to join the BBS for the full experience), and three new routes become available:

| Route | Content |
|---|---|
| `gemini://host/echomail/` | List of all public echo areas |
| `gemini://host/echomail/TAG@domain/` | 50 most recent messages (oldest first) |
| `gemini://host/echomail/TAG@domain/{id}` | Individual message with headers and body |

The Gemini daemon logs the remote IP address for every request.

### Friendly URLs for Shared Messages

Echomail share links now use a human-readable URL based on the echo area and
message subject, e.g.:

```
/shared/test@lovlynet/hello-world
```

Both the new friendly URL and the original 32-character hex token URL remain
valid simultaneously — existing share links are never broken.

New shares are assigned a friendly URL automatically. To upgrade an existing
share, open the Share dialog for that message and click **Get Friendly URL**.

### Appearance System

A new sysop-controlled appearance system replaces the previous single-template approach. All settings are managed through **Admin → Appearance** and stored in `data/appearance.json`.

#### Shells

The UI chrome is now provided by interchangeable *shells*. Two shells ship with BinktermPHP:

- **`web`** — The existing Bootstrap 5 responsive interface. This is the default and requires no action to keep using.
- **`bbs-menu`** — A retro bulletin board main menu with three display variants:
  - `cards` — Bootstrap card grid with icons and keyboard shortcuts
  - `text` — Terminal-style text menu
  - `ansi` — Full-screen ANSI art display (upload art files via Admin → Appearance → ANSI Art)

Sysops choose the default shell and can optionally lock all users to it. When not locked, users can choose their preferred shell in their Settings page.

Shell templates live in `templates/shells/<shell-name>/`. The active shell's directory is searched before the core `templates/` directory, so shell templates override core ones automatically.

**Note:** The previous `templates/base.twig` has been superseded by `templates/shells/web/base.twig`. If you had direct customisations to `templates/base.twig`, move them to `templates/custom/base.twig` to preserve them across upgrades.

#### Branding

- Set a custom accent colour applied site-wide as a CSS variable
- Supply a custom logo URL to replace the system name in the navbar
- Force a default theme and optionally lock users to it
- Add custom footer text

#### Announcements

Post a site-wide dismissible banner with an optional expiry date. The banner type (`info`, `warning`, `danger`) controls its colour. Users who dismiss it will not see it again during their session.

#### System News & House Rules

System news (shown on the dashboard) and house rules (`/houserules`) can now be written in Markdown and managed directly through the Admin → Appearance panel, stored in `data/systemnews.md` and `data/houserules.md`. The previous `templates/custom/systemnews.twig` override still works as a fallback.

#### Navigation & SEO

Add custom links to the navigation bar and configure site-wide SEO metadata (meta description, Open Graph image) through the admin panel.

See [docs/CUSTOMIZING.md](CUSTOMIZING.md) for the full appearance system reference.

## Bug Fixes

### Binkp Packet Header: FSC-0048 Type-2+ Capability Word

Outbound FTN packets had `capWord = 0` in the Type-2+ packet header, which
caused receiving mailers (including sbbsecho/Synchronet) to fail the
`capWord != 0 && capWord == WORD_REVERSE(cwCopy)` validation check. As a
result, the originating point address was not read from the packet header and
messages were identified as coming from the wrong address (e.g. `1:3634/58`
instead of `1:3634/58.1337`), failing security checks on the remote system.

The header now correctly sets `capWord = 0x0001` (bit 0: Type-2+ capable) and
`cwCopy = 0x0100` (byte-swapped capWord) per FSC-0048.

Additionally, if no `me` address is configured for the destination uplink,
the daemon now throws a clear exception rather than silently producing a
malformed `0:0/0` origin address.

---

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

```bash
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar
php binkterm-installer.phar
scripts/restart_daemons.sh
```

