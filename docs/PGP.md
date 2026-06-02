# PGP Key Management

This guide covers BinktermPHP's PGP support from a sysop's point of view: how to enable it, what it exposes to users, and how the managed-private-key option changes behavior.

## What The Feature Does

PGP support lets each user maintain more than one public key and choose a preferred key for their account. When enabled, users get a **PGP** tab in their settings page where they can:

- upload one or more public keys
- pick which key is primary
- generate a BBS-managed private key pair, if that policy is allowed
- save correspondent public keys privately through the address book for encrypted replies

The platform also exposes a public keyserver view so other systems can look up published keys. When managed private keys are enabled, the browser can also use them for netmail encryption and echomail signing.

## Default State

PGP is disabled by default. Fresh installations start with both of these BBS settings turned off:

- `Enable PGP`
- `Allow BBS-managed private keys`

That means a sysop must explicitly turn the feature on before users see the PGP settings tab or the public keyserver routes.

## Enabling PGP

Open **Admin -> BBS Settings** and enable:

- `Enable PGP` to expose user key management and the public keyserver
- `Allow BBS-managed private keys` only if you want the system to host encrypted private keys for users

The second setting depends on the first one. If PGP is disabled, managed-key generation is also unavailable.

## User-Facing Behavior

When `Enable PGP` is on:

- users see a **PGP** tab in their settings page
- users can upload armored public keys
- users can select a preferred key from a dropdown/list
- the public keyserver becomes available

The keyserver publishes the user's preferred key and key listings for lookup.

When `Allow BBS-managed private keys` is also on:

- the PGP settings page shows a managed-key generator
- generated private keys are handled by the browser and stored by the BBS in encrypted form
- users can retrieve their stored private key material later from their account key list
- users can encrypt outgoing netmail to a recipient's preferred public key from the compose screen
- users can sign outgoing echomail with their stored private key from the compose screen
- readers can decrypt encrypted netmail or verify signed echomail in the message viewer

If managed keys are disabled, the PGP tab still works for public-key upload and preferred-key selection, but the generator section is replaced with a notice.

If `Allow BBS-managed private keys` is off, the server does not provide the stored private key material needed for browser-side signing or decryption. In that mode, users can still publish public keys and choose a primary key, but the compose and reader-side PGP message handling stays disabled.

## Compose Lookup

Netmail encryption uses the compose form's recipient lookup. The browser searches for public keys using the text in the recipient fields and shows an explicit selector before the message is encrypted.

The lookup can match:

- the key fingerprint
- the published PGP user ID
- the key label, if one was set
- the BBS user's username or real name
- saved address-book entries and local user matches surfaced by the address-book search API

This matters because a user's published PGP identity and their BBS account name are not always the same thing. The selector is there so the user can choose the exact public key before sending.

When the destination address is blank or points at one of this system's own FTN addresses, compose searches the local public-key store only.

When the destination is a remote FTN address, compose switches to remote lookup:

- it first checks the current user's saved correspondent keys, including any key linked to an address-book contact
- it resolves the destination node in the nodelist
- it extracts the node's BinkP hostname from the nodelist flags
- it checks for `_hkps._tcp.<hostname>` SRV records and uses that target when present
- if no HKPS SRV record exists, it falls back to `https://<nodelist-hostname>/pks/lookup`

Remote HKP requests use a 3-second timeout so compose does not stall for long on unreachable systems.

Saved correspondent keys are private to the owning user account. They are not published on `/pks/lookup` and do not change the user's preferred public key on the keyserver.

The compose screen only shows the recipient selector when managed private keys are enabled, because the netmail encryption and echomail signing flows depend on browser-side access to the stored private key.

## Public Endpoints

When PGP is enabled, the following routes are active:

- `/keyserver`
- `/pks/lookup`
- `/pks/add`
- `/pks/download/{fingerprint}`

These are the public-facing keyserver routes used for discovery and retrieval.

The authenticated compose UI also uses `GET /api/pgp/lookup` for destination-aware local-vs-remote key resolution.

## Operational Notes

- Use the admin UI to change the BBS settings. The feature flags live in `config/bbs.json`, but the supported path is **Admin -> BBS Settings**.
- If the PGP tab is missing from user settings, confirm that `Enable PGP` is on.
- If users can upload keys but cannot generate managed keys, confirm that `Allow BBS-managed private keys` is on.
- Changing the preferred key affects which key is published and used as the user's primary public key.
- Netmail encryption and echomail signing both require managed private keys. If you leave managed keys disabled, those compose and reader features stay hidden.
- If users see address-book results but not local user matches in the compose autocomplete, confirm the address-book search route is returning both saved entries and local-user matches.

## Administration Checklist

Before you enable this feature in production, decide whether you want the BBS to host private key material at all.

Recommended rollout order:

1. Enable `Enable PGP`
2. Test public-key upload and preferred-key selection
3. Decide whether to enable `Allow BBS-managed private keys`
4. Communicate the policy to users so they know whether they should generate keys locally or use the managed option

If you only want public-key publishing, leave managed private keys disabled.
