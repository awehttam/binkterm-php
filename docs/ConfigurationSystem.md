# Configuration System

This document explains how BinktermPHP reads configuration at runtime. It is aimed at developers adding new features or modifying existing ones. For environment variable names and JSON file formats, see [CONFIGURATION.md](CONFIGURATION.md).

Configuration is split across three independent layers depending on what is being configured:

1. **Environment variables** — deployment-level infrastructure settings and secrets
2. **JSON config files** — admin-managed runtime settings for specific subsystems
3. **Database** — per-user preferences and per-user metadata

Reading from any layer is a direct operation. Writing to most config files must go through the Admin Daemon — see [AdminDaemon.md](AdminDaemon.md) for why.

---

## Layer 1: Environment Variables — `Config::env()`

`src/Config.php` loads `.env` on its first call and caches values in `$_ENV`. All code must use `Config::env()` to read environment values — never `getenv()` or `$_ENV` directly.

```php
Config::env('VARNAME', 'default_value')
```

This layer is used for settings that:
- Cannot change at runtime without restarting services (API keys, DB credentials, port numbers)
- Must be set by the server operator before the BBS starts
- Are sensitive and must not be stored in the database or in files that the web user can write
- Enable experimental or deliberately restricted features that should not be exposed in the admin UI

Common examples:

```php
Config::env('ANTHROPIC_API_KEY', '')           // AI provider secrets
Config::env('AI_DEFAULT_PROVIDER', '')         // which AI provider to use by default
Config::env('AI_MESSAGE_AI_ASSISTANT_PROVIDER', '')  // feature-specific override
Config::env('ENABLE_INTERESTS', 'true')        // coarse feature flag
Config::env('ECHOMAIL_ORDER_DATE', 'received') // echomail sort preference
Config::env('MCP_SERVER_URL', '')              // MCP server address
Config::env('SITE_URL')                        // public-facing base URL
```

### AI provider resolution

`AiService` uses a two-level lookup for both provider and model. For a request with feature `message_ai_assistant`, the normalised key becomes `MESSAGE_AI_ASSISTANT`, and the lookup chain is:

```
AI_MESSAGE_AI_ASSISTANT_PROVIDER  →  AI_DEFAULT_PROVIDER  →  first configured provider
AI_MESSAGE_AI_ASSISTANT_MODEL     →  AI_DEFAULT_MODEL      →  provider default model
```

The feature key is derived from `AiRequest::getFeature()` by uppercasing and replacing non-alphanumeric runs with underscores. For example, a request with feature `echo_digest`:

```php
$request = new AiRequest(
    feature: 'echo_digest',     // normalised → 'ECHO_DIGEST'
    systemPrompt: $systemPrompt,
    userPrompt: $userPrompt,
    // provider/model omitted — resolved from env by AiService
);
$service  = AiService::create();
$response = $service->generateText($request);
// Reads: Config::env('AI_ECHO_DIGEST_PROVIDER') → 'anthropic'
//        Config::env('AI_ECHO_DIGEST_MODEL')     → 'claude-haiku-4-5-20251001'
```

```bash
# .env
AI_ECHO_DIGEST_PROVIDER=anthropic
AI_ECHO_DIGEST_MODEL=claude-haiku-4-5-20251001
```

---

## Layer 2: JSON Config Files

Each major subsystem has its own config class that reads from a JSON file. All config classes use a static cache: the file is read once per process and the parsed array is held in a private static property. A `reload()` method clears the cache; the Admin Daemon calls it as needed after writing a new file.

### `config/binkp.json` → `BinkpConfig` (Singleton)

`src/Binkp/Config/BinkpConfig.php` manages the FTN system identity, uplinks, binkp daemon settings, security, and crashmail. It uses a Singleton rather than static methods.

```php
$binkpConfig = BinkpConfig::getInstance();
$address     = $binkpConfig->getSystemAddress();   // e.g. "1:123/456.0"
$sysop       = $binkpConfig->getSystemSysop();
```

`SystemConfig` wraps these calls with a fallback to the PHP constants in `Config.php` if `config/binkp.json` is missing:

```php
SystemConfig::getSystemFidonetAddress()
SystemConfig::getSystemSysop()
```

