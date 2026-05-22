# BinktermPHP Documentation

Complete reference for sysops and developers. New here? Start with [Getting Started](GettingStarted.md) or [Installation](INSTALL.md). Developers should begin at [Architecture](ARCHITECTURE.md) and [Developer Guide](DEVELOPER_GUIDE.md).

---

## Essential Setup & Operations

- [Getting Started](GettingStarted.md) — New sysop walkthrough: installation through first working FTN connection
- [Installation](INSTALL.md) — System requirements, web server configuration (Caddy, Nginx, Apache), cron setup, and network port reference
- [Configuration Reference](CONFIGURATION.md) — Environment variables, .env settings, and core configuration options
- [Command Line Interface](CLI.md) — All CLI scripts: binkp_server, binkp_poll, maintenance tools
- [Maintenance](MAINTENANCE.md) — Routine maintenance procedures, log rotation, database cleanup
- [Dashboard](Dashboard.md) — User dashboard, activity cards, and quick access to platform features
- [Analytics](Analytics.md) — Activity stats, AI usage, ad analytics, and content-sharing reports
- [Registration](../REGISTER.md) — How to register, premium features, and license installation

---

## FTN Networking

- [Joining and Configuring an FTN](FTNGuide.md) — Connect a node to an FTN, configure its uplink, and bring network mail into the platform
- [Echo Areas](EchoAreas.md) — Message areas where inbound FTN echomail becomes visible to web and terminal users
- [Echo Digests](EchoDigests.md) — Scheduled email digests of echomail areas
- [File Areas](FileAreas.md) — File area configuration, uploads, and management
- [FREQ](FREQ.md) — File request (FREQ) serving and requesting: modes, magic names, routing, and CLI tools
- [LovlyNet](LovlyNet.md) — LovlyNet network file sharing and FileFix integration
- [AreaFix / FileFix](AreaFix.md) — Managing echomail and file-area subscriptions with hub uplinks

---

## Network Directories

- [Nodelist](Nodelist.md) — Importing and searching the FTN nodelist: file area rules, URL downloader, node browser, map view, and crashmail routing
- [BBS Directory](BBSDirectory.md) — Public BBS listing: echomail robot, scheduled imports, file area rules, admin management, and geocoding

---

## Access Methods

- [Terminal Server](TerminalServer.md) — Terminal access layer for BBS-style sessions over text protocols
- [Telnet Daemon](TelnetServer.md) — Telnet access method setup, configuration, and troubleshooting
- [SSH Server](SSHServer.md) — Secure shell access method for terminal users
- [PacketBBS Gateway](PacketBBS.md) — Packet/mesh access method for low-bandwidth nodes using compact text commands
- [FTP Server](FTPServer.md) — Standalone passive FTP daemon for QWK exchange and file-area transfers
- [QWK Offline Mail](QWK.md) — Download and upload QWK/QWKE packets for offline message reading in external readers
- [Gemini Capsule](GeminiCapsule.md) — Gemini access method for lightweight capsule browsing

---

## AI & Integrations

- [AI Providers and Usage](AIProviders.md) — AI provider setup, request accounting, and the admin usage dashboard
- [AI Assistant](AIAssistant.md) — Reader-side helper that uses MCP-backed tool access to explain, summarize, and navigate message content
- [AI Bots](AIBots.md) — Configuring AI chat bots, the middleware pipeline, writing custom middleware, and cost management
- [MCP Server](MCPServer.md) — AI and automation integration layer exposing selected platform capabilities to MCP-compatible clients
- [MCP Client Help](MCPClientHelp.md) — Configure Claude, Anything LLM, OpenAI, and other MCP clients
- [Matterbridge](Matterbridge.md) — Bidirectional bridge between local chat rooms and external platforms (Discord, Slack, IRC, etc.)

---

## Doors & Games

