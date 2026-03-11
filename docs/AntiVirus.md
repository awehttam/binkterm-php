# AntiVirus Support

BinktermPHP uses a pluggable antivirus layer (`src/Antivirus/`) that can run multiple scanner backends simultaneously. Currently supported backends:

- **ClamAV** — local scanning via `clamdscan` (free, no API key required)
- **VirusTotal** — cloud scanning via VirusTotal API v3 (free tier available)

Both scanners can be active at the same time. If either reports a file as infected, the file is rejected.

## Architecture

| Class | Description |
|-------|-------------|
| `Antivirus\ScannerInterface` | Contract all backends implement |
| `Antivirus\ClamavScanner` | ClamAV backend |
| `Antivirus\VirusTotalScanner` | VirusTotal API v3 backend |
| `Antivirus\AntivirusManager` | Runs all enabled backends, aggregates results |
| `VirusScanner` | Backward-compat shim — delegates to `AntivirusManager` |

To add a new backend, implement `ScannerInterface` and register it in `AntivirusManager::create()`.

---

## ClamAV

BinktermPHP uses `clamdscan` (the ClamAV daemon client) rather than `clamscan` directly. The `clamd` daemon must be running; `clamdscan` connects to it via socket. Using the daemon is significantly faster than launching `clamscan` as a subprocess for each file.

The `--fdpass` flag passes the file descriptor to `clamd` rather than a file path, avoiding permission issues when PHP and `clamd` run as different users.

> **Resource warning:** ClamAV can use a significant amount of RAM, especially as signature databases grow. Plan memory capacity accordingly on low-resource systems.

If `clamd` is not running or `clamdscan` is not found, the ClamAV backend is silently disabled. A log message is written when this occurs.

### Installation

**Debian / Ubuntu:**
```bash
apt-get install clamav clamav-daemon clamdscan
freshclam
systemctl enable clamav-daemon clamav-freshclam
systemctl start clamav-daemon clamav-freshclam
```

Verify the daemon is running:
```bash
clamdscan --ping 1
# Should output: PONG
```

### .env settings

| Variable | Default | Description |
|----------|---------|-------------|
| `CLAMDSCAN` | *(auto-detected)* | Full path to the `clamdscan` binary. Set this if installed in a non-standard location. |
| `FILES_ALLOW_INFECTED` | `false` | When `true`, infected files are stored rather than deleted. The scan result is still recorded. Useful for archive/nodelist areas. |

Auto-detection order: `CLAMDSCAN` env var → `/usr/bin/clamdscan` → `/usr/local/bin/clamdscan` → `/opt/clamav/bin/clamdscan` → `which clamdscan`

### PHP user permissions

The `--fdpass` flag avoids most permission issues without extra configuration. If you encounter `Access denied` errors, add `clamav` to the web server group:

```bash
usermod -aG www-data clamav
systemctl restart clamav-daemon
```

---

## VirusTotal

> **Privacy warning:** Files uploaded to VirusTotal may be shared with their partners and the broader security research community. **Do not enable VirusTotal scanning if your BBS handles files that users expect to remain private.** Hash lookups do not upload file content, but any file whose hash is not already known to VirusTotal will be uploaded and may become publicly accessible. See [VirusTotal's terms of service](https://docs.virustotal.com/docs/terms-of-service) for details.

> VirusTotal may yield a higher number of false positives due to the number of vendors used for sample analysis.  
> 
The VirusTotal backend (`Antivirus\VirusTotalScanner`) uses the [VirusTotal API v3](https://docs.virustotal.com/reference/overview). It performs a **hash lookup first** — if the file's SHA-256 is already known to VirusTotal, the result is returned immediately without uploading the file. Unknown files are uploaded and polled until the analysis completes.

### Free-tier limits

| Limit | Value |
|-------|-------|
| Requests per minute | 4 |
| Requests per day | 500 |
| Max upload size | 32 MB |

Files larger than 32 MB are hash-checked only (no upload). If the hash is unknown and the file is too large, the result is `skipped`.

### Setup

1. Create a free account at [virustotal.com](https://www.virustotal.com)
2. Copy your API key from your profile
3. Add to `.env`:

```env
VIRUSTOTAL_API_KEY=your_api_key_here
```

VirusTotal scanning is **disabled by default** and only activates when `VIRUSTOTAL_API_KEY` is set.

---

## Per-area configuration

Virus scanning is enabled or disabled per file area in the admin interface at **Admin → File Areas**. Each area has a **Scan for Viruses** toggle. Scanning is enabled by default for new areas.

When a file fails the virus scan it is rejected and deleted. The virus signature name is logged and recorded in the database alongside the file record.

---

## Troubleshooting

**ClamAV silently disabled / "clamdscan not available" in PHP error log**
- Confirm `clamd` is running: `systemctl status clamav-daemon`
- Confirm `clamdscan --ping` returns `PONG`
- If binary is in a non-standard location, set `CLAMDSCAN=` in `.env`

**`Access denied` errors**
- Run `--fdpass` support check: `clamdscan --help | grep fdpass`
- Add `clamav` to the web server group and restart `clamd`

**ClamAV definitions out of date**
- Run `freshclam` manually, or confirm `clamav-freshclam` service is active

**VirusTotal rate limit (429)**
- Free tier is limited to 4 requests/minute. The error is logged; the file is left with `result = error`. Re-scan manually via the admin interface once the quota resets.

**VirusTotal analysis pending**
- Large or rare files may take longer than 90 seconds to analyse. The result is stored as `pending`. Use the manual rescan button in the admin file list to retry.
