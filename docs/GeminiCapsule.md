# Gemini Capsule Server

BinktermPHP includes a built-in Gemini capsule server that lets BBS users publish
personal capsules accessible via the [Gemini protocol](https://geminiprotocol.net/).

## URL Structure

| URL | Content |
|---|---|
| `gemini://yourdomain.com/` | BBS directory — lists users with published capsules |
| `gemini://yourdomain.com/home/username/` | User's capsule index |
| `gemini://yourdomain.com/home/username/page.gmi` | A specific published file |

## Setup

### 1. Enable the WebDoor

In **Admin → WebDoors**, enable the **Gemini Capsule** door. This gives users
access to the browser-based gemtext editor.

### 2. Configure `.env`

```ini
GEMINI_BIND_HOST=0.0.0.0
GEMINI_PORT=1965
```

### 3. Start the daemon

```bash
php scripts/gemini_daemon.php --daemon
```

The daemon writes its log to `data/logs/gemini_daemon.log`.

---

## TLS Certificates

The Gemini protocol requires TLS. By default the daemon generates a self-signed
certificate in `data/gemini/server.crt`. Gemini clients use a
**Trust On First Use (TOFU)** model, so self-signed certs are technically valid,
but some clients (notably Lagrange) will warn on first connection and again
whenever the cert changes.

For a smoother user experience, use a real CA-signed certificate such as one
issued by Let's Encrypt.

---

### Option A: Self-signed (default)

No configuration needed. The daemon generates a cert automatically on first
start. Clients will show a TOFU prompt on first connection.

---

### Option B: Let's Encrypt via Caddy

Caddy automatically obtains and renews Let's Encrypt certificates. You can
point the Gemini daemon at Caddy's managed cert files.

**Note:** Caddy cannot proxy the Gemini protocol (it is not HTTP). The Gemini
daemon must bind directly to port 1965. Caddy handles port 443 for the web
interface; the two do not conflict.

**1. Find Caddy's certificate directory**

When Caddy runs as a system service, certificates are stored under the `caddy`
user's home directory:

```
/var/lib/caddy/.local/share/caddy/certificates/
    acme-v02.api.letsencrypt.org-directory/
        yourdomain.com/
            yourdomain.com.crt
            yourdomain.com.key
```

**2. Add to `.env`**

```ini
GEMINI_CERT_PATH=/var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/yourdomain.com/yourdomain.com.crt
GEMINI_KEY_PATH=/var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/yourdomain.com/yourdomain.com.key
```

**3. Ensure the daemon user can read the cert files**

```bash
sudo usermod -aG caddy binkterm
# Caddy cert directory permissions must allow group read:
sudo chmod 750 /var/lib/caddy/.local/share/caddy/certificates
```

**4. Restart on renewal**

Caddy renews certs automatically. Add a renewal hook so the Gemini daemon
reloads the new cert:

```bash
# /etc/caddy/conf.d/renew-hook.conf  (or in your Caddyfile):
# After Caddy renews, restart the Gemini daemon
```

Or use a systemd path unit that watches the cert file and restarts the daemon.
The simplest approach is to include the Gemini daemon restart in your regular
maintenance cron:

```bash
# crontab — restart daemon weekly so renewed certs are picked up
0 3 * * 0 /path/to/binkterm/scripts/restart_daemons.sh
```

---

### Option C: Let's Encrypt via Certbot

If you use certbot (with nginx or standalone), certificates are in
`/etc/letsencrypt/live/yourdomain.com/`.

**1. Add to `.env`**

```ini
GEMINI_CERT_PATH=/etc/letsencrypt/live/yourdomain.com/fullchain.pem
GEMINI_KEY_PATH=/etc/letsencrypt/live/yourdomain.com/privkey.pem
```

**2. Allow the daemon user to read the key**

```bash
sudo usermod -aG ssl-cert binkterm
# Or grant read access to the live directory:
sudo setfacl -R -m u:binkterm:rX /etc/letsencrypt/live/yourdomain.com/
sudo setfacl -R -m u:binkterm:rX /etc/letsencrypt/archive/yourdomain.com/
```

**3. Reload after renewal**

Add a deploy hook so the daemon reloads the new cert after certbot renews:

```bash
# /etc/letsencrypt/renewal-hooks/deploy/restart-gemini.sh
#!/bin/bash
/path/to/binkterm/scripts/restart_daemons.sh
```

```bash
chmod +x /etc/letsencrypt/renewal-hooks/deploy/restart-gemini.sh
```

---

## Can I put a reverse proxy in front of the Gemini daemon?

**Standard HTTP proxies (nginx, Caddy, HAProxy in HTTP mode) cannot proxy
Gemini** — the Gemini protocol is not HTTP. Clients open a raw TLS connection
on port 1965 and speak the Gemini wire protocol directly.

The Gemini daemon must be the TLS endpoint on port 1965. There is no benefit
to placing an HTTP reverse proxy in front of it.

If you need TCP-level load balancing or routing (e.g. running multiple services
on one machine), **HAProxy in TCP mode** or **Caddy with the `caddy-l4` plugin**
can forward raw TCP connections. However, for a typical single-server BBS
deployment this is unnecessary.

---

## Docker

The `docker-compose.yml` includes the Gemini port mapping:

```yaml
- "${GEMINI_PORT:-1965}:1965"
```

The daemon is configured in `docker/supervisord.conf` with `autostart=false`.
To enable it in Docker, set `autostart=true` in supervisord.conf or start it
manually inside the container:

```bash
docker exec -it binkterm php scripts/gemini_daemon.php --daemon
```
