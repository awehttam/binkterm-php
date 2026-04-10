# Upgrading to 1.9.1

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Markdown WYSIWYG Compose Editor](#markdown-wysiwyg-compose-editor)
- [Markdown Heading Rendering](#markdown-heading-rendering)
- [Echomail Moderation](#echomail-moderation)
- [Polls](#polls)
- [File Area URL Links](#file-area-url-links)
- [URL Link Open Graph Image Preview](#url-link-open-graph-image-preview)
- [MCP Server Memory Leak Fix](#mcp-server-memory-leak-fix)
- [Optional Spaces in Usernames](#optional-spaces-in-usernames)
- [User Guide](#user-guide)
- [Dashboard Layout Customization](#dashboard-layout-customization)
- [Sysop Default Dashboard Layout](#sysop-default-dashboard-layout)
- [AI Bots](#ai-bots)
- [Chat Markdown Rendering](#chat-markdown-rendering)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)





## Summary of Changes

### Markdown WYSIWYG Compose Editor

- When composing a Markdown message, the plain textarea is replaced by a rich editor with a formatting toolbar and side-by-side Edit/Preview tabs.
- Toolbar buttons cover bold, italic, H1–H3, inline code, code block, link, image, unordered and ordered lists, blockquote, and horizontal rule. Keyboard shortcuts Ctrl+B, Ctrl+I, and Ctrl+K are supported.
- Pasting a bare URL into the editor prompts the user to fetch an Open Graph preview and insert a formatted link card in place of the raw URL.
- The Preview tab renders a close approximation of how the message will appear to recipients.

### Markdown Heading Rendering

- H1 headings in rendered Markdown messages now display with a double underline; H2 headings display with a single underline, matching the visual convention used by the compose editor's preview.
- Heading font sizes now scale proportionally with the user's configured message font size instead of being collapsed to the same size as body text.

### Echomail Moderation

- New users' echomail posts can be held for admin review before being distributed to the network. This feature is disabled by default and must be enabled by the sysop.
- An admin-configurable approval threshold automatically promotes users to unmoderated posting once they have accumulated a sufficient number of approved posts.
- A moderation queue page is available at **Admin → Area Management → Echomail Moderation** where pending posts can be previewed and approved or rejected.
- The Admin menu and the Echomail Moderation item highlight in yellow when posts are waiting for review.
- Existing users who have previously logged in are grandfathered in and bypass moderation automatically.
- Admins always bypass moderation regardless of post count.

### Polls

- The poll list now shows unvoted polls before polls the user has already voted on, so newly created polls always appear at the top.
- Within each group, polls are ordered newest first.

### File Area URL Links

- File areas now support external URL links in addition to uploaded files.
- URL link entries display a link icon, show a dash for file size, and present a Visit button instead of a download button throughout all file listing views (area files, recent uploads, My Uploads, and file search results).
- The file information modal on the echomail page shows a Visit button for URL link entries.

### MCP Server

- The MCP server (`mcp-server/server.js`) had a memory leak where each request allocated a new `McpServer` instance that was never explicitly closed, causing memory to grow steadily over time. The server is now properly closed when the HTTP response ends.

### Optional Spaces in Usernames

- A new `.env` setting, `USERNAMES_ALLOW_SPACES`, controls whether usernames may contain single internal spaces (e.g. `Phantom of Doom`). The setting defaults to `false`; existing behaviour is unchanged unless you opt in.
- When enabled, the registration form accepts handles containing single spaces between word characters. Leading and trailing spaces are trimmed automatically, and consecutive spaces are collapsed to one before validation runs.
- The admin terminal `finger` and `msg` commands now handle multi-word usernames correctly. The `msg` command accepts a quoted username syntax (`msg "Dark Knight" hello`) for names that contain spaces.

### User Guide

- A built-in User Guide is now available at `/user-guide`, covering the dashboard, echomail, netmail, doors, file areas, and exploring BBS networks.
- The guide is linked from the user dropdown menu under **User Guide**, grouped with a divider above the Logout option.
- No configuration or database changes are required.

### Dashboard Layout Customization

- Users can now reorder and rearrange dashboard cards to suit their preferences using a **Customize Dashboard** button at the top of the dashboard.
- Cards can be dragged between the main column and the sidebar, reordered within each column, and individually shown or hidden. The "Unread Mail" card is always visible and cannot be hidden.
- Layout preferences are saved per user and persist across sessions. A **Reset to Default** option restores the original layout.
- A database migration adds a `dashboard_layout` column to `user_settings`. Run `php scripts/setup.php` to apply.

### Sysop Default Dashboard Layout

- Sysops can now configure the default card layout that users see before they have saved a custom layout of their own.
- The setting is in **Admin → Appearance & Content** on a new **Dashboard** tab, which appears first in the tab list.
- Cards are arranged by drag-and-drop into three columns: Main Column, Sidebar Column, and Hidden by Default.
- Cards labelled Admin Only or marked with a feature badge will still only appear for users with the appropriate access level, regardless of where the sysop places them.
- No database migration is required. The layout is stored in `data/appearance.json`.

### AI Bots

- Sysops can create AI-powered bot personas that participate in local chat.
- Each bot has its own system user account, a configurable system prompt, a choice of AI provider and model, and a weekly API cost budget.
- Bots appear in the chat online users list with a robot icon and respond to direct messages and @mentions in rooms.
- Managed from **Admin → Community → AI Bots**.
- Three database migrations are required. Run `php scripts/setup.php` to apply.
- A new background daemon, `scripts/ai_bot_daemon.php`, must be started and kept running for bots to respond.

### Chat Markdown Rendering

- Chat messages are now rendered as Markdown, allowing bold, italic, inline code, code blocks, lists, and other formatting to display properly in the chat interface.
- Rendering is performed server-side using the existing Markdown renderer, so no client-side library is required.
- No configuration or database changes are required.
- No database migration is required.

### URL Link Open Graph Image Preview

- When a URL link's preview is opened, the system fetches the linked page's `og:image` meta tag server-side and displays the image above the description.
- The upload modal has a new **Add Link** tab where a URL can be submitted with a short and long description.
- A **Fetch Info** button retrieves the page title and description from the URL automatically to pre-fill the description fields.
- URL links appear in file listings alongside regular files, marked with a link icon. Clicking the filename opens a preview card showing the descriptions and a Visit button.
- URL links go through the same approval workflow and credit system as file uploads.
- Admins can edit the URL of a link record from the file edit dialog.
- The database migration relaxes the `NOT NULL` constraints on `file_hash` and `storage_path` in the `files` table, as URL records have no physical file. Run `php scripts/setup.php` to apply.

## Markdown WYSIWYG Compose Editor

When the Markup Format selector is set to **Markdown**, the compose form's plain textarea is replaced by a full WYSIWYG editor built on Toast UI Editor. The editor presents two tabs — **Edit** and **Preview** — and a formatting toolbar above the editing area.

**Toolbar**

The toolbar contains buttons for the most common Markdown constructs:

| Button | Effect |
|---|---|
| **B** | Bold |
| *I* | Italic |
| H1 / H2 / H3 | Heading levels 1–3 |
| `</>` | Inline code |
| Code block | Fenced code block |
| Link | Insert or wrap selection in a link |
| Image | Open the image insert dialog (URL, upload, paste, or My Images) |
| List / Ordered List | Unordered or numbered list |
| Blockquote | Block quote |
| — | Horizontal rule |

**Keyboard shortcuts**

- **Ctrl+B** — bold
- **Ctrl+I** — italic
- **Ctrl+K** — insert link

**Preview tab**

The Preview tab renders the current Markdown source using the editor's built-in renderer. The output is a close approximation of how the message will appear to recipients, though minor differences in styling may exist compared to the reader.

**URL unfurl on paste**

When a bare URL (a plain `https://…` string with no surrounding text) is pasted into the editor, a prompt appears offering to fetch Open Graph metadata from the page. If the user confirms, the editor replaces the raw URL with a formatted Markdown link card: an optional thumbnail image, a bold linked title, and a blockquote description. The fetch is performed server-side so CORS restrictions do not apply. Dismissing the prompt leaves the URL as plain text.

**Replies to Markdown messages**

When replying to a message that was written in Markdown, the quoted text is wrapped in standard Markdown blockquote syntax (`> `). Heading markers that appear inside the quoted block (`## Heading`, `### Sub-heading`, etc.) are preserved correctly so they display as headings when the reply is read.

No configuration or database changes are required for the WYSIWYG editor. The editor activates automatically whenever the Markup Format is set to Markdown in the compose form.

## Markdown Heading Rendering

Rendered Markdown messages now display headings with visual hierarchy that matches the compose editor's preview.

**Heading borders**

H1 headings have a double underline and H2 headings have a single underline, applied below each heading element in the message body. This mirrors the convention used by the Markdown compose editor's Preview tab and by widely used Markdown renderers such as GitHub.

**Heading size hierarchy**

Previously, the user's configured message font size was applied uniformly to all content inside the message body, which caused H1 through H6 headings to render at the same size as body text. Headings now scale proportionally:

| Heading | Size relative to body text |
|---|---|
| H1 | 1.5× |
| H2 | 1.3× |
| H3 | 1.15× |
| H4 | 1.0× |
| H5 | 0.9× |
| H6 | 0.85× |

The sizes track the user's font size preference — a larger configured font size produces proportionally larger headings.

No configuration or database changes are required.

## Echomail Moderation

New users' echomail posts can optionally be held in a moderation queue before being forwarded to the network. This feature is **disabled by default** — no action is required if you do not want to use it.

**How it works**

When moderation is enabled and a new user submits an echomail message, it is stored with `moderation_status = 'pending'` rather than being spooled for outbound delivery immediately. An admin reviews the post from the moderation queue and either approves or rejects it. Approved posts are spooled for network distribution and become visible to all subscribers. Rejected posts are removed from public view and are never forwarded.

Once a user accumulates enough approved posts to meet the configured threshold, they are automatically promoted to unmoderated status and their future posts are published immediately without requiring review.

**Configuration**

The approval threshold is set under **Admin → BBS Settings**. The default value is `0`, which disables moderation — all users post without moderation. Set it to `N` to require each new user to have `N` echomail posts approved on non-local echoareas before bypassing the queue.

**Upgrading**

The database migration adds `moderation_status` and `user_id` columns to the `echomail` table, and a `can_post_netecho_unmoderated` column to the `users` table. All users who have previously logged in are marked as unmoderated automatically so existing community members are not affected. Run `php scripts/setup.php` to apply the migration.

## Polls

The order in which polls appear has changed. Previously all polls were returned oldest-first regardless of voting status. Now:

- Polls the user has **not yet voted on** appear first, ordered newest first, so freshly created polls are always visible at the top.
- Polls the user **has already voted on** appear afterward, also ordered newest first.

No configuration or database changes are required for this behaviour.

## File Area URL Links

File areas can now contain entries that point to external URLs rather than stored files. This allows sysops and users to catalogue links to GitHub repositories, project pages, documentation, or any other web resource alongside regular file uploads.

**Adding a link**

The upload modal on the File Areas page has a new **Add Link** tab. Enter a full URL (e.g. `https://github.com/owner/repo`) and optionally click **Fetch Info** to have the server retrieve the page title and Open Graph description automatically. The short and long description fields are pre-filled from the fetched metadata and can be edited before submitting.

**How links appear**

URL entries appear in all file listing views — the area file list, the recent uploads list, My Uploads, and file search results — with a link icon instead of a file icon, a dash in the size column, and a Visit button in the actions column. Clicking the filename opens the preview modal, which displays the short description, long description, and a **Visit** button that opens the URL in a new tab. The download button in the preview modal header also navigates to the URL. The file information modal accessible from the echomail page likewise shows a Visit button for URL link entries.

**Approval and credits**

URL links use the same pending/approved workflow as file uploads. When a user submits a link in an area that requires approval, it enters the admin approval queue at **Admin → File Approvals**. The queue displays the URL alongside the description so admins can review it before approving or rejecting. The credit system treats link submissions identically to file uploads — the same upload cost and upload reward apply.

**Admin editing**

Admins can edit the URL of a link record at any time through the standard file edit dialog (the pencil button in the file details modal). The URL field is only shown for link-type records.

**Database changes**

Migration `v1.11.0.72` makes the following schema changes to the `files` table:

- Adds a `url TEXT` column (nullable) to store the external URL for link records.
- Drops the `NOT NULL` constraint from `file_hash`, since link records have no file to hash.
- Drops the `NOT NULL` constraint from `storage_path`, since link records have no physical storage path.

The existing unique constraint on `(file_area_id, file_hash)` continues to work correctly — PostgreSQL treats `NULL` values as distinct in unique indexes, so multiple link records in the same area do not conflict with each other.

Run `php scripts/setup.php` to apply the migration.

## URL Link Open Graph Image Preview

When a URL link entry's preview card is opened — either in the file area preview modal or the file information modal on the echomail page — the system fetches the linked page server-side and parses the `og:image` meta tag. If an image URL is found, it is displayed above the description as a preview thumbnail. The fetch is performed server-side, so no browser CORS restrictions apply. If no OG image is present or the image cannot be loaded, the preview displays only the description and Visit button.

No configuration or database changes are required for this feature.

## MCP Server Memory Leak Fix

The MCP server (`mcp-server/server.js`) handles each incoming request by creating a new `McpServer` instance paired with a `StreamableHTTPServerTransport`. Previously, only the transport was closed when the HTTP response ended — the `McpServer` itself was never closed. Because the SDK registers internal listeners that cause the transport to hold a reference back to the server, these instances accumulated in memory rather than being garbage collected, causing the process's RAM usage to grow steadily over time.

The server is now explicitly closed alongside the transport when each response ends. No configuration or database changes are required. Restarting the MCP server daemon picks up the fix automatically.

## Optional Spaces in Usernames

Usernames have historically been restricted to letters, numbers, and underscores. This restriction is unchanged by default. A new `.env` variable, `USERNAMES_ALLOW_SPACES`, allows the sysop to relax this rule so that handles like `Phantom of Doom` or `Dark Knight` can be registered.

**Enabling the feature**

Add the following line to your `.env` file:

```
USERNAMES_ALLOW_SPACES=true
```

Restart the web server and the telnet/SSH daemons after making this change. No database migration is required.

**Validation rules when enabled**

- The username must be between 3 and 20 characters in total length.
- Word groups must consist of letters, digits, or underscores — the same characters permitted previously.
- Words may be separated by a single space. Double spaces, leading spaces, and trailing spaces are all rejected. The registration form trims and collapses whitespace before applying the rule, so a user who accidentally types two spaces between words receives a corrected name rather than an error.
- Only the ASCII space character (U+0020) is permitted. Tabs and other Unicode whitespace are not matched and remain forbidden.

**MRC display**

Users who register with a spaced handle will appear in MRC chat with spaces converted to underscores (e.g. `Phantom_of_Doom`). This is a constraint of the MRC wire protocol, not a bug. The stored username retains the space; only the transmitted form is altered.

**Admin terminal**

The `finger` command in the admin terminal now accepts multi-word usernames directly:

```
finger Dark Knight
```

The `msg` command requires the username to be quoted when it contains spaces:

```
msg "Dark Knight" Your account has been reviewed.
```

The unquoted single-word form continues to work as before.

**rename_user.php**

The `rename_user.php` CLI script, which renames a local user account across all tables that store the username as a string, now also updates the `cwn_networks.submitted_by_username` column. This column stores the submitter's name at the time a CWN WiFi network entry was created and is read directly by the CWN WebDoor without a live join to the users table.

**Considerations before enabling**

- Usernames with spaces are visually similar to real names. The existing uniqueness constraint prevents an exact case-insensitive match between a username and any real name, but it does not prevent near-matches.
- Some older FTN node software reads name fields with whitespace-delimited parsers and may truncate a spaced handle at the first space when routing netmail to this system.
- If you have users who registered with underscores under the old rules and wish to switch to a spaced form, use `scripts/rename_user.php` to rename them.

## User Guide

A built-in User Guide has been added at `/user-guide`. It introduces new and returning users to the main features of the BBS: the dashboard, echomail and how FTN forums work, netmail, doors, file areas, and how to explore the broader BBS network through the Nodelist and BBS Directory.

The guide is accessible to both guests and logged-in users. A **User Guide** link appears in the user dropdown menu (the menu opened by clicking your username in the navigation bar), grouped between a divider and the Logout option. Profile and Settings items in the same menu now display icons for easier scanning.

The guide source lives at `docs/userguide/index.md` and is rendered server-side using the existing Markdown renderer. To customise the text for your BBS, edit that file directly — no code changes are required.

No configuration or database changes are required.

## Dashboard Layout Customization

The dashboard now supports per-user layout customization. A **Customize Dashboard** button appears at the top right of the dashboard page and opens a modal showing all available cards organized into two columns — the wide main column and the narrow sidebar.

**What users can do**

- Drag cards up or down within a column to change the order they appear on the dashboard.
- Drag a card from one column to the other to move it between the main area and the sidebar.
- Click the eye icon on any card to hide it. Clicking again makes it visible. The "Unread Mail" card cannot be hidden because it is the primary navigation element of the dashboard.
- Click **Save** to apply the changes immediately without a page reload.
- Click **Reset to Default** to discard all customization and return to the system default layout.

**Default layout**

The default card layout can be configured by the sysop from **Admin → Appearance & Content → Dashboard** (see [Sysop Default Dashboard Layout](#sysop-default-dashboard-layout) below). When no sysop layout has been saved, the order falls back to the built-in defaults defined in `src/DashboardCardRegistry.php`, where the `default_zone` field on each card entry determines which column it starts in.

Cards that are gated behind BBS features (shoutbox, voting booth, advertising) or the referral credits system only appear in the customize modal when those features are active. The admin-only "Today's Callers" card is only shown to admin users.

**Database changes**

Migration `v1.11.0.73` adds a `dashboard_layout` column of type `jsonb` to the `user_settings` table. Run `php scripts/setup.php` to apply.

## Sysop Default Dashboard Layout

Sysops can now define the card layout that users see when they first visit the dashboard — or after clicking Reset to Default — without editing PHP source files.

**Where to find it**

The setting is under **Admin → Appearance & Content**. A new **Dashboard** tab appears first in the tab list. Opening it reveals three columns:

- **Main Column** — cards that occupy the wide left column.
- **Sidebar Column** — cards that occupy the narrow right column.
- **Hidden by Default** — cards hidden from view unless the user explicitly shows them.

**How to configure it**

Drag any card chip from one column to another to reposition it. Cards can also be reordered within a column by dragging them up or down. Click **Save Dashboard Layout** when done. Changes take effect immediately for any user who has not saved their own custom layout. Users who have already customized their dashboard are unaffected.

**Badges on card chips**

Each chip may carry one or more badges:

| Badge | Meaning |
|---|---|
| Required | Card cannot be placed in Hidden by Default (the "Unread Mail" card) |
| Admin Only | Card is only rendered for users with admin access |
| Feature name | Card is only rendered when the named BBS feature is enabled |

Moving an Admin Only or feature-gated card to any column is harmless — the card will simply not appear for users who lack the required access, regardless of where it is placed.

**Reset to Defaults**

Clicking **Reset to Defaults** removes the saved sysop layout and restores the built-in defaults from `src/DashboardCardRegistry.php`.

No database migration is required. The layout is stored in `data/appearance.json` alongside other appearance settings.

## AI Bots

AI bots are chat personas driven by an external AI provider. Each bot has its own system user account and a configurable set of activities that determine when and how it responds. The first supported activity is local chat: responding to direct messages and to @mentions in chat rooms.

**Creating and managing bots**

Bots are managed under **Admin → Community → AI Bots**. The list shows all configured bots with their current-week API spend as a percentage of the weekly budget. Colour coding indicates budget headroom: green for under 75 %, amber for 75–99 %, and red when the budget has been reached.

The create/edit form covers:

| Field | Description |
|---|---|
| Username | The system username the bot appears under in chat |
| Display Name | Human-readable name shown in the admin list |
| Description | Optional internal note |
| System Prompt | The AI instruction that shapes the bot's persona and behaviour |
| Provider | AI provider: `openai` or `anthropic` |
| Model | The specific model identifier to use (e.g. `gpt-4o`, `claude-sonnet-4-6`) |
| Weekly Budget (USD) | Maximum spend per calendar week (Sunday–Saturday UTC). The bot stops responding when this limit is reached |
| Context Messages | Number of preceding messages passed to the AI as conversation history |
| Active | Whether the bot is enabled |

**Local chat activity**

The **Local Chat** section of the edit form controls where the bot participates:

- **Respond to direct messages** — the bot replies to any DM sent to its system user.
- **Respond to @mentions in rooms** — the bot replies when its username is @mentioned in a room message.
- **Allowed rooms** — if left blank, the bot participates in all rooms; specify room IDs to restrict it to particular rooms.

**System users**

When a bot is created, a system user account is created in the `users` table with `is_system = TRUE` and a randomly generated locked password. This account cannot be logged into. If a system user with the same username already exists (e.g. from a previously deleted bot), it is reused rather than duplicated. When a bot is deleted, its system user is removed as well.

**Bot presence in chat**

Active bots always appear in the chat users list regardless of active sessions. Bot entries are shown with a robot icon to distinguish them from human users.

**Bot daemon**

Bots respond to messages via a long-running background daemon: `scripts/ai_bot_daemon.php`. It connects to PostgreSQL and listens for real-time chat events using `LISTEN/NOTIFY`. Start it alongside the other daemons:

```bash
php scripts/ai_bot_daemon.php --daemon --pid-file=data/ai_bot_daemon.pid
```

The daemon can be restarted from the admin panel under **Admin → System → Daemon Status → Restart AI Bot Daemon**. Its log is written to `data/logs/ai_bot_daemon.log`.

**Database changes**

Three migrations are required:

- `v1.11.0.74` — creates the `ai_bots` table.
- `v1.11.0.75` — creates the `ai_bot_activities` table.
- `v1.11.0.76` — adds a `bot_id` column to `ai_requests` so per-bot API cost tracking is accurate even when multiple bots share a provider account.

Run `php scripts/setup.php` to apply all three.

**AI provider configuration**

Bot API calls use the same provider credentials configured in `.env` for the rest of the AI features (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`). No additional environment variables are required.

## Chat Markdown Rendering

Chat messages are now rendered as Markdown. This applies to all messages in local chat rooms and direct messages, including replies from AI bots.

Supported formatting includes bold (`**text**`), italic (`*text*`), inline code (`` `code` ``), fenced code blocks, unordered and ordered lists, blockquotes, and headings. Raw HTML is not passed through — only Markdown constructs are rendered.

Rendering is performed server-side by the same `MarkdownRenderer` used for echomail and netmail. The rendered HTML is delivered alongside the plain-text body in API responses and in real-time SSE events, so no client-side Markdown library is needed.

No configuration or database changes are required.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
```

### Using the Installer

Re-run the BinktermPHP installer to update the application files. When prompted to run `php scripts/setup.php`, allow it to complete.
