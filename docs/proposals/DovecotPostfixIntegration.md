> **Draft** — This proposal was generated with AI assistance and may not have been reviewed for accuracy. It is intended as a starting point for discussion, not a finalized specification.

> **Premium Feature** — The Dovecot/Postfix email integration is a registered/premium feature. It will be gated behind `License::isValid()` and will not be available on unregistered installs. Update `docs/proposals/PremiumFeatures.md` when this feature is implemented.

# Dovecot/Postfix Email Integration

## Overview

This proposal describes how binkterm-php can provision and manage a Postfix/Dovecot mail stack so that every registered BBS user automatically receives a personal email address in the form `username@yourbbs.com`. The web admin interface would manage mailbox creation, deletion, and password sync without requiring the sysop to touch the command line for day-to-day user management.

The goal is to make the BBS feel like a complete online community platform — one where users can receive external mail, use standard IMAP/SMTP clients (Thunderbird, K-9, etc.), and optionally have BBS-internal netmail forwarded to their mailbox.

---

## Sysop Considerations

Before enabling this feature, BBS operators should think carefully through the following policy decisions. Running an email server is a significant undertaking — it is technically complex to configure correctly, requires ongoing maintenance (TLS renewals, spam filtering, monitoring), and carries real risk of abuse. The sections below are not optional reading; they are decisions that must be made before provisioning a single mailbox.

### Should you run an email server at all?

Email is one of the most abuse-prone services on the internet. A misconfigured or unmonitored mail server will be found by spammers and used to send bulk mail, potentially getting your IP address blacklisted within hours. Before proceeding, consider whether the benefit to your community justifies the operational burden. If your BBS already has a strong, established user base and you are comfortable with Linux server administration, this feature can genuinely enhance the community experience. If you are running a small or new BBS, you may want to wait until the community has proven itself before taking on this responsibility.

### Who can access email?

**This is the most consequential policy decision you will make.** Giving every new user an email address immediately on registration creates a low-cost path to sending spam from your domain. Options to consider:

- **Admin-only provisioning** (default) — the sysop manually provisions mailboxes for trusted users only. Best for small or tight-knit BBSes.
- **Auto-provision on registration** — every approved user gets a mailbox automatically. Only appropriate if your registration process includes meaningful vetting (e.g. a sysop-approved application, a CAPTCHA plus waiting period, or a referral requirement).
- **Self-provisioning** — users claim their own mailbox via **Profile → My Email**. Combines the convenience of auto-provision with a small friction barrier (the user must explicitly opt in), but does not reduce spam risk on its own.

You are not locked in — these settings can be changed at any time — but once a mailbox is provisioned and a user has configured a mail client against it, deprovisioning is disruptive. Set the right policy from the start.

### Outbound mail (submission port 587)

Enabling authenticated SMTP submission lets users send email from desktop clients such as Thunderbird. This is powerful but opens a direct channel for spam if any account is compromised. Rate-limiting outbound messages per account is strongly recommended but is not implemented in this proposal. If you are not prepared to monitor outbound mail volume, consider leaving submission disabled and treating this as an inbound-only/webmail installation.

### Domain strategy: shared vs. subdomain

`username@yourbbs.com` vs. `username@mail.yourbbs.com`:

- **Shared domain** — cleaner addresses, but requires that `yourbbs.com` has no existing MX records pointing elsewhere. If you already use Google Workspace or another provider for the domain's email, this will conflict.
- **Subdomain** — `mail.yourbbs.com` is independent of any existing MX configuration on the apex domain and is the safer default for most sysops.

### Auto-provisioning vs. per-user grants

The `auto_provision_on_registration` setting in **Admin → Settings → Email** controls whether mailboxes are created automatically for new users. When it is off, admins grant access individually from **Admin → Users → [user] → Email**. Both modes can coexist: auto-provision can be on globally while specific users are deprovisioned, or off globally while select users are provisioned by hand. Choose the mode that matches your trust model for incoming users.

---

## Architecture Overview

```
                  ┌─────────────────────────────┐
                  │         Internet              │
                  └─────────┬───────────────────┘
                            │ SMTP (port 25)
                  ┌─────────▼───────────────────┐
                  │   Postfix (MTA)              │
                  │   - Receives inbound mail    │
                  │   - Relays outbound mail     │
                  │   - Delivers to Dovecot      │
                  └─────────┬───────────────────┘
                            │ LMTP / virtual_mailbox
                  ┌─────────▼───────────────────┐
                  │   Dovecot (MDA / IMAP)       │
                  │   - Maildir storage           │
                  │   - IMAP (port 993 TLS)      │
                  │   - Authenticates via DB     │
                  └─────────┬───────────────────┘
                            │ SQL auth queries
                  ┌─────────▼───────────────────┐
                  │   binkterm-php PostgreSQL DB │
                  │   - mail_users table          │
                  │   - Shared with BBS users    │
                  └─────────────────────────────┘
```

