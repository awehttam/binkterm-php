# QWK Offline Mail — Design Document
## Downloading QWK Packets and Uploading REP Packets in BinktermPHP

**Status:** Design / Pre-implementation  
**Scope:** QWK download, REP upload, QWKE extensions  

---

## 1. Overview

This document describes how BinktermPHP should implement QWK offline mail packet download and REP reply-packet upload. The implementation must be true to the existing codebase architecture: PHP 8.1+, PostgreSQL via PDO, Slim-style routing through `SimpleRouter`, business logic in `src/`, and no reinvention of what already exists.

QWK packets are ZIP archives containing a binary conference-based message database. A user downloads the packet, reads it in an offline mail reader, writes replies, and uploads the resulting REP packet. BinktermPHP's existing infrastructure — `MessageHandler`, `BinkdProcessor`, `FileAreaManager`, `BinkpConfig`, and the `routes/` layer — provides almost everything needed; QWK is a new surface on top of that.

---

## 2. Codebase Summary (Relevant Parts)

### 2.1 Message Storage

All messages live in two PostgreSQL tables:

| Table | Primary key | Key columns |
|-------|-------------|-------------|
| `echomail` | `id` | `echoarea_id`, `from_name`, `from_address`, `to_name`, `subject`, `message_text`, `kludge_lines`, `date_written`, `date_received`, `message_id`, `reply_to_id` |
| `netmail` | `id` | `user_id`, `from_address`, `to_address`, `from_name`, `to_name`, `subject`, `message_text`, `kludge_lines`, `date_written`, `date_received`, `message_id`, `reply_to_id` |

Echo areas are in `echoareas` (columns: `id`, `tag`, `domain`, `is_active`, `is_local`, `message_count`).

Read tracking is in `message_read_status` (`user_id`, `message_id`, `message_type`, `read_at`).

User settings are in `user_settings` (`user_id`, `messages_per_page`, …).

### 2.2 MessageHandler

`src/MessageHandler.php` is the central service for all message access. Relevant public methods:

- `getEchoareas($userId)` — returns all active echo areas the user can see
- `getEchomail($tag, $domain, $page, $limit, $userId)` — paginated echomail for one area
- `getNetmail($userId, …)` — paginated netmail for a user
- `postEchomail(…)` — creates and spools an outbound echomail
- `sendNetmail(…)` — creates and spools an outbound netmail

Read-status tracking happens inside `getMessage()` and the mark-as-read helpers. QWK will need to track its own last-download offset per user per conference, not rely on `message_read_status` alone.

### 2.3 BinkdProcessor

`src/BinkdProcessor.php` handles FTN packet I/O. Its `createOutboundPacket()` method produces `.pkt` files; `processInboundPackets()` walks `data/inbound/`. QWK has nothing to do with BinkP packets, but `BinkdProcessor` contains the canonical FTN binary format knowledge we will reference for field widths and encoding.

### 2.4 BinkpConfig

`src/Binkp/Config/BinkpConfig.php` (singleton via `::getInstance()`) exposes:

- `getSystemName()` — full BBS name (used to derive the BBSID)
- `getSystemAddress()` — primary FTN address
- `getSystemSysop()` — sysop name

### 2.5 FileAreaManager

`src/FileAreaManager.php` handles file upload/download, temp-file cleanup, and ZIP operations (via PHP `ZipArchive`). Its `uploadFileFromPath()` and `uploadFile()` methods are the model for how we ingest uploaded archives.

### 2.6 Routes

All API endpoints live in `routes/api-routes.php` using `SimpleRouter`. Auth is enforced at the top of each closure via the standard `requireAuth()` / `$user = getCurrentUser()` pattern already present throughout the file. New QWK endpoints follow this exact pattern.

---

## 3. QWK / QWKE Specification Summary

The implementation must comply with the following:

### 3.1 QWK Packet Structure (download, BBS → reader)

A QWK packet is a ZIP file named `<BBSID>.QWK`. It contains:

| File | Description |
|------|-------------|
| `CONTROL.DAT` | Plain text header: BBS name, city, sysop, serial, conf list, etc. |
| `MESSAGES.DAT` | Fixed 128-byte-block binary message file |
| `NEWFILES.DAT` | Optional list of new files (not required for first pass) |
| `BBS.LIST` | Optional list of other BBSes |
| `DOOR.ID` | Optional door identification |
| `*.NDX` | Per-conference index files (optional but helpful) |

#### CONTROL.DAT format

