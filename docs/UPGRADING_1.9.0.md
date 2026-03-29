# Upgrading to 1.9.0

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Security Fixes](#security-fixes)
  - [MCP Server Dependency Update](#mcp-server-dependency-update)
  - [CVE Coverage](#cve-coverage)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

**Security Fixes**
- The optional MCP server now updates its `path-to-regexp` dependency from `8.3.0` to `8.4.0`.
- This release addresses `CVE-2026-4926` (`GHSA-j3q9-mxjg-w52f`) and `CVE-2026-4923` (`GHSA-27v5-c462-wpq7`) in the MCP server dependency tree.

## Security Fixes

### MCP Server Dependency Update

The `mcp-server/` package now pins `path-to-regexp` at `^8.4.0`, and the lockfile has been refreshed so the resolved package version is `8.4.0`.

This change affects the optional Model Context Protocol server used for AI assistant access to echomail. The main PHP application and the DOSBox bridge do not use this dependency.

No database migration is required for this release.

### CVE Coverage

This update is included specifically to address the following dependency advisories in the MCP server stack:

- `CVE-2026-4926` (`GHSA-j3q9-mxjg-w52f`)
- `CVE-2026-4923` (`GHSA-27v5-c462-wpq7`)

If you do not run the optional MCP server, no additional service-specific action is required beyond your normal application upgrade process.

## Upgrade Instructions

### From Git

```bash
git pull origin main
composer install
php scripts/setup.php
```

If you run the optional MCP server, you must also update its npm packages so the new dependency version is installed:

```bash
cd mcp-server
npm install
```

This step updates the MCP server's Node dependency tree from `package-lock.json`, including the `path-to-regexp` security fix shipped with 1.9.0.

Then restart the MCP server process if it is running under a service manager, supervisor, or manual shell session.

### Using the Installer

Re-run the BinktermPHP installer to update the application files. When prompted to run `php scripts/setup.php`, allow it to complete.

If you use the optional MCP server, run `npm install` inside `mcp-server/` after the upgrade so the updated npm packages are installed, then restart that service.
