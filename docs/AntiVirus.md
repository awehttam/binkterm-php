# AntiVirus Support (ClamAV)

BinktermPHP integrates with **ClamAV** to scan files for viruses when they are uploaded via the web interface or received via TIC file echo. Scanning is optional and is configured per file area.

Note that ClamAV uses quite a bit of system resources.   Even on a tiny VPS ClamAV wants 1GB of RAM while idle!
## How It Works

BinktermPHP uses `clamdscan` (the ClamAV daemon client) rather than `clamscan` directly. This means the `clamd` daemon must be running; `clamdscan` connects to it via socket and submits files for scanning. Using the daemon is significantly faster than launching `clamscan` as a subprocess for each file.

The `--fdpass` flag is used when invoking `clamdscan`, which passes the file descriptor to `clamd` rather than a file path. This avoids permission problems when the PHP process and `clamd` run as different users.

If `clamd` is not running or `clamdscan` is not found, virus scanning is silently disabled and files are accepted without scanning. A log message is written when this occurs.

## Installation

### Debian / Ubuntu

```bash
apt-get install clamav clamav-daemon clamdscan
```

After installation, update the virus database and start the daemon:

```bash
freshclam
systemctl enable clamav-daemon
systemctl start clamav-daemon
```

### Verify the daemon is running

```bash
clamdscan --ping 1
# Should output: PONG
```

## Configuration

### PHP user permissions

`clamd` must be able to read files submitted by the PHP process. The easiest approach is to add the `clamd` user to the PHP/web server group, or vice versa:

The `--fdpass` flag used by BinktermPHP passes file descriptors directly to `clamd`, which avoids most permission issues without requiring shared group membership. If you encounter `Access denied` errors in the scan log, the group membership approach below resolves them, but should not be needed.

```bash
# Add clamav user to the www-data group (adjust group name as needed)
usermod -aG www-data clamav
systemctl restart clamav-daemon
```


### .env settings

| Variable | Default | Description |
|----------|---------|-------------|
| `CLAMDSCAN` | *(auto-detected)* | Full path to the `clamdscan` binary. Set this if `clamdscan` is installed in a non-standard location or auto-detection fails. |
| `CLAMAV_ALLOW_INFECTED` | `false` | When `true`, infected files are accepted and stored rather than rejected and deleted. The virus scan result is still recorded in the database and displayed in the file details. Useful for retronet/nodelist distribution where you want to preserve files regardless of scan result. |

Auto-detection checks the following locations in order:
1. `CLAMDSCAN` environment variable
2. `/usr/bin/clamdscan`
3. `/usr/local/bin/clamdscan`
4. `/opt/clamav/bin/clamdscan`
5. `which clamdscan`

Example `.env` entry:

```env
CLAMDSCAN=/usr/bin/clamdscan
```

### Per-area virus scanning

Virus scanning is enabled or disabled per file area in the admin interface at **Admin → File Areas**. Each area has a **Scan for Viruses** toggle. Scanning is enabled by default for new areas.

When a file fails the virus scan it is rejected and not stored. The virus signature name is logged and recorded in the database alongside the file record.

## Automatic Database Updates

ClamAV's virus definitions must be kept current. On Debian/Ubuntu the `clamav-freshclam` service handles this automatically:

```bash
systemctl enable clamav-freshclam
systemctl start clamav-freshclam
```

Check that updates are working:

```bash
systemctl status clamav-freshclam
```

## Troubleshooting

**Scanning silently disabled / "clamdscan not available" in PHP error log**

- Confirm `clamd` is running: `systemctl status clamav-daemon`
- Confirm `clamdscan --ping` returns `PONG`
- If the binary is in a non-standard location, set `CLAMDSCAN=` in `.env`

**`Access denied` errors**

- The `clamd` process cannot read the file. Add `clamav` to the web server group and restart `clamd`, or confirm `--fdpass` is supported by your installed version (`clamdscan --help | grep fdpass`).

**Definitions out of date**

- Run `freshclam` manually, or confirm `clamav-freshclam` service is active.