Postfix and Dovecot both authenticate and look up mailboxes via the existing PostgreSQL database. The BBS admin panel controls user provisioning. No flat `/etc/passwd` entries or Postfix `virtual` text files are needed.

---

## Database Schema

### New table: `mail_users`

```sql
CREATE TABLE mail_users (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    email         TEXT NOT NULL UNIQUE,          -- username@yourbbs.com
    mail_password TEXT NOT NULL,                  -- bcrypt hash (separate from BBS password)
    maildir       TEXT NOT NULL,                  -- absolute path, e.g. /var/mail/vhosts/yourbbs.com/username/
    quota_mb      INTEGER NOT NULL DEFAULT 200,
    enabled       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_mail_users_user_id ON mail_users(user_id);
CREATE INDEX idx_mail_users_email   ON mail_users(email);
```

The `mail_password` is intentionally separate from the BBS login password. Dovecot needs a password it can verify directly; binkterm-php stores a bcrypt hash and exposes a `{BLF-CRYPT}` prefixed value for Dovecot's `password_query`.

### Migration file

`database/migrations/vYYYYMMDDHHMMSS_add_mail_users.sql`

### Restricted database user

Postfix and Dovecot only need `SELECT` on `mail_users` (and optionally `mail_aliases`). Create a dedicated PostgreSQL role with the minimum necessary privileges — do not reuse the main `binkterm` application user, which has access to the entire database.

```sql
-- Run as the postgres superuser
CREATE ROLE binkterm_mail WITH LOGIN PASSWORD 'change_me';
GRANT CONNECT ON DATABASE binkterm TO binkterm_mail;

-- Read-only access to the two tables Postfix and Dovecot query
GRANT SELECT ON mail_users  TO binkterm_mail;
GRANT SELECT ON mail_aliases TO binkterm_mail;  -- only if using alias maps
```

Use this `binkterm_mail` role in all Postfix pgsql map files and the Dovecot SQL config. Do **not** grant it access to `users`, `echomail`, `netmail`, or any other BBS table.

---

## Postfix Configuration

Postfix is configured to use PostgreSQL maps rather than flat files. Four map files are needed under `/etc/postfix/pgsql/`:

| Map file | Postfix parameter | Purpose |
|---|---|---|
| `virtual_mailbox_domains.cf` | `virtual_mailbox_domains` | Declare the hosted domain |
| `virtual_mailbox_maps.cf` | `virtual_mailbox_maps` | Map address → maildir path |
| `virtual_alias_maps.cf` | `virtual_alias_maps` | Optional address aliases |
| `virtual_uid_maps.cf` | `virtual_uid_gid_maps` | UID of the `vmail` system user |

Example `virtual_mailbox_maps.cf`:

```ini
hosts     = 127.0.0.1
user      = binkterm_mail
password  = <binkterm_mail_password>
dbname    = binkterm
query     = SELECT maildir FROM mail_users WHERE email = '%s' AND enabled = true
```

Key `main.cf` additions:

```
virtual_mailbox_domains = pgsql:/etc/postfix/pgsql/virtual_mailbox_domains.cf
virtual_mailbox_base    = /var/mail/vhosts
virtual_mailbox_maps    = pgsql:/etc/postfix/pgsql/virtual_mailbox_maps.cf
virtual_minimum_uid     = 100
virtual_uid_maps        = static:5000
virtual_gid_maps        = static:5000
virtual_transport       = lmtp:unix:private/dovecot-lmtp
```

The `vmail` system user (uid 5000) owns all maildir storage. Postfix drops privileges to this user when delivering.

---

## Dovecot Configuration

Dovecot authenticates users and stores mail. The key configuration pieces:

### `/etc/dovecot/conf.d/auth-sql.conf.ext`

```
passdb {
    driver   = sql
    args     = /etc/dovecot/dovecot-sql.conf.ext
}

userdb {
    driver   = sql
    args     = /etc/dovecot/dovecot-sql.conf.ext
}
```

### `/etc/dovecot/dovecot-sql.conf.ext`

```
driver    = pgsql
connect   = host=127.0.0.1 dbname=binkterm user=binkterm_mail password=<binkterm_mail_password>

password_query = \
    SELECT mail_password AS password \
    FROM mail_users \
    WHERE email = '%u' AND enabled = true

user_query = \
    SELECT maildir AS home, \
           'maildir:' || maildir AS mail, \
           5000 AS uid, 5000 AS gid, \
           CONCAT('maildir_storage=', quota_mb, 'M') AS quota_rule \
    FROM mail_users \
    WHERE email = '%u' AND enabled = true
```

Dovecot resolves the password hash prefix automatically when the stored value is prefixed with `{BLF-CRYPT}`.

### Dovecot LMTP

Enable the LMTP socket so Postfix can hand off mail:

