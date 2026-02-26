# Upgrading to 1.8.3

Make sure you've made a backup of your database and files before upgrading.

## Summary of Changes

**New Features**
- Message Reader: scrollable body enabled by default — message header stays fixed while body scrolls; configurable in Admin → Appearance → Message Reader
- Gemini Browser: built-in start page (`about:home`) with curated Geminispace links
- Gemini Capsule Hosting: users can publish personal Gemini capsules at `gemini://host/home/username/`
- Gemini Capsule: echo areas can be exposed as read-only public Gemini content
- Friendly URLs for shared echomail messages (e.g. `/shared/test@lovlynet/hello-world`)
- Appearance System: shells, branding, announcements, system news/house rules, custom nav links, and SEO metadata managed through Admin → Appearance
- BBS Menu Shell: mobile improvements — ANSI scaling, tap-to-reveal shortcuts, context-aware hint text
- Address Book: "Always use crashmail" per-contact option
- File Share Links: share individual files via public `/shared/file/AREA/FILENAME` URLs
- Crashmail: binkp_zone DNS fallback — nodes not in the nodelist can be reached via a DNS zone (e.g. binkp.net)

**Bug Fixes**
- MarkdownRenderer: fixed link rendering across soft line breaks; added support for root-relative URLs
- FidoNet INTL kludge: removed point number from INTL line; added missing FMPT/TOPT in fallback packet path
- Binkp packet header: corrected FSC-0048 Type-2+ capability word (`capWord`/`cwCopy`); clearer error when no `me` address is configured
- Pipe codes: Mystic BBS letter-based control and information codes (e.g. `|AO`, `|PO`) now stripped correctly; Mystic hex colour codes (`|0A`, `|1F`, etc.) now parsed correctly; `|PI` and `|CD` rendered

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

### BBS Menu Shell: Mobile Improvements

The `bbs-menu` shell's ANSI art variant now adjusts the font size of the ANSI `<pre>` element to fit within 99% of the viewport width on mobile, and scales down on desktop if the art is wider than its container.

Tapping the ANSI art reveals a row of shortcut links for navigating the menu. Tapping again hides them. This replaces the previous floating keyboard-trigger button, which has been removed.

The `cards` and `text` variants now display context-appropriate hint text: "Press key to navigate" on desktop, "Tap an item to navigate" on mobile.

### Address Book: Always Use Crashmail

Address book entries now include an **Always use crashmail for this Recipient** checkbox. When enabled, composing a new message to that contact will automatically pre-check the crashmail option on the compose form.

This requires the migration `v1.10.10` — run `php scripts/setup.php` to apply it.

### File Share Links

Logged-in users can now share individual files from file areas via a public, human-readable link in the format:

```
/shared/file/AREANAME/FILENAME.ZIP
```

A **Share** button appears in the file details modal. Clicking it opens a share dialog where the user can set an optional expiry (1 hour, 24 hours, 1 week, 30 days, or never) and copy the generated link. The link can be revoked at any time from the same dialog.

The share page displays the filename, size, upload date, area tag, description, and virus scan status. Anonymous visitors see a login/register prompt in place of the download button. Logged-in users get a download button and a link to browse the file area.

This requires the migration `v1.10.11` — run `php scripts/setup.php` to apply it.

### Crashmail: binkp_zone DNS Fallback

Crashmail can now resolve destination nodes via DNS when a node is not present in the nodelist. Each uplink in `config/binkp.json` accepts an optional `binkp_zone` field specifying a DNS zone to query (e.g. `binkp.net`).

When crashmail cannot find connection information in the nodelist, it looks up the uplink that would normally route the destination address and checks whether that uplink has `binkp_zone` set. If so, it constructs a DNS hostname using the FidoNet address convention:

```
f{node}.n{net}.z{zone}.{binkp_zone}
# e.g. node 1:123/456 → f456.n123.z1.binkp.net
```

If the hostname resolves (A or CNAME record), that host is used for the crash delivery on the default binkp port (24554). No password is sent — the session is insecure, consistent with existing nodelist-based crash delivery.

To enable, add `binkp_zone` to an uplink in `config/binkp.json`:

```json
{
    "address": "1:1/23",
    "hostname": "uplink.example.com",
    "binkp_zone": "binkp.net",
    ...
}
```

No database migration is required. No changes are needed to existing configurations — the field is optional and ignored when empty.

### Message Reader: Scrollable Body

A new **Message Reader** tab in Admin → Appearance adds a **Scrollable message body** toggle.

When enabled, the message header (From, To, Area, Date, Subject) remains fixed at the top of the message modal while the body scrolls independently. This makes it easier to refer back to header information while reading long messages.

The default is on. To revert to the previous behaviour (entire modal scrolls together), disable the toggle in Admin → Appearance → Message Reader.

No database migration is required.

## Bug Fixes

### Pipe Code Rendering

Letter-based pipe codes used by Mystic BBS and compatible software were not handled, causing codes like `|AO` (abort off) and `|PO` (pause off) to appear as literal text in rendered messages.

The pipe code renderer now:

- **Strips** all Mystic BBS control codes (`|AO`, `|PO`, `|AA`, `|CL`, `|PA`, `|BE`, `|PB`, `|PE`, `|PN`, and others)
- **Converts** Mystic BBS cursor codes (`|[A##`, `|[B##`, `|[C##`, `|[D##`, `|[K`, `|[X##`, `|[Y##`) to their ANSI CSI equivalents and passes them to the existing ANSI terminal emulator
- **Strips** all Mystic BBS information codes (`|UN`, `|TI`, `|DA`, `|BN`, etc.) — these codes are substituted at runtime by the BBS and cannot be resolved in archived messages
- **Renders** `|PI` as a literal pipe character (`|`)
- **Renders** `|CD` as an ANSI colour reset
- **Fixes** Mystic-style hex colour codes (`|0A`, `|1F`, `|4E`, etc.) — previously these were parsed as decimal, producing the wrong colour; they are now parsed as two hex nibbles (upper = background, lower = foreground)

No configuration changes are required.

### MarkdownRenderer: Paragraph Link Rendering

Markdown links that wrapped across soft line breaks in the source text were not rendered correctly — the link syntax was split across two lines during processing, causing the URL to appear as plain text.

Root-relative URLs (e.g. `/subscriptions`) are now also recognised as valid link targets, in addition to `http://` and `https://` URLs.

### FidoNet INTL Kludge and Point Addressing (FTS-0001)

Outbound netmail had two related violations of the FTS-0001 point-addressing
specification:

1. **INTL kludge included the point number.** The INTL kludge was generated as
   `\x01INTL zone:net/node.point zone:net/node.point`, e.g.
   `\x01INTL 1:3634/12 1:3634/58.1337`. Per FTS-0001, INTL addresses must be
   `zone:net/node` only — the point is conveyed separately via FMPT/TOPT.
   The kludge is now correctly generated as `\x01INTL 1:3634/12 1:3634/58`
   with a separate `\x01FMPT 1337` line.

2. **FMPT/TOPT kludges were missing from the fallback packet-writing path.**
   When a stored message had no kludge lines (backward-compatibility path in
   `BinkdProcessor`), the generated INTL included the point but no FMPT was
   added. FMPT/TOPT are now generated in this path as well.

These fixes affect all outbound netmail sent from or to point addresses.
No database changes are required; new messages generated after upgrading will
have correct kludges.

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

