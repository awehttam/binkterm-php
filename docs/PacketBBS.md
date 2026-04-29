# PacketBBS Gateway

PacketBBS is BinktermPHP's compact text gateway for PacketBBS, MeshCore, and similar packet radio or mesh text bridges. It exposes BBS mail functions through short command/response messages instead of a full-screen terminal UI.

The gateway is designed for low-bandwidth radio links:

- short ASCII responses
- compact message lists
- one-line commands
- paged output with `M` / `MORE` and `P` / `PREV`
- compose mode that accepts one body line per packet

PacketBBS is not a web frontend and is not an ANSI terminal shell. A separate radio bridge sends HTTP requests to BinktermPHP and relays the plain-text response back to the radio network.

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
| Node ID | The bridge device ID. For MeshCore this is commonly the bridge node hash/ID. |
| Handle / Callsign | Optional sysop-facing label for the node. |
| Interface Type | Output profile. Currently `meshcore` is the normal choice. |
| Link to BBS Account | Optional account link. Leave blank for normal `LOGIN <user> <code>` flow. |

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

PacketBBS is intentionally terse. Send `HELP` first:

```text
HELP
```

Typical response:

```text
HELP: LOGIN, WHO, MAIL, AREAS
R <id>, RP <id>, M, P, Q
More: HELP MAIL, HELP AREAS
```

### Login

```text
LOGIN <username> <6-digit-code>
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

Lists users currently online. Depending on sysop configuration, this may be available before login.

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

Compatibility aliases:

```text
READ 12
NR 12
```

Reply:

```text
RP 12
```

Compatibility aliases:

```text
REPLY 12
NRP 12
```

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

Alias:

```text
E
```

Networked areas may appear as `TAG@domain`, for example:

```text
LVLY_TEST@lovlynet Test echo area
```

List messages in an area:

```text
AREA LVLY_TEST@lovlynet
```

Aliases:

```text
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

Reply:

```text
RP 44
```

Post a new echomail message:

```text
POST <tag> <subject>
```

Example:

```text
POST LVLY_TEST@lovlynet Testing radio post
```

Compatibility alias:

```text
EP LVLY_TEST@lovlynet Testing radio post
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

Then send one body line per radio message:

```text
I can join tonight.
```

PacketBBS responds:

```text
OK
```

Finish:

```text
/SEND
```

Old-style `.` also sends:

```text
.
```

Cancel:

```text
/CANCEL
```

Old-style `CANCEL` also cancels.

### Paging

Paging applies to two things: message lists and long message bodies.

#### Lists

If a list has more pages, the footer shows:

```text
R <id>, M
```

#### Long messages

If a message body exceeds 120 characters, it is split into pages. The first page shows a progress footer:

```text
1/3 M:more
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
P
```

Alias:

```text
PREV
```

`P` on the first page returns:

```text
Already at first page.
```

`M` past the last page of a list returns:

```text
End.
```

### Quit

```text
Q
```

Alias:

```text
QUIT
```

This clears the PacketBBS session.

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
- linked account, if configured
- last-seen time
- active PacketBBS sessions
- outbound queue entries

Common operations:

- Generate/regenerate API key for a node.
- Edit node handle/interface/account link.
- Delete a node.
- Kill a stuck session.
- Flush unsent outbound queue entries.

Regenerating a node API key invalidates the old bridge key immediately.

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
