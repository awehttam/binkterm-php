# QWK Offline Mail Support Proposal

## Overview

This proposal outlines adding QWK offline mail packet support to BinktermPHP, allowing users to download message packets for offline reading in classic QWK-compatible mail readers and upload reply packets (REP files) back to the system.

QWK (Quick-K) was the dominant offline mail format in the BBS era and remains supported by many mail readers. Adding this feature provides:

- Offline reading capability for users with limited connectivity
- Compatibility with classic mail readers (BlueWave, OLX, MultiMail, etc.)
- Nostalgic experience for retro computing enthusiasts
- Alternative interface for power users who prefer dedicated mail readers

## Requirements

- Generate QWK packets containing echomail from subscribed areas
- Generate QWK packets containing user's netmail
- Support for REP packet uploads (replies)
- Per-user configuration for QWK packet generation
- Configurable message limits and area selection
- Download via web interface with one-click packet generation
- Track last download pointer per user/area for incremental downloads

---

## QWK Format Specification

### QWK Packet Structure (Download)

A QWK packet is a ZIP archive containing:

| File | Description |
|------|-------------|
| `CONTROL.DAT` | BBS configuration and conference list (ASCII) |
| `MESSAGES.DAT` | Message bodies in 128-byte blocks |
| `*.NDX` | Index files for each conference (e.g., `001.NDX`) |
| `DOOR.ID` | Door identification file |
| `NEWFILES.DAT` | Optional - new files list (not implemented) |
| `PERSONAL.NDX` | Index of messages addressed to the user |

### REP Packet Structure (Upload)

A REP packet is a ZIP archive containing:

| File | Description |
|------|-------------|
| `BBSID.MSG` | Reply messages in same 128-byte block format |

### Message Block Format

Each message in `MESSAGES.DAT` consists of:

1. **Header Block** (128 bytes):
   - Status flag (1 byte)
   - Message number (7 bytes, ASCII, space-padded)
   - Date (8 bytes, MM-DD-YY)
   - Time (5 bytes, HH:MM)
   - To (25 bytes, space-padded)
   - From (25 bytes, space-padded)
   - Subject (25 bytes, space-padded)
   - Password (12 bytes, space-padded)
   - Reference message number (8 bytes)
   - Number of 128-byte blocks including header (6 bytes)
   - Active flag (1 byte, 225 = active)
   - Conference number (2 bytes, little-endian)
   - Logical message number (2 bytes)
   - Net tag (1 byte, '*' for netmail)

2. **Body Blocks** (128 bytes each):
   - Message text with `0xE3` as line separator
   - Padded with spaces to 128-byte boundary

### CONTROL.DAT Format

Line-by-line ASCII file:
```
BBS Name
City, State
Phone Number
Sysop Name
0,BBSID
MM-DD-YYYY,HH:MM:SS
User Name

0
999
0
Conference 0 Name
Conference 1 Name
...
HELLO
NEWS
GOODBYE
```

---

## Implementation Steps

### 1. Database Migration

**File:** `database/migrations/v1.7.0_add_qwk_support.sql`

```sql
-- QWK download pointers - tracks last downloaded message per user/area
CREATE TABLE IF NOT EXISTS qwk_pointers (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    echoarea_id INTEGER REFERENCES echoareas(id) ON DELETE CASCADE,
    last_message_id INTEGER NOT NULL DEFAULT 0,
    last_download_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, echoarea_id)
);

-- Also track netmail pointer
CREATE TABLE IF NOT EXISTS qwk_netmail_pointers (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    last_message_id INTEGER NOT NULL DEFAULT 0,
    last_download_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- QWK configuration per user
ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS qwk_enabled BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS qwk_max_messages INTEGER DEFAULT 500,
    ADD COLUMN IF NOT EXISTS qwk_include_netmail BOOLEAN DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS qwk_archive_name VARCHAR(8) DEFAULT NULL;

-- QWK download history for auditing
CREATE TABLE IF NOT EXISTS qwk_download_history (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    packet_name VARCHAR(255) NOT NULL,
    message_count INTEGER NOT NULL DEFAULT 0,
    packet_size INTEGER NOT NULL DEFAULT 0,
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address INET
);

-- Index for efficient pointer lookups
CREATE INDEX IF NOT EXISTS idx_qwk_pointers_user ON qwk_pointers(user_id);
CREATE INDEX IF NOT EXISTS idx_qwk_download_history_user ON qwk_download_history(user_id);
```

