# Getting Started with BinktermPHP

This guide walks a new sysop from a fresh server to a working BBS with at least one FTN network connection and users who can read and post messages. It links out to detailed reference docs at each step rather than duplicating them.

By the end you will have:

- BinktermPHP installed and all background daemons running
- Your BBS configured with a name, sysop identity, and welcome message
- A connection to at least one FTN (FidoNet-style network)
- Users able to log in, browse echo areas, and post messages

---

## Table of Contents

- [Step 1: Install the Software](#step-1-install-the-software)
- [Step 2: First Login and the Admin Panel](#step-2-first-login-and-the-admin-panel)
- [Step 3: Configure Your BBS Identity](#step-3-configure-your-bbs-identity)
- [Step 4: Connect to a Network](#step-4-connect-to-a-network)
- [Step 5: Subscribe to Echo Areas](#step-5-subscribe-to-echo-areas)
- [Step 6: Open the BBS to Users](#step-6-open-the-bbs-to-users)
- [Step 7: Send and Receive Your First Mail](#step-7-send-and-receive-your-first-mail)
- [What to Set Up Next](#what-to-set-up-next)

---

## Step 1: Install the Software

Install BinktermPHP using the installer script, which verifies system requirements, sets up the database, and generates a starter `.env`. See [INSTALL.md](INSTALL.md) for full instructions including system requirements, web server configuration, and cron job setup.

Come back here once all four daemons are running and you can reach the web interface at your domain.

---

## Step 2: First Login and the Admin Panel

Open your browser and navigate to your BBS URL. Log in with the administrator account you created during installation. If you were not prompted to set credentials, the default login is **admin / admin123** — change it immediately from **Admin → Users**.

Click **Admin** in the top navigation bar to open the Admin panel.

### Dashboard

The admin dashboard is your system health overview. Check it first:

- **Daemon status** — All four background daemons (Admin, Scheduler, BinkP Server, Realtime Server) should show as running. If any are stopped, check `data/logs/` for errors.
- **Database status** — Should show connected and healthy.
- **Recent mail activity** — Will be empty on a fresh install; this fills in as mail flows.

### Key admin sections

| Section | Purpose |
|---|---|
| BBS Settings | BBS name, sysop identity, welcome messages, registration policy |
| Networks | FTN network domains (one per network you participate in) |
| Uplinks | BinkP connection settings for each network hub |
| Echo Areas | Create and manage echomail areas |
| File Areas | File distribution areas |
| Users | User accounts, access levels, and moderation |
| AreaFix | Send AreaFix commands to your uplink |

---

## Step 3: Configure Your BBS Identity

Go to **Admin → BBS Settings**.

Fill in the fields under the **General** tab:

| Field | What to put |
|---|---|
| BBS Name | The public name of your BBS (appears in headers, the web UI, and FTN tearlines) |
| Sysop Name | Your real name or handle as sysop |
| Location | Your city and country |
| Origin Line | Tagline appended to your outgoing echomail messages |

Under **Registration**, decide whether new users can self-register or need an invitation:

- **Open registration** — anyone can create an account
- **Closed registration** — only accounts created by an admin are allowed

Save your settings. The BBS name and sysop fields take effect immediately for new outgoing mail.

---

## Step 4: Connect to a Network

To exchange echomail and netmail with other BBSes you need to join an FTN (FidoNet Technology Network) and configure a BinkP uplink.

First join [LovlyNet](LovlyNet.md) — LovlyNet is the support network for BinktermPHP. Once connected, subscribe to the **LVLY_BINKTERMPHP** echo area to get sysop help, share feedback, and test your mail path end-to-end with other BinktermPHP sysops. LovlyNet has its own self-service registration workflow.

For other FTNs you want to join (FidoNet, FSxNet, ArakNet, etc.), follow [Joining and Configuring an FTN](FTNGuide.md). That guide covers:

- Deciding between a public node and a poll-only node
- What credentials to request from the network coordinator
- Adding the network domain in **Admin → Networks**
- Adding the uplink in **Admin → BBS Settings → BinkP Config**
- Verifying the mailer services are running

Come back here once your uplink is saved and the binkp scheduler is running.

---

## Step 5: Subscribe to Echo Areas

Once you have an uplink configured, subscribe to some echo areas so mail starts flowing.

### Using AreaFix

Most FTNs use the AreaFix robot to manage subscriptions. Go to **Admin → AreaFix** and send a `%LIST` command to your uplink to see what areas are available. Then send `+AREA_TAG` for each area you want to subscribe to.

You can also send AreaFix commands by composing netmail to `AreaFix` at your hub's FTN address; see [AreaFix / FileFix](AreaFix.md) for the full command reference.

### Creating local areas

You can also create areas that exist only on your BBS — useful for a site news area, a local chat area, or testing. Go to **Admin → Echo Areas → Add Area**, check the **Local** checkbox, and set the domain to **Local**.

### After subscribing

After you subscribe via AreaFix, poll your uplink once to pick up the first batch of mail:

```bash
php scripts/binkp_poll.php
```

Check **Admin → Echo Areas** — subscribed areas will start receiving messages. Users will see new areas appear in the echomail browser on the web interface.

See [Echo Areas](EchoAreas.md) for area settings including descriptions, colors, and access controls.

---

## Step 6: Open the BBS to Users

### Create accounts manually

Go to **Admin → Users → Add User**. Set a username, real name, password, and access level. New users get the **User** access level by default, which allows reading and posting in all public echo areas. Do not assign users **Administrator** privileges — this grants full control of your BBS and should only be used for co-sysops you trust as much as yourself.

### Terminal access (optional)

If you want users to connect via telnet or SSH using a classic terminal client, see [Telnet Daemon](TelnetServer.md) and [SSH Server](SSHServer.md). Terminal access is separate from the web interface and uses a different UI shell — both share the same user accounts and message database.

---

## Step 7: Send and Receive Your First Mail

This is how you verify the full pipeline is working.

**Receive mail:**

1. Poll your uplink: `php scripts/binkp_poll.php`
2. Watch `data/logs/binkp_poll.log` for a successful session.
3. Watch `data/logs/packets.log` for packet processing.
4. Log in to the web interface and open an echo area — you should see incoming messages.

**Send mail:**

1. Log in to the web interface and open an echo area you are subscribed to.
2. Click **New Message** and write a short post.
3. Poll again: `php scripts/binkp_poll.php`
4. Check `data/logs/packets.log` — the outbound packet should be listed.
5. If you have a contact on the network, ask them to confirm they received your message.

**Send a test netmail:**

Compose a netmail to yourself or to the network coordinator using their FTN address. Go to **Netmail → Compose**, enter the destination address (e.g. `1:1/1`), and send. Poll to deliver it.

If mail is not flowing, see the [Troubleshooting section in FTNGuide](FTNGuide.md#troubleshooting) and check the log files in `data/logs/`.

---

## What to Set Up Next

Before diving into individual features, walk through the full **Admin** menu when you have time. **Admin → BBS Settings** and **Admin → Appearance** in particular have a wide range of options covering registration, credits, AI features, themes, and more — many have sensible defaults but are worth reviewing so your BBS reflects your preferences from the start.

Once you are comfortable with the Admin panel, these are the most common areas to explore next:

| Feature | Where to start |
|---|---|
| Terminal / telnet access | [Telnet Daemon](TelnetServer.md) |
| SSH access | [SSH Server](SSHServer.md) |
| Door games (browser-based) | [WebDoors](WebDoors.md) |
| DOS door games | [DOS Doors](DOSDoors.md) |
| AI message assistant | [AI Assistant](AIAssistant.md) |
| Chat with other BBSes | [MRC Chat](MRC_Chat.md) |
| Echomail digests by email | [Echo Digests](EchoDigests.md) |
| Themes and appearance | [Customizing](CUSTOMIZING.md) |
| Credit economy | [Credit System](CreditSystem.md) |
| Docker deployment | [Docker](DOCKER.md) |
| Full configuration reference | [Configuration Reference](CONFIGURATION.md) |

---

[Return to Documentation index](index.md)