```
service lmtp {
    unix_listener /var/spool/postfix/private/dovecot-lmtp {
        mode  = 0600
        user  = postfix
        group = postfix
    }
}
```

---

## binkterm-php Integration

### New class: `src/Mail/MailboxManager.php`

Handles all mailbox lifecycle operations:

```php
namespace BinktermPHP\Mail;

class MailboxManager
{
    public function provision(int $userId): void;   // creates DB row + maildir
    public function deprovision(int $userId): void; // disables row, optionally purges maildir
    public function setPassword(int $userId, string $plaintext): void; // bcrypt + store
    public function setQuota(int $userId, int $quotaMb): void;
    public function isProvisioned(int $userId): bool;
    public static function toEmailLocalPart(string $username): string; // sanitize username → RFC 5321 local-part
}
```

`provision()` creates the `mail_users` row and the Maildir directory structure on disk (`new/`, `cur/`, `tmp/`). It does **not** call any Postfix/Dovecot CLI tools — the database query alone is sufficient.

Because the web server process cannot write arbitrary filesystem paths, `provision()` must send a command to the admin daemon rather than calling `mkdir()` directly.

#### Username sanitization (`toEmailLocalPart`)

BBS usernames must be mapped to a valid RFC 5321 local-part before use as an email address:

- Lowercase the entire string
- Replace spaces with hyphens (spaces are already forbidden in usernames, but handled defensively)
- Strip any character that is not `a-z`, `0-9`, `-`, `_`, or `.`
- Collapse runs of two or more consecutive `-` or `.` characters into a single `-`
- Strip leading and trailing `-` and `.`

```php
public static function toEmailLocalPart(string $username): string
{
    $local = mb_strtolower($username, 'UTF-8');
    $local = str_replace(' ', '-', $local);
    $local = preg_replace('/[^a-z0-9\-_.]/', '', $local);
    $local = preg_replace('/[\-\.]{2,}/', '-', $local);
    return trim($local, '-.');
}
```

The sanitized local-part is stored in `mail_users.email` at provisioning time and never recomputed from the username — if a user renames their BBS account, their email address stays the same. The admin can manually reassign the address if needed.

### Admin Daemon Command: `MAIL_PROVISION`

Add a new command to `scripts/admin_daemon.php`:

```
MAIL_PROVISION <user_id>
```

The daemon creates the maildir at the configured path and sets ownership to the `vmail` user. It then inserts (or re-enables) the `mail_users` row.

Similarly, `MAIL_DEPROVISION <user_id>` disables the row and optionally removes the maildir (configurable; default: keep data for 30 days).

See `docs/AdminDaemon.md` for the full command reference — both commands must be added there.

