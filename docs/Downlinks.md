# Downlinks

BinktermPHP can act as an FTN **hub** for subordinate systems below it — regular independently-addressed nodes/peers, and FidoNet-style **points** hanging off one of this system's own addresses. This is the reverse of the existing uplink relationship (where BinktermPHP receives mail from, and sends mail up to, a hub above it): here, BinktermPHP *is* the hub, and downlinks are the systems below it.

Downlinks are managed from **Admin → Downlinks**.

## Nodes vs. points

A downlink is one of two types:

- **Node** — an independently-addressed system (e.g. `2:345/67`), entered as a free-text FTN address. This covers both traditional hub→downlink relationships and symmetric peer links.
- **Point** — a system addressed as one of *this BBS's own* AKAs plus a point number (e.g. `1:153/149.1`, where `1:153/149` is an address this BBS already holds). When adding a point, the **Boss Address** is picked from the list of AKAs configured in **Admin → Networks** rather than typed freely, and a next-available **Point Number** is suggested automatically.

Both types share the same underlying distribution, queueing, and authentication mechanics — the only structural difference is addressing.

## Adding a downlink

From **Admin → Downlinks**, choose **Add Downlink** and select **Node** or **Point**:

| Field | Applies to | Notes |
|---|---|---|
| Address | Node | Free-text FTN address |
| Boss Address | Point | Picked from configured AKAs |
| Point Number | Point | Suggested automatically, editable |
| Name / Sysop Name | Both | Informational |
| Session Password | Both | Authenticates this downlink when *it* connects to us (binkp session password) |
| Packet Password | Both | `.pkt`-level password |
| Internet Host / Port | Node | Override host/port for push delivery; falls back to the nodelist if unset. Points typically have no routable host and are pull-only |
| Enabled | Both | Disabling stops all delivery to/from this downlink without deleting it |
| Hold Mail | Both | Pauses delivery; queued mail accumulates until released |
| Accept Echomail From This Subordinate | Both | Whether echomail posted back by this downlink is accepted |
| Accept Netmail From This Subordinate | Both | Whether netmail from this downlink is accepted for relay/routing |
| Max Packet Size (KB) | Both | `0` = unlimited |
| Queue Retention (Days) | Both | How long sent/failed queue entries are kept before being purged (see [Queue cleanup](#queue-cleanup)) |
| Notes | Both | Free text |

## Authentication

Downlinks authenticate with their **Session Password** when connecting to this BBS's binkp server. Both **CRAM-MD5** and **plaintext** authentication are accepted for registered downlinks, regardless of the global plaintext-fallback setting — this keeps simpler point software (which may not support CRAM-MD5) working without weakening authentication requirements for uplink connections.

## Area subscriptions

Each downlink has its own echomail area subscription list, managed from its row in **Admin → Downlinks**. Subscriptions can be bulk-toggled, and individual areas can be paused without removing the subscription.

## Echomail distribution

When a new echomail message is stored — whether it arrived from the network, was posted locally, or was posted back by a registered point/node — it's distributed in every direction:

- **Down**, to every enabled, non-held downlink subscribed to that area.
- **Up**, to the echoarea's configured uplink, unless the message was received directly from that uplink or the uplink already appears in the message's SEEN-BY (both checks prevent sending mail straight back where it came from).

Node-type downlinks get standard SEEN-BY/PATH loop-prevention bookkeeping applied (their own address is added to SEEN-BY, and this BBS's address is appended to PATH). Point-type downlinks never appear in SEEN-BY or PATH — per FTS convention, point numbers have no place in either kludge — so a point's delivery is governed purely by its subscription state, not by SEEN-BY matching.

Mail posted by a point is tossed the same way as a locally-composed post: it carries this BBS's own SEEN-BY/PATH entry, not the point's, and fans out normally to every other subscribed downlink.

## Netmail routing

Netmail addressed to a registered downlink's address is delivered into its queue instead of being handled as ordinary local netmail. This covers three directions:

- **Inbound, addressed to a downlink** — netmail arriving from the network addressed to one of this BBS's registered downlinks is forwarded on, gated by that downlink's **Accept Netmail From This Subordinate** setting.
- **Outbound, composed locally** — netmail a user on this BBS composes to a registered downlink's address is delivered directly to that downlink's queue instead of being routed toward an unrelated uplink.
- **Relayed, from a point** — netmail sent *by* a registered point to an address that is neither this BBS nor another registered downlink is relayed onward through the normal outbound routing, rather than being misdelivered locally or silently dropped.

There is no open relay to arbitrary third-party addresses — only traffic to or from a registered downlink is handled this way.

## Delivery

Downlinks are served through the existing binkp server/client, using the same session mechanics as uplinks:

- **Pull** — the downlink connects to this BBS's binkp server and authenticates; any pending queued packets are delivered during that session.
- **Push** — this BBS connects out to the downlink (for node-type downlinks with a routable **Internet Host**, or a nodelist-resolvable address) and delivers pending packets. Push delivery runs automatically on a schedule alongside uplink polling; it can also be triggered manually with `scripts/binkp_poll.php --all-hub-nodes` (see `docs/CLI.md`).

Points typically have no independently routable host and are effectively pull-only.

## Monitoring the queue

**Binkp Status → Downlink Queue** (`/binkp`) lists every queued packet across all downlinks — destination, type (echomail/netmail), status, size, attempt count, and timestamps — with an **Inspect** action to view a queued packet's header and message contents before it's delivered.

## Queue cleanup

Delivered (`sent`) and permanently failed (`failed`) queue entries are not deleted immediately — they're kept for each downlink's configured **Queue Retention (Days)** (default 30) before being purged. This purge runs as part of `scripts/database_maintenance.php`. Entries still `pending` or `held` are never purged regardless of age; they remain queued until delivered, held mail is released, or the downlink is deleted.

## Limitations

- **File-attach netmail routed through a downlink** currently forwards only the `.pkt` header, not the referenced attached file.
- **Areafix** (allowing a downlink to self-manage its own area subscriptions via netmail commands) is not yet implemented — subscriptions are currently sysop-managed only, through **Admin → Downlinks**.
- There is no self-service "request a point" flow; points are registered by the sysop.
