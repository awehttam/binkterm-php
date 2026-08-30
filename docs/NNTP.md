# NNTP Server

BinktermPHP can expose its FTN echoareas as Usenet-style newsgroups over NNTP
(RFC 3977), so members can read echomail with any standard newsreader —
Thunderbird, and older readers such as slrn, tin or Forte Agent.

Reading works out of the box once the server is enabled. **Posting from a
newsreader is off by default** — turn on *Allow posting from newsreaders* in
**Admin → NNTP Server** to let members compose and reply from their client.

Each member also gets a private **netmail** newsgroup — a personal mail folder
inside the newsreader. See [Netmail newsgroup](#netmail-newsgroup) below.

## How it works

- Each active echoarea becomes a newsgroup named `<Network>.<AreaTag>` — for
  example `FidoNet.GENERAL` or `LovlyNet.LVLY_BINKTERMPHP`. The `<Network>` prefix
  is the network's display name (from **Admin → Networks**) with spaces and
  punctuation removed; local areas use the prefix `Local`.
- A member only sees the newsgroups for echoareas they are **subscribed** to
  (Messages → Subgroups, or the web subscription page). Two members can see
  different group lists.
- Members authenticate with their normal BinktermPHP username (or real name) and
  password.
- Message bodies are served as UTF-8. The original FTN kludges (`MSGID`, `CHRS`,
  `SEEN-BY`, `PATH`, …) are preserved as `X-FTN-*` headers.
- The daemon runs as its own process, `scripts/nntp_server.php`, separate from the
  web server.

## Enabling it

**Turn it on** in **Admin → NNTP Server**: set *Enable the NNTP server*. While it
is off the daemon answers every connection with a `400` error and closes; the
change takes effect on the next daemon restart.

**Set the transport** in `.env` (a daemon restart applies changes):

| Variable | Default | Meaning |
|---|---|---|
| `NNTP_BIND_HOST` | `0.0.0.0` | Address to bind |
| `NNTP_PORT` | `8119` | Plaintext + `STARTTLS` port |
| `NNTP_TLS_PORT` | `8563` | Implicit-TLS port; set empty to disable |
| `NNTP_TLS_CERT_PATH` | `data/nntp/server.crt` | PEM cert, or combined cert+key |
| `NNTP_TLS_KEY_PATH` | `data/nntp/server.key` | PEM private key |

The ports default to the unprivileged `8119` / `8563` so the daemon runs as an
ordinary user with no extra capabilities. Newsreaders expect NNTP on `119` and
`563` — see [Serving the standard ports](#serving-the-standard-ports-119--563)
below to redirect them. You can also just set `NNTP_PORT` / `NNTP_TLS_PORT` to
`119` / `563` if the daemon runs with permission to bind them.

If `NNTP_TLS_CERT_PATH` is left at the default and no file exists there, the
daemon generates a self-signed certificate on first start. Point
`NNTP_TLS_CERT_PATH` at a real certificate (e.g. the same one your web server
uses) for clients that reject self-signed certs. A path that is set but missing
is a fatal startup error.

**Start the daemon:**

```
scripts/restart_daemons.sh --start nntp_daemon
```

It is an optional daemon — `restart_daemons.sh` with no arguments only restarts it
if it was already running. On Windows it is not part of `start_daemons_windows.*`
and must be started by hand: `php scripts/nntp_server.php`.

In Docker, set `ENABLE_NNTP: "true"` in `docker-compose.override.yml` and uncomment
its port lines (`119:8119`, `563:8563` — the daemon listens on its default `8119` /
`8563` in the container and the mapping presents the standard ports to the
outside).

## Admin → NNTP Server settings

| Setting | Notes |
|---|---|
| Enable the NNTP server | Master switch. When off the daemon accepts connections but serves nothing. |
| Allow posting from newsreaders | Off by default. When on, authenticated members can `POST` new messages and replies. |
| Newsgroup name prefix | `Network display name` (default) or `Domain slug`. |
| Max connections per IP | Concurrent connections allowed from one source address (default 3). |
| Posts per minute / hour | Per-member posting rate limits; a post over the limit is rejected with `441`. Set to 0 to disable that limit. |
| Max cross-post areas | Most echoareas one article may target via a multi-group `Newsgroups:` header. An over-limit post is **rejected**, not trimmed. |
| Quote-style conversion | `Off`, `Outbound only` (default), or `Both directions`. See [Quote-style conversion](#quote-style-conversion) below. |
| Allow plaintext authentication on the plaintext port | **Off by default.** When off, a newsreader on the plaintext port must run `STARTTLS` before it can log in. Turn it on only for a legacy reader with no TLS support, accepting that its password crosses the wire in cleartext. The implicit-TLS port is always encrypted. |
| Offer the netmail newsgroup | On by default. Gives every authenticated member the private [netmail newsgroup](#netmail-newsgroup). |
| Netmail newsgroup name | The group's name (default `netmail`). Cleaned to a valid newsgroup name. |
| Allow sending netmail from newsreaders | On by default, but only effective when *Allow posting from newsreaders* is also on. When off, the netmail group is read-only. |
| Include sent netmail as articles | On by default — the member's own sent netmail appears alongside received mail as one thread. Off makes the group inbound-only. |
| Netmail per minute / hour | Per-member limits on netmail *sent* through NNTP, separate from the posting limits above. 0 disables a limit. |

Settings are stored in `config/nntp.json` and written through the admin daemon.
**Restart the NNTP daemon after saving.**

## Serving the standard ports (119 / 563)

The daemon defaults to `8119` and `8563`. Newsreaders connect to `119` and `563`,
so on a public server redirect the standard ports to the daemon's with a firewall
rule (this is the same approach the FTP daemon uses for port 21 — see
[FTPServer.md](FTPServer.md#iptables-redirect-rules) for persistence tips).

`iptables`:

```bash
sudo iptables -t nat -A PREROUTING -p tcp --dport 119 -j REDIRECT --to-ports 8119
sudo iptables -t nat -A PREROUTING -p tcp --dport 563 -j REDIRECT --to-ports 8563
# also needed if local processes connect to the machine's own public IP:
sudo iptables -t nat -A OUTPUT -p tcp -d YOUR.SERVER.IP --dport 119 -j REDIRECT --to-ports 8119
sudo iptables -t nat -A OUTPUT -p tcp -d YOUR.SERVER.IP --dport 563 -j REDIRECT --to-ports 8563
```

`nftables`:

```bash
sudo nft add rule ip nat prerouting tcp dport 119 redirect to :8119
sudo nft add rule ip nat prerouting tcp dport 563 redirect to :8563
```

Behind a router/NAT device, forward external `119`/`563` to the daemon host's
`8119`/`8563` instead. Alternatively, set `NNTP_PORT=119` and `NNTP_TLS_PORT=563`
in `.env` and run the daemon with permission to bind low ports.

## Connecting a newsreader

Thunderbird: *Account Settings → Account Actions → Add Other Account → Newsgroup
Account*. Server = your BBS hostname; port `119` with *Connection security:
STARTTLS* or `563` with *SSL/TLS* (or the daemon's `8119` / `8563` directly if you
have not set up a redirect). Under *Server Settings*, enable *Always request
authentication* and use your BBS credentials. Then *Subscribe* and pick the groups
you want.

## Posting

When *Allow posting from newsreaders* is on:

- A posted article is injected through the same path as a web/terminal post
  (`MessageHandler::postEchomail()`), so kludge generation, the echoarea's
  posting-name policy, and echomail moderation all apply. The `From:` line the
  newsreader sends is ignored — the message is attributed to the authenticated
  member per the network's posting-name policy.
- `Newsgroups:` may list several groups (cross-post); each becomes an independent
  copy. If the list exceeds *Max cross-post areas* the whole post is rejected.
- `References:` is used to thread a reply onto its parent when the parent is a
  message this server served.
- Held-for-moderation posts still return success to the client; the message
  appears once a moderator approves it.
- Cancel and supersede control messages are accepted and silently dropped (FTN
  has no equivalent).

## Netmail newsgroup

Alongside the echoarea groups, each authenticated member sees one extra group —
`netmail` by default — that behaves like a personal mail folder:

- Its articles are **that member's own netmail** (received, plus sent unless the
  sysop turns that off). Two members connected to the same server see completely
  different articles under the same group name. A member never sees another
  member's netmail through it.
- New inbound netmail simply appears as new articles; `NEWNEWS` reports it.
- Articles carry a real `To:` header (both the display name and the FTN address),
  plus `X-FTN-From-Address` / `X-FTN-To-Address` for round-tripping. The echomail
  area kludges (`X-FTN-AREA`, `SEEN-BY`, `PATH`, origin) do not apply. Sent items
  are tagged `X-BinktermPHP-Folder: sent`.
- A send/receive exchange threads together (`References:`), so a conversation
  reads in order.

### Sending netmail

With *Allow posting from newsreaders* and *Allow sending netmail from newsreaders*
both on, posting into the netmail group sends netmail. The message goes through
`MessageHandler::sendNetmail()` — the same path as web/terminal — so origin-address
selection, the destination network's posting-name policy, charset, local-sysop
routing, credit costs and outbound spooling all apply.

The destination FTN address is resolved in this order:

1. an explicit `X-FTN-To: 21:1/100` header (with optional `X-FTN-To-Name`);
2. **reply** — when `References:` points at an article in your netmail group, the
   address is taken from that parent message (its reply address, else its sender).
   This is the zero-effort common case;
3. the `To:` header — either the `(z:n/f.p)` shown in its display-name comment or
   the `f…n…z…` host form of the address, both of which this server emits on the
   articles it serves, so "reply" and "copy the address from a message" just work.

If none of those yields a valid FTN address the post is rejected with
`441 Cannot determine netmail destination`. Netmail cannot be cross-posted —
naming the netmail group together with any other group is rejected. Attachments,
file requests and crashmail are not supported over NNTP.

## Quote-style conversion

FTN and newsreaders quote replies differently. FTN uses the FSC-0032 form — the
quoted author's initials and a `>`, e.g. ` MA> original text`, with an extra `>`
per nesting level. Newsreaders use a bare, stacked `>`: `> text`, `>> text`.
Serving FTN-style quoting to a newsreader works but reads as foreign, and a reply
composed in a newsreader comes back as bare `>` that looks foreign in an FTN
reader.

The **Quote-style conversion** setting bridges this at the gateway:

| Value | Effect |
|---|---|
| `Off` | Message text is served and stored exactly as-is. |
| `Outbound only` *(default)* | On articles served to a newsreader, a leading ` MA> ` is rewritten to `> ` of the same depth. Stored echomail is unchanged. |
| `Both directions` | Also rewrites a leading `> ` on an **incoming** post to ` XX> `, where `XX` is the initials of the message being replied to (from `References:`). Every quote level is attributed to that one author, since FTN records only one quoted author per line. |

Both directions are line-oriented and conservative: only an unmistakable leading
prefix is touched, fenced code blocks (```` ``` ````) are skipped, and lines
containing ANSI escapes (art) are left alone. The transform preserves quote depth
but not the identity of authors quoted below the immediate parent, so it is not
perfectly reversible — inherent to any FTN/Usenet bridge. Inbound conversion is a
heuristic and can misfire on pasted shell transcripts, so it is opt-in.

Initials follow the same rule as the web and terminal reply editors: two letters
for a single-word name, first-plus-last initial otherwise.

## Article numbering

NNTP requires per-group article numbers that are never reused. BinktermPHP keeps
them in `nntp_article_numbers` / `nntp_area_watermark`; the daemon assigns numbers
the first time an area is read. If echomail is later pruned, the numbers it held
are retired (not reissued) and requests for them return `423`.

The netmail group has its own per-member tables, `nntp_netmail_article_numbers` /
`nntp_netmail_watermark`, with the same rules — numbers are assigned the first
time a member opens the group, and a soft-deleted netmail's number is retired.

## Troubleshooting

- **`log: data/logs/nntpd.log`** (`nntp_daemon.log` under Docker).
- **Client can't log in on the plaintext port** — it probably does not support
  `STARTTLS`. Use the implicit-TLS port, or (last resort) enable plaintext
  authentication.
- **"No such newsgroup (or not subscribed)"** — the member is not subscribed to
  that echoarea.
- **A group is missing from `LIST`** — its area tag contains a character that is
  not valid in a newsgroup name (e.g. `&`); such areas are skipped and logged at
  startup.
- **Probe the server** without a full newsreader:
  `php scripts/nntp_test_client.php --host=127.0.0.1 --port=8119 --user=NAME --pass=PW`
- **See the protocol dialogue** — start the daemon with `--log-level=DEBUG`. The log
  then carries the full wire trace (`C:` lines received, `S:` responses, multi-line
  bodies summarised as `[+N lines]`, POSTed article headers, and the effective
  `config/nntp.json` at startup). `AUTHINFO PASS` is redacted.