- [Doors Overview](Doors.md) — Overview of door types and how to install them
- [DOS Doors](DOSDoors.md) — Running classic DOS door games
- [Native Doors](NativeDoors.md) — Native Linux/Unix door games
- [PubTerm](PubTerm.md) — Built-in browser terminal door: setup, terminal size, guest access, and known limitations
- [WebDoors](WebDoors.md) — Browser-native door runtime that connects games and utilities to BinktermPHP users, sessions, and APIs
- [WebDoor Tutorial](WebDoor-Tutorial.md) — Step-by-step guide to building your first WebDoor
- [JS-DOS Doors](JSDOSDoors.md) — Browser-side DOS game emulation via js-dos/DOSBox WASM
- [C64 Doors](C64Doors.md) — Commodore 64 door games
- [DOSBox Headless Mode](DOSBox_Headless_Mode.md) — Running DOSBox without a display for DOS doors

---

## Communication & Chat

- [Local Chat](LocalChat.md) — Real-time room chat and direct messages: rooms, moderation, Matterbridge bridging, and AI bots
- [MRC Chat](MRC_Chat.md) — Multi-Relay Chat protocol integration
- [Shoutbox](Shoutbox.md) — Public message wall: posting, moderation, and dashboard card
- [Bulletins](Bulletins.md) — Sysop notices shown at login and on demand: scheduling, display modes, and terminal rendering
- [Voting Booth](VotingBooth.md) — User polls: creating, voting, results, and terminal access

---

## Content & Media

- [Media in Messages](MediaInMessages.md) — Inline images, video, audio, platform embeds, retro audio, and text art in echomail and netmail
- [Message Sharing](MessageSharing.md) — Public share links for echomail and netmail: token and friendly URLs, Open Graph preview images, AI summaries, referrer tracking
- [Markdown and Markup Formatting](Markdown.md) — Markdown and StyleCodes compose editor, MARKUP kludge, LSC-001 reference, and rendering details
- [ANSI Support](ANSI_Support.md) — ANSI art rendering in messages and files
- [ANSI Ads Generator](ANSI_Ads_Generator.md) — Generating ANSI-art advertisements
- [RIPScrip Support](RIPScrip_Support.md) — RIPscrip vector graphics rendering in echomail and file areas
- [Sixel Support](Sixel_Support.md) — DEC Sixel bitmap graphics in messages and file previews
- [Pipe Code Support](Pipe_Code_Support.md) — BBS pipe color code rendering

---

## Economy & Engagement

- [Credit Economy Setup](CreditEconomySetup.md) — Sysop guide: designing a balanced credit economy, tuning rewards and costs, monetization considerations
- [Credit System](CreditSystem.md) — User credit economy: earning, spending, and configuration
- [Interests](Interests.md) — Topic-based echo area groups users can subscribe to
- [Advertising](Advertising.md) — Ad banners, Broadcast Manager, and display configuration
- [Weather Reports](Weather.md) — Automated weather report generation and echomail posting

---

## Automation

