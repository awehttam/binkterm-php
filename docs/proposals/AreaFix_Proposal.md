# AreaFix / FileFix Manager — Draft Proposal

> **Draft** — AI-generated, may not have been reviewed for accuracy.

## Overview

AreaFix and FileFix are Fidonet robot services run by hub operators that allow downlink nodes to manage their echomail and file-area subscriptions by sending specially formatted netmail messages. This proposal describes an admin UI and supporting backend to compose, send, and parse AreaFix/FileFix messages, and to keep the local echo area and file area tables in sync with the hub's responses.

---

## Table of Contents

1. [Background](#background)
2. [Answered Design Questions](#answered-design-questions)
3. [Config Changes](#config-changes)
4. [Backend — `src/AreaFixManager.php`](#backend--srcareafixmanagerphp)
5. [Admin UI — `/admin/areafix`](#admin-ui--adminareafix)
6. [Routes](#routes)
7. [Response Parsing](#response-parsing)
8. [Echo Area Sync](#echo-area-sync)
9. [FileFix](#filefix)
10. [Subject Masking](#subject-masking)
11. [Migration](#migration)

---

## Background

The AreaFix protocol works as follows:

- The node sends a **netmail** to the recipient name `AreaFix` at the hub's FTN address.
- The **subject line** contains the AreaFix password (a shared secret the hub operator gives you). This is sensitive — it must never be displayed in the UI.
- The **message body** contains one command per line:

| Command | Meaning |
|---|---|
| `+AREA_NAME` | Subscribe to an echo area |
| `-AREA_NAME` | Unsubscribe from an echo area |
| `%LIST` | List all areas available at the hub |
| `%QUERY` | List areas you are currently subscribed to |
| `%UNLINKED` | List available areas you are NOT subscribed to |
| `%HELP` | Request help text from the robot |
| `%PAUSE` | Pause all subscriptions |
| `%RESUME` | Resume paused subscriptions |

The hub's AreaFix robot processes the message and sends a **reply netmail** back to the node containing the results. Multiple commands can appear in a single message body.

FileFix works identically but the recipient name is `FileFix` and it manages file echo areas (TIC files).

---

## Answered Design Questions

| Question | Decision |
|---|---|
| Subscribe optimistically or wait for `%QUERY` confirm? | **Wait for confirm** — add area to local DB only after parsing a `%QUERY` or `%LIST` reply that includes the area |
| Unsubscribe deactivates local echo area? | **Yes** — mark `is_active = false` on the local `echoareas` record |
| FileFix in same UI? | **Yes** — same page, separate tab per robot type |
| Parse `%LIST` for area descriptions? | **Yes, best-effort** — format varies by hub software; fall back to raw display |

---

## Config Changes

Add `areafix_password` and `filefix_password` per uplink in `config/binkp.json`:

```json
{
    "uplinks": [
        {
            "address": "1:1/23",
            "password": "session_secret",
            "tic_password": "",
            "areafix_password": "myareafixpass",
            "filefix_password": "myfilefixpass"
        }
    ]
}
```

Add to `config/binkp.json.example` with empty defaults.

Add to `src/Binkp/Config/BinkpConfig.php`:

```php
public function getAreafixPassword(string $uplinkAddress): string
public function getFilefixPassword(string $uplinkAddress): string
```

The Admin UI BinkP config editor should expose these fields per uplink.

---

## Backend — `src/AreaFixManager.php`

```php
class AreaFixManager
{
    /**
     * Send AreaFix or FileFix commands to a hub uplink.
     *
     * @param string   $uplinkAddress  FTN address of the hub (e.g. "1:1/23")
     * @param string[] $commands       Command lines (e.g. ["%QUERY"], ["+SYSOP", "-FIDONEWS"])
     * @param string   $robot          "areafix" or "filefix"
     * @param int      $sysopUserId    User ID of the sysop account
     */
    public function sendCommand(
        string $uplinkAddress,
        array $commands,
        string $robot,
        int $sysopUserId
    ): void;

    /**
     * Parse a %QUERY or %LIST reply body into an array of area names (and
     * optionally descriptions). Format varies by hub software — see Response
     * Parsing section.
     *
     * @return array{name: string, description: string|null}[]
     */
    public function parseResponseText(string $body, string $commandType): array;

    /**
     * Given parsed area names from a %QUERY reply, ensure each area exists in
     * the local echoareas table. Creates missing areas; sets is_active = true
     * for areas that are subscribed. Optionally deactivates areas no longer
     * present in the query.
     */
    public function syncSubscribedAreas(
        string $uplinkAddress,
        string $domain,
        array $areaNames,
        bool $deactivateMissing = false
    ): array; // returns ['created'=>[], 'activated'=>[], 'deactivated'=>[]]

    /**
     * Mark a local echo area as inactive (called after successful unsubscribe).
     */
    public function deactivateArea(string $areaTag, string $domain): void;
}
```

---

## Admin UI — `/admin/areafix`

Page: `templates/admin/areafix.twig`

### Layout

- Uplink selector (dropdown or tabs) — only uplinks with `areafix_password` OR `filefix_password` configured are shown
- Per-uplink view has two tabs: **AreaFix** and **FileFix** (FileFix tab hidden if no password configured)

### AreaFix / FileFix Tab

```
┌─ Quick Actions ─────────────────────────────────────────────────────┐
│  [%QUERY]  [%LIST]  [%UNLINKED]  [%HELP]  [%PAUSE]  [%RESUME]      │
└─────────────────────────────────────────────────────────────────────┘

┌─ Subscribe / Unsubscribe ───────────────────────────────────────────┐
│  Area tag: [_______________]  [+ Subscribe]  [- Unsubscribe]        │
└─────────────────────────────────────────────────────────────────────┘

┌─ Freeform Commands ─────────────────────────────────────────────────┐
│  [textarea — one command per line]                [Send]            │
└─────────────────────────────────────────────────────────────────────┘

┌─ Latest Reply ──────────────────────────────────────────────────────┐
│  Parsed area list (if last reply was %QUERY / %LIST / %UNLINKED)    │
│  with checkboxes:  ☑ SYSOP  ☑ FIDONEWS  ☐ OTHERNET ...             │
│  [Sync subscribed areas to echo area table]                         │
│  Raw reply text (collapsible)                                       │
└─────────────────────────────────────────────────────────────────────┘

┌─ Message History ───────────────────────────────────────────────────┐
│  Table of sent requests and received replies (subject masked)       │
│  Clicking a reply expands its body                                  │
└─────────────────────────────────────────────────────────────────────┘
```

### Area List Display

When the latest reply is parsed as a `%LIST`, `%QUERY`, or `%UNLINKED` response:

- Show a searchable table: **Area tag** | **Description** | **Subscribed?** | **Actions**
- Subscribed areas (from `%QUERY`) shown with a green badge
- Unsubscribed available areas with a **Subscribe** button
- Subscribed areas with an **Unsubscribe** button
- **Sync** button creates missing echo areas in the local DB from the parsed list

---

## Routes

### Pages
- `GET /admin/areafix` — main page

### API
- `GET /api/admin/areafix/uplinks` — list uplinks that have areafix or filefix passwords configured
- `GET /api/admin/areafix/history?uplink=1:1/23&robot=areafix` — AreaFix/FileFix message history (uses existing `MessageHandler::getLovlyNetRequests()`)
- `POST /api/admin/areafix/send` — send commands; body: `{uplink, robot, commands[]}`
- `POST /api/admin/areafix/sync` — parse latest `%QUERY` reply and sync echo areas; body: `{uplink, robot, deactivate_missing}`

---

## Response Parsing

Hub AreaFix software varies. Known formats:

**Binkd / Husky (most common)**
```
Area list for 1:234/567:
SYSOP          SysOp echo
FIDONEWS       FidoNet news
OTHERNET       Other Network
```

**FrontDoor / InterMail**
```
Areas linked at 1:234/567.0:
 SYSOP
 FIDONEWS
 OTHERNET
```

**Mystic BBS / MBSE**
```
+OK SYSOP
+OK FIDONEWS
-ERR RESTRICTED_AREA Not available
```

**Raw `%LIST` (area+description, various separators)**
```
SYSOP - SysOp echo for FidoNet
FIDONEWS    FidoNet Weekly News
```

Parsing strategy for `parseResponseText()`:
1. Strip header/footer lines (lines with "Area list", "linked at", "---", etc.)
2. For each remaining non-blank line, extract the first whitespace-delimited token as the area tag (uppercase, validate against `[A-Z0-9_\-\.]+`)
3. Remainder of line (after optional separator ` - `, `\t`, or multiple spaces) is the description
4. Lines starting with `-ERR` or `%` are skipped
5. Lines that look like status messages (no valid area tag pattern) are skipped
6. Return raw body alongside parsed results — always show raw in a collapsible section

---

## Echo Area Sync

`syncSubscribedAreas()` behaviour:

1. For each area name in the parsed list:
   - If an `echoareas` row exists with matching `tag` (case-insensitive) and `domain` → ensure `is_active = true`, set `uplink_address` if not already set
   - If no row exists → `INSERT` with `is_active = true`, `domain` from uplink, `uplink_address` from uplink, description from parsed response if available
2. If `deactivate_missing = true`: mark areas in the DB that have `uplink_address` matching this hub but are NOT in the parsed list as `is_active = false`
3. Return summary counts for display in the UI

**Important**: Sync only runs when the sysop explicitly clicks **Sync**. It does not run automatically on reply receipt.

---

## FileFix

FileFix is identical to AreaFix in protocol. The robot name is `FileFix` and it manages file echo areas (TIC-based file distributions). The UI and backend handle both robots with the same code path, parameterised by `$robot = 'areafix' | 'filefix'`.

File area sync (`syncSubscribedAreas` for FileFix) targets the `file_areas` table rather than `echoareas`.

---

## Subject Masking

Already implemented in `MessageHandler::cleanMessageForJson()` as of this branch: any message where `to_name` or `from_name` contains "areafix" or "filefix" (case-insensitive) has its `subject` field replaced with `••••••••` before the data leaves the server. This covers all display paths — list view, single message view, conversation view, threaded view, and the AreaFix history panel.
