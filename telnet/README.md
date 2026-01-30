# Telnet Daemon (Alpha)

This directory contains an **alpha-quality** telnet daemon for BinktermPHP. It provides a read-only (with basic reply/compose) text UI that reuses the existing web API endpoints for netmail and echomail.

## Status
- **Very alpha quality**
- Message browsing with reply/compose support
- Uses existing web API to avoid reimplementing netmail/echomail logic, etc

## Requirements
- PHP 8+
- PHP curl extension enabled
- BinktermPHP web API reachable (defaults to SITE_URL)

## Usage

Run the daemon (defaults to 0.0.0.0:2323):

```
php telnet/telnet_daemon.php
```

Specify host/port and API base:

```
php telnet/telnet_daemon.php --host=0.0.0.0 --port=2323 --api-base=http://127.0.0.1
```

For HTTPS with a self-signed cert:

```
php telnet/telnet_daemon.php --api-base=https://your-host --insecure
```

Debug login/API calls:

```
php telnet/telnet_daemon.php --debug
```

## Notes
- Uses the existing `/api/auth/login` and `/api/messages/*` endpoints.
- Echo handling varies by telnet client; PuTTY/SyncTERM work best.
- Windows typically allows only one connection at a time (no `pcntl_fork`).
- Message editor ends with a single `.` line. Use `/abort` to cancel.
