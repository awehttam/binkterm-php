# Upgrading to X.Y.Z

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Feature Area One](#feature-area-one)
- [Feature Area Two](#feature-area-two)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

<!--
Group bullet points by major feature area. Each bullet should be self-contained:
state what changed, why it matters, and what (if anything) the upgrader must do.
Do not reference branch discussions, prior bugs by name, or internal shorthand.
-->

### Feature Area One

- Summary bullet.

### Feature Area Two

- Summary bullet.

---

<!--
Fuller descriptions follow, one H2 section per major feature area.
Each section should be readable by someone with no prior exposure to the work.
State what changed, why it matters, and what action is required.
If a migration runs via setup.php, say so. If nothing is required, omit the sentence entirely.
-->

## Feature Area One

Describe the change in full. What did it do before? What does it do now? Why does that matter to the sysop or user?

If a database migration is applied automatically by `php scripts/setup.php`, say so here.

## Feature Area Two

Describe the change in full.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
