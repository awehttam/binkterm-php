# BinktermPHP

BinktermPHP is a modern web-based BBS that combines classic FTN packet processing with a full multi-user online experience. It supports native BinkP TCP/IP connectivity for echomail and netmail across multiple simultaneous FTN networks, while delivering a browser-accessible bulletin board where users can read and post messages, chat, play door games, and earn credits — just like on a traditional BBS, no terminal client required. For those who prefer the authentic experience, BinktermPHP also includes a built-in telnet and SSH server.

BinktermPHP's mobile-responsive interface makes netmail and echomail comfortably accessible from phones and tablets while preserving the familiar feel of a classic BBS. ANSI art renders inline, links are detected and hyperlinked automatically, messages are full-text searchable, and built-in address books help users track their contacts. Users can also share individual messages via secure, expiring web links — with public or private access controls and revocation — making it easy to point someone at a great thread without requiring a login. The result is a Fidonet messaging experience that blends traditional FTN communication with practical modern conveniences, even on modest hardware.

Whether you're setting up a lean point or a full BBS node, BinktermPHP comes loaded with the features sysops care about:

- **Built-in BinkP mailer** — connect to multiple FidoNet-style networks simultaneously, sending and receiving echomail and netmail without third-party software
- **Full door support** — native Linux/Windows programs, classic DOS doors via DOSBox, and browser-based doors with auto-discovery
- **Telnet & SSH server** — offer classic terminal access alongside the web interface
- **Credits economy** — reward logins and participation, or charge for door games and premium features
- **Message webshare** — let users share posts via secure, expiring links with public or private access
- **Nodelist browser** — search and reference FTN nodes without leaving the interface
- **Offline mail reading** — QWK packet support lets users download and reply to messages in their favourite offline reader
- **Echomail digests** — users can receive a periodic email digest summarising new activity in their subscribed areas (daily or weekly)
- **Advertising manager** — create and rotate ANSI, RIPscrip, Sixel, or plain-text ads on the dashboard, and manage automated postings
- **System analytics** — activity stats, login source breakdown, and a full activity viewer for monitoring usage
- **Full admin interface** — manage users, echo areas, doors, credits, and system settings from the browser
- **Themeable UI** — ships with multiple themes including ANSI-inspired and cyberpunk styles
- **MCP server** — lets AI assistants (Claude Code, etc.) read echomail and echo areas directly via the Model Context Protocol; each user generates their own personal bearer key
- **PacketBBS Gateway** — compact text interface for MeshCore mesh radio networks; users browse and send netmail and echomail over low-bandwidth radio links using short one-line commands (see [docs/PacketBBS.md](docs/PacketBBS.md))
- **...and more**

**Full documentation:** [docs/index.md](docs/index.md)

This code is released under the terms of a [BSD License](LICENSE.md).

awehttam operates a full instance of BinktermPHP over at https://claudes.lovelybits.org - Claude's very own BBS, and a point system @ https://mypoint.lovelybits.org.