### New admin routes

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/admin/api/mail/provision/{userId}` | Provision mailbox for a user |
| `POST` | `/admin/api/mail/deprovision/{userId}` | Disable/remove mailbox |
| `POST` | `/admin/api/mail/set-quota/{userId}` | Update quota |
| `GET`  | `/admin/api/mail/status/{userId}` | Return provisioning status |
| `POST` | `/admin/api/mail/reset-password/{userId}` | Generate new mail password |

These live in `routes/admin-routes.php`.

### Admin UI

A new panel in **Admin → Users → [user] → Email** showing:

- Whether the mailbox is provisioned
- The assigned email address (`username@yourbbs.com`)
- Current quota and usage (queried via `du` through the admin daemon)
- Buttons: Provision, Deprovision, Reset Password, Change Quota

Two global toggles in **Admin → Settings → Email** control the feature:

- **Enable email accounts** — master switch; when off, all mail UI is hidden and no provisioning is allowed.
- **Allow users to provision their own mailboxes** — when on, users can claim a mailbox themselves without admin involvement (default: off). See [User Self-Service Provisioning](#user-self-service-provisioning) below.

### Global settings (`bbs.json`)

```json
"mail": {
    "enabled": false,
    "domain": "yourbbs.com",
    "maildir_base": "/var/mail/vhosts",
    "default_quota_mb": 200,
    "vmail_uid": 5000,
    "vmail_gid": 5000,
    "auto_provision_on_registration": false,
    "allow_self_provisioning": false
}
```

- `auto_provision_on_registration` — when `true`, a mailbox is created automatically when a new user account is approved. Admins can always provision or deprovision individual users from **Admin → Users → [user] → Email** regardless of this setting.
- `allow_self_provisioning` — when `true`, logged-in users can provision their own mailbox via **Profile → My Email** without admin action. Default `false`.

---

## User-Facing Features

### Web UI

**Profile → My Email** is the user's hub for email. Its content depends on provisioning state:

**When the user has no mailbox and `allow_self_provisioning` is `true`:**

A provisioning prompt is shown:

> You don't have a BBS email address yet. Claim `username@yourbbs.com` to send and receive email from any mail client.
>
> [Set a password for your email account]  _(input field)_
>
> [Confirm password]  _(input field)_
>
> **[Claim my email address]**  _(button)_

Submitting the form calls `POST /api/mail/provision` with the chosen password. The server validates the password meets minimum requirements, triggers `MAIL_PROVISION` via the admin daemon, and returns the connection details on success. The page then transitions to the provisioned state below.

**When the user has no mailbox and `allow_self_provisioning` is `false`:**

A placeholder is shown:

> Email accounts are managed by the sysop. Contact the administrator to request an email address.

**When the user has a provisioned mailbox:**

- Their assigned address and IMAP/SMTP connection card:

```
Incoming (IMAP):  mail.yourbbs.com  port 993  TLS
Outgoing (SMTP):  mail.yourbbs.com  port 587  STARTTLS
Username:         username@yourbbs.com
Password:         (your email password — separate from BBS login)
```

- A "Change Email Password" form to update the mail password independently of the BBS login password.

### User self-service API routes

| Method | Path | Purpose |
|---|---|---|
| `GET`  | `/api/mail/status` | Return provisioning state for the current user |
| `POST` | `/api/mail/provision` | Provision own mailbox (requires `allow_self_provisioning`) |
| `POST` | `/api/mail/set-password` | Change own email password |

`POST /api/mail/provision` checks that `mail.allow_self_provisioning` is `true`, that the user is not already provisioned, and that the submitted password meets minimum strength requirements before dispatching the admin daemon command. It returns `403` if self-provisioning is disabled.

### Telnet/SSH terminal

A settings option in the terminal's settings menu lets users change their email password and view their address, parallel to the web UI (per the user settings parity policy in `CLAUDE.md`). When `allow_self_provisioning` is enabled and the user has no mailbox, the terminal settings menu also shows a **Claim email address** option that prompts for a password and calls the same provisioning path.

---

## Optional: Netmail → Email Forwarding

When a user receives a FTN netmail message, binkterm-php can optionally forward a copy to their BBS mailbox. This would be a per-user setting (`forward_netmail_to_email`) stored in `user_settings`.

The forwarded message would be formatted as a plain-text email with the original netmail headers preserved as metadata. Implementation uses PHP's `mail()` function or a configured SMTP relay — not a direct Postfix socket call.

---

## Security Considerations

- **Least-privilege database access**: Postfix and Dovecot must connect as the `binkterm_mail` role, which has `SELECT` only on `mail_users` and `mail_aliases`. Never use the main `binkterm` application credentials in the Postfix map files or Dovecot SQL config.
- **TLS everywhere**: Dovecot must be configured with a valid TLS certificate (Let's Encrypt recommended) for both IMAP (993) and submission (587 STARTTLS). Plaintext IMAP on port 143 should be disabled.
- **SPF/DKIM/DMARC**: The sysop must publish SPF records and configure Postfix with OpenDKIM before outbound mail will be accepted by major providers. This is documented in the setup guide but is outside the scope of the binkterm-php codebase itself.
- **Separate email password**: The mail password must never be the same as the BBS login password. The UI must make this clear, and the backend must store them independently.
- **Quota enforcement**: Postfix `virtual_mailbox_limit` and Dovecot quota plugin both enforce limits to prevent a single user from filling the disk.
- **Spam filtering**: Integration with SpamAssassin or Rspamd via Postfix `content_filter` is recommended but out of scope for this proposal.
- **Admin daemon privilege**: The `MAIL_PROVISION` daemon command runs as root (or as the `vmail` user via `setuid`). The admin daemon already handles similar privilege escalation for binkp operations; the same sandboxing model applies.

---

## Installation / Setup Flow

1. Sysop installs Postfix and Dovecot on the server (documented in the install guide).
2. Sysop creates the `vmail` system user and the maildir base directory.
3. Sysop creates the restricted `binkterm_mail` PostgreSQL role with `SELECT` on `mail_users` and `mail_aliases`.
4. Sysop copies the four Postfix pgsql map files and edits them with `binkterm_mail` credentials.
5. Sysop copies the Dovecot SQL config and edits it with `binkterm_mail` credentials.
6. Sysop sets `mail.enabled = true` and `mail.domain` in **Admin → Settings → Email**.
7. `php scripts/setup.php` runs the `mail_users` migration.
8. The admin daemon is restarted so it picks up the new `MAIL_PROVISION` command handler.
9. Sysop provisions mailboxes individually per user, or enables `auto_provision_on_registration`.

Detailed step-by-step instructions (with example config file snippets) would live in a new `docs/install-guide-email.md`.

---

## Debian / Ubuntu Installation Guide

> **TODO**: The manual steps below should be wrapped in an interactive installer script (`scripts/install-email.sh`) that walks the sysop through the process, generates config files from prompts (domain name, DB credentials, certificate path), and validates the result. The goal is to reduce a successful email install to a single `sudo bash scripts/install-email.sh` invocation with no manual file editing required.

This section provides concrete installation steps for Debian 12 (Bookworm) and Ubuntu 22.04/24.04. Commands assume a non-root user with `sudo` access.

### 1. Install packages

```bash
sudo apt update
sudo apt install -y \
    postfix postfix-pgsql \
    dovecot-core dovecot-imapd dovecot-lmtpd dovecot-pgsql \
    libsasl2-modules
