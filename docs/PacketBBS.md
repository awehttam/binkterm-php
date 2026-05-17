# PacketBBS Gateway

## Table of Contents

- [Architecture](#architecture)
- [Bridge Adapters](#bridge-adapters)
  - [Bridge Node vs Sender Node](#bridge-node-vs-sender-node)
- [Workflow: how PacketBBS fits into low-bandwidth access](#workflow-how-packetbbs-fits-into-low-bandwidth-access)
- [Sysop Setup](#sysop-setup)
  - [1. Configure PacketBBS Defaults](#1-configure-packetbbs-defaults)
  - [2. Register a Bridge Node](#2-register-a-bridge-node)
  - [3. Configure the Bridge](#3-configure-the-bridge)
- [User Enrollment](#user-enrollment)
- [End-User Command Guide](#end-user-command-guide)
  - [Session Context](#session-context)
  - [Command Tables](#command-tables)
  - [Login](#login)
  - [Online Users](#online-users)
  - [Status](#status)
  - [Netmail](#netmail)
  - [Echomail](#echomail)
    - [Area Search](#area-search)
  - [Compose Mode](#compose-mode)
  - [Bulletins](#bulletins)
  - [Paging](#paging)
  - [Quit](#quit)
- [Output Profiles](#output-profiles)
- [Admin Operations](#admin-operations)
- [Public Node Directory](#public-node-directory)
- [MeshCore Companion Contacts](#meshcore-companion-contacts)
  - [How Contact Sync Works](#how-contact-sync-works)
  - [Contact Identifiers](#contact-identifiers)
  - [Admin Contact Manager](#admin-contact-manager)
    - [Editing a Contact](#editing-a-contact)
    - [Deleting Contacts](#deleting-contacts)
  - [User Radio Registration](#user-radio-registration)
  - [Companion Radio Association](#companion-radio-association)
  - [Device Auto-Add Policy](#device-auto-add-policy)
  - [Device Command Queue](#device-command-queue)
- [Troubleshooting](#troubleshooting)
  - [Unknown Bridge Node](#unknown-bridge-node)
  - [Unauthorized HTTP Response](#unauthorized-http-response)
  - [User Cannot Log In](#user-cannot-log-in)
  - [Echomail Post Goes to the Wrong Area or Fails](#echomail-post-goes-to-the-wrong-area-or-fails)
  - [Logs](#logs)
- [Related Systems](#related-systems)

PacketBBS is BinktermPHP's compact text gateway for PacketBBS, MeshCore, and similar packet radio or mesh text bridges. It exposes BBS mail functions through short command/response messages instead of a full-screen terminal UI.

The gateway is designed for low-bandwidth radio links:

- short ASCII responses
- compact message lists
- one-line commands
- paged output with `M` / `MORE` and `P` / `PREV`
- compose mode that accepts one body line per packet

PacketBBS is not a web frontend and is not an ANSI terminal shell. A separate radio bridge sends HTTP requests to BinktermPHP and relays the plain-text response back to the radio network.

That makes PacketBBS an access method, not a separate mini-BBS. It reaches into the same platform data as the browser UI and terminal services, but does so through terse command/response exchanges that fit radio and mesh conditions.

## Architecture

The bridge talks to BinktermPHP through:

```text
POST /api/packetbbs/command
GET  /api/packetbbs/pending
```

Every bridge request must include:

```text
Authorization: Bearer <node-api-key>
```

The API key belongs to a registered PacketBBS node in the admin UI. The key is stored server-side as a SHA-256 hash and is only shown once when generated.

## Bridge Adapters

PacketBBS requires a bridge adapter to connect a radio network to the BinktermPHP HTTP API.

| Adapter | Radio network | Repository | Status |
|---|---|---|---|
| MeshCore Bridge | MeshCore | [awehttam/binktermphp-meshcorebridge](https://github.com/awehttam/binktermphp-meshcorebridge) | Available |

### Bridge Node vs Sender Node

PacketBBS supports a bridge device serving more than one radio sender.

- `bridge_node_id` is the registered bridge device ID used for API-key authorization.
- `node_id` is the sender/session ID for the radio operator using the bridge.

If `bridge_node_id` is omitted, PacketBBS uses `node_id` for both authorization and the user session.

Sessions are keyed by `node_id`, so multiple radio users behind one bridge can have separate login and compose state as long as the bridge sends their distinct sender IDs.

## Workflow: how PacketBBS fits into low-bandwidth access

1. A bridge node receives a radio or mesh message from a remote operator.
2. The bridge translates that short command into an authenticated HTTP request to BinktermPHP.
3. PacketBBS reads or updates the same platform mail and session state used by the browser and terminal access methods.
4. BinktermPHP returns a compact plain-text reply sized for low-bandwidth transport.
5. The bridge relays that reply back across the packet or mesh network.

## Sysop Setup

### 1. Configure PacketBBS Defaults

PacketBBS defaults live under `packet_bbs` in `config/bbs.json` and are configurable from the **Admin → BBS Settings → Packet BBS Settings** card:

```json
{
  "packet_bbs": {
    "session_timeout_minutes": 15,
    "allow_guest_who": true
  }
}
```

Options:

| Option | Default | Meaning |
|---|---:|---|
| `session_timeout_minutes` | `15` | Inactive authenticated sessions are cleared after this many minutes. The next command returns `Session expired. LOGIN again.` |
| `allow_guest_who` | `true` | Allows unauthenticated users to run `WHO`. If false, `WHO` requires login. |

Login failures are rate-limited per sender node: 5 failed attempts in 10 minutes blocks further attempts briefly. Successful login clears prior failures.

### 2. Register a Bridge Node

Go to:

```text
Admin -> Packet BBS Nodes
```

Add a node:

| Field | Purpose |
|---|---|
| Node ID | The bridge device ID. For MeshCore this is the bridge node hash/ID. |
| Handle / Callsign | The node name as it appears in the MeshCore app. This value is used as the contact display name when the bridge QR-codes itself into another operator's contact list. Setting it to the BBS hostname is recommended. |
| Interface Type | Output profile. `MeshCore` is the only currently supported type. |
| Location Description | Optional free-text location label shown on the public node directory and dashboard widget (e.g. "Lower Mainland BC"). |
| Coordinates | Optional GPS coordinates used to place the node on the public node map. Not displayed directly to users. |

After creating the node, click the key button and generate an API key. Copy it immediately; it will not be shown again.

### 3. Configure the Bridge

> **Bridge developers:** this section is aimed at you. Sysops only need to supply the BBS URL and the API key generated in step 2.

Start with the bridge's configuration file. At minimum it needs the BBS URL and the API key for the registered node:

```json
{
  "bbs_url": "https://your-bbs.example",
  "api_key": "paste-the-key-generated-in-step-2-here"
}
```

The bridge uses these to authenticate its requests to BinktermPHP. Refer to the bridge's own documentation for the full list of configuration options.

## User Enrollment

Users must enable the PacketBBS authenticator before they can log in by radio.

Steps for the user:

1. Log in to the web UI.
2. Open `Settings -> Account`.
3. Find `PacketBBS Authenticator`.
4. Click `Set up authenticator`.
5. Scan the QR code with a TOTP authenticator app, or enter the secret manually.
6. Enter the 6-digit code to verify enrollment.

The authenticator issuer is:

```text
<BBS Name> - PacketBBS
```

Radio login uses TOTP codes, not the web password.

## End-User Command Guide

PacketBBS is intentionally terse. Send `HELP` or `H` first:

```text
H
```

Typical response:

```text
H: L username code | A areas | N mail
T tag | R id | Y id | EP post
M more | B back | U status | Q quit
```

### Session Context

PacketBBS keeps lightweight session context per sender node. That means:

- `AREA <tag>` or `T <tag>` makes that echoarea the current working area
- `POST` can reuse the current area instead of requiring the tag every time
- `REPLY` can reuse the current message when the target is unambiguous
- `M`, `P`, and `B` continue the current list or message
- guided flows like `POST` -> `Subj?` keep state until sent or cancelled

Example:

```text
AREA LVLY_TEST
POST
Subj?
Testing from radio
Msg:
hello from field
+
/SEND
Posted to LVLY_TEST.
```

### Command Tables

#### Global Context

| Full command | Short code | Description |
|---|---|---|
| `HELP` | `H` | Show general help or contextual help such as `H MAIL` or `H U`. |
| `HELPFULL` | `FULLHELP` or `HELPFUL` | Show verbose help with full command names. |
| `LOGIN <user> <code>` | `L <user> <code>` | Log in using PacketBBS TOTP. |
| `WHO` | `W` | Show who is online. |
| `STATUS` | `U` | Show current area, list, message, or draft state. |
| `BULLETINS` | `BU` | List active bulletins. `BU <id>` reads bulletin number `<id>`. |
| `QUIT` | `Q` | Context-aware: exits the current area if one is active, or ends the PacketBBS session from the top level. Use the full word `QUIT` to end the session unconditionally from anywhere. |
| `WEBSITE` | `WEB` | Show the BBS website URL. |

#### Area Navigation Context

| Full command | Short code | Description |
|---|---|---|
| `AREAS` | `A` or `E` | List subscribed areas. |
| `AREAS <search>` | `A <search>` | Search subscribed areas by tag, description, or domain. If the text resolves to a subscribed area, it opens that area instead. |
| `AREA <tag>` | `AREA <tag>` or `T <tag>` or `ER <tag>` | Open an area and make it the current working area. |
| `MORE` | `M` | Show the next page of the current list or message. |
| `PREV` | `B`, `P`, or `PREV` | Show the previous page of the current list or message. |

#### Netmail Context

| Full command | Short code | Description |
|---|---|---|
| `MAIL` | `N`, `NM`, or `NETMAIL` | List netmail. |
| `READ <id>` | `R <id>` or `NR <id>` | Read a netmail or current-list message by ID. `R` without an ID reopens the current message when one is active. |
| `REPLY <id>` | `Y <id>`, `RP <id>`, or `NRP <id>` | Reply to a specific message. `REPLY` without an ID replies to the current message when context is clear. |
| `SEND <user> <subject>` | `S <user> <subject>` or `NS <user> <subject>` | Start a new netmail draft in one step. |

#### Echomail / Current Area Context

| Full command | Short code | Description |
|---|---|---|
| `AREA <tag>` | `T <tag>` or `ER <tag>` | Open an area and set area context. |
| `READ <id>` | `R <id>` | Read an echomail message by ID. `R` without an ID reopens the current message when one is active. |
| `REPLY <id>` | `Y <id>` or `RP <id>` | Reply to a message by ID, or to the current message when no ID is given and context is clear. |
| `POST <tag> <subject>` | `EP <tag> <subject>` | Start a post in an explicitly named area. |
| `POST <subject>` | `EP <subject>` | Start a post in the current area using the whole remainder as the subject. |
| `POST` | `EP` | Start a guided post flow in the current area and prompt for subject. |

#### Guided Flow Context

| Full command | Short code | Description |
|---|---|---|
| `/SEND` | `.` or `/S` | Send the current draft. |
| `/CANCEL` | `CANCEL` or `/C` | Cancel the current draft or guided flow. |
| `STATUS` | `U` | Show current draft target, subject, and body progress. |

### Login

```text
LOGIN <username> <6-digit-code>
```

Short form:

```text
L <username> <6-digit-code>
```

Example:

```text
LOGIN alice 123456
```

Success:

```text
Hi alice. HELP for commands.
```

If the session is idle too long, log in again.

### Online Users

```text
WHO
```

Short form:

```text
W
```

Lists users currently online. Depending on sysop configuration, this may be available before login.

### Status

```text
U
```

Shows the current working context. Example responses:

```text
area LVLY_TEST
list echomail p1/3
```

or:

```text
draft post LVLY_TEST
subj Testing from radio
2 lines
```

### Netmail

List netmail:

```text
MAIL
```

Aliases:

```text
N
NM
NETMAIL
```

Example response:

```text
MAIL 1/3
*12 Bob Re: Meeting
 15 Alice Files
R <id>, M
```

`*` means unread.

Read a message:

```text
R 12
```

If a current message is already open, you can also send:

```text
R
```

to reopen that message from the top.

The same replay behavior applies to `NR` and `EM` when they are used without an ID and a current message is active.

Compatibility aliases:

```text
READ 12
NR 12
```

Reply:

```text
REPLY 12
```

Compatibility aliases:

```text
RP 12
NRP 12
Y 12
```

If a message is already open, `REPLY` without an ID uses the current message.

Start new netmail:

```text
SEND <user> <subject>
```

Compatibility aliases:

```text
S <user> <subject>
NS <user> <subject>
```

### Echomail

List subscribed areas:

```text
AREAS
```

Aliases:

```text
A
E
```

Example response:

```text
AREAS 1/3
FIDONET General Fidonet discussion
LVLY_CHAT@lovlynet Chat
LVLY_TEST@lovlynet Test echo area
AREA <tag>, M
```

The header shows the current page and total pages. If there are more pages, the footer shows `AREA <tag>, M`. Use `M` and `P` or `B` to navigate pages.

Networked areas appear as `TAG@domain`. Use `M` to page through all subscribed areas.

#### Area Search

To search for areas matching a keyword across name, description, and domain:

```text
AREAS linux
```

Example response:

```text
AREAS "linux" 1/1
LINUX General Linux discussion
LVLY_LINUX@lovlynet Linux users
AREA <tag>
```

The search term is preserved across `M` / `P` pages. To return to the full list, send `AREAS` without arguments.

List messages in an area:

```text
AREA LVLY_TEST@lovlynet
```

Aliases:

```text
T LVLY_TEST@lovlynet
ER LVLY_TEST@lovlynet
```

If the tag is unique for the user, the domain may be omitted:

```text
AREA LVLY_TEST
```

Read an echomail message:

```text
R 44
```

`EM` behaves the same way as `R` for the current message: if you already have a message open, `EM` without an ID reopens it from the top.

Reply:

```text
REPLY 44
```

Post a new echomail message with an explicit area:

```text
POST LVLY_TEST@lovlynet Testing radio post
```

Compatibility alias:

```text
EP LVLY_TEST@lovlynet Testing radio post
```

If you already opened an area, PacketBBS remembers it. These are valid too:

```text
AREA LVLY_TEST
POST Testing radio post
```

or guided:

```text
AREA LVLY_TEST
POST
Subj?
Testing radio post
Msg:
hello from field
```

### Compose Mode

Replying, sending netmail, and posting echomail enter compose mode.

Example:

```text
RP 12
Replying to Bob.
Subj: Re: Meeting
Send lines. /SEND=send /CANCEL=abort
```

Guided `POST` can also prompt for the subject first:

```text
POST
Subj?
Testing from field
Msg:
```

Then send one body line per radio message:

```text
I can join tonight.
```

PacketBBS responds:

```text
OK
```

Guided post flows reply with a shorter acknowledgement while the body is in progress:

```text
+
```

Finish:

```text
/SEND
```

Short form:

```text
/S
```

Old-style `.` also sends:

```text
.
```

Cancel:

```text
/CANCEL
```

Short form:

```text
/C
```

Old-style `CANCEL` also cancels.

### Bulletins

When you log in, any unread bulletins are listed automatically:

```text
Hi alice. HELP for commands.
2 unread bulletins:
#1 Welcome to the BBS
#3 Maintenance window tonight
BU to read
```

List all active bulletins at any time:

```text
BU
```

Alias:

```text
BULLETINS
```

Example response:

```text
BULLETINS 3
*#1 Welcome to the BBS
 #2 Previous announcement
*#3 Maintenance window tonight
BU # to read
```

`*` marks bulletins you have not yet read.

Read a specific bulletin:

```text
BU 1
```

Example response:

```text
#1 Welcome to the BBS
Welcome! This BBS is...
BU for list
```

Reading a bulletin marks it as read for your account. The `*` will disappear from the list on your next `BU`.

### Paging

Paging applies to three things: the area list, message lists, and long message bodies.

#### Lists

If a list has more pages, the footer shows:

```text
R <id>, M:more B:back
```

#### Long messages

If a message body exceeds 120 characters, it is split into pages. The first page shows a progress footer:

```text
1/3 M:more B:back
```

Subsequent pages show the same until the last page, which shows the normal reply prompt.

#### Navigation

Move forward one page:

```text
M
```

Alias:

```text
MORE
```

Move back one page:

```text
B
```

Compatibility aliases:

```text
P
PREV
```

`B` or `P` on the first page returns:

```text
Already at first page.
```

`M` past the last page of a list returns:

```text
End.
```

### Quit

`Q` is context-aware:

- **Inside an area:** exits the area and returns to the main context. Your session stays active.
- **At the top level:** ends the PacketBBS session and clears state.

```text
Q
```

To end the session unconditionally from anywhere, regardless of what area you are in, use the full word:

```text
QUIT
```

## Output Profiles

The `interface` request field controls line width and page size:

| Interface | List page size | Msg page size | Width | Intended use |
|---|---:|---:|---:|---|
| `meshcore` | 5 | 4 | 42 | Default compact radio text. |
| `meshtastic` | 4 | 3 | 34 | Smaller packets and narrower displays. |
| `tnc` | 8 | 8 | 64 | Larger text frames. |

Unknown interface values fall back to the MeshCore profile.

## Admin Operations

The admin Packet BBS page shows:

- registered bridge nodes
- whether each node has an API key
- last-seen time
- active PacketBBS sessions
- outbound queue entries

Common operations:

- Generate/regenerate API key for a node.
- Edit node handle, location description, coordinates, or interface type.
- Set the auto-add contact policy for MeshCore nodes.
- Delete a node.
- Kill a stuck session.
- Flush unsent outbound queue entries.

Regenerating a node API key invalidates the old bridge key immediately.

The node edit modal uses a two-column layout. Left column: identity and location fields. Right column: Auto-Add Contact Policy (MeshCore nodes only). The auto-add policy is pushed to the device only when the bitmask changes — saving other fields without touching the policy does not queue a device command.

## Public Node Directory

Registered PacketBBS nodes are publicly listed at `/packetbbs-nodes`. No login is required to view the page.

The page shows:

- an interactive map with a marker for every node that has GPS coordinates set
- a sortable table listing all registered nodes by handle, interface type, and location description
- a node info modal (handle, location description, coordinates with a copy button, public key with a copy button, and QR code)

Linking to a specific node info modal uses the URL hash `#node-{id}`:

```text
https://your-bbs.example/packetbbs-nodes#node-42
```

The dashboard includes a **PacketBBS Nodes** card in the sidebar. It lists registered nodes with their handle and location description. Clicking a node name follows the `#node-{id}` link and opens the info modal automatically.

### Adding Location Data

To make nodes useful in the public directory, fill in the **Location Description** and optionally the **Coordinates** fields when registering or editing a node in **Admin → Packet BBS Nodes**:

- **Location Description** — human-readable label shown in the node table and dashboard (e.g. "Lower Mainland BC", "Mt. Baker repeater site").
- **Coordinates** — used to place the node on the map. Not displayed directly on the page; users see the location description instead.

## MeshCore Companion Contacts

MeshCore radio devices maintain a local contact list (the "companion" list) of nodes they have heard or been manually told about. BinktermPHP mirrors this list into the `meshcore_contacts` database table so sysops and users can associate radio contacts with BBS accounts and manage them from the web interface.

### How Contact Sync Works

At startup, the bridge sends `CMD_GET_CONTACTS` to the radio immediately after the handshake completes. The radio responds with its full contact list (`CONTACT_START` → N × `CONTACT` → `CONTACT_END`). The bridge reports each contact to the BBS via:

```text
POST /api/meshcore/contact
Authorization: Bearer <node-api-key>
```

During normal operation, the bridge also reports contacts pushed by the radio in real time (for example, when a new node is heard and automatically added to the companion list).

The BBS upserts on the contact's full 64-character public key. If a user has already pre-registered a contact by its 12-character prefix (see [User Radio Registration](#user-radio-registration) below), the incoming full-key report claims that row and fills in the complete key.

### Contact Identifiers

Each MeshCore contact has two key identifiers:

| Field | Length | Description |
|---|---|---|
| Node ID prefix | 12 hex chars | The first 6 bytes of the public key, shown in the MeshCore app |
| Full public key | 64 hex chars | The complete 32-byte public key; globally unique |

Two contacts can share the same 12-character prefix (the prefix space is 2^48, collisions are possible). Uniqueness is enforced only on the full key. The prefix is used for display and initial lookup; the full key is used for identity and for sending remove commands to the device.

### Admin Contact Manager

On the **Admin → Packet BBS Nodes** page, each MeshCore node row has a contacts button (address book icon). Clicking it opens the Contact Manager for that node.

The Contact Manager shows all contacts synced from that bridge node:

| Column | Description |
|---|---|
| Node ID (prefix) | 12-char hex prefix; hover for full key tooltip when known |
| Name | Display name, either from the radio or set by the sysop |
| Type | Advertisement type reported by the radio (chat, repeater, etc.) |
| Owner | BBS user account linked to this radio contact |
| Location | GPS coordinates, if broadcast by the contact |
| Last Seen | Timestamp of the most recent bridge sync |

#### Editing a Contact

Click the edit button on any row to open the edit modal. Fields:

- **Display Name** — overrides the name broadcast by the radio; leave blank to use the radio name.
- **Owner** — BBS user account to associate with this radio node. Uses the same username autocomplete as the Auto Feed Manager. Defaults to the currently logged-in sysop.
- **Notes** — free-form admin notes.

#### Deleting Contacts

**Single delete:** click the trash icon on any row and confirm.

**Bulk delete:** check one or more rows (or use the Select All checkbox in the header), then click **Delete Selected** in the modal footer. A single request deletes all selected contacts at once.

When a contact has both a known bridge node and a full public key, deletion queues a `remove_contact` device command. The bridge picks up this command on its next poll interval and sends the remove command to the radio, removing the contact from the device's companion list as well.

Contacts that only have a 12-character prefix (no full key) are deleted from the BBS database only — the device cannot be told to remove them because the full key needed to address the command is not known.

### User Radio Registration

Users can register their own MeshCore radio node under **Settings → MeshCore Radio**. This creates a pre-registration row in `meshcore_contacts` owned by that user.

Registration accepts either:

- **12-character node ID** — the prefix shown in the MeshCore app. The full key will be filled in automatically when the bridge next reports this contact.
- **64-character public key** — the full key. Preferred if known, as it matches immediately without waiting for a bridge sync.

Once the bridge reports a contact whose prefix matches a user's pre-registered row (and the full key is not yet known), the row is claimed and updated. The user's BBS account becomes the owner of that radio contact.

Users can rename or delete their registered radios from the same settings tab. Deleting a user-registered contact follows the same device command queue logic as admin deletion.

### Companion Radio Association

When a user registers a radio contact under **Settings → MeshCore Radio**, the registration form includes a **Companion Radio** selector. The user picks which bridge device should relay messages between the BBS and that contact.

Selecting a companion radio does two things:

1. The contact record is linked to that bridge node so the BBS knows which device is responsible for it.
2. If the full 64-character public key is already known at registration time, an `add_contact` device command is queued immediately. The bridge picks up the command on its next poll and sends `CMD_ADD_UPDATE_CONTACT` to the radio, adding the contact to the device's companion list. This happens without requiring the operator to add the contact manually through the MeshCore app.

If a companion radio is later changed through the edit form, a fresh `add_contact` command is queued for the newly selected bridge.

Users who have no full public key yet (registered by 12-character prefix only) must wait for the bridge to report the full key before the device push can happen.

### Device Auto-Add Policy

MeshCore devices can be configured to automatically add nodes they hear over the air to their local contact list. By default this is often enabled for all node types, which can fill the contact list with repeaters and sensors the operator does not care about.

The **Admin → Packet BBS Nodes** node edit modal includes an **Auto-Add Contact Policy** section for MeshCore nodes. Individual checkboxes control each auto-add type:

| Checkbox | Bit | Notes |
|---|---|---|
| Auto-add companions (chat) | `0x02` | Covers companion radios running the MeshCore companion firmware |
| Auto-add repeaters | `0x04` | Recommended: off |
| Auto-add room servers | `0x08` | Recommended: off |
| Auto-add sensors | `0x10` | Recommended: off |
| Overwrite oldest when full | `0x01` | When the contact list is at capacity, replaces the oldest non-favourite entry |

Saving the node queues a `set_autoadd_config` device command **only when the bitmask changed** since the modal was opened. The bridge sends `CMD_SET_AUTOADD_CONFIG` to the radio on its next poll cycle; the setting takes effect immediately and persists across device restarts. Saving other node fields (handle, location, coordinates) without changing the policy does not trigger a device command.

**Read from Device:** clicking this button queues a `get_autoadd_config` command. The bridge reads the device's actual current value and reports it back to the BBS. Refresh the admin page after the bridge next polls to see the result. This is useful when the device was configured through another tool and the BBS record does not yet match the device state.

The `autoadd_config` bitmask is stored in the `autoadd_config` column of `packet_bbs_nodes`. A `NULL` value means the config has not yet been read from or written to the device.

### Device Command Queue

BinktermPHP records commands for the radio device in `meshcore_device_commands`. The bridge polls for pending commands on each poll cycle:

```text
GET /api/meshcore/pending-commands?bridge_node_id=<hex>
Authorization: Bearer <node-api-key>
```

After executing each command the bridge acknowledges it:

```text
POST /api/meshcore/commands/{id}/ack
Authorization: Bearer <node-api-key>
```

If the bridge is offline when a command is queued, it stays pending until the bridge reconnects and polls again.

Supported command types:

| Command type | Triggered by | Radio frame sent |
|---|---|---|
| `remove_contact` | Contact deleted by sysop or user | `CMD_REMOVE_CONTACT` |
| `add_contact` | User registers a contact with full public key | `CMD_ADD_UPDATE_CONTACT` |
| `set_autoadd_config` | Sysop saves auto-add policy in node edit modal | `CMD_SET_AUTOADD_CONFIG` |
| `get_autoadd_config` | Sysop clicks "Read from Device" in node edit modal | `CMD_GET_AUTOADD_CONFIG` |

When the radio responds to `CMD_GET_AUTOADD_CONFIG`, the bridge posts the result back to the BBS:

```text
POST /api/meshcore/autoadd-config
Authorization: Bearer <node-api-key>
```

The BBS stores the value in `packet_bbs_nodes.autoadd_config` so the admin panel can display the current device state without querying the radio on every page load.

## Troubleshooting

### Unknown Bridge Node

Radio response:

```text
This node is not registered with this BBS. Contact the sysop to be added.
```

Fix:

- Add the bridge node in `Admin -> Packet BBS Nodes`.
- Verify the bridge sends the correct `bridge_node_id` or, if omitted, the correct `node_id`.
- Verify the bearer token belongs to that node.

### Unauthorized HTTP Response

An HTTP `401 Unauthorized` means bridge authentication failed. The radio user usually should not see this as a BBS command response.

Fix:

- Confirm the bridge sends `Authorization: Bearer <key>`.
- Regenerate the node key and update the bridge configuration.
- Confirm the bridge is using the matching registered node ID.

### User Cannot Log In

Possible causes:

- User has not enrolled PacketBBS Authenticator in `Settings -> Account`.
- User entered an expired or incorrect TOTP code.
- Too many failed attempts were made from the sender node.
- The BBS account is inactive.

PacketBBS deliberately keeps login errors short and does not reveal which users have an authenticator enrolled.

### Echomail Post Goes to the Wrong Area or Fails

Use the exact area identifier shown by `AREAS`, especially for networked areas:

```text
POST LVLY_TEST@lovlynet Subject
```

If the area has no route or uplink, PacketBBS returns:

```text
No route for area. Ask sysop.
```

Check the echoarea domain, subscription, and uplink configuration.

### Logs

PacketBBS writes operational logs to:

```text
data/logs/packetbbs.log
```

This log includes command routing and high-level errors. TOTP codes are never logged.

## Related Systems

- [Architecture](ARCHITECTURE.md) — where PacketBBS sits among other access methods
- [Joining and Configuring an FTN](FTNGuide.md) — how network mail reaches the node
- [Echo Areas](EchoAreas.md) — the message areas PacketBBS users read and post into
- [QWK Offline Mail](QWK.md) — another compact, non-live access path