If `config/binkp.json` does not exist, `BinkpConfig` creates a minimal default file. If the file exists but is not valid JSON, it throws immediately — there is no silent fallback.

Written via: Admin UI → BinkP Config (via AdminDaemonClient `save_binkp_config` command).

### `config/bbs.json` → `BbsConfig` (Static)

`src/BbsConfig.php` manages BBS-level feature flags, credits settings, AI assistant configuration, echomail moderation, QWK settings, and other sysop-configurable options.

On load it merges `config/bbs.json` over `config/bbs.json.example` using `array_replace_recursive`. The `features` sub-key is handled separately: each known feature is explicitly cast to `bool`, and features absent from `bbs.json` inherit the example default.

```php
// Full merged array
$config = BbsConfig::getConfig();

// Boolean feature flag
BbsConfig::isFeatureEnabled('webdoors')        // true/false
BbsConfig::isFeatureEnabled('ai_assistant')    // not used — see ai_assistant sub-key below

// Typed accessors for common settings
BbsConfig::getOutgoingCharset()                // e.g. 'CP437'
BbsConfig::getEchomailModerationThreshold()    // int

// Raw sub-key access
$aiConfig  = BbsConfig::getConfig()['ai_assistant'];
$enabled   = !empty($aiConfig['enabled']);
$shareSumm = !empty($aiConfig['share_summary_enabled']);
```

The AI assistant enabled flag is stored under `ai_assistant.enabled` (a sub-object), not under `features`, because it has multiple related sub-settings. Web routes check it directly:

```php
// routes/web-routes.php
$bbsConfig = BbsConfig::getConfig();
$aiAssistantEnabled = !empty($bbsConfig['ai_assistant']['enabled']);
```

API routes re-check the flag at request time (the Twig variable just hides the button; the API enforces the rule):

```php
// routes/api-routes.php
$bbsConfig = \BinktermPHP\BbsConfig::getConfig();
if (empty($bbsConfig['ai_assistant']['enabled'])) {
    http_response_code(403);
    apiError('errors.ai_assistant.disabled', ..., 403);
    return;
}
```

Written via: Admin UI → BBS Settings (via AdminDaemonClient `save_bbs_config` command).

### `data/appearance.json` → `AppearanceConfig` (Static)

`src/AppearanceConfig.php` manages visual appearance, branding, shell selection, BBS menu layout, login screen behaviour, announcement banners, custom navigation links, SEO settings, message-reader options, file area sidebar content, and the default dashboard layout.

It lives in `data/` rather than `config/` because it is written more frequently and by a different process than most config files. It deep-merges over inline PHP defaults — there is no example file.

```php
AppearanceConfig::getActiveShell()             // 'web' or 'bbs-menu'
AppearanceConfig::isShellLocked()              // bool
AppearanceConfig::getDefaultTheme()            // theme name string
AppearanceConfig::isThemeLocked()              // bool
AppearanceConfig::getAnnouncement()            // array with '_active' and '_key' computed fields
AppearanceConfig::isMediaPlayerEnabled()       // bool
AppearanceConfig::getMediaPlayerConfig()       // full media_player sub-array
AppearanceConfig::getLoginScreenConfig()       // ['display_mode' => ..., 'ansi_size' => ...]
AppearanceConfig::getBbsMenuConfig()           // ['variant' => ..., 'menu_items' => [...], ...]
AppearanceConfig::getFileAreasConfig()         // includes pre-rendered HTML from Markdown fields
AppearanceConfig::getDefaultDashboardLayout()  // array or null
```

Content files (system news, house rules, login/register splash text, login ANSI) are read from separate files in `data/` by dedicated accessors such as `AppearanceConfig::getSystemNewsMarkdown()`.

Written via: Admin UI → Appearance (via AdminDaemonClient `save_appearance_config` and related commands).

### `config/lovlynet.json` → `LovlyNetClient` (reads directly)

`src/LovlyNetClient.php` reads its own config file directly in its constructor. There is no shared config class; the file contains registration credentials (`api_key`, `ftn_address`, `hub_hostname`) written by the LovlyNet registration process.

```php
$client = new LovlyNetClient();
if ($client->isConfigured()) { ... }
```

