# Performance Tuning

This guide covers the main levers for improving BinktermPHP performance under load: profiling slow requests, right-sizing php-fpm and PostgreSQL, choosing the best realtime transport, and keeping the database healthy.

## Table of Contents

- [Slow Request Profiling](#slow-request-profiling)
- [BinkStream Transport: SSE vs WebSocket](#binkstream-transport-sse-vs-websocket)
- [php-fpm Tuning](#php-fpm-tuning)
- [Apache MPM Tuning](#apache-mpm-tuning)
- [PostgreSQL Tuning](#postgresql-tuning)
- [PHP Opcache](#php-opcache)
- [BinkP Connection Limits](#binkp-connection-limits)
- [Storage](#storage)
- [Database Maintenance](#database-maintenance)
- [Echomail Message Retention](#echomail-message-retention)
- [Capacity Planning Reference](#capacity-planning-reference)

---

## Slow Request Profiling

Enable slow request logging to identify bottlenecks without external tooling. When enabled, any request that takes longer than the threshold is written to the PHP error log.

```env
PERF_LOG_ENABLED=true
PERF_LOG_SLOW_MS=500
```

Set `PERF_LOG_SLOW_MS` to a value appropriate for your server — 500ms is a reasonable starting point. Lower it during active profiling, raise it in production to reduce noise.

---

## BinkStream Transport: SSE vs WebSocket

BinktermPHP uses BinkStream for browser realtime delivery. The transport mode has a significant effect on php-fpm worker usage.

**SSE mode** — the SharedWorker holds a `/api/stream` connection open for the duration of `SSE_WINDOW_SECONDS`. Each online user occupies one php-fpm worker continuously for the full window. This is the dominant factor in php-fpm sizing.

**WebSocket mode** — the standalone `scripts/realtime_server.php` daemon handles realtime connections directly. php-fpm workers are freed from holding SSE connections, dramatically reducing the number of workers required.

The transport is controlled by `BINKSTREAM_TRANSPORT_MODE` in `.env`:

- `auto` (default) — uses WebSocket if the realtime daemon appears to be running, otherwise falls back to SSE
- `ws` — force WebSocket; requires the daemon to be running
- `sse` — force SSE

**Recommendation:** run `scripts/realtime_server.php --daemon` and set `BINKSTREAM_TRANSPORT_MODE=ws` if you have more than a handful of concurrent users. See [BinkStreamChannel.md](BinkStreamChannel.md) for reverse proxy configuration.

**SSE window size:** When SSE is unavoidable, keep `SSE_WINDOW_SECONDS` short to reduce worker tie-up. On Apache + php-fpm with `auto` mode, BinktermPHP defaults to 2 seconds automatically. Increase it only if your proxy handles SSE flush correctly — use **Help → Developer → Buffer Test** to verify that your server flushes responses without buffering before raising this value.

```env
BINKSTREAM_TRANSPORT_MODE=auto
SSE_WINDOW_SECONDS=60
```

---

## php-fpm Tuning

`pm.max_children` is the most important setting. Size it based on your expected concurrent users and transport mode:

- **SSE mode:** `pm.max_children` ≥ (concurrent users × 1.1) + 5
- **WebSocket mode:** `pm.max_children` can be much smaller — size for peak HTTP request concurrency only

Edit your pool file (typically `/etc/php/8.x/fpm/pool.d/www.conf`):

```ini
pm = dynamic
pm.max_children = 25        ; see formula above
pm.min_spare_servers = 3
pm.max_spare_servers = 8
pm.start_servers = 5
pm.max_requests = 500       ; recycle workers to prevent slow memory growth
```

Reload after changes:

```bash
systemctl reload php8.x-fpm
```

Each php-fpm worker has been measured at approximately 33 MB RSS at typical load in a production environment (x86_64 GNU/Linux).

---

## Apache MPM Tuning

Use **php-fpm** rather than `mod_php` (`libapache2-mod-php`). Apache's `mpm_event` — which is required for efficient keepalive handling — is incompatible with `mod_php`, forcing a fallback to the much less efficient `mpm_prefork`. php-fpm also runs PHP in a separate process pool with its own user and resource limits, making it more secure and scalable.

Use **mpm_event** rather than mpm_prefork. It handles idle keepalive connections with threads instead of processes, which is far more efficient.

```bash
a2dismod mpm_prefork
a2enmod mpm_event proxy_fcgi
systemctl restart apache2
```

```apache
# /etc/apache2/mods-enabled/mpm_event.conf
<IfModule mpm_event_module>
    StartServers          2
    MinSpareThreads      10
    MaxSpareThreads      30
    ThreadsPerChild      25
    MaxRequestWorkers   150   ; must be ≥ pm.max_children
    MaxConnectionsPerChild 1000
</IfModule>
```

`MaxRequestWorkers` must be at least as large as `pm.max_children`. If Apache queues connections that php-fpm has capacity to handle, requests will pile up.

For SSE delivery specifically: do not enable gzip compression on `/api/stream`. Buffering introduced by mod_deflate can delay or swallow SSE events.

---

## PostgreSQL Tuning

PostgreSQL spawns one backend process per connection. Each php-fpm worker can hold an open connection, so `max_connections` must exceed `pm.max_children`.

```ini
# postgresql.conf

max_connections = 100         # must exceed pm.max_children + headroom for scripts

shared_buffers = 512MB        # 25% of total RAM is a standard starting point

effective_cache_size = 1GB    # 50–75% of RAM; hints the query planner

wal_buffers = 16MB
checkpoint_completion_target = 0.9
```

Reload after editing:

```bash
systemctl reload postgresql
```

**PgBouncer** is worth adding at 50+ concurrent users. It pools application connections so that many php-fpm workers share a smaller number of actual PostgreSQL backends, reducing per-connection memory significantly.

---

## PHP Opcache

> **Note:** Opcache has not been tested with BinktermPHP. The settings below are standard PHP recommendations and are expected to work, but have not been verified in this environment.

Enable opcache in `php.ini` if it is not already active. It eliminates repeated script compilation and is one of the highest-value PHP performance settings.

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
```

After editing, restart php-fpm:

```bash
systemctl restart php8.x-fpm
```

---

## BinkP Connection Limits

The BinkP server accepts inbound FTN connections concurrently. The default limit is 10. Raise it from **Admin → BBS Settings → BinkP** if you are a hub or expect many simultaneous uplinks. Each active BinkP session uses approximately 30 MB RSS.

---

## Storage

- Use SSD storage for the PostgreSQL data directory. Message search and echomail list queries are read-heavy and benefit significantly from low-latency random I/O.
- Place `data/inbound` and `data/outbound` on fast storage. Packet processing reads and writes many small files; SSD or a tmpfs ramdisk reduces processing time.
- Monitor disk space. Large inbound packet queues or accumulated log files can fill volumes unexpectedly. See [MAINTENANCE.md](MAINTENANCE.md) for log rotation.

---

## Database Maintenance

PostgreSQL requires periodic VACUUM and ANALYZE to reclaim dead row storage and keep query planner statistics fresh. BinktermPHP's maintenance script handles this:

```bash
php scripts/database_maintenance.php
```

Schedule it as a cron job during low-traffic hours:

```bash
# Run database maintenance nightly at 3 AM
0 3 * * * /usr/bin/php /path/to/binktest/scripts/database_maintenance.php
```

See [MAINTENANCE.md](MAINTENANCE.md) for full details and frequency recommendations by installation size.

---

## Echomail Message Retention

A large echomail table is the most common cause of slow message list and search queries. Use `scripts/echomail_maintenance.php` to purge old messages by age or per-area count limits.

```bash
# Delete messages older than 365 days
php scripts/echomail_maintenance.php --max-age-days=365

# Keep only the 1000 most recent messages per area
php scripts/echomail_maintenance.php --max-messages=1000
```

Add to cron to run automatically:

```bash
# Echomail retention — run weekly
0 4 * * 0 /usr/bin/php /path/to/binktest/scripts/echomail_maintenance.php --max-age-days=365
```

See [scripts/README_echomail_maintenance.md](../scripts/README_echomail_maintenance.md) for all options.

---

## Capacity Planning Reference

Figures assume all optional services running (MRC, Gemini, SSH, multiplexer). Deduct ~130 MB if those are disabled.

| Concurrent users | php-fpm workers | RAM minimum | RAM recommended | vCPU |
|---:|---:|---:|---:|---:|
| 1–5 | 11 | 1 GB | 1.5 GB | 1 |
| 5–20 | 27 | 2 GB | 3 GB | 2 |
| 20–50 | 60 | 4 GB | 5 GB | 2–4 |
| 50–150 | 170 | 10 GB | 13 GB | 4–8 |
| 150–300 | 335 | 20 GB | 26 GB | 8–16 |

Worker counts assume SSE mode. WebSocket mode significantly reduces the required worker count.

**DOS door sessions** add approximately 60–100 MB each. Plan for roughly one active door session per 100 concurrent users at typical usage, more if your BBS is games-heavy.

Per-process memory baseline at 3 concurrent users with all services running is approximately 1.1 GB total. Key per-unit costs:

- php-fpm worker: ~33 MB
- Apache worker: ~15 MB
- PostgreSQL backend: ~12–15 MB per connection
- System service baseline (daemons + proxy, no users): ~350–400 MB

See [CONFIGURATION.md](CONFIGURATION.md#server-sizing--tuning) for the full sizing reference and detailed php-fpm, Apache, and PostgreSQL configuration examples.
