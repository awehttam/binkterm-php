# RLogin Doors (Proposal — DRAFT)

> **This is a draft proposal.** It was generated with AI assistance during a design discussion and has not been reviewed for accuracy or completeness. Sections marked **Open Question** are unresolved and need a decision before implementation.

## Table of Contents

- [Motivation](#motivation)
- [Naming: Door Type vs. BBS Type](#naming-door-type-vs-bbs-type)
- [BBS Type Presets](#bbs-type-presets)
- [Pre-Login Commands](#pre-login-commands)
- [Custom Session Variables](#custom-session-variables)
- [Where This Fits Architecturally](#where-this-fits-architecturally)
- [Open Questions](#open-questions)

---

## Motivation

BinktermPHP currently supports door games that run locally on the BBS host: DOS Doors (DOSBox-X), Native Doors (PTY-spawned binaries), WebDoors, JS-DOS Doors, and C64 Doors. None of these cover the case of **rlogin-ing out to a different BBS or service entirely** — for example, linking a BinktermPHP install to a Synchronet system so users can play Synchronet's door games or use Synchronet's message base, without leaving the BinktermPHP terminal session.

Plain `rlogin` to a remote host is not enough on its own, because:

- The remote system (e.g. Synchronet) needs a **user account to exist** before rlogin can log the user in automatically. BinktermPHP needs a way to provision that account (or sync it) just-in-time.
- The remote system may expect **specific session variables** to select behavior — e.g. Synchronet's door server reads `TERM=xtrn=DOORCODE` to launch a specific door/xtrn program directly instead of dropping the user at a main menu.
- Different remote BBS software (or even different configurations of the same software) need different provisioning/handshake logic. A generic "just rlogin somewhere" door doesn't capture that.

## Naming: Door Type vs. BBS Type

Two naming approaches were considered:

1. **"RLoginDoor" as a distinct door type**, analogous to DOS Doors / Native Doors / WebDoors, appearing as its own entry in `docs/Doors.md` and its own admin section.
2. **A "BBS Type" dropdown** on door configuration, letting the sysop pick the specific remote system flavor (e.g. `Plain RLogin`, `Synchronet`, `Synchronet with BinktermPHP Service`) and having BinktermPHP apply the right provisioning/variable behavior for that flavor.

**Decision so far: both, at different layers.** The door *type* is `RLoginDoor` (or similar internal name) — it's a new addition alongside Native/DOS/WebDoors and gets its own manifest format, config file, admin UI, and doc page. Within an `RLoginDoor` instance, the sysop picks a **BBS Type** preset from a dropdown. The BBS Type preset determines:

- Whether a pre-login command runs before connecting, and what shape its output/behavior contract is.
- What session/terminal variables get sent to the remote host by default.
- Whether a BinktermPHP-side API integration is available for deeper features (e.g. reporting credits back, single sign-on beyond just account creation).

This keeps "one door type to build and maintain" while still giving sysops a guided, opinionated setup experience instead of a blank pre-login command field they have to figure out from scratch.

## BBS Type Presets

Initial preset list (names are working titles):

| BBS Type | Pre-login behavior | Notes |
|---|---|---|
| **Plain RLogin** | None — connects directly via rlogin with the BinktermPHP username. | For any generic rlogin-accessible system where the sysop manages accounts out of band, or the remote system auto-creates accounts on first rlogin (some do). |
| **Synchronet** | None built in, but the door config still exposes the pre-login command hook for the sysop to script manually. | For sysops who already have their own provisioning approach and don't want BinktermPHP's opinionated Synchronet integration. |
| **Synchronet with BinktermPHP Service** | Pre-login step pushes to a Synchronet `services.ini` service (a TCP service listening inside Synchronet) to create the account if missing and/or sync profile fields. | BinktermPHP ships the client side of this call. The Synchronet-side `services.ini` service script itself lives in its own separate repository for sysops to install — not bundled into BinktermPHP. |

This list should be treated as a starting point. **Open Question:** do we need a preset per remote BBS package (Mystic, Talisman, WWIV, etc.) eventually, or does `RLoginDoor` stay Synchronet-focused for now with "Plain RLogin" as the escape hatch for everything else?

## Pre-Login Commands

A pre-login command is a server-side helper that runs **before** the rlogin connection is made, so it can guarantee the remote account exists (and optionally is up to date) by the time the user is dropped into the session.

**Decision: input is passed via CLI arguments through a templated command string**, following the same convention Native Doors already use for `launch_command` placeholders (`{node}`, `{dropfile}`, `{user_number}`, `{homedir}`). A `pre_login_command` field on the door config would support the same style of placeholders — e.g. `{user_name}`, `{real_name}`, `{user_number}`, plus whatever else the remote-account-provisioning step needs — substituted before the command runs. This keeps the convention consistent across door types instead of introducing an environment-variable contract that only `RLoginDoor` uses.

**Decision: exit code + JSON on stdout.** Exit code `0` means proceed with the rlogin connection; any non-zero exit aborts the launch and the user sees an error (stderr, or a fixed generic message — TBD). On success, stdout is parsed as JSON, allowing the pre-login command to hand back dynamic values the launch step needs but can't know in advance — e.g. `{"remote_username": "...", "otp": "..."}` — for cases where the remote account's username differs from the BinktermPHP username, or the provisioning step generates a one-time password/token to feed into the rlogin handshake. Empty/non-JSON stdout on success is treated as "no overrides."

Still open:

- **Timeout.** A hung pre-login command shouldn't hang the user's launch request indefinitely — needs a configurable or fixed timeout with a clear failure message.
- **Idempotency.** The command runs on every login attempt (not just first-time), so it needs to be safe to call repeatedly — "create if not exists" semantics, not "create or error." This is a contract we document for script authors, not something BinktermPHP can enforce.

## Custom Session Variables

Synchronet's door server (and likely other targets) read the client's reported terminal type to decide what to launch — e.g. `TERM=xtrn=LORD` to jump straight into a specific door/xtrn program rather than landing on the main menu.

This means `RLoginDoor` needs a way to specify, per door instance:

- The **rlogin client username** field (`-l` username) sent to the remote host, which may differ from the BinktermPHP username depending on how accounts are provisioned/mapped.
- A **terminal type string** that isn't just a real terminal type — it doubles as a routing/xtrn signal on Synchronet. This should be a free-text or templated field on the door config, not hardcoded to `xterm-256color` the way Native Doors currently fix it.
- Possibly other rlogin protocol fields (client hostname/terminal speed) if a target system inspects them — TBD based on what Synchronet's rlogin listener actually reads.

## Where This Fits Architecturally

Native Doors already spawn a local process over PTY via the Node.js multiplexing bridge. The obvious implementation path is: `RLoginDoor` is launched the same way, except the "process" being spawned is an rlogin connection.

**Decision: bundle a minimal Node-based rlogin client in the bridge**, rather than shelling out to a system `rlogin` binary. RFC 1282 is a small enough protocol that a bundled client is practical, and it gives consistent behavior across Linux/Windows hosts plus full control over injecting the username and terminal-type fields at the protocol level, rather than depending on whatever `rlogin` binary (if any) happens to be installed and how it exposes those options on the command line.

**Decision: the pre-login provisioning command runs in PHP, at session-launch API time** — inside the same `POST /api/door/launch` flow that creates the door session record today, before that record is created. This keeps provisioning on the same trust and logging boundary as the rest of the web app (`BinktermPHP\Binkp\Logger`, etc.) instead of adding a second, bridge-side place where privileged commands get executed and logged. If the pre-login command fails, the API call fails and no session/bridge connection is ever created — the bridge's job stays exactly what it is today for Native Doors: given a session that already exists, spawn the connection. The bridge does not need to know anything about provisioning.

## Open Questions

1. ~~Do we shell out to a system `rlogin` client, or bundle a minimal Node-based rlogin implementation in the bridge?~~ **Resolved:** bundle a Node rlogin client in the bridge.
2. ~~Where does the pre-login command actually execute?~~ **Resolved:** PHP, at session-launch API time.
3. ~~What's the pre-login command's input/output contract?~~ **Resolved:** templated CLI placeholders in, exit code + optional JSON stdout out. Timeout value and idempotency documentation still need pinning down.
4. ~~Do we need per-preset pre-login command scripts shipped with BinktermPHP?~~ **Resolved:** BinktermPHP ships the client side (talks to the Synchronet-side service) for the Synchronet with BinktermPHP Service preset; the Synchronet-side `services.ini` service script itself lives in its own separate repository for sysops to install. Naming/location of that companion repo is still TBD.
5. ~~Is BinktermPHP exposing an API for Synchronet to call, or calling into Synchronet?~~ **Resolved:** BinktermPHP always calls out (push) — there is no "Synchronet calls BinktermPHP" preset.
6. ~~Should `RLoginDoor` reuse credit-cost/session-limit/time-limit config fields the same way Native Doors do?~~ **Resolved:** yes, full parity — `enabled`, `credit_cost`, `max_time_minutes`, `max_sessions`, `allow_anonymous`, etc. carry over the same as Native Doors, alongside RLoginDoor-specific fields (host, port, BBS Type, `pre_login_command`). Whether `allow_anonymous` makes sense here (provisioning a remote account for a guest) needs a closer look when this is implemented.
7. ~~Does the BBS Type preset list need to grow beyond Synchronet-focused entries in v1?~~ **Resolved:** stay Synchronet-focused for v1 — `Plain RLogin`, `Synchronet`, `Synchronet with BinktermPHP Service`. `Plain RLogin` is the intentional generic fallback for everything else; more BBS Type presets (Mystic, Talisman, WWIV, etc.) can be added later without changing the architecture.
