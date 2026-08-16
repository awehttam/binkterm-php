# Upgrading to 1.10.3

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [FREQ](#freq)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### FREQ

- `scripts/freq_getfile.php` and `scripts/freq_pickup.php` now connect anonymously by default, even when the target address matches one of your configured uplinks. A new `--authenticated` flag opts back into using that uplink's real session password/CRAM-MD5.

## FREQ

FREQ requests made with `scripts/freq_getfile.php` or `scripts/freq_pickup.php` now default to an anonymous binkp session, matching standard FTN convention for file requests. Previously, if the address you were FREQing happened to match one of your configured uplinks, the script would automatically use that uplink's real session password and CRAM-MD5 credentials for the session — not what most sysops expect from a simple file request. This is separate from the FREQ area password (`--password`), which is still carried inside the `.req`/`M_GET` request itself and unaffected by this change.

If you specifically want a FREQ run to use your real uplink session credentials, pass the new `--authenticated` flag.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
