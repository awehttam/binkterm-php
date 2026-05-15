# Nodelist Browser

The FTN nodelist is a directory of all nodes registered in a FidoNet-style network. Each entry describes a system's FTN address, system name, sysop name, location, and capability flags. BinktermPHP imports nodelists into its database and provides a browser for searching and viewing node details.

## Table of Contents

- [What the Nodelist Contains](#what-the-nodelist-contains)
- [Keeping the Nodelist Current](#keeping-the-nodelist-current)
  - [Recommended: File Area Rules](#recommended-file-area-rules)
  - [Alternative: URL Downloader](#alternative-url-downloader)
  - [Manual Upload](#manual-upload)
- [The Node Browser](#the-node-browser)
  - [Searching](#searching)
  - [Node Detail View](#node-detail-view)
  - [Map View](#map-view)
- [Multiple Networks](#multiple-networks)
- [Nodelist and Crashmail Routing](#nodelist-and-crashmail-routing)

---

## What the Nodelist Contains

Each nodelist entry records:

| Field | Description |
|-------|-------------|
| FTN address | `zone:net/node` or `zone:net/node.point` |
| System name | Name of the BBS or system |
| Sysop name | Name of the system operator |
| Location | City or region |
| Phone | Legacy PSTN number (often `-Unpublished-` on modern nodes) |
| Baud rate | Legacy modem speed (now used as a status field) |
| Keyword type | Node classification: `Hub`, `Host`, `Region`, `Zone`, `Pvt`, `Hold`, `Down`, etc. |
| Flags | Capability flags including internet connection details |

Common capability flags include:

| Flag | Meaning |
|------|---------|
| `IBN` | Internet BinkP Node — accepts BinkP connections; may include hostname and port |
| `INA` | Internet Address — hostname for internet connectivity |
| `ITN` | Internet Telnet Node |
| `CM` | Continuous Mail — accepts connections 24 hours |
| `MO` | Mail Only — no interactive users |

---

## Keeping the Nodelist Current

Nodelists are distributed weekly on FTN networks, typically as compressed files (e.g. `NODELIST.Z23` for day 23 of the year). BinktermPHP supports two automated methods for importing them, plus manual upload.

### Recommended: File Area Rules

The preferred method is to receive nodelists via TIC file distribution into a NODELIST file area and use a file area rule to trigger automatic import. When a matching file arrives, the rule engine calls `scripts/import_nodelist.php` automatically.

Set this up from **Admin → File Areas → File Area Rules**. A typical rule for a FidoNet NODELIST area looks like:

- **Pattern:** `/^NODELIST\.(Z|A|L|R|J)[0-9]{2}$/i`
- **Script:** `php %basedir%/scripts/import_nodelist.php %filepath% %domain% --force`
- **On success:** delete
- **On failure:** keep + notify

The `%domain%` macro passes the network domain (e.g. `fidonet`) to the import script so it knows which network's nodelist it is processing. Multiple networks can be handled with separate rules scoped to their respective file areas.

See [File Areas](FileAreas.md) for full details on configuring file area rules.

### Alternative: URL Downloader

`scripts/update_nodelists.php` downloads nodelist archives directly from configured URL sources and imports them. This suits sysops who are not receiving nodelists via TIC.

Configure sources from **Admin → Nodelists**, or directly in `config/nodelists.json`. Each source specifies a network domain and a URL that may include date macros:

URLs support date macros surrounded by pipe characters: `|DAY|` (day of year, 1–366), `|YEAR|` (4-digit year), `|YY|` (2-digit year), `|MONTH|` (2-digit month), `|DATE|` (2-digit day of month).

For example, a URL ending in `.Z|DAY|` becomes `.Z23` on day 23 of the year.

To run it on a schedule, add a cron entry:

```bash
# Update nodelists weekly (Sunday at 02:00)
0 2 * * 0 /usr/bin/php /path/to/binktest/scripts/update_nodelists.php --quiet
```

See [CLI.md](CLI.md) for full usage and options.

### Manual Upload

Admins can upload a nodelist file directly from **Admin → Nodelist → Import**. Both plain nodelist files and ZIP archives are accepted. Uploading an archive with "Archive old entries" checked will replace the existing nodelist for that domain.

---

## The Node Browser

The nodelist browser is available at `/nodelist` and does not require a login. All users and visitors can search the imported nodelists.

Stats displayed at the top of the browser show the total number of nodes, zones, nets, and point nodes across all imported nodelists, along with the import date of the active nodelist for each network.

### Searching

The search form supports:

- **Free text** — matches against system name, sysop name, and location simultaneously
- **FTN address** — enter a full address (`1:1/1`), a partial address (`1:1`), or a zone:net pair to find all nodes in that net
- **Zone / Net filters** — narrow results to a specific zone or net from the dropdowns
- **Capability flags** — filter by one or more flags (e.g. `IBN`, `CM`) to find nodes with specific capabilities

Results are capped at 500 entries and sorted by zone, net, node, point.

### Node Detail View

Clicking any node opens its detail page at `/nodelist/node/{address}`, showing all fields and the full parsed flag set. If a point address is not in the nodelist, the page falls back to displaying the boss node (`zone:net/node`).

### Map View

Nodes that have been geocoded are shown on an interactive map. Geocoding resolves the `location` field to coordinates using the [Nominatim](https://nominatim.openstreetmap.org/) API; results are cached permanently so each unique location string is only looked up once. Multiple nodes at the same system name are grouped into a single map pin.

The map can be filtered by zone. See [CLI.md](CLI.md#geocoding) for geocoding configuration options.

---

## Multiple Networks

BinktermPHP maintains separate nodelist records per network domain. Each import is tagged with its domain (`fidonet`, `fsxnet`, etc.), and old entries for that domain are replaced when a new nodelist is imported. The browser and API reflect all imported domains simultaneously.

---

## Nodelist and Crashmail Routing

When crashmail delivery is enabled, BinktermPHP queries the nodelist to find connection details for the destination node. It inspects the `IBN`, `INA`, and `ITN` flags in order to resolve a hostname and port. If the node is not in the nodelist, crashmail can fall back to DNS lookup using the zone's configured domain. See [docs/CONFIGURATION.md](CONFIGURATION.md#crashmail-settings) for crashmail routing options.
