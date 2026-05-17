# Telnet Daemon

- `telnet/telnet_daemon.php` and `ssh/ssh_daemon.php` manually `require_once` telnet-side classes from `telnet/src/`. New classes under `telnet/src/` are **not** Composer-autoloaded for those daemons. When adding a class there, update the `require_once` lists in both daemon entrypoints as needed.
- Keep the SSH daemon include list in sync with the telnet daemon include list for shared terminal-side classes. If `telnet/telnet_daemon.php` gains a new `telnet/src/` include that SSH sessions also use, add the same include to `ssh/ssh_daemon.php`.

## Data Access

**Terminal-side code must use the REST API for all data access.** Code under `telnet/src/` and `ssh/` must never access the database directly, never `require` web-side classes from `src/`, and never call web-side helpers. All reads and writes go through HTTP calls to the local API using `TelnetUtils::apiRequest()`.

```text
❌ $db = Database::getInstance()->getPdo();    (direct DB — forbidden)
❌ BulletinManager::getUnreadCount($userId);   (web-side class — forbidden)
✅ TelnetUtils::apiRequest($base, 'GET', '/api/...', null, $session);
```

When a feature needs data that the existing API does not expose, add or extend an endpoint on the web side first, then call it from the terminal side.