### 2. Create QWK Packet Generator Service

**New File:** `src/Qwk/QwkPacketGenerator.php`

```php
<?php
namespace BinktermPHP\Qwk;

class QwkPacketGenerator
{
    private $db;
    private $userId;
    private $bbsId;

    // Key methods:
    // - generate(): Create complete QWK packet
    // - generateControlDat(): Create CONTROL.DAT file
    // - generateMessagesDat(): Create MESSAGES.DAT with all messages
    // - generateNdxFiles(): Create index files per conference
    // - generatePersonalNdx(): Create PERSONAL.NDX for user's messages
    // - generateDoorId(): Create DOOR.ID file
    // - packMessage(): Convert message to 128-byte block format
    // - getNewMessages(): Fetch unread messages since last pointer
    // - updatePointers(): Update download pointers after successful generation
}
```

Key implementation details:

| Method | Purpose |
|--------|---------|
| `generate()` | Main entry point - creates ZIP with all QWK files |
| `packMessage()` | Convert message to 128-byte blocked format |
| `encodeText()` | Convert UTF-8 to CP437 for compatibility |
| `generateControlDat()` | Build conference list from user's subscribed areas |
| `generateNdxFiles()` | Create binary index for each conference |
| `getSubscribedAreas()` | Get user's echoarea subscriptions |

### 3. Create REP Packet Parser Service

**New File:** `src/Qwk/RepPacketParser.php`

```php
<?php
namespace BinktermPHP\Qwk;

class RepPacketParser
{
    private $db;
    private $userId;

    // Key methods:
    // - parse(): Extract and validate REP packet
    // - parseMessage(): Parse single message from block format
    // - validatePacket(): Verify packet belongs to this BBS
    // - processReplies(): Queue messages for sending
    // - determineMessageType(): Detect netmail vs echomail from conference
}
```

### 4. Create QWK Controller

**New File:** `src/Qwk/QwkController.php`

```php
<?php
namespace BinktermPHP\Qwk;

class QwkController
{
    // Web interface controller methods:
    // - downloadPacket(): Generate and stream QWK download
    // - uploadRep(): Handle REP packet upload
    // - getStatus(): Return current pointer status and pending message counts
    // - resetPointers(): Reset download pointers to re-download messages
    // - configure(): Update user's QWK settings
}
```

### 5. Add API Routes

**File:** `routes/api-routes.php`

Add new routes:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/qwk/download` | GET | Generate and download QWK packet |
| `/api/qwk/upload` | POST | Upload REP packet with replies |
| `/api/qwk/status` | GET | Get pending message counts and pointer info |
| `/api/qwk/reset` | POST | Reset download pointers |
| `/api/qwk/settings` | GET | Get user's QWK settings |
| `/api/qwk/settings` | PUT | Update user's QWK settings |

### 6. Add Web Routes

**File:** `routes/web-routes.php`

| Route | Purpose |
|-------|---------|
| `/qwk` | QWK download/upload page |
| `/qwk/download` | Direct download (redirects to API) |

### 7. Create QWK Page Template

**New File:** `templates/qwk.twig`

Page sections:

1. **Download Section**
   - "Download QWK Packet" button
   - Pending message count per area
   - Last download timestamp
   - Estimated packet size

2. **Upload Section**
   - REP file upload form
   - Drag-and-drop zone
   - Upload history/status

3. **Settings Section**
   - Enable/disable QWK
   - Max messages per packet
   - Include netmail toggle
   - Custom BBS ID (8 chars max)
   - Reset pointers button

4. **Statistics Section**
   - Total downloads
   - Total replies uploaded
   - Download history table

### 8. Update Settings Page

**File:** `templates/settings.twig`

Add "QWK Offline Mail" card section with:

- Toggle to enable QWK downloads
- Link to dedicated QWK page
- Quick stats (last download, pending messages)

### 9. Add Navigation Link

**File:** `templates/nav.twig`

Add "QWK" link under Messages dropdown or as separate nav item (icon: download/archive icon).

### 10. Update MessageHandler

**File:** `src/MessageHandler.php`

Add methods:

```php
// Get user's QWK settings
public function getQwkSettings(int $userId): array