```
BBS Name
City, State
Phone number
Sysop Name
0,BBSID
Date and time of packet creation
User's name (from the reader's perspective, i.e. the logged-in user)
Blank
Number of messages waiting
Number of new conferences
ConferenceNumber ConferenceName
ConferenceNumber ConferenceName
...
```

The fifth line is `0,<BBSID>`. The BBSID is an 8-character (maximum) alphanumeric identifier derived from the BBS name. BinktermPHP derives it as: take `BinkpConfig::getSystemName()`, strip non-alphanumeric characters, uppercase, truncate to 8.

The conference list begins at a fixed offset after the header block (line 11 onwards). Conference 0 is always "Main Board" (personal mail / netmail). Conferences 1–N map to subscribed echo areas.

#### MESSAGES.DAT format

Each message occupies one or more 128-byte blocks. Block 0 (the very first block) is a reserved header block containing the string `Produced by Qmail...` padded with spaces/nulls to 128 bytes.

Each subsequent message starts at a 128-byte boundary. The first block of each message is the **message header**:

| Offset | Length | Field |
|--------|--------|-------|
| 0 | 1 | Status byte |
| 1 | 7 | Message number (ASCII, space-padded) |
| 8 | 8 | Date (MM-DD-YY) |
| 16 | 5 | Time (HH:MM) |
| 21 | 25 | To (null-padded) |
| 46 | 25 | From (null-padded) |
| 71 | 25 | Subject (null-padded) |
| 96 | 8 | Password (null-padded, typically blank) |
| 104 | 8 | Reply-to message number (ASCII) |
| 112 | 6 | Number of 128-byte blocks this message occupies (ASCII, includes this header block) |
| 118 | 1 | Activity flag (0xE1 = active) |
| 119 | 2 | Conference number (little-endian 16-bit unsigned) |
| 121 | 2 | Logical message number in conference (little-endian 16-bit) |
| 123 | 1 | Net tag flag |
| 124 | 4 | Reserved/padding |

Following the header block, the message body continues in subsequent 128-byte blocks, packed end-to-end with a `\xE3` byte as the line terminator (replacing `\r\n`). The final block is padded with nulls to 128 bytes.

#### QWKE Extensions

QWKE (QWK Extended) is a backward-compatible extension. When a DOOR.ID file declares `CONTROLTYPE QWKE`, the reader knows extended headers are available. QWKE messages prepend control lines before the body using `^A` (0x01) prefixes — these map directly to FidoNet kludge lines:

```
^AMSGID: <address> <hash>
^AREPLY: <msgid>
^AFROM: Full Name <address>
^ATO: Full Name <address>
^ASUBJECT: Full subject text
```

This means kludge data already in the `kludge_lines` column can be passed through verbatim, which is a clean fit for BinktermPHP.

### 3.2 REP Packet Structure (upload, reader → BBS)

A REP packet is a ZIP file named `<BBSID>.REP`. It contains a single message file named **`<BBSID>.MSG`** (note: not `MESSAGES.DAT`). The naming convention is important — some implementations name it `MESSAGES.DAT` but the correct, widely-supported convention for REP uploads is `BBSID.MSG`.

The `<BBSID>.MSG` file has the same 128-byte block structure as `MESSAGES.DAT`. Conference 0 replies are netmail. All other conference replies are echomail. The status byte in uploaded replies indicates the disposition.

---

## 4. New Data

### 4.1 Database Migration

A new migration `vX.Y.Z_qwk_support.sql` must be created:

```sql
-- QWK download state: tracks the last message seen per user per conference
CREATE TABLE qwk_conference_state (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    echoarea_id  INTEGER REFERENCES echoareas(id) ON DELETE CASCADE,
    is_netmail   BOOLEAN NOT NULL DEFAULT FALSE,
    last_msg_id  INTEGER NOT NULL DEFAULT 0,  -- echomail.id or netmail.id of last downloaded message
    updated_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, echoarea_id),
    UNIQUE (user_id, is_netmail) WHERE is_netmail = TRUE
);

CREATE INDEX idx_qwk_conf_state_user ON qwk_conference_state (user_id);

-- QWK download log (optional, for auditing / rate-limiting)
CREATE TABLE qwk_download_log (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    downloaded_at TIMESTAMP NOT NULL DEFAULT NOW(),
    message_count INTEGER NOT NULL DEFAULT 0,
    packet_size  INTEGER NOT NULL DEFAULT 0
);
```

The `last_msg_id` represents the highest `echomail.id` (or `netmail.id`) included in the last downloaded packet. On the next download, only messages with `id > last_msg_id` are included.

