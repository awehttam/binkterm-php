# Downlinks

BinktermPHP can act as an FTN **hub** for subordinate systems below it — regular independently-addressed nodes/peers, and FidoNet-style **points** hanging off one of this system's own addresses. This is the reverse of the existing uplink relationship (where BinktermPHP receives mail from, and sends mail up to, a hub above it): here, BinktermPHP *is* the hub, and downlinks are the systems below it.

Downlinks are managed from **Admin → BBS Settings → BinkP Downlinks**.

## Nodes vs. points

A downlink is one of two types:

- **Node** — an independently-addressed system (e.g. `2:345/67`), entered as a free-text FTN address. This covers both traditional hub→downlink relationships and symmetric peer links.
- **Point** — a system addressed as one of *this BBS's own* AKAs plus a point number (e.g. `1:153/149.1`, where `1:153/149` is an address this BBS already holds). When adding a point, the **Boss Address** is picked from the list of AKAs configured in **Admin → Networks** rather than typed freely, and a next-available **Point Number** is suggested automatically.

Both types share the same underlying distribution, queueing, and authentication mechanics — the only structural difference is addressing.

A point's address only exists on the network of the boss AKA it's registered under — a point at `1:153/149.1` is a valid address on network `1:153/149`'s network only. It cannot be used to reach a different FTN network this BBS also participates in; that would require a separate point registered under an AKA on *that* network. Netmail a point sends onward (see [Netmail routing](#netmail-routing)) is still only deliverable to destinations this BBS has an uplink configured for, exactly as with any other outbound netmail — a point doesn't gain reachability to networks this BBS itself can't route to.

## Adding a downlink

From **Admin → BBS Settings → BinkP Downlinks**, choose **Add Downlink** and select **Node** or **Point**:

