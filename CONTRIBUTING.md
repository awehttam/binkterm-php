# Contributing to BinktermPHP

Thank you for your interest in contributing to BinktermPHP! This document provides guidelines and instructions for contributing to the project.

## Table of Contents

- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Code Conventions](#code-conventions)
- [Making Changes](#making-changes)
- [Database Migrations](#database-migrations)
- [Version Management](#version-management)
- [Testing](#testing)
- [Submitting Changes](#submitting-changes)

## Looking for Something to Work On?

If you're considering getting involved, check out **[HELP_WANTED.md](HELP_WANTED.md)** for an overview of the areas where contribution would have the most impact — from FTN protocol work and DOS door integration to WebDoors game development and UI themes.

## Getting Started

BinktermPHP is a PHP/PostgreSQL BBS and FTN mail system that combines a modern web interface, terminal access, and a built-in binkp mailer. It lets users read and write netmail and echomail, exchange packets with FidoNet-style networks, and run BBS features such as file areas, doors, chat, and web-based games. We suggest familiarizing yourself with:

- FidoNet Technology Network (FTN) basics
- The binkp protocol
- PHP development best practices
- PostgreSQL database operations

Before writing any code, read these documents:

- **[CLAUDE.md](CLAUDE.md)** — project-specific conventions, operational gotchas, and AI assistant configuration. Additional `CLAUDE.md` files exist in certain subdirectories (e.g. `telnet/`, `ssh/`, `scripts/`, `templates/`) and contain subsystem-specific rules that apply when working in those areas.
- **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** — system overview, daemon IPC model, FTN packet lifecycle, and how the major subsystems fit together.
- **[docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md)** — coding conventions, database migrations, development workflow, and pre-commit checklist.

## Development Setup

### Prerequisites

- PHP 8.2 or higher
- PostgreSQL database
- Composer for dependency management
- Git for version control

### Initial Setup

1. **Fork the repository** on GitHub by clicking the "Fork" button on the [project page](https://github.com/awehttam/binkterm-php). This creates your own copy of the repo under your GitHub account.

2. **Clone your fork** locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/binkterm-php.git
   cd binkterm-php
   ```

3. **Add the upstream remote** so you can pull in future changes:
   ```bash
   git remote add upstream https://github.com/awehttam/binkterm-php.git
   ```

4. Install dependencies:
   ```bash
   composer install
   ```

5. Set up your database and configuration files. For a full local install, follow the installation and configuration steps in [README.md](README.md). In most development environments you will create a PostgreSQL database, configure `.env`, then run one of:
   ```bash
   php scripts/install.php
   php scripts/setup.php
   ```

6. Check out the `claudesbbs` branch, update it from upstream, and create your feature branch from there:
   ```bash
   git fetch upstream
   git checkout claudesbbs
   git merge --ff-only upstream/claudesbbs
   git checkout -b feature/your-feature-name
   ```

**Important**: Never push directly to `main` or `claudesbbs`. All changes must go through pull requests for review.

## Code Conventions

See [docs/DEVELOPER_GUIDE.md — Code Conventions](docs/DEVELOPER_GUIDE.md#development-workflow) for naming rules, indentation, environment variable access, client-side storage, timestamp handling, security requirements, and all other coding standards.

## Making Changes

### Before You Code

1. Check existing issues and pull requests to avoid duplicate work
2. For new features, consider opening an issue first to discuss the approach
3. Understand the existing architecture and patterns in the codebase

### While Coding

1. Follow the existing code style and conventions described in [docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md)
2. Write clear, self-documenting code with meaningful variable names
3. Add comments only where the logic isn't self-evident
4. Keep functions focused and reasonably sized
5. Avoid premature optimization — prioritize clarity

Before committing, work through the **Pre-commit Checklist** in [docs/DEVELOPER_GUIDE.md — Pre-commit Checklist](docs/DEVELOPER_GUIDE.md#development-workflow).

## Database Migrations

All database schema changes must go through migration scripts. See [docs/DEVELOPER_GUIDE.md — Database Migrations](docs/DEVELOPER_GUIDE.md#development-workflow) for file format, PHP migration patterns, and best practices.

To create a new migration:

```bash
php scripts/migration.php create "add user preferences"
php scripts/migration.php create "backfill user data" php
```

Then run `php scripts/setup.php` to verify it applies cleanly.

Do not update `src/Version.php` or `composer.json` just because you added a migration. Version bumps are handled separately by maintainers unless explicitly requested.

## Version Management

See [docs/DEVELOPER_GUIDE.md — Version Management](docs/DEVELOPER_GUIDE.md#development-workflow) for the full process. Contributors typically do not need to bump the version — maintainers handle this during release preparation unless they explicitly request otherwise.

If your change adds a required Composer package, document it in the relevant `docs/UPGRADING_x.x.x.md` with instructions to run `composer update` before `php scripts/setup.php`.

## Testing

### Manual Testing

1. Test your changes in a development environment
2. Verify both success and error cases
3. Test edge cases and boundary conditions
4. Check for regressions in existing functionality
5. Run any relevant validation scripts for the area you changed

### Test Scripts

Use the scripts in the `tests/` directory for debugging and troubleshooting:
- Test message handling
- Verify packet processing
- Validate kludge line generation
- Check address parsing

### Areas to Test

- **Netmail**: Message composition, replies, addressing
- **Echomail**: Area subscriptions, message posting, threading
- **Binkp**: Connection handling, polling, packet transfer
- **Web Interface**: Form submissions, AJAX requests, user interactions
- **Character Encoding**: CP437 and UTF-8 handling
- **Date Parsing**: FidoNet timestamp formats

## Submitting Changes

### Commit Messages

Write clear, descriptive commit messages:

```
Fix parameter order in system config save handler

Corrected the parameter mapping in BinkpController::updateConfig()
to properly align form data with the setSystemConfig() method
signature. Previously, fields were shifted causing address to save
as name, sysop as address, etc.
```

Format:
- First line: Brief summary (50-72 characters)
- Blank line
- Detailed description if needed
- Reference related issues: `Fixes #123` or `Related to #456`

### Pull Request Process

**Important**: All changes must be submitted via pull request targeting the `claudesbbs` branch. Do not push directly to `main` or `claudesbbs`.

The `claudesbbs` branch is a staging branch deployed to [Claude's BBS](https://claudes.lovelybits.org), where changes are tested in a live environment before being merged into `main` for release.

1. **Sync your fork** with the latest upstream changes, then rebase your feature branch:
   ```bash
   git fetch upstream
   git checkout claudesbbs
   git merge --ff-only upstream/claudesbbs
   git checkout your-feature-branch
   git rebase claudesbbs
   ```

2. **Push your feature branch** to your fork:
   ```bash
   git push origin your-feature-branch
   ```

3. **Submit a Pull Request** on GitHub:
   - Navigate to your fork on GitHub
   - Click "Pull requests" → "New pull request"
   - Set the **base repository** to `awehttam/binkterm-php` and **base branch** to `claudesbbs`
   - Set the **head repository** to your fork and **compare branch** to your feature branch
   - Use a clear, descriptive title
   - Describe what changes you made and why
   - Reference any related issues (e.g., "Fixes #123")
   - Include screenshots for UI changes
   - List any breaking changes

4. **Wait for review**:
   - Maintainers will review your pull request
   - Be open to suggestions and constructive criticism
   - Make requested changes in new commits
   - Push updates to your feature branch (they'll appear in the PR automatically)

5. **After approval**:
   - Maintainers will merge your PR into `claudesbbs`
   - You can then delete your feature branch

### PR Checklist

Before submitting, work through the [Pre-commit Checklist](docs/DEVELOPER_GUIDE.md#development-workflow) in the Developer Guide, then confirm:
- [ ] Code follows project conventions (see [Developer Guide](docs/DEVELOPER_GUIDE.md))
- [ ] No sensitive data or credentials committed
- [ ] Changes tested locally
- [ ] Documentation updated if needed
- [ ] No new security vulnerabilities introduced

## Questions or Need Help?

- Open an issue for bugs or feature requests
- Join discussions in existing issues
- Check the project wiki for additional documentation
- Review existing code for examples and patterns

## Code of Conduct

- Be respectful and constructive
- Welcome newcomers and help them learn
- Focus on what's best for the project
- Accept constructive criticism gracefully

## License

By contributing to BinktermPHP, you agree that your contributions will be licensed under the same license as the project.

---

Thank you for contributing to BinktermPHP! Your efforts help keep FidoNet alive and thriving in the modern era.
