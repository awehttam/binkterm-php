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
Sync a parsed area list into the local echo/file area table.

**Request body:**
```json
{
    "uplink":             "1:1/23",
    "robot":              "areafix",
    "areas":              [{"name": "FIDONEWS", "description": "FidoNet news"}],
    "deactivate_missing": false
}
```

**Response:**
```json
{
    "success": true,
    "summary": { "created": 3, "activated": 1, "deactivated": 0 }
}
```

---

## Backend Class

`src/AreaFixManager.php` — key public methods:

| Method | Description |
|---|---|
| `sendCommand($uplinkAddress, $commands, $robot, $sysopUserId)` | Send commands via netmail |
| `parseResponseText($body, $commandType)` | Parse hub reply body into area records |
| `syncSubscribedAreas($uplinkAddress, $domain, $parsedAreas, $deactivateMissing, $robot)` | Sync parsed areas to DB |
| `deactivateArea($areaTag, $domain)` | Mark a local area as inactive |
| `getHistory($uplinkAddress, $sysopUserId)` | Fetch message history |
| `getConfiguredUplinks()` | List uplinks with passwords configured |
