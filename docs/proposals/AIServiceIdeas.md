# AI Service Ideas

> **Draft** — This proposal was generated with AI assistance and may not have been reviewed for accuracy. It is intended as a starting point for discussion, not a finalized specification.

## Overview

BinktermPHP already ships an MCP server (`mcp-server/`) that gives AI assistants read-only access to echomail and echo areas. This document catalogs ideas for extending AI capabilities across the system.

---

## MCP Tool Additions

### `get_netmail`

Expose the authenticated user's private netmail inbox and sent items via MCP. Because the bearer key is tied to a specific user, the tool would only ever return messages addressed to or from that user — no cross-user access is possible by design.

Suggested parameters: `folder` (inbox/sent), `limit`, `offset`, `since`, `subject`, `from_name`.

Natural parity with the existing `get_echomail_messages` / `get_echomail_message` tools.

### `search_users` / `get_user_profile`

Allow the AI to look up BBS members: username, real name, post counts, active echo area subscriptions, join date. Useful for giving the AI context about who it is talking to or who wrote a given message.

### `get_nodelist`

Expose the FTN nodelist so the AI can look up node details, network topology, and routing information. Useful for sysop-assist scenarios.

### `get_file_areas` / `list_files`

Expose the file area catalog so the AI can help users find files by name, description, or area.

### `post_netmail`

A write-capable tool that allows the AI to send a netmail on the authenticated user's behalf. Requires explicit opt-in (a separate user setting) distinct from the read-only MCP key. Should enforce rate limits and message size limits identical to those applied in the web compose form.

### Session context tool

A lightweight tool that returns the identity of the authenticated user (username, real name, is_admin) so the AI knows who it is working with without being told out-of-band.

---

## AI-Assisted Writing

### Signature / tagline suggestions

A button in Settings → Messaging that calls an AI model and returns a list of tagline ideas. Could be seeded from the user's posting history, their subscribed echo areas, or a free-text prompt they provide.

### Message summarization

A **Summarize thread** button in the echomail message reader that collects the thread via the MCP `get_echomail_thread` tool and returns a short TL;DR. Natural use of existing infrastructure. Candidate for a premium feature.

### Smart reply drafting

Pre-populate the echomail/netmail compose form with an AI-generated draft reply based on the quoted message content. The user reviews and edits before sending — the AI never sends directly.

---

## AI-Assisted Sysop Tools

### Echo area health report

An admin tool that analyzes posting patterns across echo areas: last-message dates, volume trends, dead areas, top posters. Surfaces the results as a readable report and can suggest which areas to prune or merge.

### Spam / abuse detection

Flag outbound messages that match known spam patterns or contain abusive content before they are bundled into outbound packets. Could run as a post-compose hook or as part of the packet processor.

### Auto-categorize new echo areas

When a new echo area is added, query the AI to suggest which Interest group it belongs to. Extends the existing AI suggestion wizard in `src/` and the admin interests page.

---

## Implementation Notes

- All read tools should respect the same echoarea access rules as the existing MCP server: `is_active = TRUE`, `is_sysop_only = FALSE` for non-admin users.
- Write-capable tools (`post_netmail`) need a separate opt-in flag in `users_meta` so users can enable read access without enabling write access.
- Summarization and drafting features that call an external AI model require `ANTHROPIC_API_KEY` to be set in `.env`, consistent with the existing AI suggestion wizard.
