# Netmail

Netmail is private, point-to-point FTN messaging: a message addressed to one person at one
node address, as opposed to echomail, which is broadcast into a shared area. This document
describes how BinktermPHP stores netmail, how it decides which mailbox a message belongs to,
and how messages are sent and received.

It is a subsystem reference. For the user-facing "what is netmail" framing see
[FTNGuide.md](FTNGuide.md); for the conceptual table overview see [DATA_MODEL.md](DATA_MODEL.md).

> **Authoritative caveat:** where this document and the code disagree, the code wins.
> The core logic lives in `src/MessageHandler.php` (compose, send, list, delete, threading)
> and `src/BinkdProcessor.php` (inbound tossing, recipient resolution).

---

## The `netmail` table

One row per message. A single row is shared by both parties to a conversation — there is no
separate "sender copy" and "recipient copy". Notable columns:

| Column | Meaning |
|---|---|
| `id` | Primary key. Also the natural ordering key for a conversation. |
| `user_id` | **Overloaded.** See [Ownership and visibility](#ownership-and-visibility) below — this is *not* reliably "the mailbox owner". |
| `from_address` / `to_address` | Real routable FTN addresses. Both matter — unlike echomail, where `to_name` is decorative. |
| `from_name` / `to_name` | Display names. `to_name` participates in ownership matching. |
| `subject` | Free text. For a file-attach netmail the subject *is* the filename (FTN convention). |
| `message_text` | Body, stored UTF-8, kludges stripped out. |
| `raw_message_bytes` | Original wire bytes when preserved (for art/rendering fidelity). |
| `message_charset` | Canonical iconv charset name (`CP437`, `UTF-8`, …) as normalized by `BinkpConfig::normalizeCharset()`. Use this for encode/decode, not the raw `CHRS`. |
| `date_written` | Derived from the FTN packet header (sender's local clock → UTC). Can be wrong or future-dated. |
| `date_received` | Server-side `NOW() AT TIME ZONE 'UTC'`. Always reliable. Prefer it for ordering/display. |
| `attributes` | FTN attribute bitfield. `0x0001` PRIVATE (always set on outbound), `0x0800` FILE_REQUEST (FREQ), FILE_ATTACH etc. |
| `is_sent` | `TRUE` once the row has been spooled to the outbound queue. Stays `FALSE` forever for locally-delivered mail (nothing to spool) — so it is **not** a reliable "this is a sent message" flag. |
| `is_read` | Legacy per-row flag. Real read state is per-user in `message_read_status` (`message_type = 'netmail'`). |
| `reply_to_id` | Self-referential FK for threading. |
| `message_id` | FTN MSGID for this message (`v1.4.3_add_netmail_message_id.sql`). Older rows may be null. |
| `reply_address` | Preferred reply destination when it differs from `from_address` (`v1.5.1`). |
| `original_author_address` | Set when the message was forwarded/redirected (`v1.4.7`). |
| `kludge_lines` / `bottom_kludges` | Raw kludge storage: top control lines and `SEEN-BY`/tear/origin-style trailing lines. Original wire `CHRS` lives here. |
| `deleted_by_sender` / `deleted_by_recipient` | Soft-delete flags, one per side (`v1.9.2.3`). |
| `spooled_at` | Timestamp the row was handed to the outbound queue. Non-null blocks re-spooling. |
| `is_freq` / `freq_status` | Marks the message as an outbound file request (`v1.11.0.6`, `v1.11.0.21`). |
| `outbound_attachment_path` | Filesystem path of a file to attach on remote delivery (`v1.10.12`). |
| `received_insecure` | `TRUE` if tossed from an unsecured (password-less) BinkP session (`v1.9.3.5`). |

Attachments themselves are rows in the `files` table (`message_type = 'netmail'`), stored in
the recipient's (and/or sender's) private file area for local delivery, or referenced by
`outbound_attachment_path` for crashmail.

---

## Ownership and visibility

**This is the part that trips people up.** `netmail.user_id` does not mean "the user whose
mailbox this row is in". It is set differently by each insert path:

| Insert path | What `user_id` gets set to |
|---|---|
| Inbound remote netmail (`BinkdProcessor::storeNetmail()`) | `findTargetUser(destAddr, toName)` — the resolved **recipient**, or `NULL` when the recipient cannot be resolved. |
| Local user-to-user or user-to-sysop mail (`MessageHandler::sendNetmail()` / `sendLocalSysopMessage()`) | The **sender**. `is_sent` stays `FALSE`. |
| Outbound mail to a remote node (`sendNetmail()` → spooled) | The **sender**. |

So `user_id` can be the recipient, the sender, or null, depending on how the row was
created. Any query that scopes netmail with a bare `WHERE user_id = :uid` is wrong: it will
leak nothing, but it will *hide* legitimately-owned mail (inbound mail with a null
`user_id`, or local mail received from another user where `user_id` is the sender).

### The real visibility rule

A user may see a netmail row if **either** side identifies them:

```sql
n.user_id = :uid
OR (
  (LOWER(n.to_name) = LOWER(:username) OR LOWER(n.to_name) = LOWER(:real_name))
  AND n.to_address IN (<this user's FTN addresses + the system address>)
)
```

The "sent" view is the mirror image, keyed on `from_name` + `from_address` against the
user's identity and the system's addresses. Soft-delete is applied per side: a row is hidden
from the sender when `deleted_by_sender` is set (matched via `user_id`), and from the
recipient when `deleted_by_recipient` is set (matched via the `to_name` + `to_address` test).

This predicate is currently implemented (and duplicated) in `MessageHandler::getNetmail()`,
`getMessage()`, and — transitively — `getNetmailConversation()`. `deleteNetmail()` uses a
simplified form of the same test to decide which soft-delete flag to set. New code that
needs to query netmail for a user should go through `MessageHandler` rather than
re-deriving the predicate.

### Planned change

`docs/proposals/NetmailOwnershipChangesMay9.md` proposes replacing the name-matching
inference with explicit `local_sender_id` / `local_recipient_id` columns. If that lands, the
visibility rule moves to those columns and the name+address test is retired. Until then,
the compound predicate above is the source of truth.

---

## Receiving (inbound)

Inbound netmail is tossed by `BinkdProcessor` from packets in the inbound directory. For
each netmail message in a packet, `storeNetmail()`:

1. **Intercepts non-mail traffic first**, in order: AreaFix/FileFix robot mail addressed to
   one of our AKAs; transit netmail addressed to a registered hub node/point (relayed, not
   delivered); inbound FREQ requests (attribute `0x0800` — logged and discarded).
2. **Resolves the recipient** with `findTargetUser($destAddr, $toName)`:
   - exact `users.fidonet_address` match on the destination address;
   - point address → match on the host (drop the `.point` suffix);
   - **only if the destination is one of our own AKAs**, fall back to name matching:
     the literal name `sysop` → the configured system sysop user; then a case-insensitive
     match of `toName` against `users.real_name` or `users.username`.
3. If no user matches and the packet came from one of our own registered hub nodes/points,
   the message is relayed onward rather than treated as misaddressed.
4. Otherwise the message is **dropped as undeliverable** and logged. The old
   "catch-all to the sysop inbox" behaviour was removed because misrouted echomail (no
   `AREA:` line, unrecognised `toName`) was silently landing in the sysop's mailbox.
   The caller preserves the original packet file for sysop review.
5. On a match, the row is inserted with `user_id` = the resolved recipient, kludges split
   out into `kludge_lines` / `bottom_kludges`, `message_charset` normalized, `date_written`
   parsed from the packet header via the TZUTC offset, and `date_received` set server-side.

Once the row exists it appears in the recipient's inbox automatically — there is no
separate "deliver to mailbox" step, because the mailbox is a query, not a folder.

---

## Sending (outbound)

All sends go through `MessageHandler::sendNetmail(...)`. High-level flow:

1. **Credit check.** If the credit economy is enabled, verify the sender's balance covers
   `netmail_cost` (+ `crashmail_cost` when crashmail is requested). Debited only after the
   row is successfully created.
2. **Local sysop shortcut.** If `to_name` is `sysop` and the destination is empty or one of
   our addresses, delegate to `sendLocalSysopMessage()`, which writes a local row addressed
   to the configured sysop user and never spools.
3. **Sender name resolution.** If no explicit `fromName` was passed,
   `resolveNetmailPostingName()` picks `real_name` or `username` according to the
   destination network's posting-name policy (Admin → Networks; per-destination).
4. **Origin address selection.** `getOriginAddressByDestination()` picks the `me` address of
   the uplink that will route the message; falls back to the system address with a warning.
5. **Pre-flight checks.** Outbound directory writability (only when the message will spool);
   crashmail destinations are resolved against the nodelist and rejected early if not found;
   a remote attachment without crashmail is rejected (direct delivery is required so the
   file arrives with the message).
6. **Charset selection.** Explicit caller charset → else, when replying, the parent's
   `message_charset` if the body still encodes cleanly → else the per-network default → else
   the BBS global outgoing charset. Falls back to UTF-8 whenever a conversion would lose
   data.
7. **Kludge generation.** `generateNetmailKludges()` builds MSGID, REPLY (when replying),
   INTL/FMPT/TOPT, CHRS, TZUTC, and any MARKUP kludge. The authoritative MSGID is parsed
   back out of the generated kludges and stored in `message_id`.
8. **Insert** the row with `user_id` = sender, `is_sent = FALSE`.
9. **Delivery:**
   - **Local** (destination equals our origin address): nothing is spooled. Attachments are
     copied straight into the recipient's private file area (and a sender copy into the
     sender's). If the recipient has email forwarding enabled, `Mail::maybeForwardNetmail()`
     sends them a copy.
   - **Crashmail** (`crashmail = true`, remote destination): `CrashmailService::queueCrashmail()`
     for direct node-to-node delivery, bypassing hub routing. Falls back to normal spooling
     if the queue call fails.
   - **Normal remote:** `spoolOutboundNetmail()` writes an outbound packet. If the
     destination is a registered downlink hub node/point it is routed via
     `hub_node_outbound`; otherwise it falls into uplink network-pattern routing. On success
     `is_sent` and `spooled_at` are set and an immediate outbound poll is queued.
     `spooled_at` being non-null hard-blocks any re-spool attempt (treated as a bug, logged
     to the admin daemon).

### Threading

Replies set `reply_to_id` to the parent row's `id`. `getNetmailConversation()` walks
`reply_to_id` up to the thread root with a recursive CTE, then loads all descendants.
Threaded list mode is `getThreadedNetmail()`. The REPLY kludge on outbound replies carries
the parent's MSGID so the thread survives the round trip.

---

## Deletion

`deleteNetmail()` is a **soft delete per side**:

- The querying user is the sender (`user_id` match) → set `deleted_by_sender`.
- The querying user is the recipient (`to_name` matches their username/real name) → set
  `deleted_by_recipient`.
- Anyone else → refused.

When **both** flags are set the row is hard-deleted immediately. Rows where one side is
remote (and so will never set its flag) are cleaned up by `scripts/database_maintenance.php`
— once the local side has deleted and the other side is remote, the row is purged.

A soft-deleted row drops out of that side's list and detail views but remains fully visible
to the other side until they also delete it.

---

## Configuration

| Setting | Where | Effect |
|---|---|---|
| `netmail_cost`, `crashmail_cost` | Credit economy config | Per-message credit charge. |
| Posting name policy | Admin → Networks (per network/domain) | Whether outbound `from_name` uses real name or username. |
| Outgoing charset | Admin → BBS settings / per-network | Default packet charset for outbound netmail. |
| `NETMAIL_ATTACHMENT_MAX_SIZE` | `.env` (`Config::env`) | Max size of an uploaded netmail attachment (default 10 MiB). |
| `forward_netmail_email` | Per-user setting | Forward locally-delivered netmail to the user's email (`v1.11.0.30`). |
| Markup / media allowance | Admin → Networks | Whether MARKUP kludge / inline media is emitted toward a destination. |

Crashmail routing additionally depends on a populated nodelist — see [Nodelist.md](Nodelist.md)
and [FREQ.md](FREQ.md) for the related file-request path.

---

## API surface

Netmail endpoints in `routes/api-routes.php` (see [API.md](API.md) for full request/response
tables):

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/messages/netmail` | List (filters: `all`, `unread`, `sent`, `received`, `saved`; `threaded`, `sort`). |
| `GET` | `/api/messages/netmail/stats` | Inbox/sent/unread/saved counts. |
| `GET` | `/api/messages/netmail/{id}` | Single message (same visibility rule as the list). |
| `GET` | `/api/messages/netmail/{id}/conversation` | Full thread. |
| `POST` | `/api/messages/netmail/read` | Bulk mark-as-read. |
| `POST` | `/api/messages/netmail/{id}/edit` | Sender/recipient metadata edit. |
| `DELETE` | `/api/messages/netmail/{id}` | Soft delete. |
| `POST` | `/api/messages/netmail/bulk-delete` | Bulk soft delete. |
| `GET` | `/api/messages/netmail/{id}/download` | Download raw message. |
| `POST` | `/api/netmail/attachment/upload` | Stage a file for an outbound attachment. |
| `POST` | `/api/messages/send` (`type=netmail`) | Compose and send (routes into `sendNetmail()`). |

The terminal server has a parallel netmail path (`telnet/src/`), and NNTP exposure of
netmail as a per-user pseudo-newsgroup is proposed in
`docs/proposals/NNTPNetmail.md`. Feature work on netmail should keep parity across the web,
terminal, and (where applicable) QWK and NNTP surfaces.

---

## Gotchas

- **Never scope netmail by `user_id` alone.** Use the compound visibility predicate (or the
  `MessageHandler` methods that already implement it).
- **`is_sent` is not "this is a sent message".** It only means "spooled outbound". Local
  mail this user sent has `is_sent = FALSE`.
- **`user_id` on an inbound row can be `NULL`** when `findTargetUser()` couldn't resolve the
  recipient but name matching later would (nickname `to_name`). Such rows are still owned by
  the user the name+address test matches.
- **Order by `date_received`, not `date_written`.** `date_written` comes from the remote
  clock and can be wrong or in the future; future-dated rows are suppressed from list
  queries until their timestamp passes.
- **A conversation is one row per message shared by both parties** — deleting your side does
  not remove the other party's copy.
