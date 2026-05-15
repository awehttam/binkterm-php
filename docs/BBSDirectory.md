# BBS Directory

The BBS Directory is a public listing of known Bulletin Board Systems, accessible at `/bbs-directory`. It is automatically populated from multiple sources — echomail announcements, scheduled list imports, and file area automation — and can also be supplemented with sysop-managed or user-submitted entries.

## Table of Contents

- [Enabling the Feature](#enabling-the-feature)
- [Accessing the Directory](#accessing-the-directory)
- [How Entries Are Populated](#how-entries-are-populated)
  - [Echomail Robot: FSXNet iBBS Announcements](#echomail-robot-fsxnet-ibbs-announcements)
  - [Scheduled Import: Telnet BBS Guide](#scheduled-import-telnet-bbs-guide)
  - [File Area Rule: Automatic Import on File Receipt](#file-area-rule-automatic-import-on-file-receipt)
  - [Manual and User-Submitted Entries](#manual-and-user-submitted-entries)
- [Admin Interface](#admin-interface)
- [Protecting Local Entries from Import Overwrites](#protecting-local-entries-from-import-overwrites)
- [Geocoding and the Map View](#geocoding-and-the-map-view)

---

## Enabling the Feature

The BBS Directory is enabled by default. Toggle it under **Admin → BBS Settings → BBS Settings** — look for the BBS Directory feature toggle. When disabled, the `/bbs-directory` page returns a 404 and the **B) BBS Directory** option is hidden from the terminal server menu.

---

## Accessing the Directory

- **Web**: `/bbs-directory` — paginated listing; each entry links to its own detail page at `/bbs-directory/{id}/{slug}`. The numeric ID is the authoritative lookup key; the slug is for readability, and older `/bbs-directory/{id}` URLs remain valid.
- **Terminal server**: Press **B** from the main menu. Shows a paginated list; entering an entry number shows full details (sysop, location, OS, telnet address, website, notes).
- **Map view**: The web listing has a **Map of BBS Systems** tab powered by OpenStreetMap/Leaflet. Entries appear on the map when coordinates are available (see [Geocoding](#geocoding-and-the-map-view)).

The page is publicly accessible without login.

---

## How Entries Are Populated

### Echomail Robot: FSXNet iBBS Announcements

Some BBS systems automatically post ROT47-encoded Inter-BBS (iBBS) announcement messages with the subject `ibbslastcall-data` to the **FSX_DAT** echo area on the FSXNet network. The built-in `ibbslastcall_rot47` echomail robot processor decodes these messages and upserts entries into `bbs_directory` automatically.

To enable this, configure an echomail robot rule in **Admin → Robots**:

| Field | Value |
|---|---|
| Echo Area | `FSX_DAT` |
| Subject Pattern | `ibbslastcall-data` |
| Processor Type | `ibbslastcall_rot47` |

On each matched message, the processor updates the sysop name, location, OS, and telnet address and refreshes `last_seen`. No configuration beyond the robot rule is required.

Full details on robot rules and the processor message format are in **[docs/Robots.md](Robots.md#ibbslastcall_rot47--ibbs-last-call-rot47)**.

---

### Scheduled Import: Telnet BBS Guide

`scripts/dlimport_bbslist.php` downloads the current monthly archive from the [Telnet BBS Guide](https://www.telnetbbsguide.com/bbslist/) (`ibbsMMYY.zip`) and runs `scripts/import_bbslist.php` against it. Archives are cached in `data/bbslist/`.

The recommended setup is a monthly cron job:

```cron
0 4 1 * * cd /path/to/binkterm && /usr/bin/php scripts/dlimport_bbslist.php --quiet
```

Common options:

```bash
# Download and import the current month
php scripts/dlimport_bbslist.php

# Preview without writing to the database
php scripts/dlimport_bbslist.php --dry-run

# Download a specific month/year
php scripts/dlimport_bbslist.php --month=05 --year=2026
```

For the full option reference and import merge behaviour (which fields get overwritten, how `is_local` entries are protected), see **[docs/CLI.md — Import BBS List](CLI.md#import-bbs-list)**.

---

### File Area Rule: Automatic Import on File Receipt

If your system subscribes to a file area that distributes the Telnet BBS Guide archives (the `BBSLISTS` area on Fidonet, for example), you can trigger `import_bbslist.php` automatically the moment a matching ZIP arrives via TIC.

Add a rule to `config/filearea_rules.json` (or edit it in **Admin → File Area Rules**):

```json
"BBSLISTS@fidonet": [
  {
    "name": "Telnet Guide BBS List",
    "enabled": true,
    "pattern": "/^IBBS\\d{4}\\.ZIP$/i",
    "script": "php %basedir%/scripts/import_bbslist.php %filepath%",
    "timeout": 600,
    "success_action": "keep",
    "fail_action": "keep+notify"
  }
]
```

The `%basedir%` and `%filepath%` macros are substituted at runtime. The rule matches filenames like `IBBS0526.ZIP`. On success the file is kept; on failure a sysop notification is sent and the file is kept for manual inspection.

See **[docs/FileAreas.md — File Area Rules](FileAreas.md#file-area-rules)** for the full rule configuration reference.

---

### Manual and User-Submitted Entries

**Sysop-added entries**: Create entries directly in **Admin → BBS Directory**.

**User-submitted entries**: Logged-in users can submit a listing from the `/bbs-directory` page. Submissions enter `pending` status and appear in the admin review queue at **Admin → BBS Directory → Pending**. The sysop approves or rejects each submission.

---

## Admin Interface

**Admin → BBS Directory** provides:

- **Pending queue**: approve or reject user-submitted entries
- **Create / Edit**: add or modify any entry with fields for name, sysop, location, telnet address, SSH address, website, software, OS, and notes (public-facing)
- **Local flag**: mark an entry as local to protect it from import overwrites (see below)
- **Source badge**: each entry shows whether it originated from an auto source (robot, import) or was manually added

---

## Protecting Local Entries from Import Overwrites

Each import run (from `dlimport_bbslist.php`, `import_bbslist.php`, or the file area rule) is a merge, not a replacement. How fields are handled:

| Situation | Outcome |
|---|---|
| Entry marked `is_local` | Skipped entirely by every import |
| Import field is blank | Existing value is kept |
| Import field has a value | Import value overwrites existing value |
| Entry was manually added (`source = manual`) | Source flag preserved; data fields still overwritten |

**To permanently protect an entry from imports**, mark it as local via the admin interface. This is the only reliable protection — the `manual` source flag does not prevent data from being overwritten.

---

## Geocoding and the Map View

BBS Directory entries are geocoded from their `location` field using the [Nominatim](https://nominatim.openstreetmap.org/) API. Coordinates power the **Map of BBS Systems** view. Geocoding is best-effort: if it fails, the entry is saved normally and simply does not appear on the map until coordinates are available.

Results are cached permanently in the `geocode_cache` table — each unique location string is only ever looked up once.

**Environment variables** (all optional, set in `.env`):

| Variable | Default | Description |
|---|---|---|
| `BBS_DIRECTORY_GEOCODING_ENABLED` | `true` | Set to `false` to disable geocoding |
| `BBS_DIRECTORY_GEOCODER_EMAIL` | _(none)_ | Contact email for Nominatim requests (recommended) |
| `BBS_DIRECTORY_GEOCODER_URL` | Nominatim endpoint | Override with a self-hosted instance |
| `BBS_DIRECTORY_GEOCODER_USER_AGENT` | Auto-generated | Custom `User-Agent` header |

**Backfilling existing entries** that have a location but no coordinates:

```bash
php scripts/geocode_bbs_directory.php
php scripts/geocode_bbs_directory.php --limit=50   # process N at a time
php scripts/geocode_bbs_directory.php --dry-run    # preview without writing
```

See **[docs/CLI.md — Geocoding](CLI.md#geocode-bbs-directory)** for the full option reference.
