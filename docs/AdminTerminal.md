# Admin Terminal

The Admin Terminal is a floating xterm.js terminal panel available to administrators. It provides a real-time view of BinkStream events and accepts typed commands for system management tasks.

---

## Enabling the Terminal

The terminal is disabled by default. To enable it, set the following in your `.env` file:

```
ADMIN_TERMINAL=true
```

The toggle button and panel are only rendered when `ADMIN_TERMINAL=true` and the logged-in user has admin privileges. The button is hidden on small (mobile) screen widths.

---

## Opening and Closing

A terminal icon button appears in the lower-right corner of every page (desktop only). Clicking it toggles the panel open or closed. The panel state (open/closed, stream watch, output history, command history) is persisted in `localStorage` and restored on the next page load or navigation.

---

## Commands

Type a command at the `>` prompt and press Enter.

| Command | Description |
|---|---|
| `help` | List all available commands with descriptions |
| `clear` | Clear the terminal output and wipe the saved log from localStorage |
| `stream` | Show BinkStream status: transport mode, events received, last event type and time |
| `who` | List currently online users with their service, location, and activity |
| `finger <username>` | Show user profile and online session details for a specific user |
| `wall <message>` | Broadcast a message to all connected users. A modal dialog appears on every active page |
| `msg <username> <message>` | Send a private message to a specific user. Only that user sees the modal |
| `uplinks` | List configured binkp uplink addresses and their domains |
| `poll [<address>\|all]` | Trigger a binkp poll. Runs synchronously and prints the result. Defaults to all uplinks |

### Command History

- **Up/Down arrow keys** — navigate previously entered commands
- **Ctrl-R** — incremental reverse search through command history

Command history is capped at 100 entries and persisted in `localStorage` across page loads.

---

## Live Event Stream

The terminal subscribes to all BinkStream event types. `stream watch` is a hidden command (not shown in `help`) that toggles live event output. When enabled, incoming SSE events are printed in cyan as they arrive with a timestamp, event type, and JSON payload.

Use `stream` (without arguments) to see a summary:

```
Stream Status
  Mode          sse
  Watch         on
  Events recv   42
  Connected at  14:32:01
  Last event    dashboard_stats  14:35:12
```

---

## Wall Messages

The `wall` and `msg` commands use the BinkStream `wall_message` event type to deliver messages to connected browsers.

- `wall` — emits with `user_id = NULL`, so all authenticated users receive it
- `msg` — emits with `user_id = <target>`, so only that user receives it

Recipients see a Bootstrap modal dialog with the sender's username and the message body. The modal is rendered by `notifier.js` and is shown as soon as the event is delivered via SSE.

---

## State Persistence

The following state is saved to `localStorage` under the key `binkterm_admin_terminal_<userId>` (namespaced per user) and restored on every page load. The key is cleared on logout to prevent state leaking between accounts.

| Key | Description |
|---|---|
| `streamWatch` | Whether live event output is enabled |
| `panelOpen` | Whether the terminal panel was open when the page was last left |
| `outputLog` | Up to 500 lines of terminal output |
| `history` | Up to 100 command history entries |

---

## Technical Notes

- **xterm.js** is loaded only for admin users with `ADMIN_TERMINAL=true`. The files `xterm.js`, `xterm-addon-fit.js`, and `xterm.css` are excluded from the service worker cache so they are always fetched fresh.
- The terminal uses a FitAddon to size itself to the panel container. The panel is resized on open and on window resize.
- The wildcard `*` BinkStream listener is used to receive all event types without needing to subscribe to each individually. Named-type subscriptions are also registered for each known SSE type to ensure the SharedWorker fetches them from the server.
- Admin API routes: `POST /admin/api/wall`, `POST /admin/api/msg`, `GET /admin/api/finger/{username}`, `GET /admin/api/uplinks`, `POST /admin/api/poll`
- The `poll` command uses a synchronous admin daemon command (`binkp_poll_sync`) that waits for `binkp_poll.php` to complete and returns its output. This blocks the admin daemon socket for the duration of the poll, which is acceptable for a manual terminal operation. The background spawn used by the binkp admin UI is a separate code path.
- `stream watch` is intentionally omitted from the `help` output.
