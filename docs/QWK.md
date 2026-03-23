# QWK Offline Mail

QWK is an offline mail format originating from the BBS era. Instead of reading
and writing messages while connected, you download a packet containing all new
messages, disconnect, read and reply at your leisure in a local reader
application, then reconnect and upload the reply packet. BinktermPHP supports
the standard QWK format and the QWKE (QWK Extended) variant which carries full
FidoNet metadata.

## Table of Contents

- [How It Works](#how-it-works)
- [Packet Formats](#packet-formats)
  - [QWK](#qwk)
  - [QWKE](#qwke)
- [Conferences](#conferences)
  - [Personal Mail (Conference 0)](#personal-mail-conference-0)
  - [Echo Areas](#echo-areas)
- [Message Limits](#message-limits)
- [Composing Messages](#composing-messages)
  - [Subject Lines](#subject-lines)
  - [Sending Netmail](#sending-netmail)
- [Uploading a REP Packet](#uploading-a-rep-packet)
  - [Validation](#validation)
  - [Deduplication](#deduplication)
  - [Reply Threading](#reply-threading)
- [API Endpoints](#api-endpoints)
- [Recommended Readers](#recommended-readers)

---

## How It Works

1. **Download** a QWK packet from `/api/qwk/download`. The packet is a ZIP
   archive named `BBSID.QWK` containing all new messages since your last
   download across your subscribed echo areas and personal mail.

2. **Open** the packet in a QWK-capable offline reader. Read messages, compose
   replies, and write new messages.

3. **Export** the reply packet from your reader. This is a ZIP archive named
   `BBSID.REP` containing a `BBSID.MSG` file with your outgoing messages.

4. **Upload** the REP packet to `/api/qwk/upload`. BinktermPHP imports your
   messages, posts echomail to the appropriate areas, and routes netmail.

You must download a QWK packet at least once before uploading a REP packet.
The download establishes the conference map that BinktermPHP uses to route
your replies back to the correct echo areas.

---

## Packet Formats

### QWK

The standard QWK format encodes message text in CP437 (PC character set).
Conference and message header fields are limited to 25 characters for To, From,
and Subject. QWK is supported by virtually all offline readers.

### QWKE

QWKE (QWK Extended) is a backward-compatible extension that carries full
FidoNet metadata inside the packet. BinktermPHP signals QWKE support via
`CONTROLTYPE = QWKE` in `DOOR.ID` and a `TOREADER.EXT` file listing supported
kludge types: `CHRS`, `MSGID`, `REPLY`, `TZUTC`, `INTL`, `FMPT`, `TOPT`.

Both QWK and QWKE export message bodies in CP437. Differences in QWKE:

- FidoNet kludge lines (`^A`-prefixed) are prepended to each message body,
  carrying `MSGID`, `REPLY`, `TZUTC`, `INTL`, and other metadata. A
  `^ACHRS: CP437 2` kludge signals the body encoding.
- Plain-text extended headers (`Subject:`, `To:`, `From:`) are written before
  the kludge lines when the corresponding field exceeds 25 characters, allowing
  readers that do not support `^A` prefixes to still benefit from extended
  field lengths.

QWKE is recommended for readers that support it. Use plain QWK for maximum
compatibility with older readers.

---

## Conferences

### Personal Mail (Conference 0)

Conference 0 is always Personal Mail — netmail addressed to you. Messages in
this conference are marked private (`+` status byte). When you compose a reply
to a conference-0 message, BinktermPHP routes it to the FTN address of the
original sender using the message index from your most recent download.

### Echo Areas

Each echo area you subscribe to is assigned a stable, BBS-wide conference
number (stored persistently on the echo area record). These numbers are
consistent across all users and across downloads — subscribing or unsubscribing
from other areas does not change the conference numbers you already know.

Conference names in `CONTROL.DAT` are truncated to 13 characters. The format
is `AREANAME` or `AREANAME@DOMAIN` when a network domain is present.

---

## Message Limits

Each download is limited to a configurable number of messages across all
conferences combined:

| Setting | Value |
|---|---|
| Default per-download limit | 2,500 messages |
| Hard cap | 10,000 messages |

You can choose a preferred limit (500 – hard cap) in the web UI; the preference
is saved to your account. The limit can also be overridden per-request via the
`limit` query parameter on `GET /api/qwk/download`.

When the limit is reached, messages are included in conference order (Personal
Mail first, then echo areas in conference-number order) and older messages
within each conference are prioritised over newer ones.

---

## Composing Messages

### Subject Lines

The QWK message header has a fixed 25-character subject field. In QWKE mode,
BinktermPHP writes a plain-text `Subject:` line at the top of the message body
when the subject exceeds 25 characters, and reads it back on REP import. This
means subjects up to 71 characters are preserved end-to-end when using a
QWKE-capable reader.

In plain QWK mode subjects are hard-limited to 25 characters.

### Sending Netmail

Replies to received netmail are automatically routed to the FTN address of the
original sender via the message index — no special action is needed.

To compose **new** netmail to an arbitrary FTN address, put the destination in
the To field using the following format:

```
Name@zone:net/node[.point]
```

Examples:

```
Sysop@1:1/1
John Smith@2:280/464
Point User@3:712/848.5
```

The address portion after `@` is extracted and used for FTN routing. The name
portion before `@` is used as the recipient name. If no name is given (i.e. the
field starts with `@`), the recipient name defaults to `Sysop`.

If no address is embedded and no reply reference is present, the message is
routed to the system address as a fallback.

---

## Uploading a REP Packet

### Validation

BinktermPHP validates the REP packet before importing any messages:

- The file must be a valid ZIP archive.
- The archive must contain a `BBSID.MSG` file where `BBSID` matches this
  system's BBS ID (derived from the system name: non-alphanumeric characters
  stripped, uppercased, truncated to 8 characters; falls back to `BINKTERM`).
- The MSG file size must be a non-zero multiple of 128 bytes (the QWK block
  size). A file that fails this check is rejected as corrupt.
- The upload must not exceed 10 MB.
- A prior QWK download must exist for your account (the conference map from
  that download is required to route replies).

Messages with an empty body are silently skipped. Messages with activity flag
`0x00` and a block count of 1 or less are treated as end-of-file padding and
skipped.

### Deduplication

BinktermPHP computes a SHA-256 hash of each imported message's content
(conference number, To name, Subject, and body). If an identical message has
been imported before, it is skipped. This means re-uploading the same REP
packet is safe — no duplicate messages will be posted.

### Reply Threading

Each QWK download records a message index mapping QWK logical message numbers
(1-based, sequential across all conferences) to internal database IDs. When a
reply packet references a message number in the `reply-to` header field,
BinktermPHP looks it up in the index to establish the reply relationship and,
for netmail, to resolve the destination FTN address.

The index is replaced on every download, so reply references are only valid
against the most recent packet.

---

## API Endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/qwk/download` | Build and stream a QWK packet. Optional `?format=qwk\|qwke` and `?limit=N` parameters. |
| `POST` | `/api/qwk/upload` | Accept a REP packet upload (`multipart/form-data`, field name `rep`). Returns `{success, imported, skipped, errors[]}`. |
| `GET` | `/api/qwk/status` | Return current conference state, new message counts, last download timestamp, and format preference. |
| `POST` | `/api/qwk/format` | Save preferred packet format. Body: `{"format": "qwk"}` or `{"format": "qwke"}`. |

All endpoints require authentication and return JSON (except `/download` which
streams a ZIP file).

---

## Recommended Readers

| Reader | Platform | QWK | QWKE |
|---|---|---|---|
| MultiMail | Linux, Windows, macOS | ✓ | ✓ |
| OLX | DOS | ✓ | — |
| Yarn | Cross-platform | ✓ | Partial |

QWKE support in readers varies. MultiMail reads and writes QWKE extended
subject, to, and from headers and passes `^A`-prefixed kludge lines through
to the message body for display. It does not write `^AINTL` routing kludges
in reply packets; use the `Name@address` To-field convention (described above)
for new netmail.
