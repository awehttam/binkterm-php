# AreaFix / FileFix Manager

The AreaFix / FileFix Manager lets sysops manage echomail and file-area subscriptions
with hub uplinks directly from the admin web interface. It implements the standard
Fidonet AreaFix and FileFix robot protocols.

---

## How It Works

AreaFix and FileFix are robot services run by hub operators. Your node communicates with
the robot by sending a specially formatted **netmail** to the hub:

- **To name**: `AreaFix` (for echomail areas) or `FileFix` (for file echo areas)
- **Subject**: the shared password provided by your hub operator (sensitive — never displayed in the UI)
- **Body**: one command per line

The hub processes the commands and replies with a netmail containing the results.

### Commands

| Command | Meaning |
|---|---|
| `%QUERY` | List areas you are currently subscribed to |
| `%LIST` | List all areas available at the hub |
| `%UNLINKED` | List available areas you are NOT subscribed to |
| `%HELP` | Request help text from the robot |
| `%PAUSE` | Pause all subscriptions |
| `%RESUME` | Resume paused subscriptions |
| `+AREA_NAME` | Subscribe to an echo area |
| `-AREA_NAME` | Unsubscribe from an echo area |

Multiple commands may appear in a single message body.

---

## Configuration

Add `areafix_password` and/or `filefix_password` to the relevant uplink entry in
`config/binkp.json`:

```json
{
    "uplinks": [
        {
            "address": "1:1/23",
            "password": "session_secret",
            "tic_password": "",
            "areafix_password": "myareafixpassword",
            "filefix_password": "myfilefixpassword"
        }
    ]
}
```

Both fields are optional. An uplink without either password will not appear in the
AreaFix / FileFix Manager page.

---

## Admin UI

Navigate to **Admin → AreaFix / FileFix** (or `/admin/areafix`).

If no uplinks have passwords configured, a setup guide is shown.

### Uplink Selector

When multiple uplinks are configured, use the dropdown to select the hub you want to
manage. Switching uplinks clears the reply panels.

### AreaFix / FileFix Tabs

The page has two tabs: **AreaFix** (echomail areas) and **FileFix** (file echo areas).
The FileFix tab is disabled if the selected uplink has no `filefix_password`.

### Quick Actions

One-click buttons for the most common commands:

- **%QUERY** — request the list of your current subscriptions
- **%LIST** — request the full area list from the hub
- **%UNLINKED** — request areas available but not yet subscribed
- **%HELP** — request help text from the robot
- **%PAUSE** — pause all subscriptions
- **%RESUME** — resume paused subscriptions

### Subscribe / Unsubscribe

Enter an area tag in the text field and click **Subscribe** or **Unsubscribe**.
This sends a `+TAG` or `-TAG` command to the hub.

### Freeform Commands

Enter one or more commands in the textarea (one per line) and click **Send**.
Useful for batch operations or commands not covered by the quick actions.

### Latest Reply

Shows the most recent incoming reply from the hub. If the reply is parseable as an
area list (`%LIST`, `%QUERY`, or `%UNLINKED` response), a searchable table is
displayed with:

- Area tag and description
- **Subscribe** / **Unsubscribe** action buttons per row
- A search box to filter large area lists
- A **Sync to Echo Areas** button (see below)
- A collapsible **Raw reply** section showing the full message body

### Sync to Echo Areas

The **Sync to Echo Areas** button creates or activates local `echoareas` database
rows for each area found in the parsed reply. Sync only runs when you explicitly
click the button — it does not run automatically on reply receipt.

For FileFix responses the sync targets the `file_areas` table instead.

Sync behaviour:
- Existing areas matching the tag+domain: `is_active` set to `true`, `uplink_address`
  and `description` filled in if not already set.
- New areas: inserted with `is_active = true`.
- The optional *deactivate missing* mode (available via the API, not the UI) sets
  `is_active = false` for areas belonging to this uplink that were not in the list.

### Message History

A table of all sent requests and received replies for the selected uplink. Subject
lines are automatically masked (replaced with `••••••••`) so the password is never
visible. Click a row to expand and read the full message body.

---

## Subject Masking

Any netmail where `to_name` or `from_name` contains "areafix" or "filefix"
(case-insensitive) has its subject field replaced with `••••••••` before the data
leaves the server. This is implemented in `src/MessageHandler.php` and covers all
display paths including the AreaFix history panel.

---

## API Reference

All endpoints require admin authentication.

### `GET /admin/areafix`
Render the AreaFix / FileFix Manager page.

### `GET /api/admin/areafix/uplinks`
Return the list of enabled uplinks that have `areafix_password` or `filefix_password`
configured.

