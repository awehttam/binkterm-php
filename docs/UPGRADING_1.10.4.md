# Upgrading to 1.10.4

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [RLogin Doors](#rlogin-doors)
- [Networks](#networks)
- [Dashboard](#dashboard)
- [DOS Doors](#dos-doors)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Doors

- Added **RLogin Doors**, a new door type that connects out to a remote BBS or service (such as a linked Synchronet system) over the rlogin protocol instead of running a local process.

### Networks

- Removed **DixieNet** from the built-in FTN networks list, as the network is defunct.

### Dashboard

- Fixed the unread netmail and echomail counts on the dashboard not updating in real time; they previously only refreshed on a 30-second poll instead of reacting to BinkStream events like the messaging menu badges do.

### DOS Doors

- The DOSBox/DOSEMU multiplexing server now logs the generated `[autoexec]` config section (DOSBox) or launch batch script (DOSEMU) to the console/log each time a door session is launched, to make door launch problems easier to diagnose from server logs.
- Fixed a crash in the DOSEMU adapter when a door's `launch_command` used the `{user_number}` placeholder.
- Removed a duplicate, non-functional "Requires FOSSIL Driver" checkbox from the **Requirements** section of the DOS door manifest editor. The **Requires FOSSIL Driver** checkbox in the door info section is the one that actually controls whether the FOSSIL driver is loaded at launch; the removed checkbox never had any effect.

---

## RLogin Doors

BinktermPHP can now link out to a remote BBS or service over the rlogin protocol (RFC 1282) as a new door type, alongside DOS Doors and Native Doors. This lets users reach a separate system — such as a Synchronet BBS — without leaving their BinktermPHP terminal session.

Unlike the other door types, RLogin doors have no filesystem footprint — there's no executable or manifest directory, just connection settings. Because of that, RLogin doors are stored directly in a new `rlogin_doors` database table rather than as manifest/config files, and are managed through a dedicated **Admin → RLogin Doors** page with a standard add/edit/delete form — including uploading a custom icon and screenshot for each door, stored directly in the database.

Each RLogin door is configured with a target host/port, an rlogin username/terminal-type handshake, and an optional **pre-login command** that runs server-side before every connection to provision or sync the remote account just-in-time. A bundled reference client script (`scripts/synchronet_add_user.php`) is included for sysops linking to Synchronet via a companion `services.ini` service. For sysops running that companion service, an **Import from Synchronet** button on the admin page fetches the list of installed Synchronet doors and creates a fully-configured (but disabled, pending review) RLogin door for each one automatically. See [RLoginDoors.md](RLoginDoors.md) for the full field reference, BBS Type presets, the pre-login command's wire protocol, and the import feature.

## Networks

DixieNet has been removed from the built-in list of FTN networks under **Admin → Networks**, as the network is defunct. If your system has an active binkp uplink, echo area, or file area still configured against the `dixienet` network, the row is left in place automatically.

## Dashboard

The unread netmail and echomail counts shown on the dashboard now update in real time as new mail arrives, the same way the messaging menu badges in the navigation bar already did. Previously, the dashboard counts only refreshed on a 30-second poll, so a new message could take up to 30 seconds to show up there even though the nav bar badge lit up immediately.

## DOS Doors

The DOSBox/DOSEMU multiplexing server (`scripts/dosbox-bridge/`) now writes the generated door launch script to its console/log output at launch time. For the DOSBox adapter this is the `[autoexec]` section of the generated `dosbox.conf`; for the DOSEMU adapter this is the generated launch batch script. This makes it possible to see exactly what commands a door session ran (mount points, FOSSIL driver loading, dropfile copy, launch command) directly from server logs, without needing to open the per-session config file on disk.

The DOSEMU adapter also had a bug fixed where launching a door whose manifest `launch_command` contained the `{user_number}` placeholder would crash with a `ReferenceError` instead of substituting the user's ID.

In the DOS door manifest editor (**Admin → DOS Doors**), the **Requirements** section previously had two checkboxes related to the FOSSIL driver: "Requires FOSSIL Driver" in the door info section (which actually controls whether the FOSSIL driver is loaded during door launch) and a second, identically-labeled checkbox in the Requirements section that had no effect on runtime behavior. The non-functional duplicate has been removed. Existing manifests are unaffected; the remaining "Requires FOSSIL Driver" checkbox in the door info section continues to work as before.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
