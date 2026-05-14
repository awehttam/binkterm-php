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
- [Developer Tools](#developer-tools)
- [Realtime Chat Delivery](#realtime-chat-delivery)
- [Networks](#networks)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Chat Room Bridging (Matterbridge)

- Local BinktermPHP chat rooms can now relay messages to and from external platforms (Discord, Slack, IRC, and others) via the third-party [Matterbridge](https://github.com/42wim/matterbridge) gateway.
- A new **Matterbridge Bridge Settings** panel on **Admin → Chat Rooms** lets the sysop configure the global API connection (URL, token, bridge user, and default username suffix).
- Each chat room has new per-room bridge fields: enable/disable bridging and a Matterbridge gateway name that maps the room to a configured gateway in `matterbridge.toml`.
- Outbound bridging is handled in-process by `ChatMessageService`. Inbound messages are injected by a new background daemon, `scripts/matterbridge_daemon.php`, which polls the Matterbridge API and inserts messages into local chat under a dedicated bridge user account.

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

- The unread netmail count shown on the dashboard, the netmail sidebar, and the unread filter list now reflects only messages addressed to the logged-in user. Previously the count included outbound messages composed by the user, causing the badge to show a higher number than the unread inbox actually contained.
- Added a new web-reader **Re-Post** action for echomail and netmail. Re-post opens the composer with the original message body, preserves the original message charset and markup format, prefixes the subject with `FWD:`, and leaves the recipient or target area unset so the user must choose where to send it.
- Added a new echomail reader action to **Forward by Netmail**. This opens the netmail composer using the selected echomail message as the source, keeps the original charset and markup mode, prefixes the subject with `FWD:`, and leaves the netmail recipient unset so the user can choose where to forward it.

### Shared Pages

- Fixed shared message pages so they no longer emit two `og:description` tags. Social previews now use the shared message's AI-generated summary (if one has been created) or subject/body excerpt, instead of also including the site-wide description from the global appearance settings.
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

- Moved installation instructions from `README.md` into a dedicated `docs/INSTALL.md` and overhauled it: restructured around a clear two-user model (admin account vs. dedicated `binktermphp` system account), added a BBS user creation section, moved PostgreSQL setup into its own section, added `SITE_URL` configuration guidance with common scenario examples, added a firewall rules preface to the network ports section, grouped unsupported web servers under a single heading, added a PHP built-in server safety warning, and added a Next Steps section pointing to the getting-started guide.
- Expanded the user guide with a dedicated message reader section that explains the web reader interface and lists the supported keyboard shortcuts for both echomail and netmail readers. The translated user guide variants were updated to include the same section.
- Added `docs/ARCHITECTURE.md` — a new system architecture reference covering the full daemon map, component diagram, FTN packet lifecycle (inbound and outbound), daemon IPC model, door game subsystem, and AI pipeline.
- Added `docs/DATA_MODEL.md` — a conceptual overview of the key database tables and their relationships, written as a mental model for developers rather than a schema dump.
- Added `docs/WebDoor-Tutorial.md` — a step-by-step tutorial for building a WebDoor from scratch, covering the manifest, PHP entry point, SDK usage, credit integration, and enabling in admin.
- Added an authentication quickstart to `docs/API.md` with a complete login request/response example and a follow-up authenticated request, generated as part of the API doc build so it is preserved on regeneration.
- Restructured `docs/index.md`: AI & Integrations promoted above Doors & Games; Access Methods section now includes Gemini and PacketBBS; "Content & Display" renamed to "Content & Media"; new documents added throughout.
- Expanded the `docs/DEVELOPER_GUIDE.md` architecture section with a full system diagram and a daemon reference table listing all processes, their purposes, and whether they are required or optional.
- Added new reference pages to `docs/`: Gateway Token Authentication, Markdown and StyleCodes, Nodelist, Performance Tuning, Shoutbox, Bulletins, Voting Booth, Dashboard, and Analytics.
- Updated the README opening paragraph to describe the platform's multi-protocol nature rather than framing it as a web-based BBS.

### Developer Tools

- Added `scripts/generate_api_docs.php`, a CLI utility that generates developer-facing API reference documentation directly from the route files. It produces Markdown or OpenAPI 3.0 YAML and can optionally call a configured AI provider (Anthropic or OpenAI) to enrich each endpoint with a description, parameter tables, request body schema, and response fields. No migration or configuration change is required; the script is a standalone developer tool.

### Networks

- Corrected the website URL for the DoveNet built-in network entry. The entry now links to the active DoveNet listing at `https://clrghouz.bbs.dege.au/domain/view/34`.

### Realtime Chat Delivery

- Fixed WebSocket connections dropping when BinktermPHP is deployed behind a reverse proxy (Caddy, Nginx, etc.). The WebSocket server now sends a keep-alive ping frame to each connected client every 20 seconds, preventing proxies from closing idle connections mid-session.
- Fixed the BinkStream event cursor drifting out of sync when a client reconnects. Events such as `dashboard_stats` (generated by incoming FTN mail) accumulate in the event queue without being delivered to chat-page clients, causing the stored cursor to lag behind the server's position. On reconnect the client would replay the full backlog — potentially hundreds of thousands of events — before seeing new messages. The server now sends a lightweight cursor-sync message after processing any batch that contains unsubscribed events, keeping the client's stored position current.
- On reconnect, catch-up replay is now capped to the most recent 5,000 events. Chat message history is loaded through the messages API on page load, so BinkStream only needs to cover a short real-time window. Previously there was no cap, and clients that had been disconnected during a large FTN mail import could spend minutes replaying stale events before receiving new chat messages.

---

## Chat Room Bridging (Matterbridge)

BinktermPHP chat rooms can now relay messages bidirectionally to external platforms — Discord, Slack, IRC, Telegram, and any other network supported by the third-party [Matterbridge](https://github.com/42wim/matterbridge) tool.

### How it works

Two processes are required beyond the web server:

1. **The Matterbridge binary** — a separate Go program you download and run. It maintains connections to external platforms and exposes a local HTTP API. BinktermPHP sends outbound messages to it and polls it for inbound ones.
2. **`scripts/matterbridge_daemon.php`** — a BinktermPHP background daemon that polls the Matterbridge API every few seconds, matches incoming messages to local rooms by gateway name, and inserts them into `chat_messages` under a configured bridge user account.

Neither process is started automatically — you must run the Matterbridge binary yourself and start `matterbridge_daemon.php` as described below.

### Configuration

Configure it through the admin panel, or copy `config/matterbridge.json.example` to `config/matterbridge.json` and fill in your values.

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

### Unread Netmail Count Correction

The unread netmail count displayed on the dashboard card, the netmail page sidebar, and the unread filter list has been corrected to count only messages addressed to the logged-in user — that is, messages where the recipient name and FTN destination address match the user's account. Previously, the count included outbound messages the user had composed, causing the badge number to exceed what the unread inbox actually contained. The unread filter in the netmail message list has been updated to match, so the count and the list now agree. No action is required; the corrected counts take effect immediately on the next page load.

### Forwarding and Re-Posting

The web message readers for echomail and netmail now include a **Re-Post** action alongside the existing reply tools. Re-post is intended for taking an existing message and sending it again as a new message rather than as a threaded reply.

When a user chooses **Re-Post**, the composer opens with the original message text already inserted, the original message charset preselected, and the original markup mode restored when the source message used Markdown or StyleCodes. The subject is copied with an added `FWD:` prefix. Netmail re-posts leave the recipient fields blank, and echomail re-posts leave the area selector blank, so the user must deliberately choose the new destination before sending.

The echomail reader also now includes a **Forward by Netmail** action in its message menu. This action opens the netmail composer while using the selected echomail message as the forwarding source. The forwarded draft preserves the original message body, charset, and markup mode, prefixes the subject with `FWD:`, and leaves the netmail destination blank so the user can choose the recipient explicitly.

For echomail, the send flow now keeps track of which area the user started from. After either an echomail repost or an echomail-to-netmail forward is sent, the browser returns to that original area view instead of navigating into the newly selected destination or leaving the user in the netmail section. This keeps the user in their previous reading context after forwarding or cross-posting content.


## Shared Pages

Shared message pages now override the default description metadata provided by the site shell templates. Previously, a shared message page could output both the global site description from **Admin -> Appearance** and a second message-specific `og:description` tag based on the shared post content. Link preview crawlers that saw both tags could pick the wrong one, causing the preview text to describe the BBS in general rather than the shared message itself.

The page now emits only the message-specific description metadata when a shared message is being viewed. This keeps the Open Graph preview aligned with the shared message's AI-generated summary (if one has been created) or subject and excerpt.

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

### Installation Guide Overhaul

Installation instructions have been moved out of `README.md` into a dedicated `docs/INSTALL.md`, which has also been significantly restructured to make the installation process clearer for sysops of all experience levels. The previous guide mixed steps that must be run as a privileged admin user with steps that must be run as the BBS service account, without distinguishing between them.

The revised guide introduces a two-user model throughout: an admin account (your normal login with `sudo` access) for system-level tasks, and a dedicated `binktermphp` service account for everything BinktermPHP-specific. Each section is labelled with which account should be used, and the guide walks through creating the `binktermphp` account explicitly before any install steps.

Other changes to `docs/INSTALL.md`:

- PostgreSQL setup is now its own top-level section, clearly framed as an admin-user task separate from the BinkTermPHP install.
- Web server configuration is positioned after the install methods, since sysops need the install path before they can fill in the config.
- A `SITE_URL` configuration section has been added to Step 3 of the Git install path, with a table covering the common scenarios: public domain, IP-only, local machine, and PHP built-in server. The installer section notes that `SITE_URL` will be prompted during the install run.
- Nginx and Apache configurations are grouped under an "Other web servers (unsupported)" sub-heading to make Caddy's recommended status more visible.
- The PHP built-in server entry now carries an explicit warning that it is not safe for production use.
- The Network Ports section now opens with a plain-language explanation of the table and includes a `ufw` example showing the most common firewall rules for inbound and outbound services.
- A Next Steps section at the end of the guide directs sysops to `docs/GettingStarted.md` once installation is complete.
- The Database Management reference (scripts and migration system) has been moved from `docs/INSTALL.md` to `README.md` under the Operation section, where it is more naturally discoverable during day-to-day administration.

### Other Documentation Changes

The user guide now includes a dedicated message reader section. It explains that the web message reader is shared by echomail and netmail and documents the supported keyboard shortcuts for navigation, viewer mode changes, downloads, full-screen mode, shortcut help, and closing the reader.

The localized user guide files were updated alongside the main English guide so the same message reader guidance is available across the translated variants.

Several new developer-focused documents have been added:

**[ARCHITECTURE.md](ARCHITECTURE.md)** is the primary new addition. It covers the full system in one place: a layered component diagram showing all clients, daemons, and the data layer; descriptions of each component's role; separate diagrams for the inbound FTN packet lifecycle (binkp connection → packet processor → database → BinkStream notification) and the outbound lifecycle (user post → packet bundler → binkp poll → uplink); the daemon IPC model (shared database, admin daemon as coordinator, PID files); the door game subsystem (browser WebSocket → multiplexing bridge → PTY/DOSBox-X); and the AI pipeline (user prompt → MessageAiAssistant → MCP tool loop → AI provider → browser).

**[DATA_MODEL.md](DATA_MODEL.md)** documents the key database tables conceptually — not as a schema dump, but as a mental model for contributors. It covers the core message tables (`echomail`, `netmail`, `echoareas`), the user tables (`users`, `users_meta`, `user_transactions`), FTN network tables, the `sse_events` real-time queue, and a reference table for all supporting tables. An entity-relationship diagram shows how the most important tables connect.

**[WebDoor-Tutorial.md](WebDoor-Tutorial.md)** walks through building a complete WebDoor from scratch. The example is a coin-flip game that demonstrates the full stack: manifest format, PHP entry point using the WebDoor SDK, server-side credit debit and award logic, JSON API response pattern, and enabling the door in admin. A "Going Further" section covers icons, leaderboards, config overrides, and persistent save state.

The [API.md](API.md) authentication section now includes a quickstart with a complete login request/response example showing the session cookie and CSRF token, followed by examples of a GET request and a state-changing POST with the CSRF header. This is generated by `scripts/generate_api_docs.php` and is preserved on regeneration.

The [index.md](index.md) structure has been updated: the AI & Integrations section is now placed above Doors & Games; Access Methods now consolidates all access types including Gemini and PacketBBS alongside Telnet and SSH; "Content & Display" is renamed "Content & Media"; and new documents are linked throughout.

The [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md) architecture section now includes a full ASCII system diagram and a daemon reference table listing every daemon, its script path, its purpose, and whether it is required or optional for a given feature set.

## Developer Tools

A new CLI script, `scripts/generate_api_docs.php`, generates developer-facing API reference documentation from the SimpleRouter route files. It uses PHP's built-in tokenizer to walk the route file structure, resolve nested group prefixes, extract PHPDoc comments, and detect authentication requirements — producing output that covers all endpoints in a given route set without requiring a running server.

Two output formats are supported:

- **Markdown** (default) — a structured document with a table of contents, per-section endpoint tables, and per-endpoint detail blocks. Suitable for `docs/API.md`, a GitHub wiki, or any Markdown renderer.
- **OpenAPI 3.0 YAML** — a spec file compatible with Swagger UI, Postman, and API code-generation tools.

Four route sets can be selected individually or together: `api` (the public API, selected by default), `admin`, `door`, and `webdoor`. Pass `--routes=all` to document every route file in one run.

Without any AI flags the output is static: HTTP method, path, auth flag, and any comment immediately above the route definition in the source. Passing `--ai` activates enrichment: the script sends batches of route code snippets to a configured Anthropic or OpenAI provider and back-fills a one-sentence summary, a fuller description, path and query parameter tables, a request body schema, a response field list, and common error codes for each endpoint.

```bash
# Static Markdown for the public API
php scripts/generate_api_docs.php --output=docs/API.md

# AI-enriched OpenAPI spec for all routes
php scripts/generate_api_docs.php --routes=all --ai --format=openapi --output=docs/openapi.yaml
```

Full usage is documented in `docs/DEVELOPER_GUIDE.md` under **API Documentation Generator**. No migration or configuration change is required to use the script.

## Realtime Chat Delivery

Three fixes address WebSocket reliability and event delivery performance for installations running BinktermPHP behind a reverse proxy.

**WebSocket keep-alive pings.** Reverse proxies such as Caddy and Nginx reap connections that carry no traffic for a period of time. The WebSocket server had no mechanism to keep idle connections alive, so connections would be silently dropped and clients would reconnect with exponential backoff — causing messages to arrive in bursts seconds after they were sent rather than in real time. The server now sends a WebSocket ping frame (RFC 6455 opcode 0x9) to each connected client every 20 seconds. Browsers respond automatically with a pong, which keeps both sides of the proxy connection active.

**BinkStream cursor sync.** The BinkStream event stream delivers different event types to different pages. The `sse_events` table accumulates rows for all event types: `chat_message`, `dashboard_stats` (triggered by incoming FTN mail), `binkp_session`, and others. The WebSocket server advances its internal cursor past every row it fetches — including rows for event types the current client is not subscribed to — but only sends the subscribed events to the client. Because the client's stored cursor was only updated when a subscribed event was received, a gap of unsubscribed rows between two chat messages would leave the stored cursor pointing before that gap. On reconnect, the client would replay the entire gap before receiving anything new. The server now sends a `__cursor_sync` message after each polling batch that contains unsubscribed trailing rows, so the client's stored position always matches the server's.

**Catch-up replay cap.** Even with the cursor sync fix in place, clients that reconnect after a prolonged disconnection (such as a suspended laptop waking from sleep) could arrive with a very stale cursor. The replay window is now capped at 5,000 events from the current position. If the stored cursor is further back than that, the client starts from `maxId − 5000` instead of the stored value. Chat history is unaffected because it is loaded through the `/api/chat/messages` endpoint on page load; BinkStream is only responsible for delivering events that arrive after the page has finished loading.

## Networks

The built-in DoveNet network record has been updated with a corrected website URL. The `networks` table entry for DoveNet now points to `https://clrghouz.bbs.dege.au/domain/view/34`, which is the active DoveNet listing.

This is applied automatically by the migration when you run `php scripts/setup.php`.

---

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
