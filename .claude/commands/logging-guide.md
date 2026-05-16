# Logging Guide

## Log files

| File | Purpose |
|---|---|
| `server.log` | General application, web requests, background tasks |
| `packets.log` | FTN packet processing |
| `dosdoor.log` | Door game session setup (DoorSessionManager, door routes) |
| `multiplexing-server.log` | Door execution activity (the multiplexing bridge) |
| `binkp_server.log` | Binkp server daemon |
| `binkp_poll.log` | Binkp polling daemon |
| `binkp_scheduler.log` | Binkp scheduler |
| `admin_daemon.log` | Admin daemon |
| `mrc_daemon.log` | MRC daemon |
| `crashmail.log` | CrashMail processing |

## Patterns by context

**In a class with a constructor** — inject or create a Logger property:
```php
private \BinktermPHP\Binkp\Logger $logger;

public function __construct()
{
    $this->logger = new \BinktermPHP\Binkp\Logger(
        \BinktermPHP\Config::getLogPath('server.log'),
        \BinktermPHP\Binkp\Logger::LEVEL_INFO,
        false
    );
}
```
Then call `$this->logger->info(...)`, `->warning(...)`, `->error(...)`, etc.

**In a route file or static context** — use the `getServerLogger()` helper from `src/functions.php`:
```php
getServerLogger()->error("Something went wrong: " . $e->getMessage());
```
This returns a shared Logger instance writing to `server.log`. Route files that need `dosdoor.log` should define their own local helper (see `getDoorLogger()` in `routes/door-routes.php`).

**In a CLI script** — `src/functions.php` must be included (all CLI scripts should already include it). Then use `getServerLogger()` or create a Logger inline for script-specific log files.

## Log levels

- `debug()` — detailed diagnostic info, only useful when actively debugging
- `info()` — normal operational events (session started, file created, email sent)
- `warning()` — unexpected but recoverable situations
- `error()` — failures that need attention but didn't crash the process
- `critical()` — severe failures

## Adding a new log file

If you introduce a new log file (e.g., `myfeature.log`):
1. Use `Config::getLogPath('myfeature.log')` when constructing the Logger.
2. Add the filename to the `UDP_ALLOWED_LOG_FILES` allowlist in `src/Admin/AdminDaemonServer.php` so the UDP fallback can write to it when the web process can't.
