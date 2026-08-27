# Upgrading to 1.10.4

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [RLogin Doors](#rlogin-doors)
- [Networks](#networks)
- [Dashboard](#dashboard)
- [DOS Doors](#dos-doors)
- [PubTerm](#pubterm)
- [Sessions](#sessions)
- [Logging](#logging)
- [Registration Screening](#registration-screening)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Doors

- Added **RLogin Doors**, a new door type that connects out to a remote BBS or service (such as a linked Synchronet system) over the rlogin protocol instead of running a local process.

### Networks

- Removed **DixieNet** from the built-in FTN networks list, as the network is defunct.

### Dashboard

- Fixed the unread netmail and echomail counts on the dashboard not updating in real time; they previously only refreshed on a 30-second poll instead of reacting to BinkStream events like the messaging menu badges do.

### DOS Doors

- The DOSBox/DOSEMU multiplexing server now logs the generated `[autoexec]` config section (DOSBox) or launch batch script (DOSEMU) to the console/log each time a door session is launched, to make door launch problems easier to diagnose from server logs.
- Fixed a crash in the DOSEMU adapter when a door's `launch_command` used the `{user_number}` placeholder.
- Removed a duplicate, non-functional "Requires FOSSIL Driver" checkbox from the **Requirements** section of the DOS door manifest editor. The **Requires FOSSIL Driver** checkbox in the door info section is the one that actually controls whether the FOSSIL driver is loaded at launch; the removed checkbox never had any effect.
- Fixed the **CPU Cycles** field in the DOS door manifest editor having no effect — the DOSBox multiplexing server now applies a door's configured cycle count to its generated `dosbox.conf` instead of always using the template's default value.

### PubTerm

- PubTerm (the browser terminal door) now forwards each visitor's real IP address to the telnet daemon, so per-IP connection rate limiting, new-user registration screening, connection logs, and the stored session IP shown in **Admin → Users** / `binktop` / `who` all reflect the actual visitor instead of the server's address. On a standard single-host install this needs no configuration.
- Terminal (telnet/SSH) sessions in general now record the connecting user's IP address for online-session displays, rather than the server's — previously any terminal login showed the server address because the daemon proxies API traffic through the server.
- New optional `.env` variables for the telnet daemon: `TELNET_TRUSTED_PROXIES` and `TELNET_PROXY_HEADER_TIMEOUT`. The telnet daemon's existing `TELNET_RATE_LIMIT_MAX` / `TELNET_RATE_LIMIT_WINDOW` settings are now also documented.
- Fixed a regression where arrow keys, Page Up/Down, Home/End, and function keys stopped working in PubTerm. When the browser terminal player gained DOS Doorway scan-code support for navigation keys, that translation was applied to PubTerm and other native doors too — but those expect normal ANSI/xterm key sequences, not Doorway codes.

### Sessions

- Fixed a user session's stored IP address going stale for the lifetime of the session instead of tracking the client's current address.

### Logging

- The `[BINKD]` "Storing echomail" log line now includes the echo area (`Area: AREANAME@domain`), making it possible to tell which area a stored message landed in without cross-referencing other log lines.

### Registration Screening

- Added an optional **registration screening** step that scores each new-user signup from IP and email risk signals (DNSBL/RBL listing, Tor exit node, missing email MX record, repeated attempts from one subnet). It ships turned off; when enabled it can either just record a risk score for the sysop to see, or hold high-scoring signups for manual review even when registration is set to auto-approve.
- The binkp scheduler daemon now also refreshes a cached Tor exit node list every 6 hours (only while screening and its Tor signal are enabled).

---

## RLogin Doors

BinktermPHP can now link out to a remote BBS or service over the rlogin protocol (RFC 1282) as a new door type, alongside DOS Doors and Native Doors. This lets users reach a separate system — such as a Synchronet BBS — without leaving their BinktermPHP terminal session.

Unlike the other door types, RLogin doors have no filesystem footprint — there's no executable or manifest directory, just connection settings. Because of that, RLogin doors are stored directly in a new `rlogin_doors` database table rather than as manifest/config files, and are managed through a dedicated **Admin → RLogin Doors** page with a standard add/edit/delete form — including uploading a custom icon and screenshot for each door, stored directly in the database.

Each RLogin door is configured with a target host/port, an rlogin username/terminal-type handshake, and an optional **pre-login command** that runs server-side before every connection to provision or sync the remote account just-in-time. A bundled reference client script (`scripts/synchronet_add_user.php`) is included for sysops linking to Synchronet via a companion `services.ini` service. For sysops running that companion service, an **Import from Synchronet** button on the admin page fetches the list of installed Synchronet doors and creates a fully-configured (but disabled, pending review) RLogin door for each one automatically. See [RLoginDoors.md](RLoginDoors.md) for the full field reference, BBS Type presets, the pre-login command's wire protocol, and the import feature.

## Networks

DixieNet has been removed from the built-in list of FTN networks under **Admin → Networks**, as the network is defunct. If your system has an active binkp uplink, echo area, or file area still configured against the `dixienet` network, the row is left in place automatically.

## Dashboard

The unread netmail and echomail counts shown on the dashboard now update in real time as new mail arrives, the same way the messaging menu badges in the navigation bar already did. Previously, the dashboard counts only refreshed on a 30-second poll, so a new message could take up to 30 seconds to show up there even though the nav bar badge lit up immediately.

## DOS Doors

The DOSBox/DOSEMU multiplexing server (`scripts/dosbox-bridge/`) now writes the generated door launch script to its console/log output at launch time. For the DOSBox adapter this is the `[autoexec]` section of the generated `dosbox.conf`; for the DOSEMU adapter this is the generated launch batch script. This makes it possible to see exactly what commands a door session ran (mount points, FOSSIL driver loading, dropfile copy, launch command) directly from server logs, without needing to open the per-session config file on disk.

The DOSEMU adapter also had a bug fixed where launching a door whose manifest `launch_command` contained the `{user_number}` placeholder would crash with a `ReferenceError` instead of substituting the user's ID.

In the DOS door manifest editor (**Admin → DOS Doors**), the **Requirements** section previously had two checkboxes related to the FOSSIL driver: "Requires FOSSIL Driver" in the door info section (which actually controls whether the FOSSIL driver is loaded during door launch) and a second, identically-labeled checkbox in the Requirements section that had no effect on runtime behavior. The non-functional duplicate has been removed. Existing manifests are unaffected; the remaining "Requires FOSSIL Driver" checkbox in the door info section continues to work as before.

The **CPU Cycles** field on the **Runtime Defaults** section of the manifest editor was previously stored but never applied — every door session used the same fixed `cycles=` value from the active DOSBox config template regardless of what was set per-door. The DOSBox multiplexing server now substitutes a door's configured CPU cycle count into its generated `dosbox.conf` at launch time, so raising or lowering the value for a specific door now actually changes its emulated CPU speed. Leave the field unset (or at its default) to keep using the template's value.

## PubTerm

PubTerm gives browser visitors a full terminal session by connecting them to the BBS's own telnet port. Because that connection is made on the server, the telnet daemon previously saw the loopback address (`127.0.0.1`) or the server's own IP for every PubTerm user. Anything that keys on the client address — the telnet daemon's per-IP connection rate limiter, the new registration screening feature, and connection logging — therefore could not tell PubTerm visitors apart or attribute activity to a real address.

PubTerm now stands up a short-lived per-session relay that connects to the telnet daemon, announces the visitor's real address using the HAProxy PROXY protocol, and then passes the session through. The telnet daemon reads that header and attributes the whole session to the real address. PubTerm continues to use the ordinary system `telnet` client, so terminal negotiation and rendering are unchanged.

**No action is required on a standard single-host install.** The telnet daemon only accepts the PROXY header from trusted source addresses, and it automatically trusts loopback and its own configured bind address — which is where the relay connects from. You only need to act if:

- **The telnet daemon binds a specific address.** Set `TELNET_BIND_HOST` in `.env` to that address (if it is not already set) so both the relay and the daemon's trust list agree on it. The daemon's `--host` argument defaults to `TELNET_BIND_HOST`, so avoid passing a `--host` that differs from it.
- **The relay reaches the daemon from some other address.** Add that address to `TELNET_TRUSTED_PROXIES` (comma-separated). Never list a public-facing address that untrusted clients could connect from directly — any address on the list is allowed to claim any source IP.

New telnet daemon `.env` variables:

| Variable | Default | Purpose |
|---|---|---|
| `TELNET_TRUSTED_PROXIES` | `127.0.0.1,::1` | Additional source addresses allowed to supply a PROXY header (loopback and the daemon's bind address are always trusted on top of this) |
| `TELNET_PROXY_HEADER_TIMEOUT` | `2` | Seconds to wait for a PROXY header from a trusted source before treating the connection as a normal direct connection |

To confirm it is working, connect to PubTerm and check `data/logs/telnetd.log` for:

```
PROXY header from <relay-address>: real client <visitor-ip>
Connection #N from <visitor-ip> (via bridge)
```

Restart both the multiplexing bridge and the telnet daemon after upgrading. On Windows hosts the relay is not used (the Windows launch path uses PuTTY's `plink`), so PubTerm sessions there remain attributed to the loopback address.

### Navigation keys

Arrow keys, Page Up/Page Down, Home/End, Insert/Delete, and the function keys stopped working in a PubTerm session — pressing an arrow key produced a stray character or nothing at all. This was a regression.

PubTerm uses the same in-browser terminal player as the DOS door games. That player was later changed to intercept navigation keys and send them as DOS Doorway scan codes (a null byte followed by an IBM PC scan code), which is what DOS programs expect. PubTerm is not a DOS program — the BinktermPHP terminal server behind it expects ordinary ANSI/xterm escape sequences (`ESC [ A` for cursor up, and so on) — so it silently discarded the Doorway bytes, and every navigation key appeared dead.

The player now recognizes native doors — PubTerm and any other door that runs a real Linux program rather than a DOS emulator — and leaves their key sequences untranslated. DOS doors are unchanged.

### Terminal session IP attribution

Separately from PubTerm, every telnet and SSH login now records the connecting user's address in `user_sessions.ip_address` (the value shown on the **Admin → Users** online-sessions list and by `scripts/binktop.php` / `scripts/who.php`). Previously the daemon's own API calls set this, so every terminal session was labelled with the server's address.

The daemon authenticates the forwarded address with the existing `TERMINAL_REGISTRATION_SECRET` from `.env`. **If you have not changed it from the `Chang3Me` default, do so** — anything able to present that secret can set its own recorded session IP. It is the same secret already used to authorize terminal-originated registrations.

This secret is now sent by the daemon on every API call rather than only on registration, so the daemon should reach the web application over HTTPS or a loopback address. If your daemon's API base is a plain `http://` URL on an untrusted network, point it at `http://127.0.0.1` (or an HTTPS URL) with `--api-base` or `SITE_URL`.

See [PubTerm.md](PubTerm.md#real-client-ip-forwarding) and [TelnetServer.md](TelnetServer.md#proxied-connections-proxy-protocol) for details.

## Sessions

A logged-in session's stored IP address was previously recorded only once, at login, and never updated afterward. If a client's IP address changed during a session — for example a mobile device roaming between networks, or a user reconnecting through a different network path — the address shown for that session would remain frozen at whatever it was when the session began.

Web sessions now refresh their stored IP address automatically as the session is used, so it reflects the client's current connection. This affects anywhere a session's IP is displayed to sysops, including `scripts/binktop.php`, `scripts/who.php`, and the online sessions list on the **Admin → Users** page.

## Logging

The `[BINKD]` log line written when an incoming echomail message is stored now includes the echo area it was stored in, in the format `Area: AREANAME@domain`. Previously the line included the MSGID, author, packet sender, and subject, but not the area, making it harder to tell where a given message landed when scanning server logs.

## Registration Screening

BinktermPHP can now screen new-user registrations for signs of automated abuse or throwaway accounts. This is a separate layer from the existing **Require approval for new users** setting: approval decides whether *every* signup waits for the sysop, while screening looks at *individual* signups and can single out the risky ones.

When a registration comes in, screening computes a risk score by adding up the weight of each signal that fires:

- **DNSBL / RBL** — the registrant's IP is listed on a DNS blocklist. Ships configured with `zen.spamhaus.org` and `bl.spamcop.net`. For Spamhaus ZEN, only the spam-source and compromised-host listings count; the Policy Block List (dynamic/residential ranges) is ignored so ordinary home connections are not penalized.
- **Tor exit node** — the IP is a current Tor exit relay, matched against a locally cached list.
- **Email domain has no mail server** — the submitted email's domain publishes no MX, A, or AAAA record, so it cannot receive mail.
- **Registration velocity** — several registration attempts have come from the same subnet within a time window (default: 3 attempts from a /24 in 24 hours).
- **Disposable email domain** — the email domain (or a parent of it) is on a known throwaway-provider list. Off by default; when enabled, the scheduler downloads and caches the list (the community `disposable-email-domains` blocklist by default) every 24 hours.

Screening runs in one of two modes:

- **Observe** (the shipped default) — the score and the list of triggered signals are stored on the registration and shown in **Admin → Users** under the pending list, but the approve/hold outcome is unchanged.
- **Enforce** — a registration whose score reaches the configured threshold (default: 30) is held for manual review, even if **Require approval for new users** is off. Screening never rejects a registration outright; the worst case is that the sysop has to approve it by hand.

To turn it on, go to **Admin → BBS Settings → Registration Screening**, enable it, and adjust the per-signal weights, the threshold, and the RBL zone list to taste. Every setting has a documented default in [CONFIGURATION.md](CONFIGURATION.md#registration-screening).

One caveat on the RBL check: if your server resolves DNS through a large public resolver such as `1.1.1.1` or `8.8.8.8`, Spamhaus returns an error code instead of real listing data for every query. The shipped configuration ignores that code so it will not produce false hits, but the RBL signal only returns meaningful results when the host queries its own resolver or its ISP's.

If you run the scheduler (`scripts/binkp_scheduler.php`, as either the long-lived daemon or `--once` from cron), it keeps the cache lists for the Tor and disposable-email signals current: it downloads each list the first time it finds that cache empty, then refreshes the Tor list every 6 hours and the disposable-email list every 24 hours. You can also populate them immediately by hand with `php scripts/update_tor_exit_list.php` and `php scripts/update_disposable_email_list.php`.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
