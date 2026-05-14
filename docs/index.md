# BinktermPHP Documentation

Complete reference for sysops, users, and developers. New here? Start with [Getting Started](GettingStarted.md) or [Installation](INSTALL.md). Developers should begin at [Architecture](ARCHITECTURE.md) and [Developer Guide](DEVELOPER_GUIDE.md).

---

## Essential Setup & Operations

- [Getting Started](GettingStarted.md) — New sysop walkthrough: installation through first working FTN connection
- [Installation](INSTALL.md) — System requirements, web server configuration (Caddy, Nginx, Apache), cron setup, and network port reference
- [Configuration Reference](CONFIGURATION.md) — Environment variables, .env settings, and core configuration options
- [Command Line Interface](CLI.md) — All CLI scripts: binkp_server, binkp_poll, maintenance tools
- [Maintenance](MAINTENANCE.md) — Routine maintenance procedures, log rotation, database cleanup
- [Dashboard](Dashboard.md) — User dashboard: cards, echomail badge mode, layout customization, and sysop defaults
- [Analytics](Analytics.md) — Activity stats, AI usage, ad analytics, and content sharing reports
- [Registration](../REGISTER.md) — How to register, premium features, and license installation

---

## FTN Networking

- [Echo Areas](EchoAreas.md) — Creating, managing, and subscribing to echomail areas
- [Joining and Configuring an FTN](FTNGuide.md) — Joining an FTN, configuring a binkp uplink, and subscribing to areas
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

- [Terminal Server](TerminalServer.md) — Telnet/TCP terminal server setup and configuration
- [Telnet Daemon](TelnetServer.md) — Telnet daemon setup, configuration, and troubleshooting
- [SSH Server](SSHServer.md) — SSH server setup for secure terminal access
- [PacketBBS Gateway](PacketBBS.md) — Packet radio / MeshCore text gateway setup, node configuration, and user commands
- [FTP Server](FTPServer.md) — Standalone passive FTP daemon for QWK exchange and file-area transfers
- [QWK Offline Mail](QWK.md) — Download and upload QWK/QWKE packets for offline message reading in external readers
- [Gemini Capsule](GeminiCapsule.md) — Gemini protocol capsule support

---

## AI & Integrations

- [AI Assistant](AIAssistant.md) — Web message-reader assistant for echomail and netmail, including enablement, MCP usage, and credit charging
- [AI Providers and Usage](AIProviders.md) — AI provider setup, request accounting, and the admin usage dashboard
- [AI Bots](AIBots.md) — Configuring AI chat bots, the middleware pipeline, writing custom middleware, and cost management
- [MCP Server](MCPServer.md) — Model Context Protocol server for AI assistant access to echomail
- [MCP Client Help](MCPClientHelp.md) — Configure Claude, Anything LLM, OpenAI, and other MCP clients
- [Matterbridge](Matterbridge.md) — Bidirectional bridge between local chat rooms and external platforms (Discord, Slack, IRC, etc.)

---

## Doors & Games

- [Doors Overview](Doors.md) — Overview of door types and how to install them
- [DOS Doors](DOSDoors.md) — Running classic DOS door games
- [Native Doors](NativeDoors.md) — Native Linux/Unix door games
- [WebDoors](WebDoors.md) — HTML5/JavaScript web-based door games
- [WebDoor Tutorial](WebDoor-Tutorial.md) — Step-by-step guide to building your first WebDoor
- [JS-DOS Doors](JSDOSDoors.md) — Browser-side DOS game emulation via js-dos/DOSBox WASM
- [C64 Doors](C64Doors.md) — Commodore 64 door games
- [DOSBox Headless Mode](DOSBox_Headless_Mode.md) — Running DOSBox without a display for DOS doors

---

## Communication & Chat

- [MRC Chat](MRC_Chat.md) — Multi-Relay Chat protocol integration
- [Shoutbox](Shoutbox.md) — Public message wall: posting, moderation, and dashboard card
- [Bulletins](Bulletins.md) — Sysop notices shown at login and on demand: scheduling, display modes, and terminal rendering
- [Voting Booth](VotingBooth.md) — User polls: creating, voting, results, and terminal access

---

## Content & Media

- [Media in Messages](MediaInMessages.md) — Inline images, video, audio, platform embeds, retro audio, and text art in echomail and netmail
- [Markdown and Markup Formatting](Markdown.md) — Markdown and StyleCodes compose editor, MARKUP kludge, LSC-001 reference, and rendering details
- [ANSI Support](ANSI_Support.md) — ANSI art rendering in messages and files
- [ANSI Ads Generator](ANSI_Ads_Generator.md) — Generating ANSI-art advertisements
- [RIPScrip Support](RIPScrip_Support.md) — RIPscrip vector graphics rendering in echomail and file areas
- [Sixel Support](Sixel_Support.md) — DEC Sixel bitmap graphics in messages and file previews
- [Pipe Code Support](Pipe_Code_Support.md) — BBS pipe color code rendering

---

## Economy & Engagement

- [Credit System](CreditSystem.md) — User credit economy: earning, spending, and configuration
- [Interests](Interests.md) — Topic-based echo area groups users can subscribe to
- [Advertising](Advertising.md) — Ad banners, Broadcast Manager, and display configuration
- [Weather Reports](Weather.md) — Automated weather report generation and echomail posting

---

## Automation

- [Robots](Robots.md) — Echomail robot automation and response bots
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

## Developer Reference

- [Architecture](ARCHITECTURE.md) — System architecture: component diagram, FTN packet lifecycle, daemon IPC model, door and AI pipelines
- [Data Model](DATA_MODEL.md) — Key database tables, their relationships, and conceptual model for developers
- [Developer Guide](DEVELOPER_GUIDE.md) — Coding conventions, database migrations, and project structure
- [Configuration System](ConfigurationSystem.md) — How features read configuration at runtime: Config::env(), BbsConfig, AppearanceConfig, BinkpConfig, door configs, and per-user settings
- [Admin Daemon](AdminDaemon.md) — Wire protocol, command reference, and how to add new daemon commands
- [API Reference](API.md) — HTTP endpoint reference for the public API
- [BinkStream Back-Channel](BinkStreamChannel.md) — Real-time push architecture: sse_events table, SharedWorker, and how to add new event types
- [Admin Terminal](AdminTerminal.md) — Floating xterm.js terminal for admins: live event stream, wall/msg commands, command history, and state persistence
- [Gateway Token Authentication](GatewayTokenAuth.md) — Server-to-server token verification for remote door servers and third-party integrations
- [Contributing](CONTRIBUTING.md) — Git workflow, PR process, coding standards, and pre-commit checklist

---

## Upgrading

Release-specific upgrade notes, listed newest-first. See [UPGRADING_TEMPLATE.md](UPGRADING_TEMPLATE.md) for the document template.

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