// Update user's QWK settings
public function updateQwkSettings(int $userId, array $settings): bool

// Get count of new messages since last QWK download
public function getQwkPendingCount(int $userId): array
```

Add `qwk_*` fields to `$allowedSettings` whitelist.

### 11. Character Encoding Utilities

**New File:** `src/Qwk/CharsetConverter.php`

```php
<?php
namespace BinktermPHP\Qwk;

class CharsetConverter
{
    // Convert UTF-8 to CP437 for QWK compatibility
    public static function utf8ToCp437(string $text): string

    // Convert CP437 to UTF-8 for REP parsing
    public static function cp437ToUtf8(string $text): string

    // Handle line endings (QWK uses 0xE3)
    public static function normalizeLineEndings(string $text): string
}
```

### 12. Version Bump

Update version to 1.7.0 in:
- `src/Version.php`
- `composer.json`
- `templates/recent_updates.twig` (add changelog entry)

---

## Files Summary

### New Files - User Offline Mail

| File | Purpose |
|------|---------|
| `database/migrations/v1.7.0_add_qwk_support.sql` | Database schema for QWK tracking and networks |
| `src/Qwk/QwkPacketGenerator.php` | Generate QWK download packets |
| `src/Qwk/RepPacketParser.php` | Parse REP upload packets |
| `src/Qwk/QwkController.php` | Web/API controller for user downloads |
| `src/Qwk/CharsetConverter.php` | CP437/UTF-8 conversion utilities |
| `templates/qwk.twig` | QWK download/upload page |

### New Files - QWK Networking (Inter-BBS)

| File | Purpose |
|------|---------|
| `src/Qwk/QwkNetworkService.php` | Network management and sync logic |
| `src/Qwk/QwkNetworkPoller.php` | Automated polling service |
| `src/Qwk/HttpQwkHub.php` | HTTP/HTTPS hub connector |
| `src/Qwk/FtpQwkHub.php` | FTP/SFTP hub connector |
| `scripts/qwk_poll.php` | CLI script for cron-based polling |
| `templates/admin/qwk_networks.twig` | Admin network management page |

### Modified Files

| File | Changes |
|------|---------|
| `routes/api-routes.php` | Add QWK API endpoints (user + admin) |
| `routes/web-routes.php` | Add QWK page route |
| `templates/settings.twig` | Add QWK settings section |
| `templates/nav.twig` | Add QWK navigation link |
| `templates/admin/nav.twig` | Add QWK Networks admin link |
| `src/MessageHandler.php` | Add QWK settings methods |
| `src/AdminController.php` | Add QWK network management methods |
| `src/Version.php` | Bump to 1.7.0 |
| `composer.json` | Bump version |
| `templates/recent_updates.twig` | Add changelog entry |

---

## Conference Number Mapping

QWK uses numeric conference IDs. The mapping strategy:

| Conference # | Purpose |
|--------------|---------|
| 0 | Reserved for netmail (personal messages) |
| 1-n | Echo areas (mapped by echoarea.id) |

The `CONTROL.DAT` will list conferences in order, and the `*.NDX` files will use these numbers (e.g., `000.NDX` for netmail, `001.NDX` for first echo area).

---

## Message Status Flags

QWK message status byte values:

| Value | Meaning |
|-------|---------|
| ` ` (32) | Public, read |
| `*` (42) | Public, has reply |
| `+` (43) | Private, read |
| `-` (45) | Private, unread |
| `~` (126) | Comment to sysop |
| `%` (37) | Sender requested receipt |
| `^` (94) | Private, has reply |
| `#` (35) | Group message |

