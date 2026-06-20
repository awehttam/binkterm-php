# Upgrading to 1.9.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Interface](#web-interface)
  - [Admin Controls and Moderation](#admin-controls-and-moderation)
  - [Messaging and FTN Behavior](#messaging-and-ftn-behavior)
  - [User Experience and Reader UI](#user-experience-and-reader-ui)
  - [Operations, Imports, and Localization](#operations-imports-and-localization)
- [Developer / Infrastructure](#developer--infrastructure)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Web Interface

#### Admin Controls and Moderation

- **Admin -> Users -> Edit User** now includes two separate per-user netecho moderation controls. **Allow unmoderated netecho posting** is the normal bypass for the global new-user moderation threshold, while **Force echomail moderation** is an emergency override that pushes all networked echomail posts from a specific user back into the moderation queue.
- New-user registration approval is now controlled by a dedicated BBS setting. Self-registrations still require manual approval by default, but sysops can now disable that requirement and have new accounts activated immediately. Approved registrations are also retained as history records instead of being deleted from `pending_users`.

#### Messaging and FTN Behavior

- Network and echoarea settings now include a **Missing CHRS fallback charset** used only when inbound FTN messages arrive without a `CHRS` kludge. Inbound decode order now prefers the per-area override, then the network fallback, then the legacy guess order. Message charset edits for netmail and echomail now rebuild `message_text` from `raw_message_bytes` when raw bytes are available, and a new CLI tool can bulk rebuild stored echomail text for a selected area or domain.
- Echomail tag validation is now less strict across the web UI, admin echoarea editor, importer, and route matching. Common FTN tag punctuation such as `&`, `!`, and `%` is now accepted in area tags, so names like `AT&T_CHAT` no longer fail validation.
- User settings now include a PGP tab where users can upload multiple public keys, choose a preferred key, browse the public keyserver, and use that public-key directory from the netmail compose flow when encrypting outbound mail.
- BBS-managed private key hosting is available behind a separate sysop toggle and is off by default.
- Auto Feed sources now have an option to prefix posted echomail subjects with the configured feed name, producing subjects like `[2600.network] TITLEOFPOST` before the FTN 72-character subject limit is applied.
- MeshCore repeater adverts are now stored in a dedicated `meshcore_node_adverts` table keyed by full public key. The CWN map/list and the public PacketBBS node directory still show MeshCore nodes after upgrade, but live advert writes no longer go into `cwn_networks`.

#### User Experience and Reader UI

- The user-facing echoarea subscription manager at `/subscriptions` now uses a more compact filter layout modeled after `/echolist`, with network filtering and an option to show only interest groups that currently have message traffic.
- Subscribing or unsubscribing from an echoarea in `/subscriptions` now updates in place instead of reloading the page, preserving the current scroll position and active search/filter state.
- The subscribed echomail message list now avoids duplicate unread-count work, skips unnecessary joins in its pagination count query, and deduplicates overlapping client-side refreshes, which reduces page-load time on systems with large echomail message bases.
- The browser notification-sound unlock path now respects each user's saved sound settings and no longer primes disabled sounds on first click. This avoids false notification sounds on Safari and Firefox when notification sounds are turned off.
- Local-only echomail areas now display with an explicit `@local` suffix in the web reader and compose UI, so area tags appear as `CHAT@local` instead of looking like a domain-qualified remote echo.

#### Operations, Imports, and Localization

- The manual nodelist import page no longer relies on a browser `accept` filter that attempted to match `Zxx` archives with `.z*`. Valid weekly nodelist bundles such as `LOVLYNET.Z25` now show up normally in the file chooser instead of requiring users to disable the picker filter by hand.
- The example nginx config in `docs/INSTALL.md` now includes cache-control rules for `/sw.js`, `.css`, and `.js` so updated frontend assets are revalidated more reliably. The nginx example remains untested and unsupported.
- A new Russian interface translation is now bundled under the `ru` locale, extending BinktermPHP's built-in language coverage for the web UI, API error text, and terminal strings.

### Developer / Infrastructure

- Realtime wake-up signaling now has a small transport abstraction around PostgreSQL `LISTEN/NOTIFY`. The current implementation is still PostgreSQL-only, but the direct `pg_*` calls are now concentrated in dedicated realtime classes instead of being spread across `BinkStream`, the AI bot daemon, and the admin daemon.
- Database bootstrap now has a minimal platform abstraction for DSN construction, session initialization, and base schema selection. PostgreSQL remains the only supported backend, but connection and setup behavior is no longer hardcoded in one place.
- `.env` may now include `DB_DRIVER=pgsql`. PostgreSQL is still the only supported value today. This setting exists to make future backend setup work easier to isolate if it is ever pursued.
- `.env` may now include `PIPE_CODE_PARSER_MODE` to control how BBS pipe color codes are recognized by the web renderer and terminal bulletin renderer.
- A new developer reference document, `docs/PostgreSQLDependencies.md`, tracks intentional PostgreSQL-specific dependencies and where they currently live.
- BinkP session logging now closes failed session rows more aggressively and retires orphaned `active` rows whose handler process has already exited, so the admin BinkP session view no longer treats dead pre-handshake sessions as long-running live connections.
- The `user_settings.theme` column now allows up to 300 characters instead of 20 so custom theme stylesheet paths and longer theme identifiers can be stored without truncation.

---

## Web Interface

### Admin Controls and Moderation

### Per-User Netecho Moderation Controls

The user edit screen at **Admin -> Users -> Edit User** now exposes two separate moderation controls for networked echomail posting.

What changed:

- **Allow unmoderated netecho posting** toggles the `users.can_post_netecho_unmoderated` flag directly
- this is the normal per-user override for the global **Echomail Moderation Threshold** setting used for new or not-yet-trusted users
- **Force echomail moderation** remains the stronger emergency override for abusive or high-risk users
- when **Force echomail moderation** is enabled, that user is held for review even if they would otherwise bypass moderation because of their trusted status, history, or the unmoderated-posting flag
- the edit screen now includes clearer inline help and hover text so sysops can distinguish the routine trust override from the emergency hand-brake setting

This change does not alter the global moderation threshold itself. It adds a clearer per-user control path in the admin UI for deciding whether a specific account should bypass or always enter the netecho moderation queue.

### Registration Approval Setting

Self-registration approval is now configurable in **Admin -> BBS Settings -> Features** with a new **Require approval for new users** toggle.

Default behavior after upgrading remains the same as earlier 1.9.x builds:

- the setting defaults to enabled
- new self-registrations continue to enter **Admin -> Users -> Pending**
- sysops can approve or reject those registrations manually

If you disable the setting, new registrations are converted into active accounts immediately instead of waiting in the pending queue. The registration form and terminal registration flow both follow the same setting.

Approved registrations are now retained as audit-history rows in `pending_users` instead of being deleted when the real user account is created.

What changes in the data model:

- `pending_users` now gains a `created_user_id` link to the user account created during approval
- approved rows remain in place with `status = approved`, review timestamps, reviewer ID, and any admin notes
- rejected rows continue to remain in place with `status = rejected`
- cleanup now removes only old rejected rows; approved registration history is kept

To support retained history, the upgrade also replaces the old global uniqueness behavior on `pending_users.username` and `pending_users.real_name` with pending-only uniqueness. This allows a historical approved or rejected registration row to remain in the table without blocking a later unrelated registration attempt that uses the same name.

This change does not restore approved registration rows that were deleted by earlier versions. It applies to approvals performed after you upgrade and run `php scripts/setup.php`.

### Messaging and FTN Behavior

### Missing CHRS Charset Fallbacks

Inbound FTN messages that arrive without a `CHRS` kludge can now use explicit fallback charset settings instead of relying only on the historical guess order.

What changed:

- **Admin -> Networks** now includes a **Missing CHRS fallback charset** setting
- **Admin -> Echo Areas** now includes a per-area **Missing CHRS charset** override
- inbound packet processing now uses this order when `CHRS` is missing:
  - echoarea `missing_chrs_charset`
  - network `missing_chrs_charset`
  - the legacy guess order (`CP437`, `CP850`, `ISO-8859-1`, `CP1252`)
- the existing network **Default Outgoing Charset** setting continues to control newly composed outbound messages; it is no longer described as a general-purpose fallback for inbound mail

If you have existing imported echomail that was decoded with the wrong charset because `CHRS` was missing, you can now rebuild `message_text` from the stored raw bytes with:

```bash
php scripts/rebuild_echomail_message_text.php --domain=fidonet --dry-run
php scripts/rebuild_echomail_message_text.php --echoarea=GENERAL@fidonet
```

Message metadata edits now also re-decode from `raw_message_bytes` when you change `message_charset` on an individual netmail or echomail message.

### Echomail Tag Validation

Echomail area tags now accept a broader ASCII punctuation set in the main validation paths.

What changed:

- the admin echoarea create and edit API now accepts tags containing `&`, `!`, and `%`
- the CSV and `.NA` echoarea importer now accepts the same character set
- echomail route matching now accepts those tags in URL paths instead of rejecting them before the page handler runs
- file-area comment echoareas now use the same shared validation rule

This is intended for common FTN-style tags such as `AT&T_CHAT`. Non-ASCII / UTF-8 echoarea tags are still not treated as safe or supported for interoperability.

### PGP Key Management

The new PGP settings tab lets users manage multiple public keys on a single account. Users can upload armored public keys, choose a preferred key, and browse the public keyserver from the keyserver link in settings.

Two BBS-level flags control the feature:

- `Enable PGP` turns the user-facing PGP tab and public keyserver on or off
- `Allow BBS-managed private keys` controls whether users can generate and store a BBS-managed private key pair

Both settings default to off. After upgrading, sysops who want the feature must enable it in **Admin -> BBS Settings**.

If managed private keys are disabled, users can still upload public keys and select a primary key, but the private-key generator is hidden.

The compose page now also uses the public-key directory for netmail encryption lookups. When users enable `Encrypt this netmail`, the UI searches the keyserver using the recipient text and shows an explicit public-key selector before sending. That lookup can surface:

- the user's published PGP UID
- the key fingerprint
- the key label
- matching BBS usernames and real names
- saved address-book entries, including local-user matches surfaced by the address-book search API

If the compose autocomplete only shows saved contacts and not local users, make sure the address-book search route is returning both data sources. The current implementation exposes both through `GET /api/address-book?search=...` and the legacy `/api/address-book/search/{query}` alias.

### Auto Feed Subject Prefix Option

Auto Feed sources now have a per-feed option to prefix each posted echomail subject with the configured feed name.

When enabled in **Admin -> Auto Feed**, subjects are generated in this form:

```text
[feed_name] article title
```

This is useful when multiple feeds post into the same echo area and readers need to identify the source at a glance from the subject line alone.

The prefix is applied before the normal FTN 72-character subject limit, so long feed names reduce the space available for the article title.

### MeshCore Advert Storage Refactor

MeshCore repeater advert ingest no longer writes directly into `cwn_networks`.

Instead:

- live repeater adverts are written to `meshcore_node_adverts`
- `packet_bbs_nodes` gains a nullable `public_key` column so registered MeshCore bridge nodes can be linked to their live advert rows
- the CWN WebDoor now reads manual CWN rows plus live MeshCore advert rows through a projected union

During `php scripts/setup.php`, the new migration backfills existing legacy `cwn_networks.source_type = 'meshcore'` rows into `meshcore_node_adverts`. Manual CWN submissions stay in `cwn_networks` unchanged.

If you use MeshCore or PacketBBS bridge nodes, make sure `php scripts/setup.php` completes successfully before letting the bridge send fresh adverts. Until the migration has run, the new advert endpoint will not have its destination table available.

### User Experience and Reader UI

### Subscription Manager

The `/subscriptions` page now presents its filtering tools in a compact filter panel instead of a long row of controls and per-interest buttons.

The updated page adds:

- a network filter for narrowing the visible echoareas by network
- a compact interest picker instead of a button wall
- an `Only show groups with messages` filter that limits the visible results to interest-grouped areas with message activity
- the search and sort controls inside the same filter panel for a tighter layout
- in-place subscribe/unsubscribe updates that do not reset the current search, filters, or scroll position

This change is user-facing only. It does not alter subscriptions, interest membership, or message access rules.

### Echomail List Performance

The subscribed-message view behind `/echomail` now does less repeated database work per page load on large systems.

What changed:

- the list endpoint no longer performs its own duplicate unread-count scan for the subscribed-all-areas view
- the unread badge continues to refresh from the existing stats endpoint
- the pagination total query now avoids joining read-state and saved-message tables unless the selected filter actually needs them
- the browser now coalesces overlapping echomail stats refreshes for the same scope and reuses recent stats for a short window instead of issuing back-to-back duplicate requests
- high-churn refresh paths such as initial load, visibility restore, websocket-triggered updates, and bulk actions now share a centralized refresh flow instead of independently reloading the sidebar, list, and stats endpoints

On systems with large echomail message bases and users subscribed to many areas, this reduces the amount of full-table work needed to render the default message list without changing the visible behavior of the page.

### Notification Sound Unlock Fix

The web notifier no longer primes disabled notification sounds when the user first clicks on the page.

This fixes a browser-specific issue reported on Safari and Firefox where a notification sound could play on any click even when the affected notification sound setting was set to `disabled`.

What changed:

- the first-click audio unlock path now checks the user's saved notification sound settings before attempting to prime audio
- only sounds that are actually enabled for that user are unlocked
- if all notification sounds are disabled, the unlock step now does nothing

This change is client-side only. Make sure updated clients receive the new cached assets after upgrade.

### Local Echomail Area Display

The web echomail reader and compose interface now display local-only echomail areas with an explicit `@local` suffix.

Examples:

- `CHAT` is now shown to users as `CHAT@local`
- remote areas with a real FTN domain, such as `GENERAL@fidonet`, continue to display with their normal domain suffix

This change is presentation-only. Local areas continue to use their existing stored tag values and routing behavior; the UI now makes it clearer at a glance that the area is local to this BBS rather than part of a remote FTN domain.

### Operations, Imports, and Localization

### Nodelist Import File Picker

The manual nodelist import form at **Admin -> Nodelist -> Import** no longer uses a browser-side file chooser filter that tried to express weekly `Zxx` bundles with `.z*`.

In practice, some browsers treated that pattern literally instead of as a wildcard extension. This caused valid files such as `LOVLYNET.Z25` to be hidden in the picker unless the user manually changed the chooser to show all files.

The import form now leaves file-type filtering to the server-side importer, so supported plain-text nodelists and compressed weekly bundles can be selected normally from the browser dialog.

### Nginx Example Cache Rules

The example nginx config in `docs/INSTALL.md` now includes explicit cache-control rules for the service worker and frontend assets.

What changed:

- `/sw.js` is now served with `Cache-Control: no-cache`
- `.css` and `.js` files are now served with `Cache-Control: max-age=0, must-revalidate`
- the example adds `try_files` handling in those static-cache locations so missing assets return `404` instead of falling through unexpectedly

This update is intended to reduce cases where browsers continue using stale cached frontend code after an upgrade. The nginx example remains a starting point only and is still untested because nginx is not a supported deployment target.

### Russian Translation

BinktermPHP 1.9.8 now includes a bundled Russian translation under the `ru` locale.

The new locale covers the main web interface catalog, API error text, and terminal/BBS strings through the standard `common.php`, `errors.php`, and `terminalserver.php` catalogs in `config/i18n/ru/`.

If you rely on a pinned locale allowlist through `I18N_SUPPORTED_LOCALES`, add `ru` there after upgrading so users can select Russian. Installations that use locale auto-discovery will pick it up automatically from the new catalog directory.

## Developer / Infrastructure

### Realtime Signaling Abstraction

The realtime event path now uses dedicated transport and maintenance classes instead of inlining PostgreSQL signaling details directly into each caller.

This change does not alter the current supported backend. BinktermPHP still requires PostgreSQL, and BinkStream still uses PostgreSQL notifications today.

What changed:

- `src/Realtime/BinkStream.php` now publishes wake-up notifications through a dedicated publisher class
- `scripts/ai_bot_daemon.php` now listens through a dedicated PostgreSQL event listener class
- `src/Admin/AdminDaemonServer.php` now delegates `sse_events` cleanup to a maintenance service

This keeps the current PostgreSQL behavior while making future transport changes, such as Redis-backed wake-ups, easier to isolate.

### Database Bootstrap Abstraction

Database bootstrap now resolves platform-specific setup behavior through dedicated classes under `src/DatabasePlatform/`.

Current scope:

- DSN construction
- session initialization
- base schema path selection

PostgreSQL is still the only supported platform. The new `DB_DRIVER` setting should remain `pgsql`.

### Pipe Code Parser Mode

Pipe-code rendering now has a runtime parser-mode setting shared by the web ANSI renderer and the terminal bulletin renderer.

The new `.env` setting is:

```env
PIPE_CODE_PARSER_MODE=decimal_relaxed
```

Supported modes:

- `decimal_relaxed` - default. Greedily accepts two-digit decimal color codes such as `|01` even when the following text starts with an uppercase letter.
- `strict` - keeps the more conservative uppercase-boundary checks to reduce false positives in ordinary prose.
- `loose` - restores broader legacy matching for testing and comparison.

This change is primarily intended to improve compatibility with messages that contain decimal pipe color codes immediately followed by uppercase text, such as `|01A side of beans`, without forcing a code change when sysops want to compare parser behavior.

### User Theme Length Increase

The `user_settings.theme` column has been widened from `VARCHAR(20)` to `VARCHAR(300)`.

This supports longer stored theme values, including custom stylesheet paths that exceed the previous 20-character limit.

### BinkP Session Log Cleanup

The BinkP session log now treats abnormal session termination more defensively.

What changed:

- the inbound and outbound BinkP session wrappers now close the session log row when a PHP `Throwable` escapes the normal handshake or transfer flow
- the admin `active` BinkP session listing now retires older `active` rows whose recorded handler PID is no longer running
- `scripts/database_maintenance.php` now includes a stale-session cleanup pass before age-based BinkP log retention cleanup

This keeps the admin BinkP dashboard aligned with real process state when a remote peer connects, drops during handshake, and the handler process exits before the session log row was finalized.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
