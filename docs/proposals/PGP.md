# PGP Integration Proposal

> **Draft status**: This proposal is a draft, was generated with AI assistance, and may not have been reviewed for accuracy. It should be treated as a starting point for discussion, not a final specification.

## Overview

This proposal outlines how PGP (Pretty Good Privacy) functionality can be integrated into BinktermPHP to provide users with message signing, message encryption, and a public key directory. The goal is to give users meaningful cryptographic tools without forcing the BBS to hold or manage plaintext private keys.

The scope of this proposal is **user-facing** features only. Node-to-node binkp authentication is explicitly out of scope.

---

## Goals

- Allow users to associate a PGP public key with their BBS account
- Provide a keyserver so users and external tools can discover keys
- Allow users to sign netmail and echomail messages
- Allow users to encrypt netmail to another user
- Verify and display the authenticity of signed messages
- Gracefully degrade when one or both parties use an external (bring-your-own) key workflow

---

## Key Management Models

Two models coexist. Users can choose either, and both publish to the same keyserver.

### Model A: BBS-Managed Keys

The BBS generates a keypair on behalf of the user, entirely in the browser using [OpenPGP.js](https://openpgpjs.org/).

**Key generation flow:**

1. User navigates to profile settings and clicks "Generate PGP Key".
2. OpenPGP.js generates a keypair in the browser.
3. User sets a passphrase. OpenPGP.js encrypts the private key with this passphrase (`openpgp.encryptKey()`).
4. The **encrypted private key blob** is sent to the server and stored. The server never sees the plaintext private key.
5. The public key is stored and published to the keyserver.

**Usage flow (signing or encrypting):**

1. User enters their passphrase in the compose UI.
2. OpenPGP.js fetches the encrypted private key blob from the server, decrypts it client-side using the passphrase.
3. The plaintext private key exists only in browser memory for the duration of the operation.
4. The signed or encrypted armor block is submitted as part of the message.

**Security note:** The encrypted private key blob is stored server-side. If the server is compromised, an attacker obtains this blob and can attempt offline passphrase brute-force. Users should choose a strong passphrase. This risk is inherent to any hosted key model and should be disclosed to users in the UI.

### Model B: Bring Your Own Key

The user manages their keypair entirely outside the BBS.

**Setup flow:**

1. User pastes their ASCII-armored public key into their profile settings.
2. The BBS stores it, associates it with the account, and publishes it to the keyserver.
3. The fingerprint is displayed for the user to verify.

**Usage flow (signing):**

1. User composes a message outside the BBS (or copies the draft), signs it with their local GPG tooling, and pastes the resulting armor block into the message body.
2. The BBS detects the armor block, verifies the signature against the stored public key, and displays a verification badge.

**Usage flow (encryption, receiving):**

1. An encrypted message arrives. The BBS displays the armor block.
2. The user copies it and decrypts it with their local GPG tooling.

This model is maximally secure — the server never touches private key material in any form — but the UX is manual.

---

## Keyserver

The BBS exposes an **HKP-compatible** (HTTP Keyserver Protocol) endpoint. This allows external tools such as `gpg --keyserver`, Thunderbird, and other OpenPGP clients to look up and submit keys directly.

### HKP Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/pks/lookup?op=get&search=<query>` | Fetch a key by email, username, or fingerprint |
| `GET` | `/pks/lookup?op=index&search=<query>` | Search for keys (machine-readable index) |
| `POST` | `/pks/add` | Submit a public key |

The `search` parameter accepts:
- Full fingerprint (`0x<hex>`)
- Email address
- BBS username

### Web UI

In addition to HKP, a web interface at `/keyserver` (or similar) allows browsing and searching keys with a human-readable view, including:

- Username and real name
- Key fingerprint
- Key creation date
- Subkey details
- Link to download the armored public key

### Key Pinning (Not Web of Trust)

The BBS does not implement a web of trust. Instead, it implements **key pinning**: a public key fingerprint is associated with a BBS user account. The BBS's assertion is simply: *"This user presented this key and we recorded it."* No trust chain, no cross-signatures.

This is sufficient for the primary use case: verifying that a message was signed by the same person who controls a given BBS account.

---

## Message Signing

### Netmail

- Compose UI includes a **"Sign this message"** option.
- With a BBS-managed key: user enters passphrase; OpenPGP.js signs the message body in-browser; a `-----BEGIN PGP SIGNED MESSAGE-----` armor block is stored as the message body.
- With bring-your-own: user signs externally and pastes the signed armor block as the message body.

**Display:**
- When rendering a netmail, the BBS detects a signed armor block in the body.
- It extracts the plaintext content and verifies the signature against the public key on file for the sender.
- A verification badge is shown:
  - **Verified** — signature valid, key matches the sender's account
  - **Unverified** — valid signature but key not associated with this account
  - **Invalid** — signature verification failed
  - *(no badge)* — message is not signed

### Echomail

Signing echomail is supported but the implications are different: echomail propagates across the FTN network and most readers will not be using this BBS. Nodes that do not recognize the armor block will see it as raw text in the message body.

The BBS renders signed echomail with a verification badge the same way as netmail. Users on other systems see the raw armor block (the signed cleartext format makes the message readable regardless).

This is opt-in and users should understand that signing echomail provides verification only for readers whose software can verify it.

---

## Message Encryption

Encryption applies to **netmail only**. Echomail is a broadcast medium and encrypting it does not make sense.

### Encrypt Flow

1. User composes a netmail.
2. User checks **"Encrypt this message"**.
3. The BBS fetches the recipient's public key from the keyserver (if available).
4. OpenPGP.js encrypts the message body to the recipient's public key (and optionally also to the sender's own public key so the sender can re-read the message).
5. The encrypted armor block is stored as the message body.