For generated packets:
- Echomail: Use ` ` (public)
- Netmail: Use `-` (private, unread) or `+` (private, read)

---

## Security Considerations

1. **Authentication**: All QWK endpoints require user authentication
2. **Authorization**: Users can only download their own netmail and subscribed areas
3. **File Validation**: REP uploads validated for correct format and BBS ID
4. **Size Limits**: Maximum upload size enforced (configurable, default 10MB)
5. **Rate Limiting**: Prevent abuse through download frequency limits
6. **Input Sanitization**: All message content from REP packets sanitized before storage
7. **Filename Sanitization**: BBS ID limited to 8 alphanumeric characters
8. **CSRF Protection**: Upload form includes CSRF token

---

## Configuration Options

### Environment Variables

Add to `.env`:

```
# QWK Configuration
QWK_BBS_ID=BINKTERM
QWK_BBS_NAME=BinktermPHP BBS
QWK_BBS_LOCATION=Internet
QWK_SYSOP_NAME=Sysop
QWK_MAX_PACKET_SIZE=5242880
QWK_DEFAULT_MAX_MESSAGES=500
```

### Per-User Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `qwk_enabled` | boolean | false | Enable QWK for user |
| `qwk_max_messages` | integer | 500 | Max messages per packet |
| `qwk_include_netmail` | boolean | true | Include netmail in packets |
| `qwk_archive_name` | string | null | Custom packet filename |

---

## Error Handling

### Download Errors

| Error | Response |
|-------|----------|
| Not authenticated | 401 - Redirect to login |
| QWK not enabled | 403 - "QWK downloads not enabled for your account" |
| No new messages | 200 - Empty packet with status message |
| Generation failed | 500 - Error message with details |

### Upload Errors

| Error | Response |
|-------|----------|
| Invalid file type | 400 - "Invalid file format - expected .REP file" |
| Wrong BBS ID | 400 - "This reply packet is not for this BBS" |
| Parse error | 400 - "Could not parse reply packet: [details]" |
| Message rejected | 200 - Partial success with rejection reasons |

---

## Testing Plan

### Unit Tests

1. **QwkPacketGenerator**
   - Test message packing to 128-byte format
   - Test CONTROL.DAT generation
   - Test NDX file generation
   - Test character encoding conversion
   - Test empty packet generation

2. **RepPacketParser**
   - Test message unpacking
   - Test BBS ID validation
   - Test malformed packet handling
   - Test conference number mapping

### Integration Tests

1. Download packet with echomail only
2. Download packet with netmail only
3. Download packet with both
4. Upload REP with echomail replies
5. Upload REP with netmail replies
6. Verify pointer updates after download
7. Verify pointer reset functionality

### Manual Testing

1. Generate QWK packet and open in MultiMail
2. Generate QWK packet and open in BlueWave (DOSBox)
3. Write replies in reader and upload REP
4. Verify replies appear correctly in web interface
5. Test with various character encodings (CP437 special chars)

---

## Compatibility Notes

### Tested Mail Readers

| Reader | Platform | Status |
|--------|----------|--------|
| MultiMail | Linux/Windows/macOS | Primary target |
| BlueWave | DOS (DOSBox) | Should work |
| OLX | DOS | Should work |
| SLMR | DOS | Should work |
| Offline Xpress | Windows | Should work |

### Known Limitations