Written via: AdminDaemonServer `save_lovlynet_config` command (invoked by the registration flow).

### Door configs — `GameConfig`, `DoorConfig`, `NativeDoorConfig`, `JsdosDoorConfig`

Each door type has its own static config class reading its own JSON file:

| Class | File | Purpose |
|---|---|---|
| `GameConfig` | `config/webdoors.json` | WebDoor game settings |
| `DoorConfig` | `config/dosdoors.json` | DOS door drop-file settings |
| `NativeDoorConfig` | `config/nativedoors.json` | Native Linux/Windows doors |
| `JsdosDoorConfig` | `config/jsdosdoors.json` | Browser-side JS-DOS doors |

These configs are not merged over defaults. If the file is absent, the system considers the door type disabled. Each class exposes a simple `isEnabled(string $id)` check and per-door config accessors.

```php
DoorConfig::isDoorSystemEnabled()        // false if config/dosdoors.json missing
DoorConfig::isEnabled('lordii')          // bool
DoorConfig::getDoorConfig('lordii')      // array|null
```

Written via: Admin UI → Admin Daemon (`saveDosdoorsConfig`, `saveWebdoorsConfig`, `saveNativeDoorsConfig`, `saveJsdosdoorsConfig` commands).

---

## Layer 3: Per-User Settings (Database)

User-level preferences are stored in two database tables and are never read through a config class.

### `user_settings` table

Structured preferences with typed columns: `messages_per_page`, `threaded_view`, `netmail_threaded_view`, `default_sort`, `font_family`, `font_size`, `date_format`, `locale`, `default_tagline`, and others added by migrations. Read and written by `src/MessageHandler.php` and the settings API routes.

### `users_meta` table

A flexible key-value store (`user_id`, `keyname`, `valname`) for miscellaneous per-user data not suitable for a typed column. Accessed through `src/UserMeta.php`:

```php
$meta = new UserMeta();
$key  = $meta->getValue($userId, 'mcp_serverkey');  // string|null
$meta->setValue($userId, 'mcp_serverkey', $key);
```

Used for things like the user's MCP bearer key, per-user state flags, and other ad-hoc metadata.

---

## Read vs. Write

Reading from any config layer is always a direct operation — the config class or calling code reads the file or database directly without going through any daemon. This keeps reads fast and avoids IPC overhead on every request.

Writing is a different story:

- **Config files** — must be written via AdminDaemonClient so the Admin Daemon (which owns the files) performs the write. This applies to all files under `config/` and `data/appearance.json`.
- **Database** — written directly via PDO from routes and service classes; no daemon involved.

For details on the Admin Daemon wire protocol and how to add new daemon commands, see [AdminDaemon.md](AdminDaemon.md).

---

## Summary

| Setting type | Storage | Read with | Written via |
|---|---|---|---|
| Infrastructure, secrets, feature flags | `.env` | `Config::env()` | Text editor + service restart |
| FTN identity, uplinks, binkp daemon | `config/binkp.json` | `BinkpConfig::getInstance()` | Admin UI → Admin Daemon |
| BBS features, credits, AI assistant | `config/bbs.json` | `BbsConfig::getConfig()` / `isFeatureEnabled()` | Admin UI → Admin Daemon |
| Appearance, branding, shell, menus | `data/appearance.json` | `AppearanceConfig::getConfig()` and typed accessors | Admin UI → Admin Daemon |
| LovlyNet credentials | `config/lovlynet.json` | `LovlyNetClient` constructor | Registration flow → Admin Daemon |
| WebDoors | `config/webdoors.json` | `GameConfig` | Admin UI → Admin Daemon |
| DOS Doors | `config/dosdoors.json` | `DoorConfig` | Admin UI → Admin Daemon |
| Native Doors | `config/nativedoors.json` | `NativeDoorConfig` | Admin UI → Admin Daemon |
| JS-DOS Doors | `config/jsdosdoors.json` | `JsdosDoorConfig` | Admin UI → Admin Daemon |
| User preferences | `user_settings` table | SQL / `MessageHandler` | Settings API routes |
| User metadata | `users_meta` table | `UserMeta::getValue()` | `UserMeta::setValue()` |