```

During the Postfix install prompt, select **Internet Site** and enter your mail domain (e.g. `yourbbs.com`).

### 2. Create the `vmail` system user

All maildir storage is owned by a dedicated non-login user. Using a fixed UID/GID (5000) ensures consistency across reinstalls and matches the values in the Postfix and Dovecot config examples.

```bash
sudo groupadd -g 5000 vmail
sudo useradd -u 5000 -g 5000 -d /var/mail/vhosts -s /usr/sbin/nologin vmail
sudo mkdir -p /var/mail/vhosts/yourbbs.com
sudo chown -R vmail:vmail /var/mail/vhosts
sudo chmod -R 770 /var/mail/vhosts
```

### 3. Create a restricted PostgreSQL user for mail

Postfix and Dovecot only need `SELECT` on `mail_users` and `mail_aliases`. Create a dedicated role rather than reusing the main application credentials:

```bash
sudo -u postgres psql binkterm <<'SQL'
CREATE ROLE binkterm_mail WITH LOGIN PASSWORD 'change_me';
GRANT CONNECT ON DATABASE binkterm TO binkterm_mail;
GRANT SELECT ON mail_users   TO binkterm_mail;
GRANT SELECT ON mail_aliases TO binkterm_mail;
SQL
```

Replace `change_me` with a strong, randomly generated password. Store it securely (e.g. in a password manager); you will need it when writing the Postfix map files and the Dovecot SQL config in steps 6 and 7. This role has no access to `users`, `echomail`, `netmail`, or any other BBS table.

### 4. DNS: add an MX record

Before any mail can be received, your domain needs an MX record pointing to this server. Add to your DNS zone:

```
yourbbs.com.      MX  10  mail.yourbbs.com.
mail.yourbbs.com. A       <your server IP>
```

Changes propagate in minutes to hours depending on your registrar's TTL. You can check propagation with `dig MX yourbbs.com`.

### 5. TLS certificate

Dovecot and Postfix need to read the certificate and private key files directly. If you are already running Caddy as your web server, **do not point Dovecot/Postfix at Caddy's certificate store** (`/var/lib/caddy/.local/share/caddy/certificates/`). Caddy owns those files as mode `600` and stores them in an internal path structure that can change between versions — other services cannot read them reliably.

Instead, obtain a dedicated certificate for the mail hostname using Certbot:

```bash
sudo apt install -y certbot

# If Caddy is running on port 80, stop it briefly or use the DNS challenge.
# For a standalone certificate (Caddy must not be holding port 80):
sudo certbot certonly --standalone -d mail.yourbbs.com

# Alternatively, use the DNS-01 challenge to avoid any port conflicts:
# sudo certbot certonly --manual --preferred-challenges dns -d mail.yourbbs.com
```

Certbot places the certificate at `/etc/letsencrypt/live/mail.yourbbs.com/` with permissions that allow the `ssl-cert` group to read the private key. Certificates renew automatically via the `certbot.timer` systemd unit installed by the package.

Add `dovecot` and `postfix` to the `ssl-cert` group so they can read the key file:

```bash
sudo usermod -aG ssl-cert dovecot
sudo usermod -aG ssl-cert postfix
```

If you prefer to keep a single ACME client, an alternative is to configure Caddy to run a deploy hook that copies the renewed certificate to `/etc/ssl/mail/` and reloads Dovecot and Postfix. This adds complexity and is not recommended for most installs.

<details>
<summary>Caddy deploy hook example (advanced)</summary>

Create the destination directory and set ownership so Dovecot and Postfix can read the files:

```bash
sudo mkdir -p /etc/ssl/mail
sudo chown root:ssl-cert /etc/ssl/mail
sudo chmod 750 /etc/ssl/mail
```

Add an `on_demand` exec block to your `Caddyfile` (or a separate `deploy.sh` script invoked by Caddy's `exec` hook):

**`/etc/caddy/deploy-mail-cert.sh`**

```bash
#!/bin/bash
# Called by Caddy after a successful certificate renewal for mail.yourbbs.com.
set -euo pipefail

DOMAIN="mail.yourbbs.com"
CADDY_CERT_DIR="/var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/${DOMAIN}"
DEST="/etc/ssl/mail"