If the recipient has no public key on file, the encrypt option is disabled or shows a warning.

### Decrypt Flow

**BBS-managed key recipient:**
1. Message body displays as an encrypted armor block with a **"Decrypt"** button.
2. User enters their passphrase.
3. OpenPGP.js decrypts in-browser and displays the plaintext inline.
4. Plaintext is never sent to the server.

**Bring-your-own key recipient:**
1. Message body displays the raw armor block.
2. User copies and decrypts locally.

This asymmetry is acceptable. The BBS makes no attempt to hide which model the recipient is using.

---

## Database Schema

New tables (migration required):

```sql
-- Stores PGP public keys associated with user accounts
CREATE TABLE user_pgp_keys (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    fingerprint VARCHAR(40) NOT NULL,          -- 40-char hex, no spaces
    armored_public_key TEXT NOT NULL,
    key_created_at TIMESTAMPTZ,                -- from the key itself
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE(fingerprint)
);

CREATE INDEX idx_user_pgp_keys_user_id ON user_pgp_keys(user_id);

-- Stores encrypted private key blobs for BBS-managed keys (Model A)
-- Only populated for users who use the BBS key generation flow
-- One row per key (user may have multiple BBS-managed keys)
CREATE TABLE user_pgp_private_keys (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    fingerprint VARCHAR(40) NOT NULL REFERENCES user_pgp_keys(fingerprint) ON DELETE CASCADE,
    encrypted_private_key TEXT NOT NULL,       -- OpenPGP.js passphrase-encrypted blob
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(fingerprint)
);
```

---

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/pgp/key/:userId` | Fetch a user's public key by user ID |
| `POST` | `/api/pgp/key` | Upload or replace own public key |
| `DELETE` | `/api/pgp/key/:fingerprint` | Remove a specific key by fingerprint |
| `GET` | `/api/pgp/private-key/:fingerprint` | Fetch a specific encrypted private key blob (authenticated) |
| `POST` | `/api/pgp/generate` | Store a newly generated public + encrypted private key pair |
| `GET` | `/pks/lookup` | HKP lookup |
| `POST` | `/pks/add` | HKP key submission |

---

## UI Integration Points

- **Profile / Settings page** — key management section: generate key, upload key, list all keys with fingerprints and labels, revoke/remove individual keys
- **Compose (netmail)** — "Sign" and "Encrypt" options; key selector if user has multiple keys; passphrase prompt (for BBS-managed keys)
- **Compose (echomail)** — "Sign" option only; key selector if user has multiple keys
- **Message view (netmail)** — signature verification badge; decrypt button for encrypted messages
- **Message view (echomail)** — signature verification badge
- **User profile page** — show key fingerprint if user has one, link to download public key
- **Keyserver web UI** — `/keyserver` — searchable directory of all user public keys

---

## Dependencies

- **[OpenPGP.js](https://openpgpjs.org/)** — client-side PGP operations (key generation, signing, encryption, verification, decryption). Loaded as a JS dependency. All private key operations happen exclusively in the browser.

---

## Security Considerations

- The server stores encrypted private key blobs (Model A). Compromise of the server gives an attacker the blob but not the plaintext key without the user's passphrase.
- The plaintext private key is never transmitted to or stored by the server.
- The PGP passphrase **must** be different from the user's login password. This is enforced in the UI and explained to users at key generation time: if the login password is compromised (e.g. via a phishing attack or server breach), the encrypted private key blob remains protected as long as the passphrase is distinct. Using the same value for both would collapse two separate security layers into one.
- Users should be clearly informed of the passphrase security model when generating a key.
- Revoking a key removes the association from the BBS but does not broadcast a revocation certificate to external keyservers. If external distribution is implemented later, revocation handling should be revisited.
- Verification of signed messages is only meaningful against keys pinned to BBS accounts. An "Unverified" signature badge indicates a valid cryptographic signature by an unknown key — it does not indicate fraud.

---

## Out of Scope

- **Node-to-node / binkp authentication** — not addressed in this proposal
- **Web of Trust** — deliberately excluded; key pinning is sufficient for the use cases described
- **Signed file areas** — may be appropriate for a separate proposal targeting the binkterm-php-admin tooling
- **Key expiration / rotation policy** — users can add new keys and revoke old ones manually; automated expiration enforcement is a future consideration
- **External keyserver synchronization** — keys are not pushed to or pulled from external keyservers (keys.openpgp.org, etc.)

---

## Open Questions

1. Should the BBS notify a user when their public key is used to encrypt an incoming message (i.e., they have a new encrypted netmail)?
2. Should signed-but-unverified echomail be rendered differently from unsigned echomail, or treated the same?