1. **Extended QWK**: Some readers support extended QWK features (QWKE) - not implemented in initial version
2. **Long Subjects**: QWK limits subjects to 25 characters - longer subjects truncated
3. **Unicode**: QWK uses CP437 - Unicode characters converted or replaced
4. **Attachments**: File attachments not supported in QWK format
5. **Message IDs**: QWK uses sequential numbers - FidoNet MSGID not preserved in packet

---

## Future Enhancements

### User Offline Mail
- **QWKE Support**: Extended QWK format for longer fields
- **Blue Wave Format**: Alternative packet format support
- **OMEN Format**: Another popular offline format
- **Scheduled Downloads**: Automatic packet generation at intervals
- **Email Delivery**: Option to email QWK packets to users
- **Area Selection**: Per-download area selection instead of all subscribed
- **Compression Options**: Support for different archive formats (ZIP, ARJ, LHA)
- **Tagline Database**: User-configurable taglines for replies
- **Offline File Requests**: Support for file area browsing in packets

### QWK Networking
- **Multi-Hub Support**: Connect to multiple hubs for the same network (redundancy)
- **Hierarchical Networks**: Support for hub-to-hub topology
- **Network Statistics Dashboard**: Message volume, activity graphs
- **Auto-Discovery**: Detect available conferences automatically
- **Conflict Resolution**: Handle message ID collisions between networks
- **Cross-Network Gating**: Forward messages between QWK and FidoNet networks
- **Webhook Notifications**: Alert on sync failures or new network messages

---

## QWK Networking (Inter-BBS Communication)

Beyond user offline mail, QWK packets can serve as a transport mechanism for joining message networks like **DoveNet**, **FsxNet**, **AgoraNet**, and others. This section covers using QWK for inter-BBS message exchange.

### Overview of QWK Networking

QWK networking allows BBSs to exchange messages without requiring FidoNet-style addressing or binkp connectivity. Instead:

1. **Hub BBS** maintains the master message base for each conference
2. **Member BBSs** (nodes) periodically fetch QWK packets from the hub
3. **Member BBSs** upload REP packets containing local replies back to the hub
4. The hub redistributes new messages to all members in the next sync cycle

This model is simpler than FidoNet echomail but trades real-time delivery for ease of setup.

### Network Topology

```
                    ┌─────────────┐
                    │   Hub BBS   │
                    │  (DoveNet)  │
                    └──────┬──────┘
                           │
           ┌───────────────┼───────────────┐
           │               │               │
           ▼               ▼               ▼
    ┌────────────┐  ┌────────────┐  ┌────────────┐
    │  Node BBS  │  │  Node BBS  │  │  Node BBS  │
    │ (Your BBS) │  │            │  │            │
    └────────────┘  └────────────┘  └────────────┘
```

### Popular QWK Networks

| Network | Hub | Description |
|---------|-----|-------------|
| **DoveNet** | dove-bbs.com | Active modern BBS network |
| **FsxNet** | fsxnet.nz | FSX Network via QWK |
| **AgoraNet** | agoranet.org | Agora BBS Network |
| **RetroNet** | Various | Retro computing focused |
| **tqwNet** | tqw.net | The Quantum Wormhole Network |

### Database Schema for QWK Networks

**Additional migration:** `database/migrations/v1.7.0_add_qwk_support.sql`