**Response:**
```json
{
    "success": true,
    "uplinks": [
        {
            "address": "1:1/23",
            "domain": "fidonet",
            "has_areafix": true,
            "has_filefix": false
        }
    ]
}
```

### `POST /api/admin/areafix/send`
Send one or more commands to the hub robot.

**Request body:**
```json
{
    "uplink":   "1:1/23",
    "robot":    "areafix",
    "commands": ["%QUERY"]
}
```

**Response:** `{ "success": true }`

### `GET /api/admin/areafix/history?uplink=1:1/23`
Return AreaFix/FileFix message history for an uplink.

**Response:**
```json
{
    "success":  true,
    "messages": { "messages": [...], "threaded": true, "pagination": {...} }
}
```

### `POST /api/admin/areafix/sync`
Sync an explicit, caller-provided area list into the local echo/file area table. This is what the Admin → AreaFix / FileFix Manager page's preview modal calls to apply the sysop's checkbox selection — `areas` is normally the subset of `/api/admin/areafix/preview-latest`'s response the sysop left checked.

**Request body:**
```json
{
    "uplink":              "1:1/23",
    "robot":               "areafix",
    "areas":               [{"name": "FIDONEWS", "description": "FidoNet news"}],
    "deactivate_missing":  false,
    "force_descriptions":  true
}
```

`force_descriptions` (optional, default `false`): when true, an existing area's description is overwritten whenever the submitted one differs, bypassing the usual placeholder-only protection (see `AreaFixManager::isPlaceholderDescription()`). The admin UI always sends `true` here, since the sysop has already reviewed each selected area's description in the preview — including any mismatch flagged by `description_differs` — before confirming.

**Response:**
```json
{
    "success": true,
    "summary": { "created": 3, "activated": 1, "deactivated": 0 }
}
```

### `POST /api/admin/areafix/preview-latest`
Inspect an incoming AreaFix/FileFix reply for an uplink from message history, parse available areas, and return a diff against current local area state — without writing anything to the database. The Admin → AreaFix / FileFix Manager page always calls this endpoint first and shows the result as a mandatory preview before a sysop can confirm a sync.

