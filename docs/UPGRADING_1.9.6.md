# Upgrading to 1.9.6

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Chat Room Bridging (Matterbridge)](#chat-room-bridging-matterbridge)
- [AI Settings](#ai-settings)
- [AI Share Summarizer](#ai-share-summarizer)
- [Messaging](#messaging)
- [Shared Pages](#shared-pages)
- [Sharing Analytics](#sharing-analytics)
- [Markdown Editor](#markdown-editor)
- [Terminal Server](#terminal-server)
- [Documentation](#documentation)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Chat Room Bridging (Matterbridge)

- Local BinktermPHP chat rooms can now relay messages to and from external platforms (Discord, Slack, IRC, and others) via the third-party [Matterbridge](https://github.com/42wim/matterbridge) gateway.
- A new **Matterbridge Bridge Settings** panel on **Admin → Chat Rooms** lets the sysop configure the global API connection (URL, token, bridge user, and default username suffix).
- Each chat room has new per-room bridge fields: enable/disable bridging and a Matterbridge gateway name that maps the room to a configured gateway in `matterbridge.toml`.
- Outbound bridging is handled in-process by `ChatMessageService`. Inbound messages are injected by a new background daemon, `scripts/matterbridge_daemon.php`, which polls the Matterbridge API and inserts messages into local chat under a dedicated bridge user account.
- Global bridge settings are stored in `config/matterbridge.json`. A sample file is at `config/matterbridge.json.example`.
- This release adds three new columns to the `chat_rooms` table (`matterbridge_enabled`, `matterbridge_gateway`, `matterbridge_options`). The migration runs automatically via `setup.php`.
- `scripts/restart_daemons.sh` now manages `matterbridge_daemon` as an optional service — it only restarts if it was already running.

### AI Settings

- A dedicated **AI Settings** admin page is now available at **Admin → AI Settings**. It consolidates all AI-related configuration in one place.
- The **Enable AI Assistant** toggle, previously located on the BBS Settings page, has moved to the AI Settings page.
- A new **Enable AI summaries for shared message links** toggle controls whether the AI share summarizer feature is available to users.
- The system prompt used when generating share summaries is configurable directly on the AI Settings page. Leave it blank to use the built-in default.

### AI Share Summarizer

- Users can now generate a one-sentence AI-written link preview description when sharing an echomail message. An AI button appears in the share dialog when this feature is enabled and an AI provider is configured.
- The generated description is stored with the shared message and used as the `og:description` meta tag on the shared page, so link previews on social platforms and messaging apps show a meaningful summary of the post rather than generic site text.
- The system prompt the AI receives can be customized in **Admin → AI Settings**.

### Messaging

- Added a new web-reader **Re-Post** action for echomail and netmail. Re-post opens the composer with the original message body, preserves the original message charset and markup format, prefixes the subject with `FWD:`, and leaves the recipient or target area unset so the user must choose where to send it.
- Added a new echomail reader action to **Forward by Netmail**. This opens the netmail composer using the selected echomail message as the source, keeps the original charset and markup mode, prefixes the subject with `FWD:`, and leaves the netmail recipient unset so the user can choose where to forward it.

### Shared Pages

- Fixed shared message pages so they no longer emit two `og:description` tags. Social previews now use the shared message subject/body excerpt instead of also including the site-wide description from the global appearance settings.
- Applied the same metadata override pattern to shared file pages so file shares also emit a single page-specific `og:description` value.

### Sharing Analytics

- Shared message and shared file page visits now record external HTTP referrers, allowing the system to show which outside sites are sending traffic to shared links.
- **Admin → Analytics → Sharing** now includes a **Top Referrers** column for each active shared message and shared file, listing the most common external URLs that led visitors to that share.
- Shared message page loads no longer count twice when the browser fetches the page shell and then loads the message JSON. View totals for shared messages now reflect one counted access per page visit.

### Markdown Editor

- Fixed the Markdown editor inserting unnecessary backslash escapes before underscores and hyphens when composing messages in WYSIWYG mode. Characters typed in plain text (for example underscores in usernames or filenames, and hyphen runs used as dividers) were being stored with backslash prefixes such as `\_` or `\-\-\-`, which could appear as literal backslash sequences in some renderers.

### Terminal Server

- Terminal login, main-menu, and goodbye art screens now support simple rotating file families. You can keep a single file such as `telnet/screens/login.ans`, or add numbered variants such as `telnet/screens/login1.ans`, `telnet/screens/login2.ans`, `telnet/screens/mainmenu1.sixel`, and `telnet/screens/bye1.ans`.
- When multiple matching files exist for the same screen family and file type, the terminal server now uses a glob match and randomly selects one file each time that screen is shown.

### Documentation

- Expanded the user guide with a dedicated message reader section that explains the web reader interface and lists the supported keyboard shortcuts for both echomail and netmail readers. The translated user guide variants were updated to include the same section.

---

## Chat Room Bridging (Matterbridge)

BinktermPHP chat rooms can now relay messages bidirectionally to external platforms — Discord, Slack, IRC, Telegram, and any other network supported by the third-party [Matterbridge](https://github.com/42wim/matterbridge) tool.

### How it works

Two processes are required beyond the web server:

1. **The Matterbridge binary** — a separate Go program you download and run. It maintains connections to external platforms and exposes a local HTTP API. BinktermPHP sends outbound messages to it and polls it for inbound ones.
2. **`scripts/matterbridge_daemon.php`** — a BinktermPHP background daemon that polls the Matterbridge API every few seconds, matches incoming messages to local rooms by gateway name, and inserts them into `chat_messages` under a configured bridge user account.

Neither process is started automatically. Both must be running for bidirectional bridging to work. See `docs/Matterbridge.md` for setup instructions including a sample `matterbridge.toml` configuration and systemd unit examples.

### Database migration

Three columns are added to `chat_rooms`:

| Column | Type | Purpose |
|---|---|---|
| `matterbridge_enabled` | `BOOLEAN` | Whether this room relays messages |
| `matterbridge_gateway` | `VARCHAR(100)` | Matterbridge gateway name matching a `[[gateway]]` in `matterbridge.toml` |
| `matterbridge_options` | `JSONB` | Per-room options (username template, etc.) |

The migration runs automatically when `setup.php` is run during upgrade. No manual SQL is needed.

### Configuration

Copy `config/matterbridge.json.example` to `config/matterbridge.json` and fill in your values, or configure it through the admin panel. The file is not created automatically — bridging remains disabled until it exists.

Global settings (API URL, token, bridge user, default username suffix) are managed at **Admin → Chat Rooms → Matterbridge Bridge Settings**. Per-room settings (enable bridging, gateway name, username template) are on each room's edit form.

### Running the inbound daemon

```bash
# Start
scripts/restart_daemons.sh --start matterbridge_daemon

# The daemon also participates in a full restart — but only if it was already running
scripts/restart_daemons.sh
```

Or directly:

```bash
php scripts/matterbridge_daemon.php --daemon --pid-file=data/run/matterbridge_daemon.pid
```

The daemon exits immediately if Matterbridge is not enabled in `config/matterbridge.json` or if no bridge user is set.

## AI Settings

A dedicated **AI Settings** page is now available in the admin panel. All AI-related configuration has been consolidated there, replacing the scattered controls that previously lived on the BBS Settings page.

The **Enable AI Assistant** toggle — which gates the in-reader AI assistant available to users — has moved from **Admin → BBS Settings** to **Admin → AI Settings**. Its behaviour is unchanged; the setting is simply managed from the new location.

The AI Settings page also hosts the **Enable AI summaries for shared message links** toggle and the configurable system prompt described in the next section.

## AI Share Summarizer

When sharing an echomail message, users can now optionally attach a short AI-written description to the share link. When the feature is enabled and an AI provider (OpenAI or Anthropic) is configured in `.env`, an AI button appears in the share dialog. Clicking it sends the message subject and body to the configured AI provider and fills the description field with a one-to-two sentence plain-text summary.

The description is stored with the shared message record and is served as the `og:description` meta tag on the public shared-message page. Link previews generated by social platforms, chat applications, and messaging clients will show this description rather than falling back to generic site text.

The system prompt the AI receives when generating these summaries can be customized in **Admin → AI Settings → Share summary system prompt**. Leaving the field blank uses the built-in default prompt, which instructs the model to write a concise, plain-text Open Graph description in one to two sentences without Markdown, HTML, or preamble.

The summarizer is locale-aware. When a user requests a summary, the language instruction sent to the AI reflects that user's configured interface language. A French-language user will receive a summary written in French, a German-language user in German, and so on. Users with their interface set to English receive the default English output with no additional instruction.

To enable this feature:

1. Add an `OPENAI_API_KEY` or `ANTHROPIC_API_KEY` to your `.env` file.
2. Go to **Admin → AI Settings** and enable **AI summaries for shared message links**.

## Messaging

The web message readers for echomail and netmail now include a **Re-Post** action alongside the existing reply tools. Re-post is intended for taking an existing message and sending it again as a new message rather than as a threaded reply.

When a user chooses **Re-Post**, the composer opens with the original message text already inserted, the original message charset preselected, and the original markup mode restored when the source message used Markdown or StyleCodes. The subject is copied with an added `FWD:` prefix. Netmail re-posts leave the recipient fields blank, and echomail re-posts leave the area selector blank, so the user must deliberately choose the new destination before sending.

The echomail reader also now includes a **Forward by Netmail** action in its message menu. This action opens the netmail composer while using the selected echomail message as the forwarding source. The forwarded draft preserves the original message body, charset, and markup mode, prefixes the subject with `FWD:`, and leaves the netmail destination blank so the user can choose the recipient explicitly.

For echomail, the send flow now keeps track of which area the user started from. After either an echomail repost or an echomail-to-netmail forward is sent, the browser returns to that original area view instead of navigating into the newly selected destination or leaving the user in the netmail section. This keeps the user in their previous reading context after forwarding or cross-posting content.


## Shared Pages

Shared message pages now override the default description metadata provided by the site shell templates. Previously, a shared message page could output both the global site description from **Admin -> Appearance** and a second message-specific `og:description` tag based on the shared post content. Link preview crawlers that saw both tags could pick the wrong one, causing the preview text to describe the BBS in general rather than the shared message itself.

The page now emits only the message-specific description metadata when a shared message is being viewed. This keeps the Open Graph preview aligned with the shared message subject and excerpt.

The same override structure is also applied to shared file pages. Shared files continue to use their own file description or fallback text, but they no longer risk combining that description with a second site-wide Open Graph description tag.

## Sharing Analytics

Shared links now capture the external HTTP referrer that led a visitor to the page when the browser provides one. This applies to both public shared message pages and public shared file pages. Internal links from the same BBS are ignored so the analytics focus on outside traffic sources rather than normal in-site navigation.

The **Admin → Analytics → Sharing** page now shows a **Top Referrers** column beside each active share. For each shared message or file, the page lists up to ten of the most frequent external referring URLs along with the number of visits attributed to each one. This gives the sysop a direct view of which forums, social posts, directories, or websites are driving traffic to individual shared links.

Shared message access counting has also been tightened up. The public shared message page renders server-side and then loads its message content through the API. Previously, that could increment the share's view counter twice for a single human visit. The count is now recorded only on the page request itself, so the displayed totals better match real page visits.

## Markdown Editor

The Markdown composer in WYSIWYG mode was inserting unnecessary backslash escapes before underscores. This happened because the underlying Toast UI editor serializes WYSIWYG content to Markdown and defensively escapes characters that carry special meaning in Markdown syntax, including underscores. The post-processing step that already stripped similar unnecessary escapes from `.`, `~`, and `|` was extended to cover `_` and `-` as well. Underscores and hyphens typed in plain prose are now stored without backslash prefixes.

## Terminal Server

The terminal server now supports rotating custom screen families for login, main menu, and goodbye art. Instead of being limited to one fixed file per screen, you can now place multiple matching files in `telnet/screens/` and let the server pick one at random each time the screen is displayed.

Examples include `telnet/screens/login.ans`, `telnet/screens/login1.ans`, `telnet/screens/login2.ans`, `telnet/screens/mainmenu.sixel`, `telnet/screens/mainmenu1.sixel`, and `telnet/screens/bye1.ans`. Matching is done per file type, so Sixel-capable clients randomize across matching `.sixel` files and ANSI-capable clients randomize across matching `.ans` files.

## Documentation

The user guide now includes a dedicated message reader section. It explains that the web message reader is shared by echomail and netmail and documents the supported keyboard shortcuts for navigation, viewer mode changes, downloads, full-screen mode, shortcut help, and closing the reader.

The localized user guide files were updated alongside the main English guide so the same message reader guidance is available across the translated variants.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
