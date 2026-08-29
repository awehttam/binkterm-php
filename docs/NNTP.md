# NNTP Server

BinktermPHP can expose its FTN echoareas as Usenet-style newsgroups over NNTP
(RFC 3977), so members can read echomail with any standard newsreader —
Thunderbird, and older readers such as slrn, tin or Forte Agent.

Reading works out of the box once the server is enabled. **Posting from a
newsreader is off by default** — turn on *Allow posting from newsreaders* in
**Admin → NNTP Server** to let members compose and reply from their client.

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

1. **Turn it on** in **Admin → NNTP Server**: set *Enable the NNTP server*.
2. **Set the transport** in `.env` (a daemon restart applies changes):

   | Variable | Default | Meaning |
   |---|---|---|
   | `NNTP_BIND_HOST` | `0.0.0.0` | Address to bind |
   | `NNTP_PORT` | `119` | Plaintext + `STARTTLS` port |
   | `NNTP_TLS_PORT` | `563` | Implicit-TLS port; set empty to disable |
   | `NNTP_TLS_CERT_PATH` | `data/nntp/server.crt` | PEM cert, or combined cert+key |
   | `NNTP_TLS_KEY_PATH` | `data/nntp/server.key` | PEM private key |

   Ports 119 and 563 are privileged; either run the daemon with the necessary
   capability/permission, bind it to high ports, or publish it through your
   container's port mapping.

   If `NNTP_TLS_CERT_PATH` is left at the default and no file exists there, the
   daemon generates a self-signed certificate on first start. Point
   `NNTP_TLS_CERT_PATH` at a real certificate (e.g. the same one your web server
   uses) for clients that reject self-signed certs. A path that is set but missing
   is a fatal startup error.

3. **Start the daemon**:

   ```
   scripts/restart_daemons.sh --start nntp_daemon
   ```

   It is an optional daemon — `restart_daemons.sh` with no arguments only restarts
   it if it was already running. On Windows it is not part of
   `start_daemons_windows.*` and must be started by hand:
   `php scripts/nntp_server.php`.

   In Docker, set `ENABLE_NNTP: "true"` in `docker-compose.override.yml` and
   publish ports `119` and `563`.

## Admin → NNTP Server settings

| Setting | Notes |
|---|---|
| Enable the NNTP server | Master switch. When off the daemon accepts connections but serves nothing. |
| Allow posting from newsreaders | Off by default. When on, authenticated members can `POST` new messages and replies. |
| Newsgroup name prefix | `Network display name` (default) or `Domain slug`. |
| Max connections per IP | Concurrent connections allowed from one source address (default 3). |
| Posts per minute / hour | Per-member posting rate limits; a post over the limit is rejected with `441`. Set to 0 to disable that limit. |
| Max cross-post areas | Most echoareas one article may target via a multi-group `Newsgroups:` header. An over-limit post is **rejected**, not trimmed. |
| Allow plaintext authentication on port 119 | **Off by default.** When off, a newsreader on port 119 must run `STARTTLS` before it can log in. Turn it on only for a legacy reader with no TLS support, accepting that its password crosses the wire in cleartext. Port 563 is always encrypted. |

Settings are stored in `config/nntp.json` and written through the admin daemon.
**Restart the NNTP daemon after saving.**

## Connecting a newsreader

Thunderbird: *Account Settings → Account Actions → Add Other Account → Newsgroup
Account*. Server = your BBS hostname, port 119 (with *Connection security:
STARTTLS*) or 563 (*SSL/TLS*). Under *Server Settings*, enable
*Always request authentication* and use your BBS credentials. Then *Subscribe* and
pick the groups you want.

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

## Article numbering

NNTP requires per-group article numbers that are never reused. BinktermPHP keeps
them in `nntp_article_numbers` / `nntp_area_watermark`; the daemon assigns numbers
the first time an area is read. If echomail is later pruned, the numbers it held
are retired (not reissued) and requests for them return `423`.

## Troubleshooting

- **`log: data/logs/nntpd.log`** (`nntp_daemon.log` under Docker).
- **Client can't log in on port 119** — it probably does not support `STARTTLS`.
  Use port 563, or (last resort) enable plaintext authentication.
- **"No such newsgroup (or not subscribed)"** — the member is not subscribed to
  that echoarea.
- **A group is missing from `LIST`** — its area tag contains a character that is
  not valid in a newsgroup name (e.g. `&`); such areas are skipped and logged at
  startup.
- **Probe the server** without a full newsreader:
  `php scripts/nntp_test_client.php --host=127.0.0.1 --port=119 --user=NAME --pass=PW`