By default the newest actionable incoming reply is used (the "Latest Reply" panel's sync button). Passing `message_id` targets one specific incoming message instead — this backs the per-row sync button next to each incoming message in the Message History table, so a sysop can sync from an older reply without needing it to still be the newest one.

**Request body:**
```json
{
    "uplink":     "1:1/23",
    "robot":      "areafix",
    "message_id": 4821
}
```

`message_id` is optional; omit it to preview the newest actionable incoming reply.

**Response:**
```json
{
    "success":     true,
    "areas": [
        { "name": "FIDONEWS", "description": "FidoNet news", "action": "subscribe", "is_subscribed": true, "status": "new",       "currently_active": false, "current_description": null,                        "description_will_change": true,  "description_differs": false },
        { "name": "SYS_GEN",  "description": "SysOp Chat",   "action": "subscribe", "is_subscribed": true, "status": "unchanged", "currently_active": true,  "current_description": "Auto-created area",          "description_will_change": true,  "description_differs": false },
        { "name": "SYS_TST",  "description": "Test Area",    "action": "subscribe", "is_subscribed": true, "status": "unchanged", "currently_active": true,  "current_description": "Our own custom description", "description_will_change": false, "description_differs": true }
    ],
    "areas_count": 3,
    "from":        "AreaFix",
    "date":        "2026-09-23 14:02:11"
}
```

`status` is one of `new`, `reactivate`, `deactivate`, or `unchanged`, describing what applying that area via `/api/admin/areafix/sync` would do to its activation state. Separately, `description_will_change` reports whether the sync would also update the local description if applied without `force_descriptions` — an area's activation status can be `unchanged` while its description is still filled in, because the local description is normally only overwritten when it's currently a placeholder (see `AreaFixManager::isPlaceholderDescription()`); a real, sysop-set description is otherwise never overwritten by a hub's reply. When the local description is a real value and would not be overwritten, but the hub's reply lists a different one anyway (`SYS_TST` above), `description_differs` is true so the admin UI can still point out the mismatch.

In the admin UI, an area whose `status` is `unchanged` but which has either `description_will_change` or `description_differs` set is displayed with an "Updated" badge instead of "Unchanged", since something about it did differ from the hub's reply. The UI renders one checkbox per area, pre-checked for `new`/`reactivate`/`deactivate` and for any area flagged "Updated", and unchecked by default only for a genuine no-op `unchanged` area (no description difference of any kind). The checked subset is submitted to `/api/admin/areafix/sync` with `force_descriptions: true`, which is what actually applies a flagged description mismatch — the sysop can still deselect a specific "Updated" row before confirming if they don't want that particular description overwritten.

### `POST /api/admin/areafix/sync-latest`
Inspect an incoming AreaFix/FileFix reply for an uplink from message history, parse available areas, and sync **all** of them to the local database in one all-or-nothing step, without the `force_descriptions` override or the ability to select a subset. The admin UI's preview modal now applies the sysop's curated selection via `/api/admin/areafix/sync` instead (see above); this endpoint remains available for callers that want to apply an entire reply directly without a preview step.

**Request body:**
```json
{
    "uplink":     "1:1/23",
    "robot":      "areafix",
    "message_id": 4821
}
```

`message_id` is optional; omit it to apply the newest actionable incoming reply.

**Response:**
```json
{
    "success":     true,
    "summary":     { "created": 3, "activated": 1, "deactivated": 0 },
    "areas_count": 4,
    "from":        "AreaFix"
}
```

---


## Parser Architecture & Structural Parsing

AreaFix and FileFix responses are parsed using `BinktermPHP\AreaFix\AreaFixParser` (`src/AreaFix/AreaFixParser.php`).

Rather than relying on fragile keyword blacklists or naive line regexes, the parser recognizes concrete structural grammars generated by major FTN hub software:

1. **Mystic BBS & MBSE Command / Result Blocks**:
   - Parses stacked multi-command requests in a single reply (e.g. `+TAG` followed by `-TAG` and `%LINKED`).
   - Pairs `Command:` lines with their corresponding `Result:` status.
   - Extracts indented area listings under `%LINKED`, `%QUERY`, `%LIST`, and `%UNLINKED` command results.
2. **Delimited Tables (Husky, Clearing Houz, FastEcho, FrontDoor)**:
   - Detects colon (`:`) and pipe (`|`) table headers (`AREA`, `DESCRIPTION`, `STATUS`, `MSGS`, `FILES`).
   - Slices table columns safely to preserve internal colons or special characters in area descriptions (e.g. `FSX: Ads + ANSI Art`).
   - Detects subscription markers (`*`, `+`) in status columns.
3. **Columnar & Dotted-Leader Tables (HPT, Husky)**:
   - Parses fixed-width and dotted-leader rows (`TAG .... status/description`).
   - Differentiates subscription states (`subscribed`, `rescanned` vs `unsubscribed`).
4. **Syntactic Tag Validation & Guard Rails**:
   - Validates area tags using `AreaFixParser::isValidTag()` (2–60 alphanumeric/dash/dot characters with at least one letter).
   - Does not maintain an English word blacklist, ensuring valid echo areas like `LINUX`, `BASE`, or `WINDOWS` are never dropped.
   - Discards ANSI box-drawing/block art characters (`▄█▀▌▐░▒▓─│┌┐└┘`) from descriptions.
   - Rejects non-actionable replies (help manuals, password failure notices, rescan receipts without area lists).

### Area Action Types

Each parsed area contains an `action` attribute:

| Action | Description | `is_subscribed` | DB Sync Behavior |
|---|---|---|---|
| `subscribe` | Confirmation of an added or existing subscription | `true` | Inserts or updates area with `is_active = true` |
| `unsubscribe` | Confirmation of a removed subscription | `false` | Marks existing area with `is_active = false` |
| `available` | Area listed in a `%LIST` or `%UNLINKED` catalog | `false` | Inserts area with `is_active = false`, or updates description |

---

## Backend Classes

### `src/AreaFix/AreaFixParser.php`

| Method | Description |
|---|---|
| `parse(string $body, ?string $subject = null): array` | Parse response text into structured area records with actions |
| `hasActionableContent(string $body, ?string $subject = null): bool` | Check if reply contains actionable subscriptions or area listings |
| `isValidTag(string $tag): bool` | Syntactically validate an FTN area tag |

### `src/AreaFixManager.php`

| Method | Description |
|---|---|
| `sendCommand($uplinkAddress, $commands, $robot, $sysopUserId)` | Send commands via netmail |
| `parseResponseText($body, $commandType)` | Parse hub reply body into area records via `AreaFixParser` |
| `isAreaListResponse($subject, $body)` | Determine if a netmail is an actionable AreaFix reply |
| `isPlaceholderDescription(?string $desc)` | Check if a description is an auto-created placeholder or contains ANSI art |
| `syncSubscribedAreas($uplinkAddress, $domain, $parsedAreas, $deactivateMissing, $robot)` | Sync parsed areas to DB respecting action semantics |
| `deactivateArea($areaTag, $domain)` | Mark a local area as inactive |
| `getHistory($uplinkAddress, $sysopUserId)` | Fetch message history |
| `getConfiguredUplinks()` | List uplinks with passwords configured |

