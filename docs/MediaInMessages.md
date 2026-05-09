# Media in Messages

BinktermPHP can render rich media embedded in echomail and netmail messages. This covers both inline media URLs (images, video, audio, platform embeds) and text-based graphical formats (ANSI art, SIXEL, RIPscrip, Markdown).

---

## Table of Contents

- [How It Works](#how-it-works)
- [Supported Media Types](#supported-media-types)
  - [Images](#images)
  - [Video](#video)
  - [Audio](#audio)
  - [Retro Audio (Tracker Modules, SID, MIDI)](#retro-audio)
  - [Platform Embeds](#platform-embeds)
- [Text Art Rendering](#text-art-rendering)
  - [ANSI Art](#ansi-art)
  - [SIXEL Graphics](#sixel-graphics)
  - [RIPscrip](#ripscrip)
  - [Pipe Codes](#pipe-codes)
- [Markdown / Markup](#markdown--markup)
- [Message Attachments](#message-attachments)
- [Render Modes](#render-modes)
- [Admin Configuration](#admin-configuration)
- [Architecture Reference](#architecture-reference)

---

## How It Works

When a message is opened in the web reader, the JavaScript media engine (`public_html/js/media-player.js`) scans all links in the rendered message body. For each link it identifies the media type and either:

- Renders it inline immediately (auto mode), or
- Intercepts clicks on the link and shows a small popup menu with **Load player** and **Open in new tab** options (click mode).

Client-side platform embeds (YouTube, Odysee, BitChute, Brighteon, PeerTube) are resolved directly in the browser from the URL. oEmbed providers (Rumble, SoundCloud, Twitter/X, TikTok, ReverbNation) are fetched client-side from the provider's oEmbed endpoint, with a fallback to the server-side proxy at `GET /api/media/embed` when CORS blocks the direct request. Bastyon video posts are always resolved server-side through `GET /api/media/embed`. Retro audio formats are proxied through `GET /api/media/raw`.

The scan runs via `BinkMediaPlayer.scan()` after every message render, including when switching messages and when viewing shared messages. The message API response includes a resolved `allow_media` boolean that the caller passes to `BinkMediaPlayer.scan(container, { mediaEnabled: bool })` to suppress rendering when the feature is disabled at the network or area level.

---

## Supported Media Types

### Images

Direct image URLs ending in `.png`, `.webp`, `.gif`, `.jpg`, `.jpeg`, or `.svg` are rendered as inline `<img>` elements. Known image CDN prefixes are also recognized when the CDN path omits a file extension, currently including `https://cdn.bsky.app/img/`.

### Video

Direct video file URLs ending in `.mp4`, `.webm`, or `.ogv` are rendered with an HTML5 `<video>` player.

### Audio

Direct audio file URLs ending in `.mp3`, `.flac`, `.ogg`, `.opus`, `.wav`, `.m4a`, or `.aac` are rendered with an HTML5 `<audio>` player.

### Retro Audio

Tracker module and legacy audio formats are handled by the retro audio player (`public_html/js/retro-audio-player.js`), which uses the server-side proxy to fetch the file cross-origin:

| Format | Extensions |
|--------|-----------|
| Tracker modules | `.xm`, `.it`, `.s3m`, `.mod`, `.stm`, `.amf`, `.669`, `.mptm` |
| Commodore SID | `.sid` |
| MIDI | `.mid`, `.midi` |

The proxy endpoint (`GET /api/media/raw`) enforces a maximum file size of **8 MB** and only allows the extensions listed above. It also validates that the target URL resolves to a public IP address.

### Platform Embeds

Platform embeds are resolved by the server-side `MediaLinkResolver` (`src/Media/MediaLinkResolver.php`) via the `GET /api/media/embed` endpoint.

| Provider | Method |
|----------|--------|
| YouTube | Client-side (deterministic URL pattern) |
| Odysee | Client-side |
| BitChute | Client-side |
| Brighteon | Client-side |
| PeerTube | Client-side |
| Rumble | oEmbed (server-side) |
| SoundCloud | oEmbed (server-side) |
| Twitter / X | oEmbed (server-side) |
| TikTok | oEmbed (server-side) |
| ReverbNation | oEmbed (server-side) |
| Bastyon | Server-side proxy resolution |

Each provider can be individually enabled or disabled by the administrator (see [Admin Configuration](#admin-configuration)).

---

## Text Art Rendering

### ANSI Art

Messages containing ANSI escape sequences are rendered by `public_html/js/ansisys.js`. Detection is based on the render mode selected by the user (see [Render Modes](#render-modes)).

See [ANSI Support](ANSI_Support.md) for full details.

### SIXEL Graphics

DEC Sixel bitmap graphics are decoded and drawn to an HTML5 Canvas by `public_html/js/sixel.js`. The decoder supports:

- 256-color palette with dynamic color definition
- HLS-to-RGB conversion
- Sixel escape sequences (`ESC P … q … ESC \`)
- `.six` and `.sixel` file formats

SIXEL rendering applies to echomail and netmail message bodies when a sixel payload is detected.

See [Sixel Support](Sixel_Support.md) for full details.

### RIPscrip

RIPscrip vector graphics are detected server-side in `MessageHandler::appendRipRendering()` and rendered client-side by `ripterm.js` (imported in `public_html/js/echomail.js`). Detection looks for `!|` lines with recognized RIP command sequences (`|c##` color commands, `|L########` image loads, `|@####` other commands).

RIPscrip rendering is only available in echomail.

See [RIPScrip Support](RIPScrip_Support.md) for full details.

### Pipe Codes

BBS pipe color codes are rendered by the pipe code renderer. See [Pipe Code Support](Pipe_Code_Support.md) for details.

---

## Markdown / Markup

Messages can carry a `^AMARKUP:` kludge line (LSC-001 Draft 2) that declares the body format. A legacy `^AMARKDOWN:` kludge is also recognized. `MessageHandler::appendMarkdownRendering()` detects these kludges and pre-renders the body server-side before sending it to the client.

Supported formats declared via the kludge:

- `markdown` — rendered by `MarkdownRenderer::toHtml()`
- `stylecodes` — rendered by `StyleCodesRenderer::toHtml()`

The pre-rendered HTML is returned in the `markup_html` field of the message API response. The client uses this HTML directly when available.

---

## Message Attachments

Files attached to a message (for both echomail and netmail) are retrieved by `FileAreaManager::getMessageAttachments()` and returned in the `attachments` array of the message API response. Access control respects file area privacy settings.

Attachment files are stored via `FileAreaManager::storeNetmailAttachment()` for netmail. The netmail reader displays attachments below the message body.

---

## Render Modes

The web reader supports multiple render modes that the user can cycle through with the render mode button:

| Mode | Description |
|------|-------------|
| `auto` | Detect and apply the most appropriate renderer automatically |
| `rip` | Force RIPscrip rendering |
| `ansi` | Force ANSI rendering |
| `amiga_ansi` | Amiga ANSI variant |
| `plain` | Plain text only, no art rendering |
| `raw` | Display raw message bytes (useful for debugging encoding issues) |

The user's preferred mode is stored via `UserStorage` so it persists across sessions without affecting other users on the same browser.

---

## Admin Configuration

Media player settings are managed in `src/AppearanceConfig.php` under the `media_player` key and can be changed through the admin interface.

**Global toggle:** Media rendering is **disabled by default** on fresh installs. Enable it system-wide with `media_player.enabled = true` in the admin panel. Disable it again with `media_player.enabled = false` to suppress all inline rendering across every network and area.

**Per-network toggle:** Each row in the `networks` table has an `allow_media` boolean, managed through **Admin → Networks**. Enable it on a network to allow inline media for messages received from or associated with that network. New networks default to denying media until enabled.

**Per-area toggle:** Each echo area has an `allow_media` column in the `echoareas` table. The value can be `true` (always allow), `false` (always deny), or `NULL` (inherit from the network setting). The area setting is configured in the echo area management interface.

**Resolution order** (first match wins):
1. Global disabled → media suppressed everywhere
2. Area `allow_media = false` → media suppressed for that area
3. Area `allow_media = true` → media allowed regardless of network setting
4. Area `allow_media = NULL` → network `allow_media` setting (default: deny)

**Per-provider toggles:** Each embed provider (youtube, odysee, rumble, bitchute, brighteon, peertube, soundcloud, twitter, tiktok, bastyon, reverbnation, raw_media) can be individually enabled or disabled.

**API keys:** Some oEmbed providers (SoundCloud, Twitter/X) require API keys configured under `media_player.api_keys`.

When `GET /api/media/embed` is called, it checks the global flag and the per-provider configuration before resolving the URL. Disabled providers return an error and the link is left as plain text.

The resolved `allow_media` boolean is included in every message API response. The client passes this value to `BinkMediaPlayer.scan(container, { mediaEnabled: bool })` so that suppressed areas render links as plain text without any player UI.

---

## Architecture Reference

| Component | Path | Role |
|-----------|------|------|
| Media player engine | `public_html/js/media-player.js` | URL detection, inline rendering, popup menu, embed loading |
| Retro audio player | `public_html/js/retro-audio-player.js` | Tracker/SID/MIDI playback |
| SIXEL decoder | `public_html/js/sixel.js` | DEC Sixel bitmap rendering |
| ANSI renderer | `public_html/js/ansisys.js` | ANSI escape sequence rendering |
| Server embed resolver | `src/Media/MediaLinkResolver.php` | Coordinates all embed provider classes |
| Embed API endpoint | `GET /api/media/embed` in `routes/api-routes.php` | Returns embed HTML for a URL; used as CORS fallback for oEmbed providers |
| Raw media proxy | `GET /api/media/raw` in `routes/api-routes.php` | Proxies retro audio files |
| Message enrichment | `src/MessageHandler.php` | Attaches markup HTML, RIP detection, raw bytes, area `allow_media` |
| Appearance config | `src/AppearanceConfig.php` | Reads/writes global media player admin settings |
| Network media config | `src/Binkp/Config/BinkpConfig.php` (`isMediaAllowedForDomain()`) | Per-network `allow_media` lookup from the `networks` table |