BinktermPHP was featured in the *Calling All Nodes* YouTube video: [CALLING ALL NODES — BinktermPHP](https://www.youtube.com/watch?v=I_s8X2O7Lmk)

---

- [Screenshots](#screenshots)
- [Installation](#installation)
- [Configuration](#configuration)
- [Upgrading](#upgrading)
- [Joining LovlyNet Network](#joining-lovlynet-network)
- [Customization](#customization)
- [Optional Features](#optional-features)
- [Contributors Wanted](#contributors-wanted)
- [Contributing](#contributing)
- [License](#license)
- [Support](#support)
- [Acknowledgments](#acknowledgments)

---

# Screenshots

BinktermPHP runs beautifully in any browser — here's a look at the interface across different features and themes.

<table>
  <tr>
    <td align="center"><b>Echomail List</b><br><img src="docs/screenshots/echomail.png" width="260"></td>
    <td align="center"><b>Echomail Reader</b><br><img src="docs/screenshots/read_echomail.png" width="260"></td>
    <td align="center"><b>Netmail</b><br><img src="docs/screenshots/read_netmail.png" width="260"></td>
  </tr>
  <tr>
    <td align="center"><b>Cyberpunk Theme</b><br><img src="docs/screenshots/cyberpunk.png" width="260"></td>
    <td align="center"><b>ANSI Decoder</b><br><img src="docs/screenshots/ansisys.png" width="260"></td>
    <td align="center"><b>Nodelist Browser</b><br><img src="docs/screenshots/nodelist.png" width="260"></td>
  </tr>
  <tr>
    <td align="center"><b>Mobile Echoread</b><br><img src="docs/screenshots/mobile_echoread.png" width="260"></td>
    <td align="center"><b>Mobile Echolist</b><br><img src="docs/screenshots/moble_echolist.png" width="260"></td>
    <td align="center"><b>Doors</b><br><img src="docs/screenshots/webdoors.png" width="260"></td>
  </tr>
  <tr>
    <td align="center"><b>User Settings</b><br><img src="docs/screenshots/userrsettings.png" width="260"></td>
    <td align="center"><b>Admin Menu</b><br><img src="docs/screenshots/adminmenu.png" width="260"></td>
    <td align="center"><b>Telnet Server</b><br><img src="docs/screenshots/telnetserver.png" width="260"></td>
  </tr>
  <tr>
    <td align="center"><b>Activity Stats</b><br><img src="docs/screenshots/activitystats.png" width="260"></td>
    <td align="center"><b>Gemini Home</b><br><img src="docs/screenshots/geminihome.png" width="260"></td>
    <td align="center"><b>Markdown</b><br><img src="docs/screenshots/markdown.png" width="260"></td>
  </tr>
</table>

# Installation

BinktermPHP supports two installation methods:

- **Installer (recommended)** — download and run `binkterm-installer.phar` for a guided, automated setup that handles PHP, PostgreSQL, web server configuration, and migrations
- **Git (for developers)** — clone the repository and run setup scripts for full control over the installation

For complete installation instructions — system requirements, Ubuntu/Debian package setup, PostgreSQL configuration, web server configuration (Caddy, Nginx, Apache), cron job setup, and a network port reference — see **[docs/INSTALL.md](docs/INSTALL.md)**.

# Configuration

Two files must be configured before first run. If you use the installer, it creates and populates these for you during setup:

- **`.env`** — database, SMTP, daemon ports, and feature flags. Copy `.env.example` to `.env` and fill in values.
- **`config/binkp.json`** — your FTN system identity, uplinks, binkp daemon, security, and crashmail. Copy `config/binkp.json.example` as a starting point.

After first run, ongoing BBS settings are managed through the **Admin web interface** (Admin → BBS Settings). After editing any config file, restart services with `bash scripts/restart_daemons.sh`.

Full configuration reference: **[docs/CONFIGURATION.md](docs/CONFIGURATION.md)**

# Upgrading

**Review version-specific upgrade notes** in [docs/index.md](docs/index.md#upgrading) before upgrading — individual versions may have specific steps you must take.

The general steps:

1. **Pull the latest code** — `git pull`
2. **Run setup** — `php scripts/setup.php` (handles database migrations automatically)
3. **Update configurations** — review `.env` and `config/binkp.json` for new options
4. **Restart daemons** — `bash scripts/restart_daemons.sh`

**Using the installer:** Re-run `binkterm-installer.phar` to upgrade.

# Joining LovlyNet Network

LovlyNet is a FidoNet Technology Network (FTN) operating in Zone 227 with automated registration:

```bash
php scripts/lovlynet_setup.php
```

See **[docs/LovlyNet.md](docs/LovlyNet.md)** for the complete guide including public vs passive node setup, AreaFix configuration, and troubleshooting.

# Customization

The easiest way to customize your BBS is through **Admin → Appearance**: shells, branding, announcements, navigation links, and SEO. Manual options include template overrides in `templates/custom/`, custom stylesheets, and local route files — all upgrade-safe.

See **[docs/CUSTOMIZING.md](docs/CUSTOMIZING.md)** for the full reference.

# Optional Features

These features are disabled by default and require additional setup:

| Feature | Documentation |
|---------|---------------|
| DOS Doors (DOSBox-X) | [docs/DOSDoors.md](docs/DOSDoors.md) |
| Native Doors (PTY) | [docs/NativeDoors.md](docs/NativeDoors.md) |
| WebDoors (HTML5 games) | [docs/WebDoors.md](docs/WebDoors.md) |
| JS-DOS Doors (browser WASM) | [docs/JSDOSDoors.md](docs/JSDOSDoors.md) |
| C64 Doors (emulated) | [docs/C64Doors.md](docs/C64Doors.md) |
| Gemini Browser & Capsule Hosting | [docs/GeminiCapsule.md](docs/GeminiCapsule.md) |
| MCP Server (AI assistant access) | [docs/MCPServer.md](docs/MCPServer.md) |
| PacketBBS Gateway (mesh radio) | [docs/PacketBBS.md](docs/PacketBBS.md) |
| Telnet Server | [docs/TelnetServer.md](docs/TelnetServer.md) |
| SSH Server | [docs/SSHServer.md](docs/SSHServer.md) |
| FTP Server | [docs/FTPServer.md](docs/FTPServer.md) |

See **[docs/index.md](docs/index.md)** for the full documentation index.

# Contributors Wanted

We're looking for experienced PHP developers interested in contributing to BinktermPHP. Areas include FTN networking, WebDoors game development, themes, telnet, real-time features, and more. See **[HELP_WANTED.md](HELP_WANTED.md)** for details.

# Contributing

Before contributing, review:

- **[docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md)** — codebase architecture, conventions, and migrations
- **[HELP_WANTED.md](HELP_WANTED.md)** — areas where contributions are especially needed
- **[CONTRIBUTING.md](CONTRIBUTING.md)** — PR workflow, coding standards, and testing guidelines

All contributions must be submitted via pull request and will be reviewed by project maintainers.

# License

This project is licensed under a BSD License. See [LICENSE.md](LICENSE.md) for more information.

# Support

- **Documentation**: [docs/index.md](docs/index.md)
- **FAQ**: [FAQ.md](FAQ.md)
- **Issues**: GitHub issue tracker
- **Community**: [claudes.lovelybits.org](https://claudes.lovelybits.org) — live BBS; Fidonet echo areas

# Acknowledgments

See [CREDITS.md](CREDITS.md) for contributors and third-party libraries.

- Fidonet Technical Standards Committee for protocol specifications
- Original binkd developers for reference implementation
- Bootstrap and jQuery communities for web interface components
- PHP community for excellent documentation and tools