- [Robots](Robots.md) — Echomail robot automation and response bots
- [Auto Feed](Autofeed.md) — RSS/Atom and Bluesky auto feeder: posts new items to echo areas on a cron schedule
- [File Area Rules](FileAreas.md#file-area-rules) — Automated processing rules for incoming files
- [Anti-Virus](AntiVirus.md) — File scanning integration for uploaded files

---

## Deployment & Infrastructure

- [Docker](DOCKER.md) — Docker and docker-compose deployment
- [Performance Tuning](PerformanceTuning.md) — php-fpm sizing, PostgreSQL tuning, BinkStream transport selection, opcache, and capacity planning
- [Customizing](CUSTOMIZING.md) — Themes, shells, and appearance customization
- [Localization](Localization.md) — Internationalization (i18n) and locale configuration
- [PWA and Service Worker Caching](PWA.md) — Installable PWA, asset caching, cache invalidation, and developer bump rules

---

## FTN Standards (LSC)

Specifications published by the LovlyNet Standards Council.

- [LSC-001: MARKUP Kludge](LSC/LSC1%20-%20Markup%20Kludge.txt) — FTN kludge for inline markup and styling in echomail and netmail
- [LSC-002: FILEREF Kludge](LSC/LSC2%20-%20FILEREF%20Kludge.txt) — FTN kludge for file-referenced echomail threads

---

## Developer Reference

- [Architecture](ARCHITECTURE.md) — Ecosystem map showing how access methods, realtime delivery, FTN networking, doors, MCP, and AI fit together
- [Data Model](DATA_MODEL.md) — Key database tables, their relationships, and conceptual model for developers
- [Developer Guide](DEVELOPER_GUIDE.md) — Coding conventions, database migrations, and project structure
- [Terminal Server Developer Guide](TerminalServerDevGuide.md) — Shell abstraction, style profile, widget reference, handler patterns, and session internals for contributors
- [Configuration System](ConfigurationSystem.md) — How features read configuration at runtime: Config::env(), BbsConfig, AppearanceConfig, BinkpConfig, door configs, and per-user settings
- [Admin Daemon](AdminDaemon.md) — Wire protocol, command reference, and how to add new daemon commands
- [API Reference](API.md) — HTTP endpoint reference for the public API
- [BinkStream Back-Channel](BinkStreamChannel.md) — BinkStream shared realtime event infrastructure used by chat, notifications, doors, and live UI updates
- [Admin Terminal](AdminTerminal.md) — Floating xterm.js terminal for admins: live event stream, wall/msg commands, command history, and state persistence
- [Gateway Token Authentication](GatewayTokenAuth.md) — Server-to-server token verification for remote door servers and third-party integrations
- [Contributing](../CONTRIBUTING.md) — Git workflow, PR process, coding standards, and pre-commit checklist

---

## Upgrading

Release-specific upgrade notes, listed newest-first. See [UPGRADING_TEMPLATE.md](UPGRADING_TEMPLATE.md) for the document template.

- [Upgrading to 1.9.7](UPGRADING_1.9.7.md)
- [Upgrading to 1.9.6](UPGRADING_1.9.6.md)
- [Upgrading to 1.9.5](UPGRADING_1.9.5.md)
- [Upgrading to 1.9.4](UPGRADING_1.9.4.md)
- [Upgrading to 1.9.3](UPGRADING_1.9.3.md)
- [Upgrading to 1.9.2](UPGRADING_1.9.2.md)
- [Upgrading to 1.9.1](UPGRADING_1.9.1.md)
- [Upgrading to 1.9.0](UPGRADING_1.9.0.md)
- [Upgrading to 1.8.9](UPGRADING_1.8.9.md)
- [Upgrading to 1.8.8](UPGRADING_1.8.8.md)
- [Upgrading to 1.8.7](UPGRADING_1.8.7.md)
- [Upgrading to 1.8.6](UPGRADING_1.8.6.md)
- [Upgrading to 1.8.5](UPGRADING_1.8.5.md)
- [Upgrading to 1.8.4](UPGRADING_1.8.4.md)
- [Upgrading to 1.8.3](UPGRADING_1.8.3.md)
- [Upgrading to 1.8.2](UPGRADING_1.8.2.md)
- [Upgrading to 1.8.0](UPGRADING_1.8.0.md)
- [Upgrading to 1.7.9](UPGRADING_1.7.9.md)
- [Upgrading to 1.7.8](UPGRADING_1.7.8.md)
- [Upgrading to 1.7.7](UPGRADING_1.7.7.md)
- [Upgrading to 1.7.5](UPGRADING_1.7.5.md)
- [Upgrading to 1.7.2](UPGRADING_1.7.2.md)
- [Upgrading to 1.7.1](UPGRADING_1.7.1.md)
- [Upgrading to 1.7.0](UPGRADING_1.7.0.md)
- [Upgrading to 1.6.7](UPGRADING_1.6.7.md)