```sql
-- QWK Networks configuration
CREATE TABLE IF NOT EXISTS qwk_networks (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    hub_url VARCHAR(255) NOT NULL,
    hub_type VARCHAR(20) NOT NULL DEFAULT 'http', -- http, ftp, sftp
    bbs_id VARCHAR(8) NOT NULL,
    username VARCHAR(50),
    password_encrypted TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(name)
);

-- Conference mapping between QWK network and local echoareas
CREATE TABLE IF NOT EXISTS qwk_network_conferences (
    id SERIAL PRIMARY KEY,
    network_id INTEGER NOT NULL REFERENCES qwk_networks(id) ON DELETE CASCADE,
    conference_number INTEGER NOT NULL,
    conference_name VARCHAR(50) NOT NULL,
    echoarea_id INTEGER REFERENCES echoareas(id) ON DELETE SET NULL,
    is_subscribed BOOLEAN DEFAULT FALSE,
    last_message_num INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(network_id, conference_number)
);

-- QWK network sync history
CREATE TABLE IF NOT EXISTS qwk_network_sync_log (
    id SERIAL PRIMARY KEY,
    network_id INTEGER NOT NULL REFERENCES qwk_networks(id) ON DELETE CASCADE,
    sync_type VARCHAR(10) NOT NULL, -- 'download' or 'upload'
    packet_name VARCHAR(255),
    messages_received INTEGER DEFAULT 0,
    messages_sent INTEGER DEFAULT 0,
    status VARCHAR(20) NOT NULL, -- 'success', 'error', 'partial'
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP
);

-- Outbound message queue for QWK networks
CREATE TABLE IF NOT EXISTS qwk_outbound_queue (
    id SERIAL PRIMARY KEY,
    network_id INTEGER NOT NULL REFERENCES qwk_networks(id) ON DELETE CASCADE,
    conference_number INTEGER NOT NULL,
    echomail_id INTEGER REFERENCES echomail(id) ON DELETE CASCADE,
    status VARCHAR(20) DEFAULT 'pending', -- pending, sent, error
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_qwk_networks_active ON qwk_networks(is_active);
CREATE INDEX IF NOT EXISTS idx_qwk_network_conferences_network ON qwk_network_conferences(network_id);
CREATE INDEX IF NOT EXISTS idx_qwk_outbound_queue_pending ON qwk_outbound_queue(network_id, status);
```

### QWK Network Service

**New File:** `src/Qwk/QwkNetworkService.php`

```php
<?php
namespace BinktermPHP\Qwk;

class QwkNetworkService
{
    // Network management
    public function addNetwork(array $config): int
    public function updateNetwork(int $networkId, array $config): bool
    public function removeNetwork(int $networkId): bool
    public function getNetworks(): array

    // Synchronization
    public function syncNetwork(int $networkId): array
    public function downloadPacket(int $networkId): string
    public function uploadReplies(int $networkId): bool

    // Conference management
    public function fetchConferenceList(int $networkId): array
    public function subscribeConference(int $networkId, int $confNum, int $echoareaId): bool
    public function unsubscribeConference(int $networkId, int $confNum): bool

    // Message processing
    public function importMessages(int $networkId, string $packetPath): int
    public function exportReplies(int $networkId): string
    public function queueOutboundMessage(int $networkId, int $echoMailId): bool
}
```

### Hub Connection Methods

QWK networks use various transfer methods:

| Method | Description | Implementation |
|--------|-------------|----------------|
| **HTTP/HTTPS** | Web-based packet exchange | cURL with authentication |
| **FTP/FTPS** | Traditional FTP transfer | PHP FTP functions |
| **SFTP** | Secure FTP via SSH | phpseclib library |
| **Direct Download** | Manual URL-based download | cURL GET request |

#### HTTP-Based Hub Example (DoveNet style)

```php
class HttpQwkHub
{
    public function downloadPacket(string $hubUrl, string $bbsId, string $password): string
    {
        // POST to hub with credentials
        // Receive QWK packet
        // Return local path to downloaded file
    }

    public function uploadReplies(string $hubUrl, string $repPath, string $bbsId, string $password): bool
    {
        // POST REP file to hub
        // Return success/failure
    }
}
```

### Conference-to-Echoarea Mapping

When joining a QWK network, conferences must be mapped to local echo areas:

1. **Fetch conference list** from hub's CONTROL.DAT
2. **Create local echo areas** for subscribed conferences (or map to existing)
3. **Store mapping** in `qwk_network_conferences` table
4. **Route messages** based on mapping during import/export

Example mapping:

