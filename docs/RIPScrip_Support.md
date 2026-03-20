# RIPScrip Support

BinktermPHP renders RIPscrip (Remote Imaging Protocol) graphics embedded in echomail messages and uploaded as `.rip` files in file areas.

## Overview

RIPscrip is a vector graphics protocol developed by TeleGrafix Communications in the early 1990s. It was widely used on BBSes to display menus, splash screens, and graphical content over slow serial connections. BinktermPHP automatically detects and renders RIP graphics when viewing echomail messages and when previewing `.rip` files.

RIPscrip rendering is available in **echomail and file areas** — netmail messages do not trigger RIP rendering.

## Where RIPscrip Renders

### In Messages (Echomail only)

When the message reader loads an echomail message, the server-side `MessageHandler` scans the message body for RIPscrip signature lines (lines beginning with `!|`). If detected, the raw RIP script is passed to the client as `rip_script` data alongside the normal message text.

Netmail messages do not trigger RIP rendering.

### In File Areas

Files with a `.rip` extension are automatically previewed as RIPscrip graphics in the file browser. The preview is rendered to a canvas element in the file detail panel using the same RIPterm.js library.

## How It Works

### Client-Side Rendering

The browser uses the [RIPterm.js](https://github.com/dj-256/RIPterm.js) library (loaded from `/vendor/riptermjs/`) to render the RIP script on an HTML5 `<canvas>` element. The library provides faithful RIPscrip rendering including:

- Line, rectangle, and polygon drawing
- Filled and outline shapes (circles, ellipses, polygons)
- Text placement
- The 16-color RIPscrip palette

The JS renderer is loaded lazily — `BGI.js` and `ripterm.js` are only fetched when a RIP graphic is actually being displayed.

### Server-Side Fallback

A server-side PHP renderer (`src/RipScriptRenderer.php`) also exists and renders RIPscrip to inline SVG. This handles a subset of RIPscrip commands:

- Lines (`L`), rectangles (`R`/`B`), circles (`C`/`G`)
- Ellipses (`O`/`E`/`o`/`V`), ellipse arcs
- Polygons (`P`/`F`/`p`)
- Text placement (`@`)
- Color selection (`c`)

Unsupported commands are silently ignored so partial output is always shown.

## Supported RIPscrip Commands

| Command | Description |
|---------|-------------|
| `cNN` | Set current color (0–15) |
| `Lxxxx` | Draw line between two points |
| `Rxxxx` | Draw outline rectangle |
| `Bxxxx` | Draw filled rectangle |
| `Cxxxxxx` | Draw outline circle |
| `Gxxxxxx` | Draw filled circle |
| `Oxxxxxxxx` | Draw outline ellipse or ellipse arc |
| `Exxxxxxxx` | Draw filled ellipse (bounding box) |
| `oxxxxxxxx` | Draw filled ellipse (center + radii) |
| `Vxxxxxxxx` | Draw outline ellipse (alternate form) |
| `P...` | Draw polygon outline |
| `F...` / `p...` | Draw filled polygon |
| `@xxxxText` | Place text at coordinate |

Coordinates are encoded in base-36 (two characters per axis value).

## Coordinate System

The default RIPscrip canvas is 640×350 pixels, matching the EGA screen resolution used by most RIP-capable BBS terminals of the era. The base-36 coordinate encoding maps to this space.

## Notes

- RIPscrip rendering only activates for echomail messages, not netmail.
- Messages containing RIPscrip will show the graphical rendering in place of the raw message text.
- The RIPterm.js vendor library must be present in `public_html/vendor/riptermjs/` for client-side rendering to work.
