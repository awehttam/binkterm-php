# AI Bots

AI bots are chat personas driven by an external AI provider. Each bot has its own system user account, a configurable system prompt, and a set of activities that determine when and how it responds. Bots are managed from **Admin → Community → AI Bots**.

---

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Database Schema](#database-schema)
- [System Users](#system-users)
- [Bot Daemon](#bot-daemon)
- [Activity System](#activity-system)
  - [Local Chat Activity](#local-chat-activity)
- [Middleware Pipeline](#middleware-pipeline)
  - [BotContext](#botcontext)
  - [Pipeline execution](#pipeline-execution)
- [Built-in Middleware](#built-in-middleware)
  - [SlashCommandMiddleware](#slashcommandmiddleware)
  - [FilePromptInjectorMiddleware](#filepromptinjectoriddleware)
  - [UrlPromptInjectorMiddleware](#urlpromptinjectoriddleware)
  - [RegexFilterMiddleware](#regexfiltermiddleware)
  - [RagPromptInjectorMiddleware](#ragpromptinjectormiddleware)
- [Writing Custom Middleware](#writing-custom-middleware)
- [Configuring Middleware in the Admin UI](#configuring-middleware-in-the-admin-ui)
- [Cost Management](#cost-management)
- [Debugging](#debugging)

---

## Architecture Overview

```
chat_messages INSERT
       │
       ▼
PostgreSQL trigger → pg_notify('binkstream', sse_event_id)
       │
       ▼
 ai_bot_daemon.php  ←── LISTEN binkstream
       │
       ├── Is this a chat_direct or chat_mention for one of my bots?
       │
       ▼
 LocalChatActivityHandler::handle()
       │
       ├── Load activity config from ai_bot_activities
       ├── Build BotContext (system prompt, message, history)
       │
       ▼
 BotMiddlewarePipeline::run()
       │
       ├── SlashCommandMiddleware  ──► short-circuit with static reply
       ├── FilePromptInjectorMiddleware ──► modify system prompt
       ├── UrlPromptInjectorMiddleware  ──► modify system prompt
       ├── RegexFilterMiddleware        ──► abort or rewrite message
       ├── RagPromptInjectorMiddleware  ──► inject retrieved doc chunks
       │
       ▼
 AiService::generateText()  (if not short-circuited)
       │
       ▼
 INSERT INTO chat_messages  (trigger fires, reply delivered via SSE)
```

The daemon uses native `pg_connect()` / `LISTEN` / `pg_get_notify()` for sub-second event delivery. It does not use PDO for the notify socket because PDO does not expose `pg_socket()`.

---

## Database Schema

### `ai_bots`

| Column | Type | Description |
|---|---|---|
| `id` | serial | Primary key |
| `user_id` | integer | FK → `users.id`; the bot's system user account |
| `name` | varchar(100) | Display name (admin UI) |
| `description` | text | Optional internal note |
| `system_prompt` | text | Base system message sent to the AI provider |
| `provider` | varchar(50) | `openai` or `anthropic`; null = site default |
| `model` | varchar(100) | Model identifier; null = provider default |
| `weekly_budget_usd` | numeric(10,4) | Maximum spend per Sunday–Saturday UTC week |
| `context_messages` | smallint | Number of preceding messages passed as history |
| `is_active` | boolean | When false the bot is ignored by the daemon |

### `ai_bot_activities`

| Column | Type | Description |
|---|---|---|
| `id` | serial | Primary key |
| `bot_id` | integer | FK → `ai_bots.id` (CASCADE DELETE) |
| `activity_type` | varchar(50) | e.g. `local_chat` |
| `is_enabled` | boolean | Disable an activity without deleting it |
| `config_json` | jsonb | Activity-specific configuration including middleware |

The `(bot_id, activity_type)` pair is unique.

### `ai_requests` (existing, extended)

A `bot_id` column (FK → `ai_bots.id`, ON DELETE SET NULL) was added so spend can be tracked per bot accurately even when multiple bots share a provider account.

---

## System Users

Each bot is backed by an entry in the `users` table with `is_system = TRUE`. This account has a randomly generated locked password (`password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)`) and cannot be logged into through the normal auth flow.

When a bot is created via the admin UI, the system checks whether a system user with the requested username already exists. If one does it is reused; otherwise a new one is created. When a bot is deleted its system user is intentionally preserved so that chat history and message attribution remain intact.

Bots always appear in the chat online users list (`/api/chat/online`) regardless of whether they have an active session, and are marked `is_bot: true` so the UI renders them with a robot icon.

---

## Bot Daemon

`scripts/ai_bot_daemon.php` is a long-running process that subscribes to PostgreSQL `LISTEN/NOTIFY` and routes events to the appropriate activity handler.

**Starting the daemon:**

```bash
# Foreground (development)
php scripts/ai_bot_daemon.php

# Background with PID file
php scripts/ai_bot_daemon.php --daemon --pid-file=data/run/ai_bot_daemon.pid

# Debug logging
php scripts/ai_bot_daemon.php --log-level=DEBUG
```

**Options:**

| Option | Default | Description |
|---|---|---|
| `--daemon` | — | Fork into background |
| `--pid-file=PATH` | `data/run/ai_bot_daemon.pid` | PID file location |
| `--log-level=LEVEL` | `INFO` | `DEBUG`, `INFO`, `WARNING`, `ERROR` |

The daemon can be restarted from the admin panel under **Admin → System → Daemon Status → Restart AI Bot Daemon**. Logs are written to `data/logs/ai_bot_daemon.log`.

The bot list is refreshed from the database every 60 seconds. To pick up a newly created or edited bot without waiting, restart the daemon.

**Loop prevention:** the daemon tracks the `user_id` of all active bots and ignores any chat event where `from_user_id` belongs to a bot, preventing reply loops.

---

## Activity System

Activities are defined by the `BotActivityHandler` interface (`src/AiBot/BotActivityHandler.php`):

```php
interface BotActivityHandler
{
    public function getActivityType(): string;
    public function getLabel(): string;
    public function isReactive(): bool;
    public function handle(AiBot $bot, ActivityEvent $event): void;
}
```

- **Reactive handlers** (`isReactive() === true`) are woken by real-time PostgreSQL NOTIFY events.
- **Scheduled handlers** (`isReactive() === false`) would be invoked by an internal timer (not yet implemented).

`ActivityEvent` carries a `type` string and a `payload` array derived from the SSE event that triggered the handler.

### Local Chat Activity

Activity type: `local_chat`

The daemon listens for `chat_message` SSE events and checks whether each message targets an active bot. Two event subtypes are routed to `LocalChatActivityHandler`:

| Subtype | Trigger condition |
|---|---|
| `chat_direct` | `to_user_id` matches the bot's `user_id` |
| `chat_mention` | Message body contains `@BotUsername` (case-insensitive) in a room |

**Activity config fields** (stored in `ai_bot_activities.config_json`):

| Field | Type | Default | Description |
|---|---|---|---|
| `respond_in_dm` | bool | `true` | Respond to direct messages |
| `respond_in_rooms` | bool | `true` | Respond to @mentions in rooms |
| `allowed_room_ids` | int[] | `[]` | If non-empty, only respond in these room IDs |
| `blocked_user_ids` | int[] | `[]` | Never respond to these user IDs |
| `middleware` | object[] | `[]` | Middleware pipeline entries (see below) |

---

## Middleware Pipeline

Before the AI is called, the handler runs the message through a configurable pipeline of middleware steps. Each step can inspect and modify the request, supply a static reply, or abort the response entirely — without ever touching the AI provider.

### BotContext

`src/AiBot/BotContext.php` is the mutable object passed through the chain:

| Property | Mutable | Description |
|---|---|---|
| `$systemPrompt` | yes | System message sent to the AI; middleware may append, prepend, or replace it |
| `$incomingMessage` | yes | The user's raw message text; middleware may rewrite it |
| `$conversationHistory` | yes | Array of `{role, content}` pairs forming the context window |
| `$response` | yes | When set to a non-null string the AI call is skipped and this text is posted as the reply |
| `$aborted` | yes | When `true` no reply is posted at all |
| `$bot` | read-only | The `AiBot` instance |
| `$fromUserId` | read-only | Sender's user ID |
| `$fromUsername` | read-only | Sender's username |
| `$roomId` | read-only | Room ID for room messages; `null` for DMs |
| `$toUserId` | read-only | Recipient user ID for DMs; `null` for room messages |
| `$activityConfig` | read-only | Decoded `config_json` for this activity |

### Pipeline execution

The pipeline runs the middleware in array order. Each step receives `$ctx` and a `$next` callable:

- Call `$next()` to continue to the next step (and eventually the AI call).
- Set `$ctx->response` and return without calling `$next()` to short-circuit — the AI is never called and the value of `$response` is posted as the reply.
- Set `$ctx->aborted = true` and return to suppress any reply entirely.

After all middleware have run (or after a step sets `$ctx->response`), the budget check runs and then the AI is called if no response has been provided.

**Important:** middleware that short-circuits (slash commands, static replies) does not trigger the budget check and costs nothing.

---

## Built-in Middleware

Built-in classes live in `src/AiBot/Middleware/` and can be referenced by their short name in config.

### SlashCommandMiddleware

Maps `/command` tokens at the start of a message to static reply strings. Useful for help text, versioning, status replies, and similar fixed-response commands.

```json
{
  "class": "SlashCommandMiddleware",
  "config": {
    "commands": {
      "/help":    "I can answer questions about BinktermPHP. Try asking me anything!",
      "/version": "BinktermPHP 1.9.1",
      "/ping":    "Pong!"
    },
    "case_sensitive": false
  }
}
```

| Config key | Default | Description |
|---|---|---|
| `commands` | `{}` | Map of command token → reply string |
| `case_sensitive` | `false` | Whether command matching is case-sensitive |

Matching is token-based: `/help me please` matches the `/help` command. When matched, `$next()` is not called and the AI is not invoked.

### FilePromptInjectorMiddleware

Reads a local file and prepends or appends its contents to the system prompt. Useful for knowledge bases, persona notes, pricing sheets, or any context document that lives as a file on the server.

```json
{
  "class": "FilePromptInjectorMiddleware",
  "config": {
    "path":      "config/bots/mybot/context.md",
    "position":  "append",
    "separator": "\n\n---\n\n"
  }
}
```

| Config key | Default | Description |
|---|---|---|
| `path` | — | Path to the file, relative to the project root (required) |
| `position` | `append` | `append` or `prepend` |
| `separator` | `\n\n` | String inserted between the existing prompt and the file content |

If the file does not exist or cannot be read the middleware passes through silently. The file is re-read on every message (no caching), so updates take effect immediately.

### UrlPromptInjectorMiddleware

Fetches a URL and injects the response body into the system prompt. Useful for pulling in live data, remote context documents, or content maintained outside the BBS.

```json
{
  "class": "UrlPromptInjectorMiddleware",
  "config": {
    "url":       "https://example.com/mybot-context.md",
    "position":  "append",
    "separator": "\n\n---\n\n",
    "ttl":       3600,
    "timeout":   5,
    "max_bytes": 4000
  }
}
```

| Config key | Default | Description |
|---|---|---|
| `url` | — | URL to fetch (required) |
| `position` | `append` | `append` or `prepend` |
| `separator` | `\n\n` | String between existing prompt and fetched content |
| `ttl` | `3600` | Cache lifetime in seconds; `0` disables caching |
| `timeout` | `5` | HTTP request timeout in seconds |
| `max_bytes` | `0` | Truncate injected content to this many bytes; `0` = no limit |

Fetched content is cached in `sys_get_temp_dir()/binkterm_bot_cache/` keyed by a hash of the URL. **Large injected documents are the most common cause of unexpectedly high API costs** — always set `max_bytes` when the source document might be large.

To force a cache refresh, delete the cache files:

```bash
rm -f /tmp/binkterm_bot_cache/*.txt
```

### RegexFilterMiddleware

Tests the incoming message against a PCRE pattern and takes one of three actions on match.

```json
{
  "class": "RegexFilterMiddleware",
  "config": {
    "pattern":     "/^!nobot/i",
    "action":      "abort"
  }
}
```

| Config key | Default | Description |
|---|---|---|
| `pattern` | — | PCRE regex tested against `$ctx->incomingMessage` (required) |
| `action` | `abort` | `abort`, `reply`, or `rewrite` |
| `replacement` | `""` | Used by `reply` and `rewrite`; supports back-references (`$1`, `$2`) |

**Actions:**

| Action | Behaviour |
|---|---|
| `abort` | Sets `$ctx->aborted = true`; no reply is posted |
| `reply` | Sets `$ctx->response` to `replacement` (after regex substitution); AI is not called |
| `rewrite` | Replaces `$ctx->incomingMessage` via `preg_replace()` and continues the chain |

An invalid regex is silently ignored and the middleware passes through.

### RagPromptInjectorMiddleware

Retrieves relevant documentation chunks from a sqlite-vec knowledge base and injects them into the system prompt before the AI call. The incoming chat message is used as the search query. This is the recommended way to give a bot grounded knowledge of BinktermPHP's documentation without injecting the entire docs into every request.

Requires the `tools/support-bot` knowledge base to be built first:

```bash
cd tools/support-bot
pip install -r requirements.txt
python3 build_index.py
```

```json
{
  "class": "RagPromptInjectorMiddleware",
  "config": {
    "db_path":     "tools/support-bot/binkterm_knowledge.db",
    "script_path": "tools/support-bot/query_retrieve.py",
    "top_k":       4,
    "position":    "append",
    "separator":   "\n\n"
  }
}
```

| Config key | Default | Description |
|---|---|---|
| `db_path` | `tools/support-bot/binkterm_knowledge.db` | Path to the sqlite-vec knowledge base, relative to project root or absolute |
| `script_path` | `tools/support-bot/query_retrieve.py` | Path to `query_retrieve.py`, relative or absolute |
| `top_k` | `4` | Number of chunks to retrieve per message |
| `position` | `append` | `append` or `prepend` |
| `separator` | `\n\n` | String between existing prompt and injected context |

If the database or script is missing, or no relevant chunks are found, the middleware passes through without modifying the system prompt. All activity is logged at debug level to `ai_bot_daemon.log`.

The retrieved chunks include their source filename and heading breadcrumb, giving the model accurate attribution for its answers. Pair this middleware with a system prompt that instructs the bot to answer only from the provided context.

---

## Writing Custom Middleware

Implement `BotMiddlewareInterface` (`src/AiBot/BotMiddlewareInterface.php`):

```php
<?php

namespace MyApp\Bots;

use BinktermPHP\AiBot\BotContext;
use BinktermPHP\AiBot\BotMiddlewareInterface;
use BinktermPHP\Binkp\Logger;

class WeatherMiddleware implements BotMiddlewareInterface
{
    private string  $apiKey;
    private ?Logger $logger;

    public function __construct(array $config = [], ?Logger $logger = null)
    {
        $this->apiKey = (string)($config['api_key'] ?? '');
        $this->logger = $logger;
    }

    public function handle(BotContext $ctx, callable $next): void
    {
        // Intercept "/weather <city>" commands
        if (preg_match('/^\/weather\s+(.+)$/i', $ctx->incomingMessage, $m)) {
            $city = trim($m[1]);
            $report = $this->fetchWeather($city);
            if ($report !== null) {
                $ctx->response = $report; // short-circuit — AI not called
                return;
            }
        }

        // Otherwise inject current conditions into the system prompt
        // so the AI can reference them naturally
        $conditions = $this->fetchCurrentConditions();
        if ($conditions !== null) {
            $ctx->systemPrompt .= "\n\nCurrent weather: " . $conditions;
        }

        $next();
    }

    private function fetchWeather(string $city): ?string { /* ... */ }
    private function fetchCurrentConditions(): ?string   { /* ... */ }
}
```

**Rules:**

1. The constructor signature is `__construct(array $config = [], ?Logger $logger = null)`. The pipeline passes both; older middleware that only accepts `$config` still works.
2. Always call `$next()` unless you intend to short-circuit. Forgetting `$next()` silently swallows the message.
3. Custom classes must be Composer-autoloaded. Place them anywhere under `src/` or register a PSR-4 namespace in `composer.json`.

**Referencing in config** — use the fully-qualified class name:

```json
{
  "class": "MyApp\\Bots\\WeatherMiddleware",
  "config": { "api_key": "abc123" }
}
```

---

## Configuring Middleware in the Admin UI

On the bot edit page (**Admin → Community → AI Bots → Edit**), scroll to the **Activities** section. Below the Local Chat toggles is the **Middleware Pipeline** panel.

- Click **Add Step** to append a new entry.
- Select a class from the dropdown. Built-in classes are listed by name; choose **Custom...** to type a fully-qualified class name.
- The **Config (JSON)** textarea auto-fills with a placeholder showing the expected config shape for the selected class.
- Enter valid JSON in the config box. An empty box or `{}` is valid and uses all defaults.
- Entries run top-to-bottom. There is no drag-to-reorder — remove and re-add entries to change order.
- Click **×** to remove a step.

Changes take effect after saving the bot. The daemon picks up the new config on the next message (activity config is loaded fresh per message, not cached in the daemon process).

---

## Cost Management

API costs are tracked per bot in the `ai_requests` table via the `bot_id` column. The weekly spend (Sunday–Saturday UTC) is visible as a colour-coded badge on the bot list.

**Cost levers, in order of impact:**

| Lever | Where to change | Notes |
|---|---|---|
| Model | Bot edit form → Model | Haiku (~$0.001/msg) vs Opus (~$0.10/msg) is a 100× difference |
| Injected content size | `UrlPromptInjectorMiddleware` → `max_bytes` | Large documents injected every message dominate input token cost |
| Context window | Bot edit form → Context (msgs) | Each history message adds tokens; try 5 instead of 10 |
| Weekly budget | Bot edit form → Weekly Budget | Hard cap; bot replies with a limit message and stops when reached |

When the weekly budget is reached the bot responds once with *"I've reached my weekly limit and will be back on Sunday."* and then stops responding until the budget resets.

Middleware that short-circuits (e.g. `SlashCommandMiddleware`) never triggers an AI call and costs nothing regardless of budget.

---

## Debugging

Run the daemon with debug logging to see every step:

```bash
php scripts/ai_bot_daemon.php --log-level=DEBUG
```

Then watch `data/logs/ai_bot_daemon.log`. Key entries to look for:

**Middleware not running at all:**
```
[Middleware] Class not found, skipping {"class":"BinktermPHP\\AiBot\\Middleware\\MyClass"}
```
→ Class name typo or not autoloaded.

**UrlPromptInjectorMiddleware — cache hit:**
```
[UrlPromptInjector] Cache hit {"url":"...","age_secs":142,"ttl":3600,"bytes":4821}
```

**UrlPromptInjectorMiddleware — fetch failed:**
```
[UrlPromptInjector] curl error {"url":"...","error":"Could not resolve host"}
[UrlPromptInjector] HTTP error response {"url":"...","code":403}
```
→ Check network access from the server, SSL certificates, authentication.

**UrlPromptInjectorMiddleware — prompt successfully modified:**
```
[UrlPromptInjector] System prompt updated {"injected_bytes":4821,"prompt_before":142,"prompt_after":4969}
```
If `prompt_after` is very large (tens of thousands), the injected document is the likely cause of high costs. Add `"max_bytes": 4000` to the config.

**Stale cache delivering empty content:**
Delete the cache directory to force a fresh fetch:
```bash
rm -f /tmp/binkterm_bot_cache/*.txt
```

**Bot not responding at all:**
- Check `is_active` is true on the bot.
- Check the activity's `is_enabled` flag.
- Check `blocked_user_ids` — your user ID may be in the list.
- Check `allowed_room_ids` — if set, the bot only responds in those rooms.
- Check the weekly budget hasn't been exhausted.
- Verify the daemon is running: `cat data/run/ai_bot_daemon.pid` and `ps aux | grep ai_bot_daemon`.