| Network Conference | Conf # | Local Echoarea |
|--------------------|--------|----------------|
| DOVE-GENERAL | 1 | DOVE.GENERAL |
| DOVE-SYSOPS | 2 | DOVE.SYSOPS |
| DOVE-POLITICS | 3 | (not subscribed) |
| DOVE-TECH | 4 | DOVE.TECH |

### Automatic Polling

**New File:** `src/Qwk/QwkNetworkPoller.php`

```php
<?php
namespace BinktermPHP\Qwk;

class QwkNetworkPoller
{
    // Called by cron job or scheduler
    public function pollAllNetworks(): array
    {
        $results = [];
        foreach ($this->getActiveNetworks() as $network) {
            $results[$network['id']] = $this->pollNetwork($network);
        }
        return $results;
    }

    public function pollNetwork(array $network): array
    {
        // 1. Download QWK packet from hub
        // 2. Import new messages to local areas
        // 3. Generate REP with local replies
        // 4. Upload REP to hub
        // 5. Update sync pointers
        // 6. Log results
    }
}
```

**Cron job example:**

```bash
# Poll QWK networks every 15 minutes
*/15 * * * * php /path/to/binkterm/scripts/qwk_poll.php
```

**New File:** `scripts/qwk_poll.php`

```php
#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Qwk\QwkNetworkPoller;

$poller = new QwkNetworkPoller();
$results = $poller->pollAllNetworks();

foreach ($results as $networkId => $result) {
    echo "[Network $networkId] ";
    echo "Downloaded: {$result['messages_received']}, ";
    echo "Uploaded: {$result['messages_sent']}, ";
    echo "Status: {$result['status']}\n";
}
```

### Admin Interface for QWK Networks

**New File:** `templates/admin/qwk_networks.twig`

Admin page sections:

1. **Network List**
   - Name, hub URL, status, last sync time
   - Enable/disable toggle
   - Edit/delete buttons

2. **Add Network Form**
   - Network name
   - Hub URL
   - Connection type (HTTP/FTP/SFTP)
   - BBS ID (assigned by hub)
   - Credentials

3. **Conference Management** (per network)
   - Available conferences from hub
   - Subscribe/unsubscribe checkboxes
   - Map to local echoarea dropdown
   - Create new echoarea option

4. **Sync Controls**
   - Manual "Sync Now" button
   - View sync history/logs
   - Reset pointers

### API Routes for QWK Networks