cp "${CADDY_CERT_DIR}/${DOMAIN}.crt" "${DEST}/fullchain.pem"
cp "${CADDY_CERT_DIR}/${DOMAIN}.key" "${DEST}/privkey.pem"
chmod 640 "${DEST}/fullchain.pem" "${DEST}/privkey.pem"
chown root:ssl-cert "${DEST}/fullchain.pem" "${DEST}/privkey.pem"

systemctl reload dovecot
systemctl reload postfix
```

```bash
sudo chmod +x /etc/caddy/deploy-mail-cert.sh
```

Wire it into the `Caddyfile` using Caddy's `exec` directive (requires the [caddy-exec](https://github.com/abiosoft/caddy-exec) plugin, or Caddy built with it):

```caddy
mail.yourbbs.com {
    tls {
        on_demand
    }
}

exec {
    command /etc/caddy/deploy-mail-cert.sh
    startup
    on_renew
}
```

Then point Dovecot and Postfix at `/etc/ssl/mail/` instead of the Certbot paths:

```
# /etc/postfix/main.cf
smtpd_tls_cert_file = /etc/ssl/mail/fullchain.pem
smtpd_tls_key_file  = /etc/ssl/mail/privkey.pem

# /etc/dovecot/conf.d/10-ssl.conf
ssl_cert = </etc/ssl/mail/fullchain.pem
ssl_key  = </etc/ssl/mail/privkey.pem
```

Run the script once manually after setup to perform the initial copy before starting the mail services.

</details>

### 6. Configure Postfix

#### `/etc/postfix/main.cf` — append or replace the relevant lines

```
# Identity
myhostname = mail.yourbbs.com
mydomain   = yourbbs.com
myorigin   = $mydomain

# Virtual mailbox hosting
virtual_mailbox_domains = pgsql:/etc/postfix/pgsql/virtual_mailbox_domains.cf
virtual_mailbox_base    = /var/mail/vhosts
virtual_mailbox_maps    = pgsql:/etc/postfix/pgsql/virtual_mailbox_maps.cf
virtual_minimum_uid     = 100
virtual_uid_maps        = static:5000
virtual_gid_maps        = static:5000

# Deliver via Dovecot LMTP
virtual_transport = lmtp:unix:private/dovecot-lmtp

# TLS for inbound connections
smtpd_tls_cert_file = /etc/letsencrypt/live/mail.yourbbs.com/fullchain.pem
smtpd_tls_key_file  = /etc/letsencrypt/live/mail.yourbbs.com/privkey.pem
smtpd_tls_security_level = may

# Submission (port 587) is enabled in master.cf — see below
```

#### `/etc/postfix/master.cf` — enable the submission port

Uncomment or add the submission service block:

```
submission inet n - y - - smtpd
    -o syslog_name=postfix/submission
    -o smtpd_tls_security_level=encrypt
    -o smtpd_sasl_auth_enable=yes
    -o smtpd_sasl_type=dovecot
    -o smtpd_sasl_path=private/auth
    -o smtpd_relay_restrictions=permit_sasl_authenticated,reject
    -o milter_macro_daemon_name=ORIGINATING