Conference 0 (netmail) is represented by the row where `is_netmail = TRUE`. All echo-area conferences use `echoarea_id` with `is_netmail = FALSE`.

### 4.2 Configuration

No new top-level configuration files are needed. A small `qwk` section may be added to `config/bbs.json` via the existing `BbsConfig` mechanism:

```json
{
  "qwk": {
    "enabled": true,
    "max_messages_per_download": 500,
    "include_netmail": true
  }
}
```

BbsConfig defaults handle the case where the section is absent, defaulting `enabled` to `true` and `max_messages_per_download` to `500`.

---

## 5. New Source Files

### 5.1 `src/Qwk/QwkBuilder.php`

Responsible for building the QWK packet from database content.

```php
namespace BinktermPHP\Qwk;

class QwkBuilder
{
    private \PDO $db;
    private \BinktermPHP\Binkp\Config\BinkpConfig $config;

    public function __construct() { … }

    /**
     * Build a QWK packet ZIP for the given user.
     * Returns the path to the temporary ZIP file.
     */
    public function buildPacket(int $userId): string { … }

    /** Derive the 8-char BBSID from the system name. */
    public function getBbsId(): string { … }

    /** Generate CONTROL.DAT content. */
    private function buildControlDat(int $userId, array $conferences): string { … }

    /** Generate DOOR.ID content declaring QWKE support. */
    private function buildDoorId(): string { … }

    /** Build MESSAGES.DAT binary content. */
    private function buildMessagesDat(array $conferenceMessages): string { … }

    /** Encode one message into 128-byte blocks. */
    private function encodeMessage(
        array  $message,
        int    $conferenceNumber,
        int    $logicalMessageNumber,
        string $statusByte = ' '
    ): string { … }

    /** Fetch new messages for a user across all subscribed conferences. */
    private function fetchConferenceMessages(int $userId): array { … }

    /** Update qwk_conference_state after a successful packet build. */
    private function updateConferenceState(int $userId, array $lastIds): void { … }
}
```

Key implementation notes:

- `getBbsId()`: `strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->config->getSystemName()))` truncated to 8 characters.
- `buildControlDat()`: Follows the exact line ordering from §3.1. The conference list begins at line 11. Conference 0 is netmail (name: "Personal Mail"). Subscribed echo areas follow, numbered from 1.
- `buildMessagesDat()`: Block 0 is the reserved `Produced by Qmail...` header. Messages are appended sequentially. Each call to `encodeMessage()` returns the complete set of 128-byte blocks for that message.
- `encodeMessage()`: The body text uses `\xE3` as line separator (replacing `\n`). QWKE control lines (`^AMSGID:`, `^AREPLY:`, `^AFROM:`, `^ATO:`, `^ASUBJECT:`) are prepended to the body before encoding — sourced directly from the `kludge_lines` column where available.
- The conference number field in each message header is the conference's position in the conference list (0 = netmail, 1–N = echo areas).
- `fetchConferenceMessages()`: For each conference, selects `echomail` rows with `id > last_msg_id` up to the configured limit, ordered by `id ASC`. For conference 0, selects `netmail` rows addressed to the user with `id > last_msg_id`.
- After building the ZIP, `updateConferenceState()` records the highest `id` seen per conference into `qwk_conference_state`.

### 5.2 `src/Qwk/RepProcessor.php`

Responsible for parsing an uploaded REP packet and injecting the replies into the BBS.

```php
namespace BinktermPHP\Qwk;

class RepProcessor
{
    private \PDO $db;
    private \BinktermPHP\MessageHandler $messageHandler;

    public function __construct() { … }

    /**
     * Process an uploaded REP ZIP file.
     * Returns ['imported' => N, 'errors' => [...]]
     */
    public function processRepPacket(string $zipPath, int $userId): array { … }

    /** Extract and validate the BBSID.MSG file from the ZIP. */
    private function extractMsgFile(string $zipPath): string { … }

    /** Parse BBSID.MSG into an array of message arrays. */
    private function parseMsgFile(string $msgPath): array { … }

    /** Parse one 128-byte-aligned message starting at the given offset. */
    private function parseMessage(string $data, int $offset): ?array { … }

    /** Map conference number back to echoarea tag+domain or netmail. */
    private function resolveConference(int $conferenceNumber, int $userId): ?array { … }

    /** Import one parsed reply as echomail or netmail via MessageHandler. */
    private function importReply(array $parsedMsg, int $userId): bool { … }

    /** Strip QWKE control lines from body and return [body, kludges]. */
    private function splitQwkeBody(string $body): array { … }
}
```