**File:** `routes/api-routes.php`

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/admin/qwk-networks` | GET | List all QWK networks |
| `/api/admin/qwk-networks` | POST | Add new QWK network |
| `/api/admin/qwk-networks/{id}` | PUT | Update network config |
| `/api/admin/qwk-networks/{id}` | DELETE | Remove network |
| `/api/admin/qwk-networks/{id}/sync` | POST | Trigger manual sync |
| `/api/admin/qwk-networks/{id}/conferences` | GET | Get conference list |
| `/api/admin/qwk-networks/{id}/conferences` | PUT | Update subscriptions |
| `/api/admin/qwk-networks/{id}/logs` | GET | Get sync history |

### Message Origin and Routing

When importing messages from a QWK network:

1. **Preserve origin**: Store network source in message metadata
2. **Set origin line**: `* Origin: BBS Name (QWK via HubName)`
3. **Track source**: Prevent re-exporting messages back to source network

When exporting replies:

1. **Filter by origin**: Only export locally-created messages
2. **Set proper headers**: Use network's expected format
3. **Conference mapping**: Convert local echoarea to network conference number

### Integration with Existing Multi-Network Support

This QWK networking feature complements the planned FidoNet multi-network support:

| Feature | FidoNet (binkp) | QWK Networks |
|---------|-----------------|--------------|
| Transport | binkp protocol | HTTP/FTP file transfer |
| Addressing | Zone:Net/Node.Point | BBS ID + Conference # |
| Real-time | Yes (polling) | No (batch) |
| Netmail | Yes | Limited (some networks) |
| Setup complexity | Higher | Lower |

Both can coexist:
- FidoNet areas stored with `network_id` referencing `networks` table
- QWK areas stored with `qwk_network_id` referencing `qwk_networks` table
- UI clearly distinguishes network type

### DoveNet Integration Example

DoveNet is one of the most active QWK networks. Here's how to join:

1. **Request membership** at dove-bbs.com
2. **Receive credentials**: BBS ID (e.g., "BINKTERM") and password
3. **Configure in BinktermPHP**:
   ```
   Network Name: DoveNet
   Hub URL: https://dove-bbs.com/qwk/
   BBS ID: BINKTERM
   Username: binkterm
   Password: ********
   ```
4. **Subscribe to conferences**: DOVE-GENERAL, DOVE-SYSOPS, etc.
5. **Enable automatic polling**: Set cron to run every 15-30 minutes
6. **Messages flow**: New posts appear in local echoareas, replies sync back to hub

### Security Considerations for QWK Networking

1. **Credential Storage**: Hub passwords stored encrypted in database
2. **HTTPS Preferred**: Always use HTTPS when available for hub connections
3. **Input Validation**: Sanitize all imported message content
4. **Rate Limiting**: Respect hub's polling frequency requirements
5. **Duplicate Detection**: Prevent duplicate message imports via message ID tracking
6. **Admin Only**: Network configuration restricted to admin users
7. **Audit Logging**: All sync operations logged for troubleshooting

### QWK Network Files Summary

| File | Purpose |
|------|---------|
| `src/Qwk/QwkNetworkService.php` | Network management and sync logic |
| `src/Qwk/QwkNetworkPoller.php` | Automated polling service |
| `src/Qwk/HttpQwkHub.php` | HTTP-based hub connector |
| `src/Qwk/FtpQwkHub.php` | FTP-based hub connector |
| `scripts/qwk_poll.php` | CLI script for cron-based polling |
| `templates/admin/qwk_networks.twig` | Admin network management page |

---

## References

- [QWK Format Specification](http://wiki.synchro.net/ref:qwk)
- [Synchronet QWK Documentation](https://wiki.synchro.net/module:qwk)
- [MultiMail Offline Reader](http://multimail.sourceforge.net/)
- [FidoNet Technical Standards](http://ftsc.org/)
- [DoveNet Information](https://dove-bbs.com/)
- [FsxNet QWK Setup](https://fsxnet.nz/qwk.html)
- [BBS Corner Network List](https://www.telnetbbsguide.com/network/)

---

## Verification Steps

### User Offline Mail

1. Run migration: `psql -f database/migrations/v1.7.0_add_qwk_support.sql`
2. Enable QWK in Settings page
3. Subscribe to at least one echo area
4. Click "Download QWK Packet"
5. Verify ZIP contains CONTROL.DAT, MESSAGES.DAT, *.NDX files
6. Open packet in MultiMail - verify messages display correctly
7. Write a reply in MultiMail and save REP packet
8. Upload REP packet via web interface
9. Verify reply appears in echomail area
10. Download another QWK packet - verify only new messages included
11. Test "Reset Pointers" - verify all messages included in next download

### QWK Networking (Inter-BBS)

1. Navigate to Admin → QWK Networks
2. Add a new network (e.g., DoveNet test hub or local test hub)
3. Enter hub URL, BBS ID, and credentials
4. Click "Fetch Conferences" - verify conference list loads
5. Subscribe to at least 2 conferences and map to local echoareas
6. Click "Sync Now" - verify messages are downloaded
7. Check local echoarea - verify imported messages appear with correct origin
8. Post a reply in the local echoarea
9. Click "Sync Now" again - verify reply is uploaded (check sync log)
10. Verify reply does not re-import on next sync (duplicate detection)
11. Set up cron job: `*/15 * * * * php scripts/qwk_poll.php`
12. Wait for automatic sync - verify messages flow without manual intervention
13. Test error handling: disable network connectivity and verify graceful failure
14. Check sync logs for complete audit trail