| Field | Applies to | Notes |
|---|---|---|
| Address | Node | Free-text FTN address |
| Boss Address | Point | Picked from configured AKAs |
| Point Number | Point | Suggested automatically, editable |
| Name / Sysop Name | Both | Informational |
| Session Password | Both | Authenticates this downlink when *it* connects to us (binkp session password) |
| Packet Password | Both | `.pkt`-level password |
| AreaFix Password / FileFix Password | Both | Password this downlink must send to self-manage its own echo/file area subscriptions via netmail (see [AreaFix / FileFix](#areafix--filefix)). Independent of the session password; leaving one blank disables that robot for this downlink |
| Internet Host / Port | Node | Override host/port for push delivery; falls back to the nodelist if unset. Points typically have no routable host and are pull-only |
| Enabled | Both | Disabling stops all delivery to/from this downlink without deleting it |
| Hold Mail | Both | Pauses delivery; queued mail accumulates until released |
| Accept Echomail From This Subordinate | Both | Whether echomail posted back by this downlink is accepted |
| Accept Netmail From This Subordinate | Both | Whether netmail from this downlink is accepted for relay/routing |
| Max Packet Size (KB) | Both | `0` = unlimited |
| Compress Outbound | Both | Packs bundled outbound echomail/netmail into a ZIP arcmail bundle instead of a raw packet. Only enable this if the downlink is another BinktermPHP instance or a mailer that auto-detects bundle extensions |
| Queue Retention (Days) | Both | How long sent/failed queue entries are kept before being purged (see [Queue cleanup](#queue-cleanup)) |
| Notes | Both | Free text |

## Authentication

Downlinks authenticate with their **Session Password** when connecting to this BBS's binkp server. Both **CRAM-MD5** and **plaintext** authentication are accepted for registered downlinks, regardless of the global plaintext-fallback setting — this keeps simpler point software (which may not support CRAM-MD5) working without weakening authentication requirements for uplink connections.

## Area subscriptions

Each downlink has its own echomail area subscription list, managed from its row in **Admin → BBS Settings → BinkP Downlinks**. Subscriptions can be bulk-toggled, and individual areas can be paused without removing the subscription.

Each downlink also has a separate **file area** subscription list, managed the same way from its own row via a second checklist. See [File area (TIC) distribution](#file-area-tic-distribution).

## Echomail distribution

When a new echomail message is stored — whether it arrived from the network, was posted locally, or was posted back by a registered point/node — it's distributed in every direction:

- **Down**, to every enabled, non-held downlink subscribed to that area.
- **Up**, to the echoarea's configured uplink, unless the message was received directly from that uplink or the uplink already appears in the message's SEEN-BY (both checks prevent sending mail straight back where it came from).

Node-type downlinks get standard SEEN-BY/PATH loop-prevention bookkeeping applied (their own address is added to SEEN-BY, and this BBS's address is appended to PATH). Point-type downlinks never appear in SEEN-BY or PATH — per FTS convention, point numbers have no place in either kludge — so a point's delivery is governed purely by its subscription state, not by SEEN-BY matching.

Mail posted by a point is tossed the same way as a locally-composed post: it carries this BBS's own SEEN-BY/PATH entry, not the point's, and fans out normally to every other subscribed downlink.

## File area (TIC) distribution

Files are announced to downlinks the same way they're announced to uplinks — via **TIC** (File Information Control, FSC-0087) control files paired with the data file itself — using the same subscription/queue/delivery model as echomail, extended to file areas:

- **Down**, to every enabled, non-held downlink subscribed to the file's area, whenever a file is uploaded or received via an inbound TIC. A downlink already recorded in the file's TIC `Seenby` trail is skipped (the same loop-prevention principle as echomail SEEN-BY).
- **Up**, to the file area's configured uplink(s), unless the file was received directly from that uplink or the uplink already appears in the `Seenby` trail.

Local-only and private file areas are never distributed to downlinks, matching the existing uplink behavior. Both node- and point-type downlinks can be subscribed to a file area, though in practice this is mainly useful for node-type downlinks — most point software has no file-area engine of its own.

## AreaFix / FileFix

A downlink can self-manage its own echo and file area subscriptions by sending netmail to two robot addresses at one of this BBS's AKAs: **AreaFix** (echo areas) and **FileFix** (file areas) — the server-side counterpart to the AreaFix/FileFix support this BBS already uses when *sending* commands to its own uplinks.

**Authentication**: the netmail's Subject line must match the downlink's configured **AreaFix Password** or **FileFix Password**. A downlink with no password configured for a robot cannot use it — the message is dropped without a reply so unconfigured robots can't be probed. A registered downlink with the wrong password gets a "Password incorrect" reply; netmail from an unregistered address is silently dropped.

**Commands** (one per line in the message body):

| Command | Effect |
|---|---|
| `+TAG` | Subscribe to area `TAG` |
| `-TAG` | Unsubscribe from area `TAG` |
| `%LIST` | Reply with all areas available to subscribe to |
| `%QUERY` | Reply with this downlink's current subscriptions |
| `%PAUSE` | Pause all areas (sets **Hold Mail**) |
| `%RESUME` | Resume all areas (clears **Hold Mail**) |
| `%RESCAN [AREATAG] [days]` | Re-queue echomail history (AreaFix only, see below) |
| `%HELP` | Reply with the command reference |

Only active, non-local areas are self-subscribable — sysop-only echoareas and private/local file areas never appear in `%LIST` and can't be subscribed to via `+TAG`, matching what the admin area-subscription checklists already show. A reply netmail is queued back to the downlink for every command batch, listing the result of each command.

**`%RESCAN`** re-sends past echomail the downlink is entitled to, e.g. after it lost message history locally. With no arguments it re-queues every area the downlink is currently subscribed to, going back 182 days (~6 months) by default (maximum 3650). Add a number to change the day count (`%RESCAN 30`), an area tag to scope it to just that one area (`%RESCAN GENERAL`, must already be subscribed), or both in either order (`%RESCAN GENERAL 30` or `%RESCAN 30 GENERAL`). AreaFix only — FileFix has no per-message history to replay.

## Netmail routing

Netmail addressed to a registered downlink's address is delivered into its queue instead of being handled as ordinary local netmail. This covers three directions:

- **Inbound, addressed to a downlink** — netmail arriving from the network addressed to one of this BBS's registered downlinks is forwarded on, gated by that downlink's **Accept Netmail From This Subordinate** setting.
- **Outbound, composed locally** — netmail a user on this BBS composes to a registered downlink's address is delivered directly to that downlink's queue instead of being routed toward an unrelated uplink.
- **Relayed, from a point** — netmail sent *by* a registered point to an address that is neither this BBS nor another registered downlink is relayed onward through the normal outbound routing, rather than being misdelivered locally or silently dropped. This still requires this BBS to have an uplink configured for the destination's network — a point can't relay to a network this BBS has no uplink for, regardless of which network the point's own boss AKA belongs to.

There is no open relay to arbitrary third-party addresses — only traffic to or from a registered downlink is handled this way.

## Delivery

Downlinks are served through the existing binkp server/client, using the same session mechanics as uplinks:

- **Pull** — the downlink connects to this BBS's binkp server and authenticates; any pending queued packets are delivered during that session.
- **Push** — this BBS connects out to the downlink (for node-type downlinks with a routable **Internet Host**, or a nodelist-resolvable address) and delivers pending packets. Push delivery runs automatically on a schedule alongside uplink polling; it can also be triggered manually with `scripts/binkp_poll.php --all-hub-nodes` (see `docs/CLI.md`).

A queued file area entry is sent as a TIC pair — the data file first, then its `.tic` control file — over the same session, rather than as a single packet.

Points typically have no independently routable host and are effectively pull-only.

## Monitoring the queue

**Binkp Status → Downlink Queue** (`/binkp`) lists every queued packet across all downlinks — destination, type (echomail/netmail/tic), status, size, attempt count, and timestamps — with an **Inspect** action to view a queued packet's header and message contents (or, for a `tic` entry, its TIC control fields and referenced filename) before it's delivered.

## Queue cleanup

Delivered (`sent`) and permanently failed (`failed`) queue entries are not deleted immediately — they're kept for each downlink's configured **Queue Retention (Days)** (default 30) before being purged. This purge runs as part of `scripts/database_maintenance.php`. Entries still `pending` or `held` are never purged regardless of age; they remain queued until delivered, held mail is released, or the downlink is deleted.

## Limitations

- **File-attach netmail routed through a downlink** currently forwards only the `.pkt` header, not the referenced attached file.
- There is no self-service "request a point" flow; points are registered by the sysop.