Key implementation notes:

- `extractMsgFile()`: Opens the ZIP, finds the entry matching `*.MSG` case-insensitively (expecting `<BBSID>.MSG`). Extracts to a temp path under `sys_get_temp_dir()`. Validates that the BBSID matches `QwkBuilder::getBbsId()` for this installation.
- `parseMsgFile()`: Reads block 0 (reserved header, skip), then iterates. Each message: read the 128-byte header block, extract the block count (bytes 112–117 as ASCII integer), read `(blockCount - 1) × 128` bytes of body data, trim trailing nulls, replace `\xE3` with `\n`.
- `splitQwkeBody()`: Scans the body for leading `\x01`-prefixed lines. These are QWKE kludge lines. Returns the kludge lines separately from the message body text.
- `resolveConference()`: Queries `qwk_conference_state` for the user. Conference 0 → netmail. Others → look up by the ordered position from the last download state. Since conference numbering is per-download, the mapping must be derived from the state recorded during the last `buildPacket()` call. A `qwk_conference_map` column or a separate lookup table may be needed (see §6.1).
- `importReply()`: For echomail replies, calls `$this->messageHandler->postEchomail(…)`. For netmail replies, calls `$this->messageHandler->sendNetmail(…)`. Both methods already exist and handle spooling, credits, and kludge generation. The QWKE `^AMSGID:` and `^AREPLY:` from the reader's headers should be stored as-is in the kludge lines rather than regenerated, as they carry the reader's own MSGID.
- Temp files are cleaned up in a `finally` block regardless of outcome.

---

## 6. Conference Number Mapping

Conference numbers in QWK are positional and per-packet. Because a user's subscriptions may change between downloads, the mapping from conference number → echoarea must be persisted alongside the download state.

### 6.1 Approach: Store the Conference Map in the Download State

Add a `conference_map` JSONB column to `qwk_download_log` (or a separate table):

```sql
ALTER TABLE qwk_download_log
    ADD COLUMN conference_map JSONB NOT NULL DEFAULT '{}';
```

The map is a JSON object keyed by conference number (as a string), with each value being an object `{"echoarea_id": N, "tag": "...", "domain": "..."}`. Conference `"0"` is `{"netmail": true}`.

On REP upload, `RepProcessor` fetches the most recent row from `qwk_download_log` for the user and deserialises this map to resolve conference numbers. If no prior download exists, the REP is rejected with an appropriate error message.

---

## 7. Routes

Add to `routes/api-routes.php`:

```php
// QWK packet download
SimpleRouter::get('/qwk/download', function() {
    requireAuth();
    $user = getCurrentUser();
    $userId = (int)$user['id'];

    if (!BbsConfig::isFeatureEnabled('qwk')) {
        apiError('errors.qwk.disabled', 'QWK offline mail is not enabled on this system.');
        return;
    }

    $builder = new \BinktermPHP\Qwk\QwkBuilder();
    $packetPath = $builder->buildPacket($userId);
    $bbsId      = $builder->getBbsId();
    $filename   = $bbsId . '.QWK';

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($packetPath));
    header('Cache-Control: no-store');
    readfile($packetPath);
    @unlink($packetPath);
});

// REP packet upload
SimpleRouter::post('/qwk/upload', function() {
    requireAuth();
    $user   = getCurrentUser();
    $userId = (int)$user['id'];

    if (!BbsConfig::isFeatureEnabled('qwk')) {
        apiError('errors.qwk.disabled', 'QWK offline mail is not enabled on this system.');
        return;
    }

    if (empty($_FILES['rep']) || $_FILES['rep']['error'] !== UPLOAD_ERR_OK) {
        apiError('errors.qwk.no_file', 'No REP file received or upload error.');
        return;
    }

    $processor = new \BinktermPHP\Qwk\RepProcessor();
    $result    = $processor->processRepPacket($_FILES['rep']['tmp_name'], $userId);

    echo json_encode([
        'success'  => true,
        'imported' => $result['imported'],
        'errors'   => $result['errors'],
    ]);
});
```

These endpoints are authenticated, return JSON on the upload path, and stream binary on the download path — consistent with the existing `/files/{id}/download` and `/files/upload` endpoints in `api-routes.php`.

---

## 8. UI Integration

### 8.1 New Template

Add `templates/qwk.twig` (or a section within an existing user-settings or offline-mail template). This renders:

