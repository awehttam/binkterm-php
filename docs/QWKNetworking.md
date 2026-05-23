# QWK Networking

QWK networking in BinktermPHP is the inter-BBS transport mode where this
system exchanges packets with another BBS mailbox. This is separate from a
user's own offline-reader workflow on the same BBS.

For the user-facing offline-reader download/upload workflow on this BBS, see
[QWK Offline Mail](QWK.md).

## Table of Contents

- [Overview](#overview)
- [Configuration](#configuration)
- [Polling and Packet Flow](#polling-and-packet-flow)
- [Behavior](#behavior)
- [Local-Only Areas](#local-only-areas)
- [Related Docs](#related-docs)

---

## Overview

BinktermPHP can act as a QWK client for another BBS. In this mode the local
system polls a remote QWK mailbox, downloads that system's `.QWK` packet,
imports mapped conferences into local echo areas, exports queued local posts as
a `.REP`, and uploads the reply packet back to the remote host.

Important distinction:

- A user's own offline QWK reader packet on this BBS is a local access method, not external network propagation.
- Inter-BBS QWK mailbox exchange is an external transport.

---

## Configuration

This is configured from the admin web interface:

1. Open **Admin → Echo Areas**.
2. Use **QWK Mailboxes** to define the remote BBS ID, FTP host, credentials,
   remote path, and poll schedule.
3. Edit a local echo area and add one or more **QWK Subscriptions** mapping the
   local area to remote conference numbers on that mailbox.
4. Optionally add **Gates** to mirror imported or local traffic into other
   local areas.

---

## Polling and Packet Flow

The transport cycle is driven by:

- `php scripts/qwk_poll.php --all`
- `php scripts/qwk_poll.php --mailbox=<id>`

For each enabled mailbox, BinktermPHP:

1. Downloads the remote `.QWK` packet, if one is available.
2. Imports mapped conferences into local echo areas.
3. Builds a `.REP` packet containing queued outbound messages for that mailbox.
4. Uploads the `.REP` packet back to the remote host.

---

## Behavior

- Inbound deduplication uses `(qwk_mailbox_id, qwk_conference_number, qwk_msg_number)`.
- Unknown conferences are auto-created into the built-in `qwk` network using the remote conference name as the description. The sysop can later move the area into a different network domain if needed.
- Outbound replies preserve QWK reply threading when the parent message came from the same mailbox and conference.
- Gated local copies use `source_msgid` to prevent loops and duplicate mirrors.

---

## Local-Only Areas

Areas marked `is_local = true` may still appear in the logged-in user's own QWK
download from this BBS and may accept that same user's REP uploads back into
the same local area.

Areas marked `is_local = true` must not be redistributed through inter-BBS QWK
mailbox fanout, even if QWK mailbox mappings exist elsewhere in the system.

In other words:

- Local offline-reader access is allowed for `is_local` areas.
- External QWK mailbox transport is not allowed for `is_local` areas.

---

## Related Docs

- [QWK Offline Mail](QWK.md) — User-facing download/upload workflow on this BBS
- [Echo Areas](EchoAreas.md) — Echo area delivery rules and `is_local` behavior
- [API Reference](API.md) — QWK mailbox and QWK offline-mail endpoints