```

#### PostgreSQL map files

Create the directory and set restrictive permissions so credentials are not world-readable:

```bash
sudo mkdir -p /etc/postfix/pgsql
sudo chmod 750 /etc/postfix/pgsql
```

**`/etc/postfix/pgsql/virtual_mailbox_domains.cf`**

```ini
hosts     = 127.0.0.1
user      = binkterm_mail
password  = <binkterm_mail_password>
dbname    = binkterm
query     = SELECT 1 FROM (VALUES ('yourbbs.com')) AS d(domain) WHERE domain = '%s'
```

**`/etc/postfix/pgsql/virtual_mailbox_maps.cf`**

```ini
hosts     = 127.0.0.1
user      = binkterm_mail
password  = <binkterm_mail_password>
dbname    = binkterm
query     = SELECT maildir FROM mail_users WHERE email = '%s' AND enabled = true
```

**`/etc/postfix/pgsql/virtual_alias_maps.cf`** (optional — for catch-all or admin aliases)

```ini
hosts     = 127.0.0.1
user      = binkterm_mail
password  = <binkterm_mail_password>
dbname    = binkterm
query     = SELECT destination FROM mail_aliases WHERE source = '%s'
```

Set ownership so the `postfix` user can read these files:

```bash
sudo chown root:postfix /etc/postfix/pgsql/*.cf
sudo chmod 640 /etc/postfix/pgsql/*.cf
```

### 7. Configure Dovecot

Dovecot's configuration is split across files under `/etc/dovecot/conf.d/`. Edit or create the files below.

#### `/etc/dovecot/conf.d/10-mail.conf` — maildir location

```
mail_location = maildir:/var/mail/vhosts/%d/%n/
mail_privileged_group = vmail
```

`%d` expands to the domain and `%n` to the local part of the address. Dovecot creates the maildir on first delivery.

#### `/etc/dovecot/conf.d/10-auth.conf` — enable SQL auth

```
disable_plaintext_auth = yes
auth_mechanisms        = plain login

# Comment out the default system auth include and add the SQL one:
#!include auth-system.conf.ext
!include auth-sql.conf.ext
```

#### `/etc/dovecot/conf.d/auth-sql.conf.ext`

```
passdb {
    driver = sql
    args   = /etc/dovecot/dovecot-sql.conf.ext
}

userdb {
    driver = sql
    args   = /etc/dovecot/dovecot-sql.conf.ext
}
```

#### `/etc/dovecot/dovecot-sql.conf.ext`

```
driver  = pgsql
connect = host=127.0.0.1 dbname=binkterm user=binkterm_mail password=<binkterm_mail_password>

default_pass_scheme = BLF-CRYPT

password_query = \
    SELECT mail_password AS password \
    FROM mail_users \
    WHERE email = '%u' AND enabled = true

user_query = \
    SELECT maildir AS home, \
           'maildir:' || maildir AS mail, \
           5000 AS uid, \
           5000 AS gid, \
           CONCAT('*:storage=', quota_mb, 'M') AS quota_rule \
    FROM mail_users \
    WHERE email = '%u' AND enabled = true

# Used by Dovecot for iterate_query (e.g. doveadm user *)
iterate_query = SELECT email AS username FROM mail_users WHERE enabled = true
```

Restrict read access to this file since it contains the database password:

```bash
sudo chown root:dovecot /etc/dovecot/dovecot-sql.conf.ext
sudo chmod 640 /etc/dovecot/dovecot-sql.conf.ext
```

#### `/etc/dovecot/conf.d/10-ssl.conf` — TLS

```
ssl        = required
ssl_cert   = </etc/letsencrypt/live/mail.yourbbs.com/fullchain.pem
ssl_key    = </etc/letsencrypt/live/mail.yourbbs.com/privkey.pem
ssl_min_protocol = TLSv1.2
```

#### `/etc/dovecot/conf.d/10-master.conf` — LMTP socket and auth socket for Postfix

Ensure these service blocks are present (they may already exist; merge rather than duplicate):

```
service lmtp {
    unix_listener /var/spool/postfix/private/dovecot-lmtp {
        mode  = 0600
        user  = postfix
        group = postfix
    }
}

service auth {
    unix_listener /var/spool/postfix/private/auth {
        mode  = 0660
        user  = postfix
        group = postfix
    }
    unix_listener auth-userdb {
        mode  = 0600
        user  = vmail
        group = vmail
    }
    user = dovecot
}

service auth-worker {
    user = vmail
}
```

#### `/etc/dovecot/conf.d/90-quota.conf` — quota plugin

```
plugin {
    quota           = maildir:User quota
    quota_rule      = *:storage=200M
    quota_max_mail_size = 25M
}

protocol imap {
    mail_plugins = $mail_plugins quota imap_quota
}

protocol lmtp {
    mail_plugins = $mail_plugins quota
}
```

The per-user `quota_rule` from the `user_query` above overrides the default `200M` for users with a custom quota.

### 8. SPF, DKIM, and DMARC

Without these records, outbound mail will land in spam or be rejected by Gmail, Outlook, and others.

#### SPF

Add a TXT record to your DNS zone:

```
yourbbs.com. TXT "v=spf1 mx ~all"
```

This permits the server listed in your MX record to send mail for the domain.

#### DKIM with OpenDKIM

```bash
sudo apt install -y opendkim opendkim-tools
```

Generate a key pair:

```bash
sudo mkdir -p /etc/opendkim/keys/yourbbs.com
sudo opendkim-genkey -D /etc/opendkim/keys/yourbbs.com/ -d yourbbs.com -s mail
sudo chown -R opendkim:opendkim /etc/opendkim/keys
```

Edit `/etc/opendkim.conf`:

```
Mode                  sv
Domain                yourbbs.com
Selector              mail
KeyFile               /etc/opendkim/keys/yourbbs.com/mail.private
Socket                local:/var/spool/postfix/opendkim/opendkim.sock
PidFile               /run/opendkim/opendkim.pid
UserID                opendkim:postfix
```

Create the socket directory:

```bash
sudo mkdir -p /var/spool/postfix/opendkim
sudo chown opendkim:postfix /var/spool/postfix/opendkim
```

Add the milter to `/etc/postfix/main.cf`:

```
milter_default_action = accept
milter_protocol       = 6
smtpd_milters         = local:/opendkim/opendkim.sock
non_smtpd_milters     = local:/opendkim/opendkim.sock
```

Publish the public key as a DNS TXT record. The key is in `/etc/opendkim/keys/yourbbs.com/mail.txt`; its value looks like:

```
mail._domainkey.yourbbs.com. TXT "v=DKIM1; k=rsa; p=MIGfMA0GCSq..."
```

#### DMARC

Add a TXT record:

```
_dmarc.yourbbs.com. TXT "v=DMARC1; p=none; rua=mailto:postmaster@yourbbs.com"
```

Start with `p=none` (monitoring only) and move to `p=quarantine` or `p=reject` once you have confirmed SPF and DKIM are passing.

### 9. Start and enable services

```bash
sudo systemctl enable --now dovecot
sudo systemctl enable --now postfix
sudo systemctl enable --now opendkim   # if installed

# Reload Postfix after config changes
sudo postfix check && sudo systemctl reload postfix

# Check for errors
sudo journalctl -u dovecot -n 50
sudo journalctl -u postfix -n 50
```

### 10. Verify the installation

```bash
# Check that Postfix can look up a mailbox
postmap -q username@yourbbs.com pgsql:/etc/postfix/pgsql/virtual_mailbox_maps.cf

# Check that Dovecot can authenticate a user
doveadm auth test username@yourbbs.com <email-password>

# Send a test message (requires mailutils)
sudo apt install -y mailutils
echo "Test body" | mail -s "Test subject" username@yourbbs.com

# Inspect the maildir to confirm delivery
ls /var/mail/vhosts/yourbbs.com/username/new/
```

### 11. Enable in binkterm-php

Once the mail stack is running:

1. In **Admin → Settings → Email**, set the domain to `yourbbs.com` and toggle **Enable email accounts** on.
2. Go to **Admin → Users**, open any user, and click **Email → Provision** to create their first mailbox.
3. Optionally, add a link to your Roundcube webmail URL (e.g. `https://mail.yourbbs.com/webmail`) in **Admin → BBS Settings → Appearance** so users can reach it from the BBS interface.

### 12. Install Roundcube webmail (optional)

Roundcube provides a browser-based IMAP client your users can access without configuring a desktop mail app. On Debian/Ubuntu:

```bash
sudo apt install -y roundcube roundcube-pgsql
```

The installer will prompt for a database to use. You can let it create a dedicated `roundcube` database, or create one manually first:

```bash
sudo -u postgres psql <<'SQL'
CREATE DATABASE roundcube OWNER binkterm;
SQL
```

#### Configure Roundcube

Edit `/etc/roundcube/config.inc.php` (Debian places it there; the package also symlinks it from the webroot):

```php
$config['db_dsnw'] = 'pgsql://binkterm:<db_password>@127.0.0.1/roundcube';

$config['default_host'] = 'ssl://localhost';
$config['default_port'] = 993;

$config['smtp_server'] = 'tls://localhost';
$config['smtp_port']   = 587;
$config['smtp_user']   = '%u';
$config['smtp_pass']   = '%p';

$config['product_name'] = 'YourBBS Webmail';
```

#### Serve it with Caddy

Add a block to your `Caddyfile` to expose Roundcube at a subpath or subdomain:

```caddy
mail.yourbbs.com {
    root * /var/lib/roundcube
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}
```

Or as a subpath under your existing domain:

```caddy
yourbbs.com {
    handle_path /webmail* {
        root * /var/lib/roundcube
        php_fastcgi unix//run/php/php-fpm.sock
        file_server
    }
    # ... rest of your existing site config
}
```

#### Link Roundcube from the BBS

Once Roundcube is running, add a link to it in **Admin → BBS Settings → Appearance**. This lets the sysop surface the webmail URL directly in the BBS interface (e.g. in a navigation panel or footer) without any code change.

### Firewall

Open the required ports if you use `ufw` or `nftables`:

```bash
# ufw
sudo ufw allow 25/tcp    # SMTP inbound
sudo ufw allow 587/tcp   # Submission (authenticated outbound)
sudo ufw allow 993/tcp   # IMAP over TLS
sudo ufw reload
```

Port 143 (plaintext IMAP) should remain closed.

---

## Files Affected / Created

| Path | Change |
|---|---|
| `src/Mail/MailboxManager.php` | New class |
| `scripts/admin_daemon.php` | Add `MAIL_PROVISION`, `MAIL_DEPROVISION` command handlers |
| `routes/admin-routes.php` | New admin mail management endpoints |
| `routes/api-routes.php` | New user self-service mail endpoints (`/api/mail/*`) |
| `templates/admin/mail_settings.twig` | New admin email settings panel |
| `templates/admin/user_mail.twig` | Per-user mailbox panel |
| `templates/profile/email.twig` | User-facing connection info page |
| `telnet/src/SettingsHandler.php` | Add email password change option |
| `database/migrations/vYYYYMMDDHHMMSS_add_mail_users.sql` | New migration |
| `config/bbs.json.example` | Add `mail` block |
| `docs/install-guide-email.md` | New install guide |
| `docs/AdminDaemon.md` | Document new commands |
| `docs/API.md` | Document new admin endpoints |
| `docs/DATA_MODEL.md` | Document `mail_users` table |
| `docs/index.md` | Link new install guide |