- A brief explanation of QWK offline mail.
- A **Download QWK Packet** button — an anchor to `GET /api/qwk/download` which triggers a file download.
- A **Upload REP Packet** form — a standard `<form enctype="multipart/form-data">` with a file picker accepting `.rep` and `.zip`, POST to `POST /api/qwk/upload`. Result shown inline via JavaScript.
- The current state: conferences available, messages waiting since last download.

### 8.2 Navigation

Add a QWK link to the main navigation or to the user settings area. The feature can be gated with `BbsConfig::isFeatureEnabled('qwk')` in the Twig template.

---

## 9. Encoding and Character Sets

FidoNet and QWK traditionally used CP437 / IBM PC character sets. BinktermPHP stores messages as UTF-8. The following approach is used:

- **On download (build):** Message text is stored as UTF-8 in the database. Write it as UTF-8 into the MESSAGES.DAT blocks. Include a QWKE `^ACHRS: UTF-8 4` kludge line to signal the character set. Modern QWK readers (NeoQWK, etc.) respect this kludge. Legacy readers will display UTF-8 as-is, which is broadly acceptable.
- **On upload (parse):** Incoming REP body text is treated as UTF-8 unless a `^ACHRS:` kludge says otherwise. If no charset kludge is present and the body contains bytes outside the ASCII range, attempt `mb_convert_encoding($body, 'UTF-8', 'CP437')` as a fallback.

---

## 10. Security Considerations

- **Packet size limits:** `buildPacket()` enforces `max_messages_per_download` from config. The route should also enforce a maximum execution time (consider a PHP time limit or background job for large backlogs).
- **REP upload validation:** `extractMsgFile()` validates that the ZIP contains exactly one `.MSG` file, that its BBSID matches, and that the file size is a multiple of 128 bytes. Malformed or oversized uploads are rejected before parsing.
- **Path traversal:** ZIP entry names are never used as filesystem paths directly; extraction always goes to a controlled temp directory.
- **Flooding:** A user must have a prior download on record before an REP upload is accepted. This prevents blind replay attacks. Additionally, rate-limit REP uploads to one per N minutes (configurable) via a check against `qwk_download_log`.
- **Auth:** Both endpoints use the standard `requireAuth()` pattern already in the codebase.

---

## 11. DOOR.ID File

The `DOOR.ID` file in the QWK packet declares capabilities to the offline reader:

```
DOOR = BinktermPHP
VERSION = <version from Version::getVersion()>
SYSTEM = <BBS name from BinkpConfig::getSystemName()>
NETWORK = FidoNet
CONTROLTYPE = QWKE
CONTROLNAME = CONTROL.DAT
```

`CONTROLTYPE QWKE` signals to the reader that QWKE extended headers are present and should be parsed. This file is generated by `QwkBuilder::buildDoorId()` and added to the ZIP alongside `CONTROL.DAT` and `MESSAGES.DAT`.

---

## 12. Implementation Order

1. **Database migration** — `qwk_conference_state` and `qwk_download_log` tables.
2. **`QwkBuilder`** — implement `getBbsId()`, `buildControlDat()`, `buildDoorId()`, `encodeMessage()`, `buildMessagesDat()`, `fetchConferenceMessages()`, `buildPacket()`, `updateConferenceState()`.
3. **Routes (download only)** — wire up `GET /api/qwk/download`, test end-to-end with a real QWK reader.
4. **`RepProcessor`** — implement `extractMsgFile()`, `parseMsgFile()`, `parseMessage()`, `splitQwkeBody()`, `resolveConference()`, `importReply()`, `processRepPacket()`.
5. **Routes (upload)** — wire up `POST /api/qwk/upload`.
6. **UI template** — download button, upload form, current state display.
7. **Config gating** — `qwk.enabled` in `bbs.json`.

---

## 13. Compatibility Notes

- The QWK reader most directly relevant to BinktermPHP's target audience is **NeoQWK** (QwkNet-based), which fully supports QWKE and expects `<BBSID>.MSG` inside the REP. The design above is correct for this case.
- **Synchronet** and **MBSE** also produce and consume QWK/REP packets matching this layout.
- **Legacy DOS readers** (QWK, OLX, Slick) do not understand QWKE kludge lines, but the body text remains readable. They will simply see the kludge lines as part of the message body, which is the intended graceful degradation.
- Conference numbers in the CONTROL.DAT must be consistent with the numbers embedded in MESSAGES.DAT. Both are derived from the same ordered conference list produced by `fetchConferenceMessages()`.

---

*End of document.*
