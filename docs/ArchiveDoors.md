# Internet Archive Doors

> **See also:** [Doors.md](Doors.md) for an overview of all door types.

## Table of Contents

- [How It Works](#how-it-works)
- [Why Archive Doors Are Database-Backed](#why-archive-doors-are-database-backed)
- [Adding a Game](#adding-a-game)
- [Door Fields](#door-fields)
- [Icons and Screenshots](#icons-and-screenshots)
- [Limitations](#limitations)
- [Troubleshooting](#troubleshooting)
- [Developer Notes](#developer-notes)

---

Internet Archive doors are games hosted on [archive.org](https://archive.org) that the Internet Archive emulates in the browser (its EM-DOSBox player). BinktermPHP stores the game's description and artwork and shows the game on the `/games` page. When a user launches it, the game page embeds the Internet Archive player in an iframe. Nothing is installed or run on your server, and no multiplexing bridge is needed.

## How It Works

1. A sysop adds a game under **Admin -> Doors -> Archive.org Games** (`/admin/archive-doors`).
2. Enabled games appear on `/games` with an **ARCHIVE** badge.
3. When a logged-in user opens a game, `/games/{door_id}` renders a page that embeds `https://archive.org/embed/<item id>`. The frame is a fixed 640x480 canvas that scales to fit the window, and the page has a fullscreen button.

Archive doors are available from the web interface only. They are not listed in the telnet/SSH door menu.

## Why Archive Doors Are Database-Backed

Like [RLogin Doors](RLoginDoors.md), an archive door has no executable or game files on disk. It is a set of metadata plus a pointer to an Internet Archive item, so it is stored in the `archive_doors` table rather than as a manifest file. There is no Door Manifest Editor entry for these doors and no Admin Daemon round-trip is needed to save them. Icons and screenshots are stored in the table as binary data.

## Adding a Game

1. Open **Admin -> Doors -> Archive.org Games**.
2. Find the game on archive.org, such as in the [MS-DOS games collection](https://archive.org/details/softwarelibrary_msdos_games), and copy its address (for example `https://archive.org/details/msdos_Oregon_Trail_The_1990`).
3. Paste it into **Quick Import from Archive.org** and click the fetch button. This fills in the item ID, a suggested Door Identifier, title, author, year, description, and cover art where the Internet Archive provides them. Your BBS server must be able to reach `archive.org` for this to work.
4. Review the fields, adjust anything you like, and save.

The item must be one the Internet Archive can run in its in-browser emulator. Open the item's page on archive.org first and confirm that it plays there.

## Door Fields

| Field | Description |
|-------|-------------|
| Door Identifier | Unique key used in the `/games/{door_id}` URL. Letters, numbers, hyphens and underscores only. Cannot be changed after creation. |
| Archive Item ID / URL | The Internet Archive item. A full `archive.org/details/...` or `archive.org/embed/...` URL is reduced to the item ID. |
| Game Title | Name shown on the games page. |
| Short Name | Shorter name for compact displays. Defaults to the title. |
| Developer / Creator | Author shown on the game card. Defaults to `Unknown`. |
| Version | Optional game version. |
| Release Year | Optional. |
| Categories / Tags | Genres used to group the game on `/games`. Defaults to **Internet Archive Games** when fetched from the Internet Archive. |
| Players | Text such as `1 Player`. |
| Description | Shown on the game card. |
| Enabled | Disabled games are hidden and cannot be opened. |
| Hide from Web | Keeps the game out of the `/games` listing. |
| Credit Cost, Max Time, Allow Anonymous | Stored with the game. See [Limitations](#limitations). |

## Icons and Screenshots

Upload an icon and optional screenshot with the form, or let the fetch button pull the cover art from the Internet Archive. Images are served from `/door-assets/{door_id}/icon` and `/door-assets/{door_id}/screenshot` with caching headers. Games without an icon use the default door icon.

## Limitations

- The game is run and hosted by the Internet Archive. If archive.org is slow, down, or the item is removed or restricted, the game will not load.
- Players' browsers must be able to reach `archive.org`.
- Games are web only, with no telnet/SSH launch.
- The Credit Cost, Max Time and Allow Anonymous settings are saved with the game, but launching a game currently does not charge credits, end the session after the time limit, or change who can open it. Players must be logged in to reach `/games/{door_id}`.
- The Internet Archive player manages its own saves and controls. BinktermPHP does not sync save files or record scores for these games.

## Troubleshooting

**Fetch says the item was not found.** Check that the URL points at an item on archive.org, and that the BBS server can make outbound HTTPS requests.

**The game page is blank or shows an Internet Archive error.** Open `https://archive.org/embed/<item id>` directly. If it fails there, the item cannot be embedded, so choose a different item.

**Icon is missing after fetching.** Not every item has a thumbnail file. Upload one manually.

## Developer Notes

- Logic lives in `src/ArchiveDoorManager.php`.
- Admin page: `templates/admin/archivedoors_config.twig`; admin API under `/admin/api/archive-doors` in `routes/admin-routes.php`.
- The `/games` listing and play page are in `routes/webdoor-routes.php` (door type `archivedoor`, rendered with `templates/webdoor_play.twig`). Asset serving is in `routes/door-routes.php`.
- Schema: the `archive_doors` table, created by `database/migrations/v20261025210126_add_archive_doors_table.sql`.
