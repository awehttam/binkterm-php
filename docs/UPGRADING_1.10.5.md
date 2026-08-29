# Upgrading to 1.10.5

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Message Composition](#message-composition)
- [Admin BBS Settings](#admin-bbs-settings)
- [MeshCore Enable/Disable](#meshcore-enabledisable)
- [NNTP Server](#nntp-server)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Message Composition

- The compose editor's automatic line-wrap column now defaults to **72** instead of 79, and 72 is offered as the recommended choice in the compose Advanced Options. Wrapping at 72 leaves room for quote-attribution prefixes (for example ` AB> `) so that quoted reply lines stay within the 79-column width that FidoNet readers expect. The 79-column option is still available for users who prefer it, and anyone who has already chosen a wrap width keeps their setting.

### Admin BBS Settings

- The **Admin -> BBS Settings** page is now organized into four tabs: **System & Features**, **Credit System**, **Tag Lines**, and **Registration Screening**. All settings and their save buttons are unchanged; they are only regrouped so the page is shorter and easier to navigate.

### MeshCore Enable/Disable

- A new **Enable MeshCore** toggle in **Admin -> BBS Settings -> System & Features** turns the whole MeshCore / PacketBBS radio subsystem on or off. It defaults to **on**, so existing MeshCore setups keep working. Turning it off makes the bridge API unreachable and hides every MeshCore surface (user settings tab, public nodes page, admin page, dashboard card, navigation links).

### NNTP Server

- BinktermPHP can now serve its echoareas as Usenet-style newsgroups over NNTP (RFC 3977), so members can read — and optionally post — echomail with a standard newsreader such as Thunderbird. It runs as a new optional daemon, `scripts/nntp_server.php`, and is **disabled by default**. Enable it, and configure rate limits and the plaintext-authentication policy, in the new **Admin -> NNTP Server** page. Posting from a newsreader is a second toggle on that page, also off by default.
- Two new database tables (`nntp_article_numbers`, `nntp_area_watermark`) are created and populated from your existing echomail during the upgrade.
- Transport settings — bind address, ports, and TLS certificate paths — are read from `.env`. New keys: `NNTP_BIND_HOST`, `NNTP_PORT` (default `8119`), `NNTP_TLS_PORT` (default `8563`), `NNTP_TLS_CERT_PATH`, `NNTP_TLS_KEY_PATH`. The ports default to an unprivileged range; redirect the standard `119` / `563` to them with a firewall rule.

---

## Message Composition

When composing netmail or echomail, the editor can hard-wrap long lines automatically as you type. The wrap column is a per-user preference in the compose form's **Advanced Options**.

Previously the default was 79 columns. When replying to a message, each quoted line is prefixed with an attribution string such as ` AB> `, which pushed quoted lines past 79 columns and caused readers to wrap them a second time. The default is now 72 columns, which keeps quoted lines within 79 after the prefix is added. A new **72 characters (recommended)** option appears in the wrap selector; the **79** option remains for users who want it. Existing saved preferences are unchanged.

## Admin BBS Settings

The **Admin -> BBS Settings** page previously presented every section as a long vertical stack of cards. It is now split into four tabs:

- **System & Features** - system identity, terminal server / idle timeouts, Packet BBS, and the BBS feature toggles
- **Credit System** - the full Credit System Configuration panel
- **Tag Lines** - the tagline list editor
- **Registration Screening** - new-user IP screening and its signal weights

Each tab keeps its own **Save** button and saves independently, exactly as before.

## MeshCore Enable/Disable

MeshCore (also referred to as PacketBBS — the mesh-radio / low-bandwidth access path) can now be switched off entirely from **Admin -> BBS Settings -> System & Features** with the **Enable MeshCore** checkbox. This corresponds to a new `features.meshcore` key in `config/bbs.json`.

The toggle defaults to **enabled**, so a system that is already using MeshCore sees no change after upgrading.

When it is disabled:

- All bridge endpoints (`/api/packetbbs/*` and `/api/meshcore/*`) return `404`, so no radio bridge can authenticate or relay commands.
- The **MeshCore** tab in each user's **Settings** page is hidden.
- The public **Meshcore Nodes** page (`/packetbbs-nodes`), the "Meshcore Nodes" navigation links, and the dashboard "Packet BBS Status" card are hidden.
- The **Admin -> Packet BBS** management page returns `404` and its navigation link is hidden.

Node records, radio sessions, and stored contacts are not deleted while MeshCore is disabled; re-enabling the toggle restores everything.

## NNTP Server

An NNTP server lets members connect with any standard newsreader (Thunderbird, slrn, tin, Forte Agent) and read FTN echoareas as if they were newsgroups. Each echoarea a member is subscribed to appears as a newsgroup named `<Network>.<AreaTag>`, for example `FidoNet.GENERAL` or `LovlyNet.LVLY_BINKTERMPHP`. Members sign in with their normal BinktermPHP username and password.

### Enabling it

Turn the server on in **Admin -> NNTP Server** (*Enable the NNTP server*). The same page has the newsgroup-name prefix style, a per-IP connection limit, per-member posting rate limits, and a *plaintext authentication* switch. These are stored in a new `config/nntp.json`, written through the admin daemon. A disabled server answers connections with a `400` error and closes; you must restart the daemon after switching it on.

Set the transport in `.env` and restart the daemon for the change to take effect. New variables:

| Key | Default | Purpose |
|---|---|---|
| `NNTP_BIND_HOST` | `0.0.0.0` | Address to bind |
| `NNTP_PORT` | `8119` | Plaintext + `STARTTLS` port |
| `NNTP_TLS_PORT` | `8563` | Implicit-TLS port; leave empty to disable |
| `NNTP_TLS_CERT_PATH` | `data/nntp/server.crt` | PEM certificate, or a combined cert+key PEM |
| `NNTP_TLS_KEY_PATH` | `data/nntp/server.key` | PEM private key |

The ports default to the unprivileged `8119` and `8563` so the daemon runs as an ordinary user. Newsreaders expect NNTP on `119` and `563`; on a public server, redirect those to `8119` / `8563` with an `iptables` or `nftables` rule (see `docs/NNTP.md`), or set `NNTP_PORT` / `NNTP_TLS_PORT` to `119` / `563` and run the daemon with permission to bind them.

If `NNTP_TLS_CERT_PATH` is left at its default and no file exists there, the daemon generates a self-signed certificate on first start; point it at a real certificate (for example the one your web server uses) for clients that reject self-signed certs. A path that is set but points at a missing file stops the daemon with an error.

Start the daemon. It is an optional daemon, so `scripts/restart_daemons.sh` with no arguments only restarts it if it was already running:

```bash
scripts/restart_daemons.sh --start nntp_daemon
```

On Windows it is not part of `start_daemons_windows.*` and must be started by hand with `php scripts/nntp_server.php`. In Docker, set `ENABLE_NNTP: "true"` in `docker-compose.override.yml` and uncomment its port lines (`119:8119`, `563:8563`).

### Posting

Reading works as soon as the server is enabled. To also let members compose and reply from their newsreader, turn on *Allow posting from newsreaders* in **Admin -> NNTP Server**. Posted articles go through the same path as a web or terminal post, so the echoarea's posting-name policy and echomail moderation apply, and the message is attributed to the signed-in member regardless of the `From:` line the newsreader sends. A post whose `Newsgroups:` header names more echoareas than the configured cross-post limit is rejected rather than trimmed.

### Article numbering

NNTP requires per-newsgroup article numbers that are never reused. The `nntp_article_numbers` and `nntp_area_watermark` tables track them; the daemon assigns a number the first time a newsgroup is read. If echomail is later pruned, the numbers it held are retired, not reissued.

See `docs/NNTP.md` for connecting a newsreader and troubleshooting.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
